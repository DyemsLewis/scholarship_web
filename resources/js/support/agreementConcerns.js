import { withdrawalDate, withdrawalError } from './recipientWithdrawal.js';

export const concernDate = withdrawalDate;
export const concernError = withdrawalError;
export const concernStatuses = [
    ['open', 'Open concerns'], ['draft', 'My drafts'], ['awaiting_response', 'Awaiting applicant'],
    ['under_review', 'Response received'], ['all', 'All visible concerns'],
];
export const responseKinds = [
    ['explanation', 'Provide an explanation'],
    ['correction_request', 'Request a correction'],
    ['exception_request', 'Request an exception or more time'],
];
export function concernLabel(status) {
    return ({ draft: 'Private draft', awaiting_response: 'Awaiting response', under_review: 'Response received',
        outcome_recorded: 'Outcome recorded', appeal_pending: 'Appeal pending', closed: 'Closed', cancelled: 'Cancelled draft' })[status] || 'Needs review';
}
export function concernClass(status) {
    return status === 'awaiting_response' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-slate-200 bg-slate-100 text-slate-700';
}
export function evidenceError(files, maximum = 3) {
    if (files.length > maximum) return `Choose at most ${maximum} files.`;
    for (const file of files) {
        if (!/\.(pdf|jpe?g|png)$/i.test(file.name) || !['application/pdf', 'image/jpeg', 'image/png', ''].includes(file.type || '')) return 'Use a PDF, JPG, or PNG file.';
        if (file.size > 5 * 1024 * 1024) return 'Each file must be 5 MB or smaller.';
    }
    return '';
}
