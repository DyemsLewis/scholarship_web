export const deliveryIssues = [
    ['not_received', 'Benefit not received'], ['incorrect_amount', 'Incorrect amount'],
    ['incomplete_benefit', 'Incomplete benefit'], ['damaged_item', 'Damaged item'],
    ['delivery_question', 'Ask about delivery'], ['other', 'Another delivery issue'],
];
export const deliveryReplies = [
    ['correction_planned', 'Plan a correction'], ['delivery_proposed', 'Submit delivery proof'],
    ['information_provided', 'Provide information'],
];
export function deliveryClass(status) {
    if (status === 'closed') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (status === 'awaiting_recipient') return 'border-amber-300 bg-amber-50 text-amber-900';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}
export function deliveryBodyValid({ creating, provider, kind, source, period, details, confirmed, files, record, date, followUp }) {
    if (!confirmed || !kind || details.length > 4000) return false;
    if (creating) return !!source && (!source.period_required || !!period.trim()) && details.trim().length >= 10;
    if (provider) {
        if (details.trim().length < 10) return false;
        if (kind === 'delivery_proposed') return !!record && !!date && files.length > 0;
        if (kind === 'correction_planned') return !!followUp;
        return kind === 'information_provided';
    }
    if (kind === 'receipt_confirmed') return !!date;
    if (kind === 'clarification_accepted') return true;
    return ['still_unresolved', 'additional_information'].includes(kind) && details.trim().length >= 10;
}
