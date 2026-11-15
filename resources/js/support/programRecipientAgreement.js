const textFields = [
    'responsibilities', 'required_evidence', 'release_conditions', 'duration',
    'noncompliance_consequence', 'exit_or_exception_process',
];

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
