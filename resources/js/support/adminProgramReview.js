import { configuredEligibilityRules } from './providerEligibilityRules.js';
import { labelFromKey } from './display.js';

export const programReviewSections = [
    { key: 'overview', label: 'Overview', title: 'Program overview', icon: 'fa-solid fa-align-left', description: 'Description, dates, location and contacts' },
    { key: 'eligibility', label: 'Eligibility', title: 'Eligibility rules', icon: 'fa-solid fa-list-check', description: 'Required rules, preferences and qualifications' },
    { key: 'benefits', label: 'Benefits & terms', title: 'Benefits and recipient terms', icon: 'fa-solid fa-gift', description: 'Support offered and recipient responsibilities' },
    { key: 'application', label: 'Application', title: 'Application requirements', icon: 'fa-solid fa-folder-open', description: 'Required files, questions and provider handoff' },
    { key: 'selection', label: 'Selection', title: 'Selection and schedule', icon: 'fa-solid fa-route', description: 'Stage sequence, evaluation criteria and activities' },
];

export function programReviewEligibility(program = {}) {
    const catalog = values => (Array.isArray(values) ? values : String(values ?? '').split(/[,\n]+/))
        .map(value => String(value).trim()).filter(Boolean).map(value => [value, labelFromKey(value)]);
    return configuredEligibilityRules({
        eligibleEducationLevels: program.eligible_education_levels,
        eligibleCourses: program.eligible_courses,
        eligibleYearLevels: program.eligible_year_levels,
        eligibleLocations: program.eligible_locations,
        incomeRequirement: program.income_requirement,
        eligibleSchoolTypes: program.eligible_school_types,
        minimumGwa: program.minimum_gwa,
        minimumGradeScale: program.minimum_grade_scale,
        excludeCurrentRecipients: program.exclude_current_scholarship_recipients,
        educationEligibilityPolicy: program.education_eligibility_policy,
        eligibilityConditions: program.eligibility_conditions,
        eligibilityRuleModes: program.eligibility_rule_modes,
    }, { educationLevels: catalog(program.eligible_education_levels), schoolTypes: catalog(program.eligible_school_types) })
        .filter(row => !['completed_qualification', 'enrollment_status'].includes(row.key))
        .map(row => row.key === 'academic' && program.minimum_grade_label ? { ...row, value: program.minimum_grade_label } : row);
}

export function safeProgramReviewUrl(value) {
    try {
        const url = new URL(value);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
    } catch { return ''; }
}
