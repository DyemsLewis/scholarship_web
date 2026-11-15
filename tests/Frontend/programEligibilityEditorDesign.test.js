import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';

const base = new URL('../../resources/js/pages/provider/programs/', import.meta.url);
const page = readFileSync(new URL('ProgramFormPage.vue', base), 'utf8');
const start = page.indexOf("<section v-else-if=\"activeStep === 'eligibility'\"");
const section = page.slice(start, page.indexOf("<section v-else-if=\"activeStep === 'application'\"", start));

test('eligibility follows the shared builder hierarchy with two full-width rule groups and a final summary review', () => {
    for (const text of ['data-program-eligibility-editor', 'Study and academic rules', 'Coverage and support rules', 'Applicant-facing summary']) assert.ok(section.includes(text), text);
    const study = section.indexOf('data-eligibility-group="study"');
    const access = section.indexOf('data-eligibility-group="access"');
    const summary = section.indexOf('data-eligibility-summary-panel');
    assert.ok(study < access && access < summary);
    assert.ok(section.indexOf('id="program-study-levels"') < section.indexOf('id="program-eligible-courses"'));
    assert.doesNotMatch(section, /<aside|max-w-(xl|2xl|3xl)|grid-cols-\[.*_18rem/);
    assert.match(section, /program-icon grid size-9/);
});

test('every existing rule, explicit-choice safeguard and saved treatment remains editable', () => {
    for (const key of ['education_level', 'course', 'year_level', 'school_type', 'academic', 'location', 'income', 'current_scholarship_support', 'enrollment_status']) assert.ok(section.includes(`rule-key="${key}"`), key);
    for (const model of ['form.eligibleCourses', 'form.eligibleYearLevels', 'form.eligibleLocations', 'form.incomeRequirement', 'form.minimumGradeScale', 'form.minimumGwa', 'form.excludeCurrentRecipients', 'form.educationEligibilityPolicy']) assert.ok(section.includes(`v-model="${model}"`), model);
    assert.match(section, /@update:model-value="changeEducationLevels"/);
    assert.match(section, /v-model:needs-choice="coursesNeedChoice" require-explicit-open/);
    assert.match(section, /v-model:needs-choice="yearsNeedChoice" require-explicit-open/);
    assert.match(section, /data-education-value-review/);
    assert.match(section, /I checked these selections/);
    assert.match(page, /:disabled="isReadOnly \|\| isSaving"/);
});

test('the automatic summary is secondary but custom, stale and overlong summaries open for review', () => {
    assert.match(section, /<details :open="!useGeneratedEligibilitySummary \|\| customSummaryNeedsReview \|\| eligibilitySummary.length > 5000"/);
    assert.match(section, /:readonly="useGeneratedEligibilitySummary"/);
    assert.match(section, /@input="form.eligibility = \$event.target.value"/);
    assert.match(section, /@click="reviewedEligibilitySummary = currentEligibilitySummaryKey"/);
    assert.match(section, /Write custom summary/);
    assert.match(section, /Use rule-based summary/);
    assert.match(page, /eligibility: eligibilitySummary.value.trim\(\)/);
    assert.match(page, /&& !customSummaryNeedsReview.value/);
    assert.match(page, /&& !educationChoicesNeedReview.value/);
});

test('unrestricted choices are clear and selected filters use the neutral builder styling', () => {
    assert.match(section, /No restriction allows any current study level/);
    assert.match(section, /Empty rules remain unrestricted/);
    assert.equal((section.match(/<EligibilityCheckboxPicker/g) || []).length, 2);
    assert.doesNotMatch(section, /bg-(purple|violet|blue|indigo|pink)-/);
    const mode = readFileSync(new URL('components/EligibilityRuleModeSelect.vue', base), 'utf8');
    assert.match(mode, /h-8 min-w-26 rounded-sm/);
    assert.match(mode, /aria-describedby="eligibility-mode-guide"/);
    assert.match(mode, /option value="required"/);
    assert.match(mode, /option value="preferred"/);
});

test('common course and grade shortcuts reuse the existing add validation without replacing the full picker', () => {
    const picker = readFileSync(new URL('components/EligibilityOptionPicker.vue', base), 'utf8');
    assert.equal((section.match(/quick-options/g) || []).length, 2);
    assert.match(picker, /quickOptions: \{ type: Boolean, default: false \}/);
    assert.match(picker, /options.slice\(0, 8\)/);
    assert.match(picker, /:disabled="hasValue\(option\)"[^>]+@click="add\(option\)"/);
    assert.match(picker, /data-add-option/);
    assert.match(picker, /v-if="allowCustom"/);
});

for (const filename of ['ProgramFormPage.vue', 'components/EducationEligibilityPolicyEditor.vue', 'components/EligibilityRuleModeSelect.vue', 'components/EligibilityOptionPicker.vue', 'components/EligibilityCheckboxPicker.vue', 'components/EligibilityOptionsModal.vue']) {
    test(`${filename} compiles with its real script bindings`, () => {
        const { descriptor, errors } = parse(readFileSync(new URL(filename, base), 'utf8')); assert.deepEqual(errors, []);
        const script = compileScript(descriptor, { id: filename });
        assert.deepEqual(compileTemplate({ id: filename, filename, source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } }).errors, []);
    });
}
