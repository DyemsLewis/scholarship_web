import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, reactive, ref, watch } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { adminServiceDateInput, adminServiceTargetDateError, formatAdminServiceDateTime } from '../../resources/js/composables/useAdminServiceWorkspace.js';
import { normalizeWorkspaceDashboard } from '../../resources/js/support/workspaceDashboard.js';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const page = (name) => read(`pages/admin/billing/${name}.vue`);
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const purchase = { id: 3, status: 'paid', fulfillment_status: 'in_progress', priority: 'normal', cancellation_status: null, target_due_at: null,
    milestones: [{ id: 'review', label: 'Review the record', completed: false }], fulfillment_notes: 'Existing progress', updates: [], files: [] };
function workspace(section = 'delivery') {
    const gets = [], writes = [], mounted = [];
    const window = { location: { pathname: '/admin/workspaces/billing/requests/3', search: '' }, axios: {
        get: async (...args) => { gets.push(args); return { data: { purchase: structuredClone(purchase), assignees: [{ id: 2, name: 'Billing officer' }], status_options: [{ value: 'in_progress', label: 'In progress' }] } }; },
        patch: async (...args) => { writes.push(args); return { data: { purchase: structuredClone(purchase), message: 'Saved.' } }; },
        post: async (...args) => { writes.push(args); return { data: { purchase: structuredClone(purchase), message: 'Saved.' } }; },
    } };
    const source = read('composables/useAdminServiceWorkspace.js').replace(/^import .*;\r?\n/gm, '').replace(/^export /gm, '');
    const deps = { computed, ref, useAdminResource, onMounted: (callback) => mounted.push(callback), window, document: { getElementById: () => ({ dataset: { purchaseId: '3' } }) } };
    const service = new Function(...Object.keys(deps), `${source}\nreturn useAdminServiceWorkspace;`)(...Object.values(deps))(section);
    return { service, window, gets, writes, mounted };
}
function harness(name, exposed, context = workspace()) {
    const source = parse(page(name)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const deps = { computed, reactive, ref, watch: (source, callback) => watch(source, callback, { flush: 'sync' }),
        useAdminServiceWorkspace: () => context.service, adminServiceDateInput, adminServiceTargetDateError,
        window: context.window, URL, FormData };
    return { ...new Function(...Object.keys(deps), `${source}\nreturn { ${exposed} };`)(...Object.values(deps)), ...context };
}

for (const name of ['BillingOverviewPage', 'BillingRequestDirectoryPage', 'AdminServiceOverviewPage', 'AdminServiceDeliveryPage', 'AdminServiceFilesPage', 'AdminServiceUpdatesPage', 'AdminServiceMeetingPage', 'AdminServiceCloseoutPage', 'AdminServiceHistoryPage', 'components/AdminServiceWorkspaceShell']) {
    test(`${name} compiles with billing refinements`, () => {
        const { descriptor, errors } = parse(page(name), { filename: name });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(compileTemplate({ id: name, filename: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('target dates preserve a saved overdue day but reject other past or invalid days', () => {
    const saved = '2026-10-20T09:30:00Z', today = '2026-10-31';
    assert.equal(adminServiceTargetDateError('2026-10-20', saved, today), '');
    for (const value of ['2026-10-19', '2026-10-25']) assert.match(adminServiceTargetDateError(value, saved, today), /saved overdue date/);
    for (const value of ['nonsense', '2026-02-30', '2026-13-01']) assert.match(adminServiceTargetDateError(value, saved, today), /valid target date/);
    for (const value of ['', today, '2026-11-05']) assert.equal(adminServiceTargetDateError(value, saved, today), '');
});

test('billing dates use Philippine time even when the browser host is elsewhere and invalid data does not crash', () => {
    assert.equal(adminServiceDateInput('2026-10-30T18:00:00Z'), '2026-10-31');
    assert.equal(adminServiceDateInput('2026-10-31'), '2026-10-31');
    assert.equal(adminServiceDateInput('invalid'), '');
    assert.equal(formatAdminServiceDateTime('invalid'), 'Not set');
    assert.match(formatAdminServiceDateTime('2026-10-30T18:00:00Z'), /Oct 31, 2026/);
});

test('unloaded or failed service requests cannot mutate and retry restores section-specific data', async () => {
    const { service, window, gets } = workspace('meeting'); let actions = 0;
    await service.runAction(() => { actions++; }); assert.equal(actions, 0);
    const get = window.axios.get; window.axios.get = async () => { throw new Error('Offline'); };
    await service.loadRecord(); assert.equal(service.loadError.value, 'This service request could not be loaded.');
    assert.equal(service.purchase.value, null); await service.runAction(() => { actions++; }); assert.equal(actions, 0);
    window.axios.get = get; await service.loadRecord();
    assert.equal(service.loadError.value, ''); assert.equal(service.ready.value, true);
    assert.equal(gets[0][1].params.section, 'meeting'); assert.ok(gets[0][1].signal instanceof AbortSignal);
    assert.equal(service.assignees.value.length, 1);
});

test('service loader ignores a delayed response that belongs to an earlier retry', async () => {
    const { service, window } = workspace(); const old = deferred(), latest = deferred(); let count = 0;
    window.axios.get = () => ++count === 1 ? old.promise : latest.promise;
    const first = service.loadRecord(), second = service.loadRecord();
    latest.resolve({ data: { purchase: { ...purchase, plan_name: 'Latest' } } }); await second;
    old.resolve({ data: { purchase: { ...purchase, plan_name: 'Old' } } }); await first;
    assert.equal(service.purchase.value.plan_name, 'Latest');
});

test('mutations are single-flight, keep lookup data, and do not reload after success', async () => {
    const { service, window, gets } = workspace(); await service.loadRecord();
    const pending = deferred(); let calls = 0, config;
    const action = (options) => { calls++; config = options; return pending.promise; };
    const request = service.runAction(action); await service.runAction(action); await service.loadRecord();
    assert.equal(calls, 1); assert.equal(gets.length, 1); assert.deepEqual(config, { portalToast: false });
    pending.resolve({ data: { purchase: { ...purchase, fulfillment_notes: 'Saved progress', delivery_status_options: [{ value: 'provider_review', label: 'Provider review' }] } } }); await request;
    assert.equal(service.purchase.value.fulfillment_notes, 'Saved progress'); assert.equal(service.assignees.value.length, 1);
    assert.equal(service.statusOptions.value.length, 1); assert.equal(service.isSaving.value, false);
    assert.equal(service.statusOptions.value[0].value, 'provider_review');
});

test('action errors stay distinct from load errors without losing the loaded service', async () => {
    const { service } = workspace(); await service.loadRecord();
    await service.runAction(async () => { throw { response: { data: { message: 'Generic error', errors: { target_due_at: ['Choose another date.'] } } } }; });
    assert.equal(service.errorMessage.value, 'Choose another date.'); assert.equal(service.loadError.value, '');
    assert.equal(service.purchase.value.id, 3); assert.equal(service.isSaving.value, false);
});

test('delivery keeps overdue values editable, blocks new past dates, and retains drafts after failure', async () => {
    const state = harness('AdminServiceDeliveryPage', 'workflow, minimumTargetDate, saveDelivery, savedTargetOverdue');
    await state.service.loadRecord(); state.service.purchase.value = { ...purchase, target_due_at: '2020-10-20T09:30:00Z' };
    assert.equal(state.minimumTargetDate.value, '2020-10-20'); assert.equal(state.savedTargetOverdue.value, true);
    state.workflow.value.target_due_at = '2020-10-25'; await state.saveDelivery(); assert.equal(state.writes.length, 0);
    assert.match(state.service.errorMessage.value, /saved overdue date/);
    state.workflow.value.target_due_at = '2020-10-20'; state.workflow.value.internal_note = 'Unsaved internal draft';
    state.window.axios.patch = async () => { throw { response: { data: { message: 'Save unavailable' } } }; }; await state.saveDelivery();
    assert.equal(state.workflow.value.internal_note, 'Unsaved internal draft'); assert.equal(state.workflow.value.target_due_at, '2020-10-20');
});

test('closed, unpaid or cancellation-pending requests cannot change delivery or upload deliverables', async () => {
    const state = harness('AdminServiceDeliveryPage', 'saveDelivery'); await state.service.loadRecord();
    for (const extra of [{ fulfillment_status: 'cancelled' }, { fulfillment_status: 'completed' }, { status: 'pending' }, { cancellation_status: 'requested' }]) {
        state.service.purchase.value = { ...purchase, ...extra }; assert.equal(state.service.canEdit.value, false); await state.saveDelivery();
    }
    assert.equal(state.writes.length, 0);
});

test('oversize deliverables are rejected before upload and cancelled services cannot upload', async () => {
    const state = harness('AdminServiceFilesPage', 'uploadDeliverable'); await state.service.loadRecord();
    const input = { files: [{ size: 11 * 1024 * 1024 }], value: 'selected' }; await state.uploadDeliverable({ target: input });
    assert.equal(state.writes.length, 0); assert.equal(input.value, ''); assert.match(state.service.errorMessage.value, /10 MB/);
    state.service.purchase.value = { ...purchase, fulfillment_status: 'cancelled' }; await state.uploadDeliverable({ target: input });
    assert.equal(state.writes.length, 0);
});

test('updates validate length, clearly choose visibility, and retain messages after failed saves', async () => {
    const state = harness('AdminServiceUpdatesPage', 'updateForm, addUpdate, canRequestInformation'); await state.service.loadRecord();
    state.updateForm.value.message = 'ab'; await state.addUpdate(); assert.equal(state.writes.length, 0);
    state.updateForm.value = { kind: 'internal_note', message: 'Internal coordination draft' };
    state.window.axios.post = async (...args) => { state.writes.push(args); throw new Error('Offline'); }; await state.addUpdate();
    assert.equal(state.writes[0][1].kind, 'internal_note'); assert.equal(state.updateForm.value.message, 'Internal coordination draft');
    state.service.purchase.value = { ...purchase, fulfillment_status: 'completed' };
    assert.equal(state.canRequestInformation.value, false); state.updateForm.value.kind = 'clarification_request'; await state.addUpdate(); assert.equal(state.writes.length, 1);
});

test('meeting confirmation validates HTTP links while decline does not require an online link', async () => {
    const state = harness('AdminServiceMeetingPage', 'decideMeeting, joinUrl, responseNote'); await state.service.loadRecord();
    state.service.purchase.value = { ...purchase, meeting_status: 'requested', meeting_mode: 'online', meeting_scheduled_for: '2027-01-05T01:00:00Z' };
    for (const link of ['', 'not-a-url', 'javascript:alert(1)']) { state.joinUrl.value = link; await state.decideMeeting('confirmed'); }
    assert.equal(state.writes.length, 0);
    state.responseNote.value = 'Please propose another time.'; await state.decideMeeting('declined');
    assert.equal(state.writes[0][1].meeting_join_url, null); assert.equal(state.writes[0][1].meeting_status, 'declined');
    state.service.purchase.value = { ...purchase, meeting_status: 'confirmed' }; await state.decideMeeting('confirmed'); assert.equal(state.writes.length, 1);
});

test('cancellation refund reference is required only for approval and restoration honors refund locks', async () => {
    const state = harness('AdminServiceCloseoutPage', 'decision, decide, restore, restoreReason, canRestore'); await state.service.loadRecord();
    state.service.purchase.value = { ...purchase, cancellation_status: 'requested' };
    state.decision.decision_note = 'Valid cancellation explanation'; state.decision.refund_status = 'refunded';
    await state.decide('approved'); assert.equal(state.writes.length, 0);
    await state.decide('declined'); assert.equal(state.writes[0][1].refund_status, null);
    for (const refund of ['manual_refund_pending', 'refunded']) {
        state.service.purchase.value = { ...purchase, fulfillment_status: 'cancelled', refund_status: refund };
        state.restoreReason.value = 'A legitimate restoration explanation'; await state.restore(); assert.equal(state.canRestore.value, false);
    }
    assert.equal(state.writes.length, 1);
});

test('all record subpages provide retry, and shell errors focus without replacing editable drafts', () => {
    for (const name of ['AdminServiceOverviewPage', 'AdminServiceDeliveryPage', 'AdminServiceFilesPage', 'AdminServiceUpdatesPage', 'AdminServiceMeetingPage', 'AdminServiceCloseoutPage', 'AdminServiceHistoryPage']) {
        assert.match(page(name), /:load-error="loadError"/); assert.match(page(name), /@retry="loadRecord"/);
    }
    const shell = page('components/AdminServiceWorkspaceShell');
    for (const value of ['recordReady', 'AdminLoadState', 'actionError.value?.focus', 'scrollIntoView', 'role="alert"']) assert.ok(shell.includes(value), value);
    assert.match(page('AdminServiceUpdatesPage'), /Internal: visible only to administrators/);
    assert.match(page('AdminServiceFilesPage'), /data-file-preview="true"/);
    assert.doesNotMatch(page('AdminServiceFilesPage'), /FilePreviewModal|truncate/);
    assert.match(page('BillingRequestDirectoryPage'), /resource.invalidate\(\)/);
    assert.match(page('BillingRequestDirectoryPage'), /onBeforeUnmount/);
    assert.doesNotMatch(page('BillingRequestDirectoryPage'), /truncate/);
});

test('billing queue latest-request and retry states preserve the current queue and export remains explicit', () => {
    const source = page('BillingRequestDirectoryPage');
    for (const value of ["{ flush: 'sync' }", ':loading="loading"', ':error="errorMessage"', '@retry="loadItems(1)"', 'table-fixed min-w-[56rem]', 'No matching requests', ':href="exportUrl"']) assert.ok(source.includes(value), value);
    assert.doesNotMatch(source, /window\.open|\.axios\.(post|patch|delete)/);
    assert.match(page('BillingOverviewPage'), /@retry="loadDashboard"/);
});
