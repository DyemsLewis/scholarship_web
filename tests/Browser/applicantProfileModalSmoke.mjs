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
const overviewScreenshotPath = process.env.APPLICANT_PROFILE_SMOKE_OVERVIEW_SCREENSHOT;
const recordScreenshotPath = process.env.APPLICANT_PROFILE_SMOKE_RECORD_SCREENSHOT;
const layoutOnly = process.env.APPLICANT_PROFILE_SMOKE_LAYOUT_ONLY === '1';
const windowSize = process.env.APPLICANT_PROFILE_SMOKE_WINDOW_SIZE ?? '1440,1000';
const expectedState = process.env.APPLICANT_PROFILE_SMOKE_EXPECTED_STATE;
const verifySavedDetails = process.env.APPLICANT_PROFILE_SMOKE_SAVED_DETAILS === '1';
const detailsScreenshotPath = process.env.APPLICANT_PROFILE_SMOKE_DETAILS_SCREENSHOT;
const addressScreenshotPath = process.env.APPLICANT_PROFILE_SMOKE_ADDRESS_SCREENSHOT;
const verifyEvidence = process.env.APPLICANT_PROFILE_SMOKE_EVIDENCE === '1';
const mockUploads = process.env.APPLICANT_PROFILE_SMOKE_MOCK_UPLOADS === '1';
const modalScreenshotDir = process.env.APPLICANT_PROFILE_SMOKE_MODAL_SCREENSHOT_DIR;
const verifyBackground = process.env.APPLICANT_PROFILE_SMOKE_BACKGROUND === '1';
const verifySections = process.env.APPLICANT_PROFILE_SMOKE_SECTIONS === '1';

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
        `--window-size=${windowSize}`,
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
        const [viewportWidth, viewportHeight] = windowSize.split(',').map(Number);
        await client.send('Emulation.setDeviceMetricsOverride', { width: viewportWidth, height: viewportHeight, deviceScaleFactor: 1, mobile: viewportWidth < 640 });

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
        await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-profile-identity] img')?.naturalWidth)"), 'the applicant identity photo');

        const foundation = await client.evaluate(`(() => {
            const identity = document.querySelector('[data-profile-identity]');
            const readiness = document.querySelector('[data-profile-readiness]');
            const firstSection = identity?.parentElement.querySelector('.divide-y > section');
            const photo = identity?.querySelector('img');
            return {
                identityCount: document.querySelectorAll('[data-profile-identity]').length,
                readinessCount: document.querySelectorAll('[data-profile-readiness]').length,
                state: readiness?.dataset.state,
                viewportWidth: window.innerWidth,
                status: identity?.querySelector('[data-profile-status]')?.innerText.trim(),
                photoLoaded: Boolean(photo?.complete && photo?.naturalWidth),
                readinessBeforeDetails: Boolean(firstSection && readiness.getBoundingClientRect().top < firstSection.getBoundingClientRect().top),
                horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
                headerHasPrivateContact: identity?.innerText.includes('@') || /City|Province|Barangay/.test(identity?.innerText || ''),
                hasEditAction: Boolean([...identity.querySelectorAll('button')].find((button) => button.innerText.trim() === 'Edit profile')),
                continueAction: readiness?.querySelector('button')?.innerText.trim() ?? null,
            };
        })()`);
        if (foundation.identityCount !== 1 || foundation.readinessCount !== 1) fail(`The profile does not have one identity area and one readiness strip: ${JSON.stringify(foundation)}`);
        if (!foundation.photoLoaded || !foundation.readinessBeforeDetails || !foundation.hasEditAction) fail(`The profile foundation is incomplete: ${JSON.stringify(foundation)}`);
        if (foundation.horizontalOverflow || foundation.headerHasPrivateContact) fail(`The profile identity layout has overflow or misplaced contact details: ${JSON.stringify(foundation)}`);
        if (expectedState && foundation.state !== expectedState) fail(`Expected ${expectedState}, received ${foundation.state}`);
        if (overviewScreenshotPath) {
            if (Number(windowSize.split(',')[0]) < 640) {
                await client.evaluate("window.scrollTo(0, document.querySelector('.student-page').getBoundingClientRect().top + window.scrollY - 12)");
            }
            const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
            await writeFile(path.resolve(overviewScreenshotPath), Buffer.from(screenshot.data, 'base64'));
        }
        console.log(`PASS Applicant profile foundation: ${JSON.stringify(foundation)}`);
        if (recordScreenshotPath) {
            await client.evaluate('window.scrollTo(0, 0)');
            await delay(250);
            const bounds = await client.evaluate(`(() => {
                const section = document.querySelector('[data-profile-identity]').closest('section');
                const rect = section.getBoundingClientRect();
                return { x: Math.max(0, rect.left - 12), y: rect.top + window.scrollY - 12, width: rect.width + 24, height: rect.height + 24, scale: 1 };
            })()`);
            const screenshot = await client.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: bounds });
            await writeFile(path.resolve(recordScreenshotPath), Buffer.from(screenshot.data, 'base64'));
        }

        const details = await client.evaluate(`(() => {
            const sections = [...document.querySelectorAll('[data-profile-details] > [data-profile-section]')];
            const grids = [...document.querySelectorAll('[data-profile-field-grid]')];
            const firstRow = sections.map((section) => section.querySelector('[data-profile-field]')?.getBoundingClientRect().left);
            return {
                ids: sections.map((section) => section.dataset.profileSection),
                fullWidth: sections.every((section) => Math.abs(section.getBoundingClientRect().width - sections[0].getBoundingClientRect().width) < 2),
                aligned: firstRow.every((left) => Math.abs(left - firstRow[0]) < 2),
                columns: grids.map((grid) => getComputedStyle(grid).gridTemplateColumns.split(/\\s+/).length),
                guardianContact: Boolean(document.querySelector('[data-profile-field="guardian_contact"]')),
                addressHeading: document.querySelector('#profile-record-location-title')?.innerText,
                clippedFields: [...document.querySelectorAll('[data-profile-field] dd')].some((field) => field.scrollWidth > field.clientWidth + 2),
            };
        })()`);
        if (JSON.stringify(details.ids) !== JSON.stringify(['personal', 'academic', 'household', 'location'])) fail(`Profile detail sections are missing or out of order: ${JSON.stringify(details)}`);
        if (!details.fullWidth || !details.aligned || details.clippedFields) fail(`Profile field alignment or wrapping is incorrect: ${JSON.stringify(details)}`);
        const expectedColumns = viewportWidth >= 1024 ? 3 : viewportWidth >= 640 ? 2 : 1;
        if (details.columns.some((columns) => columns !== expectedColumns)) fail(`Profile fields have inconsistent column counts: ${JSON.stringify(details)}`);
        if (details.addressHeading !== 'Home address') fail('The address section is not labelled consistently.');
        if (detailsScreenshotPath) {
            await client.evaluate("document.querySelector('[data-profile-section=\"personal\"]').scrollIntoView({ block: 'start' })");
            const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
            await writeFile(path.resolve(detailsScreenshotPath), Buffer.from(screenshot.data, 'base64'));
        }
        if (addressScreenshotPath) {
            await client.evaluate("document.querySelector('[data-profile-section=\"location\"]').scrollIntoView({ block: 'start' })");
            const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
            await writeFile(path.resolve(addressScreenshotPath), Buffer.from(screenshot.data, 'base64'));
        }
        if (verifySavedDetails) {
            await client.evaluate(`(() => {
                window.__profileMutationCount = 0;
                window.axios.interceptors.request.use((config) => {
                    if (/^\\/dashboard\\/profile(?:\\/|$)/.test(config.url) && ['post', 'patch', 'put', 'delete'].includes(config.method)) window.__profileMutationCount++;
                    return config;
                });
                window.__savedProfileContact = document.querySelector('[data-profile-field="contact_number"] dd').innerText.trim();
                document.querySelector('[data-profile-saved-map] button')?.click();
            })()`);
            await waitFor(() => client.evaluate("Boolean(document.querySelector('[role=\"dialog\"][aria-labelledby=\"location-map-modal-title\"]'))"), 'the saved-address map');
            const mapTitle = await client.evaluate("document.querySelector('#location-map-modal-title').innerText");
            if (mapTitle !== 'Approximate home location') fail(`The saved location opened the wrong map: ${mapTitle}`);
            const mapFocused = await client.evaluate("document.querySelector('[aria-labelledby=\"location-map-modal-title\"]').contains(document.activeElement) && document.documentElement.style.overflowY === 'hidden'");
            if (!mapFocused) fail('The address map did not receive focus and lock background scrolling.');
            await client.evaluate("document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");
            await waitFor(() => client.evaluate("!document.querySelector('[role=\"dialog\"][aria-labelledby=\"location-map-modal-title\"]')"), 'the saved-address map to close');
            if (await client.evaluate('window.__profileMutationCount !== 0')) fail('Viewing the saved address attempted to update profile data.');
            if (await client.evaluate("document.documentElement.style.overflowY !== ''")) fail('Closing the saved map left page scrolling locked.');
            console.log('PASS Saved home address opens a read-only map without modifying the profile');
        }
        console.log(`PASS Applicant profile details: ${JSON.stringify(details)}`);
        if (verifySections) {
            await client.evaluate("(async () => { window.__savedSectionProfile = (await window.axios.get('/dashboard/profile/data')).data.user; })()");
        }

        if (verifyBackground) {
            await client.evaluate(`(() => {
                window.__profileBackground = () => {
                    const rect = (element) => {
                        const bounds = element.getBoundingClientRect();
                        return { top: bounds.top, left: bounds.left, width: bounds.width, height: bounds.height };
                    };
                    return {
                        scrollY: window.scrollY, bodyWidth: document.body.getBoundingClientRect().width,
                        sidebar: rect(document.querySelector('.student-shell > aside')),
                        page: rect(document.querySelector('.student-page')),
                        identity: rect(document.querySelector('[data-profile-identity]')),
                    };
                };
                window.scrollTo(0, 180);
            })()`);
            await delay(100);
        }

        const buttonLabels = await client.evaluate("[...document.querySelectorAll('button')].filter((button) => button.offsetParent !== null).map((button) => button.innerText.trim()).filter(Boolean)");
        const expectedSections = layoutOnly ? [] : [
            ['personal', 'Personal and contact'],
            ['academic', 'Learning record'],
            ['household', 'Household and guardian'],
            ['location', 'Home address'],
        ];
        const audits = [];

        for (const [index, [section, expectedTitle]] of expectedSections.entries()) {
            const backgroundBefore = verifyBackground ? await client.evaluate('window.__profileBackground()') : null;
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
                    viewportOverflow: document.documentElement.style.overflowY,
                    dialogs: [...document.querySelectorAll('[role="dialog"]')].map((dialog) => ({ title: dialog.innerText.slice(0, 200), html: dialog.outerHTML.slice(0, 500) })),
                    bodyText: document.body.innerText.slice(0, 1200),
                })`);
                fail(`${error.message}: ${JSON.stringify({ section, snapshot, runtimeErrors, browserLogs, requestErrors })}`);
            }

            await delay(250);
            if (verifyBackground) {
                const during = await client.evaluate('window.__profileBackground()');
                const stable = (before, after) => Object.keys(before).every((key) => typeof before[key] === 'object'
                    ? stable(before[key], after[key]) : Math.abs(before[key] - after[key]) <= 1);
                if (!stable(backgroundBefore, during)) fail(`Opening ${section} shifts the background: ${JSON.stringify({ before: backgroundBefore, during })}`);
            }
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
                    viewportOverflow: document.documentElement.style.overflowY,
                    dialogRect: dialogRect ? { top: dialogRect.top, left: dialogRect.left, width: dialogRect.width, height: dialogRect.height } : null,
                    dialogDisplay: dialogStyle?.display,
                    dialogVisibility: dialogStyle?.visibility,
                    overlayPosition: overlayStyle?.position,
                    overlayZIndex: overlayStyle?.zIndex,
                    overflow: dialog.scrollWidth > dialog.clientWidth + 2,
                    footerVisible: dialog.querySelector('footer').getBoundingClientRect().bottom <= window.innerHeight,
                };
            })()`);

            if (!audit.url.includes(`section=${section}`)) fail(`The ${section} modal did not preserve its section in the URL: ${audit.url}`);
            if (audit.title !== expectedTitle) fail(`The ${section} modal rendered the wrong title: ${audit.title}`);
            if (audit.viewportOverflow !== 'hidden' || audit.bodyOverflow) fail(`The ${section} modal did not lock the viewport without disturbing the body.`);
            if (!audit.dialogRect || audit.dialogRect.width <= 0 || audit.dialogRect.height <= 0) fail(`The ${section} modal has no visible bounds: ${JSON.stringify(audit)}`);
            if (audit.overflow || !audit.footerVisible) fail(`The ${section} modal clips fields or its actions: ${JSON.stringify(audit)}`);
            if (runtimeErrors.length) fail(`The ${section} profile editor raised a browser exception: ${runtimeErrors[0]}`);
            if (requestErrors.length) fail(`The ${section} profile editor had failed requests: ${requestErrors.join(', ')}`);
            audits.push(audit);
            if (section === 'personal') {
                const personal = await client.evaluate(`(() => {
                    const editor = document.querySelector('[data-personal-editor]');
                    const header = document.querySelector('[data-profile-editor-header]');
                    const nameFields = ['first-name', 'last-name', 'middle', 'suffix'].map((id) => editor.querySelector('#profile-' + id));
                    return {
                        headings: [...editor.querySelectorAll('h3')].map((heading) => heading.innerText),
                        photoSummaryHeight: editor.querySelector('[data-personal-photo-summary]').getBoundingClientRect().height,
                        hasToggle: Boolean(editor.querySelector('[data-photo-controls-toggle]')),
                        controlsShown: Boolean(editor.querySelector('[data-photo-controls]')),
                        headerHeight: header.getBoundingClientRect().height,
                        headerColor: getComputedStyle(header).backgroundColor,
                        nameInputTops: nameFields.map((field) => field.getBoundingClientRect().top),
                        fieldsClipped: [...editor.querySelectorAll('input, select')].some((field) => field.getBoundingClientRect().right > editor.getBoundingClientRect().right + 1),
                    };
                })()`);
                if (JSON.stringify(personal.headings) !== JSON.stringify(['Applicant photo', 'Official name', 'Personal details', 'Contact and account'])) fail(`The Personal editor has an incorrect hierarchy: ${JSON.stringify(personal)}`);
                if (personal.headerColor !== 'rgb(8, 20, 38)' || personal.headerHeight > 125 || personal.photoSummaryHeight > 90 || personal.fieldsClipped) fail(`The Personal editor is not compact or clips inputs: ${JSON.stringify(personal)}`);
                if (viewportWidth >= 768 && personal.nameInputTops.some((top) => Math.abs(top - personal.nameInputTops[0]) > 1)) fail(`Personal name inputs are misaligned: ${JSON.stringify(personal)}`);
                if (personal.hasToggle) {
                    if (personal.controlsShown) fail('A saved photo unnecessarily expands all upload controls.');
                    await client.evaluate("document.querySelector('[data-photo-controls-toggle]').click()");
                    await waitFor(() => client.evaluate("document.querySelector('[data-photo-controls-toggle]')?.getAttribute('aria-expanded') === 'true' && document.querySelector('[data-photo-controls]')?.innerText.includes('Photo changes save immediately.')"), 'the photo controls to expand');
                    const expanded = await client.evaluate(`(() => {
                        const controls = document.querySelector('[data-photo-controls]');
                        return Boolean(controls.querySelector('input[type="file"]')) && controls.innerText.includes('Open camera') && controls.innerText.includes('Remove photo');
                    })()`);
                    if (!expanded) fail('Changing a saved photo must still offer upload, camera and removal.');
                    await client.evaluate("document.querySelector('[data-photo-controls-toggle]').click()");
                    await waitFor(() => client.evaluate("!document.querySelector('[data-photo-controls]')"), 'the photo controls to collapse');
                    if (!await client.evaluate("[...document.querySelector('[aria-labelledby=\"profile-editor-title\"] footer').querySelectorAll('button')].find((button) => button.innerText.trim() === 'Save changes').disabled")) fail('Opening photo controls incorrectly dirtied the Personal form.');
                } else if (!personal.controlsShown) {
                    fail('Missing or replacement-requested photos must expose their upload actions immediately.');
                }
                console.log(`PASS Compact Personal editor and progressive photo controls: ${JSON.stringify(personal)}`);
            }
            if (verifySections && section !== 'personal') {
                const sectionAudit = await client.evaluate(`(() => {
                    const root = document.querySelector(${JSON.stringify({ academic: '[data-learning-editor]', household: '[data-household-editor]', location: '[data-address-editor]' }[section])});
                    const fields = [...root.querySelectorAll('input, select, textarea')];
                    const clipped = fields.filter((field) => field.offsetParent !== null).some((field) => field.getBoundingClientRect().right > root.getBoundingClientRect().right + 1);
                    const hiddenRequired = fields.filter((field) => field.required && field.closest('details:not([open])')).map((field) => field.id);
                    const saved = window.__savedSectionProfile;
                    const keys = ${JSON.stringify({ academic: { 'profile-education-level': 'education_level', 'profile-school': 'school', 'profile-course': 'course_or_strand', 'profile-year': 'year_level', 'profile-academic-year': 'academic_year', 'profile-academic-term': 'academic_term' }, household: { 'profile-income': 'income_bracket', 'profile-household-size': 'household_size', 'profile-current-scholarship': 'current_scholarship_status', 'profile-guardian-relationship': 'guardian_relationship' }, location: { 'profile-city': 'city', 'profile-province': 'province' } }[section])};
                    return {
                        clipped, hiddenRequired,
                        savedValuesMatch: Object.entries(keys).every(([id, key]) => !root.querySelector('#' + id) || root.querySelector('#' + id).value === String(saved[key] ?? '')),
                        mismatchedValues: Object.entries(keys).filter(([id, key]) => root.querySelector('#' + id) && root.querySelector('#' + id).value !== String(saved[key] ?? '')).map(([id, key]) => ({ field: id, saved: saved[key], shown: root.querySelector('#' + id).value })),
                        width: root.getBoundingClientRect().width,
                        identifiersCollapsed: root.querySelector('[data-school-identifiers]')?.open === false,
                        guardianTag: root.querySelector('[data-guardian-section]')?.tagName,
                    };
                })()`);
                if (sectionAudit.clipped || sectionAudit.hiddenRequired.length || !sectionAudit.savedValuesMatch || Math.abs(sectionAudit.width - audit.dialogRect.width) > 3) fail(`The ${section} editor hides required fields, loses saved values or clips its form: ${JSON.stringify(sectionAudit)}`);
                if (section === 'academic') {
                    if (!sectionAudit.identifiersCollapsed) fail('Optional school identifiers should start collapsed.');
                    await client.send('Page.bringToFront');
                    await client.evaluate("document.querySelector('[data-school-identifiers] summary').focus()");
                    if (!await client.evaluate("document.activeElement.matches('[data-school-identifiers] summary')")) fail('The optional school details summary cannot receive keyboard focus.');
                    await client.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13, text: '\r' });
                    await client.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 });
                    try {
                        await waitFor(() => client.evaluate("document.querySelector('[data-school-identifiers]').open && document.querySelector('#profile-lrn').getClientRects().length > 0"), 'school identifiers to open from the keyboard');
                    } catch (error) {
                        const state = await client.evaluate("({ focused: document.activeElement.outerHTML.slice(0, 300), open: document.querySelector('[data-school-identifiers]').open, field: document.querySelector('#profile-lrn').getBoundingClientRect().toJSON() })");
                        fail(`${error.message}: ${JSON.stringify(state)}`);
                    }
                    await client.evaluate("document.querySelector('[data-school-identifiers] summary').click(); document.querySelector('[data-profile-editor-body]').scrollTop = 0");
                }
                if (section === 'household') {
                    await client.evaluate(`(() => {
                        const select = document.querySelector('#profile-current-scholarship');
                        window.__outsideScholarshipStatus = select.value;
                        window.__outsideScholarshipDetails = document.querySelector('#profile-current-scholarship-details')?.value ?? '';
                        select.value = 'receiving'; select.dispatchEvent(new Event('change', { bubbles: true }));
                    })()`);
                    await waitFor(() => client.evaluate("document.querySelector('#profile-current-scholarship-details')?.required"), 'outside-scholarship details to become required');
                    await client.evaluate(`(() => {
                        const select = document.querySelector('#profile-current-scholarship');
                        select.value = window.__outsideScholarshipStatus; select.dispatchEvent(new Event('change', { bubbles: true }));
                    })()`);
                    await delay(50);
                    await client.evaluate(`(() => {
                        const input = document.querySelector('#profile-current-scholarship-details');
                        if (input) { input.value = window.__outsideScholarshipDetails; input.dispatchEvent(new Event('input', { bubbles: true })); }
                    })()`);
                }
                if (section === 'location') {
                    await client.evaluate("document.querySelector('[data-profile-map-action]').click()");
                    await waitFor(() => client.evaluate("document.querySelector('[aria-labelledby=\"location-map-modal-title\"]')?.contains(document.activeElement)"), 'the editable map to receive focus');
                    await client.evaluate("document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");
                    await waitFor(() => client.evaluate("!document.querySelector('[aria-labelledby=\"location-map-modal-title\"]')"), 'the editable map to close');
                    if (!await client.evaluate("Boolean(document.querySelector('[data-address-editor]')) && document.documentElement.style.overflowY === 'hidden'")) fail('Closing the map also closed the Address editor or unlocked the page.');
                }
                if (!await client.evaluate("[...document.querySelector('[aria-labelledby=\"profile-editor-title\"] footer').querySelectorAll('button')].find((button) => button.innerText.trim() === 'Save changes').disabled")) fail(`Browsing the ${section} editor unexpectedly changed saved profile values.`);
                console.log(`PASS Focused ${section} editor, saved values and conditional fields: ${JSON.stringify(sectionAudit)}`);
            }
            if (modalScreenshotDir) {
                const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
                await writeFile(path.join(path.resolve(modalScreenshotDir), `profile-part3-${section}-${viewportWidth}.png`), Buffer.from(screenshot.data, 'base64'));
            }

            if (verifySavedDetails && index === 0) {
                await client.evaluate(`(() => {
                    const input = document.querySelector('#profile-contact');
                    if (!input) throw new Error('The personal contact input was not found');
                    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, '09179998888');
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                })()`);
                await delay(150);
                if (await client.evaluate("document.querySelector('[data-profile-field=\"contact_number\"] dd').innerText.trim() !== window.__savedProfileContact")) fail('An unsaved edit leaked into the saved applicant record.');
            }

            if (index < expectedSections.length - 1) {
                await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]')?.click()");
                if (verifySavedDetails && index === 0) {
                    await waitFor(() => client.evaluate("[...document.querySelectorAll('[role=\"alertdialog\"] button')].some((button) => button.innerText.trim() === 'Discard changes')"), 'the unsaved profile confirmation');
                    await client.evaluate("[...document.querySelectorAll('[role=\"alertdialog\"] button')].find((button) => button.innerText.trim() === 'Discard changes').click()");
                }
                await waitFor(
                    () => client.evaluate("!document.querySelector('[role=\"dialog\"][aria-labelledby=\"profile-editor-title\"]')"),
                    `${section} profile edit modal to close`,
                );
                const bodyOverflowAfterClose = await client.evaluate('document.body.style.overflow');
                if (bodyOverflowAfterClose) fail(`Closing the ${section} modal left body overflow set to ${bodyOverflowAfterClose}.`);
                if (await client.evaluate("document.documentElement.style.overflowY !== ''")) fail(`Closing the ${section} modal left the viewport locked.`);
                if (verifyBackground) {
                    await delay(200);
                    const after = await client.evaluate('window.__profileBackground()');
                    const stable = (before, result) => Object.keys(before).every((key) => typeof before[key] === 'object'
                        ? stable(before[key], result[key]) : Math.abs(before[key] - result[key]) <= 1);
                    if (!stable(backgroundBefore, after)) fail(`Closing ${section} shifts the background: ${JSON.stringify({ before: backgroundBefore, after })}`);
                }
            }
        }

        if (verifySavedDetails && await client.evaluate('window.__profileMutationCount !== 0')) fail('The read-only details test saved profile changes.');

        if (verifyEvidence) {
            await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]')?.click()");
            await waitFor(() => client.evaluate("!document.querySelector('#profile-editor-title')"), 'the location editor to close');
            const evidenceBackground = verifyBackground ? await client.evaluate('window.__profileBackground()') : null;
            await client.evaluate("[...document.querySelectorAll('button')].find((button) => button.innerText.trim().startsWith('Supporting evidence')).click()");
            await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-profile-evidence-list]'))"), 'the evidence editor');
            await delay(250);
            const evidenceAudit = await client.evaluate(`(() => {
                const dialog = document.querySelector('[aria-labelledby="profile-editor-title"]');
                const rows = [...dialog.querySelectorAll('[data-evidence-type]')].filter((row) => !row.closest('details:not([open])'));
                const statusLefts = rows.map((row) => row.querySelector('[data-evidence-status]').getBoundingClientRect().left);
                return {
                    rows: rows.length,
                    requiredVisible: dialog.querySelector('[data-evidence-group="required"]')?.tagName === 'SECTION',
                    collapsed: rows.every((row) => !row.querySelector('details').open),
                    statusesAligned: statusLefts.every((left) => Math.abs(left - statusLefts[0]) < 2),
                    overflow: dialog.scrollWidth > dialog.clientWidth + 2,
                    message: dialog.querySelector('[data-profile-editor-save-state]').innerText,
                    actions: [...dialog.querySelector('footer').querySelectorAll('button')].map((button) => button.innerText.trim()),
                };
            })()`);
            if (!evidenceAudit.rows || !evidenceAudit.requiredVisible || !evidenceAudit.collapsed || !evidenceAudit.statusesAligned || evidenceAudit.overflow) fail(`Evidence rows are inconsistent: ${JSON.stringify(evidenceAudit)}`);
            if (!evidenceAudit.message.includes('saved immediately') || JSON.stringify(evidenceAudit.actions) !== '["Close"]') fail(`Evidence save behavior is unclear: ${JSON.stringify(evidenceAudit)}`);

            await client.evaluate("document.querySelector('[data-evidence-help] summary').click()");
            if (!await client.evaluate("document.querySelector('[data-evidence-help]').open")) fail('Evidence guidance did not expand.');
            await client.evaluate("[...document.querySelectorAll('#profile-verification button')].find((button) => button.innerText.trim() === 'Read terms').click()");
            await waitFor(() => client.evaluate("Boolean(document.querySelector('[role=\"dialog\"][aria-label]'))"), 'upload terms');
            const termsAudit = await client.evaluate(`(() => {
                const terms = document.querySelector('[role="dialog"][aria-label]');
                return { layer: Number(getComputedStyle(terms).zIndex), focused: terms.contains(document.activeElement) };
            })()`);
            if (termsAudit.layer <= 90 || !termsAudit.focused) fail(`Upload terms are behind the editor or unfocused: ${JSON.stringify(termsAudit)}`);
            await client.evaluate("document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");
            await waitFor(() => client.evaluate("!document.querySelector('[role=\"dialog\"][aria-label]')"), 'upload terms to close');
            if (!await client.evaluate("Boolean(document.querySelector('[data-profile-evidence-list]')) && document.documentElement.style.overflowY === 'hidden'")) fail('Closing terms also closed the editor or unlocked the page.');

            const previewButton = await client.evaluate("Boolean(document.querySelector('[data-evidence-type] button[aria-label^=\"View\"]'))");
            if (previewButton) {
                await client.evaluate("document.querySelector('[data-evidence-type] button[aria-label^=\"View\"]').click()");
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[aria-label=\"Close file preview\"]'))"), 'the evidence preview');
                await client.evaluate("document.querySelector('[aria-label=\"Close file preview\"]').click()");
                await waitFor(() => client.evaluate("!document.querySelector('[aria-label=\"Close file preview\"]')"), 'the evidence preview to close');
                if (!await client.evaluate("Boolean(document.querySelector('[data-profile-evidence-list]'))")) fail('Closing a file preview also closed the editor.');
                await client.evaluate("document.querySelector('[data-evidence-type] button[aria-label^=\"Remove\"]').click()");
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[role=\"alertdialog\"]'))"), 'the remove-file confirmation');
                await client.evaluate("[...document.querySelectorAll('[role=\"alertdialog\"] button')].find((button) => button.innerText.trim() === 'Cancel').click()");
                await waitFor(() => client.evaluate("!document.querySelector('[role=\"alertdialog\"]')"), 'the file removal to be cancelled');
                if (!await client.evaluate("Boolean(document.querySelector('[data-evidence-type] button[aria-label^=\"View\"]')) && document.documentElement.style.overflowY === 'hidden'")) fail('Cancelling file removal changed the record or unlocked the page.');
            }

            if (verifyBackground) {
                const during = await client.evaluate('window.__profileBackground()');
                const stable = (before, after) => Object.keys(before).every((key) => typeof before[key] === 'object'
                    ? stable(before[key], after[key]) : Math.abs(before[key] - after[key]) <= 1);
                if (!stable(evidenceBackground, during)) fail(`Evidence and nested dialogs shift the background: ${JSON.stringify({ before: evidenceBackground, during })}`);
                console.log('PASS Background sidebar, record dimensions and scroll position remain unchanged across all profile and nested dialogs');
            }

            if (screenshotPath || modalScreenshotDir) {
                await client.evaluate("document.querySelectorAll('[data-evidence-help]').forEach((help) => { help.open = false; }); document.querySelector('[data-profile-editor-body]').scrollTop = 0");
                await delay(150);
                const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
                if (screenshotPath) await writeFile(path.resolve(screenshotPath), Buffer.from(screenshot.data, 'base64'));
                if (modalScreenshotDir) await writeFile(path.join(path.resolve(modalScreenshotDir), `profile-evidence-${viewportWidth}.png`), Buffer.from(screenshot.data, 'base64'));
            }

            if (mockUploads) {
                // Intercept mutations inside this browser only; never upload test files to demo storage.
                await client.evaluate(`(async () => {
                    const response = await window.axios.get('/dashboard/profile/data');
                    window.__profileUploadPayload = response.data;
                    window.__mockUploadCalls = 0;
                    window.__mockUploadSuccess = false;
                    window.axios.interceptors.request.use((config) => {
                        if (config.method === 'post' && config.url === '/dashboard/profile/verification-documents') {
                            config.adapter = () => new Promise((resolve, reject) => {
                                window.__mockUploadCalls++;
                                window.__finishMockUpload = () => {
                                    if (!window.__mockUploadSuccess) {
                                        reject({ config, response: { status: 422, data: { message: 'Test upload error: choose a clearer file.' } }, message: 'Mock validation error' });
                                        return;
                                    }
                                    const data = structuredClone(window.__profileUploadPayload);
                                    const type = config.data.get('document_type');
                                    const document = data.verification_documents.find((file) => file.document_type === type);
                                    const file = { ...document, id: document?.id ?? 987654, document_type: type, original_name: 'smoke-profile.pdf', size: 1024, status: 'submitted' };
                                    if (type === 'academic_record' && window.__mockAcademicFailure) {
                                        file.ocr_status = 'failed'; file.ocr_grade = null;
                                        data.academic_ocr = { ...data.academic_ocr, configured: true };
                                    }
                                    data.verification_documents = [file, ...data.verification_documents.filter((item) => item.document_type !== type)];
                                    data.user.applicant_verification_status = 'pending';
                                    resolve({ data, status: 200, statusText: 'OK', headers: {}, config });
                                };
                            });
                        }
                        return config;
                    });
                    window.__chooseProfileTestFile = (type = 'identity_record') => {
                        const input = document.querySelector('[data-evidence-type="' + type + '"] input[type="file"]');
                        const transfer = new DataTransfer();
                        transfer.items.add(new File(['%PDF-1.4 smoke'], 'smoke-profile.pdf', { type: 'application/pdf' }));
                        input.files = transfer.files;
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    };
                    window.__chooseProfileTestFile();
                })()`);
                await delay(100);
                if (!await client.evaluate("document.querySelector('[data-profile-editor-body]').innerText.includes('Agree to the upload terms first.') && window.__mockUploadCalls === 0 && document.activeElement.matches('[data-profile-evidence-terms] input')")) fail('The upload terms gate did not prevent an unconsented upload and focus its consent checkbox.');
                await client.evaluate(`(() => {
                    const checkbox = document.querySelector('#profile-verification input[type="checkbox"]');
                    checkbox.checked = true;
                    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                })()`);
                await client.evaluate('window.__chooseProfileTestFile()');
                await waitFor(() => client.evaluate('window.__mockUploadCalls === 1'), 'the mocked upload');
                const protectedUpload = await client.evaluate(`(() => {
                    const close = document.querySelector('[aria-label="Close profile editor"]');
                    close.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
                    return close.disabled && Boolean(document.querySelector('[data-profile-evidence-list]'));
                })()`);
                if (!protectedUpload) fail('An in-progress upload can be closed accidentally.');
                await client.evaluate('window.__finishMockUpload()');
                await waitFor(() => client.evaluate("document.querySelector('[data-profile-editor-body]').innerText.includes('Test upload error: choose a clearer file.')"), 'the upload error to appear');
                await client.evaluate('window.__mockUploadSuccess = true; window.__chooseProfileTestFile()');
                await waitFor(() => client.evaluate('window.__mockUploadCalls === 2'), 'the retry upload');
                await client.evaluate('window.__finishMockUpload()');
                await waitFor(() => client.evaluate("document.querySelector('[data-evidence-type=\"identity_record\"]').innerText.includes('smoke-profile.pdf') && !document.querySelector('[aria-label=\"Close profile editor\"]').disabled"), 'the successful upload response');
                console.log('PASS Browser-mocked uploads: consent gate, busy-close protection, visible validation errors, retry and saved-file response; no database writes');

                await client.evaluate("window.__mockAcademicFailure = true; window.__chooseProfileTestFile('academic_record')");
                await waitFor(() => client.evaluate('window.__mockUploadCalls === 3'), 'the mocked academic record upload');
                await client.evaluate('window.__finishMockUpload()');
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-manual-academic-result]'))"), 'manual academic entry after a failed scan');
                if (!await client.evaluate("document.querySelector('[data-manual-academic-result]').closest('[data-evidence-type]').dataset.evidenceType === 'academic_record'")) fail('Manual academic entry is separated from the academic record.');
                await client.evaluate(`(() => {
                    const scale = document.querySelector('#fallback-grading-scale');
                    window.__manualOriginal = { scale: scale.value, grade: document.querySelector('#fallback-academic-result')?.value ?? '' };
                    scale.value = 'grade_point'; scale.dispatchEvent(new Event('change', { bubbles: true }));
                })()`);
                await delay(75);
                await client.evaluate(`(() => {
                    const result = document.querySelector('#fallback-academic-result');
                    if (result.max !== '5') throw new Error('Grade-point entry must retain its maximum of 5');
                    result.value = '1.75'; result.dispatchEvent(new Event('input', { bubbles: true }));
                })()`);
                await waitFor(() => client.evaluate("document.querySelector('[data-profile-editor-save-state]').innerText.includes('Academic result not saved. Files are saved immediately.')"), 'the mixed immediate-upload and manual-save explanation');
                await client.evaluate(`(() => {
                    const scale = document.querySelector('#fallback-grading-scale');
                    scale.value = window.__manualOriginal.scale; scale.dispatchEvent(new Event('change', { bubbles: true }));
                })()`);
                await delay(75);
                await client.evaluate(`(() => {
                    const result = document.querySelector('#fallback-academic-result');
                    if (result) { result.value = window.__manualOriginal.grade; result.dispatchEvent(new Event('input', { bubbles: true })); }
                })()`);
                await waitFor(() => client.evaluate("document.querySelector('[data-profile-editor-save-state]').innerText === 'Uploads and removals are saved immediately.'"), 'the temporary academic edit to be restored');
                console.log('PASS Browser-mocked scan failure: manual entry stays with the academic record, preserves numeric limits and explains its explicit Save action; no database writes');
            }

            if (screenshotPath) {
                await client.evaluate("document.querySelector('[data-evidence-help]').open = false; document.querySelector('[data-profile-editor-body]').scrollTop = 0");
            }
            console.log(`PASS Supporting evidence, upload terms and file preview: ${JSON.stringify(evidenceAudit)}`);
            if (runtimeErrors.length) fail(`The evidence editor raised a browser exception: ${runtimeErrors.join(', ')}`);
        }

        if (screenshotPath && !verifyEvidence) {
            await delay(250);
            const screenshot = await client.send('Page.captureScreenshot', { format: 'png' });
            await writeFile(path.resolve(screenshotPath), Buffer.from(screenshot.data, 'base64'));
        }

        if (verifyEvidence && mockUploads) {
            await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]').click()");
            await waitFor(() => client.evaluate("!document.querySelector('#profile-editor-title')"), 'the evidence editor to close');
            await client.evaluate(`(() => {
                window.axios.interceptors.request.use((config) => {
                    if (config.method === 'patch' && config.url === '/dashboard/profile') {
                        const field = window.__mockProfileField ?? 'contact_number';
                        config.adapter = () => Promise.reject({ config, response: { status: 422, data: {
                            message: 'Check your profile details.', errors: { [field]: [field === 'contact_number' ? 'Enter a valid contact number.' : 'Check the learner reference number.'] },
                        } }, message: 'Mock validation error' });
                    }
                    return config;
                });
                document.querySelector('[data-profile-section="personal"] button').click();
            })()`);
            await waitFor(() => client.evaluate("Boolean(document.querySelector('#profile-contact'))"), 'the personal editor');
            await client.evaluate(`(() => {
                const input = document.querySelector('#profile-contact');
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            })()`);
            await delay(100);
            await client.evaluate("[...document.querySelectorAll('[aria-labelledby=\"profile-editor-title\"] footer button')].find((button) => button.innerText.trim() === 'Save changes').click()");
            await waitFor(() => client.evaluate("document.activeElement?.id === 'profile-contact' && document.querySelector('[data-profile-editor-body]').innerText.includes('Enter a valid contact number.')"), 'validation focus to return to the invalid field');
            await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]').click()");
            await waitFor(() => client.evaluate("Boolean(document.querySelector('[role=\"alertdialog\"]'))"), 'the discard confirmation');
            await client.evaluate("[...document.querySelectorAll('[role=\"alertdialog\"] button')].find((button) => button.innerText.trim() === 'Discard changes').click()");
            await waitFor(() => client.evaluate("!document.querySelector('#profile-editor-title')"), 'the invalid edit to be discarded');
            console.log('PASS Browser-mocked profile validation: rejected saves retain edits, announce the error and focus the invalid field; no database writes');
            if (verifySections) {
                await client.evaluate(`(() => {
                    window.__mockProfileField = 'learner_reference_number';
                    document.querySelector('[data-profile-section="academic"] button').click();
                })()`);
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[data-learning-editor]'))"), 'the Learning editor to reopen');
                await client.evaluate(`(() => {
                    const school = document.querySelector('#profile-school');
                    school.value += ' test'; school.dispatchEvent(new Event('input', { bubbles: true }));
                })()`);
                await delay(50);
                await client.evaluate("[...document.querySelector('[aria-labelledby=\"profile-editor-title\"] footer').querySelectorAll('button')].find((button) => button.innerText.trim() === 'Save changes').click()");
                await waitFor(() => client.evaluate("document.querySelector('[data-school-identifiers]').open && document.activeElement?.id === 'profile-lrn'"), 'the invalid optional school identifier to become visible and focused');
                await client.evaluate("document.querySelector('[aria-label=\"Close profile editor\"]').click()");
                await waitFor(() => client.evaluate("Boolean(document.querySelector('[role=\"alertdialog\"]'))"), 'the optional-field discard confirmation');
                await client.evaluate("[...document.querySelectorAll('[role=\"alertdialog\"] button')].find((button) => button.innerText.trim() === 'Discard changes').click()");
                await waitFor(() => client.evaluate("!document.querySelector('#profile-editor-title')"), 'the optional-field edit to be discarded');
                console.log('PASS A validation error reveals the optional school identifiers and focuses the invalid field; no database writes');
            }
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
