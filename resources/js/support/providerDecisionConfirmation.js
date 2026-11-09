export function stageDecisionConsequence(workflow, stage, passed) {
    if (!passed) return 'This closes the application at this stage. The applicant will be notified.';

    const steps = workflow?.steps ?? [];
    const position = steps.findIndex((step) => step.key === stage);
    const next = position >= 0 ? steps[position + 1]?.label : null;
    return `The applicant will move to ${next || 'the next configured stage'} and will be notified.`;
}

export function finalDecisionConsequence(outcome) {
    return {
        selected: 'This offers the scholarship and uses an award slot if the program has a limit. The applicant will be notified to review the agreement.',
        waitlisted: 'The applicant stays in consideration without receiving an award. They will be notified of their waitlist status.',
        not_selected: 'This closes the application without an award. The applicant will be notified.',
    }[outcome];
}

export function decisionAwardLabel(amount) {
    return amount === null || amount === undefined || amount === ''
        ? 'Program benefits'
        : `PHP ${Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
