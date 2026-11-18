import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';

const read = file => readFileSync(`resources/js/pages/provider/releases/${file}`, 'utf8');

for (const file of ['BenefitReleaseRecordPage.vue', 'BenefitReleaseSchedulePage.vue']) {
    test(`${file} compiles with separate scheduling and distribution checks`, () => {
        const { descriptor, errors } = parse(read(file), { filename: file });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content,
            compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

function harness(readiness, extra = {}) {
    const source = parse(read('BenefitReleaseRecordPage.vue')).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const posts = [];
    const window = { location: { search: '' }, axios: { post: (...args) => { posts.push(args); throw new Error('Unexpected write'); } } };
    const document = { getElementById: () => ({ dataset: { releaseId: 1 } }) };
    const run = new Function('ref', 'computed', 'watch', 'onMounted', 'window', 'document', `${source}
        return { release, selectRecord, canReleaseSelected, releaseHold, resultForm, confirmation, askToSaveResult, confirmAction, stateLabel, earliestTransferOn };`);
    const state = run(ref, computed, () => {}, () => {}, window, document);
    const record = { id: 1, name: 'Preview recipient', status: 'scheduled', release_readiness: readiness, ...extra.record };
    state.release.value = { records: [record], release_method: 'in_person', release_at: '2000-01-01T09:00', ...extra.release };
    state.selectRecord(record);
    return { ...state, posts, record };
}

test('onboarding holds default to preparation and block both the first submit and stale confirmation', async () => {
    const state = harness({ eligible: false, reason: 'Complete the final agreement first.',
        onboarding: { can_distribute: false }, onboarding_url: '/provider/applications/1?workspace=recipient&section=onboarding' });
    assert.equal(state.canReleaseSelected.value, false);
    assert.equal(state.resultForm.value.status, 'prepared');
    assert.equal(state.releaseHold.value.owner, 'Recipient officer or manager');
    assert.equal(state.releaseHold.value.action.label, 'Open onboarding');
    assert.equal(state.stateLabel(state.record), 'Final agreement pending');
    state.resultForm.value.status = 'released';
    state.askToSaveResult();
    assert.equal(state.confirmation.value, null);
    state.confirmation.value = { type: 'result', recordId: 1, form: { status: 'released' } };
    await state.confirmAction();
    assert.deepEqual(state.posts, []);
    state.resultForm.value.status = 'prepared';
    state.askToSaveResult();
    assert.equal(state.confirmation.value.form.status, 'prepared');
});

test('release-only staff get the responsible role rather than an inaccessible onboarding action', () => {
    const state = harness({ eligible: false, onboarding: { can_distribute: false }, onboarding_url: null });
    assert.equal(state.releaseHold.value.owner, 'Recipient officer or manager');
    assert.equal(state.releaseHold.value.action, null);
});

test('completed onboarding still respects monitoring, payout approval and scheduled time', () => {
    const monitor = harness({ eligible: false, blocked_cycle_id: 4, monitoring_url: '/provider/check-ins/4', onboarding: { can_distribute: true } });
    assert.equal(monitor.canReleaseSelected.value, false);
    assert.equal(monitor.releaseHold.value.owner, 'Monitoring officer');
    assert.equal(monitor.releaseHold.value.action.label, 'Open check-in');
    const digital = harness({ eligible: true, onboarding: { can_distribute: true } }, {
        release: { release_method: 'bank_transfer' }, record: { payout: { status: 'submitted' } },
    });
    assert.equal(digital.canReleaseSelected.value, false);
    digital.release.value.records[0].payout.status = 'approved';
    assert.equal(digital.canReleaseSelected.value, true);
    digital.release.value.release_at = '2999-01-01T09:00';
    assert.equal(digital.canReleaseSelected.value, false);
});

test('eligible distribution still opens the existing confirmation without sending a request', () => {
    const state = harness({ eligible: true, onboarding: { can_distribute: true } });
    assert.equal(state.resultForm.value.status, 'released');
    state.askToSaveResult();
    assert.equal(state.confirmation.value.type, 'result');
    assert.deepEqual(state.posts, []);
});

test('transfer dates begin no earlier than both payout approval and agreement completion', () => {
    const state = harness({ eligible: true, onboarding: { can_distribute: true, completed_on: '2026-11-18' } }, {
        release: { release_method: 'bank_transfer' }, record: { payout: { status: 'approved', approved_on: '2026-11-17' } },
    });
    assert.equal(state.earliestTransferOn.value, '2026-11-18');
    state.release.value.records[0].payout.approved_on = '2026-11-19';
    assert.equal(state.earliestTransferOn.value, '2026-11-19');
});

test('scheduling remains selectable when only final agreement is pending', () => {
    const source = read('BenefitReleaseSchedulePage.vue');
    assert.match(source, /:disabled="!candidate.eligible"/);
    assert.match(source, /candidate.eligible && candidate.onboarding\?\.can_distribute === false \? 'Can schedule'/);
    assert.match(source, /Final agreement must be completed before distribution/);
    assert.match(source, /attendance does not confirm agreement completion or benefit receipt/);
    assert.doesNotMatch(source, /:disabled="[^"]*onboarding/);
});
