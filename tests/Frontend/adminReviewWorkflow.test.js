import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { computed, ref } from 'vue';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { createStaffQueueState, staffQueueLink, staffQueueReturn } from '../../resources/js/support/staffQueueState.js';

for (const name of ['AdminReviewGuide', 'AdminReviewNavigation']) {
    test(`${name} compiles with bounded secondary details`, () => {
        const source = readFileSync(new URL(`../../resources/js/components/admin/${name}.vue`, import.meta.url), 'utf8');
        const { descriptor, errors } = parse(source);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(errors, []);
        assert.deepEqual(compileTemplate({ id: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
        assert.doesNotMatch(source, /v-html|axios\./);
    });
}

function navigation(get, search = '?return_to=' + encodeURIComponent('/admin/workspaces/reviews/applicants?status=all&search=learner&page=2')) {
    const source = readFileSync(new URL('../../resources/js/composables/useAdminReviewNavigation.js', import.meta.url), 'utf8')
        .replace(/^import .*;\r?\n/gm, '').replace('export function', 'function');
    const window = { location: { origin: 'https://portal.test', search }, portalUser: { id: 3 }, sessionStorage: { getItem: () => null, setItem() {} }, axios: { get } };
    const deps = { ref, computed, createStaffQueueState, staffQueueLink, staffQueueReturn, onBeforeUnmount() {}, window, URL, AbortController };
    const create = new Function(...Object.keys(deps), source + '; return useAdminReviewNavigation;')(...Object.values(deps));
    return create('applicants', '1');
}

test('next record retains the exact queue search, status and page', async () => {
    let query;
    const state = navigation(async (url, { params }) => {
        query = params;
        return { data: { items: [{ id: 1, name: 'Current', review_url: '/admin/workspaces/reviews/applicants/1' }, { id: 2, name: 'Next learner', review_url: '/admin/workspaces/reviews/applicants/2' }], pagination: { current_page: 2, last_page: 3 } } };
    });
    await state.load();
    assert.equal(query.search, 'learner'); assert.equal(query.status, 'all'); assert.equal(query.page, 2);
    const back = new URL(new URL(state.next.value.url, 'https://portal.test').searchParams.get('return_to'), 'https://portal.test');
    assert.equal(back.searchParams.get('page'), '2'); assert.equal(back.searchParams.get('search'), 'learner');
    assert.equal(state.next.value.name, 'Next learner');
});

test('next record crosses page boundaries and carries the new page in its return link', async () => {
    const calls = [];
    const state = navigation(async (url, { params }) => {
        calls.push(params.page);
        return { data: { items: params.page === 2 ? [{ id: 1 }] : [{ id: 9, name: 'Next page learner', review_url: '/admin/workspaces/reviews/applicants/9' }], pagination: { current_page: params.page, last_page: 3 } } };
    });
    await state.load(); assert.deepEqual(calls, [2, 3]);
    assert.match(new URL(state.next.value.url, 'https://portal.test').searchParams.get('return_to'), /page=3/);
});

test('a completed record removed from its queue continues to the first remaining match', async () => {
    const state = navigation(async () => ({ data: { items: [{ id: 8, name: 'Remaining', review_url: '/admin/workspaces/reviews/applicants/8' }], pagination: { current_page: 2, last_page: 2 } } }));
    await state.load(); assert.equal(state.next.value.name, 'Remaining');
});

test('navigation failures are retryable and never navigate or submit a decision', async () => {
    let offline = true;
    const state = navigation(async () => {
        if (offline) throw new Error('Offline');
        return { data: { items: [], pagination: { current_page: 1, last_page: 1 } } };
    });
    await state.load(); assert.equal(state.next.value, null); assert.ok(state.error.value);
    offline = false; await state.load(); assert.equal(state.error.value, ''); assert.equal(state.next.value, null);
});
