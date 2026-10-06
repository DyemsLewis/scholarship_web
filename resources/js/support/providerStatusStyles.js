const positive = 'bg-emerald-100 text-emerald-800';
const negative = 'bg-rose-100 text-rose-800';
const warning = 'bg-amber-100 text-amber-800';
const neutral = 'bg-slate-100 text-slate-700';
const informational = 'bg-sky-100 text-sky-800';

export function applicationStatusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'exam_passed'].includes(status)) return positive;
    if (['withdrawn', 'rejected', 'not_awarded', 'exam_failed', 'interview_failed', 'benefits_terminated'].includes(status)) return negative;
    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled', 'waitlisted'].includes(status)) return 'bg-slate-100 text-slate-800';
    return warning;
}

export function eligibilityStatusClass(status) {
    if (status === 'pass') return positive;
    if (status === 'fail') return negative;
    if (status === 'missing') return warning;
    return neutral;
}

export function recommendationStatusClass(recommendation) {
    if (recommendation === 'highly_recommended') return positive;
    if (recommendation === 'recommended') return 'bg-slate-100 text-slate-800';
    if (recommendation === 'needs_review') return warning;
    if (recommendation === 'not_recommended') return 'bg-slate-200 text-slate-700';
    return negative;
}

export function documentStatusClass(status) {
    if (status === 'accepted') return positive;
    if (status === 'rejected') return negative;
    if (status === 'needs_replacement') return warning;
    return neutral;
}

export function profileVerificationStatusClass(status) {
    if (status === 'approved') return positive;
    if (status === 'rejected') return negative;
    if (status === 'pending') return warning;
    return neutral;
}

export function evidenceStatusClass(status) {
    if (status === 'verified') return positive;
    if (status === 'document_supported') return warning;
    if (['needs_replacement', 'missing'].includes(status)) return negative;
    return 'bg-slate-100 text-slate-600';
}

export function recipientSupportStatusClass(status) {
    if (status === 'renewed') return informational;
    if (status === 'completed') return positive;
    if (status === 'terminated') return 'bg-rose-100 text-rose-700';
    return warning;
}

export function recipientRecordStatusClass(status) {
    if (['accepted', 'met', 'excused', 'released', 'renewed', 'completed'].includes(status)) return positive;
    if (['not_met', 'missed', 'withheld', 'terminated', 'declined'].includes(status)) return 'bg-rose-100 text-rose-700';
    if (['needs_correction', 'prepared'].includes(status)) return warning;
    return 'bg-slate-100 text-slate-600';
}

export function benefitReleaseStatusClass(status) {
    if (['released', 'completed'].includes(status)) return positive;
    if (['prepared', 'in_progress'].includes(status)) return informational;
    if (['missed', 'withheld'].includes(status)) return 'bg-rose-100 text-rose-700';
    return warning;
}

export function receiptResponseStatusClass(response) {
    if (!response) return 'bg-slate-100 text-slate-600';
    if (['confirmed', 'resolved'].includes(response.status)) return positive;
    return 'bg-rose-100 text-rose-700';
}

export function requirementComparisonStatusClass(status) {
    if (status === 'pass') return positive;
    if (status === 'fail') return 'bg-rose-100 text-rose-700';
    return warning;
}

export function monitoringReviewStatusClass(status) {
    if (status === 'met') return positive;
    if (status === 'not_met') return 'bg-rose-100 text-rose-700';
    if (status === 'needs_correction') return warning;
    if (status === 'excused') return informational;
    return 'bg-slate-100 text-slate-600';
}

export function adjustmentRequestStatusClass(status) {
    if (status === 'approved') return positive;
    if (status === 'declined') return negative;
    return 'bg-amber-100 text-amber-900';
}
