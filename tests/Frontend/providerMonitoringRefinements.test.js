import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';

const read = (path) => readFileSync(new URL(`../../resources/js/${path}`, import.meta.url), 'utf8');
const pages = [
    'monitoring/MonitoringDirectoryPage.vue', 'monitoring/MonitoringPlanPage.vue',
    'monitoring/MonitoringCheckInRecordPage.vue', 'releases/BenefitReleaseDirectoryPage.vue',
    'releases/BenefitReleaseSchedulePage.vue', 'releases/BenefitReleaseRecordPage.vue',
];
for (const page of [...pages.map((page) => `pages/provider/${page}`), 'components/MonitoringCaseHistoryModal.vue']) {
    test(`${page} compiles with monitoring refinements`, () => {
        const source = read(page);
        const { descriptor, errors } = parse(source, { filename: page });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: page });
        const template = compileTemplate({ id: page, filename: page, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
        if (page.startsWith('pages/')) {
            assert.match(source, /provider-monitoring-workspace/);
            assert.doesNotMatch(descriptor.template.content.replace(/<i\b[^>]*>/g, ''), /text-\[(?:9|10|11)px\]|\btruncate\b/);
        }
    });
}

test('both queues keep filters and pagination while reserving action columns', () => {
    for (const [page, endpoint, rows] of [
        ['monitoring/MonitoringDirectoryPage.vue', '/provider/workspaces/monitoring/data', 'checkIns'],
        ['releases/BenefitReleaseDirectoryPage.vue', '/provider/workspaces/releases/data', 'releases'],
    ]) {
        const source = read(`pages/provider/${page}`);
        for (const token of [endpoint, 'activeQueue', 'selectedProgram', 'pagination', 'requestVersion', 'staff-table-viewport', '<colgroup>', 'min-w-[56rem]']) assert.ok(source.includes(token), token);
        assert.ok(source.includes(`!${rows}.length && !errorMessage`));
        assert.doesNotMatch(source, /<th[^>]*w-\[\d+%\]|min-w-\[(?:70|72)rem\]|line-clamp-1/);
    }
});

test('recipient pickers retain photos and selection semantics without growing the entire page', () => {
    for (const page of ['monitoring/MonitoringCheckInRecordPage.vue', 'releases/BenefitReleaseRecordPage.vue']) {
        const source = read(`pages/provider/${page}`);
        for (const token of ['monitoring-recipient-picker', 'profile_photo_url', ':aria-pressed=', 'Choose a recipient', 'WorkspaceTextSummary']) assert.ok(source.includes(token), `${page}: ${token}`);
        assert.doesNotMatch(source, /min-w-\[14rem\]/);
    }
});

test('plan and outcome dialogs have their own scrolling, focus handling and visible errors', () => {
    const plan = read('pages/provider/monitoring/MonitoringPlanPage.vue');
    assert.equal((plan.match(/useModalAccessibility\(/g) ?? []).length, 2);
    for (const id of ['requirement-editor-title', 'check-in-editor-title']) assert.ok(plan.includes(`aria-labelledby="${id}"`));
    for (const token of ['<Teleport to="body">', 'monitoring-dialog-body', 'requirementError', 'closeCheckInEditor', 'if (isPublishingCheckIn.value) return;']) assert.ok(plan.includes(token), token);
    const record = read('pages/provider/monitoring/MonitoringCheckInRecordPage.vue');
    for (const token of ['handleCaseDecisionKeydown', 'caseDecisionError', 'monitoring-dialog-body', 'provider-workspace', 'caseDecisionBusy']) assert.ok(record.includes(token), token);
    const history = read('components/MonitoringCaseHistoryModal.vue');
    assert.match(history, /providerWorkspace: \{ type: Boolean, default: false \}/);
    assert.match(history, /useModalAccessibility\(\(\) => props.open/);
});

test('existing monitoring and distribution decision gates are preserved', () => {
    const monitor = read('pages/provider/monitoring/MonitoringCheckInRecordPage.vue');
    for (const token of ['canWaiveCase', 'isTerminalCase', 'ready_to_close', 'resolve_overdue', 'hasCompletedFollowUp', 'requestConfirmation', 'approved_due_at', 'requires_file']) assert.ok(monitor.includes(token));
    const plan = read('pages/provider/monitoring/MonitoringPlanPage.vue');
    for (const token of ['if (!canEdit.value || isSaving.value) return;', 'canPublishCheckIn', 'form.value.requirements.length', 'savedPlan?.ends_on']) assert.ok(plan.includes(token));
    const release = read('pages/provider/releases/BenefitReleaseRecordPage.vue');
    for (const token of ['canMarkReleased', ':disabled="!canMarkReleased"', 'release.requires_original_verification', 'receipt_proof', 'askToSaveResult', 'askToResolveIssue']) assert.ok(release.includes(token));
});

test('new layout rules are scoped and keep desktop forms full-width', () => {
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    const rules = css.split('/* Keep monitoring and distribution refinements out of applicant records. */')[1].split('/* Refinements stay inside')[0];
    for (const match of rules.matchAll(/([^{}]+)\{/g)) for (const selector of match[1].trim().split(/,\s*\n/)) assert.match(selector.trim(), /^\.provider-monitoring-workspace/);
    for (const token of ['max-height: 16rem', 'max-height: 24rem', 'calc(100dvh - 3rem)', 'overflow-wrap: anywhere', 'overscroll-behavior: contain', 'focus-visible']) assert.ok(rules.includes(token), token);
    assert.doesNotMatch(rules, /@media|gradient/);
});

function scheduleHarness() {
    const source = parse(read('pages/provider/releases/BenefitReleaseSchedulePage.vue')).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const requests = [];
    const watchers = [];
    const posts = [];
    const window = { location: { search: '' }, axios: {
        get: () => new Promise((resolve, reject) => requests.push({ resolve, reject })),
        post: (...args) => { posts.push(args); return Promise.reject(new Error('Unexpected write')); },
    }, clearTimeout() {}, setTimeout() { return 1; } };
    const run = new Function('ref', 'computed', 'watch', 'onMounted', 'onBeforeUnmount', 'window', 'URLSearchParams', `${source}\nreturn { loadScheduleData, saveRelease, isLoading, errorMessage, eligibilityError, selectedProgram, selectedRecipients, candidates, confirmation };`);
    const state = run(ref, computed, (source, callback) => watchers.push(callback), () => {}, () => {}, window, URLSearchParams);
    return { ...state, requests, watchers, posts };
}
const payload = (id) => ({ data: { programs: [], candidates: [{ application_id: id, name: `Recipient ${id}`, eligible: true }] } });

test('release eligibility ignores out-of-order success responses', async () => {
    const state = scheduleHarness();
    state.selectedProgram.value = '1';
    const first = state.loadScheduleData();
    state.selectedProgram.value = '2';
    const second = state.loadScheduleData();
    state.requests[1].resolve(payload(22));
    await second;
    state.requests[0].resolve(payload(11));
    await first;
    assert.equal(state.candidates.value[0].application_id, 22);
    assert.equal(state.isLoading.value, false);
});

test('stale eligibility errors cannot replace the current program results', async () => {
    const state = scheduleHarness();
    state.selectedProgram.value = '1';
    const first = state.loadScheduleData();
    const second = state.loadScheduleData();
    state.requests[1].resolve(payload(22));
    await second;
    state.requests[0].reject({ response: { data: { message: 'An outdated error' } } });
    await first;
    assert.equal(state.errorMessage.value, '');
    assert.equal(state.candidates.value[0].application_id, 22);
});

test('changing the release date invalidates the previous response before the debounce ends', async () => {
    const state = scheduleHarness();
    const loading = state.loadScheduleData();
    state.watchers[1]();
    state.requests[0].resolve(payload(11));
    await loading;
    assert.equal(state.candidates.value.length, 0);
    assert.equal(state.isLoading.value, true);
});

test('release scheduling cannot save while eligibility is loading or failed', async () => {
    const state = scheduleHarness();
    state.selectedProgram.value = '1';
    state.selectedRecipients.value = [11];
    state.confirmation.value = true;
    await state.saveRelease();
    assert.equal(state.confirmation.value, false);
    assert.equal(state.posts.length, 0);
    state.isLoading.value = false;
    state.eligibilityError.value = 'Eligibility unavailable';
    await state.saveRelease();
    assert.equal(state.posts.length, 0);
});

test('a save validation error does not prevent correcting and retrying the form', async () => {
    const state = scheduleHarness();
    state.selectedProgram.value = '1';
    state.selectedRecipients.value = [11];
    state.isLoading.value = false;
    state.errorMessage.value = 'Add a valid release title.';
    await state.saveRelease();
    assert.equal(state.posts.length, 1);
});
