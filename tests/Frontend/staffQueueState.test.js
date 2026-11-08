import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { createStaffQueueState, staffQueueLink, staffQueueReturn } from '../../resources/js/support/staffQueueState.js';

const origin = 'https://portal.example.test';
function browser(pathname, { search = '', id = 17, items = new Map(), axios = {} } = {}) {
    return { location: { pathname, search, origin }, portalUser: { id }, axios,
        sessionStorage: { getItem: (key) => items.get(key), setItem: (key, value) => items.set(key, value) },
        clearTimeout() {}, setTimeout() {} };
}

test('queue state is scoped to the signed-in account and the exact queue in the current tab', async () => {
    const items = new Map(), pathname = '/provider/programs/published';
    const state = createStaffQueueState(browser(pathname, { items }), ['search', 'sort']);
    await state.load(async () => ({ data: { pagination: { current_page: 3, last_page: 5 } } }), { page: 3, search: 'sample learner', sort: 'title' });
    const restored = createStaffQueueState(browser(pathname + '/', { items }), ['search', 'sort']);
    assert.equal(restored.get('search'), 'sample learner');
    assert.equal(restored.get('sort'), 'title');
    assert.equal(restored.page, 3);
    for (const view of [browser('/provider/programs/drafts', { items }), browser(pathname, { id: 18, items }), browser(pathname)]) {
        const separate = createStaffQueueState(view, ['search', 'sort']);
        assert.equal(separate.page, 1);
        assert.equal(separate.get('search'), null);
    }
    const explicit = createStaffQueueState(browser(pathname, { search: '?sort=deadline', items }), ['search', 'sort']);
    assert.equal(explicit.get('search'), null);
    assert.equal(explicit.get('sort'), 'deadline');
    assert.equal(explicit.page, 1);
});

test('unavailable or corrupt session storage does not block an explicit queue link', async () => {
    const view = browser('/admin/workspaces/reviews/programs', { search: '?status=published&page=2' });
    Object.defineProperty(view, 'sessionStorage', { get() { throw new Error('Disabled'); } });
    const state = createStaffQueueState(view, ['status']);
    assert.equal(state.get('status'), 'published');
    assert.equal(state.page, 2);
    await assert.doesNotReject(() => state.load(async () => ({ data: {} }), { page: 2, status: 'published' }));
    const corrupt = browser('/admin/workspaces/reviews/programs');
    corrupt.sessionStorage.getItem = () => 'not valid JSON';
    assert.equal(createStaffQueueState(corrupt, ['status']).page, 1);
});

test('a shortened queue loads its last valid page, but an outdated response cannot reload or overwrite the state', async () => {
    const view = browser('/provider/programs/published');
    const calls = [];
    const state = createStaffQueueState(view, ['search']);
    const fetch = async (params) => { calls.push(params); return { data: { pagination: { current_page: params.page, last_page: 2 } } }; };
    const payload = await state.load(fetch, { page: 5, search: 'sample' });
    assert.deepEqual(calls.map(({ page }) => page), [5, 2]);
    assert.equal(payload.data.pagination.current_page, 2);
    await state.load(fetch, { page: 8, search: 'obsolete' }, () => false);
    assert.deepEqual(calls.map(({ page }) => page), [5, 2, 8]);
    await assert.rejects(() => state.load(async () => { throw new Error('Offline'); }, { page: 5, search: 'failed request' }), /Offline/);
    const restored = createStaffQueueState(view, ['search']);
    assert.equal(restored.page, 2);
    assert.equal(restored.get('search'), 'sample');
});

test('record links carry only the originating queue and retain existing parameters and fragments', () => {
    const queue = '/admin/workspaces/billing/queues/waiting';
    const record = staffQueueLink('/admin/workspaces/billing/requests/4?section=files#proof', queue, origin);
    const url = new URL(record, origin);
    assert.equal(url.searchParams.get('return_to'), queue);
    assert.equal(url.searchParams.get('section'), 'files');
    assert.equal(url.hash, '#proof');
    assert.equal(staffQueueReturn(browser(url.pathname, { search: url.search }), 'adminBilling'), queue);
    const nextTab = staffQueueLink('/admin/workspaces/billing/requests/4/history', queue, origin);
    assert.equal(new URL(nextTab, origin).searchParams.get('return_to'), queue);
    assert.equal(staffQueueLink('https://external.example.test/pay', queue, origin), 'https://external.example.test/pay');
    for (const invalid of ['//external.example.test', '/\\external.example.test', '/admin/workspaces/billing/queues/waiting/../../logout', '/admin/workspaces/accounts/directory', '/admin/workspaces/billing/requests/4']) {
        const view = browser(url.pathname, { search: `?${new URLSearchParams({ return_to: invalid })}` });
        assert.equal(staffQueueReturn(view, 'adminBilling', '/default'), '/default');
    }
});

function directory(path, window, exposed) {
    const file = readFileSync(new URL(`../../resources/js/pages/${path}.vue`, import.meta.url), 'utf8');
    const script = parse(file).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const mounted = [];
    const deps = { createStaffQueueState, useAdminResource, window, ref, computed, watch() {}, onBeforeUnmount() {}, onMounted: (fn) => mounted.push(fn) };
    const state = new Function(...Object.keys(deps), `${script}\nreturn {${exposed}};`)(...Object.values(deps));
    return { ...state, open: () => mounted[0]() };
}

test('admin program queue restores search, status and page after its Back link is used', async () => {
    const items = new Map(), calls = [], path = '/admin/workspaces/reviews/programs';
    const axios = { get: async (_, { params }) => { calls.push(params); return { data: { items: [], pagination: { current_page: params.page, last_page: 4 } } }; } };
    const first = directory('admin/reviews/ReviewQueuePage', browser(path, { items, axios }), 'queueState, loadItems, search, status');
    first.search.value = 'matching program';
    first.status.value = 'published';
    await first.loadItems(3);
    const record = new URL(first.queueState.link(`${path}/8`), origin);
    assert.doesNotMatch(record.href, /matching|published/);
    const back = staffQueueReturn(browser(record.pathname, { search: record.search }), 'adminReviews');
    const restored = directory('admin/reviews/ReviewQueuePage', browser(back, { items, axios }), 'search, status');
    await restored.open();
    assert.deepEqual(calls[1], calls[0]);
    assert.equal(restored.search.value, 'matching program');
    assert.equal(restored.status.value, 'published');
});

test('provider reviewer queue restores the applied search, assigned program and page', async () => {
    const items = new Map(), calls = [], path = '/provider/workspaces/reviews/assigned';
    const axios = { get: async (_, { params }) => { calls.push(params); return { data: { applications: [], pagination: { current_page: params.page, last_page: 4 } } }; } };
    const first = directory('provider/reviews/ApplicationReviewDirectoryPage', browser(path, { items, axios }), 'loadApplications, search, selectedProgram, reviewUrl');
    first.search.value = 'learner@example.test';
    first.selectedProgram.value = '42';
    await first.loadApplications(2);
    const record = new URL(first.reviewUrl({ detail_url: '/provider/applications/7?section=documents' }), origin);
    assert.equal(record.searchParams.get('section'), 'documents');
    assert.doesNotMatch(record.href, /learner/);
    const back = record.searchParams.get('return_to');
    const restored = directory('provider/reviews/ApplicationReviewDirectoryPage', browser(back, { items, axios }), 'search, selectedProgram');
    await restored.open();
    assert.deepEqual(calls[1], calls[0]);
    assert.equal(restored.search.value, 'learner@example.test');
    assert.equal(restored.selectedProgram.value, '42');
});
