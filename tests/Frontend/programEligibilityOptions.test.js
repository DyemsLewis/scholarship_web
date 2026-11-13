import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { addEligibilityOption, eligibilityListValues, programEligibilityOptions } from '../../resources/js/support/programEligibilityOptions.js';

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
        assert.doesNotMatch(source, /onMounted|watch\(/);
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
