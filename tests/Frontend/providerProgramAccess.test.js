import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const source = readFileSync('resources/js/pages/provider/programs/ProgramAccessPage.vue', 'utf8');
test('program access notice compiles and directs authorized profile managers to verification', () => {
    const { descriptor, errors } = parse(source);
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'program-access' });
    assert.deepEqual(compileTemplate({ id: 'program-access', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    assert.match(source, /data-program-access-notice/);
    assert.match(source, /v-if="access.can_manage_profile"/);
    assert.match(source, /Ask your organization profile manager/);
    assert.doesNotMatch(source, /axios\.(post|patch|put|delete)/);
});
