import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { computed, reactive, ref, watch } from 'vue';
import { commonProgramDocuments, commonDocumentLabel, requirementLines, splitDocumentRequirements, addCommonDocument, removeCommonDocument, setSpecificDocuments, documentGroupConflict, programDocumentErrors } from '../../resources/js/support/programDocumentRequirements.js';

const pickerFile = 'resources/js/pages/provider/programs/components/DocumentRequirementsPicker.vue';
const pickerSource = readFileSync(pickerFile, 'utf8');

function picker(modelValue, otherGroupValue = '') {
    const props = reactive({ modelValue, otherGroupValue, disabled: false });
    const events = [];
    const dependencies = { computed, ref, useId: () => 'files', watch, commonProgramDocuments, commonDocumentLabel, requirementLines, splitDocumentRequirements, addCommonDocument, removeCommonDocument, setSpecificDocuments, documentGroupConflict, defineProps: () => props, defineEmits: () => (...args) => events.push(args) };
    const script = parse(pickerSource).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const state = new Function(...Object.keys(dependencies), script + '\nreturn { show, close, apply, toggleDocument, open, draftSpecific, draftSelected, selectedCount, draftCount, tooLong };')(...Object.values(dependencies));
    return { ...state, props, events };
}

test('common requirements recognize existing wording without rewriting saved files', () => {
    const value = 'Proof of enrollment\r\nSchool ID\r\nSigned science proposal';
    assert.deepEqual(splitDocumentRequirements(value), { common: ['Proof of enrollment', 'School ID'], specific: ['Signed science proposal'] });
    assert.equal(setSpecificDocuments(value, 'Updated project proposal'), 'Proof of enrollment\nSchool ID\nUpdated project proposal');
    assert.equal(commonDocumentLabel('  school   ID  '), 'Recent school ID');
});
test('selection adds only catalog entries and does not duplicate aliases', () => {
    assert.equal(addCommonDocument('School ID', 'Recent school ID'), 'School ID');
    assert.equal(addCommonDocument('', 'Recent school ID'), 'Recent school ID');
    assert.equal(addCommonDocument('School ID', 'Not in catalog'), 'School ID');
    assert.equal(removeCommonDocument('School ID\nResearch proposal', 'Recent school ID'), 'Research proposal');
    assert.equal(removeCommonDocument('Research proposal', 'Not in catalog'), 'Research proposal');
});
test('specific documents remain editable while common entries are selected and deduplicated', () => {
    assert.equal(setSpecificDocuments('School ID\nOld custom file', 'school id\nNew custom file\nNew custom file'), 'School ID\nNew custom file');
    assert.deepEqual(splitDocumentRequirements(setSpecificDocuments('', 'PSA birth certificate\nA program research plan')), { common: ['PSA birth certificate'], specific: ['A program research plan'] });
    assert.equal(setSpecificDocuments('School ID\nOld custom file', ''), 'School ID');
    assert.deepEqual(splitDocumentRequirements(null), { common: [], specific: [] });
});
test('the catalog has unique canonical labels and does not restore the removed no scholarship proof', () => {
    assert.equal(new Set(commonProgramDocuments.map(x => x.label)).size, commonProgramDocuments.length);
    assert.ok(!commonProgramDocuments.some(x => /no.*scholarship/i.test(x.label)));
});
test('document picker compiles and is used for all three create and edit requirement lists', () => {
    const { descriptor, errors } = parse(pickerSource, { filename: pickerFile }); assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: pickerFile });
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: pickerFile, id: pickerFile, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    const [row, modal] = descriptor.template.content.split('<EligibilityOptionsModal');
    assert.match(row, /None selected/);
    assert.doesNotMatch(row, /<textarea|<select|v-for/);
    assert.match(modal, /eyebrow="Application documents"/);
    assert.match(modal, /type="checkbox"/);
    assert.match(modal, /v-model="draftSpecific"/);
    const page = readFileSync('resources/js/pages/provider/programs/ProgramFormPage.vue', 'utf8');
    for (const field of ['requirements', 'optionalRequirements', 'postQualificationRequirements']) {
        assert.ok(page.includes(`<DocumentRequirementsPicker v-model="form.${field}"`));
        assert.ok(!page.includes(`<textarea v-model="form.${field}"`));
    }
});

test('document changes are staged in the modal and Cancel never alters the row or saved wording', () => {
    const state = picker('A signed proposal\nSchool ID');
    state.show();
    assert.equal(state.draftSelected.value.has('Recent school ID'), true);
    state.toggleDocument('Passport', true); // Not a catalog entry.
    state.toggleDocument('Certificate of enrollment', true);
    state.draftSpecific.value = 'Research plan';
    assert.equal(state.draftCount.value, 3);
    assert.equal(state.selectedCount.value, 2);
    state.close();
    state.show();
    assert.equal(state.draftSpecific.value, 'A signed proposal');
    state.apply();
    assert.deepEqual(state.events, []);
    state.show();
    state.toggleDocument('Certificate of enrollment', true);
    state.toggleDocument('Latest report card or grades', true);
    state.draftSpecific.value = 'Research plan\nSchool ID\nResearch plan';
    state.apply();
    assert.deepEqual(state.events, [['update:modelValue', 'School ID\nCertificate of enrollment\nLatest report card or grades\nResearch plan']]);
    assert.equal(state.open.value, false);
    state.props.modelValue = state.events[0][1];
    assert.equal(state.selectedCount.value, 4);
});

test('optional selections can be cleared, while a locked or oversized draft cannot be applied', () => {
    const state = picker('School ID');
    state.show();
    state.draftSpecific.value = 'x'.repeat(5000);
    assert.equal(state.tooLong.value, true);
    state.apply();
    assert.deepEqual(state.events, []);
    assert.equal(state.open.value, true);
    state.draftSpecific.value = '';
    state.toggleDocument('Recent school ID', false);
    state.props.disabled = true;
    state.apply();
    assert.deepEqual(state.events, []);
    state.props.disabled = false;
    state.apply();
    assert.deepEqual(state.events, [['update:modelValue', '']]);
});

test('different names for the same document cannot be applied to both pre-screening lists', () => {
    const state = picker('', 'School ID');
    state.show();
    state.toggleDocument('Recent school ID', true);
    state.apply();
    assert.equal(state.open.value, true);
    assert.deepEqual(state.events, []);
    state.toggleDocument('Recent school ID', false);
    state.toggleDocument('Certificate of enrollment', true);
    state.apply();
    assert.deepEqual(state.events, [['update:modelValue', 'Certificate of enrollment']]);
    assert.match(documentGroupConflict('A signed   proposal', 'a SIGNED proposal'), /both required and optional/);
});

test('profile review hides inactive lists and flags an explicit document eligibility condition', () => {
    const draft = { applicationMode: 'provider_review', requirements: 'School ID', optionalRequirements: 'Recent school ID' };
    assert.deepEqual(programDocumentErrors(draft), {});
    assert.match(programDocumentErrors({ ...draft, eligibilityConditions: [{ key: 'required_documents' }] }).application_mode, /requires documents/);
    assert.match(programDocumentErrors({ ...draft, applicationMode: 'online' }).optional_requirements, /both required and optional/);
    const page = readFileSync('resources/js/pages/provider/programs/ProgramFormPage.vue', 'utf8');
    assert.match(page, /v-if="usesScreeningFiles"[\s\S]+?v-model="form.optionalRequirements"[\s\S]+?<\/template>/);
    assert.match(page, /v-model="form.postQualificationRequirements"/);
    assert.match(page, /screeningFilesRequiredByEligibility/);
});
