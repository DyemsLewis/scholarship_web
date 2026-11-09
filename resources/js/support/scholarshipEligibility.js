import { eligibilityCriterionPresentation } from './applicantEligibility.js';
import { profileValueLabel } from './applicantProfileLabels.js';
import { labelFromKey } from './display.js';

const profileSections = {
    academic: 'academic', education_level: 'academic', completed_qualification: 'academic', course: 'academic', school_type: 'academic',
    year_level: 'academic', enrollment_status: 'academic', location: 'location', income: 'household',
    current_scholarship_support: 'household', age: 'personal', citizenship: 'personal',
};
const valueTypes = { education_level: 'educationLevel', completed_qualification: 'completedQualification', school_type: 'schoolType', current_scholarship_support: 'currentScholarship' };
const attentionStatuses = new Set(['fail', 'missing', 'review', 'preferred', 'unknown']);

export function eligibilityValue(value, key = '', fallback = 'Not provided') {
    if (Array.isArray(value)) return value.map((item) => eligibilityValue(item, key, '')).filter(Boolean).join(', ') || fallback;
    if (value !== null && typeof value === 'object') return fallback;
    const text = String(value ?? '').trim();
    if (!text) return fallback;
    return text.split(/,(?!\d)/).map((part) => {
        const item = part.trim();
        if (/^[a-z][a-z0-9_]*$/.test(item) && valueTypes[key]) return profileValueLabel(valueTypes[key], item);
        return item.replace(/\b[a-z]+(?:_[a-z0-9]+)+\b/gi, (token) => labelFromKey(token));
    }).join(', ');
}

export function scholarshipEligibilityRows(criteria = []) {
    return criteria.filter((criterion) => criterion.category !== 'readiness' && criterion.key !== 'documents').map((criterion, index) => {
        const result = eligibilityCriterionPresentation(criterion);
        return {
            ...criterion,
            id: `${criterion.key || 'criterion'}-${index}`,
            result,
            attention: attentionStatuses.has(result.status),
            profileValue: eligibilityValue(criterion.student_value ?? criterion.studentValue, criterion.key),
            requirementValue: eligibilityValue(criterion.requirement, criterion.key, result.status === 'info' ? 'Open to all' : 'Not specified'),
            explanation: eligibilityValue(criterion.comparison || criterion.note, '', ''),
            profileHref: `/dashboard/profile?section=${result.status === 'review' && criterion.key === 'academic' ? 'verification' : (profileSections[criterion.key] ?? 'personal')}`,
        };
    });
}

export function scholarshipEligibilityGroups(rows, publishedOnly = false) {
    const restricted = rows.filter((row) => row.result.status !== 'info');
    const preferred = (row) => row.rule_mode === 'preferred';
    if (publishedOnly) {
        return [
            { id: 'required', label: 'Required criteria', description: 'These rules must be met to apply.', rows: restricted.filter((row) => !preferred(row)) },
            { id: 'preferred', label: 'Provider preferences', description: 'These do not block an application.', rows: restricted.filter(preferred) },
        ].filter((group) => group.rows.length);
    }

    return [
        { id: 'blocking', label: 'Required rules not met', description: 'These differences block a new application.', rows: restricted.filter((row) => row.result.status === 'fail') },
        { id: 'review', label: 'Checks not confirmed', description: 'Missing information or a check the provider needs to review. These are not confirmed failures.', rows: restricted.filter((row) => !preferred(row) && ['missing', 'review', 'unknown'].includes(row.result.status)) },
        { id: 'preferred', label: 'Preferences to review', description: 'Differences here do not block an application.', rows: restricted.filter((row) => preferred(row) && row.result.status !== 'pass') },
        { id: 'matched', label: 'Matched criteria', description: 'Matches are based on the saved profile and do not guarantee selection.', collapsed: true, rows: restricted.filter((row) => row.result.status === 'pass') },
    ].filter((group) => group.rows.length);
}
