import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const origin = 'https://findscholarship.online';
const password = process.env.ACCOUNTABILITY_DEMO_PASSWORD;
if (!password) throw new Error('Set the existing demo password.');
const folder = 'C:/Users/James/.codex/visualizations/2026/04/20/019da896-fac2-7a43-95f3-aeb7ea1ba958/accountability-hosted-part6';
const report = { roles: [], errors: [], serverErrors: [], blockedWrites: [], screenshots: [], verified_application_ids: [] };
const delay = (ms) => new Promise((yes) => setTimeout(yes, ms));
async function wait(check, label) { const end = Date.now() + 60000; let last; while (Date.now() < end) { try { if (await check()) return; } catch (e) { last = e.message; } await delay(150); } throw new Error('Timed out: ' + label + (last ? ': ' + last : '')); }
class Cdp {
    constructor(url) { this.id = 0; this.pending = new Map(); this.handlers = new Map(); this.socket = new WebSocket(url); this.ready = new Promise((yes, no) => { this.socket.addEventListener('open', yes); this.socket.addEventListener('error', no); }); this.socket.addEventListener('message', ({ data }) => { const e = JSON.parse(data); if (e.id) { const p = this.pending.get(e.id); this.pending.delete(e.id); e.error ? p?.reject(new Error(e.error.message)) : p?.resolve(e.result); } else for (const fn of this.handlers.get(e.method) || []) fn(e.params); }); }
    on(name, fn) { this.handlers.set(name, [...(this.handlers.get(name) || []), fn]); }
    async send(method, params = {}) { await this.ready; const id = ++this.id; return new Promise((yes, no) => { const timer = setTimeout(() => { this.pending.delete(id); no(new Error('CDP timeout: ' + method)); }, 60000); this.pending.set(id, { resolve: (r) => { clearTimeout(timer); yes(r); }, reject: (e) => { clearTimeout(timer); no(e); } }); this.socket.send(JSON.stringify({ id, method, params })); }); }
    async evaluate(expression) { const r = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }); if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text); return r.result.value; }
}
const reserve = createServer(); await new Promise((yes) => reserve.listen(0, '127.0.0.1', yes)); const port = reserve.address().port; await new Promise((yes) => reserve.close(yes));
const profile = await mkdtemp(path.join(tmpdir(), 'accountability-hosted-part6-'));
const chrome = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore', windowsHide: true });
let control, client, contextId;
try {
    await mkdir(folder, { recursive: true });
    await wait(async () => (await fetch(`http://127.0.0.1:${port}/json/version`)).ok, 'Chrome');
    control = new Cdp((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    async function configure() {
        await client.send('Page.enable'); await client.send('Runtime.enable'); await client.send('Network.enable'); await client.send('Network.setCacheDisabled', { cacheDisabled: true });
        await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => report.errors.push(exceptionDetails.exception?.description || exceptionDetails.text));
        client.on('Network.responseReceived', ({ response }) => { if (response.url.startsWith(origin) && response.status >= 500) report.serverErrors.push({ path: new URL(response.url).pathname, status: response.status }); });
        await client.send('Fetch.enable', { patterns: [{ urlPattern: origin + '/*', requestStage: 'Request' }] });
        client.on('Fetch.requestPaused', ({ requestId, request }) => {
            const route = new URL(request.url).pathname;
            const blocked = !['GET', 'HEAD', 'OPTIONS'].includes(request.method) && !['/login', '/hcdn-cgi/jschallenge-validate'].includes(route);
            if (blocked) report.blockedWrites.push(request.method + ' ' + route);
            void client.send(blocked ? 'Fetch.failRequest' : 'Fetch.continueRequest', blocked ? { requestId, errorReason: 'BlockedByClient' } : { requestId }).catch(() => {});
        });
    }
    const navigate = async (url, predicate) => { await client.send('Page.navigate', { url: origin + url }); await wait(() => client.evaluate(predicate), url); };
    async function login(name) {
        client?.socket.close(); if (contextId) await control.send('Target.disposeBrowserContext', { browserContextId: contextId });
        contextId = (await control.send('Target.createBrowserContext')).browserContextId;
        const target = await control.send('Target.createTarget', { url: 'about:blank', browserContextId: contextId }); client = new Cdp(`ws://127.0.0.1:${port}/devtools/page/${target.targetId}`); await configure();
        await navigate('/login', "!!document.querySelector('#login') && document.readyState==='complete'");
        await client.evaluate(`(()=>{for(const [selector,value] of [['#login',${JSON.stringify(name)}],['#password',${JSON.stringify(password)}]]){const e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event('input',{bubbles:true}));}document.querySelector('form').requestSubmit()})()`);
        await wait(() => client.evaluate("location.pathname!='/login' && document.readyState==='complete' && !!window.axios"), 'login ' + name); report.roles.push(name); console.log('SIGNED_IN ' + name);
    }
    const get = (url) => client.evaluate(`(async()=>{const r=await window.axios.get(${JSON.stringify(url)},{validateStatus:()=>true,portalToast:false});return {status:r.status,body:r.data}})()`);
    const click = (label, scope = 'document') => client.evaluate(`(()=>{const b=[...${scope}.querySelectorAll('button')].find(e=>e.innerText.trim()===${JSON.stringify(label)});if(!b)throw new Error('Missing button: '+${JSON.stringify(label)});b.click()})()`);
    const shot = async (name) => { await delay(180); const { data } = await client.send('Page.captureScreenshot', { format: 'png' }); const file = path.join(folder, name + '.png'); await writeFile(file, Buffer.from(data, 'base64')); report.screenshots.push(file); };
    async function audit(selector) { assert.deepEqual(await client.evaluate(`(()=>{const d=document.querySelector(${JSON.stringify(selector)}),r=d.getBoundingClientRect();return {fits:r.top>=0&&r.bottom<=innerHeight+1,footer:d.querySelector('footer').getBoundingClientRect().bottom<=innerHeight+1,overflow:d.scrollWidth>d.clientWidth+2||document.documentElement.scrollWidth>innerWidth+2,focus:d.contains(document.activeElement),locked:document.documentElement.style.overflowY==='hidden'}})()`), { fits: true, footer: true, overflow: false, focus: true, locked: true }); }
    const response = await fetch(origin + '/login'); assert.equal(response.status, 200); assert.match(response.headers.get('cache-control'), /no-store/); assert.equal(response.headers.get('x-content-type-options'), 'nosniff'); assert.ok(response.headers.get('strict-transport-security')); report.securityHeaders = true;
    await login('learner.support.decision');
    const list = await get('/dashboard/applications/data'); assert.equal(list.status, 200);
    const award = list.body.applications.find((row) => row.recipient_agreement?.status === 'accepted'); assert.ok(award); const id = award.id; report.verified_application_ids.push(id);
    const applicantHistory = await get(`/dashboard/applications/${id}/agreement-concerns`); assert.equal(applicantHistory.status, 200); assert.equal(applicantHistory.body.cases.total, 0); assert.equal(applicantHistory.body.create_url, null);
    const reviewHistory = await get(`/dashboard/applications/${id}/case-reviews`); assert.equal(reviewHistory.status, 200); assert.equal(reviewHistory.body.cases.total, 0);
    await navigate(`/dashboard/applications/${id}/outcome`, "!!document.querySelector('#recipient-agreement')"); await shot('applicant-award-case-review-section');
    await login('marilag.recipients');
    const history = await get(`/provider/applications/${id}/agreement-concerns`); assert.equal(history.status, 200); assert.ok(history.body.options.length > 0); assert.equal(history.body.cases.total, 0); report.concernOptions = history.body.options.length;
    await navigate(`/provider/applications/${id}?section=support`, "!!document.querySelector('[data-concern-panel]') && document.body.innerText.includes('New concern')");
    await client.evaluate("document.querySelector('[data-concern-panel]').scrollIntoView({block:'center'})"); await shot('recipient-officer-concern-section'); await click('New concern'); await wait(() => client.evaluate("!!document.querySelector('[data-concern-dialog]')"), 'live concern dialog'); await audit('[data-concern-dialog]'); await shot('provider-new-concern-form'); await click('Close', "document.querySelector('[data-concern-dialog]')");
    assert.equal((await get(`/provider/applications/${id}/agreement-concerns`)).body.cases.total, 0);
    await navigate('/provider/workspaces/recipients/concerns', "!!document.querySelector('[data-concern-queue]') || document.body.innerText.includes('Agreement concerns')");
    assert.equal((await get('/provider/workspaces/recipients/concerns/data')).status, 200); await shot('recipient-officer-concern-queue');
    const denied = await get(`/provider/applications/${id}/delivery-reports`); assert.equal(denied.status, 403); report.separateRecipientAndReleasePermissions = true;
    await login('learner.release.confirm');
    const releaseList = await get('/dashboard/applications/data'); const releaseAward = releaseList.body.applications.find((row) => row.recipient_agreement?.status === 'accepted'); assert.ok(releaseAward); const releaseId = releaseAward.id; report.verified_application_ids.push(releaseId);
    const deliveryHistory = await get(`/dashboard/applications/${releaseId}/delivery-reports`); assert.equal(deliveryHistory.status, 200); assert.ok(deliveryHistory.body.options.length); assert.equal(deliveryHistory.body.reports.total, 0); report.deliveryOptions = deliveryHistory.body.options.length;
    await navigate(`/dashboard/monitoring/${releaseId}/benefits`, "!!document.querySelector('[data-delivery-panel]') && document.querySelector('[data-delivery-panel]').innerText.includes('Report a benefit issue')");
    await client.evaluate("document.querySelector('[data-delivery-panel]').scrollIntoView({block:'center'})"); await shot('applicant-delivery-reports');
    await click('Report a benefit issue', "document.querySelector('[data-delivery-panel]')"); await wait(() => client.evaluate("!!document.querySelector('[data-delivery-dialog]')"), 'live delivery dialog'); await audit('[data-delivery-dialog]'); await shot('applicant-benefit-report-desktop');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await audit('[data-delivery-dialog]'); await shot('applicant-benefit-report-mobile'); await click('Cancel', "document.querySelector('[data-delivery-dialog]')");
    assert.equal((await get(`/dashboard/applications/${releaseId}/delivery-reports`)).body.reports.total, 0);
    await login('marilag.releases'); await navigate('/provider/workspaces/releases/delivery-reports', "document.querySelector('#delivery-queue-title')?.innerText==='Delivery reports'"); assert.equal((await get('/provider/workspaces/releases/delivery-reports/data')).status, 200); await shot('release-officer-delivery-queue');
    assert.equal((await get(`/provider/applications/${id}/agreement-concerns`)).status, 403);
    await login('marilag.manager'); assert.equal((await get(`/provider/applications/${id}/agreement-concerns`)).status, 200); assert.equal((await get(`/provider/applications/${releaseId}/delivery-reports`)).status, 200);
    await login('silayan.recipients'); assert.equal((await get(`/provider/applications/${id}/agreement-concerns`)).status, 403); assert.equal((await get(`/provider/applications/${id}/case-reviews`)).status, 403); report.crossOrganizationDenied = true;
    await login('platform.reviews'); await navigate('/admin/workspaces/reviews/recipient-cases', "!!document.querySelector('[data-case-review-panel]') && document.body.innerText.includes('No matching case reviews')"); assert.equal((await get('/admin/workspaces/reviews/recipient-cases/data')).status, 200); await shot('admin-case-review-queue');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 720, deviceScaleFactor: 1, mobile: false }); assert.equal(await client.evaluate('document.documentElement.scrollWidth<=innerWidth+2'), true);
    await login('platform.manager'); assert.equal((await get('/admin/workspaces/reviews/recipient-cases/data')).status, 200); report.independentSecondReviewerAvailable = true;
    assert.deepEqual(report.errors, []); assert.deepEqual(report.serverErrors, []); assert.deepEqual(report.blockedWrites, []); report.passed = true;
    console.log('PASS Hosted accountability screens, unsaved forms, permissions, demo handoff, and mobile layout. No business actions submitted.');
} catch (error) { report.failure = error.message; report.screen = await client?.evaluate("({path:location.pathname,text:document.body.innerText.slice(-2200)})").catch(() => null); throw error; }
finally { await writeFile('storage/deployment/accountability-part6-hosted-browser-20261103.json', JSON.stringify(report, null, 2)); client?.socket.close(); control?.socket.close(); chrome.kill(); await delay(600); const target = path.resolve(profile); assert.ok(target.startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(target).startsWith('accountability-hosted-part6-')); await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 250 }); }
