import assert from 'node:assert/strict';
import test from 'node:test';
import { stageEvaluationForm, stageEvaluationResult, stageEvaluationPayload } from '../../resources/js/support/stageEvaluation.js';
const criteria = [{ key: 'knowledge', weight: 40, maximum_score: 40 }, { key: 'reasoning', weight: 40, maximum_score: 40 }, { key: 'application', weight: 20, maximum_score: 20 }];

test('blank criterion scores stay incomplete rather than becoming zeros', () => {
    const form = stageEvaluationForm({ criteria });
    assert.equal(stageEvaluationResult(form, criteria, 'exam'), null);
    for (const row of Object.values(form.criteria_scores)) row.score = 0;
    assert.equal(stageEvaluationResult(form, criteria, 'exam').percentage, 0);
});
test('different exam maxima are normalized before fixed criterion weights', () => {
    const form = { criteria_scores: { knowledge: { score: 36, maximum_score: 40 }, reasoning: { score: 16, maximum_score: 20 }, application: { score: 9, maximum_score: 10 } } };
    assert.equal(stageEvaluationResult(form, criteria, 'exam').percentage, 86);
    assert.deepEqual(stageEvaluationPayload(form, criteria), form);
    form.criteria_scores.reasoning.score = 21;
    assert.equal(stageEvaluationResult(form, criteria, 'exam'), null);
});
test('interview uses a fixed rating scale and weighted criterion results', () => {
    const rubric = criteria.map((row, i) => ({ ...row, weight: [40, 30, 30][i], maximum_score: 5 }));
    const form = stageEvaluationForm({ criteria: rubric });
    [4.5, 4, 5].forEach((score, i) => { form.criteria_scores[rubric[i].key].score = score; });
    assert.equal(stageEvaluationResult(form, rubric, 'interview').percentage, 90);
    form.criteria_scores.knowledge.maximum_score = 4.5;
    assert.equal(stageEvaluationResult(form, rubric, 'interview'), null);
});
test('correction forms restore their actual criteria and existing intakes retain total entry', () => {
    const form = stageEvaluationForm({ criteria, breakdown: [{ key: 'knowledge', score: 30, maximum_score: 35 }] });
    assert.deepEqual(form.criteria_scores.knowledge, { score: 30, maximum_score: 35 });
    assert.equal(form.criteria_scores.reasoning.score, '');
    const legacy = stageEvaluationForm({ raw_score: 18, maximum_score: 20 });
    assert.equal(stageEvaluationResult(legacy).percentage, 90);
    assert.deepEqual(stageEvaluationPayload(legacy), { score: 18, maximum_score: 20 });
});
