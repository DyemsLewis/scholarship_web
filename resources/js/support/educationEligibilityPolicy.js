import { completedQualificationOptions } from './applicantProfileLabels.js';

export const acceptedQualificationOptions = completedQualificationOptions.filter((option) => option.value !== 'none');
export const activeEnrollmentStatuses = ['Enrolled', 'Continuing student', 'Graduating'];
export const enrollmentStatusOptions = ['Enrolled', 'Incoming student', 'Continuing student', 'Graduating', 'Not currently enrolled', 'Transfer enrollment pending', 'Returning enrollment pending'];
export const pendingStudyStatuses = ['Incoming student', 'Transfer enrollment pending', 'Returning enrollment pending', 'Not currently enrolled'];

export function normalizeEducationPolicy(value = {}) {
    const required = value?.requires_completed_qualification === true || value?.requires_completed_qualification === 1;
    const accepted = Array.isArray(value?.accepted_qualifications) ? value.accepted_qualifications : [];
    const enrollment = Array.isArray(value?.enrollment_statuses) ? value.enrollment_statuses : [];
    return {
        requires_completed_qualification: required,
        accepted_qualifications: required ? acceptedQualificationOptions.map((option) => option.value).filter((type) => accepted.includes(type)) : [],
        unlisted_qualification: required && value?.unlisted_qualification === 'not_accepted' ? 'not_accepted' : 'review',
        enrollment_statuses: enrollmentStatusOptions.filter((status) => enrollment.includes(status)),
    };
}

export function educationPolicySummary(policy, modes = {}) {
    const value = normalizeEducationPolicy(policy);
    const rows = [];
    if (value.requires_completed_qualification) {
        rows.push({ key: 'qualification', label: 'Completed qualification', value: value.accepted_qualifications.map((type) => acceptedQualificationOptions.find((option) => option.value === type).label).join('; ') || 'Accepted credentials not specified', mode: modes.completed_qualification === 'preferred' ? 'Preferred' : 'Required' });
        rows.push({ key: 'unlisted', label: 'Unlisted qualifications', value: value.unlisted_qualification === 'review' ? 'Provider review; no automatic equivalency' : 'Recognized unlisted credentials do not match; unclear records need review. Required / Preferred controls blocking.' });
    }
    if (value.enrollment_statuses.length) rows.push({ key: 'enrollment', label: 'Accepted enrollment statuses', value: value.enrollment_statuses.join(', '), mode: modes.enrollment_status === 'preferred' ? 'Preferred' : 'Required' });
    return rows;
}

export function educationPolicyError(policy, conditions = []) {
    const value = normalizeEducationPolicy(policy);
    if (value.requires_completed_qualification && !value.accepted_qualifications.length) return 'Choose at least one accepted completed qualification, or turn off the completed qualification rule.';
    if (conditions.some((item) => item.key === 'currently_enrolled' && item.verification_type === 'automatic')
        && value.enrollment_statuses.some((status) => !activeEnrollmentStatuses.includes(status))) return 'This program also requires active enrollment. Remove that condition before accepting pending or not-enrolled applicants.';
    return '';
}
