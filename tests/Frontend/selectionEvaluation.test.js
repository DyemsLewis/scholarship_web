import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

for (const path of [
    '../../resources/js/components/SelectionEvaluationSummary.vue',
    '../../resources/js/components/ProfileMatchDialog.vue',
    '../../resources/js/pages/provider/decisions/FinalDecisionRecordPage.vue',
    '../../resources/js/pages/UserScholarshipsPage.vue',
]) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8');
    test(`${path.split('/').at(-1)} compiles with evaluation explanations`, () => {
        const { descriptor, errors } = parse(source, { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({
            id: path, filename: path, source: descriptor.template.content,
            compilerOptions: { bindingMetadata: script.bindings },
        });
        assert.deepEqual(template.errors, []);
    });
}

test('the provider summary keeps missing scores blank and the scoring detail collapsed', () => {
    const source = readFileSync(new URL('../../resources/js/components/SelectionEvaluationSummary.vue', import.meta.url), 'utf8');
    assert.match(source, /evaluation\.score === null/);
    assert.match(source, /stage\.score !== null/);
    assert.match(source, /<details[^>]+class=/);
    assert.match(source, /expanded: \{ type: Boolean, default: false \}/);
    assert.match(source, /<details :open="expanded"/);
    assert.match(source, /Missing evaluations are not zero scores/);
    assert.match(source, /document completeness do not earn ranking points/);
    assert.doesNotMatch(source, /axios|fetch\(/);
});

test('the applicant explanation uses the exact backend counts and distinguishes matching from selection', () => {
    const source = readFileSync(new URL('../../resources/js/components/ProfileMatchDialog.vue', import.meta.url), 'utf8');
    assert.match(source, /calculation\.matched/);
    assert.match(source, /calculation\.compared/);
    assert.match(source, /row\.counts_toward_match/);
    assert.match(source, /usePageScrollLock/);
    assert.match(source, /showModal\(\)/);
    assert.match(source, /@cancel\.prevent="emit\('close'\)"/);
    assert.match(source, /\/eligibility#scholarship-section/);
    assert.doesNotMatch(source, /\?\? 100/);
});
