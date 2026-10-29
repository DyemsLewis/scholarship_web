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
const code = script.content.replace(/from (['"])(vue|\.\.\/support\/[^'"]+)\1/g, (_, quote, module) => `from ${JSON.stringify(module === 'vue' ? import.meta.resolve('vue') : new URL(`../../resources/js/support/${module.split('/').at(-1)}.js`, import.meta.url).href)}`);
const { default: Panel } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const criterion = (key, status, extra = {}) => ({ key, label: key === 'academic' ? 'Academic result' : 'Education level', category: 'eligibility', status, student_value: 'senior_high_school', requirement: 'senior_high_school', rule_mode: 'required', comparison: 'Compared with your saved profile.', ...extra });
const render = (criteria, overrides = {}) => renderToString(createSSRApp(Panel, {
    content: { criteria, profile: { complete: true, verified: true } },
    verdict: { eyebrow: 'Profile comparison', title: 'Review the program rules', description: 'Comparison guidance.' },
    visual: { image: '/images/filipino-senior-high.jpg', alt: 'Students in the Philippines', caption: 'Compare the published requirements.' },
    applicationHref: '/dashboard/scholarships/1/application',
    ...overrides,
}));

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

test('required and preferred criteria are separate and required issues come before matches', () => {
    const original = [criterion('education_level', 'pass'), criterion('income', 'fail'), criterion('course', 'fail', { rule_mode: 'preferred' }), criterion('school_type', 'info', { requirement: null })];
    const groups = scholarshipEligibilityGroups(scholarshipEligibilityRows(original));
    assert.deepEqual(groups.map((group) => group.id), ['required', 'preferred']);
    assert.deepEqual(groups[0].rows.map((row) => row.key), ['income', 'education_level']);
    assert.equal(groups[1].rows[0].result.label, 'Preference not matched');
    assert.equal(original[0].key, 'education_level');
    assert.equal('result' in original[0], false);
    const legacy = scholarshipEligibilityGroups(scholarshipEligibilityRows([criterion('income', 'fail', { rule_mode: 'optional', is_blocking: false })]));
    assert.equal(legacy[0].id, 'required');
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
    assert.match(html, /data-eligibility-group="required"/);
    assert.match(html, /data-eligibility-group="preferred"/);
    assert.match(html, /Only correct your profile if the saved information is inaccurate/);
    assert.match(html, /href="\/dashboard\/profile\?section=household"/);
    assert.match(html, /href="\/dashboard\/profile\?section=verification"/);
    assert.doesNotMatch(html, /% profile match|You can apply/);
});

test('matched explanations start collapsed while attention explanations remain visible', async () => {
    const html = await render([criterion('education_level', 'pass'), criterion('income', 'fail')]);
    assert.match(html, /<details[^>]+data-eligibility-explanation(?![^>]*open)/);
    assert.match(html, /<div[^>]+data-eligibility-explanation/);
    assert.match(source, /md:grid-cols-\[minmax\(0,1.4fr\)_minmax\(0,1fr\)_12rem\]/);
    assert.match(source, /data-eligibility-result/);
});

test('unrestricted criteria remain available without being counted as successful comparisons', async () => {
    const html = await render([criterion('education_level', 'pass'), criterion('course', 'info', { requirement: null })]);
    assert.match(html, /<details[^>]+data-unrestricted-criteria(?![^>]*open)/);
    assert.match(html, /They are not counted as profile matches/);
    assert.match(html, /<dt[^>]*>Matched<\/dt><dd[^>]*>1<\/dd>/);
    assert.match(html, /<dt[^>]*>Unrestricted<\/dt><dd[^>]*>1<\/dd>/);
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
    assert.match(html, /Not compared/);
    assert.doesNotMatch(html, /data-eligibility-summary|href="\/dashboard\/profile|>Profile information needed</);
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
    assert.match(html, /View application files and process/);
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
