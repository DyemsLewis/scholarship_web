import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import * as evaluation from '../../resources/js/support/stageEvaluation.js';

const read = path => readFileSync(new URL('../../resources/js/' + path, import.meta.url), 'utf8');
const modalPath = 'components/StageEvaluationEntryDialog.vue';
const exam = [{ key: 'knowledge', label: 'Mathematics', weight: 40, maximum_score: 40 }, { key: 'reasoning', label: 'Reasoning', weight: 40, maximum_score: 40 }, { key: 'application', label: 'Problem solving', weight: 20, maximum_score: 20 }];
const rubric = [{ key: 'academic_merit', label: 'Academic merit', weight: 55 }, { key: 'financial_need', label: 'Financial need', weight: 45 }];
function harness(path, bindings, exposed) {
    const source = parse(read(path)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const deps = { ref, computed, watch, ...evaluation, useModalAccessibility: () => ({ handleKeydown() {} }), onMounted() {}, ...bindings };
    return new Function(...Object.keys(deps), source + '\nreturn {' + exposed + '};')(...Object.values(deps));
}
function modal(stage, modelValue, criteria) {
    const props = reactive({ open: false, modelValue, criteria, stage, disabled: false }), events = [];
    return { props, events, ...harness(modalPath, { defineProps: () => props, defineEmits: () => (name, payload) => events.push([name, payload]) }, 'draft, valid, apply, close') };
}

for (const path of [modalPath, 'pages/provider/reviews/ApplicationReviewRecordPage.vue', 'pages/provider/selection/SelectionActivityRecordPage.vue']) {
    test(path.split('/').at(-1) + ' compiles with evaluation modals', () => {
        const { descriptor, errors } = parse(read(path), { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        assert.deepEqual(compileTemplate({ id: path, filename: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}
test('screening completeness distinguishes missing scores from valid zero and retains score bounds', () => {
    for (const score of ['', ' ', null, undefined, false, -1, 101, NaN, Infinity]) assert.equal(evaluation.screeningEvaluationStatus({ academic_merit: score, financial_need: 90 }, rubric).valid, false);
    assert.deepEqual(evaluation.screeningEvaluationStatus({ academic_merit: 0, financial_need: '90' }, rubric), { completed: 2, total: 2, valid: true });
    assert.equal(evaluation.screeningEvaluationStatus({}, []).valid, true);
});
for (const [stage, criteria, scores, total] of [['exam', exam, [36, 32, 18], 86], ['interview', exam.map((row, i) => ({ ...row, weight: [40, 30, 30][i], maximum_score: 5 })), [4.5, 4, 5], 90]]) {
    test(stage + ' Cancel discards independent edits; apply returns a draft without saving a result', async () => {
        const original = evaluation.stageEvaluationForm({ criteria }), before = JSON.stringify(original);
        const state = modal(stage, original, criteria);
        state.props.open = true; await nextTick();
        assert.equal(state.valid.value, false);
        criteria.forEach((row, i) => { state.draft.value.criteria_scores[row.key].score = scores[i]; });
        assert.equal(state.valid.value, true);
        assert.equal(JSON.stringify(original), before);
        state.close(); assert.deepEqual(state.events, [['close', undefined]]);
        state.props.open = false; await nextTick(); state.props.open = true; await nextTick();
        assert.equal(state.draft.value.criteria_scores[criteria[0].key].score, '');
        criteria.forEach((row, i) => { state.draft.value.criteria_scores[row.key].score = scores[i]; });
        state.apply(); const applied = state.events.at(-1);
        assert.equal(applied[0], 'apply'); assert.equal(evaluation.stageEvaluationResult(applied[1], criteria, stage).percentage, total);
        assert.equal(JSON.stringify(original), before);
        applied[1].criteria_scores[criteria[0].key].score = 0;
        assert.equal(state.draft.value.criteria_scores[criteria[0].key].score, scores[0]);
    });
}
test('total exam entry retains its score/max; closed and disabled modals cannot apply', async () => {
    const state = modal('exam', evaluation.stageEvaluationForm(), []);
    state.apply(); assert.equal(state.events.length, 0);
    state.props.open = true; await nextTick(); state.draft.value.score = 43; state.draft.value.maximum_score = 50;
    assert.equal(state.valid.value, true);
    state.props.disabled = true; state.apply(); state.close(); assert.equal(state.events.length, 0);
    state.props.disabled = false; state.apply(); assert.equal(evaluation.stageEvaluationResult(state.events[0][1]).percentage, 86);
});
test('screening modal rejects incomplete/invalid scores and emits only rubric keys', async () => {
    const state = modal('screening', { academic_merit: '', financial_need: '', unrelated: 90 }, rubric);
    state.props.open = true; await nextTick(); state.apply(); assert.equal(state.events.length, 0);
    state.draft.value.academic_merit = '90'; state.draft.value.financial_need = 101; assert.equal(state.valid.value, false);
    state.draft.value.financial_need = 0; state.apply();
    assert.deepEqual(state.events[0], ['apply', { academic_merit: 90, financial_need: 0 }]);
    assert.equal(state.props.modelValue.academic_merit, '');
});
test('selection requires an applied score and retains atomic result submission and unscored formal checks', () => {
    const state = harness('pages/provider/selection/SelectionActivityRecordPage.vue', {
        document: { getElementById: () => ({ dataset: { applicationId: 3 } }) }, window: { location: { search: '' } }, decisionReasonOptions: [],
    }, 'application, readiness, resultForm, showEvaluationEntry, canSubmitResult, applyEvaluation');
    state.application.value = { workflow_stage: 'exam', selection_evaluation: { policy_frozen: true, stages: [{ key: 'exam', weight: 40, criteria: exam }] } };
    state.readiness.value = { can_record_result: true }; state.resultForm.value.result = 'passed';
    assert.equal(state.canSubmitResult.value, false);
    const form = evaluation.stageEvaluationForm({ criteria: exam }); exam.forEach(row => { form.criteria_scores[row.key].score = row.maximum_score; });
    state.applyEvaluation(form); assert.equal(state.canSubmitResult.value, false);
    state.showEvaluationEntry.value = true; state.applyEvaluation(form);
    assert.equal(state.showEvaluationEntry.value, false); assert.equal(state.canSubmitResult.value, true);
    const source = read('pages/provider/selection/SelectionActivityRecordPage.vue');
    assert.doesNotMatch(parse(source).descriptor.template.content, /<StageEvaluationFields/);
    assert.match(source, /\.\.\.\(evaluation \? \{ evaluation \} : \{\}\)/);
    assert.match(source, /Formal verification is a qualifying check/);
});
test('screening scores apply locally only for an assigned open review', () => {
    const state = harness('pages/provider/reviews/ApplicationReviewRecordPage.vue', {
        document: { getElementById: () => ({ dataset: { applicationId: 1 } }) }, window: { location: { search: '' }, portalUser: { id: 1 } }, decisionReasonOptions: [],
    }, 'syncApplication, application, decisionForm, rubricScores, showEvaluationEntry, canSubmitScreening, applyEvaluation');
    state.syncApplication({ workflow_stage: 'screening', workflow: { is_closed: false }, assigned_reviewer: { id: 1 }, rubric_review: { criteria: rubric }, document_readiness: { ready: true } });
    state.decisionForm.value.decision = 'approve'; assert.equal(state.canSubmitScreening.value, false);
    state.showEvaluationEntry.value = true; state.applyEvaluation({ academic_merit: 90, financial_need: 80 });
    assert.equal(state.canSubmitScreening.value, true); assert.equal(state.showEvaluationEntry.value, false);
    state.showEvaluationEntry.value = true; state.application.value.assigned_reviewer.id = 2; state.applyEvaluation({ academic_merit: 1, financial_need: 1 });
    assert.equal(state.rubricScores.value.academic_merit, 90);
    assert.doesNotMatch(parse(read('pages/provider/reviews/ApplicationReviewRecordPage.vue')).descriptor.template.content, /v-model.number="rubricScores/);
});
test('modal uses bounded scrolling and shared keyboard/scroll protection, without backend writes', () => {
    const source = read(modalPath);
    for (const text of ['useModalAccessibility', 'aria-modal="true"', '@click.self="close"', 'min-h-0 overflow-y-auto', '<footer class="flex shrink-0', 'Nothing is recorded until you review and confirm', 'Cancel discards changes']) assert.ok(source.includes(text), text);
    assert.doesNotMatch(source, /axios|fetch\(|confirmOutcome|emit\('save'/);
});
