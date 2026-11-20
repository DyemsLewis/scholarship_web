export const formalApplicationModes = [
    ['onsite', 'Visit provider office'],
    ['online', 'Use an official link'],
    ['provider_contact', 'Wait for provider contact'],
];

export function copyFormalApplication(value = {}) {
    return {
        handoffMode: value.handoffMode || '',
        handoffInstructions: value.handoffInstructions ?? '',
        handoffUrl: value.handoffUrl ?? '',
        handoffLocationName: value.handoffLocationName ?? '',
        handoffLocationAddress: value.handoffLocationAddress ?? '',
        handoffAddressDetails: value.handoffAddressDetails ? { ...value.handoffAddressDetails } : null,
        handoffLatitude: value.handoffLatitude ?? null,
        handoffLongitude: value.handoffLongitude ?? null,
    };
}

export function formalApplicationLinkHost(value) {
    const url = String(value ?? '').trim();
    if (!/^https?:\/\//i.test(url)) return '';
    try {
        const link = new URL(url);
        return ['http:', 'https:'].includes(link.protocol) ? link.host : '';
    } catch {
        return '';
    }
}

export function formalApplicationIssue(value) {
    if (!formalApplicationModes.some(([mode]) => mode === value.handoffMode)) return 'Choose how qualified applicants continue.';
    if (!value.handoffInstructions?.trim()) return 'Enter instructions in Formal application details.';
    if (value.handoffMode === 'onsite' && !value.handoffLocationAddress?.trim()) return 'Add the venue address in Formal application details.';
    if (value.handoffMode === 'online' && !formalApplicationLinkHost(value.handoffUrl)) return 'Add a valid official link in Formal application details.';
    return '';
}
