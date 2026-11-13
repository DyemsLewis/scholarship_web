import { readFileSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { selectionRationaleRequired } from '../../resources/js/support/selectionComparison.js';

for (const path of [
    '../../resources/js/pages/provider/decisions/FinalDecisionDirectoryPage.vue',
    '../../resources/js/pages/provider/decisions/FinalDecisionRecordPage.vue',
    '../../resources/js/components/SelectionEvaluationSummary.vue',
    '../../resources/js/components/SelectionBreakdownDialog.vue',
]) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8');
    test(`${path.split('/').at(-1)} compiles for final comparisons`, () => {
        const { descriptor, errors } = parse(source, { filename: path });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: path });
        const template = compileTemplate({ id: path, filename: path, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
        assert.deepEqual(template.errors, []);
    });
}

test('matching suggestions need no extra field but overrides and ties need a reason', () => {
    assert.equal(selectionRationaleRequired({}, 'selected'), false);
    assert.equal(selectionRationaleRequired({ scoring_enabled: true, recommended_outcome: 'selected' }, 'selected'), false);
    assert.equal(selectionRationaleRequired({ scoring_enabled: true, recommended_outcome: 'selected' }, 'waitlisted'), true);
    assert.equal(selectionRationaleRequired({ scoring_enabled: true, tie_at_cutoff: true }, 'selected'), true);
    assert.equal(selectionRationaleRequired({ scoring_enabled: true, waitlist_priority: true }, 'selected'), true);
    assert.equal(selectionRationaleRequired({ scoring_enabled: true, waitlist_priority: true }, 'waitlisted'), false);
    assert.equal(selectionRationaleRequired({ scoring_enabled: true, tie_at_cutoff: true }, ''), false);
});

test('the queue explains program scope, tied ranks, and official waitlist positions', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/decisions/FinalDecisionDirectoryPage.vue', import.meta.url), 'utf8');
    assert.match(source, /Choose a program to compare/);
    assert.match(source, /Official waitlist order is preserved/);
    assert.match(source, /Equal scores share a rank/);
    assert.match(source, /View evaluation breakdown for/);
    assert.match(source, /<SelectionBreakdownDialog/);
    assert.doesNotMatch(source, /window\.axios\.(post|patch|delete)/);
});

test('the final outcome sends its comparison reference and keeps internal reasons separate', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/decisions/FinalDecisionRecordPage.vue', import.meta.url), 'utf8');
    assert.match(source, /comparison_reference: reference/);
    assert.match(source, /selection_rationale: rationale \|\| null/);
    assert.match(source, /JSON\.stringify\(decisionForm\.value\) === formSnapshot/);
    assert.match(source, /Reload comparison/);
    assert.match(source, /Not shown to the applicant/);
    assert.match(source, /confirmOutcome\(/);
});
