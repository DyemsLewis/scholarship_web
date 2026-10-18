import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const components = [
    'WorkspaceDashboardFrame.vue',
    'WorkspaceDashboardHeader.vue',
    'WorkspaceDashboardItemList.vue',
    'WorkspaceDashboardSection.vue',
    'WorkspaceMetricStrip.vue',
];

for (const component of components) {
    test(`${component} compiles as a Vue component`, () => {
        const filename = new URL(`../../resources/js/components/workspace-dashboard/${component}`, import.meta.url);
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
