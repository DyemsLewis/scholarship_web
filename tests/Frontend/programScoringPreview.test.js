import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { programScoringPreview } from '../../resources/js/support/programScoringPreview.js';

const criteria = [
    { key: 'academic_merit', label: 'Academic merit', weight: 25 },
    { key: 'financial_need', label: 'Financial need', weight: 20 },
];
const policy = (weights) => ({ stages: ['screening', 'formal_application', ...Object.keys(weights).filter(key => key !== 'screening'), 'decision'], weights, screening_criteria: criteria });
const presets = {
    screening: policy({ screening: 100 }), exam: policy({ screening: 40, exam: 60 }),
    interview: policy({ screening: 50, interview: 50 }), 'exam+interview': policy({ screening: 30, exam: 40, interview: 30 }),
};

test('every optional stage combination selects the server preset rather than hardcoded frontend weights', () => {
    for (const entry of Object.values(presets)) {
        const preview = programScoringPreview(presets, entry.stages);
        assert.deepEqual(Object.fromEntries(preview.rows.map(row => [row.key, row.weight])), entry.weights);
        assert.equal(preview.total, 100);
        assert.equal(preview.fixed, false);
        assert.ok(!preview.rows.some(row => row.key === 'formal_application'));
    }
    const custom = programScoringPreview({ exam: policy({ screening: 37, exam: 63 }) }, ['exam']);
    assert.equal(custom.rows[1].weight, 63);
    assert.match(custom.formula, /Exam score x 63%/);
});

test('screening weights are normalized within their actual subtotal, excluding qualifying points', () => {
    const preview = programScoringPreview(presets, []);
    assert.equal(preview.rows[0].basis, 'Academic merit 55.56% + Financial need 44.44% of the screening score.');
    assert.doesNotMatch(preview.rows[0].basis, /Eligibility fit|Document quality/);
});

test('a fixed policy takes precedence over a changed plan and is never mutated', () => {
    const frozen = structuredClone(presets['exam+interview']);
    frozen.frozen_at = '2026-11-14T00:00:00Z';
    const before = JSON.stringify(frozen);
    const preview = programScoringPreview(presets, ['screening', 'formal_application', 'decision'], frozen, 4);
    assert.equal(preview.fixed, true);
    assert.equal(preview.stagesDiffer, true);
    assert.deepEqual(preview.rows.map(row => row.weight), [30, 40, 30]);
    assert.equal(JSON.stringify(frozen), before);
    assert.equal(programScoringPreview(presets, frozen.stages, frozen).stagesDiffer, false);
});

test('older intakes and qualifying-only plans do not acquire a numeric ranking preview', () => {
    const legacy = programScoringPreview(presets, ['exam'], null, 2);
    assert.equal(legacy.legacy, true);
    assert.equal(legacy.available, true);
    assert.deepEqual(legacy.rows, []);
    const qualifying = programScoringPreview(presets, [], { stages: ['screening', 'formal_application', 'decision'], weights: [], screening_criteria: [] });
    assert.equal(qualifying.fixed, true);
    assert.deepEqual(qualifying.rows, []);
    assert.equal(qualifying.formula, '');
});

test('missing server configuration cannot invent a score or a zero-point screening formula', () => {
    const missing = programScoringPreview({}, ['exam']);
    assert.equal(missing.available, false);
    assert.deepEqual(missing.rows, []);
    const examOnly = programScoringPreview({}, ['exam'], { stages: ['exam'], weights: { exam: 100 }, screening_criteria: [] });
    assert.equal(examOnly.rows[0].key, 'exam');
    assert.doesNotMatch(examOnly.formula, /NaN|screening/i);
});

test('scoring component compiles and the form only consumes read-only scoring data', () => {
    const read = file => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
    const filename = 'resources/js/pages/provider/programs/components/SelectionScoringPreview.vue';
    const source = read(filename);
    const { descriptor, errors } = parse(source, { filename });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'scoring-preview' });
    assert.deepEqual(compileTemplate({ id: 'scoring-preview', filename, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.doesNotMatch(source, /axios|fetch\(|v-model|<input/);
    assert.match(source, /You still make the final decision/);
    const form = read('resources/js/pages/provider/programs/ProgramFormPage.vue');
    assert.match(form, /fixedScoringPolicy.value = program.selection_scoring_policy \?\? null/);
    assert.match(form, /:applications-count="applicationsCount"/);
    const payload = form.slice(form.indexOf('function createPayload'), form.indexOf('function stepForError'));
    assert.doesNotMatch(payload, /append\('selection_scoring_policy'/);
});
