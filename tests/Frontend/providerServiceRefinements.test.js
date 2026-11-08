import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';
import { staffQueueReturn } from '../../resources/js/support/staffQueueState.js';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, reactive, ref } from 'vue';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const pages = [
    'support/SupportCaseDirectoryPage.vue', 'support/SupportCaseRecordPage.vue', 'support/SupportPlatformReportPage.vue',
    'billing/BillingRequestDirectoryPage.vue', 'billing/BillingServiceCatalogPage.vue', 'billing/BillingServiceDetailPage.vue',
    'billing/BillingServiceIntakePage.vue', 'billing/BillingServiceReviewPage.vue', 'billing/BillingServiceOverviewPage.vue',
    'billing/BillingServiceMeetingPage.vue', 'billing/BillingServiceFilesPage.vue', 'billing/BillingServiceUpdatesPage.vue',
    'billing/BillingServiceCloseoutPage.vue', 'billing/BillingServiceHistoryPage.vue', 'billing/components/BillingServiceWorkspaceShell.vue',
];
for (const page of pages) {
    test(`${page} compiles with service refinements`, () => {
        const source = read(`pages/provider/${page}`);
        const { descriptor, errors } = parse(source, { filename: page });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: page });
        const template = compileTemplate({ id: page, filename: page, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
        assert.doesNotMatch(descriptor.template.content.replace(/<i\b[^>]*>/g, ''), /text-\[(?:9|10|11)px\]|\btruncate\b/);
        assert.ok(source.includes('provider-service-workspace') || source.includes('<BillingServiceWorkspaceShell'));
    });
}

test('support, payment and file tables keep fixed viewports and contain their action columns', () => {
    for (const page of ['support/SupportCaseDirectoryPage.vue', 'billing/BillingRequestDirectoryPage.vue', 'billing/BillingServiceFilesPage.vue']) {
        const source = read(`pages/provider/${page}`);
        for (const token of ['staff-table-viewport', '<colgroup>', 'min-w-[56rem]']) assert.ok(source.includes(token), `${page}: ${token}`);
        assert.doesNotMatch(source, /<th[^>]*w-\[\d+%\]|min-w-\[(?:72rem|920px|880px)\]/);
    }
    const support = read('pages/provider/support/SupportCaseDirectoryPage.vue');
    for (const token of ['StaffTableIdentity', 'profile_photo_url', 'selectedProgram', 'pagination', 'requestVersion', '!reports.length && !errorMessage']) assert.ok(support.includes(token));
    const billing = read('pages/provider/billing/BillingRequestDirectoryPage.vue');
    for (const token of ['exportUrl', 'isRequestQueue', 'entry.can_refresh_payment', '!entries.length && !errorMessage']) assert.ok(billing.includes(token));
});

test('new CSS remains provider-scoped and leaves payment decisions and applicant views alone', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const rules = css.split('/* Refine provider support and paid-service screens without affecting admin. */')[1].split('/* Provider account')[0];
    for (const match of rules.matchAll(/([^{}]+)\{/g)) for (const selector of match[1].trim().split(/,\s*\n/)) assert.match(selector.trim(), /^\.provider-service-workspace/);
    for (const token of ['overflow-wrap: anywhere', 'focus-visible', 'white-space: nowrap', 'service-next-action']) assert.ok(rules.includes(token));
    assert.doesNotMatch(rules, /@media|gradient/);
    const closeout = read('pages/provider/billing/BillingServiceCloseoutPage.vue');
    for (const token of ["purchase.fulfillment_status === 'provider_review'", "purchase.fulfillment_status === 'completed'", 'canRequestCancellation', 'revisionReason', 'Confirm complete']) assert.ok(closeout.includes(token));
    const meeting = read('pages/provider/billing/BillingServiceMeetingPage.vue');
    assert.match(meeting, /\['completed', 'cancelled'\]/);
    assert.match(read('pages/provider/billing/BillingServiceHistoryPage.vue'), /labelFromKey\(event.delivery_from/);
});

test('staff file previews retain full names and keep their footer inside short viewports', () => {
    const source = read('components/StaffFilePreviewHost.vue');
    const { descriptor, errors } = parse(source);
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'staff-file-preview' });
    assert.deepEqual(compileTemplate({ id: 'staff-file-preview', filename: 'StaffFilePreviewHost.vue', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.doesNotMatch(descriptor.template.content, /truncate|text-\[10px\]|min-h-\[24rem\]/);
    assert.match(source, /<footer class="flex shrink-0/);
    assert.match(source, /usePageScrollLock/);
});

test('final provider page scan finds no clipped text or tiny non-icon labels outside the sidebar', () => {
    const directory = new URL('../../resources/js/pages/provider/', import.meta.url);
    function walk(path) {
        return readdirSync(path, { withFileTypes: true }).flatMap((entry) => entry.isDirectory()
            ? walk(join(path, entry.name)) : entry.name.endsWith('.vue') && entry.name !== 'ProviderSidebar.vue' ? [join(path, entry.name)] : []);
    }
    const files = walk(decodeURIComponent(directory.pathname).replace(/^\/([A-Za-z]:)/, '$1'));
    assert.ok(files.length >= 50);
    for (const file of files) {
        const { descriptor } = parse(readFileSync(file, 'utf8'));
        const template = descriptor.template?.content.replace(/<i\b[^>]*>/g, '') ?? '';
        assert.doesNotMatch(template, /text-\[(?:9|10|11)px\]|\btruncate\b/, file);
    }
});

function pageHarness(path, exposed, overrides = {}) {
    const source = parse(read(`pages/provider/${path}`)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const writes = [];
    const navigation = [];
    const window = { location: { pathname: '/provider/workspaces/billing/service-requests/1/review', href: 'http://localhost/provider', search: '', assign: (url) => navigation.push(url) }, history: { replaceState() {} },
        axios: { post: async (...args) => { writes.push(args); return { data: {} }; }, patch: async (...args) => { writes.push(args); return { data: {} }; } } };
    const deps = { staffQueueReturn, ref, reactive, computed, onMounted() {}, watch() {}, window, URLSearchParams, URL, FormData: class { append() {} }, ...overrides };
    return { ...new Function(...Object.keys(deps), `${source}\nreturn { ${exposed} };`)(...Object.values(deps)), window, writes, navigation };
}

function workspaceHarness() {
    const source = read('composables/useProviderServiceWorkspace.js').replace(/^import .*;\r?\n/gm, '').replaceAll('export function', 'function');
    const document = { getElementById: () => ({ dataset: { purchaseId: '1' } }) };
    const create = new Function('computed', 'ref', 'onMounted', 'providerServiceWorkspace', 'document', `${source}\nreturn useProviderServiceWorkspace;`)(computed, ref, () => {}, () => ({}), document);
    return create('overview');
}

test('shared service actions cannot run before loading or submit twice while pending', async () => {
    const state = workspaceHarness();
    let calls = 0;
    assert.equal(await state.runAction(async () => { calls++; }), false);
    state.purchase.value = { id: 1, status: 'paid', fulfillment_status: 'in_progress' };
    state.isLoading.value = false;
    let finish;
    const action = state.runAction(() => { calls++; return new Promise((resolve) => { finish = resolve; }); });
    assert.equal(await state.runAction(async () => { calls++; }), false);
    assert.equal(calls, 1);
    finish({ data: { purchase: state.purchase.value, message: 'Saved' } });
    assert.equal(await action, true);
    assert.equal(state.isSaving.value, false);
});

test('service edits follow the existing paid, closed and pending-cancellation restrictions', () => {
    const state = workspaceHarness();
    for (const purchase of [{ status: 'pending', fulfillment_status: 'queued' }, { status: 'paid', fulfillment_status: 'completed' }, { status: 'paid', fulfillment_status: 'cancelled' }, { status: 'paid', fulfillment_status: 'provider_review' }, { status: 'paid', fulfillment_status: 'in_progress', cancellation_status: 'requested' }]) {
        state.purchase.value = purchase;
        assert.equal(state.canModify.value, false);
    }
    state.purchase.value = { status: 'paid', fulfillment_status: 'in_progress' };
    assert.equal(state.canModify.value, true);
});

test('support response errors stay in the confirmation and preserve the written answer', async () => {
    const state = pageHarness('support/SupportCaseRecordPage.vue', 'report, responseText, confirmation, confirmationError, requestComplete, confirmStatus, errorMessage');
    state.report.value = { requires_both_roles: true, submitted_by_provider: false, provider_status: 'open' };
    state.responseText.value = 'Please review the updated application instructions.';
    state.requestComplete();
    state.window.axios.patch = async () => { throw { response: { data: { message: 'Please try again.' } } }; };
    await state.confirmStatus();
    assert.equal(state.confirmation.value.status, 'resolved');
    assert.equal(state.confirmationError.value, 'Please try again.');
    assert.equal(state.errorMessage.value, '');
    assert.equal(state.responseText.value, 'Please review the updated application instructions.');
});

test('a platform report cannot be answered as if it were an applicant concern', () => {
    const state = pageHarness('support/SupportCaseRecordPage.vue', 'report, responseText, confirmation, requestComplete, requestReopen');
    state.report.value = { requires_both_roles: false, submitted_by_provider: true, provider_status: 'resolved' };
    state.responseText.value = 'An otherwise valid written response.';
    state.requestComplete();
    state.requestReopen();
    assert.equal(state.confirmation.value, null);
});

test('a failed platform-form load cannot send an empty report', async () => {
    const state = pageHarness('support/SupportPlatformReportPage.vue', 'submitReport, isLoading');
    state.isLoading.value = false;
    await state.submitReport();
    assert.equal(state.writes.length, 0);
});

test('a redirected or unloaded service intake cannot save a request', async () => {
    const state = pageHarness('billing/BillingServiceIntakePage.vue', 'continueToReview, isLoading, plan, canSaveRequest');
    state.isLoading.value = false;
    await state.continueToReview();
    state.plan.value = { name: 'Service' };
    await state.continueToReview();
    assert.equal(state.writes.length, 0);
});

test('checkout requires configured payment, accepted terms and a loaded unpaid request', async () => {
    const state = pageHarness('billing/BillingServiceReviewPage.vue', 'startCheckout, plan, serviceRequest, gateway, acceptTerms, isLoading');
    state.isLoading.value = false;
    state.gateway.value.configured = true;
    state.acceptTerms.value = true;
    await state.startCheckout();
    assert.equal(state.writes.length, 0);
    state.serviceRequest.value = { id: 1, purchase: { status: 'paid' } };
    await state.startCheckout();
    assert.equal(state.writes.length, 0);
});

test('repeated checkout clicks open only one checkout request', async () => {
    const state = pageHarness('billing/BillingServiceReviewPage.vue', 'startCheckout, serviceRequest, gateway, acceptTerms, isLoading');
    state.isLoading.value = false;
    state.serviceRequest.value = { id: 1 };
    state.gateway.value.configured = true;
    state.acceptTerms.value = true;
    let finish;
    let calls = 0;
    state.window.axios.post = () => { calls++; return new Promise((resolve) => { finish = resolve; }); };
    const first = state.startCheckout();
    await state.startCheckout();
    assert.equal(calls, 1);
    finish({ data: { checkout_url: 'https://checkout.example.test/demo' } });
    await first;
    assert.deepEqual(state.navigation, ['https://checkout.example.test/demo']);
});
