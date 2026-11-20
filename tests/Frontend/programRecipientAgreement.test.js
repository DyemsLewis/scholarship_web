import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { computed, reactive, ref, watch } from 'vue';
import { normalizeProgramRecipientAgreement, recipientCommitmentOptions, recipientTermsFields, recipientTermsIssue } from '../../resources/js/support/programRecipientAgreement.js';

const fields = ['responsibilities', 'required_evidence', 'release_conditions', 'duration', 'noncompliance_consequence', 'exit_or_exception_process'];
const types = ['none', 'renewal', 'service', 'activities', 'reporting', 'custom'];

test('unanswered saved draft fields become empty strings for every recipient commitment option', () => {
    for (const commitment_type of types) {
        const saved = { commitment_type, ...Object.fromEntries(fields.map(field => [field, null])) };
        const before = JSON.stringify(saved);
        const form = normalizeProgramRecipientAgreement(saved);
        assert.equal(form.commitment_type, commitment_type);
        for (const field of fields) assert.equal(form[field].trim(), '');
        assert.equal(Boolean(form.release_conditions.trim()), false, 'Selecting a type must not bypass required conditions');
        assert.equal(JSON.stringify(saved), before);
    }
});

test('new, missing and partial agreements use the same complete editor defaults', () => {
    for (const saved of [undefined, null, {}, [], 'Old agreement text']) {
        const form = normalizeProgramRecipientAgreement(saved);
        assert.equal(form.commitment_type, 'provider_briefing');
        for (const field of fields) assert.equal(form[field], '');
    }
    const partial = normalizeProgramRecipientAgreement({ commitment_type: 'service', responsibilities: 'Attend the service activity.' });
    assert.equal(partial.responsibilities, 'Attend the service activity.');
    assert.equal(partial.duration, '');
});

test('saved terms and line breaks are preserved, while invalid field types cannot crash text validation', () => {
    const saved = { commitment_type: 'reporting', ...Object.fromEntries(fields.map(field => [field, `First line for ${field}\nSecond line`])) };
    assert.deepEqual(normalizeProgramRecipientAgreement(saved), saved);
    const legacy = normalizeProgramRecipientAgreement({ duration: 2, responsibilities: {}, required_evidence: [], release_conditions: false, noncompliance_consequence: NaN });
    assert.equal(legacy.duration, '2');
    for (const field of fields) assert.equal(typeof legacy[field].trim(), 'string');
});

test('the program builder normalizes both new forms and loaded programs without weakening agreement validation', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    assert.match(source, /recipientAgreement: normalizeProgramRecipientAgreement\(\)/);
    assert.match(source, /recipientAgreement: normalizeProgramRecipientAgreement\(program.recipient_agreement\)/);
    assert.match(source, /recipientTermsIssue\(form.value.recipientAgreement\)/);
    assert.match(source, /!selectionTermsIssue.value/);
    assert.match(source, /<ProgramRecipientTermsEditor v-model="form.recipientAgreement"/);
});

test('submission still requires the applicable agreement details', () => {
    assert.match(recipientTermsIssue({}), /Choose a recipient commitment/);
    assert.match(recipientTermsIssue({ commitment_type: 'none' }), /Conditions for receiving benefits/);
    assert.equal(recipientTermsIssue({ commitment_type: 'none', release_conditions: 'After acceptance.' }), '');
    assert.match(recipientTermsIssue({ commitment_type: 'reporting', release_conditions: 'After acceptance.' }), /Recipient responsibilities/);
    assert.equal(recipientTermsIssue({ commitment_type: 'reporting', ...Object.fromEntries(fields.map(field => [field, 'Set'])) }), '');
});

test('recipient terms renders as a modal and edits a copy until Apply', () => {
    const file = 'resources/js/pages/provider/programs/components/ProgramRecipientTermsEditor.vue';
    const source = readFileSync(file, 'utf8');
    const { descriptor, errors } = parse(source, { filename: file });
    assert.deepEqual(errors, []);
    const compiled = compileScript(descriptor, { id: file });
    assert.deepEqual(compileTemplate({ id: file, filename: file, source: descriptor.template.content, compilerOptions: { bindingMetadata: compiled.bindings } }).errors, []);
    const [summary, modal] = descriptor.template.content.split('<EligibilityOptionsModal');
    assert.doesNotMatch(summary, /<textarea/);
    assert.match(modal, /apply-label="Apply changes"/);

    const props = reactive({ modelValue: { commitment_type: 'none', responsibilities: 'Keep prior draft obligations.' }, disabled: false });
    const events = [];
    const dependencies = {
        computed, ref, watch, normalizeProgramRecipientAgreement, recipientCommitmentOptions, recipientTermsFields, recipientTermsIssue,
        defineProps: () => props, defineEmits: () => (...args) => events.push(args),
    };
    const body = descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const state = new Function(...Object.keys(dependencies), body + '\nreturn { show, close, apply, changeCommitment, draft, fields, open };')(...Object.values(dependencies));
    state.show();
    assert.deepEqual(state.fields.value.map(field => field.key), ['release_conditions']);
    state.draft.value.release_conditions = 'Instructions to discard.';
    state.close();
    state.show();
    assert.equal(state.draft.value.release_conditions, '');
    assert.deepEqual(events, []);

    state.draft.value.release_conditions = 'Collect at the provider office.';
    state.apply();
    assert.equal(state.open.value, false);
    assert.equal(events[0][1].release_conditions, 'Collect at the provider office.');
    assert.equal(events[0][1].responsibilities, 'Keep prior draft obligations.');

    state.changeCommitment('reporting');
    props.modelValue = events[1][1];
    state.show();
    assert.equal(state.fields.value.length, 6);
    props.disabled = true;
    state.apply();
    assert.equal(events.length, 2);
});
