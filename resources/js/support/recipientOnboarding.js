export function programOnboardingPolicy(value) {
    if (value === null || value === undefined) return null;
    const required = [true, 1, '1'].includes(value.final_agreement_required);
    return {
        version: 1,
        final_agreement_required: required,
        instructions: required && typeof value.instructions === 'string' ? value.instructions : null,
    };
}

export function onboardingPolicyError(value) {
    if (!value?.final_agreement_required) return '';
    if (typeof value.instructions !== 'string' || !value.instructions.trim()) return 'Explain how selected recipients complete the final agreement.';
    if (value.instructions.length > 3000) return 'Keep final-agreement instructions within 3000 characters.';
    return '';
}
