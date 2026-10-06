import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const baseUrl = new URL(process.env.PROVIDER_SMOKE_BASE_URL ?? 'http://127.0.0.1:8000');
const login = process.env.PROVIDER_SMOKE_LOGIN;
const password = process.env.PROVIDER_SMOKE_PASSWORD;
const timeoutMs = Number(process.env.PROVIDER_SMOKE_TIMEOUT_MS ?? 20_000);

const workspaces = [
    ['/provider/workspaces/programs', 'Program coordination'],
    ['/provider/workspaces/reviews', 'Application verification'],
    ['/provider/workspaces/selection', 'Selection activities'],
    ['/provider/workspaces/decisions', 'Final decisions'],
    ['/provider/workspaces/recipients', 'Recipient onboarding'],
    ['/provider/workspaces/monitoring', 'Recipient monitoring'],
    ['/provider/workspaces/releases', 'Benefit distribution'],
    ['/provider/workspaces/organization-profile', 'Organization profile'],
    ['/provider/workspaces/team', 'Team access'],
    ['/provider/workspaces/support', 'Support desk'],
    ['/provider/profile/details', 'Provider details', 'Loading provider profile...'],
    ['/provider/applications', 'Applicant workflow', 'Loading applicants...'],
    ['/provider/programs', 'Programs', 'Loading scholarship programs...'],
    ['/provider/monitoring', 'Recipient monitoring', 'Loading programs...'],
];

function fail(message) {
    throw new Error(message);
}

function browserPath() {
    const configured = process.env.PROVIDER_SMOKE_BROWSER;
    const candidates = [
        configured,
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
            if (result) {
                return result;
            }
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

    const address = server.address();
    await new Promise((resolve) => server.close(resolve));

    return address.port;
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
            if (!pending) {
                return;
            }

            this.pending.delete(message.id);
            if (message.error) {
                pending.reject(new Error(message.error.message));
            } else {
                pending.resolve(message.result);
            }
            return;
        }

        for (const listener of this.listeners.get(message.method) ?? []) {
            listener(message.params);
        }
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

        if (response.exceptionDetails) {
            fail(response.exceptionDetails.exception?.description ?? response.exceptionDetails.text);
        }

        return response.result.value;
    }

    close() {
        this.socket.close();
    }
}

async function main() {
    if (!login || !password) {
        fail('Set PROVIDER_SMOKE_LOGIN and PROVIDER_SMOKE_PASSWORD to a verified provider manager account.');
    }

    const executable = browserPath();
    if (!executable) {
        fail('Chrome or Edge was not found. Set PROVIDER_SMOKE_BROWSER to a Chromium executable.');
    }

    const port = await reservePort();
    const profileDir = await mkdtemp(path.join(tmpdir(), 'provider-workspace-smoke-'));
    const browser = spawn(executable, [
        '--headless=new',
        '--disable-gpu',
        '--no-first-run',
        '--no-default-browser-check',
        `--remote-debugging-port=${port}`,
        `--user-data-dir=${profileDir}`,
        'about:blank',
    ], { stdio: 'ignore' });
    let client;

    try {
        const debuggerUrl = `http://127.0.0.1:${port}`;
        await waitFor(async () => {
            const response = await fetch(`${debuggerUrl}/json/version`);
            return response.ok;
        }, 'the headless browser');

        const targetResponse = await fetch(`${debuggerUrl}/json/new?${encodeURIComponent(baseUrl.href)}`, {
            method: 'PUT',
        });
        const target = await targetResponse.json();
        client = new CdpClient(target.webSocketDebuggerUrl);

        const runtimeErrors = [];
        const requestErrors = [];
        client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
            runtimeErrors.push(exceptionDetails.exception?.description ?? exceptionDetails.text);
        });
        client.on('Network.responseReceived', ({ response }) => {
            const responseUrl = new URL(response.url);
            if (responseUrl.origin === baseUrl.origin
                && (responseUrl.pathname.startsWith('/provider/workspaces/')
                    || responseUrl.pathname === '/provider/profile/data'
                    || responseUrl.pathname === '/provider/applications/data'
                    || responseUrl.pathname === '/provider/dashboard/data'
                    || responseUrl.pathname === '/provider/scholarships')
                && response.status >= 400) {
                requestErrors.push(`${response.status} ${responseUrl.pathname}`);
            }
        });

        await Promise.all([
            client.send('Page.enable'),
            client.send('Runtime.enable'),
            client.send('Network.enable'),
        ]);

        await client.send('Page.navigate', { url: new URL('/login', baseUrl).href });
        await waitFor(
            () => client.evaluate("document.readyState === 'complete' && Boolean(document.querySelector('#login'))"),
            'the login form',
        );
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
        await waitFor(
            () => client.evaluate("location.pathname !== '/login'"),
            'provider sign-in',
        );

        for (const [workspacePath, heading, loadingText = null] of workspaces) {
            runtimeErrors.length = 0;
            requestErrors.length = 0;
            await client.send('Page.navigate', { url: new URL(workspacePath, baseUrl).href });
            await waitFor(
                () => client.evaluate(`document.readyState === 'complete'
                    && location.pathname === ${JSON.stringify(workspacePath)}
                    && document.body.innerText.includes(${JSON.stringify(heading)})
                    && (${JSON.stringify(loadingText)} === null
                        || !document.body.innerText.includes(${JSON.stringify(loadingText)}))`),
                heading,
            );
            await delay(250);

            const unavailable = await client.evaluate("/is unavailable|unable to load/i.test(document.body.innerText)");
            if (unavailable) {
                fail(`${heading} rendered its unavailable state.`);
            }
            if (requestErrors.length) {
                fail(`${heading} had failed workspace requests: ${requestErrors.join(', ')}`);
            }
            if (runtimeErrors.length) {
                fail(`${heading} raised a browser exception: ${runtimeErrors[0]}`);
            }

            console.log(`PASS ${heading}`);
        }
    } finally {
        client?.close();
        if (browser.exitCode === null) {
            browser.kill();
            await Promise.race([
                new Promise((resolve) => browser.once('exit', resolve)),
                delay(3_000),
            ]);
        }
        await rm(profileDir, {
            recursive: true,
            force: true,
            maxRetries: 10,
            retryDelay: 200,
        });
    }
}

main().catch((error) => {
    console.error(`FAIL ${error.message}`);
    process.exitCode = 1;
});
