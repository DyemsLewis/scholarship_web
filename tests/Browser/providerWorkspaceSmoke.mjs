import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const baseUrl = new URL(process.env.PROVIDER_SMOKE_BASE_URL ?? 'http://127.0.0.1:8000');
const login = process.env.PROVIDER_SMOKE_LOGIN;
const password = process.env.PROVIDER_SMOKE_PASSWORD;
const timeoutMs = Number(process.env.PROVIDER_SMOKE_TIMEOUT_MS ?? 20_000);

const governanceWorkspaces = [
    ['/provider', 'Governance overview'],
    ['/provider?view=responsibilities', 'Responsibility coverage'],
    ['/provider?view=access', 'Operating model'],
    ['/provider?view=activity', 'Governance activity'],
];

const operationalWorkspaces = [
    ['/provider/workspaces/programs', 'Program coordination'],
    ['/provider/workspaces/programs/drafts', 'Drafts and requested changes'],
    ['/provider/workspaces/programs/review', 'Programs in admin review'],
    ['/provider/workspaces/programs/published', 'Published programs'],
    ['/provider/workspaces/programs/closed', 'Closed programs'],
    ['/provider/workspaces/reviews', 'Assigned reviews'],
    ['/provider/workspaces/reviews/assigned', 'Assigned reviews'],
    ['/provider/workspaces/reviews/unassigned', 'Unassigned applications'],
    ['/provider/workspaces/reviews/returned', 'Returned corrections'],
    ['/provider/workspaces/reviews/history', 'Review history'],
    ['/provider/workspaces/selection', 'Activity setup'],
    ['/provider/workspaces/selection/setup', 'Activity setup'],
    ['/provider/workspaces/selection/results', 'Results to record'],
    ['/provider/workspaces/selection/active', 'Active pipeline'],
    ['/provider/workspaces/decisions', 'Pending decisions'],
    ['/provider/workspaces/decisions/pending', 'Pending decisions'],
    ['/provider/workspaces/decisions/waitlist', 'Waitlist'],
    ['/provider/workspaces/decisions/recorded', 'Decision history'],
    ['/provider/workspaces/recipients', 'Agreement responses'],
    ['/provider/workspaces/recipients/agreements', 'Agreement responses'],
    ['/provider/workspaces/recipients/active', 'Active recipients'],
    ['/provider/workspaces/recipients/declined', 'Declined responses'],
    ['/provider/workspaces/recipients/closed', 'Closed recipient records'],
    ['/provider/workspaces/monitoring', 'Monitoring review'],
    ['/provider/workspaces/monitoring/review', 'Monitoring review'],
    ['/provider/workspaces/monitoring/follow-ups', 'Recipient follow-ups'],
    ['/provider/workspaces/monitoring/awaiting', 'Awaiting uploads'],
    ['/provider/workspaces/monitoring/history', 'Check-in history'],
    ['/provider/workspaces/releases', 'Reported release issues'],
    ['/provider/workspaces/releases/issues', 'Reported release issues'],
    ['/provider/workspaces/releases/record', 'Record distribution'],
    ['/provider/workspaces/releases/upcoming', 'Upcoming releases'],
    ['/provider/workspaces/releases/history', 'Release history'],
    ['/provider/workspaces/organization-profile', 'Profile readiness'],
    ['/provider/workspaces/organization-profile/readiness', 'Profile readiness'],
    ['/provider/workspaces/team', 'Setup required'],
    ['/provider/workspaces/team/setup', 'Setup required'],
    ['/provider/workspaces/team/active', 'Active staff accounts'],
    ['/provider/workspaces/team/suspended', 'Suspended staff accounts'],
    ['/provider/workspaces/support', 'Needs response'],
    ['/provider/workspaces/support/needs-response', 'Needs response'],
    ['/provider/workspaces/support/waiting', 'Waiting for platform'],
    ['/provider/workspaces/support/platform', 'Platform reports'],
    ['/provider/workspaces/support/resolved', 'Resolved cases'],
    ['/provider/workspaces/billing', 'Requests needing action'],
    ['/provider/workspaces/billing/action', 'Requests needing action'],
    ['/provider/workspaces/billing/active', 'Services in progress'],
    ['/provider/workspaces/billing/waiting', 'Waiting to start'],
    ['/provider/workspaces/billing/completed', 'Completed requests'],
    ['/provider/workspaces/billing/services', 'Optional support services', 'Loading support services...'],
    ['/provider/profile/details', 'Provider details', 'Loading provider profile...'],
    ['/provider/applications', 'Applicant workflow', 'Loading applicants...'],
    ['/provider/programs', 'Programs', 'Loading scholarship programs...'],
    ['/provider/monitoring', 'Recipient monitoring', 'Loading programs...'],
];
const workspaceScope = process.env.PROVIDER_SMOKE_SCOPE ?? 'operations';
const workspacePathFilter = process.env.PROVIDER_SMOKE_PATH ?? '';
const scopedWorkspaces = workspaceScope === 'governance'
    ? governanceWorkspaces
    : (workspaceScope === 'all' ? [...governanceWorkspaces, ...operationalWorkspaces] : operationalWorkspaces);
const workspaces = workspacePathFilter
    ? scopedWorkspaces.filter(([workspacePath]) => workspacePath.startsWith(workspacePathFilter))
    : scopedWorkspaces;

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
        '--window-size=1440,1000',
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
            const workspaceUrl = new URL(workspacePath, baseUrl);
            await client.send('Page.navigate', { url: workspaceUrl.href });
            await waitFor(
                () => client.evaluate(`document.readyState === 'complete'
                    && location.pathname === ${JSON.stringify(workspaceUrl.pathname)}
                    && location.search === ${JSON.stringify(workspaceUrl.search)}
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

            if (workspaceUrl.pathname === '/provider/workspaces/support') {
                await client.evaluate(`[...document.querySelectorAll('button')]
                    .find((button) => button.innerText.includes('Contact platform'))?.click()`);
                await waitFor(
                    () => client.evaluate("Boolean(document.querySelector('[role=\"dialog\"] select'))"),
                    'the platform support dialog',
                );
                const dialogContrast = await client.evaluate(`(() => {
                    const control = document.querySelector('[role="dialog"] select');
                    const colorToRgb = (value) => {
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
                            const normalized = channel / 255;
                            return normalized <= 0.03928 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
                        });
                        return (0.2126 * channels[0]) + (0.7152 * channels[1]) + (0.0722 * channels[2]);
                    };
                    const foreground = colorToRgb(getComputedStyle(control).color);
                    const background = colorToRgb(getComputedStyle(control).backgroundColor);
                    const lighter = Math.max(luminance(foreground), luminance(background));
                    const darker = Math.min(luminance(foreground), luminance(background));
                    return {
                        color: getComputedStyle(control).color,
                        background: getComputedStyle(control).backgroundColor,
                        ratio: (lighter + 0.05) / (darker + 0.05),
                    };
                })()`);
                if (dialogContrast.ratio < 4.5) {
                    fail(`Support modal controls are not using readable light-theme colors: ${JSON.stringify(dialogContrast)}.`);
                }
                await client.evaluate(`document.querySelector('[role="dialog"] [aria-label="Close form"]')?.click()`);
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
