import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';
import { programReviewEligibility, programReviewSections, safeProgramReviewUrl } from '../../resources/js/support/adminProgramReview.js';
import { reviewRequirementItems } from '../../resources/js/support/adminReviewPresentation.js';
import { labelFromKey } from '../../resources/js/support/display.js';

const read = file => readFileSync(new URL(`../../resources/js/pages/admin/reviews/${file}.vue`, import.meta.url), 'utf8');
for (const name of ['ProgramReviewRecordPage', 'ProgramReviewDetailsModal']) {
    test(`${name} compiles with the admin workspace`, () => {
        const { descriptor, errors } = parse(read(name)); assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: name });
        assert.deepEqual(compileTemplate({ id: name, filename: name, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}

test('the main page exposes summary and decisions, not the complete offer inline', () => {
    const page = read('ProgramReviewRecordPage');
    assert.match(page, /data-program-review-summary/); assert.match(page, /aria-haspopup="dialog"/);
    assert.match(page, /<ProgramReviewDetailsModal :open="showDetails" :program="program"/);
    assert.doesNotMatch(page, /\{\{ program\.(description|eligibility|benefit_summary|handoff_instructions)/);
    assert.match(page, /program\.value\.review_workflow\?\.ready === true/);
    assert.match(page, /expected_review_token: program\.value\.review_workflow\.token/);
    assert.match(page, /program.can_review !== false/);
});

test('modal preserves all prior review topics and separates the longer details into five sections', () => {
    const modal = read('ProgramReviewDetailsModal');
    assert.equal(programReviewSections.length, 5);
    for (const field of ['description', 'eligibility', 'benefits', 'requirements', 'optional_requirements', 'post_qualification_requirements', 'application_questions', 'handoff_instructions', 'review_rubric', 'selection_stages', 'program_events']) assert.match(modal, new RegExp(`program\\.${field}`));
    assert.match(modal, /<RecipientAgreementDetails :scholarship="program"/);
    assert.match(modal, /<EducationEligibilitySummary/);
    assert.doesNotMatch(modal, /axios|<form|v-html|window\.alert|window\.confirm/);
    assert.match(modal, /useModalAccessibility/); assert.match(modal, /<Teleport to="body">/);
    assert.match(modal, /role="dialog" aria-modal="true"/);
    assert.match(modal, /role="tablist"/); assert.match(modal, /role="tabpanel"/);
    for (const button of modal.match(/<button\b[\s\S]*?<\/button>/g)) assert.match(button, /type="button"/);
});

test('eligibility values stay readable and distinguish required rules from preferences', () => {
    const rows = programReviewEligibility({ eligible_education_levels: ['senior_high_school'], eligible_school_types: ['public', 'private'], minimum_gwa: 85, minimum_grade_scale: 'percentage', minimum_grade_label: 'Minimum average 85.00%', eligibility_rule_modes: { academic: 'preferred' }, exclude_current_scholarship_recipients: true });
    assert.equal(rows.find(row => row.key === 'education_level').value, 'Senior High School');
    assert.equal(rows.find(row => row.key === 'school_type').value, 'Public, Private');
    assert.equal(rows.find(row => row.key === 'academic').value, 'Minimum average 85.00%');
    assert.equal(rows.find(row => row.key === 'academic').mode, 'preferred');
    assert.equal(rows.find(row => row.key === 'education_level').mode, 'required');
    assert.ok(rows.some(row => row.key === 'current_scholarship_support'));
    assert.equal(programReviewEligibility({}).length, 0);
});

test('external links reject executable and malformed URLs', () => {
    for (const value of ['javascript:alert(1)', 'data:text/html,test', '//example.test', '/relative', '', null]) assert.equal(safeProgramReviewUrl(value), '');
    assert.equal(safeProgramReviewUrl('https://example.test/apply'), 'https://example.test/apply');
});

test('modal tab selection resets on opening and keyboard navigation wraps without affecting the program', () => {
    const source = parse(read('ProgramReviewDetailsModal')).descriptor.scriptSetup.content.replace(/^import .*;\r?\n/gm, '');
    const watches = [], focused = [], scrolls = [];
    const props = { open: false, section: 'eligibility', program: { title: 'Test program', slots_available: 0, application_limit: 0 } };
    const state = new Function('defineProps', 'defineEmits', 'useId', 'computed', 'ref', 'watch', 'useModalAccessibility', 'programReviewSections', 'programReviewEligibility', 'reviewRequirementItems', 'providerObjectiveDetails', 'labelFromKey', source + '\nreturn { active, changeTab, tabs, content, overviewFacts };')(
        () => props, () => () => {}, () => 'modal-test', computed, ref, (get, callback) => watches.push(callback), () => ({ handleKeydown() {} }), programReviewSections, programReviewEligibility, reviewRequirementItems, () => [], labelFromKey,
    );
    state.tabs.value = { querySelectorAll: () => programReviewSections.map((_, index) => ({ focus: () => focused.push(index) })) };
    state.content.value = { scrollTo: value => scrolls.push(value) };
    watches[0](true); assert.equal(state.active.value, 'eligibility');
    const event = key => ({ key, preventDefault() {} });
    state.changeTab(event('ArrowLeft'), 0); assert.equal(state.active.value, 'selection'); assert.equal(focused.at(-1), 4);
    state.changeTab(event('ArrowRight'), 4); assert.equal(state.active.value, 'overview');
    state.changeTab(event('End'), 0); assert.equal(state.active.value, 'selection');
    state.changeTab(event('Home'), 4); assert.equal(state.active.value, 'overview');
    watches[1](); assert.deepEqual(scrolls, [{ top: 0 }]);
    props.section = 'invalid'; watches[0](true); assert.equal(state.active.value, 'overview');
    assert.equal(state.overviewFacts.value.find(([key]) => key === 'Available slots')[1], 0);
    assert.equal(state.overviewFacts.value.find(([key]) => key === 'Application limit')[1], 0);
    assert.deepEqual(props.program, { title: 'Test program', slots_available: 0, application_limit: 0 });
});
