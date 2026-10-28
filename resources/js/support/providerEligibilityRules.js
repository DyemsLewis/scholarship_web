export const eligibilityRuleKeys = [
    'academic', 'education_level', 'course', 'school_type', 'year_level',
    'location', 'income', 'current_scholarship_support', 'enrollment_status',
];

export function normalizeEligibilityRuleModes(modes) {
    return Object.fromEntries(eligibilityRuleKeys.map((key) => [key, modes?.[key] === 'preferred' ? 'preferred' : 'required']));
}

function options(value) {
    return (Array.isArray(value) ? value : String(value ?? '').split(/[\r\n,]+/)).map((item) => String(item).trim()).filter(Boolean);
}

export function isOpenEligibilityRule(value) {
    const normalized = String(value ?? '').toLowerCase().replace(/[_\-/]/g, ' ').replace(/[.;:]+/g, '').replace(/\s+/g, ' ').trim();
    return ['', 'any', 'all', 'na', 'none', 'philippines', 'the philippines', 'republic of the philippines', 'no income requirement', 'no income ceiling'].includes(normalized)
        || /^(any|all) /.test(normalized)
        || ['open to all', 'no restriction', 'no preference', 'not applicable', 'anywhere in the philippines', 'within the philippines', 'all over the philippines'].some((phrase) => normalized.includes(phrase))
        || (normalized.includes('nationwide') && !normalized.includes('not nationwide'));
}

export function configuredEligibilityRules(form, catalogs = {}) {
    const modes = normalizeEligibilityRuleModes(form.eligibilityRuleModes);
    const labels = (values, catalog = []) => options(values).map((value) => catalog.find(([key]) => key === value)?.[1] ?? value.replaceAll('_', ' ')).join(', ');
    const restricts = (value) => {
        const values = options(value);
        return values.length > 0 && !values.some(isOpenEligibilityRule);
    };
    const grade = form.minimumGwa;
    const hasGrade = grade !== '' && grade !== null && grade !== undefined && Number.isFinite(Number(grade));
    const scale = form.minimumGradeScale;
    const academicValue = scale === 'pass_fail' ? 'Pass/fail or competency evidence'
        : scale === 'other' ? 'Academic evidence reviewed by the provider'
            : hasGrade ? `${scale === 'grade_point' || (!scale && Number(grade) <= 5) ? 'Maximum GWA / GPA' : 'Minimum average'}: ${Number(grade).toFixed(2)}${scale === 'percentage' || (!scale && Number(grade) > 5) ? '%' : ''}`
                : 'Academic threshold not entered yet';
    const enrollment = (form.eligibilityConditions ?? []).find((condition) => condition.key === 'currently_enrolled' && condition.verification_type === 'automatic');
    return [
        { key: 'education_level', label: 'Education levels', value: labels(form.eligibleEducationLevels, catalogs.educationLevels), active: restricts(form.eligibleEducationLevels) },
        { key: 'course', label: 'Courses, tracks, or strands', value: options(form.eligibleCourses).join(', '), active: restricts(form.eligibleCourses) },
        { key: 'year_level', label: 'Grade or year levels', value: options(form.eligibleYearLevels).join(', '), active: restricts(form.eligibleYearLevels) },
        { key: 'location', label: 'Residency', value: options(form.eligibleLocations).join(', '), active: restricts(form.eligibleLocations) },
        { key: 'income', label: 'Household income', value: form.incomeRequirement, active: !isOpenEligibilityRule(form.incomeRequirement) },
        { key: 'school_type', label: 'School types', value: labels(form.eligibleSchoolTypes, catalogs.schoolTypes), active: restricts(form.eligibleSchoolTypes) },
        { key: 'academic', label: 'Academic performance', value: academicValue, active: Boolean(scale || hasGrade) },
        { key: 'current_scholarship_support', label: 'Other scholarship support', value: 'No other active scholarship support', active: Boolean(form.excludeCurrentRecipients) },
        { key: 'enrollment_status', label: 'Current enrollment', value: enrollment?.statement || 'Currently enrolled', active: Boolean(enrollment) },
    ].filter((rule) => rule.active).map(({ active, ...rule }) => ({ ...rule, mode: modes[rule.key] }));
}
