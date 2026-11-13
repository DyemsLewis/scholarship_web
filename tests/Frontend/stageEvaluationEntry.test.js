import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { stageScorePercentage, stageScoreLabel } from '../../resources/js/support/stageEvaluation.js';

for (const path of [
    '../../resources/js/components/StageEvaluationFields.vue',
    '../../resources/js/components/StageEvaluationDialog.vue',
    '../../resources/js/pages/provider/selection/SelectionActivityRecordPage.vue',
    '../../resources/js/pages/provider/reviews/ApplicationReviewRecordPage.vue',
]) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8');
    test(`${path.split('/').at(-1)} compiles with stage score entry`, () => {
        const { descriptor, errors } = parse(source, { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({ id: path, filename: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}

test('score previews do not mistake a missing score for zero', () => {
    for (const [score, maximum] of [['', 100], [null, 100], [undefined, 100], [false, 100], [101, 100], [1, 0], [-1, 100], [1, Infinity], [NaN, 100], [1.00001, 100]]) {
        assert.equal(stageScorePercentage(score, maximum), null);
    }
    assert.equal(stageScorePercentage(0, 100), 0);
    assert.equal(stageScorePercentage(43, 50), 86);
    assert.equal(stageScorePercentage('18', '20'), 90);
    assert.equal(stageScorePercentage(1, 3), 33.33);
    assert.equal(stageScoreLabel(43, 50), '43 / 50 (86%)');
});

test('an outcome confirmation includes the score and posts both values in one request', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/selection/SelectionActivityRecordPage.vue', import.meta.url), 'utf8');
    assert.match(source, /Evaluation: \$\{stageScoreLabel/);
    assert.match(source, /\.\.\.\(evaluation \? \{ evaluation \} : \{\}\)/);
    assert.match(source, /expected_stage_revision: currentEvaluation\.value\.stage_revision/);
    assert.match(source, /JSON\.stringify\(resultForm\.value\) === snapshot/);
    assert.match(source, /await loadRecord\(\)/);
});

test('score corrections keep a reason, history, confirmation, and modal keyboard handling', () => {
    const source = readFileSync(new URL('../../resources/js/components/StageEvaluationDialog.vue', import.meta.url), 'utf8');
    assert.match(source, /form\.value\.note\.trim\(\)\.length >= 10/);
    assert.match(source, /confirmOutcome\(/);
    assert.match(source, /Recent score history/);
    assert.match(source, /useModalAccessibility/);
    assert.match(source, /expected_revision: evaluation\.revision/);
    assert.match(source, /does not change a stage result/);
});
