import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    '../../resources/js/pages/provider/selection/SelectionActivityDirectoryPage.vue',
    '../../resources/js/pages/provider/selection/components/SelectionSessionFormModal.vue',
];

for (const path of components) {
    const filename = new URL(path, import.meta.url);
    const source = readFileSync(filename, 'utf8');
    const component = path.split('/').at(-1);

    test(`${component} compiles for modal session scheduling`, () => {
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

test('the selection directory creates sessions without navigating to the standalone form', () => {
    const directory = readFileSync(new URL(components[0], import.meta.url), 'utf8');
    assert.match(directory, /@click="openSessionModal\(\)"/);
    assert.match(directory, /<SelectionSessionFormModal/);
    assert.match(directory, /Schedule an optional appointment/);
    assert.match(directory, /Ready for document review/);
    assert.doesNotMatch(directory, /href="\/provider\/workspaces\/selection\/sessions\/create"/);
});

test('the modal keeps scheduling and delivery as two focused steps', () => {
    const modal = readFileSync(new URL(components[1], import.meta.url), 'utf8');
    assert.match(modal, /aria-modal="true"/);
    assert.match(modal, />Schedule<\/span>/);
    assert.match(modal, />Delivery<\/span>/);
    assert.match(modal, /@submit\.prevent="saveSession"/);
    assert.match(modal, /application_ids: \[Number\(props\.applicationId\)\]/);
});
