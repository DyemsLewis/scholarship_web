import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';

const paths = ['pages/AdminPortalManagerWorkspacePage.vue', 'pages/admin/portal/PortalManagerAreaPage.vue'];
const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const deferred = () => { let resolve; const promise = new Promise((yes) => { resolve = yes; }); return { promise, resolve }; };
function harness(area = null) {
    const calls = [], mounted = [];
    const window = { axios: { get: async (...args) => { calls.push(args); return { data: {} }; } } };
    const document = { getElementById: () => ({ dataset: { area } }) };
    const source = parse(read(paths[area ? 1 : 0])).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const deps = { computed, useAdminResource, window, document, onMounted: (fn) => mounted.push(fn) };
    const exposed = area ? 'loadArea, payload, metrics, isLoading, errorMessage, serviceItems, accountDescription, serviceDescription, supportDescription, financeDescription, activityDescription, reviewNames' : 'loadOverview, summary, priorities, isLoading, errorMessage';
    return { ...new Function(...Object.keys(deps), `${source}\nreturn { ${exposed} };`)(...Object.values(deps)), window, calls, mounted };
}

for (const path of paths) {
    test(`${path} compiles`, () => {
        const { descriptor, errors } = parse(read(path)); assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        assert.deepEqual(compileTemplate({ id: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

for (const area of [null, 'accounts', 'reviews', 'support', 'services', 'finance', 'records']) {
    test(`${area || 'overview'} has an explicit failure and retry without writing data`, async () => {
        const s = harness(area), load = area ? s.loadArea : s.loadOverview;
        s.window.axios.get = async () => { throw new Error('Offline'); }; await load();
        assert.equal(s.isLoading.value, false); assert.match(s.errorMessage.value, /Unable to load/);
        s.window.axios.get = async (url, config) => { s.calls.push([url, config]); return { data: { summary: { total: 9 } } }; };
        await load(); assert.equal(s.errorMessage.value, ''); assert.ok(s.calls[0][1].signal instanceof AbortSignal);
        assert.equal(area ? s.payload.value.summary.total : s.summary.value.total, 9);
    });
}

test('late refresh responses cannot replace the most recent oversight snapshot', async () => {
    const s = harness('support'), first = deferred(), latest = deferred(); let count = 0;
    s.window.axios.get = () => ++count === 1 ? first.promise : latest.promise;
    const old = s.loadArea(), current = s.loadArea(); latest.resolve({ data: { summary: { needs_response: 7 } } }); await current;
    first.resolve({ data: { summary: { needs_response: 0 } } }); await old;
    assert.equal(s.payload.value.summary.needs_response, 7);
});

test('limited previews disclose shown rows versus the real totals', async () => {
    const s = harness('accounts');
    s.window.axios.get = async () => ({ data: { users: [{ id: 1 }], summary: { attention_total: 21, ready: 9, active: 12, waiting: 8 },
        queues: { ready: { items: [{ id: 2 }] }, active: { items: [{ id: 3 }] } }, cases: { items: [{ id: 4 }], pagination: { total: 50 } }, payments: { items: [{ id: 5 }], pagination: { total: 30 } }, activity: { items: [{ id: 6 }], pagination: { total: 200 } } } });
    await s.loadArea();
    assert.match(s.accountDescription.value, /1 of 21/); assert.match(s.serviceDescription.value, /2 of 29/);
    assert.match(s.supportDescription.value, /1 of 50/); assert.match(s.financeDescription.value, /1 of 30/); assert.match(s.activityDescription.value, /1 of 200/);
    assert.equal(s.reviewNames({ count: 3, items: [] }), 'Waiting records are not available in this preview.');
    assert.equal(s.reviewNames({ count: 0, items: [] }), 'No waiting records');
});

test('all portal tables use fixed columns and keep oversight distinct from specialist actions', () => {
    for (const path of paths) { const source = read(path); assert.ok(source.includes('colgroup')); assert.ok(!source.includes('truncate')); assert.ok(source.includes('@retry=')); }
    const source = read(paths[1]); assert.equal((source.match(/<colgroup>/g) || []).length, 7);
    assert.match(source, /Read-only oversight/); assert.ok(source.includes('payload.summary.exceptions'));
    assert.ok(!source.includes('payload.exceptions.items.length')); assert.ok(!source.includes('window.axios.post'));
});
