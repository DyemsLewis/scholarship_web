const genericBriefingText = 'The provider will explain any final recipient commitments after the applicant is accepted.';

export const recipientCommitmentLabels = {
    provider_briefing: 'Final terms not yet disclosed',
    none: 'No recipient commitment',
    renewal: 'Renewal condition',
    service: 'Service or community commitment',
    activities: 'Program activity or orientation',
    reporting: 'Progress or utilization reporting',
    custom: 'Other recipient commitment',
};

function hasText(value) {
    return String(value ?? '').trim() !== '';
}

export function recipientCommitmentText(scholarship) {
    return [
        scholarship?.return_service_contract,
        scholarship?.other_contract_terms,
        scholarship?.renewal_policy,
    ].filter(hasText).join('\n\n');
}

export function normalizeRecipientAgreement(scholarship) {
    const saved = scholarship?.recipient_agreement && typeof scholarship.recipient_agreement === 'object'
        ? scholarship.recipient_agreement
        : {};
    const commitmentText = recipientCommitmentText(scholarship);
    let commitmentType = saved.commitment_type || (commitmentText ? 'custom' : 'provider_briefing');

    if (commitmentType === 'none' && commitmentText) {
        commitmentType = 'custom';
    }

    return {
        commitment_type: commitmentType,
        responsibilities: saved.responsibilities ?? commitmentText,
        required_evidence: saved.required_evidence ?? '',
        release_conditions: saved.release_conditions ?? '',
        duration: saved.duration ?? '',
        noncompliance_consequence: saved.noncompliance_consequence ?? '',
        exit_or_exception_process: saved.exit_or_exception_process ?? '',
        commitment_text: commitmentText,
    };
}

export function agreementClarity(scholarship) {
    const agreement = normalizeRecipientAgreement(scholarship);
    const benefits = Array.isArray(scholarship?.benefits) ? scholarship.benefits : [];
    const noCommitment = agreement.commitment_type === 'none' && !agreement.commitment_text;
    const commitmentDisclosed = noCommitment || (
        hasText(agreement.responsibilities)
        && agreement.responsibilities !== genericBriefingText
        && agreement.commitment_type !== 'provider_briefing'
    );
    const checks = [
        {
            key: 'support',
            label: 'Provider support',
            value: scholarship?.benefit_summary || (benefits.length ? `${benefits.length} listed benefits` : ''),
            complete: benefits.length > 0,
            missing: 'The support package is not clearly listed.',
        },
        {
            key: 'commitment',
            label: 'What the provider expects',
            value: noCommitment ? 'The provider states that no recipient commitment applies.' : agreement.responsibilities,
            complete: commitmentDisclosed,
            missing: 'The recipient duty has not been clearly explained.',
        },
        {
            key: 'evidence',
            label: 'Proof the recipient must submit',
            value: noCommitment ? 'No responsibility evidence is required.' : agreement.required_evidence,
            complete: noCommitment || hasText(agreement.required_evidence),
            missing: 'The proof required from the recipient has not been listed.',
        },
        {
            key: 'release',
            label: 'Conditions for receiving benefits',
            value: agreement.release_conditions,
            complete: hasText(agreement.release_conditions),
            missing: 'The conditions and timing for benefit release have not been explained.',
        },
        {
            key: 'duration',
            label: 'Timeframe',
            value: noCommitment ? 'Not applicable because no commitment is listed.' : agreement.duration,
            complete: noCommitment || hasText(agreement.duration),
            missing: 'The duration or completion point is missing.',
        },
        {
            key: 'consequence',
            label: 'If the commitment is not completed',
            value: noCommitment ? 'No commitment consequence applies.' : agreement.noncompliance_consequence,
            complete: noCommitment || hasText(agreement.noncompliance_consequence),
            missing: 'The possible consequence has not been disclosed.',
        },
        {
            key: 'exception',
            label: 'Exception or withdrawal process',
            value: noCommitment ? 'Use the provider contact for program questions or withdrawal.' : agreement.exit_or_exception_process,
            complete: noCommitment || hasText(agreement.exit_or_exception_process),
            missing: 'No process is listed for illness, withdrawal, or circumstances outside the recipient\'s control.',
        },
    ];
    const completed = checks.filter((check) => check.complete).length;

    return {
        agreement,
        checks,
        completed,
        total: checks.length,
        complete: completed === checks.length,
        noCommitment,
        title: completed === checks.length ? 'Clear enough to review' : 'Needs clarification before agreeing',
    };
}
