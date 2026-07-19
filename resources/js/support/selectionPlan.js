export const selectionStageDefinitions = [
    {
        value: 'screening',
        label: 'Screening',
        icon: 'fa-solid fa-list-check',
        detail: 'Eligibility and file review',
    },
    {
        value: 'exam',
        label: 'Exam',
        icon: 'fa-solid fa-clipboard-question',
        detail: 'Provider-managed exam',
    },
    {
        value: 'interview',
        label: 'Interview',
        icon: 'fa-solid fa-comments',
        detail: 'Provider conversation',
    },
    {
        value: 'distribution',
        label: 'Reward distribution',
        icon: 'fa-solid fa-hand-holding-dollar',
        detail: 'Scholarship release',
    },
];

export function programEventForStage(scholarship, stage) {
    const events = Array.isArray(scholarship?.program_events)
        ? scholarship.program_events
        : [];

    return events.find((event) => event.type === stage) ?? null;
}

export function selectionPlanFor(scholarship) {
    const selectedStages = Array.isArray(scholarship?.selection_stages)
        ? scholarship.selection_stages
        : ['screening', 'distribution'];

    return selectionStageDefinitions
        .filter((stage) => selectedStages.includes(stage.value))
        .map((stage) => ({
            ...stage,
            event: programEventForStage(scholarship, stage.value),
        }));
}

export function progressStepIcon(step) {
    if (step === 'submitted') {
        return 'fa-solid fa-paper-plane';
    }

    return selectionStageDefinitions.find((stage) => stage.value === step)?.icon
        ?? 'fa-solid fa-circle-dot';
}

export function progressStateLabel(state) {
    return {
        complete: 'Complete',
        current: 'Current',
        stopped: 'Did not advance',
        skipped: 'Not reached',
        upcoming: 'Upcoming',
    }[state] ?? 'Upcoming';
}
