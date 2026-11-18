import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { programOnboardingPolicy, onboardingPolicyError } from '../../resources/js/support/recipientOnboarding.js';

for (const file of [
    'components/provider/RecipientOnboardingPanel.vue',
    'components/provider/RecipientOnboardingDialog.vue',
    'pages/provider/programs/components/ProgramOnboardingPolicyFields.vue',
    'pages/provider/recipients/RecipientRecordPage.vue',
    'pages/provider/programs/ProgramFormPage.vue',
]) {
    test(`${file} compiles with provider onboarding`, () => {
        const source = readFileSync(`resources/js/${file}`, 'utf8');
        const { descriptor, errors } = parse(source, { filename: file });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content,
            compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('legacy policies are not silently opted in and client validation preserves required instructions', () => {
    assert.equal(programOnboardingPolicy(null), null);
    assert.equal(programOnboardingPolicy(undefined), null);
    assert.equal(programOnboardingPolicy({ final_agreement_required: false, instructions: 'Unused' }).instructions, null);
    const policy = { final_agreement_required: true, instructions: 'First line\nSecond line' };
    const normalized = programOnboardingPolicy(policy);
    assert.equal(normalized.instructions, policy.instructions);
    assert.equal(onboardingPolicyError(normalized), '');
    assert.ok(onboardingPolicyError({ final_agreement_required: true, instructions: '  ' }));
    assert.ok(onboardingPolicyError({ final_agreement_required: true, instructions: 'x'.repeat(3001) }));
});

test('onboarding mutations require the designed confirmation and keep contract files outside the platform', () => {
    const dialog = readFileSync('resources/js/components/provider/RecipientOnboardingDialog.vue', 'utf8');
    assert.match(dialog, /useModalAccessibility/);
    assert.match(dialog, /<ConfirmationDialog/);
    assert.match(dialog, /!reviewing.value \|\| busy.value \|\| !valid.value/);
    assert.match(dialog, /expected_revision: props.onboarding.revision/);
    assert.match(dialog, /failure.response\?\.status === 409/);
    assert.match(dialog, /Do not upload the contract here/);
    assert.match(dialog, /Attendance alone is not proof of signing/);
    assert.match(dialog, /:min="onboarding.minimum_completed_on/);
    assert.match(dialog, /:max="today"/);
    assert.doesNotMatch(dialog, /type="file"|window\.confirm|window\.alert/);
    const page = readFileSync('resources/js/pages/provider/recipients/RecipientRecordPage.vue', 'utf8');
    assert.match(page, /key: 'onboarding', label: 'Onboarding'/);
    assert.match(page, /@open-section="updateSection"/);
    assert.match(page, /<RecipientOnboardingPanel v-else-if="activeSection === 'onboarding'"/);
});

test('program setup persists the policy only when configured and locks it after applications', () => {
    const page = readFileSync('resources/js/pages/provider/programs/ProgramFormPage.vue', 'utf8');
    assert.match(page, /onboardingPolicy: null/);
    assert.match(page, /onboardingPolicy: programOnboardingPolicy\(program.onboarding_policy\)/);
    assert.match(page, /if \(form.value.onboardingPolicy !== null\) fields.onboarding_policy/);
    assert.match(page, /<ProgramOnboardingPolicyFields[^>]+:fixed="applicationsCount > 0"/);
    const fields = readFileSync('resources/js/pages/provider/programs/components/ProgramOnboardingPolicyFields.vue', 'utf8');
    assert.match(fields, /if \(props.disabled \|\| props.fixed\) return/);
    assert.match(fields, /:disabled="disabled \|\| fixed"/);
    assert.doesNotMatch(fields, /type="file"/);
});
