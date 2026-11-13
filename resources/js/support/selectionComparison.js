export function selectionRationaleRequired(comparison, outcome) {
    if (!comparison?.scoring_enabled || !outcome) return false;
    return Boolean(comparison.tie_at_cutoff
        || (comparison.recommended_outcome && comparison.recommended_outcome !== outcome)
        || (outcome === 'selected' && comparison.waitlist_priority));
}
