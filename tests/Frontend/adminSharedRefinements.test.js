import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, createSSRApp, effectScope, h, reactive, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { reviewErrorMessage } from '../../resources/js/support/adminReviewPresentation.js';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const compile = (path, imports = {}) => {
    const { descriptor } = parse(read(path), { filename: path });
    const code = compileScript(descriptor, { id: path, inlineTemplate: true }).content.replace(/from (['"])(.*?)\1/g, (match, quote, specifier) => {
        const resolved = specifier === 'vue' ? import.meta.resolve('vue') : imports[specifier];
        return resolved ? `from ${JSON.stringify(resolved)}` : match;
    });
    return `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
};
const loadStateUrl = compile('pages/admin/components/AdminLoadState.vue');
const { default: LoadState } = await import(loadStateUrl);
const { default: TableFrame } = await import(compile('pages/admin/components/AdminTableFrame.vue', { './AdminLoadState.vue': loadStateUrl }));
const modalStub = `data:text/javascript,${encodeURIComponent('export function useModalAccessibility() { return { handleKeydown() {} }; }')}`;
const confirmationUrl = compile('components/ConfirmationDialog.vue', { '../composables/useModalAccessibility': modalStub });
const { default: Dialog } = await import(compile('pages/admin/components/AdminDialog.vue', { '../../../composables/useModalAccessibility': modalStub, '../../../components/ConfirmationDialog.vue': confirmationUrl }));
const render = (component, props = {}, slots = {}) => renderToString(createSSRApp({ render: () => h(component, props, slots) }));

for (const path of [
    'components/TaskPageHeader.vue', 'pages/AdminProfilePage.vue',
    'pages/admin/components/AdminDialog.vue', 'pages/admin/components/AdminLoadState.vue', 'pages/admin/components/AdminTableFrame.vue',
    'pages/admin/accounts/AccountRecordPage.vue', 'pages/admin/reviews/ReviewQueuePage.vue', 'pages/admin/reviews/MonitoringReviewRecordPage.vue',
]) {
    test(`${path} compiles with admin shared refinements`, () => {
        const { descriptor, errors } = parse(read(path), { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({ id: path, filename: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}

test('only the latest successful request can replace data, including when a client ignores abort', async () => {
    const first = deferred(), second = deferred();
    const signals = [];
    const resource = useAdminResource(({ signal }, key) => { signals.push(signal); return key === 'old' ? first.promise : second.promise; });
    const old = resource.load('old'); const latest = resource.load('latest');
    assert.equal(signals[0].aborted, true);
    second.resolve({ items: ['Pending'] });
    await latest;
    first.resolve({ items: ['Published'] });
    assert.equal(await old, null);
    assert.deepEqual(resource.data.value, { items: ['Pending'] });
    assert.equal(resource.isLoading.value, false);
    assert.equal(resource.hasLoaded.value, true);
});

test('superseded failures cannot clear a new request loading state or show stale errors', async () => {
    const first = deferred(), second = deferred();
    const resource = useAdminResource((_, key) => key === 1 ? first.promise : second.promise);
    const old = resource.load(1); const latest = resource.load(2);
    first.reject({ response: { data: { message: 'Old failure' } } });
    await old;
    assert.equal(resource.isLoading.value, true);
    assert.equal(resource.errorMessage.value, '');
    second.resolve({ items: [] }); await latest;
    assert.equal(resource.isLoading.value, false);
    assert.equal(resource.hasLoaded.value, true);
});

test('debounced filters invalidate requests immediately, not only after the debounce delay', async () => {
    const first = deferred();
    const resource = useAdminResource(() => first.promise);
    const old = resource.load(); resource.invalidate();
    first.resolve({ items: ['Stale result'] }); await old;
    assert.equal(resource.data.value, null);
    assert.equal(resource.isLoading.value, true);
    assert.equal(resource.hasLoaded.value, false);
});

test('failed refreshes retain previously loaded data and expose a retryable error', async () => {
    let count = 0;
    const resource = useAdminResource(async () => {
        if (++count === 1) return { id: 7 };
        throw { response: { data: { message: 'Temporarily unavailable' } } };
    });
    await resource.load(); await resource.load();
    assert.deepEqual(resource.data.value, { id: 7 });
    assert.equal(resource.errorMessage.value, 'Temporarily unavailable');
    assert.equal(resource.hasLoaded.value, true);
    assert.equal(resource.isLoading.value, false);
});

test('initial load failures never masquerade as a successful empty resource', async () => {
    const resource = useAdminResource(async () => { throw new Error('Offline'); }, { failureMessage: 'Could not load accounts.' });
    await resource.load();
    assert.equal(resource.hasLoaded.value, false);
    assert.equal(resource.data.value, null);
    assert.equal(resource.errorMessage.value, 'Could not load accounts.');
});

test('scope disposal cancels requests and prevents late updates or additional fetches', async () => {
    const pending = deferred(); let signal; let calls = 0;
    const scope = effectScope();
    const resource = scope.run(() => useAdminResource((config) => { signal = config.signal; calls++; return pending.promise; }));
    const request = resource.load(); scope.stop();
    assert.equal(signal.aborted, true);
    pending.resolve({ id: 8 }); await request; await resource.load();
    assert.equal(resource.data.value, null);
    assert.equal(resource.isLoading.value, false);
    assert.equal(calls, 1);
});

test('load feedback distinguishes loading, failure, and successful empty states', async () => {
    const loading = await render(LoadState, { loading: true, error: 'Hidden previous error', loadingMessage: 'Loading verification queue...' });
    assert.match(loading, /role="status"/);
    assert.match(loading, /Loading verification queue/);
    assert.doesNotMatch(loading, /Hidden previous error|Try again/);
    const error = await render(LoadState, { error: '<script>unavailable</script>' });
    assert.match(error, /role="alert"/);
    assert.match(error, /Try again/);
    assert.match(error, /&lt;script&gt;/);
    assert.doesNotMatch(await render(LoadState), /role="alert"|role="status"|Try again/);
});

test('table frames hide empty claims and actions on failed loads but preserve their fixed viewport', async () => {
    const slots = { default: () => h('p', 'No records in this queue'), filters: () => h('input', { placeholder: 'Search' }), footer: () => h('button', 'Next') };
    for (const props of [{ error: 'Unavailable' }, { loading: true }]) {
        const html = await render(TableFrame, { title: 'Review queue', ...props }, slots);
        assert.match(html, /staff-table-viewport/);
        assert.match(html, /placeholder="Search"/);
        assert.doesNotMatch(html, /No records in this queue/);
        if (props.error) assert.doesNotMatch(html, />Next</);
    }
    const ready = await render(TableFrame, { title: 'Review queue' }, slots);
    assert.match(ready, /No records in this queue/);
    assert.match(ready, />Next</);
    assert.match(css, /\.staff-table-viewport\s*\{[^}]*min-height: 24rem/);
});

test('shared dialogs retain labelled form semantics and disable controls during save', async () => {
    const context = {};
    await renderToString(createSSRApp({ render: () => h(Dialog, { open: true, title: 'Record review', titleId: 'review-title', description: 'Provider oversight', error: 'Could not save', busy: true }, { default: () => h('textarea', { name: 'notes' }) }) }), context);
    const html = context.teleports.body;
    for (const value of ['role="dialog"', 'aria-modal="true"', 'aria-labelledby="review-title"', 'aria-busy="true"', 'tabindex="-1"', 'role="alert"', 'Could not save', 'admin-dialog-body', '<fieldset disabled', 'Saving...']) assert.ok(html.includes(value), value);
    assert.equal((html.match(/<form\b/g) ?? []).length, 1);
    assert.equal((html.match(/<button[^>]* disabled/g) ?? []).length, 3);
    assert.match(read('pages/admin/components/AdminDialog.vue'), /useModalAccessibility\(\(\) => props.open && isFormDialog.value, dialog, close\)/);
    for (const value of ['@keydown="handleKeydown"', 'if (!props.busy)', '!props.confirmDisabled', 'body.value.scrollTop = 0', ':inert="busy || undefined"', 'if (busy && props.open) dialog.value?.focus']) assert.ok(read('pages/admin/components/AdminDialog.vue').includes(value));
});

test('dialog close and confirm are guarded during save and disabled confirmation', () => {
    const script = parse(read('pages/admin/components/AdminDialog.vue')).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const props = { open: true, title: 'Review', busy: true, confirmDisabled: false };
    const emitted = [];
    const state = new Function('ref', 'computed', 'useId', 'watch', 'defineProps', 'defineEmits', 'useModalAccessibility', 'useSlots', `${script}\nreturn { close, confirm };`)(ref, computed, () => 'dialog', () => {}, () => props, () => (event) => emitted.push(event), () => ({ handleKeydown() {} }), () => ({}));
    state.close(); state.confirm(); assert.deepEqual(emitted, []);
    props.busy = false; props.confirmDisabled = true; state.confirm(); assert.deepEqual(emitted, []);
    state.close(); props.confirmDisabled = false; state.confirm(); assert.deepEqual(emitted, ['cancel', 'confirm']);
});

function harness(path, exposed) {
    const script = parse(read(path)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const writes = [];
    const window = { location: { pathname: '/admin/workspaces/reviews/programs', search: '' }, axios: {
        get: async () => { throw new Error('Unavailable'); },
        post: async (...args) => { writes.push(args); return { data: {} }; },
        patch: async (...args) => { writes.push(args); return { data: { user: { first_name: 'Saved' } } }; },
    } };
    const deps = { ref, reactive, computed, useAdminResource, reviewErrorMessage, watch() {}, onMounted() {}, onBeforeUnmount() {}, window, URLSearchParams, limitPhoneNumber: (value) => value, showPortalToast() {} };
    return { ...new Function(...Object.keys(deps), `${script}\nreturn { ${exposed} };`)(...Object.values(deps)), window, writes };
}

test('profile failure cannot show or save a blank record and retry recovers loaded fields', async () => {
    const state = harness('pages/AdminProfilePage.vue', 'loadProfile, saveProfile, loadError, isLoading, user, form');
    await state.loadProfile(); await state.saveProfile();
    assert.equal(state.loadError.value, 'Unable to load admin profile.');
    assert.equal(state.user.value, null);
    assert.equal(state.writes.length, 0);
    state.window.axios.get = async () => ({ data: { user: { first_name: 'Tala', last_name: 'Reyes' } } });
    await state.loadProfile();
    assert.equal(state.loadError.value, '');
    assert.equal(state.form.first_name, 'Tala');
    assert.equal(state.isLoading.value, false);
    const source = read('pages/AdminProfilePage.vue');
    assert.match(source, /v-else-if="user"/);
    assert.doesNotMatch(source, /max-w-4xl/);
    assert.match(source, /grid w-full gap-4 md:grid-cols-3/);
});

test('profile saves preserve drafts on failure and reject duplicate submissions', async () => {
    const state = harness('pages/AdminProfilePage.vue', 'loadProfile, saveProfile, form, validationErrors');
    state.window.axios.get = async () => ({ data: { user: { first_name: 'Tala', last_name: 'Reyes' } } });
    await state.loadProfile(); state.form.first_name = 'Draft name';
    const pending = deferred();
    state.window.axios.patch = (...args) => { state.writes.push(args); return pending.promise; };
    const saving = state.saveProfile(); await state.saveProfile();
    assert.equal(state.writes.length, 1);
    pending.reject({ response: { data: { errors: { username: ['Already in use'] } } } }); await saving;
    assert.equal(state.form.first_name, 'Draft name');
    assert.deepEqual(state.validationErrors.value.username, ['Already in use']);
});

test('monitoring save errors stay inside the dialog, preserve notes and do not duplicate writes', async () => {
    const state = harness('pages/admin/reviews/MonitoringReviewRecordPage.vue', 'record, loading, notes, outcome, reviewOpen, reviewError, errorMessage, saveReview, closeReview');
    state.loading.value = false;
    state.record.value = { id: 14 }; state.notes.value = 'Please clarify the submitted evidence.'; state.outcome.value = 'follow_up_required'; state.reviewOpen.value = true;
    const pending = deferred();
    state.window.axios.post = (...args) => { state.writes.push(args); return pending.promise; };
    const saving = state.saveReview(); state.closeReview(); await state.saveReview();
    assert.equal(state.reviewOpen.value, true);
    assert.equal(state.writes.length, 1);
    pending.reject({ response: { data: { message: 'The review could not be saved.' } } }); await saving;
    assert.equal(state.reviewError.value, 'The review could not be saved.');
    assert.equal(state.errorMessage.value, '');
    assert.equal(state.notes.value, 'Please clarify the submitted evidence.');
    assert.equal(state.reviewOpen.value, true);
});

test('review queues use shared latest-request and load-state protection without changing the API', () => {
    const source = read('pages/admin/reviews/ReviewQueuePage.vue');
    for (const value of ['useAdminResource', 'resource.invalidate()', "{ flush: 'sync' }", 'onBeforeUnmount(() => clearTimeout(searchTimer))', ':loading="loading" :error="errorMessage"', '@retry="loadItems(1)"', 'params, signal', 'loading || pagination.current_page']) assert.ok(source.includes(value), value);
    assert.doesNotMatch(source, /\.axios\.(post|patch|put|delete)\(/);
    for (const endpoint of ['providers', 'programs', 'applicants', 'selection-activities', 'benefits', 'monitoring']) assert.ok(source.includes(`/admin/workspaces/reviews/${endpoint}/data`));
});

test('admin refinement selectors cannot style applicant or provider pages', () => {
    const rules = css.split('/* Admin-only refinements leave shared applicant and provider views unchanged. */')[1].split('/* End admin shared refinements. */')[0];
    for (const match of rules.matchAll(/([^{}]+)\{/g)) {
        for (const selector of match[1].trim().split(/,\s*\n/)) assert.match(selector.trim(), /^\.admin-(page|dialog)/);
    }
    for (const value of ['min-height: 2.5rem', 'font-size: 0.75rem', 'white-space: normal', 'calc(100dvh - 3rem)', 'overflow-y: auto', 'overscroll-behavior: contain', 'align-items: center', 'outline: 2px solid']) assert.ok(rules.includes(value), value);
    assert.doesNotMatch(rules, /@media|gradient|!important/);
    const header = read('components/TaskPageHeader.vue');
    for (const value of ['task-page-heading', 'task-page-icon', 'task-page-eyebrow', 'task-page-description', 'task-page-actions']) assert.ok(header.includes(value));
});
