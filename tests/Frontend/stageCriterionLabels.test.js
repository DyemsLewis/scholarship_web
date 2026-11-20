import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, createSSRApp, h, reactive, ref, watch } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { programCriterionLabels, programCriterionLabelErrors, programScoringPreview } from '../../resources/js/support/programScoringPreview.js';
import { programReviewSummary } from '../../resources/js/support/programReviewSummary.js';

const policy = {
    stages: ['screening', 'formal_application', 'exam', 'interview', 'decision'],
    weights: { exam: 60, interview: 40 }, exam_scoring_method: 'criteria', screening_criteria: [],
    stage_criteria: {
        exam: [{ key: 'knowledge', label: 'Subject knowledge', weight: 40, maximum_score: 40 }, { key: 'reasoning', label: 'Reasoning', weight: 40, maximum_score: 40 }, { key: 'application', label: 'Problem solving', weight: 20, maximum_score: 20 }],
        interview: [{ key: 'motivation', label: 'Motivation', weight: 40, maximum_score: 5 }, { key: 'communication', label: 'Communication', weight: 30, maximum_score: 5 }, { key: 'readiness', label: 'Readiness', weight: 30, maximum_score: 5 }],
    },
};
const presets = { 'exam+interview': policy };
const labels = () => ({ ...programCriterionLabels(presets), exam: { knowledge: 'Mathematics', reasoning: 'Science', application: 'Problem solving' } });
const preview = (overrides = labels(), count = 0, method = 'criteria') => programScoringPreview(presets, policy.stages, policy, count, method, overrides);
const filename = 'StageCriterionLabelsEditor.vue';
const source = readFileSync(new URL('../../resources/js/pages/provider/programs/components/' + filename, import.meta.url), 'utf8');
const { descriptor, errors } = parse(source, { filename });
assert.deepEqual(errors, []);
const script = compileScript(descriptor, { id: 'criterion-labels', inlineTemplate: true });
const code = script.content
    .replace(/from (['"])vue\1/g, `from ${JSON.stringify(import.meta.resolve('vue'))}`)
    .replace(/from ['"][^'"]+\/programScoringPreview\.js['"]/, `from ${JSON.stringify(new URL('../../resources/js/support/programScoringPreview.js', import.meta.url).href)}`)
    .replace(/^import EligibilityOptionsModal .*;$/m, 'const EligibilityOptionsModal = { render: () => null };');
const Editor = (await import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'))).default;
const render = props => renderToString(createSSRApp({ render: () => h(Editor, { modelValue: labels(), rows: preview().rows, ...props }) }));

function editor(overrides = {}) {
    const props = reactive({ modelValue: labels(), rows: preview().rows, ...overrides });
    const events = [];
    const deps = { computed, ref, watch, programCriterionLabelErrors, defineProps: () => props, defineEmits: () => (...args) => events.push(args) };
    const setup = descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const state = new Function(...Object.keys(deps), setup + '\nreturn { show, close, apply, draft, activeStage, errorMessage };')(...Object.values(deps));
    return { ...state, props, events };
}

test('labels hydrate from defaults, configured inactive criteria, and the fixed policy without mutation', () => {
    const saved = { ...policy, stage_criteria: { interview: [{ ...policy.stage_criteria.interview[0], label: 'Study motivation' }] }, stage_criterion_labels: { exam: { knowledge: 'Mathematics' } } };
    const before = JSON.stringify({ presets, saved });
    const result = programCriterionLabels(presets, saved);
    assert.equal(result.exam.knowledge, 'Mathematics');
    assert.equal(result.interview.motivation, 'Study motivation');
    assert.equal(result.interview.communication, 'Communication');
    assert.equal(JSON.stringify({ presets, saved }), before);
});

test('custom names reach the DSS preview and review summary without changing calculations', () => {
    const before = JSON.stringify(presets);
    const scoring = preview();
    assert.equal(scoring.rows[0].criteria[0].label, 'Mathematics');
    assert.match(scoring.rows[0].basis, /Mathematics 40% \+ Science 40%/);
    assert.deepEqual(scoring.rows.map(row => row.weight), [60, 40]);
    assert.deepEqual(scoring.rows[0].criteria.map(row => [row.key, row.weight, row.maximum_score]), policy.stage_criteria.exam.map(row => [row.key, row.weight, row.maximum_score]));
    const summary = programReviewSummary({ selectionStages: policy.stages }, { scoring });
    assert.match(summary.selection.details.find(row => row.label === 'Exam criteria').value, /Mathematics/);
    assert.equal(JSON.stringify(presets), before);
});

test('frozen intake previews ignore attempted label changes and total mode hides only exam criteria', () => {
    assert.equal(preview(labels(), 1).rows[0].criteria[0].label, 'Subject knowledge');
    const total = preview(labels(), 0, 'total');
    assert.equal(total.rows[0].criteria.length, 0);
    assert.equal(total.rows[1].criteria.length, 3);
    assert.equal(preview().rows[0].criteria[0].label, 'Mathematics');
});

test('client checks reject blank, duplicated, invisible and overlong names without exposing markup', async () => {
    assert.deepEqual(programCriterionLabelErrors(labels()), {});
    for (const value of ['', '   ', 'science', '  SCIENCE  ', 'a'.repeat(81), 'Hidden\u200blabel']) {
        const input = labels();
        input.exam.knowledge = value;
        assert.ok(programCriterionLabelErrors(input)['stage_criterion_labels.exam.knowledge'] || programCriterionLabelErrors(input)['stage_criterion_labels.exam.reasoning']);
    }
    const input = labels();
    input.exam.knowledge = '<strong>Mathematics</strong>';
    const html = await render({ modelValue: input });
    assert.ok(!html.includes(input.exam.knowledge));
});

test('editor shows only included stage summaries and places bounded inputs inside the modal', async () => {
    const html = await render({});
    assert.match(html, /data-criterion-stage="exam"/);
    assert.match(html, /data-criterion-stage="interview"/);
    assert.match(html, /Edit Exam criteria/);
    assert.doesNotMatch(html, /<input/);
    assert.match(source, /<EligibilityOptionsModal[^>]*:view-only="fixed"/);
    assert.match(source, /maxlength="80"/);
    assert.match(source, /Weights and rating scales stay unchanged/);
    const fixed = await render({ fixed: true });
    assert.match(fixed, /View Exam criteria/);
    assert.match(fixed, /fixed after the first application/i);
    const total = await render({ rows: preview(labels(), 0, 'total').rows });
    assert.doesNotMatch(total, /data-criterion-stage="exam"/);
    assert.match(total, /data-criterion-stage="interview"/);
    assert.doesNotMatch(await render({ includedStages: ['exam'] }), /data-criterion-stage="interview"/);
    const qualifying = await render({ rows: [] });
    assert.doesNotMatch(qualifying, /data-stage-criterion-labels/);
});

test('editing a single stage stages valid labels only until Apply and never changes other stages', () => {
    const state = editor();
    state.show('exam');
    state.draft.value.knowledge = 'Discard this';
    state.close();
    state.show('exam');
    assert.deepEqual(state.events, []);
    assert.equal(state.draft.value.knowledge, 'Mathematics');
    state.draft.value.knowledge = '   science  ';
    state.apply();
    assert.ok(state.errorMessage.value);
    assert.equal(state.activeStage.value.key, 'exam');
    assert.equal(state.events.length, 0);
    state.draft.value.knowledge = '  Applied mathematics  ';
    state.apply();
    const payload = state.events[0][1];
    assert.equal(payload.exam.knowledge, 'Applied mathematics');
    assert.equal(state.props.modelValue.exam.knowledge, 'Mathematics');
    assert.deepEqual(payload.interview, labels().interview);
    assert.equal(state.activeStage.value, undefined);
});

test('locked review reads the fixed policy and excluded or absent criteria cannot open', () => {
    const state = editor({ rows: preview(labels(), 1).rows, fixed: true, includedStages: ['exam'] });
    state.show('interview');
    assert.equal(state.activeStage.value, undefined);
    state.show('exam');
    assert.equal(state.draft.value.knowledge, 'Subject knowledge');
    state.draft.value.knowledge = 'Not allowed';
    state.apply();
    assert.deepEqual(state.events, []);
    const total = editor({ rows: preview(labels(), 0, 'total').rows });
    total.show('exam');
    assert.equal(total.activeStage.value, undefined);
});

test('program form compiles and submits labels rather than editable weights or a replacement policy', () => {
    const form = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    const parsed = parse(form, { filename: 'ProgramFormPage.vue' });
    assert.deepEqual(parsed.errors, []);
    const compiled = compileScript(parsed.descriptor, { id: 'criterion-form' });
    assert.deepEqual(compileTemplate({ id: 'criterion-form', filename: 'ProgramFormPage.vue', source: parsed.descriptor.template.content, compilerOptions: { bindingMetadata: compiled.bindings } }).errors, []);
    assert.match(form, /<StageCriterionLabelsEditor v-model="form.stageCriterionLabels"[^>]*:fixed="applicationsCount > 0"/);
    assert.match(form, /stage_criterion_labels: JSON.stringify\(form.value.stageCriterionLabels\)/);
    assert.match(source, /if \(props.disabled \|\| props.fixed\) return/);
    assert.doesNotMatch(source, /v-html|fetch\(|axios|type="number"/);
});
