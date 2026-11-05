import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const origin = process.env.CONCERN_SMOKE_URL || 'http://127.0.0.1:8148';
if (!['127.0.0.1', 'localhost'].includes(new URL(origin).hostname)) throw new Error('Mocked concern testing is local only.');
const password = process.env.CONCERN_SMOKE_PASSWORD;
if (!password) throw new Error('Set the existing local demo password.');
const folder = process.env.CONCERN_SMOKE_SCREENSHOTS;
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
const profile = await mkdtemp(path.join(tmpdir(), 'concern-smoke-'));
const browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore', windowsHide: true });
let control, client, contextId, applicationId, programTitle, fixture = null, acting = 'applicant', resultMode = 'success';
const option = { key: 'recipient_expectation.responsibilities', label: 'Recipient responsibilities', text: 'Stay enrolled and submit the agreed progress records.' };
const base = () => ({
    id: 901, revision: 1, status: 'draft', subject: 'Enrollment record needs clarification', details: 'Please explain the missing enrollment record for this semester.',
    applicant: { name: 'Sabina Ocampo', photo_url: `/provider/applications/${applicationId}/profile-photo` }, program: { title: programTitle },
    obligation: option, agreement: { version: 'browser-only-accepted-v1', accepted_at: '2026-10-25T08:00:00Z' },
    evidence: [], responses: [], history: [], created_at: '2026-11-02T01:00:00Z', notice_sent_at: null, response_due_at: null,
});
function payload() {
    if (!fixture) return null;
    const draft = acting === 'provider' && fixture.status === 'draft';
    const prefix = acting === 'provider' ? 'provider' : 'dashboard';
    return { ...fixture, read_url: `/${prefix}/agreement-concerns/901`, can_edit: draft, can_issue: draft,
        can_respond: acting === 'applicant' && fixture.status === 'awaiting_response',
        edit_url: draft ? '/provider/agreement-concerns/901' : null,
        upload_url: draft ? '/provider/agreement-concerns/901/evidence' : null,
        cancel_url: draft ? '/provider/agreement-concerns/901/cancel' : null,
        issue_url: draft ? '/provider/agreement-concerns/901/issue' : null,
        response_url: acting === 'applicant' ? '/dashboard/agreement-concerns/901/response' : null,
    };
}
const page = (rows) => ({ data: rows, current_page: 1, last_page: 1, total: rows.length });
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
            if (pathname.includes('/agreement-concerns') || pathname === '/provider/workspaces/recipients/concerns/data') {
                if (request.method === 'GET') {
                    if (pathname.endsWith('/agreement-concerns/901')) return fulfill(requestId, { case: payload() });
                    return fulfill(requestId, { cases: page(fixture ? [payload()] : []), options: acting === 'provider' ? [option] : [], create_url: acting === 'provider' ? `/provider/applications/${applicationId}/agreement-concerns` : null, programs: [{ id: 1, title: programTitle }] });
                }
                report.mockedWrites++;
                if (resultMode === 'conflict') return fulfill(requestId, { message: 'This concern changed.' }, 409);
                if (resultMode === 'validation') return fulfill(requestId, { errors: { details: ['Provide a clearer explanation.'] } }, 422);
                if (pathname.endsWith('/agreement-concerns') && request.method === 'POST') {
                    fixture = { ...base(), ...JSON.parse(request.postData) };
                } else if (pathname.endsWith('/issue')) {
                    fixture = { ...fixture, status: 'awaiting_response', notice_sent_at: '2026-11-02T01:00:00Z', response_due_at: '2026-11-09T01:00:00Z' };
                } else if (pathname.endsWith('/response')) {
                    fixture = { ...fixture, status: 'under_review', response_received_at: '2026-11-02T02:00:00Z', responses: [{ id: 1, kind_label: 'Request an exception or more time', author_name: 'Sabina Ocampo', body: 'My school is still preparing the record. Please allow more time.', created_at: '2026-11-02T02:00:00Z', evidence: [] }] };
                } else if (request.method === 'DELETE') fixture.evidence = [];
                else if (pathname.endsWith('/evidence')) fixture.evidence = [{ id: 1, name: 'enrollment.pdf', mime_type: 'application/pdf', size: 400, view_url: '/provider/agreement-concern-evidence/901', remove_url: '/provider/agreement-concerns/901/evidence/1' }];
                else if (pathname.endsWith('/cancel')) fixture.status = 'cancelled';
                else fixture = { ...fixture, ...JSON.parse(request.postData) };
                fixture.revision++;
                return fulfill(requestId, { case: payload(), message: 'Browser-only action saved. No real concern was submitted.' });
            }
            if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method) && pathname !== '/login') {
                report.unexpectedWrites.push(request.method + ' ' + pathname);
                return client.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
            }
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
        await wait(() => client.evaluate("location.pathname!='/login' && !!window.axios && document.readyState==='complete'"), 'login ' + name);
        console.log('SIGNED_IN ' + name);
    }
    const click = (text) => client.evaluate(`(() => {const button=[...document.querySelectorAll('[data-concern-dialog] button, [data-concern-panel] button')].find(x=>x.innerText.trim()===${JSON.stringify(text)});if(!button)throw new Error('Button missing: '+${JSON.stringify(text)});button.click();})()`);
    const fill = (selector, value, event = 'input') => client.evaluate(`(() => {const input=document.querySelector(${JSON.stringify(selector)});input.value=${JSON.stringify(value)};input.dispatchEvent(new Event(${JSON.stringify(event)},{bubbles:true}));})()`);
    async function audit() {
        const value = await client.evaluate("(()=>{const d=document.querySelector('[data-concern-dialog]'),r=d.getBoundingClientRect(),f=d.querySelector('footer').getBoundingClientRect();return {contained:r.top>=0&&r.bottom<=innerHeight+1,footer:f.bottom<=innerHeight+1,overflow:document.documentElement.scrollWidth>innerWidth+2||d.scrollWidth>d.clientWidth+2,focus:d.contains(document.activeElement),locked:document.documentElement.style.overflowY==='hidden'};})()");
        assert.deepEqual(value, { contained: true, footer: true, overflow: false, focus: true, locked: true });
    }
    async function shot(name) { if (!folder) return; await delay(160); const { data } = await client.send('Page.captureScreenshot', { format: 'png' }); const output = path.join(folder, name + '.png'); await writeFile(output, Buffer.from(data, 'base64')); report.screenshots.push(output); }

    await login('learner.support.decision');
    const award = await client.evaluate("(async()=>{const rows=(await window.axios.get('/dashboard/applications/data')).data.applications;const row=rows.find(x=>x.recipient_agreement?.status==='accepted');if(!row)throw new Error('An accepted demo award is required.');return {id:row.id,title:row.scholarship.title};})()");
    applicationId = award.id; programTitle = award.title;
    acting = 'provider'; await login('marilag.recipients');
    await navigate(`/provider/applications/${applicationId}`, "!!document.querySelector('[data-concern-panel]') && document.body.innerText.includes('New concern')");
    await client.evaluate("document.querySelector('[data-concern-panel]').scrollIntoView({block:'center'})");
    const background = await client.evaluate("({x:document.querySelector('.student-page').getBoundingClientRect().x,w:document.querySelector('.student-page').getBoundingClientRect().width,y:scrollY})");
    await click('New concern'); await wait(() => client.evaluate("!!document.querySelector('[data-concern-dialog]')"), 'provider dialog'); await audit();
    assert.deepEqual(await client.evaluate("({x:document.querySelector('.student-page').getBoundingClientRect().x,w:document.querySelector('.student-page').getBoundingClientRect().width,y:scrollY})"), background);
    await fill('[data-concern-dialog] select', option.key, 'change');
    await fill('[data-concern-dialog] input:not([type])', 'Enrollment record needs clarification');
    await fill('[data-concern-dialog] textarea', 'Please explain the missing enrollment record for this semester.');
    await shot('provider-concern-accepted-term');
    await click('Continue'); await wait(() => client.evaluate("document.querySelector('[data-concern-dialog]').innerText.includes('Support the concern with evidence')"), 'evidence step');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 720, deviceScaleFactor: 1, mobile: false });
    await delay(120); await audit();
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await client.evaluate("(()=>{const transfer=new DataTransfer();transfer.items.add(new File(['%PDF-1.4 browser-only proof'],'enrollment.pdf',{type:'application/pdf'}));const input=document.querySelector('[data-provider-file]');input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));})()");
    await click('Add file'); await wait(() => client.evaluate("document.querySelector('[data-concern-dialog]').innerText.includes('enrollment.pdf')"), 'proof added');
    await shot('provider-concern-evidence'); await click('Continue');
    assert.equal(await client.evaluate("document.querySelector('[data-concern-dialog] input[type=checkbox]').checked"), false);
    await client.evaluate("document.querySelector('[data-concern-dialog] input[type=checkbox]').click()");
    resultMode = 'conflict'; await click('Send notice'); await wait(() => client.evaluate("!!document.querySelector('[data-concern-error]')"), 'stale notice');
    await click('Reload current concern'); await wait(() => client.evaluate("!document.querySelector('[data-concern-error]')"), 'reload');
    assert.equal(await client.evaluate("document.querySelector('[data-concern-dialog] input[type=checkbox]').checked"), false);
    await shot('provider-concern-notice-review');
    await client.evaluate("document.querySelector('[data-concern-dialog] input[type=checkbox]').click()"); resultMode = 'success'; await click('Send notice');
    await wait(() => client.evaluate("document.querySelector('[data-concern-dialog]').innerText.includes('Awaiting response')"), 'issued notice'); await click('Close');
    report.checks.push('Provider: accepted-term wizard, private proof, explicit notice acknowledgement, stale reload/reconfirmation, unchanged background.');
    await navigate('/provider/workspaces/recipients/concerns', "document.querySelector('h1')?.innerText==='Agreement concerns' && !!document.querySelector('tbody img')");
    await wait(() => client.evaluate("document.querySelector('tbody img').naturalWidth>0"), 'real private applicant photo');
    await shot('provider-agreement-concern-queue');

    acting = 'applicant'; await login('learner.support.decision');
    await navigate(`/dashboard/applications/${applicationId}/outcome`, "!!document.querySelector('[data-concern-panel]') && document.body.innerText.includes('Review and respond')");
    await client.evaluate("document.querySelector('[data-concern-panel]').scrollIntoView({block:'center'})"); await shot('applicant-concern-panel');
    await click('Review and respond'); await wait(() => client.evaluate("!!document.querySelector('[data-concern-dialog]')"), 'applicant notice'); await audit(); await shot('applicant-agreement-concern');
    await click('Respond to concern');
    await fill('[data-concern-dialog] select', 'exception_request', 'change');
    await fill('[data-concern-dialog] textarea', 'My school is still preparing the record. Please allow more time.');
    await client.evaluate("document.querySelector('[data-concern-dialog]').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))");
    await wait(() => client.evaluate("document.querySelector('[data-concern-dialog]').innerText.includes('Close without saving your latest text?')"), 'discard confirmation');
    await click('Keep editing');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await delay(160); await audit();
    await shot('applicant-concern-response-mobile');
    await client.evaluate("document.querySelector('[data-concern-dialog] input[type=checkbox]').click()");
    resultMode = 'validation'; await click('Send response'); await wait(() => client.evaluate("document.querySelector('[data-concern-error]')?.innerText.includes('clearer explanation')"), 'response validation');
    assert.match(await client.evaluate("document.querySelector('[data-concern-dialog] textarea').value"), /allow more time/);
    resultMode = 'success'; await click('Send response'); await wait(() => client.evaluate("document.querySelector('[data-concern-dialog]').innerText.includes('Response received')"), 'response saved');
    await audit(); await shot('applicant-concern-response-recorded-mobile'); await click('Close');
    report.checks.push('Applicant: read notice before responding, explanation/correction/exception selection, draft retention, discard/focus, phone containment, read-only saved response.');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.unexpectedWrites, []);
    report.passed = true;
    console.log('PASS Agreement concern browser checks; all concern writes were intercepted, not saved to demo data.');
} catch (error) {
    report.failure = error.message; report.screen = await client?.evaluate("({path:location.pathname,text:document.body.innerText.slice(0,1800)})").catch(() => null); throw error;
} finally {
    await writeFile('storage/deployment/agreement-concern-part2-browser-20261102.json', JSON.stringify(report, null, 2));
    client?.socket.close(); control?.socket.close(); browser.kill(); await delay(600);
    const target = path.resolve(profile);
    assert.ok(target.startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(target).startsWith('concern-smoke-'));
    await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 250 });
}
