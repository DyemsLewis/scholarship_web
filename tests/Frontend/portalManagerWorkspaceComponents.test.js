import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    '../../resources/js/pages/AdminPortalManagerWorkspacePage.vue',
    '../../resources/js/pages/admin/portal/PortalManagerAreaPage.vue',
];

for (const path of components) {
    const component = path.split('/').at(-1);

    test(`${component} compiles as a focused portal manager workspace`, () => {
        const filename = new URL(path, import.meta.url);
        const source = readFileSync(filename, 'utf8');
        const { descriptor, errors } = parse(source, { filename: component });

        assert.deepEqual(errors, []);

        const script = descriptor.scriptSetup
            ? compileScript(descriptor, { id: component })
            : null;
        const template = compileTemplate({
            id: component,
            filename: component,
            source: descriptor.template?.content ?? '',
            compilerOptions: {
                bindingMetadata: script?.bindings ?? {},
            },
        });

        assert.deepEqual(template.errors, []);
    });
}
