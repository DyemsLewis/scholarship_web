import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

async function loadEditor(name) {
    const source = readFileSync(new URL(`../../resources/js/components/profile/${name}.vue`, import.meta.url), 'utf8');
    const { descriptor, errors } = parse(source, { filename: `${name}.vue` });
    assert.deepEqual(errors, []);
    const compiled = compileScript(descriptor, { id: name, inlineTemplate: true });
    const code = compiled.content.replace(/from (['"])(vue|\.\.\/\.\.\/support\/[^'"]+)\1/g, (_, quote, module) => {
        const url = module === 'vue' ? import.meta.resolve('vue')
            : new URL(`../../resources/js/support/${module.split('/').at(-1)}.js`, import.meta.url).href;
        return `from ${JSON.stringify(url)}`;
    });
    return { source, component: (await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`)).default };
}

const learning = await loadEditor('ApplicantLearningEditor');
const household = await loadEditor('ApplicantHouseholdEditor');
const address = await loadEditor('ApplicantAddressEditor');
const option = (value, label = value) => ({ value, label });
const render = (editor, props) => renderToString(createSSRApp(editor.component, props));

function learningProps(overrides = {}) {
    return {
        modelValue: { education_level: 'senior_high_school', enrollment_status: 'Enrolled', school: 'Demo school', course_or_strand: 'STEM', year_level: 'Grade 12', academic_year: '2026-2027', academic_term: 'first_semester', grading_scale: 'percentage', gwa: '91.20', school_type: 'public', learner_reference_number: '' },
        educationOptions: [option('senior_high_school', 'Senior High School'), option('preschool', 'Preschool')],
        enrollmentOptions: ['Enrolled'], courseOptions: ['STEM', 'Other'], yearOptions: ['Grade 11', 'Grade 12'],
        academicYearOptions: ['2026-2027'], termOptions: [option('first_semester', 'First semester')],
        gradingScaleOptions: [option('percentage', 'Percentage'), option('grade_point', 'Grade point'), option('pass_fail', 'Pass / fail')],
        schoolTypeOptions: [option('public', 'Public')],
        pathCopy: { courseLabel: 'Track / strand', coursePlaceholder: 'Strand', yearLabel: 'Grade level', yearPlaceholder: 'Grade', gwaLabel: 'General average', idLabel: 'Learner Reference Number', idPlaceholder: 'LRN if available' },
        fieldRelevant: () => true, fieldRequired: () => true, requiresGrades: true,
        ...overrides,
    };
}

function householdProps(overrides = {}) {
    return {
        modelValue: { income_bracket: 'PHP 10,000 - 20,000', household_size: 5, guardian_name: '', guardian_relationship: '', guardian_contact: '', guardian_email: '', guardian_is_account_owner: false, current_scholarship_status: 'none', current_scholarship_details: '' },
        incomeOptions: ['PHP 10,000 - 20,000'], relationshipOptions: ['Mother'],
        scholarshipOptions: ['none', 'receiving', 'pending', 'completed'].map((value) => option(value)),
        ...overrides,
    };
}

function addressProps(overrides = {}) {
    return { modelValue: { region: 'Region IV-A', province: 'Rizal', city: 'Antipolo City', barangay: '', address: '' }, provinceOptions: [{ code: '1', ...option('Rizal') }], cityOptions: [{ code: '2', ...option('Antipolo City') }], ...overrides };
}

test('learning keeps core fields visible and only school identifiers behind a disclosure', async () => {
    const html = await render(learning, learningProps());
    const optionalStart = html.indexOf('<details');
    for (const id of ['education-level', 'enrollment', 'school', 'course', 'year', 'academic-year', 'academic-term']) {
        assert.ok(html.indexOf(`id="profile-${id}"`) < optionalStart, `${id} cannot be hidden in optional details.`);
        assert.match(html, new RegExp(`id="profile-${id}"[^>]+required`));
    }
    assert.match(html, /<details data-school-identifiers(?![^>]* open)/);
    assert.match(html.slice(optionalStart), /id="profile-school-type"/);
    assert.match(html.slice(optionalStart), /id="profile-lrn"/);
    assert.match(html, /Current studies[^]*?Record period and result[^]*?School identifiers/);
});

test('learning preserves custom study paths and saved years outside the current option list', async () => {
    const props = learningProps();
    props.modelValue = { ...props.modelValue, course_or_strand: 'Special curriculum', year_level: 'Legacy level', academic_year: '2022-2023' };
    const html = await render(learning, props);
    for (const value of ['Special curriculum', 'Legacy level', '2022-2023']) assert.match(html, new RegExp(`<option value="${value}">`));
    assert.match(html, /aria-label="Exact track \/ strand"/);
});

test('manual and OCR-managed academic results remain separate', async () => {
    const props = learningProps();
    let html = await render(learning, props);
    assert.match(html, /id="profile-gwa"[^>]+max="100"/);
    props.modelValue = { ...props.modelValue, grading_scale: 'grade_point' };
    html = await render(learning, props);
    assert.match(html, /id="profile-gwa"[^>]+max="5"/);
    html = await render(learning, { ...props, ocrActive: true, extractedResult: '91.20 / Percentage' });
    assert.match(html, /91.20 \/ Percentage/);
    assert.match(html, /Supporting evidence/);
    assert.match(html, /A reviewer confirms this result/);
    assert.doesNotMatch(html, /id="profile-gwa"|id="profile-grading-scale"/);
    html = await render(learning, { ...props, ocrActive: true, manualResultAllowed: true });
    assert.match(html, /Scan needs attention/);
});

test('early learning and pass-fail paths do not add unnecessary numeric-grade fields', async () => {
    const props = learningProps();
    let html = await render(learning, { ...props, modelValue: { ...props.modelValue, education_level: 'preschool', academic_year: '', academic_term: '', grading_scale: '', gwa: '' }, requiresGrades: false, fieldRelevant: () => false });
    assert.doesNotMatch(html, /Record period and result|id="profile-course"|id="profile-gwa"/);
    html = await render(learning, { ...props, modelValue: { ...props.modelValue, grading_scale: 'pass_fail' }, fieldRelevant: (key) => key !== 'gwa' });
    assert.match(html, /id="profile-grading-scale"/);
    assert.doesNotMatch(html, /id="profile-gwa"/);
});

test('required guardians stay visible; optional guardians can be added without clutter', async () => {
    const props = householdProps();
    const required = await render(household, { ...props, guardianRequired: true, guardianReason: 'Required for a learner under 18' });
    assert.match(required, /<section data-guardian-section/);
    assert.match(required, /Required for a learner under 18/);
    for (const id of ['guardian', 'guardian-relationship', 'guardian-contact', 'guardian-email']) assert.match(required, new RegExp(`id="profile-${id}"[^>]+required`));
    const optional = await render(household, props);
    assert.match(optional, /<details data-guardian-section(?![^>]* open)/);
    assert.doesNotMatch(optional.match(/id="profile-guardian-email"[^>]*>/)[0], /required/);
    const saved = await render(household, { ...props, hasGuardianDetails: true });
    assert.match(saved, /<details data-guardian-section[^>]+open/);
    const legacy = await render(household, { ...props, modelValue: { ...props.modelValue, guardian_relationship: 'Parent' }, hasGuardianDetails: true });
    assert.match(legacy, /<option value="Parent">Parent<\/option>/);
});

test('outside scholarships only ask for details when needed and retain portal award context', async () => {
    const props = householdProps();
    for (const status of ['none', 'completed', 'pending', 'receiving']) {
        const html = await render(household, { ...props, modelValue: { ...props.modelValue, current_scholarship_status: status } });
        assert.equal(html.includes('id="profile-current-scholarship-details"'), ['pending', 'receiving'].includes(status));
    }
    const html = await render(household, { ...props, portalScholarships: [{ application_id: 7, title: 'Demo learning grant' }] });
    assert.match(html, /1 award recorded in this portal/);
    assert.match(html, /Demo learning grant/);
    assert.doesNotMatch(html, /No active award/);
});

test('address keeps residency required and saved precision editable without requiring an exact home', async () => {
    const props = addressProps();
    const html = await render(address, props);
    for (const id of ['region', 'province', 'city']) assert.match(html, new RegExp(`id="profile-${id}"[^>]+required`));
    assert.match(html, /<details data-address-details(?![^>]* open)/);
    assert.match(html, /Some providers may request it for verification/);
    assert.match(html, /does not need to identify your exact home/);
    assert.match(html, /not shown on your public profile/);
    assert.match(html, /Set map pin/);
    const saved = await render(address, { ...props, modelValue: { ...props.modelValue, barangay: 'San Isidro' }, hasPin: true });
    assert.match(saved, /<details data-address-details[^>]+open/);
    assert.match(saved, /Review map pin/);
});

test('location selectors protect dependent fields, preserve saved values and offer error recovery', async () => {
    let html = await render(address, addressProps({ modelValue: { region: '', province: '', city: '' } }));
    assert.match(html, /id="profile-province"[^>]+disabled/);
    assert.match(html, /id="profile-city"[^>]+disabled/);
    html = await render(address, addressProps({ loadingProvinces: true, loadingCities: true }));
    assert.match(html, /id="profile-province"[^>]+disabled/);
    assert.match(html, /Loading provinces/);
    assert.match(html, /id="profile-city"[^>]+disabled/);
    html = await render(address, addressProps({ provinceOptions: [], cityOptions: [], optionsError: 'Locations could not be loaded.' }));
    assert.match(html, /<option value="Rizal">/);
    assert.match(html, /<option value="Antipolo City">/);
    assert.match(html, /role="alert"/);
    assert.match(html, /Try loading locations again/);
    const alias = await render(address, addressProps({ cityOptions: [{ code: '2', value: 'City of Antipolo', label: 'City of Antipolo' }] }));
    assert.match(alias, /<option value="Antipolo City">Antipolo City<\/option>/);
    assert.match(alias, /<option value="City of Antipolo">/);
});

test('section editors share the compact control styling and keep save ownership in the shell', () => {
    for (const editor of [learning, household, address]) {
        assert.match(editor.source, /profileEditorStyles/);
        assert.match(editor.source, /divide-y divide-slate-200 px-4 sm:px-6/);
        assert.doesNotMatch(editor.source, /student-card|shadow-|rounded-xl|<footer|Save changes/);
    }
    const page = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    assert.match(page, /:guardian-required="needsGuardianContext"/);
    assert.match(page, /@guardian-phone-input="handlePhoneInput\('guardian_contact', \$event\)"/);
    assert.match(page, /@open-evidence="openVerificationRecords"/);
    assert.match(page, /@region-change="handleApplicantRegionChange"/);
    assert.match(page, /@province-change="handleApplicantProvinceChange"/);
    assert.match(page, /@city-change="handleApplicantCityChange"/);
    assert.match(page, /@retry-options="loadApplicantLocationHierarchy\(\)"/);
    assert.match(page, /ancestor.tagName === 'DETAILS'[^]*?ancestor.open = true/);
});
