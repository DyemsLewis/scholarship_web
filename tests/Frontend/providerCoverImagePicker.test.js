import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    '../../resources/js/components/CoverImagePickerModal.vue',
    '../../resources/js/pages/provider/organization/OrganizationPublicProfilePage.vue',
    '../../resources/js/pages/provider/programs/ProgramFormPage.vue',
];

for (const path of components) {
    const filename = new URL(path, import.meta.url);
    const source = readFileSync(filename, 'utf8');
    const component = path.split('/').at(-1);

    test(`${component} compiles with provider-controlled cover images`, () => {
        const { descriptor, errors } = parse(source, { filename: component });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: component });
        const template = compileTemplate({
            id: component,
            filename: component,
            source: descriptor.template?.content ?? '',
            compilerOptions: { bindingMetadata: script.bindings },
        });
        assert.deepEqual(template.errors, []);
    });
}

test('the shared picker keeps system images and custom upload inside one modal', () => {
    const source = readFileSync(new URL(components[0], import.meta.url), 'utf8');
    assert.match(source, /System images/);
    assert.match(source, /Upload your own background/);
    assert.match(source, /emit\('select-system'/);
    assert.match(source, /emit\('upload'/);
});

test('provider forms use the shared picker instead of separate inline image controls', () => {
    const organization = readFileSync(new URL(components[1], import.meta.url), 'utf8');
    const program = readFileSync(new URL(components[2], import.meta.url), 'utf8');
    assert.match(organization, /<CoverImagePickerModal/);
    assert.match(program, /<CoverImagePickerModal/);
    assert.match(program, /fields\.cover_source = coverSource\.value/);
    assert.doesNotMatch(program, /Use the organization logo/);
});

test('the program form treats its square logo and wide background as separate media', () => {
    const program = readFileSync(new URL(components[2], import.meta.url), 'utf8');
    assert.match(program, /Scholarship logo/);
    assert.match(program, /Background image/);
    assert.match(program, /payload\.append\('image_file', logoFile\.value\)/);
    assert.match(program, /payload\.append\('cover_file', coverFile\.value\)/);
});
