import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createStaffQueueState } from '../../resources/js/support/staffQueueState.js';
import { compileScript, parse } from '@vue/compiler-sfc';
import { computed, createSSRApp, effectScope, ref, watch } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { useAdminResource } from '../../resources/js/composables/useAdminResource.js';
import { useConcernCollection } from '../../resources/js/composables/useConcernCollection.js';
import { useWithdrawalList } from '../../resources/js/composables/useWithdrawalList.js';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const reviews = 'pages/provider/reviews/ApplicationReviewDirectoryPage.vue';
const programs = 'pages/provider/programs/ProgramDirectoryPage.vue';
const billing = 'pages/provider/billing/BillingRequestDirectoryPage.vue';

function harness(path, pathname, exposed) {
    const timers = new Map(), unmount = [];
    let timerId = 0;
    const window = {
        location: { pathname, search: '' }, axios: {},
        clearTimeout: (id) => timers.delete(id),
        setTimeout: (callback) => { timers.set(++timerId, callback); return timerId; },
    };
    const scope = effectScope();
    const source = parse(read(path)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const deps = { createStaffQueueState, computed, ref, watch, window, onMounted() {}, onBeforeUnmount: (fn) => unmount.push(fn) };
    const state = scope.run(() => new Function(...Object.keys(deps), `${source}\nreturn {${exposed}};`)(...Object.values(deps)));
    return { ...state, window, runTimer: () => [...timers.values()][0]?.(),
        dispose: () => { unmount.forEach((fn) => fn()); scope.stop(); } };
}

test('admin Retry repeats the failed page and filters and ignores a duplicate click', async () => {
    const calls = [];
    let finish;
    const resource = useAdminResource(async ({ signal }, query) => {
        calls.push({ query, signal });
        if (calls.length === 1) throw new Error('Offline');
        return new Promise((resolve) => { finish = resolve; });
    });
    const query = { page: 3, status: 'pending', search: 'sample' };
    await resource.load(query);
    assert.ok(resource.errorMessage.value);
    const retry = resource.retry();
    assert.equal(resource.retry(), null);
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[1].query, query);
    assert.notEqual(calls[1].signal, calls[0].signal);
    finish({ items: [{ id: 1 }] });
    await retry;
    assert.equal(resource.errorMessage.value, '');
    assert.deepEqual(resource.data.value.items, [{ id: 1 }]);
    assert.equal(resource.retry(), null);
});

test('provider review filters invalidate in-flight results before the debounce and block stale claims', async () => {
    const state = harness(reviews, '/provider/workspaces/reviews/unassigned', 'loadApplications, claimAndOpen, search, selectedProgram, isLoading, errorMessage, claimError, applications, resultRange, retry: () => loadApplications(requestedPage)');
    const old = { id: 1, detail_url: '/provider/application/1' };
    const calls = [], writes = [];
    try {
        state.window.axios.patch = async (...args) => { writes.push(args); throw new Error('Claim denied'); };
        state.window.axios.get = async (_, { params }) => { calls.push(params); return { data: { applications: [old] } }; };
        await state.loadApplications();
        let resolveOld;
        state.window.axios.get = () => new Promise((resolve) => { resolveOld = resolve; });
        const previous = state.loadApplications(2);
        state.search.value = 'new search';
        assert.equal(state.resultRange.value, 'Updating results...');
        resolveOld({ data: { applications: [{ id: 2 }] } });
        await previous;
        assert.equal(state.isLoading.value, true);
        assert.equal(state.applications.value[0].id, old.id);
        await state.claimAndOpen(old);
        state.window.axios.get = async (_, { params }) => { calls.push(params); throw new Error('Offline'); };
        await state.runTimer();
        assert.equal(state.resultRange.value, 'Results unavailable');
        assert.equal(state.isLoading.value, false);
        await state.claimAndOpen(old);
        assert.equal(writes.length, 0);

        await state.loadApplications(3);
        const failedQuery = calls.at(-1);
        state.window.axios.get = async (_, { params }) => { calls.push(params); return { data: { applications: [{ id: 3 }] } }; };
        await state.retry();
        assert.deepEqual(calls.at(-1), failedQuery);
        assert.equal(calls.at(-1).page, 3);
        assert.equal(state.errorMessage.value, '');
        assert.equal(state.applications.value[0].id, 3);
        await state.claimAndOpen(state.applications.value[0]);
        assert.equal(writes.length, 1);
        assert.match(state.claimError.value, /could not be claimed/);
        assert.equal(state.errorMessage.value, '');
    } finally { state.dispose(); }
});

test('program Retry repeats submitted search and sort, not unapplied input edits', async () => {
    const state = harness(programs, '/provider/programs/published', 'loadPrograms, retryPrograms, search, sort, errorMessage');
    const requests = [];
    try {
        state.search.value = 'previous';
        state.sort.value = 'deadline';
        state.window.axios.get = async (_, config) => { requests.push(config.params); throw new Error('Offline'); };
        await state.loadPrograms(2);
        state.search.value = 'not yet submitted';
        state.sort.value = 'updated';
        state.window.axios.get = async (_, config) => { requests.push(config.params); return { data: {} }; };
        await state.retryPrograms();
        assert.deepEqual(requests[1], requests[0]);
        assert.equal(requests[1].page, 2);
        assert.equal(state.errorMessage.value, '');
        assert.equal(state.search.value, 'not yet submitted');
    } finally { state.dispose(); }
});

test('billing separates payment errors from list failures and a list Retry never repeats a payment sync', async () => {
    const state = harness(billing, '/provider/workspaces/billing/requests', 'loadEntries, refreshPayment, errorMessage, paymentError, retry: () => loadEntries(requestedPage)');
    const writes = [];
    try {
        state.window.axios.get = async () => ({ data: { entries: [{ id: 1 }] } });
        await state.loadEntries();
        state.window.axios.post = async (...args) => { writes.push(args); throw new Error('Sync unavailable'); };
        const entry = { id: 1, reference_number: 'sample' };
        await state.refreshPayment(entry);
        assert.ok(state.paymentError.value);
        assert.equal(state.errorMessage.value, '');
        state.window.axios.get = async () => { throw new Error('List unavailable'); };
        await state.loadEntries(2);
        assert.ok(state.errorMessage.value);
        assert.equal(state.paymentError.value, '');
        await state.refreshPayment(entry);
        await state.retry();
        assert.equal(writes.length, 1);
    } finally { state.dispose(); }
});

for (const factory of [useConcernCollection, useWithdrawalList]) {
    test(`${factory.name} retries the failed page without showing old rows`, async () => {
        const pages = [];
        const state = factory({ endpoint: () => '/queue', fetcher: async (_, { params }) => {
            pages.push(params.page);
            if (pages.length === 1) throw new Error('Offline');
            return { data: {} };
        } });
        await state.load(4);
        assert.deepEqual(state.rows.value, []);
        assert.ok(state.error.value);
        await state.retry();
        assert.deepEqual(pages, [4, 4]);
        assert.equal(state.error.value, '');
    });
}

const moduleUrl = (source) => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const vueUrl = import.meta.resolve('vue');
function compile(path, imports = {}) {
    const { descriptor } = parse(read(path));
    return compileScript(descriptor, { id: path, inlineTemplate: true }).content
        .replace(/from (['"])(.*?)\1/g, (match, quote, name) => (name === 'vue' || imports[name])
            ? `from ${JSON.stringify(name === 'vue' ? vueUrl : imports[name])}` : match);
}

const alertUrl = moduleUrl(compile('components/StaffTableLoadError.vue'));
test('provider table refresh feedback is accessible and escapes server messages', async () => {
    const { default: Alert } = await import(alertUrl);
    const html = await renderToString(createSSRApp(Alert, { message: '<script>Offline</script>' }));
    assert.match(html, /role="alert"/);
    assert.match(html, /&lt;script&gt;Offline&lt;\/script&gt;/);
    assert.match(html, /<button type="button"[^>]*>.*Retry/s);
});

const reviewRow = { id: 1, applicant: { name: 'Earlier applicant', verification_label: 'Profile verified' }, program: { title: 'Earlier program' },
    evidence: { state: 'ready', label: 'Files ready' }, eligibility_score: 75,
    assignment: { state: 'unassigned', label: 'Unassigned' }, detail_url: '/provider/record/1' };

async function renderReviewQueue(applications, failRefresh = false) {
    const source = compile(reviews, {
        '../ProviderSidebar.vue': moduleUrl('export default { render: () => null }'),
        '../../../components/StaffTableIdentity.vue': moduleUrl(compile('components/StaffTableIdentity.vue')),
        '../../../components/StaffTableLoadError.vue': alertUrl,
        '../../../support/staffQueueState.js': new URL('../../resources/js/support/staffQueueState.js', import.meta.url).href,
    }).replace(/\bonMounted,\s*/, '');
    const fixture = `
            import { onServerPrefetch } from ${JSON.stringify(vueUrl)};
            const onMounted = (load) => onServerPrefetch(async () => { await load(); await load(); });
            let calls = 0;
            const window = {
                location: { pathname: '/provider/workspaces/reviews/unassigned', search: '' },
                clearTimeout() {},
                axios: { get: async () => {
                    if (++calls === 2 && ${failRefresh}) throw new Error('Offline');
                    return { data: { applications: ${JSON.stringify(applications)} } };
                } },
            };
        `;
    const { default: Page } = await import(moduleUrl(fixture + source));
    return renderToString(createSSRApp(Page));
}

test('the rendered provider review table hides previously loaded rows after refresh failure', async () => {
    for (const failRefresh of [false, true]) {
        const html = await renderReviewQueue([reviewRow], failRefresh);
        assert.match(html, /staff-table-viewport/);
        if (failRefresh) {
            assert.match(html, /Table results unavailable/);
            assert.match(html, /Retry/);
            assert.doesNotMatch(html, /Earlier applicant|>Claim</);
        } else {
            assert.match(html, /Earlier applicant/);
            assert.match(html, />Claim</);
            assert.match(html, /aria-label="Claim review for Earlier applicant"/);
            assert.doesNotMatch(html, /Table results unavailable/);
        }
    }
});

test('the review queue presents saved match as advisory and distinguishes zero from no score', async () => {
    const html = await renderReviewQueue([
        reviewRow,
        { ...reviewRow, id: 2, applicant: { name: 'Zero score', verification_label: 'Profile verified' }, eligibility_score: 0 },
        { ...reviewRow, id: 3, applicant: { name: 'No score', verification_label: 'Profile pending' }, eligibility_score: null },
        { ...reviewRow, id: 4, applicant: { name: 'Unavailable score', verification_label: 'Profile not verified' }, eligibility_score: undefined },
    ]);
    assert.match(html, /<th[^>]*>\s*Saved match.*?Advisory only.*?<\/th>/s);
    const row = (name) => html.match(/<tr\b[^>]*>[\s\S]*?<\/tr>/g).find((content) => content.includes(`>${name}</p>`));
    assert.match(row('Earlier applicant'), />75%<\/p>.*?>Profile verified</s);
    assert.match(row('Zero score'), />0%<\/p>.*?>Profile verified</s);
    assert.match(row('No score'), />Not calculated<\/p>.*?>Profile pending</s);
    assert.match(row('Unavailable score'), />Not calculated<\/p>.*?>Profile not verified</s);
    assert.doesNotMatch(html, /Check required|undefined%|null%/);
});

test('provider review rows keep evidence progress and nonzero wait time without filler', async () => {
    const html = await renderReviewQueue([
        {
            ...reviewRow,
            applicant: { ...reviewRow.applicant, education: 'Senior High School' },
            evidence: { ...reviewRow.evidence, required: 4, accepted: 2 },
            waiting_days: 0,
        },
        {
            ...reviewRow,
            id: 2,
            evidence: { ...reviewRow.evidence, required: 0, accepted: 0 },
            waiting_days: 3,
        },
    ]);
    assert.match(html, />2 of 4 accepted</);
    assert.match(html, />3 days waiting</);
    assert.doesNotMatch(html, /0 days waiting|0 of 0 accepted|Senior High School/);
});

async function renderAdminTable(path, pathname, items) {
    const passthrough = moduleUrl('export default { inheritAttrs: false, render() { return this.$slots.default?.(); } }');
    const blank = moduleUrl('export default { render: () => null }');
    const resource = (name) => new URL(`../../resources/js/${name}`, import.meta.url).href;
    const source = compile(path, {
        '../../../components/StaffTableIdentity.vue': moduleUrl(compile('components/StaffTableIdentity.vue')),
        '../../../components/workspace-dashboard/WorkspaceTextSummary.vue': moduleUrl(compile('components/workspace-dashboard/WorkspaceTextSummary.vue')),
        '../components/AdminWorkspacePage.vue': passthrough,
        '../components/AdminTableFrame.vue': passthrough,
        '../components/AdminWorkspaceHeader.vue': blank,
        '../components/AdminEmptyState.vue': blank,
        '../../../composables/useAdminResource.js': resource('composables/useAdminResource.js'),
        '../../../support/staffQueueState.js': resource('support/staffQueueState.js'),
        '../../../support/display.js': resource('support/display.js'),
        '../../../support/display': resource('support/display.js'),
        '../../../support/adminReviewPresentation.js': resource('support/adminReviewPresentation.js'),
    }).replace(/\bonMounted,\s*/, '');
    const fixture = `
        import { onServerPrefetch as onMounted } from ${JSON.stringify(vueUrl)};
        const window = {
            location: { pathname: ${JSON.stringify(pathname)}, search: '' },
            axios: { get: async () => ({ data: { items: ${JSON.stringify(items)} } }) },
        };
    `;
    const { default: Page } = await import(moduleUrl(fixture + source));
    return renderToString(createSSRApp(Page));
}

const adminReview = 'pages/admin/reviews/ReviewQueuePage.vue';
const renderedCells = (html) => html.match(/<td\b[^>]*>[\s\S]*?<\/td>/g);

test('admin selection oversight separates session state, evidence warnings, and oversight status', async () => {
    const html = await renderAdminTable(adminReview, '/admin/workspaces/reviews/selection-activities', [{
        id: 5, title: 'Entrance exam', program: 'Science grant', provider: 'Provider organization',
        stage_label: 'Exam', schedule: 'Nov 18, 2026', status: 'completed', oversight_status: 'attention',
        primary_flag: 'Required session proof is missing.', active_assignments_count: 1, current_evidence_count: 0,
        review_url: '/admin/workspaces/reviews/selection-activities/5',
    }]);
    const [identity, context, status, proof, action] = renderedCells(html);
    assert.match(identity, /Science grant/);
    assert.match(context, /Nov 18, 2026 · Completed/);
    assert.match(status, />Needs attention</);
    assert.doesNotMatch(status, /Completed|proof is missing/);
    assert.match(proof, /1 applicant · 0 files.*Required session proof is missing/s);
    assert.match(action, /href="\/admin\/workspaces\/reviews\/selection-activities\/5\?return_to=/);
});

test('admin monitoring rows keep the due date, action owner, and overdue wait without repeating the flag', async () => {
    const html = await renderAdminTable(adminReview, '/admin/workspaces/reviews/monitoring', [{
        id: 6, recipient: 'Current recipient', cycle_title: 'First check-in', due_on: 'Nov 05, 2026',
        oversight_status: 'attention', primary_flag: 'Check-in is past due', wait_label: '3 days overdue',
        next_action_owner: 'Provider', next_action_label: 'Resolve overdue check-in',
        review_url: '/admin/workspaces/reviews/monitoring/7?case=6',
    }]);
    const [, context, status, nextAction, action] = renderedCells(html);
    assert.match(context, /Due Nov 05, 2026/);
    assert.match(status, />Needs attention</);
    assert.match(nextAction, /Provider: Resolve overdue check-in.*3 days overdue/s);
    assert.match(action, /case=6&amp;return_to=/);
    assert.doesNotMatch(html, /Check-in is past due|Record details/);
});

test('admin benefit rows keep the receipt preview and the open issue next to it', async () => {
    const html = await renderAdminTable(adminReview, '/admin/workspaces/reviews/benefits', [{
        id: 7, recipient: 'Current recipient', release: 'Initial stipend', status: 'released', oversight_status: 'attention',
        primary_flag: 'Open recipient report', receipt_url: '/admin/receipts/7', receipt_name: 'stipend-receipt.pdf',
        recipient_url: '/admin/workspaces/reviews/applicants/7',
    }]);
    const [, context, status, receipt, action] = renderedCells(html);
    assert.match(context, />Released</);
    assert.doesNotMatch(status, /Open recipient report/);
    assert.match(receipt, /href="\/admin\/receipts\/7" data-file-preview="true" data-file-name="stipend-receipt.pdf"/);
    assert.match(receipt, /Open recipient report/);
    assert.match(action, /aria-label="Open recipient profile for Current recipient"/);
});

test('admin finance exceptions make the reason available on demand, outside the status cell', async () => {
    const html = await renderAdminTable('pages/admin/finance/FinanceExceptionsPage.vue', '/admin/workspaces/finance/exceptions', [{
        id: 8, reference_number: 'PAY-8', provider: 'Example foundation', status: 'failed',
        reason: 'Payment timed out. <retry>', amount: 10000, currency: 'PHP',
    }]);
    const [reference, , , status] = renderedCells(html);
    assert.match(reference, /<details[^>]*>.*Read reason.*Payment timed out\. &lt;retry&gt;/s);
    assert.doesNotMatch(reference, /<details[^>]*\bopen\b|line-clamp-2/);
    assert.match(status, />Failed</);
    assert.doesNotMatch(status, /Payment timed out/);
});
