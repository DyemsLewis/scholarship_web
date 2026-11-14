import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { addEligibilityOption, educationSelectionImpact, eligibilityListValues, programEligibilityOptions } from '../../resources/js/support/programEligibilityOptions.js';

test('options follow selected education levels using applicant-compatible labels', () => {
    const senior = programEligibilityOptions(['senior_high_school']);
    assert.ok(senior.courses.includes('STEM'));
    assert.deepEqual(senior.years, ['Grade 11', 'Grade 12']);
    assert.ok(!senior.courses.includes('Any strand') && !senior.courses.includes('Other'));
    const mixed = programEligibilityOptions(['senior_high_school', 'college']);
    assert.ok(mixed.courses.includes('BS Information Technology'));
    assert.ok(mixed.years.includes('1st year') && mixed.years.includes('Grade 12'));
    assert.ok(programEligibilityOptions().years.includes('Nursery'));
    assert.deepEqual(programEligibilityOptions(['elementary']).courses, []);
});

test('saved and uncommon values survive parsing; duplicates are not added', () => {
    assert.deepEqual(eligibilityListValues('STEM, Unlisted legacy course\nGrade 12'), ['STEM', 'Unlisted legacy course', 'Grade 12']);
    assert.equal(addEligibilityOption('STEM\nUnlisted legacy course', 'stem'), 'STEM\nUnlisted legacy course');
    assert.equal(addEligibilityOption('Grade 11', 'Grade 12'), 'Grade 11\nGrade 12');
    assert.equal(addEligibilityOption('Rizal, Metro Manila', ''), 'Rizal, Metro Manila');
});

test('specific choices replace open markers, and nationwide replaces restrictions only on explicit selection', () => {
    assert.equal(addEligibilityOption('Any', 'STEM'), 'STEM');
    assert.equal(addEligibilityOption('Nationwide', 'Rizal'), 'Rizal');
    assert.equal(addEligibilityOption('Rizal\nMetro Manila', 'Nationwide'), 'Nationwide');
});

for (const component of ['EligibilityOptionPicker', 'EligibilityLocationPicker']) {
    test(`${component} compiles and does not emit changes on mount`, () => {
        const source = readFileSync(new URL(`../../resources/js/pages/provider/programs/components/${component}.vue`, import.meta.url), 'utf8');
        const { descriptor, errors } = parse(source);
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: component });
        assert.deepEqual(compileTemplate({ id: component, filename: component, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
        assert.doesNotMatch(source, /onMounted/);
    });
}

test('builder keeps the existing payload format and Required/Preferred controls', () => {
    const source = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    for (const field of ['Courses', 'YearLevels', 'Locations']) assert.ok(source.includes(`form.eligible${field}`));
    assert.match(source, /eligible_courses: form.value.eligibleCourses.trim\(\)/);
    assert.match(source, /eligible_year_levels: form.value.eligibleYearLevels.trim\(\)/);
    assert.match(source, /eligible_locations: form.value.eligibleLocations.trim\(\)/);
    assert.doesNotMatch(source, /<textarea id="program-eligible-(courses|years|locations)"/);
});

test('education changes keep compatible and shared options and remove only known incompatible ones', () => {
    const changed = educationSelectionImpact(['senior_high_school', 'college'], ['college'], 'STEM\nBSIT', 'Grade 12\n1st year');
    assert.deepEqual(changed.removedCourses, ['STEM']);
    assert.deepEqual(changed.removedYears, ['Grade 12']);
    assert.equal(changed.courses, 'BSIT');
    assert.equal(changed.years, '1st year');
    assert.deepEqual(changed.reviewCourses, []);
    const broader = educationSelectionImpact(['senior_high_school'], ['senior_high_school', 'college'], 'STEM', 'Grade 12');
    assert.equal(broader.courses, 'STEM');
    assert.equal(broader.years, 'Grade 12');
    assert.deepEqual(broader.removedCourses, []);
    assert.equal(educationSelectionImpact(['senior_high_school'], [], 'STEM', 'Grade 12').courses, 'STEM');
});

test('custom or legacy selections require review rather than being guessed and erased', () => {
    const result = educationSelectionImpact(['senior_high_school'], ['college'], 'STEM\nSpecial school program', 'Grade12\nCustom year');
    assert.deepEqual(result.removedCourses, ['STEM']);
    assert.deepEqual(result.removedYears, ['Grade12']);
    assert.deepEqual(result.reviewCourses, ['Special school program']);
    assert.deepEqual(result.reviewYears, ['Custom year']);
    assert.equal(result.courses, 'Special school program');
    assert.equal(result.years, 'Custom year');
    assert.deepEqual(educationSelectionImpact([], ['college'], 'STEM', 'Grade 11').removedYears, ['Grade 11']);
});

test('cleared restrictions require explicit replacement and save cannot silently open them', () => {
    const builder = readFileSync(new URL('../../resources/js/pages/provider/programs/ProgramFormPage.vue', import.meta.url), 'utf8');
    const picker = readFileSync(new URL('../../resources/js/pages/provider/programs/components/EligibilityOptionPicker.vue', import.meta.url), 'utf8');
    assert.match(builder, /@change="changeEducationLevel\(value, \$event\)"/);
    assert.match(builder, /Keep current settings/);
    assert.match(builder, /if \(!confirmed \|\| isSaving.value \|\| isReadOnly.value\) return/);
    assert.match(builder, /async function save[^]*?if \(educationChoicesNeedReview.value\)/);
    assert.match(builder, /v-model:needs-choice="coursesNeedChoice" require-explicit-open open-value="Any"/);
    assert.match(builder, /v-model:needs-choice="yearsNeedChoice" require-explicit-open open-value="Any"/);
    assert.match(picker, /props.requireExplicitOpen && !eligibilityListValues\(updated\).length/);
    assert.match(picker, /data-selection-field/);
    assert.match(picker, /v-if="optionsOpen"/);
});

test('removing the last value detects an empty list before parent v-model props update', () => {
    const picker = readFileSync(new URL('../../resources/js/pages/provider/programs/components/EligibilityOptionPicker.vue', import.meta.url), 'utf8');
    const body = picker.match(/function remove\(value\) \{([^]*?)\n\}/)[1];
    let emitted;
    const model = { get value() { return 'STEM'; }, set value(value) { emitted = value; } };
    const needsChoice = { value: false };
    new Function('value', 'values', 'model', 'needsChoice', 'props', 'eligibilityListValues', 'error', body)(
        'STEM', { value: ['STEM'] }, model, needsChoice, { requireExplicitOpen: true }, eligibilityListValues, { value: '' },
    );
    assert.equal(emitted, '');
    assert.equal(needsChoice.value, true);
});
