import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const baseUrl = new URL(process.env.APPLICANT_PROFILE_SMOKE_BASE_URL ?? 'http://127.0.0.1:8000');
const login = process.env.APPLICANT_PROFILE_SMOKE_LOGIN;
const password = process.env.APPLICANT_PROFILE_SMOKE_PASSWORD;
const timeoutMs = Number(process.env.APPLICANT_PROFILE_SMOKE_TIMEOUT_MS ?? 20_000);
const screenshotPath = process.env.APPLICANT_PROFILE_SMOKE_SCREENSHOT;

function fail(message) {
    throw new Error(message);
}

function browserPath() {
    const candidates = [
        process.env.APPLICANT_PROFILE_SMOKE_BROWSER,
        process.platform === 'win32' ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : null,
        process.platform === 'win32' ? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe' : null,
        process.platform === 'darwin' ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : null,
        process.platform === 'linux' ? '/usr/bin/google-chrome' : null,
        process.platform === 'linux' ? '/usr/bin/chromium' : null,
    ].filter(Boolean);

    return candidates.find((candidate) => existsSync(candidate));
}

function delay(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

async function waitFor(check, description) {
    const deadline = Date.now() + timeoutMs;
    let lastError;

    while (Date.now() < deadline) {
        try {
            const result = await check();
            if (result) return result;
        } catch (error) {
            lastError = error;
        }

        await delay(100);
    }

    fail(`Timed out waiting for ${description}${lastError ? `: ${lastError.message}` : ''}`);
}

async function reservePort() {
    const { createServer } = await import('node:net');
    const server = createServer();
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    const { port } = server.address();
    await new Promise((resolve) => server.close(resolve));
    return port;
}

class CdpClient {
    constructor(webSocketUrl) {
        this.nextId = 1;
        this.pending = new Map();
        this.listeners = new Map();
        this.socket = new WebSocket(webSocketUrl);
        this.ready = new Promise((resolve, reject) => {
            this.socket.addEventListener('open', resolve, { once: true });
            this.socket.addEventListener('error', () => reject(new Error('Could not connect to Chrome DevTools.')), { once: true });
        });
        this.socket.addEventListener('message', (event) => this.handleMessage(event.data));
    }

    handleMessage(rawMessage) {
        const message = JSON.parse(rawMessage);
        if (message.id) {
            const pending = this.pending.get(message.id);
            if (!pending) return;
            this.pending.delete(message.id);
            message.error ? pending.reject(new Error(message.error.message)) : pending.resolve(message.result);
            return;
        }

        for (const listener of this.listeners.get(message.method) ?? []) listener(message.params);
    }

    on(method, listener) {
        const listeners = this.listeners.get(method) ?? [];
        listeners.push(listener);
        this.listeners.set(method, listeners);
    }

    async send(method, params = {}) {
        await this.ready;
        const id = this.nextId++;
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }

    async evaluate(expression) {
        const response = await this.send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        });
        if (response.exceptionDetails) fail(response.exceptionDetails.exception?.description ?? response.exceptionDetails.text);
        return response.result.value;
    }

    close() {
        this.socket.close();
    }
}

async function main() {
    if (!login || !password) fail('Set applicant profile smoke-test credentials.');
    const executable = browserPath();
    if (!executable) fail('Chrome or Edge was not found.');

    const port = await reservePort();
    const profileDir = await mkdtemp(path.join(tmpdir(), 'applicant-profile-modal-smoke-'));
    const browser = spawn(executable, [
        '--headless=new',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        '--window-size=1440,1000',
        `--remote-debugging-port=${port}`,
        `--user-data-dir=${profileDir}`,
        'about:blank',
    ], { stdio: 'ignore' });
    let client;

    try {
        const debuggerUrl = `http://127.0.0.1:${port}`;
        await waitFor(async () => (await fetch(`${debuggerUrl}/json/version`)).ok, 'the headless browser');
        const target = await (await fetch(`${debuggerUrl}/json/new?${encodeURIComponent(baseUrl.href)}`, { method: 'PUT' })).json();
        client = new CdpClient(target.webSocketDebuggerUrl);

        const runtimeErrors = [];
        const browserLogs = [];
        const requestErrors = [];
        client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
            runtimeErrors.push(exceptionDetails.exception?.description ?? exceptionDetails.text);
        });
        client.on('Runtime.consoleAPICalled', ({ type, args }) => {
            if (['error', 'warning'].includes(type)) browserLogs.push(args.map((argument) => argument.value ?? argument.description ?? '').join(' '));
        });
        client.on('Network.loadingFailed', ({ errorText }) => {
            if (errorText !== 'net::ERR_ABORTED') requestErrors.push(errorText);
        });

        await Promise.all([
            client.send('Page.enable'),
            client.send('Runtime.enable'),
            client.send('Network.enable'),
            client.send('Log.enable'),
        ]);

        await client.send('Page.navigate', { url: new URL('/login', baseUrl).href });
        await waitFor(() => client.evaluate("document.readyState === 'complete' && Boolean(document.querySelector('#login'))"), 'the login form');
        await client.evaluate(`(() => {
            const setValue = (selector, value) => {
                const element = document.querySelector(selector);
                element.value = value;
                element.dispatchEvent(new Event('input', { bubbles: true }));
            };
            setValue('#login', ${JSON.stringify(login)});
            setValue('#password', ${JSON.stringify(password)});
            document.querySelector('form').requestSubmit();
        })()`);
        try {
            await waitFor(() => client.evaluate("location.pathname !== '/login'"), 'applicant sign-in');
        } catch (error) {
            const loginSnapshot = await client.evaluate(`({
                url: location.href,
                body: document.body.innerText.slice(0, 900),
            })`);
            fail(`${error.message}: ${JSON.stringify({ loginSnapshot, runtimeErrors, browserLogs, requestErrors })}`);
        }

        await client.send('Page.navigate', { url: new URL('/dashboard/profile', baseUrl).href });
        try {
            await waitFor(
                () => client.evaluate("document.readyState === 'complete' && location.pathname === '/dashboard/profile' && document.body.innerText.toLowerCase().includes('applicant record')"),
                'the applicant profile',
            );
        } catch (error) {
            const profileSnapshot = await client.evaluate(`({
                url: location.href,
                title: document.title,
                body: document.body.innerText.slice(0, 1200),
                appHtml: document.querySelector('#app')?.innerHTML.slice(0, 800),
            })`);
            fail(`${error.message}: ${JSON.stringify({ profileSnapshot, runtimeErrors, browserLogs, requestErrors })}`);
        }
        await delay(300);

        const buttonLabels = await client.evaluate("[...document.querySelectorAll('button')].filter((button) => button.offsetParent !== null).map((button) => button.innerText.trim()).filter(Boolean)");
        const expectedSections = [
            ['personal', 'Personal and contact'],
            ['academic', 'Learning record'],
            ['household', 'Household and guardian'],
            ['location', 'Home location'],
        ];
        const audits = [];

        for (const [index, [section, expectedTitle]] of expectedSections.entries()) {
            const opened = await client.evaluate(`(() => {
                const editButtons = [...document.querySelectorAll('button')]
                    .filter((button) => button.offsetParent !== null && button.innerText.trim() === 'Edit');
                editButtons[${index}]?.click();
                return editButtons.length;
            })()`);
            if (opened <= index) fail(`Edit button ${index + 1} was not found. Buttons: ${buttonLabels.join(', ')}`);

            try {
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[role=\"dialog\"][aria-labelledby=\"profile-editor-title\"]'))"), `${section} profile edit modal`);
            } catch (error) {
                const snapshot = await client.evaluate(`({
                    url: location.href,
                    bodyOverflow: document.body.style.overflow,
                    dialogs: [...document.querySelectorAll('[role="dialog"]')].map((dialog) => ({ title: dialog.innerText.slice(0, 200), html: dialog.outerHTML.slice(0, 500) })),
                    bodyText: document.body.innerText.slice(0, 1200),
                })`);
                fail(`${error.message}: ${JSON.stringify({ section, snapshot, runtimeErrors, browserLogs, requestErrors })}`);
            }

            await delay(250);
            const audit = await client.evaluate(`(() => {
                const dialog = document.querySelector('[role="dialog"][aria-labelledby="profile-editor-title"]');
                const overlay = dialog?.parentElement;
                const dialogRect = dialog?.getBoundingClientRect();
                const overlayStyle = overlay ? getComputedStyle(overlay) : null;
                const dialogStyle = dialog ? getComputedStyle(dialog) : null;
                return {
                    url: location.href,
                    title: document.querySelector('#profile-editor-title')?.innerText,
                    bodyOverflow: document.body.style.overflow,
                    dialogRect: dialogRect ? { top: dialogRect.top, left: dialogRect.left, width: dialogRect.width, height: dialogRect.height } : null,
                    dialogDisplay: dialogStyle?.display,
                    dialogVisibility: dialogStyle?.visibility,
                    overlayPosition: overlayStyle?.position,
                    overlayZIndex: overlayStyle?.zIndex,
                };
            })()`);

            if (!audit.url.includes(`section=${section}`)) fail(`The ${section} modal did not preserve its section in the URL: ${audit.url}`);
            if (audit.title !== expectedTitle) fail(`The ${section} modal rendered the wrong title: ${audit.title}`);
            if (audit.bodyOverflow !== 'hidden') fail(`The ${section} modal did not lock background scrolling.`);
            if (!audit.dialogRect || audit.dialogRect.width <= 0 || audit.dialogRect.height <= 0) fail(`The ${section} modal has no visible bounds: ${JSON.stringify(audit)}`);
            if (runtimeErrors.length) fail(`The ${section} profile editor raised a browser exception: ${runtimeErrors[0]}`);
            if (requestErrors.length) fail(`The ${section} profile editor had failed requests: ${requestErrors.join(', ')}`);
            audits.push(audit);

            if (index < expectedSections.length - 1) {
                await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]')?.click()");
                await waitFor(
                    () => client.evaluate("!document.querySelector('[role=\"dialog\"][aria-labelledby=\"profile-editor-title\"]')"),
                    `${section} profile edit modal to close`,
                );
                const bodyOverflowAfterClose = await client.evaluate('document.body.style.overflow');
                if (bodyOverflowAfterClose) fail(`Closing the ${section} modal left body overflow set to ${bodyOverflowAfterClose}.`);
            }
        }

        if (screenshotPath) {
            const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
            await writeFile(path.resolve(screenshotPath), Buffer.from(screenshot.data, 'base64'));
        }

        console.log(`PASS Applicant profile edit modals: ${JSON.stringify(audits)}`);
    } finally {
        client?.close();
        if (browser.exitCode === null) {
            browser.kill();
            await Promise.race([new Promise((resolve) => browser.once('exit', resolve)), delay(3_000)]);
        }
        await rm(profileDir, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
}

main().catch((error) => {
    console.error(`FAIL ${error.message}`);
    process.exitCode = 1;
});
