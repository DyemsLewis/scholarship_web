import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const origin = process.env.WITHDRAWAL_SMOKE_URL || 'http://127.0.0.1:8147';
if (!['localhost', '127.0.0.1'].includes(new URL(origin).hostname)) throw new Error('Mocked workflow checks are local only.');
const password = process.env.WITHDRAWAL_SMOKE_PASSWORD;
if (!password) throw new Error('Set the existing local demo password.');
const folder = process.env.WITHDRAWAL_SMOKE_SCREENSHOTS;
const report = { errors: [], unexpectedWrites: [], screenshots: [], checks: [] };
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function wait(check, label) {
    const deadline = Date.now() + 45000;
    let last;
    while (Date.now() < deadline) {
        try { if (await check()) return; } catch (error) { last = error.message; }
        await delay(120);
    }
    throw new Error(`Timed out: ${label}${last ? ': ' + last : ''}`);
}
class Cdp {
    constructor(url) {
        this.id = 0; this.pending = new Map(); this.handlers = new Map(); this.socket = new WebSocket(url);
        this.ready = new Promise((resolve, reject) => { this.socket.addEventListener('open', resolve); this.socket.addEventListener('error', reject); });
        this.socket.addEventListener('message', ({ data }) => {
            const message = JSON.parse(data);
            if (message.id) { const p = this.pending.get(message.id); this.pending.delete(message.id); message.error ? p?.reject(new Error(message.error.message)) : p?.resolve(message.result); }
            else for (const handler of this.handlers.get(message.method) ?? []) handler(message.params);
        });
    }
    on(name, handler) { this.handlers.set(name, [...(this.handlers.get(name) || []), handler]); }
    async send(method, params = {}) {
        await this.ready; const id = ++this.id;
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => { this.pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 45000);
            this.pending.set(id, { resolve: (result) => { clearTimeout(timer); resolve(result); }, reject: (error) => { clearTimeout(timer); reject(error); } });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }
    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    }
}
const server = createServer(); await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const port = server.address().port; await new Promise((resolve) => server.close(resolve));
const profile = await mkdtemp(path.join(tmpdir(), 'withdrawal-smoke-'));
const browser = spawn(process.env.WITHDRAWAL_SMOKE_BROWSER || 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--window-size=1440,1000',
    `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore', windowsHide: true });
let client, browserControl, contextId, fixture = null, acting = 'applicant', applicationId, programTitle;
let mutationResult = 'success';
let mockedWrites = 0;
const makeRequest = () => ({
    id: 901, revision: 1, status: 'pending', reason: 'My study circumstances have changed and I need to leave this scholarship.',
    requested_at: '2026-11-01T10:00:00+08:00', resolved_at: null,
    applicant: { name: 'Sabina Ocampo', profile_photo_url: `/provider/applications/${applicationId}/profile-photo` },
    program: { id: 1, title: programTitle },
    agreement: { accepted_at: '2026-10-25T12:00:00+08:00', version: 'browser-only-accepted-terms', snapshot: {
        recipient_expectation: { responsibilities: 'Stay enrolled and provide the agreed progress update.', exit_or_exception_process: 'Contact the provider when circumstances change.' },
    } },
    history: [{ revision: 1, to_status: 'pending', actor_name: 'Sabina Ocampo', note: 'Request submitted for provider review.', created_at: '2026-11-01T10:00:00+08:00' }],
});
function payload(row) {
    return { ...row, can_cancel: acting === 'applicant' && row.status === 'pending', can_review: acting === 'provider' && row.status === 'pending',
        read_url: `/${acting === 'applicant' ? 'dashboard' : 'provider'}/recipient-withdrawals/901`,
        cancel_url: acting === 'applicant' && row.status === 'pending' ? '/dashboard/recipient-withdrawals/901/cancel' : null,
        review_url: acting === 'provider' && row.status === 'pending' ? '/provider/recipient-withdrawals/901/review' : null };
}
function page(rows) { return { data: rows, current_page: 1, last_page: 1, total: rows.length, from: rows.length ? 1 : null, to: rows.length || null }; }
try {
    if (folder) await mkdir(folder, { recursive: true });
    await wait(async () => (await fetch(`http://127.0.0.1:${port}/json/version`)).ok, 'Chrome');
    browserControl = new Cdp((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    async function configure(client) {
    await client.send('Page.enable'); await client.send('Runtime.enable'); await client.send('Network.enable');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    client.on('Network.responseReceived', ({ response }) => {
        if (response.url === origin + '/login' && response.status >= 400) report.loginFailureStatus = response.status;
    });
    client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => report.errors.push(exceptionDetails.exception?.description || exceptionDetails.text));
    await client.send('Fetch.enable', { patterns: [{ urlPattern: origin + '/*', requestStage: 'Request' }] });
    const fulfill = (requestId, value, responseCode = 200) => client.send('Fetch.fulfillRequest', { requestId, responseCode,
        responseHeaders: [{ name: 'content-type', value: 'application/json' }], body: Buffer.from(JSON.stringify(value)).toString('base64') });
    client.on('Fetch.requestPaused', ({ requestId, request: requestData }) => { void (async () => {
        const url = new URL(requestData.url); const pathname = url.pathname; const method = requestData.method;
        if (pathname.includes('recipient-withdrawals') || pathname === '/provider/workspaces/recipients/withdrawals/data') {
            if (method === 'GET') {
                if (/recipient-withdrawals\/901$/.test(pathname)) return fulfill(requestId, { request: payload(fixture) });
                let rows = fixture ? [payload(fixture)] : [];
                if (acting === 'provider' && !['all', fixture?.status].includes(url.searchParams.get('status'))) rows = [];
                return fulfill(requestId, { can_request: !fixture || ['declined', 'cancelled'].includes(fixture.status), requests: page(rows), programs: [{ id: 1, title: programTitle }] });
            }
            mockedWrites++;
            const data = JSON.parse(requestData.postData || '{}');
            if (mutationResult === 'validation') return fulfill(requestId, { message: 'Provide a clear explanation.', errors: { review_note: ['Provide a clear explanation.'] } }, 422);
            if (mutationResult === 'conflict') { fixture.revision++; return fulfill(requestId, { message: 'Request changed.' }, 409); }
            await delay(450);
            if (method === 'POST') { fixture = makeRequest(); fixture.reason = data.reason; }
            else {
                assert.equal(data.expected_revision, fixture.revision);
                fixture = { ...fixture, status: pathname.endsWith('/cancel') ? 'cancelled' : data.outcome, revision: fixture.revision + 1,
                    review_note: data.review_note || null, resolved_at: '2026-11-02T10:00:00+08:00' };
                fixture.history.push({ revision: fixture.revision, to_status: fixture.status, actor_name: acting === 'provider' ? 'Recipient Officer' : 'Sabina Ocampo', note: data.review_note || 'Cancelled by the applicant.', created_at: fixture.resolved_at });
            }
            return fulfill(requestId, { request: payload(fixture), message: 'Browser-only request action saved.' }, method === 'POST' ? 201 : 200);
        }
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && pathname !== '/login') {
            report.unexpectedWrites.push(`${method} ${pathname}`);
            return client.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
        }
        return client.send('Fetch.continueRequest', { requestId });
    })().catch((error) => { report.errors.push(error.message); void client.send('Fetch.failRequest', { requestId, errorReason: 'Failed' }).catch(() => {}); }); });
    }
    const navigate = async (route, predicate) => { await client.send('Page.navigate', { url: origin + route }); await wait(() => client.evaluate(predicate), route); };
    const login = async (name) => {
        client?.socket.close();
        if (contextId) await browserControl.send('Target.disposeBrowserContext', { browserContextId: contextId });
        contextId = (await browserControl.send('Target.createBrowserContext')).browserContextId;
        const target = await browserControl.send('Target.createTarget', { url: 'about:blank', browserContextId: contextId });
        client = new Cdp(`ws://127.0.0.1:${port}/devtools/page/${target.targetId}`);
        await configure(client);
        await navigate('/login', "!!document.querySelector('#login') && document.readyState === 'complete'");
        await client.evaluate(`(() => { for (const [selector, value] of [['#login',${JSON.stringify(name)}],['#password',${JSON.stringify(password)}]]) { const input = document.querySelector(selector); input.value = value; input.dispatchEvent(new Event('input',{bubbles:true})); } document.querySelector('form').requestSubmit(); })()`);
        await wait(() => client.evaluate("location.pathname !== '/login' && !!window.axios && document.readyState === 'complete'"), 'login ' + name);
        console.log('SIGNED_IN ' + name);
    };
    const click = (label) => client.evaluate(`(() => { const button=[...document.querySelectorAll('button')].find(item => item.innerText.trim() === ${JSON.stringify(label)}); if(!button)throw new Error('Missing button: '+${JSON.stringify(label)}); button.click(); })()`);
    const fill = (selector, value) => client.evaluate(`(() => { const element=document.querySelector(${JSON.stringify(selector)}); element.value=${JSON.stringify(value)}; element.dispatchEvent(new Event('input',{bubbles:true})); })()`);
    const shot = async (name) => {
        if (!folder) return;
        await delay(180); const result = await client.send('Page.captureScreenshot', { format: 'png' });
        const file = path.join(folder, name + '.png'); await writeFile(file, Buffer.from(result.data, 'base64')); report.screenshots.push(file);
    };
    const auditDialog = async () => {
        const audit = await client.evaluate(`(() => { const dialog=document.querySelector('[data-withdrawal-dialog]'); const box=dialog.getBoundingClientRect(); const footer=dialog.querySelector('footer').getBoundingClientRect(); return { overflow:document.documentElement.scrollWidth>innerWidth+2 || dialog.scrollWidth>dialog.clientWidth+2, contained:box.top>=0&&box.bottom<=innerHeight+1, footer:footer.bottom<=innerHeight+1, focus:dialog.contains(document.activeElement), locked:document.documentElement.style.overflowY==='hidden' }; })()`);
        assert.deepEqual(audit, { overflow: false, contained: true, footer: true, focus: true, locked: true });
    };
    await login(process.env.WITHDRAWAL_SMOKE_APPLICANT || 'learner.support.decision');
    const identity = await client.evaluate("(async()=>{const rows=(await window.axios.get('/dashboard/applications/data')).data.applications; const application=rows.find(item=>item.recipient_agreement?.status==='accepted'); if(!application)throw new Error('An existing accepted award is required.');return {id:application.id,title:application.scholarship.title};})()");
    applicationId = identity.id; programTitle = identity.title;
    await navigate(`/dashboard/applications/${applicationId}/outcome`, "!!document.querySelector('[data-withdrawal-panel]') && document.body.innerText.includes('Request withdrawal')");
    await client.evaluate("document.querySelector('[data-withdrawal-panel]').scrollIntoView({block:'center'})");
    await shot('applicant-withdrawal-section-desktop');
    const background = await client.evaluate("({x:document.querySelector('.student-page').getBoundingClientRect().x,w:document.querySelector('.student-page').getBoundingClientRect().width,y:scrollY})");
    await click('Request withdrawal'); await wait(() => client.evaluate("!!document.querySelector('[data-withdrawal-dialog]')"), 'applicant request dialog'); await auditDialog();
    const during = await client.evaluate("({x:document.querySelector('.student-page').getBoundingClientRect().x,w:document.querySelector('.student-page').getBoundingClientRect().width,y:scrollY})");
    assert.deepEqual(during, background);
    await fill('[data-withdrawal-dialog] textarea', 'I need to leave the program because my study plans have changed.');
    await client.evaluate("document.querySelector('[data-withdrawal-dialog]').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
    await wait(() => client.evaluate("document.querySelector('[data-withdrawal-dialog]').innerText.includes('Discard this unsaved explanation?')"), 'discard prompt');
    await click('Keep editing');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await delay(150); await auditDialog(); await shot('applicant-withdrawal-request-mobile');
    await client.evaluate("document.querySelector('[data-withdrawal-dialog] input[type=checkbox]').click()");
    await click('Send request');
    assert.equal(await client.evaluate("document.querySelector('[data-withdrawal-dialog] button[aria-label]').disabled"), true);
    await wait(() => client.evaluate("!document.querySelector('[data-withdrawal-dialog]') && document.querySelector('[data-withdrawal-panel]').innerText.includes('Awaiting provider review')"), 'mock request submitted');
    assert.equal(await client.evaluate("document.documentElement.style.overflowY"), '');
    await click('View request'); await click('Cancel pending request');
    await wait(() => client.evaluate("document.querySelector('[data-withdrawal-dialog] h2').innerText.includes('Cancel your')"), 'cancel mode');
    await client.evaluate("document.querySelector('[data-withdrawal-dialog] input[type=checkbox]').click()"); await click('Cancel withdrawal request');
    await wait(() => client.evaluate("!document.querySelector('[data-withdrawal-dialog]') && document.querySelector('[data-withdrawal-panel]').innerText.includes('Request cancelled')"), 'mock cancellation');
    report.checks.push('Applicant desktop/mobile: stable background, contained/focused dialog, explicit discard, busy close, mock request/cancel.');

    acting = 'provider'; fixture = makeRequest();
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await login(process.env.WITHDRAWAL_SMOKE_PROVIDER || 'marilag.recipients');
    await navigate('/provider/workspaces/recipients/withdrawals', "document.querySelector('h1')?.innerText === 'Withdrawal requests' && !!document.querySelector('tbody button')");
    await wait(() => client.evaluate("[...document.querySelectorAll('tbody img')].every(img=>img.complete&&img.naturalWidth>0)"), 'recipient photos');
    await shot('provider-withdrawal-queue');
    await click('Review'); await wait(() => client.evaluate("!!document.querySelector('[data-withdrawal-dialog] textarea')"), 'provider review'); await auditDialog();
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 720, deviceScaleFactor: 1, mobile: false });
    await delay(120); await auditDialog();
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    assert.equal(await client.evaluate("document.querySelector('[data-withdrawal-dialog] input[type=radio]:checked') === null"), true);
    await client.evaluate("document.querySelector('[data-withdrawal-dialog] input[value=approved]').click()");
    await fill('[data-withdrawal-dialog] textarea', 'We reviewed your circumstances and approve the requested exit.');
    await client.evaluate("document.querySelector('[data-withdrawal-dialog] input[type=checkbox]').click()");
    mutationResult = 'validation'; await click('Approve withdrawal');
    await wait(() => client.evaluate("document.querySelector('[data-withdrawal-error]')?.innerText.includes('Provide a clear explanation')"), 'validation recovery');
    assert.match(await client.evaluate("document.querySelector('[data-withdrawal-dialog] textarea').value"), /approve the requested exit/);
    mutationResult = 'conflict'; await click('Approve withdrawal');
    await wait(() => client.evaluate("document.querySelector('[data-withdrawal-error]')?.innerText.includes('Reload the current record')"), 'stale request conflict');
    await shot('provider-withdrawal-conflict');
    await click('Reload current request');
    await wait(() => client.evaluate("!document.querySelector('[data-withdrawal-error]')"), 'reload');
    assert.equal(await client.evaluate("document.querySelector('[data-withdrawal-dialog] input[type=checkbox]').checked"), false);
    await client.evaluate("document.querySelector('[data-withdrawal-dialog] details').open=true; document.querySelector('[data-withdrawal-body]').scrollTop=0");
    await shot('provider-withdrawal-review');
    await client.evaluate("document.querySelector('[data-withdrawal-dialog] input[type=checkbox]').click()");
    mutationResult = 'success'; await click('Approve withdrawal');
    await wait(() => client.evaluate("!document.querySelector('[data-withdrawal-dialog]') && document.body.innerText.includes('No pending requests found')"), 'mock review success');
    await client.evaluate("const select=[...document.querySelectorAll('select')].at(-1);select.value='all';select.dispatchEvent(new Event('change',{bubbles:true}))");
    await wait(() => client.evaluate("document.querySelector('tbody')?.innerText.includes('Withdrawal approved')"), 'approved history');
    await click('View'); await wait(() => client.evaluate("!!document.querySelector('[data-withdrawal-dialog]')"), 'read-only outcome');
    assert.equal(await client.evaluate("document.querySelector('[data-withdrawal-dialog] textarea') === null"), true);
    await click('Close');
    report.checks.push('Recipient officer: queue, photo, explicit outcome, validation/draft preservation, stale reload/reconfirmation, read-only resolved history.');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.unexpectedWrites, []);
    report.mockedWrites = mockedWrites; report.passed = true;
    console.log('PASS Withdrawal browser checks. All request/cancel/review writes were intercepted; no demo decisions were saved.');
} catch (error) {
    report.failure = error.message;
    report.screen = client ? await client.evaluate("({path:location.pathname,text:document.body.innerText.slice(0,1800)})").catch(() => null) : null;
    throw error;
} finally {
    await writeFile('storage/deployment/recipient-withdrawal-part2-browser-20261102.json', JSON.stringify(report, null, 2));
    client?.socket.close(); browserControl?.socket.close(); browser.kill(); await delay(600);
    const target = path.resolve(profile);
    assert.ok(target.startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(target).startsWith('withdrawal-smoke-'));
    await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 250 });
}
