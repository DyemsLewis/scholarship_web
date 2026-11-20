import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { computed, reactive, ref, watch } from 'vue';

const base = new URL('../../resources/js/pages/provider/programs/components/', import.meta.url);
const read = name => readFileSync(new URL(name + '.vue', base), 'utf8');

function editor(name, values, state) {
    const props = reactive({ disabled: false, ...values });
    const events = [];
    const deps = { computed, ref, watch, defineProps: () => props, defineEmits: () => (...args) => events.push(args) };
    const body = parse(read(name)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const actions = new Function(...Object.keys(deps), body + `\nreturn { show, close, apply, open, ${state} };`)(...Object.values(deps));
    return { ...actions, props, events };
}

test('reconsideration offers current Required rules only and applies the checked list as a draft', () => {
    const state = editor('EligibilityReconsiderationEditor', {
        modelValue: ['course', 'academic'],
        rules: [{ key: 'course', mode: 'preferred' }, { key: 'academic', mode: 'required' }, { key: 'location', mode: 'required' }],
    }, 'draft, requiredRules, selectedCount');
    assert.deepEqual(state.requiredRules.value.map(rule => rule.key), ['academic', 'location']);
    assert.equal(state.selectedCount.value, 1);
    state.show();
    state.draft.value = ['location'];
    state.close();
    state.show();
    assert.deepEqual(state.events, []);
    assert.deepEqual(state.draft.value, ['academic']);
    state.draft.value = ['location', 'unavailable'];
    state.apply();
    assert.deepEqual(state.events, [['update:modelValue', ['course', 'location']]]);
    assert.deepEqual(state.props.modelValue, ['course', 'academic']);
});

test('no reconsideration dialog opens when no required rules are configured', () => {
    const state = editor('EligibilityReconsiderationEditor', { rules: [{ key: 'course', mode: 'preferred' }], modelValue: ['course'] }, 'draft, selectedCount');
    state.show();
    assert.equal(state.open.value, false);
    assert.equal(state.selectedCount.value, 0);
    assert.deepEqual(state.events, []);
});

test('summary mode and text remain unchanged until Apply, including when switching back to automatic', () => {
    const state = editor('EligibilitySummaryEditor', {
        automatic: true, generatedSummary: 'Required to apply:\n- Residency: Rizal', modelValue: '',
    }, 'draftAutomatic, draftCustom, draftSummary');
    state.show();
    assert.equal(state.draftSummary.value, state.props.generatedSummary);
    state.draftAutomatic.value = false;
    state.draftCustom.value = 'Discard this wording.';
    state.close();
    state.show();
    assert.equal(state.draftAutomatic.value, true);
    assert.equal(state.draftCustom.value, state.props.generatedSummary);
    assert.deepEqual(state.events, []);
    state.draftAutomatic.value = false;
    state.draftCustom.value = 'Applicants must meet the residency rule.';
    state.apply();
    assert.deepEqual(state.events, [['update:modelValue', 'Applicants must meet the residency rule.'], ['update:automatic', false]]);
    state.props.automatic = false;
    state.props.modelValue = 'Applicants must meet the residency rule.';
    state.show();
    state.draftAutomatic.value = true;
    state.apply();
    assert.deepEqual(state.events[2], ['update:automatic', true]);
});

test('an oversized summary needs fixing but a shorter custom summary can be applied', () => {
    const state = editor('EligibilitySummaryEditor', { automatic: true, generatedSummary: 'x'.repeat(5001), modelValue: '' }, 'draftAutomatic, draftCustom, tooLong');
    state.show();
    assert.equal(state.tooLong.value, true);
    state.apply();
    assert.deepEqual(state.events, []);
    assert.equal(state.open.value, true);
    state.draftAutomatic.value = false;
    state.draftCustom.value = 'x'.repeat(5000);
    assert.equal(state.tooLong.value, false);
    state.apply();
    assert.equal(state.events[0][1].length, 5000);
    assert.doesNotMatch(read('EligibilitySummaryEditor'), /I checked my summary/);
});
