import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createStaffQueueState, staffQueueReturn } from '../../resources/js/support/staffQueueState.js';
import { confirmOutcome, afterConfirmation } from './helpers/acceptedOutcome.js';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { normalizeWorkspaceDashboard } from '../../resources/js/support/workspaceDashboard.js';

const read = (name) => readFileSync(new URL(`../../resources/js/pages/admin/support/${name}.vue`, import.meta.url), 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const casePayload = { id: 1, admin_status: 'open', admin_response: null, provider_status: 'open',
    provider_response: null, requires_provider: true, state: 'needs_response', reporter: { name: 'Mira Cruz' } };
function harness(name, exposed, pathname = '/admin/workspaces/support/cases/1', search = '') {
    const script = parse(read(name)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const gets = [], writes = [], watchers = [], unmounts = [], timers = new Map();
    let nextTimer = 0;
    const window = { location: { pathname, search }, axios: {
        get: async (...args) => { gets.push(args); return { data: { report: structuredClone(casePayload), items: [], categories: [] } }; },
        patch: async (...args) => { writes.push(args); return { data: { support_case: { ...casePayload, admin_status: 'resolved', admin_response: 'A saved platform response.', state: 'waiting' }, message: 'Platform response recorded.' } }; },
    } };
    const deps = { createStaffQueueState, staffQueueReturn, confirmOutcome, ref, computed, useAdminResource, normalizeWorkspaceDashboard, window, URLSearchParams,
        onMounted() {}, onBeforeUnmount: (callback) => unmounts.push(callback), watch: (...args) => watchers.push(args),
        setTimeout: (callback) => { timers.set(++nextTimer, callback); return nextTimer; }, clearTimeout: (id) => timers.delete(id) };
    return { ...new Function(...Object.keys(deps), `${script}\nreturn { ${exposed} };`)(...Object.values(deps)), window, gets, writes, watchers, unmounts, timers };
}

for (const name of ['SupportOverviewPage', 'SupportCaseDirectoryPage', 'SupportCaseRecordPage']) {
    test(`${name} compiles with support refinements`, () => {
        const { descriptor, errors } = parse(read(name), { filename: name });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(compileTemplate({ id: name, filename: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('support dashboard has stable role context and retry feedback instead of empty-success claims', async () => {
    const state = harness('SupportOverviewPage', 'loadDashboard, dashboard, errorMessage, loading');
    assert.equal(state.dashboard.value.workspace.title, 'Support dashboard');
    state.window.axios.get = async () => { throw new Error('Offline'); };
    await state.loadDashboard(); assert.equal(state.errorMessage.value, 'The support dashboard could not be loaded.');
    assert.equal(state.loading.value, false);
    state.window.axios.get = async () => ({ data: { workspace: { title: 'Support dashboard' }, metrics: [] } });
    await state.loadDashboard(); assert.equal(state.errorMessage.value, '');
    assert.match(read('SupportOverviewPage'), /#error/);
    assert.match(read('SupportOverviewPage'), /@retry="loadDashboard"/);
});

for (const [slug, queue] of [['needs-response', 'needs_response'], ['waiting', 'waiting'], ['resolved', 'resolved']]) {
    test(`${slug} preserves its own queue, category and paginated search parameters`, async () => {
        const state = harness('SupportCaseDirectoryPage', 'loadCases', `/admin/workspaces/support/${slug}`, '?category=privacy&search=%20record%20');
        await state.loadCases(2);
        assert.deepEqual(state.gets[0][1].params, { page: 2, per_page: 10, queue, category: 'privacy', search: 'record' });
        assert.ok(state.gets[0][1].signal instanceof AbortSignal);
    });
}

test('latest category search wins even when a superseded response ignores abort', async () => {
    const state = harness('SupportCaseDirectoryPage', 'loadCases, category, items, loading', '/admin/workspaces/support/needs-response');
    const old = deferred(), latest = deferred(); let count = 0;
    state.window.axios.get = () => ++count === 1 ? old.promise : latest.promise;
    const first = state.loadCases(); state.category.value = 'privacy'; const second = state.loadCases();
    latest.resolve({ data: { items: [{ subject: 'Privacy case' }] } }); await second;
    old.resolve({ data: { items: [{ subject: 'Old case' }] } }); await first;
    assert.equal(state.items.value[0].subject, 'Privacy case'); assert.equal(state.loading.value, false);
});

test('filter changes immediately invalidate in-flight requests and remove delayed work on unmount', async () => {
    const state = harness('SupportCaseDirectoryPage', 'loadCases, items, loading', '/admin/workspaces/support/needs-response');
    const old = deferred(); state.window.axios.get = () => old.promise;
    const request = state.loadCases(); state.watchers[0][1]();
    assert.deepEqual(state.watchers[0][2], { flush: 'sync' }); assert.equal(state.timers.size, 1);
    old.resolve({ data: { items: [{ subject: 'Stale result' }] } }); await request;
    assert.deepEqual(state.items.value, []); assert.equal(state.loading.value, true);
    state.unmounts[0](); assert.equal(state.timers.size, 0);
});

test('support queue pagination is bounded and cannot run while loading', async () => {
    const state = harness('SupportCaseDirectoryPage', 'loadCases, changePage, resource, loading', '/admin/workspaces/support/needs-response');
    state.resource.data.value = { pagination: { current_page: 2, last_page: 3 } };
    for (const page of [0, 1, 2, 4]) state.changePage(page);
    assert.equal(state.gets.length, 0); state.loading.value = false;
    for (const page of [0, 2, 4]) state.changePage(page);
    assert.equal(state.gets.length, 0); state.changePage(3);
    assert.equal(state.gets[0][1].params.page, 3);
});

test('queue load errors stay distinct from empty records and expose retry', async () => {
    const state = harness('SupportCaseDirectoryPage', 'loadCases, errorMessage, items', '/admin/workspaces/support/needs-response');
    state.window.axios.get = async () => { throw new Error('Offline'); };
    await state.loadCases(); assert.equal(state.errorMessage.value, 'This support queue could not be loaded.');
    const source = read('SupportCaseDirectoryPage');
    for (const value of [':loading="loading"', ':error="errorMessage"', '@retry="resource.retry"']) assert.ok(source.includes(value), value);
});

test('unloaded and failed records cannot resolve or reopen a case; retry recovers', async () => {
    const state = harness('SupportCaseRecordPage', 'loadCase, updateStatus, openReopen, reopenOpen, errorMessage, ready');
    await state.updateStatus('resolved'); await state.updateStatus('open'); state.openReopen();
    assert.equal(state.reopenOpen.value, false); assert.equal(state.writes.length, 0);
    const get = state.window.axios.get;
    state.window.axios.get = async () => { throw new Error('Offline'); }; await state.loadCase();
    assert.equal(state.ready.value, null); assert.equal(state.errorMessage.value, 'This support case could not be loaded.');
    state.window.axios.get = get; await state.loadCase(); assert.equal(Boolean(state.ready.value), true);
});

test('response validation trims input and bounds it without submitting', async () => {
    const state = harness('SupportCaseRecordPage', 'loadCase, updateStatus, responseText, actionError, canSave');
    await state.loadCase();
    for (const value of ['', '          ', 'Too short', 'x'.repeat(2001)]) {
        state.responseText.value = value; assert.equal(state.canSave.value, false); await state.updateStatus('resolved');
        assert.match(state.actionError.value, /10 to 2000/);
    }
    assert.equal(state.writes.length, 0);
    state.responseText.value = '  Please use your current school record.  '; await state.updateStatus('resolved');
    assert.equal(state.writes[0][1].admin_response, 'Please use your current school record.');
    assert.equal(state.writes[0][2].portalToast, false);
});

test('save failures keep the draft, prefer specific feedback and guard duplicate writes and refreshes', async () => {
    const state = harness('SupportCaseRecordPage', 'loadCase, updateStatus, responseText, saving, actionError, report');
    await state.loadCase(); state.responseText.value = 'The school record was checked.';
    const pending = deferred(); state.window.axios.patch = (...args) => { state.writes.push(args); return pending.promise; };
    const request = state.updateStatus('resolved'); await afterConfirmation(); await state.updateStatus('resolved'); await state.loadCase();
    assert.equal(state.writes.length, 1); assert.equal(state.gets.length, 1); assert.equal(state.saving.value, true);
    pending.reject({ response: { data: { message: 'Generic summary', errors: { admin_response: ['Specific response feedback'] } } } }); await request;
    assert.equal(state.responseText.value, 'The school record was checked.');
    assert.equal(state.actionError.value, 'Specific response feedback'); assert.equal(state.saving.value, false);
    assert.equal(state.report.value.admin_status, 'open');
});

test('a successful response uses refreshed mutation data without a risky second GET', async () => {
    const state = harness('SupportCaseRecordPage', 'loadCase, updateStatus, responseText, report, canResolve, confirmation');
    await state.loadCase(); state.responseText.value = 'A saved platform response.';
    state.window.axios.get = async () => { throw new Error('The follow-up fetch would fail.'); };
    await state.updateStatus('resolved');
    assert.equal(state.report.value.state, 'waiting'); assert.equal(state.report.value.provider_status, 'open');
    assert.equal(state.canResolve.value, false); assert.equal(state.confirmation.value, 'Platform response recorded.');
    assert.equal(state.gets.length, 1); assert.equal(state.writes.length, 1);
    assert.deepEqual(Object.keys(state.writes[0][1]).sort(), ['admin_response', 'status']);
});

test('reopening stays confirmed and guarded while saving and clears only platform response', async () => {
    const state = harness('SupportCaseRecordPage', 'loadCase, openReopen, closeReopen, updateStatus, reopenOpen, report, responseText, actionError');
    state.window.axios.get = async () => ({ data: { report: { ...casePayload, admin_status: 'resolved', admin_response: 'Completed platform review.', provider_response: 'Provider instructions.', state: 'waiting' } } });
    await state.loadCase(); state.openReopen(); assert.equal(state.reopenOpen.value, true);
    const pending = deferred(); state.window.axios.patch = (...args) => { state.writes.push(args); return pending.promise; };
    const request = state.updateStatus('open'); state.closeReopen(); await state.updateStatus('open');
    assert.equal(state.reopenOpen.value, true); assert.equal(state.writes.length, 1);
    assert.deepEqual(state.writes[0][1], { status: 'open', admin_response: null });
    pending.reject({ response: { data: { message: 'Reopen unavailable' } } }); await request;
    assert.equal(state.reopenOpen.value, true); assert.equal(state.actionError.value, 'Reopen unavailable');
    assert.equal(state.responseText.value, 'Completed platform review.');
    state.window.axios.patch = async () => ({ data: { support_case: { ...casePayload, provider_response: 'Provider instructions.' } } });
    await state.updateStatus('open'); assert.equal(state.reopenOpen.value, false);
    assert.equal(state.responseText.value, ''); assert.equal(state.report.value.provider_response, 'Provider instructions.');
});

test('loaded cases cannot submit arbitrary or already-current status changes', async () => {
    const state = harness('SupportCaseRecordPage', 'loadCase, updateStatus, report');
    await state.loadCase();
    for (const status of ['open', 'rejected', 'deleted']) await state.updateStatus(status);
    state.report.value.admin_status = 'resolved'; await state.updateStatus('resolved');
    assert.equal(state.writes.length, 0);
});

test('provider-only cases do not offer a platform completion or reopening action', async () => {
    const state = harness('SupportCaseRecordPage', 'loadCase, updateStatus, openReopen, reopenOpen, canResolve, canReopen');
    state.window.axios.get = async () => ({ data: { report: { ...casePayload, admin_status: 'not_required' } } });
    await state.loadCase(); await state.updateStatus('open'); await state.updateStatus('resolved'); state.openReopen();
    assert.equal(state.canResolve.value, false); assert.equal(state.canReopen.value, false);
    assert.equal(state.reopenOpen.value, false); assert.equal(state.writes.length, 0);
    assert.match(read('SupportCaseRecordPage'), /Platform response not required/);
    assert.match(read('SupportCaseRecordPage'), /report.context_label/);
    assert.doesNotMatch(read('SupportCaseRecordPage'), /\{\{ report.context \}\}/);
});

test('support records keep full-width forms, full identifiers and shared file/reopen modals', () => {
    const record = read('SupportCaseRecordPage'), directory = read('SupportCaseDirectoryPage');
    assert.doesNotMatch(record + directory, /truncate|max-w-(?:4xl|5xl|md|xs)|h-9 w-9.*Preview/);
    for (const value of ['data-file-preview="true"', ':data-file-name="report.attachment.original_name"', 'h-10 w-10', 'AdminDialog', 'support-reopen-title', ':busy="saving"', ':error="actionError"', ':readonly="!canResolve"', ':disabled="saving"', 'The provider\'s response is kept.', 'Shared with the reporter', 'This case stays open until the provider']) assert.ok(record.includes(value), value);
    assert.doesNotMatch(record, /FilePreviewModal|previewAttachment/);
    for (const value of ['table-fixed min-w-[56rem]', '<colgroup>', 'item.reporter.image_url', 'item.state_label', 'support-text']) assert.ok(directory.includes(value), value);
    assert.match(record, /@submit.prevent="updateStatus\('resolved'\)"/);
});
