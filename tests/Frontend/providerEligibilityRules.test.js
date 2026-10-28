import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { configuredEligibilityRules, eligibilityRuleKeys, isOpenEligibilityRule, normalizeEligibilityRuleModes } from '../../resources/js/support/providerEligibilityRules.js';

const blankForm = () => ({
    eligibilityRuleModes: normalizeEligibilityRuleModes(), eligibilityConditions: [],
    eligibleEducationLevels: [], eligibleCourses: '', eligibleYearLevels: '', eligibleLocations: '',
    incomeRequirement: 'Any', eligibleSchoolTypes: [], minimumGradeScale: '', minimumGwa: '', excludeCurrentRecipients: false,
});

test('frontend rule keys match the backend policy and missing values remain Required', () => {
    const policy = readFileSync(new URL('../../app/Support/EligibilityRulePolicy.php', import.meta.url), 'utf8');
    const keys = [...policy.match(/public const KEYS = \[([\s\S]*?)\];/)[1].matchAll(/'([^']+)'/g)].map((match) => match[1]);
    assert.deepEqual(eligibilityRuleKeys, keys);
    assert.deepEqual(Object.values(normalizeEligibilityRuleModes()), keys.map(() => 'required'));
    assert.equal(normalizeEligibilityRuleModes({ course: 'preferred' }).course, 'preferred');
    assert.equal(normalizeEligibilityRuleModes({ course: 'optional' }).course, 'required');
    assert.equal(normalizeEligibilityRuleModes({ documents: 'preferred' }).documents, undefined);
});

test('empty and unrestricted fields do not create mode selectors or review rows', () => {
    for (const value of ['', 'Any', 'Open to all', 'Nationwide', 'Any course', 'No restrictions']) {
        assert.equal(isOpenEligibilityRule(value), true);
        assert.deepEqual(configuredEligibilityRules({ ...blankForm(), eligibleCourses: value, eligibleLocations: value }), []);
    }
    assert.equal(isOpenEligibilityRule('Not nationwide'), false);
    assert.equal(isOpenEligibilityRule('Metro Manila'), false);
});

test('all configured rules are summarized without treating preferences as required', () => {
    const form = {
        ...blankForm(), eligibleEducationLevels: ['senior_high_school'], eligibleCourses: 'STEM', eligibleYearLevels: 'Grade 12',
        eligibleLocations: 'Rizal', incomeRequirement: 'PHP 10,000 - 20,000', eligibleSchoolTypes: ['public'],
        minimumGradeScale: 'percentage', minimumGwa: 85, excludeCurrentRecipients: true,
        eligibilityRuleModes: normalizeEligibilityRuleModes({ academic: 'preferred', location: 'preferred' }),
        eligibilityConditions: [{ key: 'currently_enrolled', verification_type: 'automatic', statement: 'Must be enrolled.' }],
    };
    const rules = configuredEligibilityRules(form, {
        educationLevels: [['senior_high_school', 'Senior High School']], schoolTypes: [['public', 'Public school']],
    });
    assert.equal(rules.length, 9);
    assert.equal(rules.filter((rule) => rule.mode === 'required').length, 7);
    assert.equal(rules.filter((rule) => rule.mode === 'preferred').length, 2);
    assert.equal(rules.find((rule) => rule.key === 'education_level').value, 'Senior High School');
    assert.equal(rules.find((rule) => rule.key === 'income').value, 'PHP 10,000 - 20,000');
    assert.equal(rules.find((rule) => rule.key === 'academic').value, 'Minimum average: 85.00%');
});

test('clearing a rule hides it without discarding its saved preference', () => {
    const form = { ...blankForm(), eligibleCourses: 'STEM', eligibilityRuleModes: normalizeEligibilityRuleModes({ course: 'preferred' }) };
    assert.equal(configuredEligibilityRules(form)[0].mode, 'preferred');
    form.eligibleCourses = 'Any';
    assert.deepEqual(configuredEligibilityRules(form), []);
    assert.equal(form.eligibilityRuleModes.course, 'preferred');
    form.eligibleCourses = 'ABM';
    assert.equal(configuredEligibilityRules(form)[0].mode, 'preferred');
});

test('academic review labels do not invent conversions and enrollment controls only cover automatic checks', () => {
    for (const [scale, label] of [['other', 'Academic evidence reviewed by the provider'], ['pass_fail', 'Pass/fail or competency evidence']]) {
        const rules = configuredEligibilityRules({ ...blankForm(), minimumGradeScale: scale, minimumGwa: 90 });
        assert.equal(rules[0].value, label);
    }
    assert.deepEqual(configuredEligibilityRules({ ...blankForm(), eligibilityConditions: [{ key: 'currently_enrolled', verification_type: 'provider_verification' }] }), []);
    assert.deepEqual(configuredEligibilityRules({ ...blankForm(), minimumGradeScale: '', minimumGwa: '' }), []);
});

for (const path of [
    '../../resources/js/pages/provider/programs/ProgramFormPage.vue',
    '../../resources/js/pages/provider/programs/components/EligibilityRuleModeSelect.vue',
    '../../resources/js/pages/provider/programs/components/EligibilityRulesSummary.vue',
]) {
    test(`${path.split('/').at(-1)} compiles`, () => {
        const { descriptor, errors } = parse(readFileSync(new URL(path, import.meta.url), 'utf8'));
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({ id: path, filename: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}

test('the builder restores, serializes and reviews rule modes instead of resetting them', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    assert.match(source, /eligibilityRuleModes: normalizeEligibilityRuleModes\(program.eligibility_rule_modes\)/);
    assert.match(source, /eligibility_rule_modes: JSON.stringify\(normalizeEligibilityRuleModes\(form.value.eligibilityRuleModes\)\)/);
    assert.match(source, /<EligibilityRulesSummary class="mt-6" :rules="eligibilityRules"/);
    assert.match(source, /Changing a rule between Required and Preferred sends this program back/);
    assert.doesNotMatch(source, /Exclude applicants who currently receive another scholarship/);
    assert.match(source, /@change="form.minimumGwa = ''"/);
});
