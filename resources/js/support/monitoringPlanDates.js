import { providerDate } from './providerDates.js';

export function monitoringPlanDateError(values, program = {}, saved = null, today = providerDate()) {
    const start = values.starts_on || program.support_starts_on;
    const end = values.ends_on || program.support_ends_on;
    const savedEnd = saved?.ends_on || program.support_ends_on;
    if (start && end && end < start) return 'The monitoring end date must be on or after the start date.';
    if (end && end < today && !(saved?.status === 'active' && end === savedEnd)) return 'The monitoring end date must be today or a future date.';
    for (const date of [start, end]) {
        if (date && ((program.support_starts_on && date < program.support_starts_on) || (program.support_ends_on && date > program.support_ends_on))) return 'Keep the monitoring dates within the program support period.';
    }
    return '';
}
