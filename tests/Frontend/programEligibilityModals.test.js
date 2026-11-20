import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { addEligibilityOption, eligibilityListValues, educationSelectionImpact } from '../../resources/js/support/programEligibilityOptions.js';
import { isOpenEligibilityRule } from '../../resources/js/support/providerEligibilityRules.js';
import { educationPolicyError } from '../../resources/js/support/educationEligibilityPolicy.js';

const base = new URL('../../resources/js/pages/provider/programs/components/', import.meta.url);
const read = name => readFileSync(new URL(name + '.vue', base), 'utf8');
const script = name => parse(read(name)).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');

function picker(value = 'STEM', extra = {}) {
    const model = ref(value), needsChoice = ref(false);
    const props = { id: 'test-options', options: ['STEM', 'ABM'], openValue: 'Any', openLabel: 'Any course / strand', requireExplicitOpen: true, maxLength: 3000, disabled: false, ...extra };
    const state = new Function('defineModel', 'defineProps', 'computed', 'ref', 'watch', 'nextTick', 'addEligibilityOption', 'eligibilityListValues', 'isOpenEligibilityRule', script('EligibilityOptionPicker') + '\nreturn { toggleOptions, close, apply, add, removeDraft, openToAll, draft, draftNeedsChoice, optionsOpen, error, choiceWarning };')(
        name => name === 'needsChoice' ? needsChoice : model, () => props, computed, ref, () => {}, () => Promise.resolve(), addEligibilityOption, eligibilityListValues, isOpenEligibilityRule,
    );
    return { ...state, model, needsChoice, props };
}

test('course, grade and location choices remain drafts until Apply; Cancel restores the saved form value', () => {
    const state = picker(); state.toggleOptions(); state.add('ABM');
    assert.equal(state.draft.value, 'STEM\nABM'); assert.equal(state.model.value, 'STEM');
    state.close(); assert.equal(state.model.value, 'STEM');
    state.toggleOptions(); assert.equal(state.draft.value, 'STEM');
    state.add('ABM'); state.apply(); assert.equal(state.model.value, 'STEM\nABM'); assert.equal(state.optionsOpen.value, false);
    const location = picker('Rizal, Metro Manila', { openValue: 'Nationwide', openLabel: 'Nationwide' });
    location.toggleOptions(); location.openToAll(); location.close(); assert.equal(location.model.value, 'Rizal, Metro Manila');
    location.toggleOptions(); location.openToAll(); location.apply(); assert.equal(location.model.value, 'Nationwide');
});

test('removing the last restricted draft option still requires an explicit replacement or unrestricted choice', () => {
    const state = picker(); state.toggleOptions(); state.removeDraft('STEM'); state.apply();
    assert.equal(state.model.value, 'STEM'); assert.equal(state.optionsOpen.value, true);
    assert.equal(state.draftNeedsChoice.value, true); assert.match(state.choiceWarning.value, /replacement/);
    state.openToAll(); state.apply(); assert.equal(state.model.value, 'Any'); assert.equal(state.needsChoice.value, false);
});

test('invalid, duplicate and overlong options cannot corrupt either the draft or committed selection', () => {
    const state = picker(); state.toggleOptions(); state.add('stem'); assert.equal(state.draft.value, 'STEM');
    state.add('ABM, GAS'); assert.match(state.error.value, /one option/); assert.equal(state.draft.value, 'STEM');
    state.props.maxLength = 6; state.add('A longer custom option'); assert.match(state.error.value, /too long/);
    assert.equal(state.model.value, 'STEM'); assert.equal(state.draft.value, 'STEM');
});

test('locked fields cannot open a picker or commit a draft left open before saving began', () => {
    const state = picker('STEM', { disabled: true }); state.toggleOptions(); assert.equal(state.optionsOpen.value, false);
    state.draft.value = 'ABM'; state.apply(); assert.equal(state.model.value, 'STEM');
    assert.match(read('EligibilityOptionPicker'), /watch\(\(\) => props.disabled, disabled => \{ if \(disabled\) close\(\)/);
});

test('study and school-type modals copy arrays, cancel without emissions, and emit independent arrays on Apply', () => {
    const props = { modelValue: ['senior_high_school'], options: [['senior_high_school', 'Senior High School'], ['college', 'College / University']], disabled: false };
    const events = [];
    const state = new Function('defineProps', 'defineEmits', 'ref', 'watch', 'computed', 'labelFromKey', script('EligibilityCheckboxPicker') + '\nreturn { show, apply, open, draft };')(() => props, () => (...args) => events.push(args), ref, () => {}, computed, value => value);
    state.show(); state.draft.value.push('college'); state.open.value = false;
    assert.deepEqual(props.modelValue, ['senior_high_school']); assert.deepEqual(events, []);
    state.show(); assert.deepEqual(state.draft.value, ['senior_high_school']);
    state.draft.value.push('college'); state.apply(); assert.deepEqual(events[0], ['update:modelValue', ['senior_high_school', 'college']]);
    state.draft.value.push('als'); assert.deepEqual(events[0][1], ['senior_high_school', 'college']);
    props.disabled = true; state.apply(); assert.equal(events.length, 1);
});

test('qualification and enrollment modals preserve policy and unrelated modes on Cancel and validate before Apply', () => {
    const policy = ref({ requires_completed_qualification: false, accepted_qualifications: [], enrollment_statuses: [], unlisted_qualification: 'review' });
    const modes = ref({ academic: 'preferred', completed_qualification: 'required', enrollment_status: 'required' });
    const props = { disabled: false, conditions: [] };
    const state = new Function('defineModel', 'defineProps', 'computed', 'ref', 'watch', 'educationPolicyError', 'acceptedQualificationOptions', script('EducationEligibilityPolicyEditor') + '\nreturn { show, close, apply, draft, draftModes, error, open };')(name => name === 'modes' ? modes : policy, () => props, computed, ref, () => {}, educationPolicyError, []);
    state.show(); state.draft.value.enrollment_statuses.push('Enrolled'); state.draftModes.value.enrollment_status = 'preferred'; state.close();
    assert.deepEqual(policy.value.enrollment_statuses, []); assert.equal(modes.value.enrollment_status, 'required');
    state.show(); state.draft.value.requires_completed_qualification = true; state.apply();
    assert.match(state.error.value, /at least one/); assert.equal(policy.value.requires_completed_qualification, false);
    state.draft.value.accepted_qualifications = ['senior_high_school']; state.draftModes.value.completed_qualification = 'preferred'; state.apply();
    assert.equal(policy.value.requires_completed_qualification, true); assert.equal(modes.value.academic, 'preferred');
    assert.equal(modes.value.completed_qualification, 'preferred'); assert.equal(state.open.value, false);
});

test('bulk study-level changes retain the previous confirmation and compatibility safeguards', async () => {
    const page = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    const fn = page.slice(page.indexOf('async function changeEducationLevels('), page.indexOf('\nfunction toggleStage'));
    const form = ref({ eligibleEducationLevels: ['senior_high_school'], eligibleCourses: 'STEM', eligibleYearLevels: 'Grade 12', incomeRequirement: 'Any' });
    const pending = ref(false), coursesNeedChoice = ref(false), yearsNeedChoice = ref(false), educationReview = ref({ courses: [], years: [] });
    let confirm = false, prompts = 0;
    const change = new Function('form', 'isReadOnly', 'isSaving', 'educationChangePending', 'educationSelectionImpact', 'requestConfirmation', 'coursesNeedChoice', 'yearsNeedChoice', 'educationReview', fn + '\nreturn changeEducationLevels;')(form, ref(false), ref(false), pending, educationSelectionImpact, async () => { prompts++; return confirm; }, coursesNeedChoice, yearsNeedChoice, educationReview);
    await change(['college']); assert.deepEqual(form.value.eligibleEducationLevels, ['senior_high_school']); assert.equal(form.value.eligibleCourses, 'STEM');
    confirm = true; await change(['college']); assert.deepEqual(form.value.eligibleEducationLevels, ['college']); assert.equal(form.value.eligibleCourses, '');
    assert.equal(coursesNeedChoice.value, true); assert.equal(yearsNeedChoice.value, true); assert.equal(pending.value, false);
    assert.equal(form.value.incomeRequirement, 'Any'); assert.equal(prompts, 2);
});

test('option modals use the established scroll lock, focus trap and accessible dialog without expanding form rows', () => {
    const modal = read('EligibilityOptionsModal');
    for (const text of ['<Teleport to="body">', 'useModalAccessibility', 'role="dialog"', 'aria-modal="true"', 'overflow-y-auto', 'Apply selections', 'Cancel']) assert.ok(modal.includes(text), text);
    assert.doesNotMatch(read('EligibilityOptionPicker'), /<div v-if="optionsOpen"/);
    assert.match(read('EligibilityOptionPicker'), /:aria-label="`\$\{selected.length/);
    assert.match(read('EligibilityCheckboxPicker'), /:disabled="disabled"/);
    assert.match(read('EducationEligibilityPolicyEditor'), /id-prefix="eligibility-policy-mode"/);
});
