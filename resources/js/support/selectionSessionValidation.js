export function sessionScheduleError(form, assignedCount = 0, now = Date.now()) {
    const startsAt = Date.parse(form.starts_at);
    if (!Number.isFinite(startsAt) || startsAt < now) return 'Choose a future date and time for the session.';
    if (form.ends_at) {
        const endsAt = Date.parse(form.ends_at);
        if (!Number.isFinite(endsAt) || endsAt <= startsAt) return 'The end time must be later than the start time.';
    }
    if (form.capacity !== '' && form.capacity !== null && form.capacity !== undefined) {
        const capacity = Number(form.capacity);
        if (!Number.isInteger(capacity) || capacity < 1 || capacity > 10_000) return 'Capacity must be a whole number from 1 to 10,000.';
        if (capacity < assignedCount) return `Keep capacity at ${assignedCount} or higher to accommodate the applicants already assigned.`;
    }
    return '';
}
