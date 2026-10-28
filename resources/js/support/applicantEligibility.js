// Presentation only. Application permission always comes from the server's gates.
export function eligibilityCriterionPresentation(criterion) {
    if (criterion?.category === 'readiness' || criterion?.key === 'documents') {
        const status = criterion.status;
        return { status, label: status === 'missing' ? 'Files needed' : (status === 'pass' ? 'Files ready' : 'No files required'), state: status === 'missing' ? 'attention' : (status === 'pass' ? 'ready' : 'neutral'), modeLabel: '' };
    }
    const preferred = criterion?.rule_mode === 'preferred';
    const review = criterion?.needs_review === true
        || ['scale_mismatch', 'manual_review'].includes(criterion?.comparison_mode)
        || (criterion?.key === 'academic' && criterion?.status === 'info' && Boolean(criterion?.requirement));
    const modeLabel = preferred ? 'Preferred' : 'Required';
    if (criterion?.status === 'fail') {
        return preferred
            ? { status: 'preferred', label: 'Preference not matched', state: 'attention', modeLabel }
            : { status: 'fail', label: 'Required rule not met', state: 'blocked', modeLabel };
    }
    if (review) return { status: 'review', label: 'Needs review', state: 'attention', modeLabel };
    if (criterion?.status === 'missing') return { status: 'missing', label: 'Profile information needed', state: 'attention', modeLabel };
    if (criterion?.status === 'pass') return { status: 'pass', label: 'Matched', state: 'ready', modeLabel };
    if (criterion?.status === 'info') return { status: 'info', label: 'Open', state: 'neutral', modeLabel: '' };
    return { status: 'unknown', label: 'Not checked', state: 'neutral', modeLabel: '' };
}

export function eligibilityOverview(match) {
    if (match?.guidance) return match.guidance;
    const checks = (match?.criteria ?? []).filter((item) => item.category !== 'readiness' && item.key !== 'documents');
    const blockers = match?.blocking_criteria?.length ? match.blocking_criteria : checks.filter((item) => eligibilityCriterionPresentation(item).status === 'fail');
    const labels = (items) => items.map((item) => item.label).filter(Boolean).join(', ');
    if (blockers.length || match?.is_eligible === false) {
        return { state: 'blocked', label: 'Required rules not met', title: 'Some required rules do not match', description: `Required rules not met: ${labels(blockers) || 'Review the eligibility comparison'}. These differences prevent applying.` };
    }
    if (checks.some((item) => eligibilityCriterionPresentation(item).status === 'review')) {
        return { state: 'attention', label: 'Needs review', title: 'Some checks need provider review', description: 'Some checks cannot be compared automatically. They are not confirmed matches or failed rules.' };
    }
    if (checks.some((item) => item.status === 'missing')) {
        return { state: 'attention', label: 'Profile information needed', title: 'Complete your profile comparison', description: 'Add the missing profile information for a complete comparison.' };
    }
    if (checks.some((item) => eligibilityCriterionPresentation(item).status === 'preferred')) {
        return { state: 'attention', label: 'Preferences differ', title: 'Some preferences do not match', description: 'Preferred differences do not block an application. The provider will still review your information.' };
    }
    return { state: checks.length ? 'ready' : 'neutral', label: checks.length ? 'No required mismatches' : 'Not checked', title: checks.length ? 'No required rule mismatches found' : 'Review the published eligibility rules', description: 'Your profile, supporting records, and application availability still need to meet the submission requirements.' };
}
