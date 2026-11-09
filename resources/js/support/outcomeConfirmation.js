import { labelFromKey } from './display.js';

let presenter = null;

export function registerOutcomeConfirmationPresenter(show) {
    presenter = show;
    return () => { if (presenter === show) presenter = null; };
}

export async function confirmOutcome(options = {}, canProceed = () => true) {
    if (!presenter || !canProceed()) return false;
    const accepted = await presenter({
        title: options.title || 'Confirm outcome',
        message: [
            options.record ? `Record: ${options.record}` : '',
            options.outcome ? `Outcome: ${labelFromKey(options.outcome)}` : '',
            ...(options.details ?? []),
            options.consequence ? `\n${options.consequence}` : '\nThis outcome will be saved to the record.',
        ].filter(Boolean).join('\n'),
        confirmLabel: options.confirmLabel || 'Record outcome',
        cancelLabel: 'Go back',
        eyebrow: 'Confirm outcome',
        tone: options.danger ? 'danger' : 'warning',
    });
    return accepted === true && Boolean(canProceed());
}
