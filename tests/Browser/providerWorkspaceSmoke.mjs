import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const baseUrl = new URL(process.env.PROVIDER_SMOKE_BASE_URL ?? 'http://127.0.0.1:8000');
const login = process.env.PROVIDER_SMOKE_LOGIN;
const password = process.env.PROVIDER_SMOKE_PASSWORD;
const sessionCookie = process.env.PROVIDER_SMOKE_SESSION_COOKIE;
const sessionCookieName = process.env.PROVIDER_SMOKE_SESSION_COOKIE_NAME;
const timeoutMs = Number(process.env.PROVIDER_SMOKE_TIMEOUT_MS ?? 20_000);
const verifyFilePreview = process.env.PROVIDER_SMOKE_FILE_PREVIEW === '1';
const windowSize = process.env.PROVIDER_SMOKE_WINDOW_SIZE ?? '1440,1000';
const screenshotPath = process.env.PROVIDER_SMOKE_SCREENSHOT;

const governanceWorkspaces = [
    ['/provider', 'Provider dashboard', 'Loading organization overview...'],
];

const operationalWorkspaces = [
    ['/provider/programs', 'Programs', 'Loading programs...'],
    ['/provider/programs/drafts', 'Drafts & changes', 'Loading programs...'],
    ['/provider/programs/review', 'Under review', 'Loading programs...'],
    ['/provider/programs/published', 'Published programs', 'Loading programs...'],
    ['/provider/programs/closed', 'Closed programs', 'Loading programs...'],
    ['/provider/workspaces/reviews', 'My review queue'],
    ['/provider/workspaces/reviews/assigned', 'My review queue'],
    ['/provider/workspaces/reviews/unassigned', 'Unassigned applications'],
    ['/provider/workspaces/reviews/returned', 'Returned updates'],
    ['/provider/workspaces/reviews/history', 'Review history'],
    ['/provider/workspaces/selection', 'Selection sessions'],
    ['/provider/workspaces/selection/setup', 'Unassigned applicants'],
    ['/provider/workspaces/selection/unassigned', 'Unassigned applicants'],
    ['/provider/workspaces/selection/results', 'Results to record'],
    ['/provider/workspaces/selection/active', 'Selection sessions'],
    ['/provider/workspaces/selection/history', 'Session history'],
    ['/provider/workspaces/decisions', 'Pending decisions'],
    ['/provider/workspaces/decisions/pending', 'Pending decisions'],
    ['/provider/workspaces/decisions/waitlist', 'Waitlist'],
    ['/provider/workspaces/decisions/recorded', 'Decision history'],
    ['/provider/workspaces/recipients', 'Recipient dashboard'],
    ['/provider/workspaces/recipients/agreements', 'Agreement responses'],
    ['/provider/workspaces/recipients/active', 'Active recipients'],
    ['/provider/workspaces/recipients/declined', 'Declined responses'],
    ['/provider/workspaces/recipients/closed', 'Closed recipient records'],
    ['/provider/workspaces/monitoring', 'Monitoring dashboard'],
    ['/provider/workspaces/monitoring/review', 'Monitoring review'],
    ['/provider/workspaces/monitoring/follow-ups', 'Recipient follow-ups'],
    ['/provider/workspaces/monitoring/awaiting', 'Awaiting uploads'],
    ['/provider/workspaces/monitoring/history', 'Check-in history'],
    ['/provider/workspaces/releases', 'Benefit release dashboard'],
    ['/provider/workspaces/releases/issues', 'Reported release issues'],
    ['/provider/workspaces/releases/record', 'Record distribution'],
    ['/provider/workspaces/releases/upcoming', 'Upcoming releases'],
    ['/provider/workspaces/releases/history', 'Release history'],
    ['/provider/workspaces/organization-profile', 'Profile dashboard'],
    ['/provider/workspaces/organization-profile/readiness', 'Profile readiness'],
    ['/provider/workspaces/organization-profile/public', 'Public profile'],
    ['/provider/workspaces/organization-profile/contact', 'Contact and location'],
    ['/provider/workspaces/organization-profile/verification', 'Legal verification'],
    ['/provider/workspaces/team', 'Team access dashboard'],
    ['/provider/workspaces/team/setup', 'Setup required'],
    ['/provider/workspaces/team/active', 'Active accounts'],
    ['/provider/workspaces/team/suspended', 'Suspended accounts'],
    ['/provider/team/accounts/create', 'Add team member'],
    ['/provider/workspaces/support', 'Support dashboard'],
    ['/provider/workspaces/support/needs-response', 'Needs response'],
    ['/provider/workspaces/support/waiting', 'Waiting for platform'],
    ['/provider/workspaces/support/platform', 'Platform reports'],
    ['/provider/workspaces/support/resolved', 'Resolved cases'],
    ['/provider/workspaces/support/report-platform-issue', 'Report a platform issue'],
    ['/provider/workspaces/billing', 'Service request dashboard'],
    ['/provider/workspaces/billing/action', 'Requests needing action'],
    ['/provider/workspaces/billing/active', 'Services in progress'],
    ['/provider/workspaces/billing/waiting', 'Waiting to start'],
    ['/provider/workspaces/billing/completed', 'Completed requests'],
    ['/provider/workspaces/billing/services', 'Optional support services'],
    ['/provider/workspaces/billing/services/application_cycle_support', 'Application cycle support'],
];
const workspaceScope = process.env.PROVIDER_SMOKE_SCOPE ?? 'operations';
const workspacePathFilter = process.env.PROVIDER_SMOKE_PATH ?? '';
const workspaceHeading = process.env.PROVIDER_SMOKE_HEADING ?? '';
const scopedWorkspaces = workspaceScope === 'governance'
    ? governanceWorkspaces
    : (workspaceScope === 'all' ? [...governanceWorkspaces, ...operationalWorkspaces] : operationalWorkspaces);
const filteredWorkspaces = workspacePathFilter
    ? scopedWorkspaces.filter(([workspacePath]) => workspacePath.startsWith(workspacePathFilter))
    : scopedWorkspaces;
const exactWorkspaceOverride = workspacePathFilter
    && workspaceHeading
    && filteredWorkspaces.length === 1
    && filteredWorkspaces[0][0] === workspacePathFilter;
const workspaceOverrides = process.env.PROVIDER_SMOKE_WORKSPACES
    ? JSON.parse(process.env.PROVIDER_SMOKE_WORKSPACES)
    : null;
const workspaces = workspaceOverrides ?? (exactWorkspaceOverride
    ? [[workspacePathFilter, workspaceHeading]]
    : (filteredWorkspaces.length || !workspacePathFilter || !workspaceHeading
        ? filteredWorkspaces
        : [[workspacePathFilter, workspaceHeading]]));

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
    if ((!login || !password) && (!sessionCookie || !sessionCookieName)) {
        fail('Set login credentials or a browser QA session cookie.');
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
        `--window-size=${windowSize}`,
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
        const browserLogs = [];
        client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => {
            runtimeErrors.push(exceptionDetails.exception?.description ?? exceptionDetails.text);
        });
        client.on('Runtime.consoleAPICalled', ({ type, args }) => {
            if (['error', 'warning'].includes(type)) {
                browserLogs.push(args.map((argument) => argument.value ?? argument.description ?? '').join(' '));
            }
        });
        client.on('Log.entryAdded', ({ entry }) => {
            if (['error', 'warning'].includes(entry.level)) {
                browserLogs.push(entry.text);
            }
        });
        client.on('Network.loadingFailed', ({ errorText, blockedReason }) => {
            if (errorText === 'net::ERR_ABORTED') {
                return;
            }
            requestErrors.push(blockedReason ? `${errorText} (${blockedReason})` : errorText);
        });
        client.on('Network.responseReceived', ({ response }) => {
            const responseUrl = new URL(response.url);
            if (responseUrl.origin === baseUrl.origin
                && (responseUrl.pathname.startsWith('/provider/workspaces/')
                    || responseUrl.pathname.startsWith('/provider/applications/')
                    || responseUrl.pathname.startsWith('/admin/workspaces/')
                    || responseUrl.pathname.startsWith('/admin/governance/')
                    || responseUrl.pathname.startsWith('/dashboard/monitoring/')
                    || responseUrl.pathname === '/provider/profile/data'
                    || responseUrl.pathname === '/provider/applications/data'
                    || responseUrl.pathname === '/provider/dashboard/data'
                    || responseUrl.pathname === '/admin/dashboard/data'
                    || responseUrl.pathname === '/provider/scholarships')
                && response.status >= 400) {
                requestErrors.push(`${response.status} ${responseUrl.pathname}`);
            }
        });

        await Promise.all([
            client.send('Page.enable'),
            client.send('Runtime.enable'),
            client.send('Network.enable'),
            client.send('Log.enable'),
        ]);

        if (sessionCookie && sessionCookieName) {
            const cookieResult = await client.send('Network.setCookie', {
                name: sessionCookieName,
                value: sessionCookie,
                url: baseUrl.href,
                httpOnly: true,
                sameSite: 'Lax',
            });
            if (!cookieResult.success) {
                fail('The browser QA session cookie could not be set.');
            }
        } else {
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
            try {
                await waitFor(
                    () => client.evaluate("location.pathname !== '/login'"),
                    'role sign-in',
                );
            } catch (error) {
                const loginSnapshot = await client.evaluate(`({
                    url: location.href,
                    body: document.body.innerText.slice(0, 800),
                    runtimeErrors: ${JSON.stringify(runtimeErrors)},
                    requestErrors: ${JSON.stringify(requestErrors)},
                    browserLogs: ${JSON.stringify(browserLogs)},
                })`);
                fail(`${error.message}: ${JSON.stringify(loginSnapshot)}`);
            }
        }

        let filePreviewVerified = false;

        for (const [workspacePath, heading, loadingText = null] of workspaces) {
            runtimeErrors.length = 0;
            requestErrors.length = 0;
            browserLogs.length = 0;
            const workspaceUrl = new URL(workspacePath, baseUrl);
            await client.send('Page.navigate', { url: workspaceUrl.href });
            try {
                await waitFor(
                    () => client.evaluate(`document.readyState === 'complete'
                        && location.pathname === ${JSON.stringify(workspaceUrl.pathname)}
                        && location.search === ${JSON.stringify(workspaceUrl.search)}
                        && document.body.innerText.toLowerCase().includes(${JSON.stringify(heading.toLowerCase())})
                        && (${JSON.stringify(loadingText)} === null
                            || !document.body.innerText.includes(${JSON.stringify(loadingText)}))`),
                    heading,
                );
            } catch (error) {
                if (runtimeErrors.length) {
                    fail(`${heading} raised a browser exception: ${runtimeErrors[0]}`);
                }
                if (requestErrors.length) {
                    fail(`${heading} had failed workspace requests: ${requestErrors.join(', ')}`);
                }
                if (browserLogs.length) {
                    fail(`${heading} logged a browser error: ${browserLogs[0]}`);
                }
                const snapshot = await client.evaluate(`({
                    url: location.href,
                    title: document.title,
                    body: document.body.innerText.slice(0, 500),
                    appPage: document.querySelector('#app')?.dataset.page ?? null,
                    appHtml: document.querySelector('#app')?.innerHTML.slice(0, 500) ?? null,
                })`);
                fail(`${heading} did not render: ${JSON.stringify(snapshot)}`);
            }
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

            const visualAudit = await client.evaluate(`(() => {
                const visible = (element) => {
                    const style = getComputedStyle(element);
                    const rect = element.getBoundingClientRect();
                    return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
                };
                const parseColor = (value) => {
                    const canvas = document.createElement('canvas');
                    canvas.width = 1;
                    canvas.height = 1;
                    const context = canvas.getContext('2d', { willReadFrequently: true });
                    context.clearRect(0, 0, 1, 1);
                    context.fillStyle = value;
                    context.fillRect(0, 0, 1, 1);
                    return [...context.getImageData(0, 0, 1, 1).data].slice(0, 3);
                };
                const luminance = (rgb) => {
                    const channels = rgb.map((channel) => {
                        const value = channel / 255;
                        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
                    });
                    return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
                };
                const contrast = (foreground, background) => {
                    const lighter = Math.max(luminance(foreground), luminance(background));
                    const darker = Math.min(luminance(foreground), luminance(background));
                    return (lighter + 0.05) / (darker + 0.05);
                };
                const backgroundFor = (element) => {
                    let current = element;
                    while (current) {
                        const color = getComputedStyle(current).backgroundColor;
                        if (color && !color.endsWith(', 0)') && color !== 'transparent') return parseColor(color);
                        current = current.parentElement;
                    }
                    return [255, 255, 255];
                };
                const ignoredTypes = new Set(['checkbox', 'radio', 'hidden', 'file', 'color', 'range']);
                const lowContrastControls = [...document.querySelectorAll('input, select, textarea')]
                    .filter((control) => visible(control) && !control.disabled && !ignoredTypes.has(control.type))
                    .filter((control) => {
                        const foreground = parseColor(getComputedStyle(control).color);
                        const background = backgroundFor(control);
                        return foreground && background && contrast(foreground, background) < 3;
                    })
                    .map((control) => control.getAttribute('aria-label') || control.name || control.placeholder || control.tagName.toLowerCase())
                    .slice(0, 5);
                const duplicateActiveNavigations = [...document.querySelectorAll('nav')]
                    .filter(visible)
                    .map((navigation) => ({
                        label: navigation.getAttribute('aria-label') || 'navigation',
                        active: navigation.querySelectorAll('[aria-current="page"]').length,
                    }))
                    .filter((navigation) => navigation.active > 1);

                return {
                    horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
                    objectLeak: document.body.innerText.includes('[object Object]'),
                    lowContrastControls,
                    duplicateActiveNavigations,
                };
            })()`);

            if (visualAudit.horizontalOverflow) {
                fail(`${heading} causes page-level horizontal overflow at desktop width.`);
            }
            if (visualAudit.objectLeak) {
                fail(`${heading} renders [object Object] in visible content.`);
            }
            if (visualAudit.lowContrastControls.length) {
                fail(`${heading} has low-contrast controls: ${visualAudit.lowContrastControls.join(', ')}`);
            }
            if (visualAudit.duplicateActiveNavigations.length) {
                fail(`${heading} has conflicting active navigation items.`);
            }

            if (screenshotPath) {
                const screenshot = await client.send('Page.captureScreenshot', {
                    format: 'png',
                    captureBeyondViewport: true,
                });
                await writeFile(path.resolve(screenshotPath), Buffer.from(screenshot.data, 'base64'));
            }

            if (verifyFilePreview && !filePreviewVerified) {
                const locationBeforePreview = await client.evaluate('location.href');
                const previewHostReady = await client.evaluate("Boolean(document.querySelector('#staff-file-preview-host'))");
                if (!previewHostReady) {
                    fail('The global staff file preview host was not mounted.');
                }
                await client.evaluate(`(() => {
                    const link = document.createElement('a');
                    link.href = '/favicon.ico';
                    link.dataset.filePreview = 'true';
                    link.dataset.fileName = 'Test image';
                    link.textContent = 'Open test image';
                    document.body.appendChild(link);
                    link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
                })()`);
                try {
                    await waitFor(
                        () => client.evaluate("Boolean(document.querySelector('[role=\"dialog\"][aria-labelledby=\"staff-file-preview-title\"]'))"),
                        'the staff file preview modal',
                    );
                } catch (error) {
                    const previewSnapshot = await client.evaluate(`({
                        hostHtml: document.querySelector('#staff-file-preview-host')?.innerHTML,
                        bodyLocked: document.body.classList.contains('overflow-hidden'),
                    })`);
                    fail(`${error.message}: ${JSON.stringify({ previewSnapshot, runtimeErrors, browserLogs })}`);
                }

                const previewAudit = await client.evaluate(`({
                    location: location.href,
                    title: document.querySelector('#staff-file-preview-title')?.textContent?.trim(),
                    bodyLocked: document.body.classList.contains('overflow-hidden'),
                })`);

                if (previewAudit.location !== locationBeforePreview) {
                    fail('Opening a staff file preview changed the workspace URL.');
                }
                if (previewAudit.title !== 'Test image' || !previewAudit.bodyLocked) {
                    fail(`The staff file preview did not initialize correctly: ${JSON.stringify(previewAudit)}`);
                }

                await client.evaluate("document.querySelector('[aria-label=\"Close file preview\"]')?.click()");
                await waitFor(
                    () => client.evaluate("!document.querySelector('[role=\"dialog\"][aria-labelledby=\"staff-file-preview-title\"]')"),
                    'the staff file preview modal to close',
                );
                filePreviewVerified = true;
                console.log('PASS Staff file preview modal');
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
