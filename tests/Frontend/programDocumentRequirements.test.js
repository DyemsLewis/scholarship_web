import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { commonProgramDocuments, commonDocumentLabel, splitDocumentRequirements, addCommonDocument, removeCommonDocument, setSpecificDocuments } from '../../resources/js/support/programDocumentRequirements.js';

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
    const file = 'resources/js/pages/provider/programs/components/DocumentRequirementsPicker.vue';
    const source = readFileSync(file, 'utf8');
    const { descriptor, errors } = parse(source, { filename: file }); assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: file });
    assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: file, id: file, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.ok(source.includes('@input="inputSpecific"')); assert.ok(source.includes('@blur="finishSpecific"'));
    const page = readFileSync('resources/js/pages/provider/programs/ProgramFormPage.vue', 'utf8');
    for (const field of ['requirements', 'optionalRequirements', 'postQualificationRequirements']) {
        assert.ok(page.includes(`<DocumentRequirementsPicker v-model="form.${field}"`));
        assert.ok(!page.includes(`<textarea v-model="form.${field}"`));
    }
});
