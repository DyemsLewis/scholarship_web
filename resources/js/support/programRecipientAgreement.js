export const recipientCommitmentOptions = [
    ['provider_briefing', 'Choose the agreement type'],
    ['none', 'No ongoing recipient commitment'],
    ['renewal', 'Enrollment or renewal requirements'],
    ['service', 'Service or community commitment'],
    ['activities', 'Required program activities'],
    ['reporting', 'Progress or utilization reports'],
    ['custom', 'Custom commitment'],
];

const releaseField = { key: 'release_conditions', label: 'Conditions for receiving benefits', maxLength: 1500 };
const ongoingFields = [
    { key: 'responsibilities', label: 'Recipient responsibilities', maxLength: 2000 },
    { key: 'required_evidence', label: 'Required evidence', maxLength: 1500 },
    { key: 'duration', label: 'Commitment duration', maxLength: 500 },
    { key: 'noncompliance_consequence', label: 'If requirements are not met', maxLength: 1000 },
    { key: 'exit_or_exception_process', label: 'Exception or withdrawal process', maxLength: 1000 },
];
const textFields = [releaseField, ...ongoingFields].map(field => field.key);

export function recipientTermsFields(commitmentType) {
    return ['provider_briefing', 'none'].includes(commitmentType) ? [releaseField] : [releaseField, ...ongoingFields];
}

export function recipientTermsIssue(value) {
    const terms = normalizeProgramRecipientAgreement(value);
    if (terms.commitment_type === 'provider_briefing') return 'Choose a recipient commitment in Selection.';
    const missing = recipientTermsFields(terms.commitment_type).find(field => !terms[field.key].trim());
    return missing ? `Complete "${missing.label}" in Recipient terms.` : '';
}

export function normalizeProgramRecipientAgreement(value) {
    const saved = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    return {
        commitment_type: typeof saved.commitment_type === 'string' && saved.commitment_type.trim()
            ? saved.commitment_type : 'provider_briefing',
        // Drafts store unanswered fields as null; text inputs and validation need strings.
        ...Object.fromEntries(textFields.map(field => [field,
            typeof saved[field] === 'string' ? saved[field]
                : typeof saved[field] === 'number' && Number.isFinite(saved[field]) ? String(saved[field]) : '',
        ])),
    };
}
