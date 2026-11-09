export function scheduleAcknowledgementPrompt(activity) {
    return {
        title: 'Confirm you saw this schedule?',
        message: [
            activity.title || 'Provider activity',
            activity.scheduled_label,
            '\nThe provider will see that you reviewed this schedule. This does not mark you as having attended.',
        ].filter(Boolean).join('\n'),
        confirmLabel: 'Confirm schedule',
        cancelLabel: 'Go back',
    };
}
