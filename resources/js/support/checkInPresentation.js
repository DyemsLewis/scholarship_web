export function checkInCardFacts(record = {}, past = false) {
    const handoff = record.handoff;
    if (handoff) return {
        dateCaption: handoff.rescheduled && !handoff.started && !past ? 'New date and time' : 'Appointment date',
        dateLabel: handoff.scheduled_label || 'Awaiting provider schedule',
        contextCaption: 'Venue',
        contextLabel: handoff.location || 'Awaiting provider details',
    };
    return {
        dateCaption: past && record.closed_at ? 'Closed on' : 'Deadline',
        dateLabel: (past && record.closed_at) || record.effective_due_label || record.due_label || 'No deadline posted',
        contextCaption: 'Checklist',
        contextLabel: record.progress_label || 'Open the record for details',
    };
}

export function checkInCardGuidance(record = {}, past = false) {
    if (past) return 'View the provider outcome and saved records.';
    if (record.state === 'upcoming') return record.handoff
        ? 'Review the appointment and prepare the listed documents.'
        : 'Check the opening date and instructions before submitting.';
    return (record.handoff && record.progress_label) || (record.next_actor_label
        ? `Next step: ${record.next_actor_label}`
        : 'Open the check-in for instructions and provider updates.');
}
