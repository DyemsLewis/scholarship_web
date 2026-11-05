export const withdrawalStatuses = [
    { value: 'pending', label: 'Awaiting review' },
    { value: 'approved', label: 'Approved' },
    { value: 'declined', label: 'Declined' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'all', label: 'All requests' },
];

export function withdrawalLabel(status) {
    return { pending: 'Awaiting provider review', approved: 'Withdrawal approved', declined: 'Request declined', cancelled: 'Request cancelled' }[status] || 'Request recorded';
}

export function withdrawalClass(status) {
    return status === 'pending' ? 'border-amber-200 bg-amber-50 text-amber-900'
        : status === 'approved' ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
            : 'border-slate-300 bg-slate-100 text-slate-700';
}

export function withdrawalDate(value) {
    if (!value) return 'Not recorded';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Not recorded' : new Intl.DateTimeFormat('en-PH', {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila',
    }).format(date);
}

export function withdrawalError(error, fallback = 'The request could not be saved. Your explanation has been kept.') {
    return Object.values(error?.response?.data?.errors ?? {}).flat()[0] || error?.response?.data?.message || fallback;
}

export function withdrawalAgreementRows(agreement) {
    const snapshot = agreement?.snapshot;
    if (!snapshot || typeof snapshot !== 'object') return [];
    const expectation = snapshot.recipient_expectation ?? {};
    return [
        ['Recipient responsibilities', expectation.responsibilities],
        ['Exceptions or withdrawal', expectation.exit_or_exception_process],
        ['Release conditions', expectation.release_conditions],
        ['Provider terms', snapshot.other_contract_terms],
        ['Return service', snapshot.return_service_contract],
        ['Renewal policy', snapshot.renewal_policy],
    ].filter(([, value]) => typeof value === 'string' && value.trim());
}
