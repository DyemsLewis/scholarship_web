import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createStaffQueueState } from '../../resources/js/support/staffQueueState.js';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, ref, watch } from 'vue';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { normalizeWorkspaceDashboard } from '../../resources/js/support/workspaceDashboard.js';

const read = (name) => readFileSync(new URL(`../../resources/js/pages/admin/${name}.vue`, import.meta.url), 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const directories = [['finance/FinancePaymentsPage', 'Payments'], ['finance/FinanceExceptionsPage', 'Exceptions'], ['records/RecordsActivityPage', 'Activity']];
function harness(name, exposed) {
    const gets = [], mounted = [], cleanup = [], clicks = [], revoked = [];
    let prints = 0;
    const pathname = {
        'finance/FinancePaymentsPage': '/admin/workspaces/finance/payments',
        'finance/FinanceExceptionsPage': '/admin/workspaces/finance/exceptions',
        'records/RecordsActivityPage': '/admin/workspaces/records/activity',
    }[name] ?? '/admin';
    const window = { location: { pathname, search: '', origin: 'http://localhost' }, print: () => prints++, axios: {
        get: async (...args) => { gets.push(args); return { data: { items: [], pagination: { current_page: 1, last_page: 3, total: 0 }, datasets: [], recent_exports: [] } }; },
    } };
    class BrowserURL extends URL { static createObjectURL() { return 'blob:demo'; } static revokeObjectURL(url) { revoked.push(url); } }
    const document = { getElementById: () => ({ dataset: { purchaseId: '2', entryId: '1' } }), body: { appendChild() {} }, createElement: () => ({ click() { clicks.push(this.download); }, remove() {} }) };
    const source = parse(read(name)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const deps = { createStaffQueueState, computed, ref, watch, useAdminResource, normalizeWorkspaceDashboard, window, document, URL: BrowserURL, URLSearchParams, Blob, AbortController,
        onMounted: (fn) => mounted.push(fn), onBeforeUnmount: (fn) => cleanup.push(fn) };
    return { ...new Function(...Object.keys(deps), `${source}\nreturn { ${exposed} };`)(...Object.values(deps)), window, gets, mounted, cleanup, clicks, revoked, prints: () => prints };
}

for (const name of ['finance/FinanceOverviewPage', 'finance/FinancePaymentsPage', 'finance/FinanceExceptionsPage', 'finance/FinanceReceiptPage', 'records/RecordsOfficerDashboardPage', 'records/RecordsActivityPage', 'records/RecordsActivityRecordPage', 'records/RecordsExportsPage']) {
    test(`${name} compiles with finance/records refinements`, () => {
        const { descriptor, errors } = parse(read(name)); assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(compileTemplate({ id: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

for (const [name, method] of directories) {
    test(`${method} distinguishes a failed load from empty results and retries`, async () => {
        const s = harness(name, `load${method}, errorMessage, loading, resultLabel, items`);
        s.window.axios.get = async () => { throw new Error('Offline'); }; await s[`load${method}`]();
        assert.match(s.errorMessage.value, /could not be loaded/); assert.equal(s.resultLabel.value, 'Results unavailable');
        s.window.axios.get = async () => ({ data: { items: [{ id: 9 }] } }); await s[`load${method}`]();
        assert.equal(s.errorMessage.value, ''); assert.equal(s.items.value[0].id, 9);
    });
    test(`${method} invalidates requests before debounce and rejects stale results`, async () => {
        const s = harness(name, `load${method}, search, items, loading`), old = deferred(), latest = deferred(); let count = 0;
        s.window.axios.get = () => ++count === 1 ? old.promise : latest.promise;
        const first = s[`load${method}`](); s.search.value = 'Latest';
        old.resolve({ data: { items: [{ id: 1 }] } }); await first;
        assert.equal(s.loading.value, true); assert.deepEqual(s.items.value, []);
        const second = s[`load${method}`](); latest.resolve({ data: { items: [{ id: 2 }] } }); await second;
        assert.equal(s.items.value[0].id, 2); s.cleanup.forEach((fn) => fn());
    });
    test(`${method} blocks out-of-range and loading pagination`, async () => {
        const s = harness(name, `load${method}, changePage, loading`);
        s.changePage(2); assert.equal(s.gets.length, 0); await s[`load${method}`]();
        for (const page of [0, 1, 4]) s.changePage(page); assert.equal(s.gets.length, 1);
        s.changePage(2); assert.equal(s.gets.length, 2); assert.equal(s.gets[1][1].params.page, 2);
        assert.ok(s.gets[1][1].signal instanceof AbortSignal);
    });
}

test('receipt printing requires a successfully loaded receipt, including after failed refreshes', async () => {
    const s = harness('finance/FinanceReceiptPage', 'loadReceipt, printReceipt, ready, errorMessage');
    s.printReceipt(); assert.equal(s.prints(), 0); await s.loadReceipt(); assert.equal(s.ready.value, false);
    assert.match(s.errorMessage.value, /could not be loaded/);
    s.window.axios.get = async () => ({ data: { receipt: { receipt_number: 'PAID-1' } } }); await s.loadReceipt();
    s.printReceipt(); assert.equal(s.prints(), 1);
    s.window.axios.get = async () => { throw new Error('Offline'); }; await s.loadReceipt(); s.printReceipt(); assert.equal(s.prints(), 1);
});

test('activity record null responses are retryable failures, not blank pages', async () => {
    const s = harness('records/RecordsActivityRecordPage', 'loadRecord, ready, errorMessage');
    await s.loadRecord(); assert.equal(s.ready.value, false); assert.match(s.errorMessage.value, /could not be loaded/);
    s.window.axios.get = async () => ({ data: { entry: { id: 1, metadata: [] } } }); await s.loadRecord(); assert.equal(s.ready.value, true);
});

const dataset = { key: 'monitoring', name: 'Recipient monitoring', download_url: '/admin/export/monitoring' };
async function exportsDesk() {
    const s = harness('records/RecordsExportsPage', 'loadData, downloadDataset, monitoringStatus, exporting, exportError, exportMessage, datasets');
    s.window.axios.get = async (...args) => { s.gets.push(args); return { data: { datasets: [{ ...dataset }], recent_exports: [] } }; };
    await s.loadData(); return s;
}

test('export desk load never starts downloads and CSV errors preserve the selected filter', async () => {
    const s = await exportsDesk(); assert.equal(s.gets.length, 1); assert.equal(s.clicks.length, 0);
    s.monitoringStatus.value = 'reviewed';
    s.window.axios.get = async () => { throw { response: { data: new Blob([JSON.stringify({ message: 'Export temporarily unavailable.' })]) } }; };
    await s.downloadDataset(s.datasets.value[0]);
    assert.equal(s.exportError.value, 'Export temporarily unavailable.'); assert.equal(s.monitoringStatus.value, 'reviewed');
    assert.equal(s.exportMessage.value, ''); assert.equal(s.exporting.value, ''); assert.equal(s.clicks.length, 0);
});

test('export downloads are single-flight, status-filtered and refresh only after CSV success', async () => {
    const s = await exportsDesk(), pending = deferred(); let calls = 0, options;
    s.window.axios.get = async (url, config) => { calls++; options ??= config; return calls === 1 ? pending.promise : { data: { datasets: [dataset], recent_exports: [] } }; };
    s.monitoringStatus.value = 'attention'; const active = s.downloadDataset(s.datasets.value[0]); await s.downloadDataset(s.datasets.value[0]);
    assert.equal(calls, 1); assert.equal(s.exporting.value, 'monitoring'); assert.equal(options.params.status, 'attention');
    pending.resolve({ data: new Blob(['CSV']), headers: { 'content-type': 'text/csv', 'content-disposition': 'attachment; filename="recipient-monitoring-attention.csv"' } }); await active;
    assert.equal(calls, 2); assert.deepEqual(s.clicks, ['recipient-monitoring-attention.csv']); assert.match(s.exportMessage.value, /browser has started/);
    s.cleanup.forEach((fn) => fn()); assert.deepEqual(s.revoked, ['blob:demo']);
});

test('export desk rejects non-CSV login/error pages instead of downloading them', async () => {
    const s = await exportsDesk(); s.window.axios.get = async () => ({ data: new Blob(['Login']), headers: { 'content-type': 'text/html' } });
    await s.downloadDataset(s.datasets.value[0]); assert.equal(s.clicks.length, 0); assert.match(s.exportError.value, /could not be completed/);
});

test('invalid or foreign export links show errors without making requests', async () => {
    for (const url of ['https://example.test/admin/export/users', 'http://[invalid', '/unrelated']) {
        const s = await exportsDesk(); s.datasets.value[0].download_url = url;
        await s.downloadDataset(s.datasets.value[0]); assert.equal(s.gets.length, 1); assert.match(s.exportError.value, /valid download link/);
    }
});

test('unmounted exports abort requests and never start late downloads', async () => {
    const s = await exportsDesk(), pending = deferred(); let signal;
    s.window.axios.get = (url, options) => { signal = options.signal; return pending.promise; };
    const active = s.downloadDataset(s.datasets.value[0]); s.cleanup.forEach((fn) => fn()); assert.equal(signal.aborted, true);
    pending.resolve({ data: new Blob(['CSV']), headers: { 'content-type': 'text/csv' } }); await active; assert.deepEqual(s.clicks, []);
});

test('tables preserve full text, stable columns, accessible actions and the shared retry frame', () => {
    for (const [name] of directories) {
        const source = read(name); assert.ok(!source.includes('truncate')); assert.ok(source.includes('<colgroup>'));
        assert.match(source, /:loading="loading" :error="errorMessage" @retry=/); assert.match(source, /onBeforeUnmount/);
    }
    assert.match(read('finance/FinancePaymentsPage'), /gcash: 'GCash'/);
    assert.match(read('records/RecordsActivityRecordPage'), /whitespace-pre-wrap/);
});
