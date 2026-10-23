import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const filename = new URL('../../resources/js/pages/UserApplicationsPage.vue', import.meta.url);
const source = readFileSync(filename, 'utf8');

test('applicant application start flow compiles', () => {
    const { descriptor, errors } = parse(source, { filename: 'UserApplicationsPage.vue' });

    assert.deepEqual(errors, []);

    const script = compileScript(descriptor, { id: 'applicant-application-start' });
    const template = compileTemplate({
        id: 'applicant-application-start',
        filename: 'UserApplicationsPage.vue',
        source: descriptor.template?.content ?? '',
        compilerOptions: { bindingMetadata: script.bindings },
    });

    assert.deepEqual(template.errors, []);
});

test('eligibility cards use distinct status colors without repeating profile values', () => {
    assert.match(source, /eligibilityCriterionCardClass/);
    assert.match(source, /pass: 'border-emerald-200 bg-emerald-50\/50'/);
    assert.match(source, /fail: 'border-rose-300 bg-rose-50\/60'/);
    assert.match(source, /missing: 'border-amber-300 bg-amber-50\/60'/);
    assert.match(source, /info: 'border-sky-200 bg-sky-50\/60'/);
    assert.doesNotMatch(source, /Your profile:/);
    assert.doesNotMatch(source, /profileValue:/);
});

test('eligibility summary links to the scholarship eligibility details', () => {
    assert.match(source, /`\/dashboard\/scholarships\/\$\{selectedScholarship\.value\.id\}\/eligibility`/);
    assert.match(source, /View full eligibility/);
});
