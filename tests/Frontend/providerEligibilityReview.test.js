import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const root = '../../resources/js/pages/provider/';
for (const file of ['reviews/components/EligibilityCriteriaTable.vue', 'reviews/components/EligibilityReviewPanel.vue', 'reviews/ApplicationReviewRecordPage.vue', 'decisions/FinalDecisionRecordPage.vue']) {
    test(`${file} compiles with eligibility review guidance`, () => {
        const source = readFileSync(new URL(root + file, import.meta.url), 'utf8');
        const { descriptor, errors } = parse(source);
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('the comparison separates saved and current data and discloses the advisory score', () => {
    const panel = readFileSync(new URL(root + 'reviews/components/EligibilityReviewPanel.vue', import.meta.url), 'utf8');
    assert.match(panel, /data-eligibility-snapshot-notice/);
    assert.match(panel, /<details[^>]*data-current-eligibility-comparison/);
    assert.match(panel, /data-eligibility-advisory-score/);
    assert.match(panel, /The saved comparison below is unchanged/);
    assert.match(panel, /File readiness is separate from suitability/);
});

test('eligibility rows use shared statuses, omit file readiness, and disclose reference conversions', () => {
    const table = readFileSync(new URL(root + 'reviews/components/EligibilityCriteriaTable.vue', import.meta.url), 'utf8');
    assert.match(table, /eligibilityCriterionPresentation/);
    assert.match(table, /item.category !== 'readiness' && item.key !== 'documents'/);
    assert.match(table, /presentation\(criterion\).modeLabel/);
    assert.match(table, /<details[^>]*criterion.equivalence/);
    assert.match(table, /not missing profile data or an automatic failure/);
    assert.doesNotMatch(table, /@click|fetch\(|axios\./);
});

test('screening and final decision use the same review panel rather than a percentage verdict', () => {
    for (const file of ['reviews/ApplicationReviewRecordPage.vue', 'decisions/FinalDecisionRecordPage.vue']) {
        const source = readFileSync(new URL(root + file, import.meta.url), 'utf8');
        assert.match(source, /<EligibilityReviewPanel/);
        assert.match(source, /Review comparison/);
        assert.doesNotMatch(source, /eligibility_score[^\n]*%|eligibility_breakdown\?\.score/);
    }
});
