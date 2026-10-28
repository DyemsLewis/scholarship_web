import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { eligibilityCriterionPresentation as present, eligibilityOverview } from '../../resources/js/support/applicantEligibility.js';

test('required and preferred failures have different meanings, with strict legacy defaults', () => {
    assert.equal(present({ status: 'fail' }).label, 'Required rule not met');
    assert.equal(present({ status: 'fail', is_blocking: false }).state, 'blocked');
    assert.equal(present({ status: 'fail', rule_mode: 'optional' }).state, 'blocked');
    assert.deepEqual(present({ status: 'fail', rule_mode: 'preferred' }), {
        status: 'preferred', label: 'Preference not matched', state: 'attention', modeLabel: 'Preferred',
    });
});

test('grade scales needing review are neither missing profile data, open rules, nor matches', () => {
    for (const criterion of [
        { status: 'missing', comparison_mode: 'scale_mismatch' },
        { status: 'info', comparison_mode: 'manual_review' },
        { status: 'pass', needs_review: true },
        { key: 'academic', status: 'info', requirement: 'Competency evidence' },
    ]) {
        assert.equal(present(criterion).label, 'Needs review');
        assert.equal(present(criterion).state, 'attention');
    }
    assert.equal(present({ status: 'missing', comparison_mode: 'missing_value' }).label, 'Profile information needed');
    assert.equal(present({ status: 'info', key: 'academic', requirement: null }).label, 'Open');
    assert.equal(present({ status: 'info' }).modeLabel, '');
    assert.equal(present({ category: 'readiness', status: 'missing' }).modeLabel, '');
    assert.equal(present({ key: 'documents', status: 'missing' }).label, 'Files needed');
});

test('a high score does not hide a required blocker and a low score does not reject preferences', () => {
    const required = eligibilityOverview({ score: 99, criteria: [{ key: 'income', label: 'Household income', status: 'fail' }] });
    assert.equal(required.state, 'blocked');
    assert.match(required.description, /Household income/);
    const preferred = eligibilityOverview({ score: 0, criteria: [{ key: 'course', status: 'fail', rule_mode: 'preferred' }] });
    assert.equal(preferred.label, 'Preferences differ');
    assert.doesNotMatch(preferred.description, /You can apply/);
    assert.equal('can_apply' in preferred, false);
});

test('review, incomplete comparison, and missing responses are not presented as all matched', () => {
    assert.equal(eligibilityOverview({ criteria: [{ status: 'missing', needs_review: true }] }).label, 'Needs review');
    assert.equal(eligibilityOverview({ criteria: [{ status: 'missing' }] }).label, 'Profile information needed');
    assert.equal(eligibilityOverview(null).label, 'Not checked');
    const guidance = { state: 'blocked', title: 'Required rules not met', description: 'Income is required.' };
    assert.deepEqual(eligibilityOverview({ guidance }), guidance);
});

for (const file of ['components/EligibilityConditionList.vue', 'pages/UserScholarshipsPage.vue']) {
    test(`${file} compiles with shared eligibility presentation`, () => {
        const source = readFileSync(new URL(`../../resources/js/${file}`, import.meta.url), 'utf8');
        const { descriptor, errors } = parse(source);
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        const result = compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(result.errors, []);
    });
}

test('application guidance includes enrollment and scholarship support without a percentage verdict', () => {
    const source = readFileSync(new URL('../../resources/js/pages/UserApplicationsPage.vue', import.meta.url), 'utf8');
    assert.match(source, /key: 'enrollment_status'/);
    assert.match(source, /key: 'current_scholarship_support'/);
    assert.match(source, /selectedEligibilityOverview\.title/);
    assert.doesNotMatch(source, /Your profile matches the structured restrictions|Structured profile restrictions matched|% profile match/);
});
