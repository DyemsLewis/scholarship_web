import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
const origin = process.env.CASE_REVIEW_SMOKE_URL || 'http://127.0.0.1:8154';
if (!['localhost', '127.0.0.1'].includes(new URL(origin).hostname)) throw new Error('Local-only mocked review checks.');
const password = process.env.CASE_REVIEW_SMOKE_PASSWORD; if (!password) throw new Error('Set the local demo password.');
const folder = process.env.CASE_REVIEW_SMOKE_SCREENSHOTS;
const testPrivacy = process.env.CASE_REVIEW_SMOKE_PRIVACY === '1';
const report = { errors: [], unexpectedWrites: [], mockedWrites: 0, screenshots: [], checks: [] };
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function wait(check, label) { const until = Date.now() + 45000; let last; while (Date.now() < until) { try { if (await check()) return; } catch (e) { last = e.message; } await delay(100); } throw new Error('Timed out: ' + label + (last ? ': ' + last : '')); }
class Cdp {
    constructor(url) {
        this.id = 0; this.pending = new Map(); this.handlers = new Map(); this.socket = new WebSocket(url);
        this.ready = new Promise((yes, no) => { this.socket.addEventListener('open', yes); this.socket.addEventListener('error', no); });
        this.socket.addEventListener('message', ({ data }) => { const e = JSON.parse(data); if (e.id) { const p = this.pending.get(e.id); this.pending.delete(e.id); e.error ? p?.reject(new Error(e.error.message)) : p?.resolve(e.result); } else for (const fn of this.handlers.get(e.method) || []) fn(e.params); });
    }
    on(name, fn) { this.handlers.set(name, [...(this.handlers.get(name) || []), fn]); }
    async send(method, params = {}) { await this.ready; const id = ++this.id; return new Promise((yes, no) => { const timer = setTimeout(() => { this.pending.delete(id); no(new Error('CDP timeout: ' + method)); }, 45000); this.pending.set(id, { resolve: (value) => { clearTimeout(timer); yes(value); }, reject: (error) => { clearTimeout(timer); no(error); } }); this.socket.send(JSON.stringify({ id, method, params })); }); }
    async evaluate(expression) { const r = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }); if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text); return r.result.value; }
}
const listener = createServer(); await new Promise((yes) => listener.listen(0, '127.0.0.1', yes)); const port = listener.address().port; await new Promise((yes) => listener.close(yes));
const profile = await mkdtemp(path.join(tmpdir(), 'case-review-smoke-'));
const browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank'], { stdio: 'ignore', windowsHide: true });
let control, client, contextId, applicationId, applicantId, programTitle, acting = 'provider', resultMode = 'success';
const fixture = { id: 901, revision: 7, review_state: null, reviews: [], statements: [{ id: 1, label: 'Applicant explanation', author_name: 'Sabina Ocampo', body: 'The school is preparing my enrollment record. Please consider my explanation.', created_at: '2026-11-03T01:00:00Z', evidence: [] }], history: [] };
const page = (data) => ({ data, current_page: 1, last_page: 1, total: data.length });
let privacyAssessment = null;
function privacyPayload() {
    const admin = acting === 'admin';
    return { sharing_enabled: false, sharing_label: 'Not shared with other providers', shared_fields: [], revision: fixture.revision,
        assessment_state_label: privacyAssessment ? (privacyAssessment.case_revision === fixture.revision ? 'Internal assessment recorded' : 'Case changed; assessment superseded') : 'No internal assessment', blockers: ['External sharing has not been activated after an independent privacy/policy review.', 'The recorded appeal opportunity remains open.'],
        audit_retention_days: 90, audit_retention_hold: privacyAssessment?.audit_retention_hold || false, can_record: admin,
        record_url: admin ? '/admin/recipient-cases/901/privacy' : null,
        notice: 'An internal finding does not grant external access. No blacklist, automatic rejection, public flag, or case-document export is created.',
        assessments: page(privacyAssessment ? [{ id: 1, label: 'Do not disclose', audience: 'Originating award only', created_at: '2026-11-03T03:00:00Z', recheck_on: '2026-12-03', audit_retention_hold: privacyAssessment.audit_retention_hold, ...(admin ? { purpose: privacyAssessment.purpose, rationale: privacyAssessment.rationale, recorder_name: 'Platform Review Officer' } : {}) }] : []),
    };
}
function payload() {
    const prefix = acting === 'applicant' ? 'dashboard' : acting, admin = acting === 'admin', pending = ['requested', 'awaiting_information', 'appeal_pending'].includes(fixture.review_state);
    return { ...fixture, case_type: 'agreement_concern', type_label: 'Agreement concern', subject: 'Enrollment record clarification', details: 'The provider asked for clarification of the enrollment evidence for this semester.', source_label: 'Accepted enrollment responsibility', source_text: 'Submit the enrollment record each semester.', accepted_consequences: 'Support may end only after notice, response, and review.',
        program: { title: programTitle }, applicant: { name: 'Sabina Ocampo', photo_url: admin ? `/admin/applicants/${applicantId}/profile-photo` : `/provider/applications/${applicationId}/profile-photo` },
        review_state_label: { requested: 'Awaiting admin review', reviewed: 'Review outcome recorded', appeal_pending: 'Appeal awaiting independent review' }[fixture.review_state] || 'Not escalated', finding_label: pending ? 'Not final / under review' : 'Concern supported by evidence',
        can_request: !admin && !fixture.review_state, can_statement: !admin && pending, can_appeal: !admin && fixture.review_state === 'reviewed', can_review: admin && pending && fixture.review_state !== 'appeal_pending', independent_reviewer_required: admin && fixture.review_state === 'appeal_pending',
        read_url: `/${prefix}/recipient-cases/901`, actions: Object.fromEntries((admin ? ['information', 'outcome'] : ['request', 'statement', 'appeal']).map((key) => [key, `/${prefix}/recipient-cases/901/${key}`])),
        ...(testPrivacy ? { privacy_url: `/${prefix}/recipient-cases/901/privacy` } : {}),
        evidence: [{ id: 1, name: 'enrollment-record.pdf', mime_type: 'application/pdf', size: 800, view_url: `/${prefix}/recipient-case-evidence/901` }],
        response_received_at: '2026-11-03T01:00:00Z', response_due_at: '2026-11-10T01:00:00Z', escalated_at: fixture.review_state ? '2026-11-03T02:00:00Z' : null,
    };
}
try {
    if (folder) await mkdir(folder, { recursive: true });
    await wait(async () => (await fetch(`http://127.0.0.1:${port}/json/version`)).ok, 'Chrome'); control = new Cdp((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    async function configure() {
        await client.send('Page.enable'); await client.send('Runtime.enable'); await client.send('Network.enable'); await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        client.on('Runtime.exceptionThrown', ({ exceptionDetails }) => report.errors.push(exceptionDetails.exception?.description || exceptionDetails.text));
        await client.send('Fetch.enable', { patterns: [{ urlPattern: origin + '/*', requestStage: 'Request' }] });
        const fulfill = (id, data, code = 200) => client.send('Fetch.fulfillRequest', { requestId: id, responseCode: code, responseHeaders: [{ name: 'content-type', value: 'application/json' }], body: Buffer.from(JSON.stringify(data)).toString('base64') });
        client.on('Fetch.requestPaused', ({ requestId, request }) => { void (async () => {
            const url = new URL(request.url).pathname;
            if (testPrivacy && url.endsWith('/recipient-cases/901/privacy')) {
                if (request.method === 'POST') {
                    report.mockedWrites++;
                    if (acting !== 'admin') throw new Error('A non-admin privacy write was attempted.');
                    if (resultMode === 'conflict') return fulfill(requestId, { message: 'This case changed.' }, 409);
                    privacyAssessment = JSON.parse(request.postData); fixture.revision++; privacyAssessment.case_revision = fixture.revision;
                    return fulfill(requestId, { privacy: privacyPayload(), message: 'Internal privacy assessment saved. Nothing was shared with another provider.' });
                }
                return fulfill(requestId, { privacy: privacyPayload(), access: page([{ id: 1, at: '2026-11-03T03:00:00Z', actor_label: 'Platform review team', ...(acting === 'admin' ? { actor_name: 'Platform Review Officer' } : {}), resource_label: 'Case record', outcome_label: 'Authorized access' }]) });
            }
            if (url.includes('/agreement-concerns') && request.method === 'GET') {
                const concern = { ...payload(), status: 'under_review', can_edit: false, can_respond: false, obligation: { key: 'recipient_expectation.responsibilities', label: 'Enrollment responsibility', text: 'Submit enrollment records.' }, agreement: { version: 'browser-only-v1' }, responses: [], evidence: [], read_url: `/${acting === 'applicant' ? 'dashboard' : 'provider'}/agreement-concerns/901` };
                return fulfill(requestId, url.endsWith('/901') ? { case: concern } : { cases: page([concern]), options: [], create_url: null });
            }
            if (url.includes('/case-reviews') || (url.includes('/recipient-cases') && url !== '/admin/workspaces/reviews/recipient-cases')) {
                if (request.method === 'GET') return fulfill(requestId, url.endsWith('/901') ? { case: payload() } : { cases: page(fixture.review_state ? [payload()] : []) });
                report.mockedWrites++;
                if (resultMode === 'conflict') return fulfill(requestId, { message: 'This case changed.' }, 409);
                if (resultMode === 'validation') return fulfill(requestId, { errors: { details: ['Provide the relevant reason for this appeal.'] } }, 422);
                fixture.revision++;
                if (url.endsWith('/request')) fixture.review_state = 'requested';
                if (url.endsWith('/outcome')) { fixture.review_state = 'reviewed'; fixture.reviews.unshift({ id: 1, finding_label: 'Concern supported by evidence', recommendation_label: 'Provider may consider a separate support decision', reviewer_name: 'Platform Review Officer', rationale: 'The accepted enrollment duty, source evidence, and applicant explanation were reviewed together. A separate provider decision may be considered only after the appeal opportunity.', created_at: '2026-11-03T03:00:00Z', appeal_due_at: '2026-11-17T03:00:00Z', current: true, under_appeal: false }); }
                if (url.endsWith('/appeal')) { fixture.review_state = 'appeal_pending'; fixture.reviews[0].under_appeal = true; }
                return fulfill(requestId, { case: payload(), message: 'Browser-only case update; no real review or support change was saved.' });
            }
            if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method) && url !== '/login') { report.unexpectedWrites.push(request.method + ' ' + url); return client.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }); }
            return client.send('Fetch.continueRequest', { requestId });
        })().catch((error) => { report.errors.push(error.message); void client.send('Fetch.failRequest', { requestId, errorReason: 'Failed' }).catch(() => {}); }); });
    }
    async function navigate(url, predicate) { await client.send('Page.navigate', { url: origin + url }); await wait(() => client.evaluate(predicate), url); }
    async function login(name) {
        client?.socket.close(); if (contextId) await control.send('Target.disposeBrowserContext', { browserContextId: contextId }); contextId = (await control.send('Target.createBrowserContext')).browserContextId;
        const target = await control.send('Target.createTarget', { url: 'about:blank', browserContextId: contextId }); client = new Cdp(`ws://127.0.0.1:${port}/devtools/page/${target.targetId}`); await configure();
        await navigate('/login', "!!document.querySelector('#login') && document.readyState==='complete'");
        await client.evaluate(`(()=>{for(const [selector,value] of [['#login',${JSON.stringify(name)}],['#password',${JSON.stringify(password)}]]){const e=document.querySelector(selector);e.value=value;e.dispatchEvent(new Event('input',{bubbles:true}));}document.querySelector('form').requestSubmit();})()`);
        await wait(() => client.evaluate("location.pathname!='/login' && !!window.axios && document.readyState==='complete'"), 'login ' + name); console.log('SIGNED_IN ' + name);
    }
    const click = (text) => client.evaluate(`(()=>{const root=document.querySelector('[data-case-review-dialog]') || document.querySelector('[data-concern-dialog]') || document.querySelector('[data-case-review-panel]') || document;const b=[...root.querySelectorAll('button')].find(x=>x.innerText.trim()===${JSON.stringify(text)});if(!b)throw new Error('Button missing: '+${JSON.stringify(text)});b.click();})()`);
    const fill = (selector, value, event = 'input') => client.evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.value=${JSON.stringify(value)};e.dispatchEvent(new Event(${JSON.stringify(event)},{bubbles:true}));})()`);
    const checkAll = () => client.evaluate("document.querySelectorAll('[data-case-review-dialog] form input[type=checkbox]').forEach(e=>{if(!e.checked)e.click()})");
    async function audit() { assert.deepEqual(await client.evaluate("(()=>{const d=document.querySelector('[data-case-review-dialog]'),r=d.getBoundingClientRect(),f=d.querySelector('footer').getBoundingClientRect();return {fits:r.top>=0&&r.bottom<=innerHeight+1,footer:f.bottom<=innerHeight+1,overflow:d.scrollWidth>d.clientWidth+2||document.documentElement.scrollWidth>innerWidth+2,focus:d.contains(document.activeElement),locked:document.documentElement.style.overflowY==='hidden'};})()"), { fits: true, footer: true, overflow: false, focus: true, locked: true }); }
    async function shot(name) { if (!folder) return; await delay(160); const { data } = await client.send('Page.captureScreenshot', { format: 'png' }); const output = path.join(folder, name + '.png'); await writeFile(output, Buffer.from(data, 'base64')); report.screenshots.push(output); }
    const waitDialog = () => wait(() => client.evaluate("!!document.querySelector('[data-case-review-dialog]')"), 'review dialog');
    acting = 'applicant'; await login('learner.support.decision');
    const award = await client.evaluate("(async()=>{const row=(await window.axios.get('/dashboard/applications/data')).data.applications.find(x=>x.recipient_agreement?.status==='accepted');return {id:row.id,title:row.scholarship.title,userId:window.portalUser.id};})()");
    applicationId = award.id; applicantId = award.userId; programTitle = award.title;
    acting = 'provider'; await login('marilag.recipients'); await navigate(`/provider/applications/${applicationId}`, "!!document.querySelector('[data-concern-panel]') && document.body.innerText.includes('View concern')");
    await client.evaluate("document.querySelector('[data-concern-panel]').scrollIntoView({block:'center'});[...document.querySelectorAll('[data-concern-panel] button')].find(e=>e.innerText.trim()==='View concern').click()");
    await wait(() => client.evaluate("!!document.querySelector('[data-concern-dialog]')"), 'original concern'); await click('Case review and appeals'); await waitDialog(); await audit();
    if (testPrivacy) {
        await client.evaluate("document.querySelector('[data-case-privacy]').open=true"); await wait(() => client.evaluate("document.querySelector('[data-case-privacy]').innerText.includes('Not shared with other providers')"), 'provider privacy');
        assert.equal(await client.evaluate("!!document.querySelector('[data-privacy-begin]')"), false);
        await shot('provider-privacy-status');
    }
    await click('Request review');
    await fill('[data-case-review-dialog] textarea', 'Please independently review the accepted enrollment responsibility, proof, and applicant explanation.'); await checkAll(); await shot('provider-request-independent-review'); await click('Send statement');
    await wait(() => client.evaluate("document.querySelector('[data-case-review-dialog]').innerText.includes('Awaiting admin review')"), 'review requested'); await click('Close');
    assert.equal(await client.evaluate("!!document.querySelector('[data-concern-dialog]') && document.documentElement.style.overflowY==='hidden'"), true); await click('Close');
    report.checks.push('Provider request opens inside the issued concern; nested dialog close keeps the parent viewport locked.');
    acting = 'admin'; await login('platform.reviews'); await navigate('/admin/workspaces/reviews/recipient-cases', "!!document.querySelector('[data-case-review-panel] tbody button')");
    await wait(() => client.evaluate("document.querySelector('tbody img').naturalWidth>0"), 'private applicant photo'); await shot('admin-case-review-queue'); await client.evaluate("document.querySelector('tbody button').click()"); await waitDialog(); await audit(); await click('Review outcome');
    assert.equal(await client.evaluate("document.querySelector('[data-case-review-dialog] select').value"), '');
    await fill('[data-case-review-dialog] select', 'confirmed', 'change'); await fill('[data-case-review-dialog] label:nth-of-type(2) select', 'provider_decision_review', 'change');
    await fill('[data-case-review-dialog] textarea', 'The accepted enrollment duty, source evidence, and applicant explanation were reviewed together. A separate provider decision may be considered only after the appeal opportunity.'); await checkAll(); resultMode = 'conflict'; await click('Record outcome'); await wait(() => client.evaluate("!!document.querySelector('[data-review-error]')"), 'stale review'); await click('Reload case'); await wait(() => client.evaluate("!document.querySelector('[data-review-error]')"), 'review reloaded');
    assert.equal(await client.evaluate("[...document.querySelectorAll('[data-case-review-dialog] form input[type=checkbox]')].some(e=>e.checked)"), false); await checkAll();
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 720, deviceScaleFactor: 1, mobile: false }); await audit(); await shot('admin-evidence-backed-outcome'); resultMode = 'success'; await click('Record outcome');
    await wait(() => client.evaluate("document.querySelector('[data-case-review-dialog]').innerText.includes('Review outcome recorded')"), 'review outcome'); await audit(); await shot('admin-recorded-review-outcome');
    if (testPrivacy) {
        await client.evaluate("document.querySelector('[data-case-privacy]').open=true"); await wait(() => client.evaluate("!!document.querySelector('[data-privacy-begin]')"), 'admin privacy'); await click('Record internal assessment');
        await fill('[data-privacy-form] select', 'blocked', 'change');
        await fill('[data-privacy-form] textarea', 'Check the necessity, accuracy, and scope of any proposed disclosure.');
        await client.evaluate("(()=>{const e=document.querySelectorAll('[data-privacy-form] textarea')[1];e.value='External sharing is not necessary or authorized. Preserve access metadata while the internal privacy review remains open.';e.dispatchEvent(new Event('input',{bubbles:true}));})()");
        await fill('[data-privacy-form] input[type=date]', '2026-12-03'); await checkAll();
        assert.equal(await client.evaluate("document.querySelector('[data-case-review-dialog] footer fieldset').matches(':disabled')"), true);
        await client.evaluate("document.querySelector('[data-case-review-dialog]').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))"); await wait(() => client.evaluate("document.querySelector('[data-case-review-dialog]').innerText.includes('Discard your unsent statement?')"), 'privacy discard'); await click('Keep editing');
        resultMode = 'conflict'; await click('Save internal assessment'); await wait(() => client.evaluate("document.querySelector('[data-privacy-form]').innerText.includes('Reload privacy status')"), 'privacy stale'); await click('Reload privacy status');
        await wait(() => client.evaluate("!document.querySelector('[data-privacy-form]').innerText.includes('Reload privacy status')"), 'privacy reload');
        assert.match(await client.evaluate("document.querySelector('[data-privacy-form] textarea').value"), /necessity/);
        assert.equal(await client.evaluate("document.querySelectorAll('[data-privacy-form] input[type=checkbox]')[1].checked"), false);
        await checkAll(); await client.evaluate("document.querySelector('[data-privacy-form]').scrollIntoView({block:'start'})"); await audit(); await shot('admin-internal-privacy-assessment'); resultMode = 'success'; await click('Save internal assessment');
        await wait(() => client.evaluate("document.querySelector('[data-case-privacy]').innerText.includes('Nothing was shared')"), 'privacy saved'); await audit(); await shot('admin-privacy-access-history');
        report.checks.push('Privacy: no grant option; administrator-only internal notes; stale reload preserves reasons and resets confirmation; close guard and no automatic award change.');
    }
    await click('Close');
    report.checks.push('Admin: no default finding; cited proof, accepted source, response acknowledgment and reasons; stale reload requires all checks again; 1280x720 containment.');
    acting = 'applicant'; await login('learner.support.decision'); await navigate(`/dashboard/applications/${applicationId}/outcome`, "!!document.querySelector('[data-case-review-panel]') && document.body.innerText.includes('Open review')");
    await client.evaluate("document.querySelector('[data-case-review-panel]').scrollIntoView({block:'center'})"); await click('Open review'); await waitDialog(); await shot('applicant-review-outcome'); await click('Appeal outcome'); await fill('[data-case-review-dialog] textarea', 'The school has now supplied my enrollment evidence. Please reconsider the context and attached records.');
    await client.evaluate("document.querySelector('[data-case-review-dialog]').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))"); await wait(() => client.evaluate("document.querySelector('[data-case-review-dialog]').innerText.includes('Discard your unsent statement?')"), 'discard prompt'); await click('Keep editing');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true }); await audit(); await checkAll(); resultMode = 'validation'; await click('Send statement'); await wait(() => client.evaluate("document.querySelector('[data-review-error]')?.innerText.includes('relevant reason')"), 'appeal validation'); assert.match(await client.evaluate("document.querySelector('[data-case-review-dialog] textarea').value"), /school has now/);
    await shot('applicant-appeal-mobile'); resultMode = 'success'; await click('Send statement'); await wait(() => client.evaluate("document.querySelector('[data-case-review-dialog]').innerText.includes('Appeal awaiting independent review')"), 'appeal pending'); await audit(); await shot('applicant-appeal-recorded-mobile');
    if (testPrivacy) {
        await client.evaluate("document.querySelector('[data-case-privacy]').open=true"); await wait(() => client.evaluate("document.querySelector('[data-case-privacy]').innerText.includes('Not shared with other providers')"), 'applicant privacy');
        assert.equal(await client.evaluate("!!document.querySelector('[data-privacy-begin]')"), false);
        assert.equal(await client.evaluate("document.querySelector('[data-case-privacy]').innerText.includes('assessment superseded')"), true);
        await client.evaluate("document.querySelector('[data-case-privacy]').scrollIntoView({block:'start'})"); await audit(); await shot('applicant-privacy-access-mobile');
    }
    await click('Close');
    report.checks.push('Applicant: original reasoned outcome retained under appeal; mobile focus/containment, discard and validation preserve text; no automatic support reversal.');
    acting = 'admin'; await login('platform.reviews'); await navigate('/admin/workspaces/reviews/recipient-cases', "!!document.querySelector('tbody button')"); await client.evaluate("document.querySelector('tbody button').click()"); await waitDialog(); await audit(); assert.equal(await client.evaluate("[...document.querySelectorAll('[data-case-review-dialog] button')].some(e=>e.innerText.trim()==='Review outcome')"), false); await shot('admin-independent-appeal-review-required');
    report.checks.push('Original admin reviewer has no action to decide their own appeal.');
    assert.deepEqual(report.errors, []); assert.deepEqual(report.unexpectedWrites, []); report.passed = true; console.log('PASS Case review browser checks; every review write was intercepted.');
} catch (error) { report.failure = error.message; report.screen = await client?.evaluate("({path:location.pathname,text:document.body.innerText.slice(-2500)})").catch(() => null); throw error; }
finally { await writeFile(`storage/deployment/${testPrivacy ? 'case-privacy-part5' : 'recipient-case-part4'}-browser-20261103.json`, JSON.stringify(report, null, 2)); client?.socket.close(); control?.socket.close(); browser.kill(); await delay(600); const target = path.resolve(profile); assert.ok(target.startsWith(path.resolve(tmpdir()) + path.sep) && path.basename(target).startsWith('case-review-smoke-')); await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 250 }); }
