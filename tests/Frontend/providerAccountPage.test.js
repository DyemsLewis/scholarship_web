import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const filename = new URL('../../resources/js/pages/provider/ProviderAccountPage.vue', import.meta.url);
const source = readFileSync(filename, 'utf8');

test('provider account page compiles with personal and security forms', () => {
    const { descriptor, errors } = parse(source, { filename: 'ProviderAccountPage.vue' });

    assert.deepEqual(errors, []);

    const script = compileScript(descriptor, { id: 'ProviderAccountPage' });
    const template = compileTemplate({
        id: 'ProviderAccountPage',
        filename: 'ProviderAccountPage.vue',
        source: descriptor.template?.content ?? '',
        compilerOptions: { bindingMetadata: script.bindings },
    });

    assert.deepEqual(template.errors, []);
    assert.match(source, /\/provider\/account\/data/);
    assert.match(source, />My profile</);
    assert.match(source, /Password security/);
    assert.match(source, /without changing the organization profile/);
});
