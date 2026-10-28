import { labelFromKey } from './display.js';

const labels = {
    educationLevel: {
        preschool: 'Preschool / Kindergarten', elementary: 'Elementary', junior_high_school: 'Junior High School',
        senior_high_school: 'Senior High School', college: 'College / University', tvet: 'TVET / Vocational', als: 'ALS / Alternative Learning', other: 'Other',
    },
    gender: { female: 'Female', male: 'Male', non_binary: 'Non-binary', prefer_not_to_say: 'Prefer not to say' },
    citizenship: { filipino: 'Filipino citizen', dual_filipino: 'Dual citizen, including Filipino', other: 'Other citizenship status' },
    accountManager: { learner: 'Learner / student', parent_guardian: 'Parent or guardian', relative: 'Relative', school_representative: 'School representative', other: 'Other trusted person' },
    schoolType: { daycare_learning_center: 'Daycare / learning center', public: 'Public', private: 'Private', state_university: 'State university / college', local_college: 'Local college / university', tvet_center: 'TVET center', als_center: 'ALS center', other: 'Other' },
    gradingScale: { percentage: 'General average / percentage', grade_point: 'GWA grade point', pass_fail: 'Pass/fail or competency based', other: 'Other institutional grading system' },
    academicTerm: {
        full_year: 'Full school year', first_grading_period: 'First grading period', second_grading_period: 'Second grading period',
        third_grading_period: 'Third grading period', fourth_grading_period: 'Fourth grading period', first_semester: 'First semester / term',
        second_semester: 'Second semester / term', third_term: 'Third trimester / term', summer_term: 'Summer term', latest_completed_term: 'Latest completed term', not_applicable: 'Not applicable',
    },
    currentScholarship: { none: 'No scholarship outside this portal', receiving: 'Receiving scholarship support outside this portal', pending: 'Outside scholarship application is pending', completed: 'Outside scholarship already completed' },
};

export function profileValueLabel(type, value) {
    if (value === null || value === undefined || String(value).trim() === '') return '';
    return labels[type]?.[value] ?? labelFromKey(value);
}

export function profileVerificationLabel(status) {
    return { approved: 'Evidence reviewed', pending: 'Under review', rejected: 'Needs replacement', unsubmitted: 'Not submitted' }[status] ?? 'Not submitted';
}

export function profileEducationSummary(profile = {}) {
    return [profileValueLabel('educationLevel', profile?.education_level), profile?.course_or_strand, profile?.year_level].filter(Boolean).join(' - ');
}
