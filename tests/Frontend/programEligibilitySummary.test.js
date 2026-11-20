import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { eligibilitySummaryFromRules } from '../../resources/js/support/programEligibilitySummary.js';
import { configuredEligibilityRules } from '../../resources/js/support/providerEligibilityRules.js';

const rules = [
    { key: 'academic', label: 'Academic performance', value: 'Minimum average: 85.00%', mode: 'required' },
    { key: 'course', label: 'Courses, tracks, or strands', value: 'STEM', mode: 'preferred' },
];

test('generated summaries separate required rules from nonblocking preferences', () => {
    assert.equal(eligibilitySummaryFromRules(), '');
    assert.equal(eligibilitySummaryFromRules(rules), 'Required to apply:\n- Academic performance: Minimum average: 85.00%\n\nPreferred, not required to apply:\n- Courses, tracks, or strands: STEM');
    const preferred = eligibilitySummaryFromRules(rules.map(rule => ({ ...rule, mode: 'preferred' })));
    assert.ok(!preferred.startsWith('Required to apply'));
    assert.match(preferred, /Preferred, not required to apply/);
});

test('summary uses existing rule configuration without inventing restrictions', () => {
    const configured = configuredEligibilityRules({ eligibleCourses: 'Any', eligibleLocations: 'Nationwide', incomeRequirement: 'Any', minimumGradeScale: 'percentage', minimumGwa: 88, eligibilityRuleModes: { academic: 'preferred' } });
    assert.equal(configured.length, 1);
    assert.match(eligibilitySummaryFromRules(configured), /Preferred, not required to apply:\n- Academic performance: Minimum average: 88.00%/);
    assert.doesNotMatch(eligibilitySummaryFromRules(configured), /Residency|Household income|Courses/);
});

test('builder preserves custom text and validates the effective summary without requiring acknowledgement', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    for (const text of ['eligibility: eligibilitySummary.value.trim()', 'eligibility: eligibilitySummary.value', 'v-model:automatic="useGeneratedEligibilitySummary"', 'useGeneratedEligibilitySummary.value = form.value.eligibility.trim() === suggestedEligibilitySummary.value.trim()']) assert.ok(source.includes(text), text);
    assert.match(source, /eligibility: Boolean\(\s*eligibilitySummary\.value\.trim\(\)\s*&& eligibilitySummary\.value\.length <= 5000/);
    assert.doesNotMatch(source, /customSummaryNeedsReview|reviewedEligibilitySummary/);
    assert.doesNotMatch(source, /watch\(.*eligibility/);
});
