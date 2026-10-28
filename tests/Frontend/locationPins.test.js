import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { hasLocationPin } from '../../resources/js/support/locationPins.js';

test('pins accept zero coordinates and valid decimal strings', () => {
    for (const pair of [[0, 0], ['14.5861000', '121.1756000'], [-90, -180], [90, 180]]) {
        assert.equal(hasLocationPin(...pair), true);
    }
});

test('pins reject missing, partial, non-finite and out-of-range coordinates', () => {
    for (const pair of [[null, null], ['', ''], [' ', 121], [undefined, 121], [14, ''], [91, 121], [14, 181], [NaN, 121], [14, Infinity], ['invalid', 121]]) {
        assert.equal(hasLocationPin(...pair), false);
    }
});

for (const file of ['LocationPinField.vue', 'LocationMapLink.vue', 'LocationMapModal.vue']) {
    test(`${file} compiles`, () => {
        const source = readFileSync(new URL(`../../resources/js/components/${file}`, import.meta.url), 'utf8');
        const { descriptor, errors } = parse(source);
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: file });
        const result = compileTemplate({
            id: file, filename: file, source: descriptor.template.content,
            compilerOptions: { bindingMetadata: script.bindings },
        });
        assert.deepEqual(result.errors, []);
    });
}

test('every previously text-only physical location has the shared pin picker', () => {
    for (const file of [
        'organization/OrganizationContactPage.vue',
        'programs/ProgramFormPage.vue',
        'selection/SelectionSessionFormPage.vue',
        'selection/components/SelectionSessionFormModal.vue',
        'releases/BenefitReleaseSchedulePage.vue',
    ]) {
        const source = readFileSync(new URL(`../../resources/js/pages/provider/${file}`, import.meta.url), 'utf8');
        assert.match(source, /<LocationPinField/);
        assert.match(source, /v-model:latitude=/);
        assert.match(source, /v-model:longitude=/);
    }
});

test('changing the address clears a stale pin and nested map escape does not close the parent form', () => {
    const picker = readFileSync(new URL('../../resources/js/components/LocationPinField.vue', import.meta.url), 'utf8');
    const modal = readFileSync(new URL('../../resources/js/components/LocationMapModal.vue', import.meta.url), 'utf8');
    assert.match(picker, /watch\(\(\) => props.address.trim\(\), clearPin\)/);
    assert.match(modal, /@keydown.esc.stop="close"/);
});
