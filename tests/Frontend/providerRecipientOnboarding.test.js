import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, reactive, ref, watch } from 'vue';
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
    assert.match(fields, /:view-only="fixed"/);
    assert.doesNotMatch(fields, /type="file"/);
});

test('final agreement changes are staged, require instructions when enabled and cannot bypass the locked policy', () => {
    const source = readFileSync('resources/js/pages/provider/programs/components/ProgramOnboardingPolicyFields.vue', 'utf8');
    const { descriptor } = parse(source);
    assert.doesNotMatch(descriptor.template.content.split('<EligibilityOptionsModal')[0], /<textarea|type="checkbox"/);
    const props = reactive({ modelValue: null, disabled: false, fixed: false });
    const events = [];
    const deps = { computed, ref, watch, onboardingPolicyError, programOnboardingPolicy, defineProps: () => props, defineEmits: () => (...args) => events.push(args) };
    const body = descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const state = new Function(...Object.keys(deps), body + '\nreturn { show, close, apply, open, draftRequired, draftInstructions };')(...Object.values(deps));
    state.show();
    state.draftRequired.value = true;
    state.apply();
    assert.equal(state.open.value, true);
    assert.deepEqual(events, []);
    state.draftInstructions.value = 'Instructions to discard.';
    state.close();
    state.show();
    assert.equal(state.draftRequired.value, false);
    assert.equal(state.draftInstructions.value, '');

    state.draftRequired.value = true;
    state.draftInstructions.value = 'Complete the agreement at orientation.';
    state.apply();
    assert.equal(state.open.value, false);
    assert.deepEqual(events[0], ['update:modelValue', { version: 1, final_agreement_required: true, instructions: 'Complete the agreement at orientation.' }]);

    props.fixed = true;
    props.modelValue = events[0][1];
    state.show();
    assert.equal(state.draftInstructions.value, 'Complete the agreement at orientation.');
    state.draftRequired.value = false;
    state.apply();
    assert.equal(events.length, 1);
});
