import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const component = 'ProgramFormPage.vue';
const filename = new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url);
const source = readFileSync(filename, 'utf8');

test('provider program form compiles with the benefit dialog', () => {
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

test('adding and editing benefits share an accessible modal draft form', () => {
    assert.match(source, /@click="openBenefitDialog\(\)"/);
    assert.match(source, /@click="openBenefitDialog\(index\)"/);
    assert.match(source, /role="dialog"/);
    assert.match(source, /aria-labelledby="benefit-dialog-title"/);
    assert.match(source, /@submit\.prevent="saveBenefit"/);
    assert.doesNotMatch(source, /editingBenefit === index/);
});
