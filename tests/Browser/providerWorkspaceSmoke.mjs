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
const verifyMapPin = process.env.PROVIDER_SMOKE_MAP_PIN === '1';
const verifyEligibilityBuilder = process.env.PROVIDER_SMOKE_ELIGIBILITY_BUILDER === '1';
const verifyProgramConsistency = process.env.PROVIDER_SMOKE_PROGRAM_CONSISTENCY === '1';
const windowSize = process.env.PROVIDER_SMOKE_WINDOW_SIZE ?? '1440,1000';
const screenshotPath = process.env.PROVIDER_SMOKE_SCREENSHOT;
const verifyProfileRecord = process.env.PROVIDER_SMOKE_PROFILE_RECORD === '1';
const expectProfileRedactions = process.env.PROVIDER_SMOKE_PROFILE_REDACTIONS === '1';
const modalChecks = JSON.parse(process.env.PORTAL_SMOKE_MODAL_CHECKS ?? '[]');
const verifyScholarshipEligibility = process.env.PORTAL_SMOKE_SCHOLARSHIP_ELIGIBILITY === '1';
const verifyScholarshipPreparation = process.env.PORTAL_SMOKE_SCHOLARSHIP_PREPARATION === '1';
const verifyPreparationPreview = process.env.PORTAL_SMOKE_PREPARATION_PREVIEW === '1';
const verifyScholarshipRequirements = process.env.PORTAL_SMOKE_SCHOLARSHIP_REQUIREMENTS === '1';
const verifyPreparationHandoff = process.env.PORTAL_SMOKE_PREPARATION_HANDOFF === '1';
const verifyPreparationStates = process.env.PORTAL_SMOKE_PREPARATION_STATES === '1';
const handoffScreenshotDirectory = process.env.PORTAL_SMOKE_HANDOFF_SCREENSHOT_DIRECTORY;
const screenshotSelector = process.env.PORTAL_SMOKE_SCREENSHOT_SELECTOR;

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
const workspaceOverrideInput = process.env.PROVIDER_SMOKE_WORKSPACES
    ? JSON.parse(process.env.PROVIDER_SMOKE_WORKSPACES)
    : null;
const workspaceOverrides = typeof workspaceOverrideInput?.[0] === 'string' ? [workspaceOverrideInput] : workspaceOverrideInput;
const workspaces = workspaceOverrides ?? (exactWorkspaceOverride
    ? [[workspacePathFilter, workspaceHeading]]
    : (filteredWorkspaces.length || !workspacePathFilter || !workspaceHeading
        ? filteredWorkspaces
        : [[workspacePathFilter, workspaceHeading]]));

function fail(message) {
    throw new Error(message);
}

const preparationStateCases = [
    { key: 'incomplete', state: 'profile_incomplete', step: 'profile', status: 'incomplete', label: 'Details needed' },
    { key: 'missing-evidence', state: 'profile_unverified', step: 'profile', status: 'evidence_missing', label: 'Evidence needed' },
    { key: 'pending', state: 'profile_unverified', step: 'profile', status: 'pending', label: 'Waiting for review' },
    { key: 'returned', state: 'profile_unverified', step: 'profile', status: 'changes_required', label: 'Changes requested' },
    { key: 'required-mismatch', state: 'blocked', step: 'eligibility', status: 'blocked', label: 'Required rules not met' },
    { key: 'preferences', state: 'eligible', step: 'submit', status: 'preferences_differ', label: 'Preferences differ', starts: true },
    { key: 'manual-review', state: 'eligible', step: 'submit', status: 'needs_review', label: 'Provider review needed', starts: true },
    { key: 'missing-files', state: 'eligible', step: 'requirements', status: 'needs_files', label: 'Files needed', starts: true },
    { key: 'no-files', state: 'eligible', step: 'submit', status: 'not_required', label: 'No initial files', starts: true },
    { key: 'email', state: 'eligible', step: 'submit', status: 'email_unverified', label: 'Verify email' },
    ...['upcoming', 'closed', 'full'].map((key) => ({ key, state: key, step: null })),
    { key: 'existing', state: 'applied', step: null },
];

function preparationStateFixture(payload, example) {
    const detail = payload.detail;
    const guide = detail.preparation;
    const rows = Object.fromEntries(guide.steps.map((step) => [step.key, step]));
    const start = { kind: 'link', label: 'Start pre-screening', href: `/dashboard/applications/start/${detail.identity.id}` };
    guide.state = detail.decision.state = example.state;
    guide.can_start_application = detail.decision.can_apply = example.starts === true;
    guide.next_step = example.step;
    guide.has_applied = detail.decision.has_applied = example.key === 'existing';
    rows.submit.action = example.starts ? start : { kind: 'disabled', label: 'Start pre-screening', disabled: true, href: null };
    detail.decision.title = `Browser-only fixture: ${example.key}`;
    detail.saved.is_saved = false;
    detail.saved.endpoint = `/dashboard/scholarships/${detail.identity.id}/save`;
    if (example.step === 'profile') {
        Object.assign(rows.profile, { status: example.status, status_label: example.label, tone: 'attention', blocks_start: true,
            description: example.key === 'pending' ? 'Your supporting evidence is awaiting review.' : 'Review the profile details and evidence that need your attention.',
            details: example.key === 'incomplete' ? { missing_fields: [{ label: 'School' }] } : {} });
    } else if (['required-mismatch', 'preferences', 'manual-review'].includes(example.key)) {
        Object.assign(rows.eligibility, { status: example.status, status_label: example.label, tone: example.key === 'required-mismatch' ? 'blocked' : 'attention', blocks_start: example.key === 'required-mismatch' });
    } else if (['missing-files', 'no-files'].includes(example.key)) {
        Object.assign(rows.requirements, { status: example.status, status_label: example.label, tone: example.key === 'missing-files' ? 'attention' : 'neutral' });
        Object.assign(rows.requirements.details, { saved_file_count: 0, missing_file_count: example.key === 'missing-files' ? rows.requirements.details.required_file_count : 0 });
        if (example.key === 'no-files') Object.assign(rows.requirements.details, { required_file_count: 0, question_count: 0, required_question_count: 0 });
    } else if (example.key === 'email') {
        Object.assign(rows.submit, { status: example.status, status_label: example.label, tone: 'attention', action: { kind: 'link', label: 'Review email verification', href: '/dashboard' } });
    } else if (example.key === 'existing') {
        rows.submit.action = { kind: 'link', label: 'View application', href: '/dashboard/applications/1' };
    }
    guide.next_action = example.step ? rows[example.step].action : example.key === 'existing' ? rows.submit.action : { kind: 'save', label: 'Save scholarship' };
    detail.decision.action = guide.next_action;
    return payload;
}

function stableModalBackground(before, after) {
    return Object.keys(before).every((key) => before[key] === null ? after[key] === null
        : typeof before[key] === 'object' ? stableModalBackground(before[key], after[key])
            : Math.abs(before[key] - after[key]) <= 1);
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

        let activePreparationFixture = null;
        const fixtureErrors = [];
        if (verifyPreparationStates) {
            await client.send('Fetch.enable', { patterns: [
                { urlPattern: `${baseUrl.origin}/dashboard/scholarships/*/data*`, requestStage: 'Response' },
                { urlPattern: `${baseUrl.origin}/dashboard/scholarships/*/save`, requestStage: 'Request' },
            ] });
            client.on('Fetch.requestPaused', (event) => {
                (async () => {
                    if (event.request.url.endsWith('/save')) {
                        if (!activePreparationFixture || !['POST', 'DELETE'].includes(event.request.method)) {
                            await client.send('Fetch.failRequest', { requestId: event.requestId, errorReason: 'BlockedByClient' });
                            throw new Error('Unexpected bookmark write outside the browser-only state fixtures.');
                        }
                        await client.send('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: 200,
                            responseHeaders: [{ name: 'Content-Type', value: 'application/json' }],
                            body: Buffer.from(JSON.stringify({ scholarship: { is_saved: event.request.method === 'POST' } })).toString('base64') });
                        return;
                    }
                    if (!activePreparationFixture) {
                        await client.send('Fetch.continueRequest', { requestId: event.requestId });
                        return;
                    }
                    const response = await client.send('Fetch.getResponseBody', { requestId: event.requestId });
                    const payload = JSON.parse(response.base64Encoded ? Buffer.from(response.body, 'base64').toString('utf8') : response.body);
                    const result = preparationStateFixture(payload, activePreparationFixture);
                    await client.send('Fetch.fulfillRequest', { requestId: event.requestId, responseCode: 200,
                        responseHeaders: [{ name: 'Content-Type', value: 'application/json' }, { name: 'Cache-Control', value: 'no-store' }],
                        body: Buffer.from(JSON.stringify(result)).toString('base64') });
                })().catch((error) => { fixtureErrors.push(error.message); });
            });
        }

        const runtimeErrors = [];
        const requestErrors = [];
        const browserLogs = [];
        const preparationWrites = [];
        client.on('Network.requestWillBeSent', ({ request }) => {
            if (!verifyPreparationHandoff || ['GET', 'HEAD', 'OPTIONS'].includes(request.method)) return;
            const url = new URL(request.url);
            const hostingSecurityCheck = request.method === 'POST' && url.pathname === '/hcdn-cgi/jschallenge-validate';
            if (url.origin === baseUrl.origin && url.pathname !== '/login' && !url.pathname.endsWith('/application-start') && !hostingSecurityCheck) preparationWrites.push(`${request.method} ${url.pathname}`);
        });
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
        const [viewportWidth, viewportHeight] = windowSize.split(',').map(Number);
        if (viewportWidth < 640) {
            await client.send('Emulation.setDeviceMetricsOverride', { width: viewportWidth, height: viewportHeight, deviceScaleFactor: 1, mobile: true });
        }

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

        for (const [workspacePath, heading, loadingText = null, capture = {}] of workspaces) {
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

            if (verifyPreparationStates) {
                for (const example of preparationStateCases) {
                    activePreparationFixture = example;
                    await client.send('Page.navigate', { url: workspaceUrl.href });
                    await waitFor(() => client.evaluate(`document.querySelector('[data-scholarship-detail-header]')?.innerText.includes(${JSON.stringify(`Browser-only fixture: ${example.key}`)}) && Boolean(document.querySelector('[data-scholarship-application-guide]'))`), `the ${example.key} browser-only fixture`);
                    await client.evaluate("(() => { const readiness = document.querySelector('[data-guide-readiness]'); if (readiness) readiness.open = true; })()");
                    const result = await client.evaluate(`(() => {
                        const panel = document.querySelector('[data-scholarship-application-guide]');
                        return { current: panel.querySelector('[data-guide-next]')?.dataset.preparationStep ?? null,
                            start: Boolean(panel.querySelector('a[href*="/applications/start/"]')),
                            existing: Boolean(panel.querySelector('[data-guide-existing-application]')),
                            label: ${example.label ? `panel.innerText.includes(${JSON.stringify(example.label)})` : 'true'},
                            overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
                        };
                    })()`);
                    if (result.current !== example.step || result.start !== (example.starts === true) || result.existing !== (example.key === 'existing') || !result.label || result.overflow) fail(`State fixture ${example.key} failed: ${JSON.stringify(result)}`);
                    if (['upcoming', 'closed', 'full'].includes(example.key)) {
                        await client.evaluate("document.querySelector('[data-guide-intake-notice] button').click()");
                        await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-guide-intake-notice] a[href*=\"view=saved\"]')) && document.querySelector('[data-scholarship-detail-header]').innerText.includes('View saved scholarships')"), 'mock bookmark feedback in both header and guide');
                    }
                    if (fixtureErrors.length || runtimeErrors.length) fail([...fixtureErrors, ...runtimeErrors].join('; '));
                    console.log(`PASS Browser-only preparation state: ${example.key}`);
                }
                activePreparationFixture = null;
                await client.send('Page.navigate', { url: workspaceUrl.href });
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-scholarship-application-guide]')) && !document.querySelector('[data-scholarship-detail-header]').innerText.includes('Browser-only fixture')"), 'the real applicant page after isolated fixtures');
            }

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

            if (verifyProgramConsistency && /\/provider\/programs\/\d+\/edit\/support$/.test(workspacePath)) {
                await waitFor(() => client.evaluate(`Boolean([...document.querySelectorAll('label')].find((label) => label.textContent.includes('Recipient slots'))?.querySelector('input'))`), 'the capacity form');
                await client.evaluate(`(() => {
                    window.__programConsistencyWrites = 0;
                    window.axios.interceptors.request.use((config) => {
                        if (/^\\/provider\\/scholarships(?:\\/|$)/.test(config.url) && ['post', 'put', 'patch'].includes(config.method)) window.__programConsistencyWrites++;
                        return config;
                    });
                    const setInput = (labelText, value) => {
                        const input = [...document.querySelectorAll('label')].find((label) => label.textContent.includes(labelText))?.querySelector('input');
                        if (!input) throw new Error('Missing capacity input: ' + labelText);
                        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, value);
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    };
                    setInput('Recipient slots', '30');
                    setInput('Application limit', '20');
                })()`);
                await delay(100);
                await client.evaluate(`(() => {
                    const button = [...document.querySelectorAll('button')].find((item) => item.textContent.trim() === 'Save draft');
                    if (!button) throw new Error('Missing Save draft button');
                    button.click();
                })()`);
                await waitFor(() => client.evaluate(`document.querySelector('[role="alertdialog"]')?.textContent.includes('The application limit must be at least the number of recipient slots.')`), 'the capacity conflict alert');
                if (await client.evaluate('window.__programConsistencyWrites !== 0')) fail('An inconsistent program was submitted despite its client validation.');
                console.log('PASS Capacity conflict shows an alert without saving');
                await client.evaluate(`(() => {
                    document.querySelector('[role="alertdialog"] button').click();
                    const setInput = (labelText, value) => {
                        const input = [...document.querySelectorAll('label')].find((label) => label.textContent.includes(labelText)).querySelector('input');
                        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, value);
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    };
                    setInput('Application limit', '30');
                    setInput('Support starts', '2027-10-02');
                    setInput('Support ends', '2027-10-01');
                })()`);
                await delay(100);
                await client.evaluate(`(() => {
                    const button = [...document.querySelectorAll('button')].find((item) => item.textContent.trim() === 'Save and continue');
                    if (!button) throw new Error('Missing Save and continue button');
                    button.click();
                })()`);
                await waitFor(() => client.evaluate(`document.querySelector('[role="alertdialog"]')?.textContent.includes('The support end date must be on or after the support start date.')`), 'the support date conflict alert');
                if (await client.evaluate('window.__programConsistencyWrites !== 0')) fail('An invalid support period was submitted despite its client validation.');
                console.log('PASS Support date conflict blocks continuation without saving');
            }

            if (verifyScholarshipEligibility) {
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-scholarship-eligibility]'))"), 'the scholarship eligibility checklist');
                await waitFor(() => client.evaluate(`(() => {
                    const photo = document.querySelector('[data-scholarship-eligibility] figure img');
                    return !photo || getComputedStyle(photo.parentElement).display === 'none' || (photo.complete && photo.naturalWidth > 0);
                })()`), 'the eligibility section photograph');
                await client.evaluate(`(() => {
                    window.__eligibilityWrites = 0;
                    window.axios.interceptors.request.use((config) => {
                        if (['post', 'put', 'patch', 'delete'].includes(config.method)) window.__eligibilityWrites++;
                        return config;
                    });
                })()`);
                const eligibilityAudit = await client.evaluate(`(() => {
                    const panel = document.querySelector('[data-scholarship-eligibility]');
                    const rows = [...panel.querySelectorAll('[data-eligibility-criterion]')];
                    const results = rows.map((row) => row.querySelector('[data-eligibility-result]').getBoundingClientRect());
                    const explanations = [...panel.querySelectorAll('[data-eligibility-explanation]')];
                    return {
                        rows: rows.length,
                        groups: [...panel.querySelectorAll('[data-eligibility-group]')].map((group) => group.dataset.eligibilityGroup),
                        aligned: results.every((rect) => Math.abs(rect.left - results[0].left) < 2),
                        clipped: results.some((rect) => rect.right > panel.getBoundingClientRect().right + 1),
                        rawValues: /senior_high_school|grade_point|state_university|current_scholarship_support/.test(panel.innerText),
                        documentsAsRule: Boolean(panel.querySelector('[data-eligibility-criterion="documents"]')),
                        explanationsProgressive: explanations.filter((node) => node.tagName === 'DETAILS').every((node) => !node.open),
                        attentionExplanationsVisible: explanations.filter((node) => node.tagName !== 'DETAILS').every((node) => node.getBoundingClientRect().height > 0),
                    };
                })()`);
                if (!eligibilityAudit.aligned || eligibilityAudit.clipped || eligibilityAudit.rawValues || eligibilityAudit.documentsAsRule || !eligibilityAudit.explanationsProgressive || !eligibilityAudit.attentionExplanationsVisible) fail(`The eligibility checklist has inconsistent results or hidden information: ${JSON.stringify(eligibilityAudit)}`);
                const disclosures = ['[data-unrestricted-criteria]', '[data-provider-eligibility-conditions]', '[data-eligibility-explanation]'];
                for (const selector of disclosures) {
                    if (!await client.evaluate(`document.querySelector(${JSON.stringify(selector)})?.tagName === 'DETAILS'`)) continue;
                    await client.send('Page.bringToFront');
                    await client.evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('summary').focus()`);
                    await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13, text: '\r' });
                    await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 });
                    await waitFor(() => client.evaluate(`document.querySelector(${JSON.stringify(selector)}).open`), `${selector} to expand from the keyboard`);
                    await client.evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('summary').click()`);
                }
                if (await client.evaluate('window.__eligibilityWrites !== 0')) fail('Reading eligibility submitted an unexpected mutation.');
                await client.evaluate("document.querySelector('[data-scholarship-eligibility]').scrollIntoView({ block: 'start' }); window.scrollBy(0, -16)");
                console.log(`PASS Scholarship eligibility: aligned results, progressive guidance and read-only disclosure controls ${JSON.stringify(eligibilityAudit)}`);
            }

            if (verifyScholarshipPreparation) {
                if (verifyPreparationPreview) {
                    await client.evaluate("[...document.querySelectorAll('button')].find((button) => button.innerText.trim() === 'Applicant preview').click()");
                }
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-scholarship-application-guide]'))"), 'the application preparation guide');
                const readinessInitiallyOpen = await client.evaluate("Boolean(document.querySelector('[data-guide-readiness]')?.open)");
                await client.evaluate("(() => { const readiness = document.querySelector('[data-guide-readiness]'); if (readiness && !readiness.open) readiness.querySelector('summary').click(); })()");
                await client.evaluate(`(() => {
                    window.__guideWrites = 0;
                    window.axios.interceptors.request.use((config) => {
                        if (['post', 'put', 'patch', 'delete'].includes(config.method)) window.__guideWrites++;
                        return config;
                    });
                })()`);
                const preparationAudit = await client.evaluate(`(() => {
                    const panel = document.querySelector('[data-scholarship-application-guide]');
                    const rows = [...panel.querySelectorAll('[data-preparation-step]')];
                    const rects = rows.map((row) => row.querySelector('[data-guide-status]').getBoundingClientRect());
                    const actions = rows.map((row) => row.querySelector('[data-guide-action]').getBoundingClientRect());
                    const bounds = panel.getBoundingClientRect();
                    return {
                        existing: Boolean(panel.querySelector('[data-guide-existing-application]')),
                        instructions: panel.querySelectorAll('[data-application-instruction]').length,
                        actualControls: ['Start pre-screening', 'Continue to Eligibility', 'Continue to Requirements', 'Continue to Confirm', 'Submit application'].every((label) => panel.innerText.includes(label)),
                        rows: rows.map((row) => row.dataset.preparationStep),
                        current: panel.querySelectorAll('[aria-current="step"]').length,
                        aligned: rects.every((rect) => Math.abs(rect.left - rects[0].left) < 2) && actions.every((rect) => Math.abs(rect.left - actions[0].left) < 2),
                        clipped: [...panel.querySelectorAll('a, [data-guide-status] > span')].some((node) => node.getBoundingClientRect().right > bounds.right + 1),
                        progressive: [...panel.querySelectorAll('details:not([data-guide-readiness])')].every((node) => !node.open),
                        rawValues: /profile_unverified|preferences_differ|evidence_missing|in_application|not_compared/.test(panel.innerText),
                        preview: panel.innerText.includes('Read-only preview'),
                        livePreviewLinks: panel.innerText.includes('Read-only preview') && [...panel.querySelectorAll('a')].some((node) => node.getAttribute('href').startsWith('/dashboard')),
                    };
                })()`);
                if ((!preparationAudit.existing && (preparationAudit.rows.join(',') !== 'profile,eligibility,requirements,submit' || preparationAudit.instructions !== 4 || !preparationAudit.actualControls)) || preparationAudit.current > 1 || !preparationAudit.aligned || preparationAudit.clipped || !preparationAudit.progressive || preparationAudit.rawValues || preparationAudit.livePreviewLinks) fail(`Application preparation guide audit failed: ${JSON.stringify(preparationAudit)}`);
                for (const selector of ['[data-guide-missing]', '[data-guide-submission-note]']) {
                    if (!await client.evaluate(`Boolean(document.querySelector(${JSON.stringify(selector)}))`)) continue;
                    await client.send('Page.bringToFront');
                    await client.evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('summary').focus()`);
                    await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13, text: '\r' });
                    await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 });
                    await waitFor(() => client.evaluate(`document.querySelector(${JSON.stringify(selector)}).open`), `${selector} to expand from the keyboard`);
                    await client.evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('summary').click()`);
                }
                if (verifyPreparationPreview) {
                    const originalPath = await client.evaluate('location.pathname');
                    for (const [key, target] of [['eligibility', '[data-scholarship-eligibility]'], ['requirements', '[data-scholarship-application-files]']]) {
                        await client.evaluate("document.querySelector('[data-guide-readiness]').open = true");
                        await client.evaluate(`document.querySelector('[data-preparation-step="${key}"] a').click()`);
                        await waitFor(() => client.evaluate(`Boolean(document.querySelector('[data-embedded-preview] ${target}'))`), `${key} within the preview modal`);
                        if (await client.evaluate('location.pathname') !== originalPath) fail('The guide navigated away from the provider preview modal.');
                        await client.evaluate("[...document.querySelectorAll('[data-embedded-preview] nav a')].find((node) => /^\\/provider\\/previews\\/programs\\/\\d+\\/?$/.test(new URL(node.href).pathname)).click()");
                        await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-scholarship-application-guide]'))"), 'the overview guide in the preview modal');
                    }
                    for (const scenario of ['eligible', 'ineligible', 'general']) {
                        await client.evaluate(`(() => { const select = document.querySelector('[data-applicant-page-preview-modal] select'); select.value = '${scenario}'; select.dispatchEvent(new Event('change', { bubbles: true })); })()`);
                        const expectedLabel = { general: 'Applicant-specific', eligible: 'No required mismatches', ineligible: 'Required rules not met' }[scenario];
                        await waitFor(() => client.evaluate(`document.querySelector('[data-scholarship-application-guide] [data-preparation-step="eligibility"] [data-guide-status]')?.textContent.includes(${JSON.stringify(expectedLabel)})`), `${scenario} guide preview scenario`);
                        await client.evaluate("document.querySelector('[data-guide-readiness]').open = true");
                        if (await client.evaluate("Boolean(document.querySelector('[data-scholarship-application-guide] a[href^=\"/dashboard\"]'))")) fail('A preview guide exposed a live applicant action.');
                    }
                }
                if (await client.evaluate('window.__guideWrites !== 0')) fail('Reading the guide caused an unexpected mutation.');
                if (!readinessInitiallyOpen) await client.evaluate("(() => { const readiness = document.querySelector('[data-guide-readiness]'); if (readiness) readiness.open = false; })()");
                await client.evaluate("document.activeElement?.blur(); document.querySelector('[data-scholarship-application-guide]').scrollIntoView({ block: 'start' }); window.scrollBy(0, -16)");
                console.log(`PASS Application preparation guide: aligned steps, progressive details and safe actions ${JSON.stringify(preparationAudit)}`);
            }

            if (verifyScholarshipRequirements) {
                if (verifyPreparationPreview) {
                    await client.evaluate("document.querySelector('[data-preparation-step=\"requirements\"] a').click()");
                }
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-scholarship-application-files]'))"), 'the pre-submission requirements card');
                const requirementsAudit = await client.evaluate(`(() => {
                    const panel = document.querySelector('[data-scholarship-application-files]');
                    const statuses = [...panel.querySelectorAll('[data-file-status]')].map((node) => node.getBoundingClientRect());
                    const preview = panel.innerText.includes('Read-only preview');
                    return {
                        requiredFiles: panel.querySelectorAll('[data-required-file]').length,
                        aligned: statuses.every((rect) => Math.abs(rect.left - statuses[0].left) < 2),
                        clipped: [...panel.querySelectorAll('a, [data-file-status] span')].some((node) => node.getBoundingClientRect().right > panel.getBoundingClientRect().right + 1),
                        progressive: [...panel.querySelectorAll('details')].every((node) => !node.open),
                        separateGroups: !panel.innerText.includes('Files that may be requested later'),
                        preview,
                        livePreviewLinks: preview && Boolean(panel.querySelector('a[href^="/dashboard"]')),
                        rawValues: /not_compared|provider_review|senior_high_school/.test(panel.innerText),
                        hasScheduleNote: Boolean(document.querySelector('[data-program-schedule-note]')),
                    };
                })()`);
                if (!requirementsAudit.aligned || requirementsAudit.clipped || !requirementsAudit.progressive || !requirementsAudit.separateGroups || requirementsAudit.livePreviewLinks || requirementsAudit.rawValues || !requirementsAudit.hasScheduleNote) fail(`Requirements audit failed: ${JSON.stringify(requirementsAudit)}`);
                for (const selector of ['[data-provider-questions]', '[data-optional-files]', '[data-later-files]', '[data-file-guidance]']) {
                    if (!await client.evaluate(`Boolean(document.querySelector(${JSON.stringify(selector)}))`)) continue;
                    await client.send('Page.bringToFront');
                    await client.evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('summary').focus()`);
                    await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13, text: '\r' });
                    await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 });
                    await waitFor(() => client.evaluate(`document.querySelector(${JSON.stringify(selector)}).open`), `${selector} to expand`);
                    await client.evaluate(`document.querySelector(${JSON.stringify(selector)}).querySelector('summary').click()`);
                }
                if (verifyPreparationHandoff) {
                    await client.evaluate("document.querySelector('[data-scholarship-application-files] a[href^=\"/dashboard/documents\"]').click()");
                    await waitFor(() => client.evaluate("location.pathname === '/dashboard/documents' && Boolean(document.querySelector('[data-preparation-return] a'))"), 'document library return context');
                    await client.evaluate("document.querySelector('[data-preparation-return] a').click()");
                    await waitFor(() => client.evaluate(`location.pathname === ${JSON.stringify(workspacePath)} && Boolean(document.querySelector('[data-scholarship-application-files]'))`), 'return to the same scholarship requirements');
                    const summaryPath = workspacePath.replace(/\/application$/, '');
                    await client.evaluate(`[...document.querySelectorAll('[data-scholarship-detail-navigation] a')].find((node) => new URL(node.href).pathname === ${JSON.stringify(summaryPath)}).click()`);
                    await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-preparation-step=\"profile\"] a'))"), 'the profile preparation action');
                    await client.evaluate("document.querySelector('[data-guide-readiness]').open = true");
                    await client.evaluate("document.querySelector('[data-preparation-step=\"profile\"] a').click()");
                    await waitFor(() => client.evaluate("location.pathname === '/dashboard/profile' && Boolean(document.querySelector('[data-preparation-return] a')) && Boolean(document.querySelector('[aria-label=\"Close profile editor\"]'))"), 'profile editor with a scholarship return context');
                    await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]').click()");
                    await waitFor(() => client.evaluate("!document.querySelector('[aria-label=\"Close profile editor\"]')"), 'the unchanged profile editor to close');
                    await client.evaluate("document.querySelector('[data-preparation-return] a').click()");
                    await waitFor(() => client.evaluate(`location.pathname === ${JSON.stringify(summaryPath)} && Boolean(document.querySelector('[data-scholarship-application-guide]'))`), 'return to the same scholarship overview');
                    await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-guide-start]'))"), 'the start link in the actual application instructions');
                    await client.evaluate("document.querySelector('[data-guide-start]').click()");
                    await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-application-preparation-handoff] a'))"), 'the preselected scholarship form');
                    await client.evaluate("document.querySelectorAll('nav[aria-label=\"Pre-screening process\"] button')[2].click()");
                    await waitFor(() => client.evaluate("Boolean(document.querySelector('textarea'))"), 'an application question');
                    await client.evaluate("(() => { const input = document.querySelector('textarea'); input.value = 'Read-only browser test answer'; input.dispatchEvent(new Event('input', { bubbles: true })); document.querySelector('[data-application-preparation-handoff] a').click(); })()");
                    await waitFor(() => client.evaluate("document.querySelector('[role=\"alertdialog\"]')?.innerText.includes('Leave this application form?')"), 'the unsent-answer confirmation');
                    if (handoffScreenshotDirectory) {
                        const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
                        await writeFile(path.join(handoffScreenshotDirectory, 'unsent-answers-dialog.png'), Buffer.from(screenshot.data, 'base64'));
                    }
                    await client.evaluate("[...document.querySelectorAll('[role=\"alertdialog\"] button')].find((node) => node.innerText.trim() === 'Keep editing').click()");
                    await waitFor(() => client.evaluate("!document.querySelector('[role=\"alertdialog\"]')"), 'cancelled navigation to keep editing');
                    if (!await client.evaluate("document.querySelector('textarea').value === 'Read-only browser test answer'")) fail('Cancelling navigation lost an unsent answer.');
                    await client.evaluate("(() => { const input = document.querySelector('textarea'); input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); })()");
                    await client.evaluate("document.querySelector('[data-application-preparation-handoff] a').click()");
                    await waitFor(() => client.evaluate(`location.pathname === ${JSON.stringify(workspacePath)} && Boolean(document.querySelector('[data-scholarship-application-files]'))`), 'return from an unchanged form');
                    if (preparationWrites.length) fail(`The read-only handoff changed application data: ${preparationWrites.join(', ')}`);
                    console.log('PASS Scholarship handoff: document/profile return links, selected form and unsent-answer protection without submission');
                }
                await client.evaluate("document.activeElement?.blur(); document.querySelector('[data-scholarship-application-files]').scrollIntoView({ block: 'start' }); window.scrollBy(0, -16)");
                console.log(`PASS Scholarship requirements: separated groups, aligned saved-file statuses and read-only questions ${JSON.stringify(requirementsAudit)}`);
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

            for (const check of modalChecks.filter((check) => !check.path || check.path === workspacePath)) {
                const opener = check.openSelector
                    ? `document.querySelector(${JSON.stringify(check.openSelector)})`
                    : `[...document.querySelectorAll('button')].find((button) => button.innerText.trim() === ${JSON.stringify(check.openText)})`;
                await waitFor(() => client.evaluate(`Boolean((${opener}) && !(${opener}).disabled)`), `${check.label} action`);
                await client.evaluate(`(() => {
                    window.__portalModalBackground = () => {
                        const rect = (element) => {
                            if (!element) return null;
                            const bounds = element.getBoundingClientRect();
                            return { top: bounds.top, left: bounds.left, width: bounds.width, height: bounds.height };
                        };
                        return {
                            scrollY: window.scrollY, bodyWidth: document.body.getBoundingClientRect().width,
                            sidebar: rect(document.querySelector('.student-shell > aside, .provider-shell > aside, .admin-shell > aside')),
                            page: rect(document.querySelector('.student-page, .provider-page, .admin-page')),
                        };
                    };
                    window.scrollTo(0, 180);
                })()`);
                await delay(200);
                const before = await client.evaluate('window.__portalModalBackground()');
                await client.evaluate(`(${opener}).click()`);
                await waitFor(() => client.evaluate(`Boolean(document.querySelector(${JSON.stringify(check.dialogSelector)}))`), check.label);
                await delay(300);
                const during = await client.evaluate('window.__portalModalBackground()');
                if (!stableModalBackground(before, during)) fail(`${check.label} shifts its background: ${JSON.stringify({ before, during })}`);
                if (!await client.evaluate("document.documentElement.style.overflowY === 'hidden' && !document.body.style.overflow && !document.body.classList.contains('overflow-hidden')")) fail(`${check.label} does not use the shared viewport lock.`);
                await client.evaluate(`document.querySelector(${JSON.stringify(check.closeSelector)}).click()`);
                await waitFor(() => client.evaluate(`!document.querySelector(${JSON.stringify(check.dialogSelector)}) && document.documentElement.style.overflowY === ''`), `${check.label} to close and release its lock`);
                await delay(200);
                const after = await client.evaluate('window.__portalModalBackground()');
                if (!stableModalBackground(before, after)) fail(`${check.label} changes its page on close: ${JSON.stringify({ before, after })}`);
                if (runtimeErrors.length) fail(`${check.label} raised a browser exception: ${runtimeErrors.join(', ')}`);
                console.log(`PASS ${check.label}: stable background and scroll position on open and close`);
            }

            if (verifyProfileRecord) {
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-profile-details]'))"), 'the shared reviewer profile');
                const profileAudit = await client.evaluate(`(() => {
                    const record = document.querySelector('[data-profile-details]');
                    return {
                        sections: [...record.querySelectorAll('[data-profile-section]')].map((section) => section.dataset.profileSection),
                        editable: [...record.querySelectorAll('button')].some((button) => button.innerText.trim() === 'Edit'),
                        clipped: [...record.querySelectorAll('dd')].some((field) => field.scrollWidth > field.clientWidth + 2),
                        hasSourceNotice: document.body.innerText.includes('Self-declared profile information'),
                        guardianEmail: Boolean(record.querySelector('[data-profile-field="guardian_email"]')),
                        privateAddress: Boolean(record.querySelector('[data-profile-field="address"]')),
                        rawEnums: /senior_high_school|parent_guardian/.test(record.innerText),
                    };
                })()`);
                if (profileAudit.sections.length !== 4 || profileAudit.editable || profileAudit.clipped || !profileAudit.hasSourceNotice || profileAudit.rawEnums) fail(`Reviewer profile consistency failed: ${JSON.stringify(profileAudit)}`);
                if (expectProfileRedactions && (profileAudit.guardianEmail || profileAudit.privateAddress)) fail(`Role-redacted profile data was rendered: ${JSON.stringify(profileAudit)}`);
                if (!expectProfileRedactions && !profileAudit.guardianEmail) fail(`The authorized reviewer is missing guardian contacts: ${JSON.stringify(profileAudit)}`);
                console.log(`PASS Read-only shared applicant profile: ${JSON.stringify(profileAudit)}`);
            }

            const capturePath = capture.path || screenshotPath;
            if (capturePath) {
                if (capture.closeProfile) {
                    await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]')?.click()");
                }
                for (const selector of capture.expand ?? []) {
                    await client.evaluate(`document.querySelector(${JSON.stringify(selector)})?.querySelector('summary')?.click()`);
                }
                await client.evaluate('document.fonts.ready');
                await waitFor(() => client.evaluate("[...document.images].filter((node) => node.getBoundingClientRect().width > 0).every((node) => node.complete)"), 'page images before capture');
                await client.evaluate('document.activeElement?.blur(); window.scrollTo(0, 0)');
                const selector = capture.selector || screenshotSelector;
                const clip = selector ? await client.evaluate(`(() => {
                    const element = document.querySelector(${JSON.stringify(selector)});
                    if (!element) throw new Error('The screenshot target was not found');
                    const rect = element.getBoundingClientRect();
                    return { x: Math.max(0, rect.left + window.scrollX), y: Math.max(0, rect.top + window.scrollY), width: rect.width, height: rect.height, scale: 1 };
                })()`) : capture.fullPage ? await client.send('Page.getLayoutMetrics').then((metrics) => ({ x: 0, y: 0, width: metrics.cssContentSize.width, height: metrics.cssContentSize.height, scale: 1 })) : null;
                const screenshot = await client.send('Page.captureScreenshot', {
                    format: 'png',
                    captureBeyondViewport: true,
                    ...(clip ? { clip } : {}),
                });
                await writeFile(path.resolve(capturePath), Buffer.from(screenshot.data, 'base64'));
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

            if (verifyEligibilityBuilder) {
                if (!['127.0.0.1', 'localhost'].includes(baseUrl.hostname)) fail('Eligibility builder write tests are limited to the local demo.');
                const draft = await client.evaluate(`(async () => {
                    const response = await window.axios.post('/provider/scholarships', {
                        title: 'QA eligibility controls ' + Date.now(), status: 'draft',
                        eligibility: 'Applicants may apply when they meet the required rules. STEM is preferred.',
                        eligible_education_levels: 'senior_high_school', eligible_courses: 'STEM', eligible_locations: 'Rizal',
                    });
                    return { id: response.data.scholarship.id, title: response.data.scholarship.title };
                })()`);
                if (!Number.isInteger(draft.id) || !draft.title.startsWith('QA eligibility controls ')) fail('The isolated test draft could not be identified.');
                try {
                    const editUrl = new URL('/provider/programs/' + draft.id + '/edit/eligibility', baseUrl).href;
                    await client.send('Page.navigate', { url: editUrl });
                    await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-eligibility-mode=course]'))"), 'the rule mode selectors');
                    const defaults = await client.evaluate("[...document.querySelectorAll('[data-eligibility-mode]')].every((select) => select.value === 'required')");
                    if (!defaults) fail('Newly configured rules did not default to Required.');
                    await client.evaluate(`(() => {
                        const select = document.querySelector('[data-eligibility-mode=course]');
                        select.value = 'preferred';
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    })()`);
                    await waitFor(() => client.evaluate("document.querySelector('[data-eligibility-mode-counts]').textContent.includes('1 preferred')"), 'the updated rule counts');
                    await client.evaluate("[...document.querySelectorAll('button')].find((button) => button.textContent.trim() === 'Save draft').click()");
                    await waitFor(() => client.evaluate("!document.querySelector('fieldset').disabled && document.querySelector('[data-eligibility-mode=course]')?.value === 'preferred'"), 'the saved rule preference');
                    const saved = await client.evaluate(`(async () => (await window.axios.get('/provider/scholarships/${draft.id}')).data.scholarship.eligibility_rule_modes)()`);
                    if (saved.course !== 'preferred' || saved.location !== 'required') fail('Saving the form did not preserve the required/preferred policy.');
                    await client.send('Page.navigate', { url: editUrl });
                    await waitFor(() => client.evaluate("document.querySelector('[data-eligibility-mode=course]')?.value === 'preferred'"), 'the reloaded preference');
                    await client.evaluate(`(() => {
                        const input = document.querySelector('#program-eligible-courses');
                        input.value = 'Any'; input.dispatchEvent(new Event('input', { bubbles: true }));
                    })()`);
                    await waitFor(() => client.evaluate("!document.querySelector('[data-eligibility-mode=course]')"), 'the unrestricted field state');
                    await client.evaluate(`(() => {
                        const input = document.querySelector('#program-eligible-courses');
                        input.value = 'STEM'; input.dispatchEvent(new Event('input', { bubbles: true }));
                    })()`);
                    await waitFor(() => client.evaluate("document.querySelector('[data-eligibility-mode=course]')?.value === 'preferred'"), 'the preserved inactive preference');
                    await client.evaluate(`(() => {
                        const select = document.querySelector('#program-academic-rule');
                        select.value = 'percentage'; select.dispatchEvent(new Event('change', { bubbles: true }));
                    })()`);
                    await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-eligibility-mode=academic]'))"), 'the academic rule selector');
                    await client.evaluate(`(() => {
                        const input = document.querySelector('input[type="number"][max="100"]');
                        input.value = '85'; input.dispatchEvent(new Event('input', { bubbles: true }));
                        const select = document.querySelector('#program-academic-rule');
                        select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true }));
                    })()`);
                    await waitFor(() => client.evaluate("!document.querySelector('[data-eligibility-mode=academic]')"), 'the removed academic minimum');
                    if (screenshotPath) {
                        const screenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                        await writeFile(path.resolve(screenshotPath.replace(/\.png$/i, '-eligibility.png')), Buffer.from(screenshot.data, 'base64'));
                    }
                    await client.evaluate(`(() => {
                        const buttons = [...document.querySelectorAll('[aria-label="Program builder steps"] button')];
                        buttons.find((button) => button.querySelector('.truncate.text-xs.font-bold')?.textContent.trim() === 'Review').click();
                    })()`);
                    await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-eligibility-policy-summary]'))"), 'the eligibility review summary');
                    const summary = await client.evaluate(`({
                        preferred: document.querySelector('[data-rule-group="preferred"]').textContent,
                        required: document.querySelector('[data-rule-group="required"]').textContent,
                    })`);
                    if (!summary.preferred.includes('Courses, tracks, or strands') || !summary.required.includes('Residency')) fail('The review summary did not separate preferences from required rules.');
                    if (screenshotPath) {
                        const screenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                        await writeFile(path.resolve(screenshotPath.replace(/\.png$/i, '-review.png')), Buffer.from(screenshot.data, 'base64'));
                    }
                    if (runtimeErrors.length) fail('Eligibility builder runtime errors: ' + runtimeErrors.join('; '));
                    console.log('PASS Eligibility selectors, save/reload, unrestricted fields, and grouped review');
                } finally {
                    const removed = await client.evaluate(`(async () => {
                        const current = (await window.axios.get('/provider/scholarships/${draft.id}')).data.scholarship;
                        if (current.title !== ${JSON.stringify(draft.title)} || current.status !== 'draft' || current.applications_count > 0) return false;
                        await window.axios.delete('/provider/scholarships/${draft.id}');
                        return true;
                    })()`);
                    if (!removed) fail('The isolated eligibility test draft could not be safely removed.');
                    console.log('PASS Isolated eligibility test draft removed');
                }
            }

            if (verifyMapPin) {
                await client.evaluate(`(() => {
                    const button = [...document.querySelectorAll('button')].find((item) => /^(Set map pin|Review map pin)$/.test(item.textContent.trim()));
                    if (!button) throw new Error('No map pin control found.');
                    button.click();
                })()`);
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[aria-labelledby=\"location-map-modal-title\"] .leaflet-container'))"), 'the interactive map');
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[aria-labelledby=\"location-map-modal-title\"] .leaflet-tile-loaded'))"), 'the map tiles to render');
                const point = await client.evaluate(`(() => {
                    const bounds = document.querySelector('[aria-labelledby="location-map-modal-title"] .leaflet-container').getBoundingClientRect();
                    return { x: bounds.x + bounds.width * 0.6, y: bounds.y + bounds.height * 0.55 };
                })()`);
                await client.send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 });
                await client.send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 });
                await waitFor(() => client.evaluate("document.body.innerText.includes('Map pin selected. Save the form to keep this location.')"), 'the selected map pin');
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[aria-labelledby=\"location-map-modal-title\"] .leaflet-marker-icon')) && !document.querySelector('[aria-labelledby=\"location-map-modal-title\"]').innerText.includes('Finding address from selected pin')"), 'the selected pin marker');
                if (screenshotPath) {
                    const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
                    await writeFile(path.resolve(screenshotPath.replace(/\.png$/i, '-map.png')), Buffer.from(screenshot.data, 'base64'));
                }
                await client.evaluate("document.querySelector('[aria-label=\"Close map\"]').click()");
                await waitFor(() => client.evaluate("!document.querySelector('[aria-labelledby=\"location-map-modal-title\"]')"), 'the map modal to close');
                const cleared = await client.evaluate(`(() => {
                    const button = [...document.querySelectorAll('button')].find((item) => item.textContent.trim() === 'Clear pin');
                    button?.click();
                    return Boolean(button);
                })()`);
                if (!cleared) fail('The selected map pin could not be cleared.');
                await waitFor(() => client.evaluate("[...document.querySelectorAll('button')].some((item) => item.textContent.trim() === 'Set map pin')"), 'the cleared pin state');
                if (runtimeErrors.length) fail(`Map runtime errors: ${runtimeErrors.join('; ')}`);
                console.log('PASS Map pin selection and clearing without saving');
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
