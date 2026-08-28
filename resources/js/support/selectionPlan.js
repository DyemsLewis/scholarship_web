export const selectionStageDefinitions = [
    {
        value: 'screening',
        label: 'Pre-screening review',
        icon: 'fa-solid fa-list-check',
        detail: 'Eligibility and file review',
    },
    {
        value: 'formal_application',
        label: 'Formal application',
        icon: 'fa-solid fa-file-signature',
        detail: 'Continue directly with the provider',
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
        value: 'decision',
        label: 'Final decision',
        icon: 'fa-solid fa-award',
        detail: 'Selected, waitlisted, or not selected',
    },
];

export function programEventForStage(scholarship, stage) {
    if (!['exam', 'interview'].includes(stage)) {
        return null;
    }

    const events = Array.isArray(scholarship?.program_events)
        ? scholarship.program_events
        : [];

    return events.find((event) => event.type === stage) ?? null;
}

export function selectionPlanFor(scholarship) {
    const rawStages = Array.isArray(scholarship?.selection_stages)
        ? scholarship.selection_stages
        : [];
    const selectedStages = rawStages
        .map((stage) => stage === 'distribution' ? 'decision' : stage)
        .filter((stage, index, stages) => selectionStageDefinitions.some((definition) => definition.value === stage)
            && stages.indexOf(stage) === index);

    const middleStages = selectedStages.filter((stage) => !['screening', 'decision'].includes(stage));

    if (!middleStages.includes('formal_application')) {
        middleStages.push('formal_application');
    }

    return ['screening', ...middleStages, 'decision']
        .map((stageValue) => selectionStageDefinitions.find((stage) => stage.value === stageValue))
        .filter(Boolean)
        .map((stage) => ({ ...stage, event: programEventForStage(scholarship, stage.value) }));
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
