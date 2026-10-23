import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const filename = new URL('../../resources/js/pages/provider/ProviderSidebar.vue', import.meta.url);
const source = readFileSync(filename, 'utf8');

test('provider sidebar compiles with one dashboard entry', () => {
    const { descriptor, errors } = parse(source, { filename: 'ProviderSidebar.vue' });

    assert.deepEqual(errors, []);

    const script = compileScript(descriptor, { id: 'ProviderSidebar' });
    const template = compileTemplate({
        id: 'ProviderSidebar',
        filename: 'ProviderSidebar.vue',
        source: descriptor.template?.content ?? '',
        compilerOptions: {
            bindingMetadata: script.bindings,
        },
    });

    assert.deepEqual(template.errors, []);
    assert.equal((source.match(/label: 'Dashboard'/g) ?? []).length, 1);
    assert.doesNotMatch(source, /roleDashboardLink/);
    assert.match(source, /ProviderAccountSidebarLink/);
});
