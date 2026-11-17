import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { eligibilityValue, scholarshipEligibilityGroups, scholarshipEligibilityRows } from '../../resources/js/support/scholarshipEligibility.js';

const source = readFileSync(new URL('../../resources/js/components/ScholarshipEligibilityPanel.vue', import.meta.url), 'utf8');
const { descriptor, errors } = parse(source, { filename: 'ScholarshipEligibilityPanel.vue' });
assert.deepEqual(errors, []);
const script = compileScript(descriptor, { id: 'scholarship-eligibility-test', inlineTemplate: true });
const code = script.content.replace(/from (['"])(vue|\.\.\/support\/[^'"]+)\1/g, (_, quote, module) => `from ${JSON.stringify(module === 'vue' ? import.meta.resolve('vue') : new URL(`../../resources/js/support/${module.split('/').at(-1)}.js`, import.meta.url).href)}`)
    // Review-request behavior is covered separately; these tests isolate the rule panel.
    .replace(/import ApplicantEligibilityReview from ['"]\.\/ApplicantEligibilityReview.vue['"];?/, 'const ApplicantEligibilityReview = { render: () => null };');
const { default: Panel } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const criterion = (key, status, extra = {}) => ({ key, label: key === 'academic' ? 'Academic result' : 'Education level', category: 'eligibility', status, student_value: 'senior_high_school', requirement: 'senior_high_school', rule_mode: 'required', comparison: 'Compared with your saved profile.', ...extra });
const render = (criteria, overrides = {}) => renderToString(createSSRApp(Panel, {
    content: { criteria, profile: { complete: true, verified: true } },
    verdict: { eyebrow: 'Profile comparison', title: 'Review the program rules', description: 'Comparison guidance.' },
    applicationHref: '/dashboard/scholarships/1/application',
    ...overrides,
}));

test('a platform scholarship conflict links to applications rather than the removed declaration field', async () => {
    const conflict = criterion('current_scholarship_support', 'fail', { label: 'Scholarship support in this platform', student_value: 'Platform record: Existing award', requirement: 'No other active platform award' });
    assert.equal(scholarshipEligibilityRows([conflict])[0].profileHref, '/dashboard/applications');
    const html = await render([conflict]);
    assert.match(html, /href="\/dashboard\/applications"/);
    assert.match(html, /View your applications/);
    assert.doesNotMatch(html, /Review profile details/);
});

test('eligibility values format machine labels without damaging currency, acronyms or zero', () => {
    assert.equal(eligibilityValue('senior_high_school, college', 'education_level'), 'Senior High School, College / University');
    assert.equal(eligibilityValue(['public', 'private'], 'school_type'), 'Public, Private');
    assert.equal(eligibilityValue('PHP 10,000 - 20,000', 'income'), 'PHP 10,000 - 20,000');
    assert.equal(eligibilityValue('STEM'), 'STEM');
    assert.equal(eligibilityValue(0), '0');
    assert.equal(eligibilityValue(null), 'Not provided');
    assert.equal(eligibilityValue({ value: 'unavailable' }), 'Not provided');
    assert.equal(eligibilityValue('Your education level is senior_high_school.'), 'Your education level is Senior High School.');
});

test('blocking rules, unconfirmed checks and optional differences come before matched criteria', () => {
    const original = [criterion('education_level', 'pass'), criterion('income', 'fail'), criterion('course', 'fail', { rule_mode: 'preferred' }), criterion('academic', 'missing'), criterion('school_type', 'info', { requirement: null })];
    const groups = scholarshipEligibilityGroups(scholarshipEligibilityRows(original));
    assert.deepEqual(groups.map((group) => group.id), ['blocking', 'review', 'preferred', 'matched']);
    assert.deepEqual(groups.map((group) => group.rows.map((row) => row.key)), [['income'], ['academic'], ['course'], ['education_level']]);
    assert.equal(groups[2].rows[0].result.label, 'Preference not matched');
    assert.equal(groups[3].collapsed, false);
    assert.equal(original[0].key, 'education_level');
    assert.equal('result' in original[0], false);
    const legacy = scholarshipEligibilityGroups(scholarshipEligibilityRows([criterion('income', 'fail', { rule_mode: 'optional', is_blocking: false })]));
    assert.equal(legacy[0].id, 'blocking');
    assert.equal(legacy[0].rows[0].result.state, 'blocked');
});

test('manual grade review is never grouped as unrestricted and file readiness is kept separate', () => {
    const rows = scholarshipEligibilityRows([criterion('academic', 'info', { comparison_mode: 'scale_mismatch', needs_review: true }), criterion('documents', 'missing', { category: 'readiness' }), criterion('location', 'info', { requirement: null })]);
    assert.equal(rows.length, 2);
    assert.equal(rows[0].result.status, 'review');
    assert.equal(rows[0].profileHref, '/dashboard/profile?section=verification');
    assert.equal(scholarshipEligibilityGroups(rows)[0].rows.length, 1);
    assert.equal(rows[1].result.status, 'info');
});

test('comparison rows retain zero values, including legacy camel-case profile values', () => {
    const rows = scholarshipEligibilityRows([criterion('academic', 'pass', { student_value: 0, requirement: 'Minimum 0' }), criterion('course', 'pass', { student_value: null, studentValue: 'STEM' })]);
    assert.equal(rows[0].profileValue, '0');
    assert.equal(rows[1].profileValue, 'STEM');
});

test('the checklist distinguishes required failures, preferences, missing values and provider review', async () => {
    const html = await render([criterion('income', 'fail'), criterion('course', 'fail', { rule_mode: 'preferred' }), criterion('school_type', 'missing'), criterion('academic', 'info', { comparison_mode: 'manual_review' })]);
    for (const label of ['Required rule not met', 'Preference not matched', 'Profile information needed', 'Needs review']) assert.ok(html.includes(label));
    assert.match(html, /data-eligibility-group="blocking"/);
    assert.match(html, /data-eligibility-group="review"/);
    assert.match(html, /data-eligibility-group="preferred"/);
    assert.match(html, /Program preference/);
    assert.match(html, /Only correct your profile if the saved information is inaccurate/);
    assert.match(html, /href="\/dashboard\/profile\?section=household"/);
    assert.match(html, /href="\/dashboard\/profile\?section=verification"/);
    assert.doesNotMatch(html, /% profile match|You can apply/);
});

test('all rule values stay visible while only the comparison explanations start collapsed', async () => {
    const html = await render([criterion('education_level', 'pass'), criterion('income', 'fail')]);
    assert.match(html, /<section[^>]+data-eligibility-group="matched"/);
    assert.doesNotMatch(html, /<details[^>]+data-eligibility-group="matched"/);
    assert.match(html, /<details[^>]+data-eligibility-explanation(?![^>]*open)/);
    assert.doesNotMatch(html, /<details[^>]+data-eligibility-explanation[^>]*\bopen/);
    assert.match(html, /Why this needs attention/);
    assert.match(html, /data-eligibility-profile-action/);
    assert.match(html, /data-eligibility-values/);
    assert.doesNotMatch(source, /grid-cols-/);
    assert.match(source, /data-eligibility-result/);
});

test('the comparison aligns desktop columns and retains labeled stacked values for narrow containers', async () => {
    const html = await render([criterion('income', 'fail', { label: 'Household income', requirement: 'Below PHP 20,000', student_value: 'PHP 25,000' })]);
    assert.match(html, /data-eligibility-row-heading/);
    assert.match(html, /aria-label="Required rule not met"/);
    assert.match(html, />Not met<\/span>/);
    assert.match(html, />Required<\/dt><dd[^>]*>Below PHP 20,000/);
    assert.match(html, />Your profile<\/dt><dd[^>]*>PHP 25,000/);
    assert.match(html, /data-eligibility-column-headings[^>]+aria-hidden="true"/);
    assert.match(html, /Program requirement/);
    assert.match(html, /Your information/);
    assert.match(source, /container-name: scholarship-eligibility/);
    assert.match(source, /@container scholarship-eligibility \(min-width: 54rem\)/);
    assert.match(source, /\.eligibility-result \{ grid-column: 3;/);
    assert.doesNotMatch(source, /md:grid|line-clamp|truncate/);
    assert.match(html, /<details[^>]+data-eligibility-verdict-details(?![^>]*open)/);
    assert.match(html, /Comparison guidance/);
});

test('review and preferences remain distinct from a confirmed required failure', async () => {
    const html = await render([criterion('academic', 'info', { comparison_mode: 'scale_mismatch', student_value: '1.75', requirement: '85%' }), criterion('course', 'fail', { rule_mode: 'preferred' })]);
    assert.match(html, />Needs review<\/span>/);
    assert.match(html, />Preference differs<\/span>/);
    assert.doesNotMatch(html, />Not met<\/span>/);
    assert.match(html, /1.75/);
    assert.match(html, /85%/);
    assert.match(html, /These differences do not block an application/);
});

test('unrestricted criteria remain available without being counted as successful comparisons', async () => {
    const html = await render([criterion('education_level', 'pass'), criterion('course', 'info', { requirement: null })]);
    assert.match(html, /<details[^>]+data-unrestricted-criteria(?![^>]*open)/);
    assert.match(html, /They are not counted as profile matches/);
    assert.match(html, /Matched criteria[^]*?\(1\)/);
    assert.doesNotMatch(html, /data-eligibility-summary/);
    assert.equal((html.match(/data-eligibility-criterion=/g) ?? []).length, 1);
});

test('profile verification stays a separate gate and no application action is inferred by the checklist', async () => {
    let html = await render([criterion('education_level', 'pass')], { content: { criteria: [criterion('education_level', 'pass')], profile: { complete: false, verified: false } } });
    assert.match(html, /Complete profile/);
    html = await render([], { content: { criteria: [], profile: { complete: false, verified: true } } });
    assert.match(html, /Complete profile/);
    html = await render([], { content: { criteria: [], profile: { complete: true, verified: false } } });
    assert.match(html, /View verification/);
    assert.match(html, /Your profile must be verified before you apply/);
    assert.doesNotMatch(source, /can_apply|\.is_eligible\s*=|Start pre-screening/);
});

test('general provider previews show published rules without a live applicant comparison or profile links', async () => {
    const html = await render([criterion('education_level', 'missing')], { preview: true, previewScenario: 'general' });
    assert.match(html, /Who this scholarship is for/);
    assert.match(html, /data-eligibility-group="required"/);
    assert.doesNotMatch(html, /data-eligibility-summary|data-eligibility-result|href="\/dashboard\/profile|>Profile information needed</);
});

test('illustrative eligibility samples are explicitly labeled and never provide profile-edit actions', async () => {
    const html = await render([criterion('education_level', 'pass', { student_value: 'Example meets this rule' })], { preview: true, previewScenario: 'eligible' });
    assert.match(html, /data-preview-comparison-note/);
    assert.match(html, /These are not real applicant values/);
    assert.match(html, /Example meets this rule/);
    assert.doesNotMatch(html, /href="\/dashboard\/profile/);
});

test('written conditions keep their full statement and files link to the application section', async () => {
    const html = await render([], { content: { criteria: [criterion('documents', 'missing', { category: 'readiness' })], profile: { complete: true, verified: true }, written_conditions: 'Remain enrolled.', provider_review: [{ key: 'residency', label: 'Residence proof', statement: 'Bring an original residency certificate.', note: 'Checked by the provider.' }] } });
    assert.match(html, /data-provider-eligibility-conditions/);
    assert.match(html, /Remain enrolled/);
    assert.match(html, /Bring an original residency certificate/);
    assert.match(html, /Checked by the provider/);
    assert.match(html, /See how to apply/);
    assert.equal((html.match(/data-eligibility-criterion=/g) ?? []).length, 0);
});

test('empty and all-open programs do not claim that all requirements have matched', async () => {
    const html = await render([]);
    assert.match(html, /The provider has not published structured eligibility rules/);
    assert.doesNotMatch(html, /data-eligibility-summary/);
    const open = await render([criterion('course', 'info', { requirement: null })]);
    assert.doesNotMatch(open, /data-eligibility-group="required"/);
    assert.match(open, /No restrictions/);
});

test('the redesign retains all rules, full long values, correct profile actions, and meaningful status labels', async () => {
    const criteria = [
        criterion('education_level', 'pass', { label: 'Education level' }),
        criterion('location', 'pass', { label: 'Location', student_value: 'Barangay San Isidro, Antipolo City, Rizal, CALABARZON', requirement: 'Rizal, Metro Manila, Laguna, Cavite' }),
        criterion('income', 'fail', { label: 'Household income', student_value: 'PHP 25,000', requirement: 'Below PHP 20,000' }),
        criterion('course', 'fail', { label: 'Course', rule_mode: 'preferred', student_value: 'HUMSS', requirement: 'STEM' }),
    ];
    const html = await render(criteria);
    assert.equal((html.match(/data-eligibility-criterion=/g) ?? []).length, criteria.length);
    assert.match(html, /Barangay San Isidro, Antipolo City, Rizal, CALABARZON/);
    assert.match(html, /Rizal, Metro Manila, Laguna, Cavite/);
    assert.match(html, /aria-label="Required rule not met"/);
    assert.match(html, /aria-label="Preference not matched"/);
    assert.match(html, /Verified profile/);
    assert.ok(html.indexOf('data-eligibility-group="blocking"') < html.indexOf('data-eligibility-group="matched"'));
    assert.doesNotMatch(source, /line-clamp|\btruncate\b|<img|v-html/);
});

test('approved exceptions remain explicit and written values cannot inject markup', async () => {
    const html = await render([criterion('academic', 'fail', { exception_approved: true, label: '<img src=x onerror=alert(1)>', student_value: 80, requirement: '85%' })]);
    assert.match(html, /data-eligibility-group="exceptions"/);
    assert.match(html, /Exception approved/);
    assert.doesNotMatch(html, /data-eligibility-group="matched"|data-eligibility-group="blocking"|<img/);
    assert.match(html, /&lt;img/);
    assert.match(source, /<ApplicantEligibilityReview v-if="!preview && scholarshipId"/);
    assert.match(source, /@changed="\$emit\('review-changed'\)"/);
});
