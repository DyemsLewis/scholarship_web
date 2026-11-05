import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const origin = process.env.DELIVERY_SMOKE_URL || 'http://127.0.0.1:8152';
if (!['127.0.0.1', 'localhost'].includes(new URL(origin).hostname)) throw new Error('Mocked delivery checks are local only.');
const password = process.env.DELIVERY_SMOKE_PASSWORD;
if (!password) throw new Error('Set the existing local demo password.');
const folder = process.env.DELIVERY_SMOKE_SCREENSHOTS;
const report = { errors: [], unexpectedWrites: [], mockedWrites: 0, screenshots: [], checks: [] };
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function wait(check, name) {
    const until = Date.now() + 45000; let last;
    while (Date.now() < until) { try { if (await check()) return; } catch (error) { last = error.message; } await delay(100); }
    throw new Error(`Timed out: ${name}${last ? ': ' + last : ''}`);
}
class Cdp {
    constructor(url) {
        this.id = 0; this.pending = new Map(); this.handlers = new Map(); this.socket = new WebSocket(url);
        this.ready = new Promise((resolve, reject) => { this.socket.addEventListener('open', resolve); this.socket.addEventListener('error', reject); });
        this.socket.addEventListener('message', ({ data }) => {
            const event = JSON.parse(data);
            if (event.id) { const job = this.pending.get(event.id); this.pending.delete(event.id); event.error ? job?.reject(new Error(event.error.message)) : job?.resolve(event.result); }
            else for (const handler of this.handlers.get(event.method) || []) handler(event.params);
        });
    }
    on(name, callback) { this.handlers.set(name, [...(this.handlers.get(name) || []), callback]); }
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
const listener = createServer(); await new Promise((resolve) => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port; await new Promise((resolve) => listener.close(resolve));
const profile = await mkdtemp(path.join(tmpdir(), 'delivery-smoke-'));
const browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore', windowsHide: true });
let control, client, contextId, applicationId, programTitle, fixture = null, acting = 'applicant', action = 'create', resultMode = 'success';
const page = (rows) => ({ data: rows, current_page: 1, last_page: 1, total: rows.length });
const proof = () => ({ id: 1, name: 'delivery-proof.pdf', mime_type: 'application/pdf', size: 400, view_url: `/${acting === 'provider' ? 'provider' : 'dashboard'}/delivery-report-evidence/901` });
function payload() {
    if (!fixture) return null;
    const prefix = acting === 'provider' ? 'provider' : 'dashboard', open = fixture.status !== 'closed';
    return { ...fixture, read_url: `/${prefix}/delivery-reports/901`, can_propose: acting === 'provider' && open, can_respond: acting === 'applicant' && open,
        propose_url: '/provider/delivery-reports/901/proposal', response_url: '/dashboard/delivery-reports/901/response',
        applicant: { name: 'Sabina Ocampo', photo_url: `/provider/applications/${applicationId}/profile-photo` }, program: { title: programTitle },
        status_label: fixture.status === 'closed' ? 'Receipt confirmed' : fixture.status === 'awaiting_recipient' ? 'Awaiting applicant confirmation' : fixture.follow_up_on ? 'Correction planned' : 'Awaiting provider',
        benefit: { title: 'Learning support allowance', description: 'Assigned support for learning materials.' },
        delivery_records: [{ id: 902, label: 'Learning support delivery - Nov 2, 2026' }],
        response_choices: fixture.status === 'awaiting_recipient' ? [{ value: 'receipt_confirmed', label: 'I received the reported benefit' }, { value: 'still_unresolved', label: 'This is still unresolved' }] : [{ value: 'additional_information', label: 'Add information for the provider' }],
    };
}
function saveMock() {
    if (action === 'create') fixture = { id: 901, revision: 1, status: 'awaiting_provider', subject: 'Benefit not received: Learning support allowance', issue_kind: 'not_received', details: 'My assigned learning support has not reached me. Please clarify the delivery date.', evidence: [], responses: [], history: [], created_at: '2026-11-02T01:00:00Z' };
    else {
        fixture.revision++;
        const reply = { id: fixture.revision, author_name: acting === 'provider' ? 'Benefit Release Officer' : 'Sabina Ocampo', created_at: '2026-11-02T03:00:00Z', evidence: [] };
        if (action === 'plan') { fixture.status = 'awaiting_provider'; fixture.follow_up_on = '2026-11-05'; Object.assign(reply, { label: 'Plan a correction', body: 'We will review the missing payment and follow up by November 5.', follow_up_on: '2026-11-05' }); }
        if (action === 'proof') { fixture.status = 'awaiting_recipient'; fixture.follow_up_on = null; Object.assign(reply, { label: 'Submit delivery proof', body: 'The learning support was delivered. Please check the attached delivery proof.', delivered_on: '2026-11-02', evidence: [proof()] }); }
        if (action === 'unresolved') { fixture.status = 'awaiting_provider'; Object.assign(reply, { label: 'Applicant reported an unresolved issue', body: 'I checked the delivery proof but the expected learning support has still not arrived.' }); }
        if (action === 'confirm') { fixture.status = 'closed'; Object.assign(reply, { label: 'Applicant confirmed receipt', body: 'I have now received the learning support.', delivered_on: '2026-11-02' }); }
        fixture.responses.push(reply);
    }
}
try {
    if (folder) await mkdir(folder, { recursive: true });
    await wait(async () => (await fetch(`http://127.0.0.1:${port}/json/version`)).ok, 'Chrome');
    control = new Cdp((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    async function configure() {
        await client.send('Page.enable'); await client.send('Runtime.enable'); await client.send('Network.enable');
        await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => report.errors.push(exceptionDetails.exception?.description || exceptionDetails.text));
        await client.send('Fetch.enable', { patterns: [{ urlPattern: origin + '/*', requestStage: 'Request' }] });
        const fulfill = (id, data, code = 200) => client.send('Fetch.fulfillRequest', { requestId: id, responseCode: code, responseHeaders: [{ name: 'content-type', value: 'application/json' }], body: Buffer.from(JSON.stringify(data)).toString('base64') });
        client.on('Fetch.requestPaused', ({ requestId, request }) => { void (async () => {
            const pathname = new URL(request.url).pathname;
            if (pathname.includes('/delivery-reports') && pathname !== '/provider/workspaces/releases/delivery-reports') {
                if (request.method === 'GET') {
                    if (pathname.endsWith('/delivery-reports/901')) return fulfill(requestId, { report: payload() });
                    return fulfill(requestId, { reports: page(fixture ? [payload()] : []), legacy_reports: page([]), options: acting === 'applicant' ? [{ type: 'entitlement', id: 903, title: 'Learning support allowance', scheduled_at: null, period_required: false }] : [], create_url: `/dashboard/applications/${applicationId}/delivery-reports`, programs: [{ id: 1, title: programTitle }] });
                }
                report.mockedWrites++;
                if (resultMode === 'conflict') return fulfill(requestId, { message: 'This report changed.' }, 409);
                if (resultMode === 'validation') return fulfill(requestId, { errors: { details: ['Provide a clearer description of what is missing.'] } }, 422);
                saveMock(); return fulfill(requestId, { report: payload(), message: 'Browser-only response recorded; no real data changed.' }, action === 'create' ? 201 : 200);
            }
            if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method) && pathname !== '/login') { report.unexpectedWrites.push(request.method + ' ' + pathname); return client.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }); }
            return client.send('Fetch.continueRequest', { requestId });
        })().catch((error) => { report.errors.push(error.message); void client.send('Fetch.failRequest', { requestId, errorReason: 'Failed' }).catch(() => {}); }); });
    }
    async function navigate(url, predicate) { await client.send('Page.navigate', { url: origin + url }); await wait(() => client.evaluate(predicate), url); }
    async function login(name) {
        client?.socket.close(); if (contextId) await control.send('Target.disposeBrowserContext', { browserContextId: contextId });
        contextId = (await control.send('Target.createBrowserContext')).browserContextId;
        const target = await control.send('Target.createTarget', { url: 'about:blank', browserContextId: contextId });
        client = new Cdp(`ws://127.0.0.1:${port}/devtools/page/${target.targetId}`); await configure();
        await navigate('/login', "!!document.querySelector('#login') && document.readyState==='complete'");
        await client.evaluate(`(() => { for(const [selector,value] of [['#login',${JSON.stringify(name)}],['#password',${JSON.stringify(password)}]]){const input=document.querySelector(selector);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));}document.querySelector('form').requestSubmit();})()`);
        await wait(() => client.evaluate("location.pathname!='/login' && !!window.axios && document.readyState==='complete'"), 'login ' + name); console.log('SIGNED_IN ' + name);
    }
    const click = (text) => client.evaluate(`(() => {const root=document.querySelector('[data-delivery-dialog]')||document.querySelector('[data-delivery-panel]');const button=[...(root||document).querySelectorAll('button')].find(x=>x.innerText.trim()===${JSON.stringify(text)});if(!button)throw new Error('Button missing: '+${JSON.stringify(text)});button.click();})()`);
    const fill = (selector, value, event = 'input') => client.evaluate(`(() => {const input=document.querySelector(${JSON.stringify(selector)});input.value=${JSON.stringify(value)};input.dispatchEvent(new Event(${JSON.stringify(event)},{bubbles:true}));})()`);
    async function audit() {
        const actual = await client.evaluate("(()=>{const d=document.querySelector('[data-delivery-dialog]'),r=d.getBoundingClientRect(),f=d.querySelector('footer').getBoundingClientRect();return {contained:r.top>=0&&r.bottom<=innerHeight+1,footer:f.bottom<=innerHeight+1,overflow:document.documentElement.scrollWidth>innerWidth+2||d.scrollWidth>d.clientWidth+2,focus:d.contains(document.activeElement),locked:document.documentElement.style.overflowY==='hidden'};})()");
        assert.deepEqual(actual, { contained: true, footer: true, overflow: false, focus: true, locked: true });
    }
    async function shot(name) { if (!folder) return; await delay(170); const { data } = await client.send('Page.captureScreenshot', { format: 'png' }); const output = path.join(folder, name + '.png'); await writeFile(output, Buffer.from(data, 'base64')); report.screenshots.push(output); }
    const check = () => client.evaluate("document.querySelector('[data-delivery-dialog] input[type=checkbox]').click()");
    const waitDialog = () => wait(() => client.evaluate("!!document.querySelector('[data-delivery-dialog]')"), 'dialog');

    await login('learner.support.decision');
    const award = await client.evaluate("(async()=>{const rows=(await window.axios.get('/dashboard/applications/data')).data.applications;const row=rows.find(x=>x.recipient_agreement?.status==='accepted');if(!row)throw new Error('An accepted demo award is required.');return {id:row.id,title:row.scholarship.title};})()");
    applicationId = award.id; programTitle = award.title;
    const benefits = `/dashboard/monitoring/${applicationId}/benefits`;
    await navigate(benefits, "!!document.querySelector('[data-delivery-panel]') && document.body.innerText.includes('Report a benefit issue')");
    await client.evaluate("document.querySelector('[data-delivery-panel]').scrollIntoView({block:'center'})");
    const background = await client.evaluate("document.querySelector('[data-delivery-panel]').getBoundingClientRect().top");
    await click('Report a benefit issue'); await waitDialog(); await audit();
    assert.ok(Math.abs(background - await client.evaluate("document.querySelector('[data-delivery-panel]').getBoundingClientRect().top")) < 2);
    await fill('[data-delivery-dialog] select', 'entitlement:903', 'change');
    await fill('[data-delivery-dialog] label:nth-of-type(2) select', 'not_received', 'change');
    await fill('[data-delivery-dialog] textarea', 'My assigned learning support has not reached me. Please clarify the delivery date.');
    await check(); await shot('applicant-new-benefit-report'); await click('Send report');
    await wait(() => client.evaluate("document.querySelector('[data-delivery-dialog]')?.innerText.includes('Awaiting provider')"), 'report sent'); await audit(); await click('Close');
    report.checks.push('Applicant: report an assigned benefit without an invented schedule; modal leaves background position intact.');

    acting = 'provider'; await login('marilag.releases');
    await navigate('/provider/workspaces/releases/delivery-reports', "document.querySelector('h1')?.innerText==='Delivery reports' && !!document.querySelector('tbody img')");
    await wait(() => client.evaluate("document.querySelector('tbody img').naturalWidth>0"), 'private applicant photo'); await shot('provider-delivery-report-queue');
    await client.evaluate("document.querySelector('tbody button').click()"); await waitDialog(); await click('Respond to report');
    action = 'plan'; await fill('[data-delivery-dialog] select', 'correction_planned', 'change'); await fill('[data-delivery-dialog] input[type=date]', '2026-11-05');
    await fill('[data-delivery-dialog] textarea', 'We will review the missing payment and follow up by November 5.'); await check(); await audit(); await shot('provider-correction-plan'); await click('Send response');
    await wait(() => client.evaluate("document.querySelector('[data-delivery-dialog]')?.innerText.includes('Correction planned')"), 'correction planned');
    await click('Respond to report'); action = 'proof'; await fill('[data-delivery-dialog] select', 'delivery_proposed', 'change');
    await fill('[data-delivery-dialog] label:nth-of-type(2) select', '902', 'change'); await fill('[data-delivery-dialog] input[type=date]', '2026-11-02');
    await fill('[data-delivery-dialog] textarea', 'The learning support was delivered. Please check the attached delivery proof.');
    await client.evaluate("(()=>{const transfer=new DataTransfer();transfer.items.add(new File(['%PDF-1.4 browser-only proof'],'delivery-proof.pdf',{type:'application/pdf'}));const input=document.querySelector('[data-delivery-dialog] input[type=file]');input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));})()");
    await check(); resultMode = 'conflict'; await click('Send response'); await wait(() => client.evaluate("!!document.querySelector('[data-delivery-error]')"), 'stale response');
    await click('Reload report'); await wait(() => client.evaluate("!document.querySelector('[data-delivery-error]')"), 'fresh report');
    assert.equal(await client.evaluate("document.querySelector('[data-delivery-dialog] input[type=checkbox]').checked"), false);
    assert.match(await client.evaluate("document.querySelector('[data-delivery-dialog] textarea').value"), /attached delivery proof/);
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 720, deviceScaleFactor: 1, mobile: false }); await audit(); await shot('provider-delivery-proof');
    resultMode = 'success'; await check(); await click('Send response'); await wait(() => client.evaluate("document.querySelector('[data-delivery-dialog]')?.innerText.includes('Awaiting applicant confirmation')"), 'proof proposed'); await audit(); await click('Close');
    report.checks.push('Provider: correction date is not receipt; recorded-release proof proposal; stale reload retains files/text and requires reconfirmation; short desktop containment.');

    acting = 'applicant'; await login('learner.support.decision'); await navigate(benefits, "!!document.querySelector('[data-delivery-panel]') && document.body.innerText.includes('Review and respond')");
    await client.evaluate("document.querySelector('[data-delivery-panel]').scrollIntoView({block:'center'})"); await click('Review and respond'); await waitDialog(); await shot('applicant-review-delivery-proof'); await click('Review and respond');
    action = 'unresolved'; await fill('[data-delivery-dialog] select', 'still_unresolved', 'change'); await fill('[data-delivery-dialog] textarea', 'I checked the delivery proof but the expected learning support has still not arrived.');
    await client.evaluate("document.querySelector('[data-delivery-dialog]').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))"); await wait(() => client.evaluate("document.querySelector('[data-delivery-dialog]')?.innerText.includes('Discard your unsent response?')"), 'discard prompt'); await click('Keep editing');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await audit(); await shot('applicant-unresolved-mobile');
    await check(); resultMode = 'validation'; await click('Send response'); await wait(() => client.evaluate("document.querySelector('[data-delivery-error]')?.innerText.includes('clearer description')"), 'validation feedback'); assert.match(await client.evaluate("document.querySelector('[data-delivery-dialog] textarea').value"), /still not arrived/);
    resultMode = 'success'; await click('Send response'); await wait(() => client.evaluate("document.querySelector('[data-delivery-dialog]')?.innerText.includes('Awaiting provider')"), 'report remains open'); await audit(); await click('Close');
    action = 'proof'; saveMock();
    await navigate(benefits, "!!document.querySelector('[data-delivery-panel]') && document.body.innerText.includes('Review and respond')"); await click('Review and respond'); await waitDialog(); await click('Review and respond');
    action = 'confirm'; await fill('[data-delivery-dialog] select', 'receipt_confirmed', 'change'); await fill('[data-delivery-dialog] input[type=date]', '2026-11-02'); await check(); await audit(); await shot('applicant-confirm-receipt-mobile'); await click('Confirm receipt');
    await wait(() => client.evaluate("document.querySelector('[data-delivery-dialog]')?.innerText.includes('Receipt confirmed') && !document.querySelector('[data-delivery-dialog] form')"), 'applicant-confirmed resolution'); await audit(); await shot('applicant-confirmed-report-mobile'); await click('Close');
    await wait(() => client.evaluate("!document.querySelector('[data-delivery-dialog]') && document.documentElement.style.overflowY!=='hidden'"), 'viewport restored');
    report.checks.push('Applicant: review evidence, unresolved response keeps report open, validation/discard retains draft, explicit dated receipt confirmation closes report, phone containment and viewport restoration.');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.unexpectedWrites, []); report.passed = true;
    console.log('PASS Delivery report browser checks; every delivery write was intercepted and no real report was saved.');
} catch (error) {
    report.failure = error.message; report.screen = await client?.evaluate("({path:location.pathname,text:document.body.innerText.slice(-2600)})").catch(() => null); throw error;
} finally {
    await writeFile('storage/deployment/benefit-delivery-part3-browser-20261102.json', JSON.stringify(report, null, 2));
    client?.socket.close(); control?.socket.close(); browser.kill(); await delay(600);
    const target = path.resolve(profile); assert.ok(target.startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(target).startsWith('delivery-smoke-'));
    await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 250 });
}
