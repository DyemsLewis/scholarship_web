import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { applicantProfileDetails, profileBirthdateLabel } from '../../resources/js/support/applicantProfileDetails.js';

const savedProfile = () => ({
    id: 42, first_name: 'Cassandra', last_name: 'Miraflor', birthdate: '2008-06-15', age: 18, is_minor: false,
    gender: 'female', citizenship_status: 'filipino', contact_number: '09178001003', email: 'learner.apply@demo.findscholarship.test', account_managed_by: 'learner',
    education_level: 'senior_high_school', school: 'Marilag Demonstration Senior High School', school_type: 'public',
    enrollment_status: 'Enrolled', course_or_strand: 'HUMSS', year_level: 'Grade 12', gwa: '93.00', grading_scale: 'percentage', academic_year: '2026-2027', academic_term: 'first_semester',
    income_bracket: 'PHP 10,000 - 20,000', household_size: 5, current_scholarship_status: 'none',
    guardian_name: 'Teresa Miraflor', guardian_relationship: 'Mother', guardian_contact: '09178003000', guardian_email: 'guardian@demo.findscholarship.test', guardian_is_account_owner: true,
    city: 'Antipolo City', province: 'Rizal', region: 'Region IV-A', barangay: 'San Isidro', address: 'Barangay San Isidro, Antipolo City, Rizal', latitude: 14.5864, longitude: 121.1753,
});
const findSection = (sections, id) => sections.find((section) => section.id === id);
const findField = (sections, key) => sections.flatMap((section) => section.groups.flatMap((group) => group.items)).find((item) => item.key === key);

test('saved details have four full-width sections in applicant review order', () => {
    const sections = applicantProfileDetails(savedProfile());
    assert.deepEqual(sections.map((section) => section.id), ['personal', 'academic', 'household', 'location']);
    assert.equal(sections.at(-1).title, 'Home address');
    assert.equal(findField(sections, 'school').span, 'two');
    assert.equal(findField(sections, 'address').span, 'two');
    assert.equal(findField(sections, 'guardian_email').span, 'two');
    assert.equal(findField(sections, 'email').value, savedProfile().email);
    assert.equal(findField(sections, 'birthdate').detail, '18 years old');
});

test('record formatting uses the saved payload without mutating or reading an editor draft', () => {
    const saved = Object.freeze(savedProfile());
    const before = JSON.stringify(saved);
    const sections = applicantProfileDetails(saved);
    const draft = { ...saved, contact_number: '09179998888', school: 'Unsaved school', address: 'Unsaved address' };
    assert.equal(findField(sections, 'contact_number').value, saved.contact_number);
    assert.notEqual(findField(sections, 'school').value, draft.school);
    assert.notEqual(findField(sections, 'address').value, draft.address);
    assert.equal(JSON.stringify(saved), before);
});

test('school and academic labels retain the applicants actual education and grade scale', () => {
    const shs = applicantProfileDetails(savedProfile(), { labels: { schoolType: () => 'Public school', gradingScale: () => 'General average / percentage' } });
    assert.equal(findField(shs, 'school').detail, 'Public school');
    assert.equal(findField(shs, 'course_or_strand').label, 'Track / strand');
    assert.equal(findField(shs, 'gwa').value, '93.00%');
    assert.equal(findField(shs, 'gwa').detail, 'General average / percentage');
    const college = applicantProfileDetails({ ...savedProfile(), education_level: 'college', grading_scale: 'grade_point', gwa: '1.75' });
    assert.equal(findField(college, 'course_or_strand').label, 'Course / program');
    assert.equal(findField(college, 'year_level').label, 'Year level');
    assert.equal(findField(college, 'gwa').value, '1.75');
    assert.equal(findField(applicantProfileDetails({ ...savedProfile(), gwa: 0 }), 'gwa').value, '0.00%');
});

test('record-based grading never invents a passing result or a numeric conversion', () => {
    for (const grading_scale of ['pass_fail', 'other']) {
        const field = findField(applicantProfileDetails({ ...savedProfile(), grading_scale, gwa: null }), 'gwa');
        assert.equal(field.label, 'Academic assessment');
        assert.equal(field.value, 'School record');
        assert.doesNotMatch(field.value, /passed|100|%/i);
    }
    const field = findField(applicantProfileDetails({ ...savedProfile(), grading_scale: null, gwa: 1.75 }), 'gwa');
    assert.equal(field.value, '1.75');
    assert.equal(field.detail, 'Grading scale not provided');
});

test('guardian contacts show saved relationship, phone and email without adding an empty mandatory contact for adults', () => {
    const sections = applicantProfileDetails(savedProfile());
    assert.equal(findField(sections, 'guardian_name').detail, 'Manages this account');
    assert.equal(findField(sections, 'guardian_contact').value, '09178003000');
    const adult = { ...savedProfile(), education_level: 'college', age: 21, guardian_name: '', guardian_relationship: '', guardian_contact: '', guardian_email: '', guardian_is_account_owner: false };
    const noGuardian = findSection(applicantProfileDetails(adult, { requiresGuardian: false }), 'household');
    assert.equal(noGuardian.groups.length, 1);
    assert.match(noGuardian.note, /optional/);
    assert.equal(findSection(applicantProfileDetails(adult, { requiresGuardian: true }), 'household').groups.length, 2);
    assert.equal(findSection(applicantProfileDetails({ ...adult, guardian_name: 'Trusted contact' }, { requiresGuardian: false }), 'household').groups.length, 2);
});

test('outside scholarship information is clearly separate and stale details are not shown for a none declaration', () => {
    const current = { ...savedProfile(), current_scholarship_status: 'receiving', current_scholarship_details: 'Local tuition assistance' };
    assert.equal(findField(applicantProfileDetails(current), 'current_scholarship_details').value, 'Local tuition assistance');
    assert.equal(findField(applicantProfileDetails(current), 'current_scholarship_status').label, 'Outside scholarship');
    assert.equal(findField(applicantProfileDetails({ ...current, current_scholarship_status: 'none' }), 'current_scholarship_details'), undefined);
    assert.equal(findField(applicantProfileDetails({ ...current, household_size: 1 }), 'household_size').value, '1 member');
});

test('saved map coordinates are retained and general geocoding does not unnecessarily include the street address', () => {
    const location = findSection(applicantProfileDetails(savedProfile()), 'location');
    assert.equal(location.map.address, 'Antipolo City, Rizal, Region IV-A');
    assert.equal(location.map.latitude, 14.5864);
    assert.equal(location.map.hasPin, true);
    assert.equal(findSection(applicantProfileDetails({ ...savedProfile(), latitude: 0, longitude: 0 }), 'location').map.hasPin, true);
    assert.equal(findSection(applicantProfileDetails({ ...savedProfile(), latitude: null }), 'location').map.hasPin, false);
    assert.equal(findSection(applicantProfileDetails({ ...savedProfile(), latitude: 200 }), 'location').map.hasPin, false);
});

test('technical IDs, goals, activities and support requests do not become profile detail rows', () => {
    const sections = applicantProfileDetails({ ...savedProfile(), scholarship_goal: 'Hidden goal', achievements: 'Hidden achievement', activities_and_responsibilities: 'Hidden activity', support_needs: 'Hidden support request' });
    assert.doesNotMatch(JSON.stringify(sections), /Hidden |scholarship_goal|activities_and_responsibilities/);
    assert.equal(findField(sections, 'id'), undefined);
    assert.equal(findField(sections, 'learner_reference_number'), undefined);
    assert.equal(findField(applicantProfileDetails({ ...savedProfile(), learner_reference_number: 'SCHOOL-2026-123' }), 'learner_reference_number').value, 'SCHOOL-2026-123');
});

test('missing values and older enum keys remain readable instead of becoming false declarations', () => {
    const sections = applicantProfileDetails({ gender: 'prefer_not_to_say', enrollment_status: 'not_currently_enrolled' });
    assert.equal(findField(sections, 'gender').value, 'Prefer Not To Say');
    assert.equal(findField(sections, 'enrollment_status').value, 'Not Currently Enrolled');
    assert.equal(findField(sections, 'current_scholarship_status').value, '');
    assert.equal(findField(sections, 'household_size').value, '');
    assert.equal(applicantProfileDetails(null).length, 4);
});

test('date-only birthdates are not shifted or silently repaired', () => {
    assert.equal(profileBirthdateLabel('2008-06-15'), 'Jun 15, 2008');
    for (const value of [null, '', 'invalid', '2008-02-31', '2008-06-15T00:00:00Z']) assert.equal(profileBirthdateLabel(value), '');
});

test('the detailed record compiles with consistent field grids and read-only map access', () => {
    const source = readFileSync(new URL('../../resources/js/components/profile/ApplicantProfileDetails.vue', import.meta.url), 'utf8');
    const { descriptor, errors } = parse(source, { filename: 'ApplicantProfileDetails' });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, { id: 'profile-details' });
    const result = compileTemplate({ id: 'profile-details', filename: 'ApplicantProfileDetails', source: descriptor.template.content, compilerOptions: { bindingMetadata: script.bindings } });
    assert.deepEqual(result.errors, []);
    assert.match(source, /grid-cols-1[^]*?lg:grid-cols-3/);
    assert.doesNotMatch(source, /lg:grid-cols-2|line-clamp|truncate|\bpicker\b/);
    assert.match(source, /<LocationMapLink/);
    assert.match(source, /:aria-label="`Edit/);
    assert.match(source, /Not provided/);
    const page = readFileSync(new URL('../../resources/js/pages/UserProfilePage.vue', import.meta.url), 'utf8');
    const mapper = page.slice(page.indexOf('const applicantProfileSections = computed'), page.indexOf('const yearLevelOptions'));
    assert.match(mapper, /applicantProfileDetails\(user\.value/);
    assert.doesNotMatch(mapper, /form\.value/);
    assert.match(page, /<template #details>\s*<ApplicantProfileDetails/);
});
