import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { eligibilityCriterionPresentation } from '../../resources/js/support/applicantEligibility.js';
import { scholarshipEligibilityGroups, scholarshipEligibilityRows } from '../../resources/js/support/scholarshipEligibility.js';
import { configuredEligibilityRules } from '../../resources/js/support/providerEligibilityRules.js';

const source = (file) => readFileSync(new URL('../../resources/js/' + file, import.meta.url), 'utf8');

for (const file of ['components/ApplicantEligibilityReview.vue', 'pages/provider/reviews/EligibilityReviewRequestsPage.vue', 'pages/provider/programs/ProgramFormPage.vue', 'components/ScholarshipEligibilityPanel.vue', 'pages/UserApplicationsPage.vue']) {
    test(`${file} compiles with eligibility reconsideration`, () => {
        const { descriptor, errors } = parse(source(file));
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('an exception is never a matched rule and does not appear in the blocked group', () => {
    const row = { key: 'academic', status: 'fail', rule_mode: 'required', exception_approved: true, student_value: '90%', requirement: '95%' };
    assert.equal(eligibilityCriterionPresentation(row).label, 'Exception approved');
    const groups = scholarshipEligibilityGroups(scholarshipEligibilityRows([row]));
    assert.deepEqual(groups.map((group) => group.id), ['exceptions']);
    assert.equal(groups[0].rows[0].status, 'fail');
    assert.equal(groups[0].rows[0].profileValue, '90%');
});

test('only explicitly enabled required rules advertise reconsideration', () => {
    const base = { eligibleCourses: 'STEM', eligibilityRuleModes: {}, eligibilityReviewRules: [] };
    assert.equal(configuredEligibilityRules(base).find((rule) => rule.key === 'course').allowsReview, false);
    assert.equal(configuredEligibilityRules({ ...base, eligibilityReviewRules: ['course'] }).find((rule) => rule.key === 'course').allowsReview, true);
    assert.equal(configuredEligibilityRules({ ...base, eligibilityReviewRules: ['course'], eligibilityRuleModes: { course: 'preferred' } }).find((rule) => rule.key === 'course').allowsReview, false);
    const builder = source('pages/provider/programs/ProgramFormPage.vue');
    assert.match(builder, /eligibilityReviewRules: program\.eligibility_review_rules \?\? \[\]/);
    assert.match(builder, /eligibility_review_rules: JSON\.stringify\(form\.value\.eligibilityReviewRules\)/);
});

test('applicant attachment and consequential actions use the shared modal protections', () => {
    const applicant = source('components/ApplicantEligibilityReview.vue');
    assert.match(applicant, /accept="\.jpg,\.jpeg,\.png,\.webp,\.pdf"/);
    assert.match(applicant, /file\.size > 5 \* 1024 \* 1024/);
    assert.match(applicant, /URL\.revokeObjectURL/);
    assert.match(applicant, /data\.append\('evidence', evidence\.value\)/);
    assert.match(applicant, /useModalAccessibility/);
    assert.match(applicant, /confirmOutcome/);
    assert.match(applicant, /FilePreviewModal/);
    assert.doesNotMatch(applicant, /window\.alert|window\.confirm|v-html/);
    assert.match(applicant, /This does not submit an application or reserve a slot/);
});

test('provider modal requires rationale and evidence review and guards stale approvals', () => {
    const provider = source('pages/provider/reviews/EligibilityReviewRequestsPage.vue');
    assert.match(provider, /decision\.value === 'approved' && !item\.can_approve/);
    assert.match(provider, /item\.evidence && !reviewed\.value/);
    assert.match(provider, /note\.value\.trim\(\)\.length < 10/);
    assert.match(provider, /confirmOutcome/);
    assert.match(provider, /StaffTableIdentity/);
    assert.doesNotMatch(provider, /window\.alert|window\.confirm|v-html/);
});
