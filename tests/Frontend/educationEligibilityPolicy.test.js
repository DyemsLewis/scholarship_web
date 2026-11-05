import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { normalizeEducationPolicy, educationPolicyError, educationPolicySummary, enrollmentStatusOptions, acceptedQualificationOptions, pendingStudyStatuses } from '../../resources/js/support/educationEligibilityPolicy.js';
import { configuredEligibilityRules } from '../../resources/js/support/providerEligibilityRules.js';
import { scholarshipEligibilityRows } from '../../resources/js/support/scholarshipEligibility.js';
import { eligibilityCriterionPresentation } from '../../resources/js/support/applicantEligibility.js';

const policy = (extra = {}) => ({ requires_completed_qualification: true, accepted_qualifications: ['pre_k12_high_school', 'als_secondary'], unlisted_qualification: 'review', enrollment_statuses: [], ...extra });

test('qualification and enrollment catalogs remain aligned with server validation', () => {
    const php = readFileSync(new URL('../../app/Support/EducationEligibilityPolicy.php', import.meta.url), 'utf8');
    const statuses = [...php.match(/public const ENROLLMENT_STATUSES = \[([^]*?)\];/)[1].matchAll(/'([^']+)'/g)].map((match) => match[1]);
    assert.deepEqual(enrollmentStatusOptions, statuses);
    const pending = [...php.match(/public const PENDING_STUDIES = \[([^]*?)\];/)[1].matchAll(/'([^']+)'/g)].map((match) => match[1]);
    assert.deepEqual(pending, pendingStudyStatuses);
    assert.equal(acceptedQualificationOptions.some((option) => option.value === 'none'), false);
    assert.ok(acceptedQualificationOptions.some((option) => option.value === 'tvet'));
    assert.ok(acceptedQualificationOptions.some((option) => option.value === 'postgraduate'));
});

test('inactive and malformed data do not silently enable a credential or enrollment rule', () => {
    assert.deepEqual(normalizeEducationPolicy(null), { requires_completed_qualification: false, accepted_qualifications: [], unlisted_qualification: 'review', enrollment_statuses: [] });
    assert.deepEqual(normalizeEducationPolicy(policy({ accepted_qualifications: 'pre_k12_high_school', enrollment_statuses: {} })).accepted_qualifications, []);
    assert.equal(educationPolicyError(policy({ accepted_qualifications: [] })).includes('at least one'), true);
    assert.equal(educationPolicyError(normalizeEducationPolicy()), '');
    assert.deepEqual(educationPolicySummary(null), []);
});

test('active-enrollment conditions cannot silently accept pending admission', () => {
    const active = [{ key: 'currently_enrolled', verification_type: 'automatic' }];
    assert.match(educationPolicyError(policy({ enrollment_statuses: ['Incoming student'] }), active), /active enrollment/);
    assert.equal(educationPolicyError(policy({ enrollment_statuses: ['Enrolled'] }), active), '');
    assert.equal(educationPolicyError(policy({ enrollment_statuses: ['Returning enrollment pending'] }), []), '');
});

test('program review and read-only summaries show the exact accepted credentials without asserting equivalence', () => {
    const form = { educationEligibilityPolicy: policy({ enrollment_statuses: ['Incoming student'] }), eligibilityRuleModes: { completed_qualification: 'preferred' } };
    const rules = configuredEligibilityRules(form);
    const qualification = rules.find((row) => row.key === 'completed_qualification');
    assert.equal(qualification.mode, 'preferred');
    assert.match(qualification.value, /old curriculum/);
    assert.match(qualification.value, /provider review/);
    assert.doesNotMatch(qualification.value, /pre_k12|als_secondary/);
    assert.equal(rules.find((row) => row.key === 'enrollment_status').value, 'Incoming student');
    const summary = educationPolicySummary(form.educationEligibilityPolicy, form.eligibilityRuleModes);
    assert.equal(summary[0].mode, 'Preferred');
    assert.match(summary[1].value, /no automatic equivalency/);
});

test('applicant credential comparisons keep readable labels, review signals and the correct edit destination', () => {
    const row = scholarshipEligibilityRows([{ key: 'completed_qualification', label: 'Completed qualification', student_value: 'pre_k12_high_school', requirement: 'senior_high_school', status: 'missing', comparison_mode: 'manual_review', needs_review: true, rule_mode: 'required' }])[0];
    assert.equal(row.result.label, 'Needs review');
    assert.match(row.profileValue, /old curriculum/);
    assert.match(row.requirementValue, /Senior High School/);
    assert.equal(row.profileHref, '/dashboard/profile?section=academic');
    assert.equal(eligibilityCriterionPresentation({ key: 'academic', status: 'missing', comparison_mode: 'manual_review' }).label, 'Needs review');
});

for (const path of ['../../resources/js/pages/provider/programs/components/EducationEligibilityPolicyEditor.vue', '../../resources/js/components/EducationEligibilitySummary.vue', '../../resources/js/pages/admin/reviews/ProgramReviewRecordPage.vue']) {
    test(`${path.split('/').at(-1)} compiles with the existing workspace`, () => {
        const { descriptor, errors } = parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({ id: path, filename: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}

test('builder restores and serializes the policy, validates it before continuing and keeps controls behind one disclosure', () => {
    const page = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    const editor = readFileSync(new URL('../../resources/js/pages/provider/programs/components/EducationEligibilityPolicyEditor.vue', import.meta.url), 'utf8');
    assert.match(page, /educationEligibilityPolicy: normalizeEducationPolicy\(program.education_eligibility_policy\)/);
    assert.match(page, /education_eligibility_policy: JSON.stringify\(normalizeEducationPolicy\(form.value.educationEligibilityPolicy\)\)/);
    assert.match(page, /!educationPolicyError\(form.value.educationEligibilityPolicy, form.value.eligibilityConditions\)/);
    assert.match(page, /eligibility\|education_eligibility_policy\|eligible_/);
    assert.match(editor, /<details data-education-policy-editor :open="configured"/);
    assert.match(editor, /current-study filters/);
    assert.doesNotMatch(editor, /gradient|rounded-xl|shadow-/);
});
