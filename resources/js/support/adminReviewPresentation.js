import { labelFromKey } from './display.js';

export function reviewRequirementItems(value) {
    return (Array.isArray(value) ? value : String(value ?? '').split(/\r\n|\r|\n/))
        .map((item) => String(item ?? '').trim()).filter(Boolean);
}

export function reviewErrorMessage(error, fallback) {
    return Object.values(error?.response?.data?.errors ?? {})[0]?.[0]
        || error?.response?.data?.message || fallback;
}

export function reviewStatusLabel(value, queue = '') {
    if (queue === 'applicants') {
        const labels = { approved: 'Evidence reviewed', rejected: 'Needs replacement', pending: 'Under review', unsubmitted: 'Not submitted' };
        if (labels[value]) return labels[value];
    }
    if (queue === 'programs' && value === 'rejected') return 'Returned to provider';
    const labels = { pending_review: 'In review', attention: 'Needs attention', documented: 'Documented', approved: 'Approved', unsubmitted: 'Not submitted' };
    return labels[value] || labelFromKey(value || 'pending');
}

export function reviewQueueStatus(item, queue) {
    if (['benefits', 'selection-activities', 'monitoring'].includes(queue)) return item.oversight_status || item.status || 'pending';
    return item.verification_status || item.status || 'pending';
}

export function reviewStatusClass(value) {
    if (['approved', 'published', 'documented', 'reviewed', 'stable', 'completed', 'confirmed'].includes(value)) return 'bg-emerald-100 text-emerald-800';
    if (['rejected', 'attention', 'missed', 'withheld', 'overdue', 'needs_replacement'].includes(value)) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-700';
}
