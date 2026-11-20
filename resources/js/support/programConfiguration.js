import { latestDate, providerDate, providerDateOffset } from './providerDates.js';
export const programDateToday = providerDate;

const dateLabels = {
    application_opens_at: 'application opening date', deadline: 'application deadline',
    expected_results_at: 'expected final results date', handoff_deadline: 'formal application deadline',
    support_starts_at: 'support start date', support_ends_at: 'support end date',
};

export function programTimelineMinimums(dates) {
    const lastDeadline = latestDate(dates.deadline, dates.handoff_deadline);
    const afterLastDeadline = providerDateOffset(lastDeadline, 1);
    return {
        deadline: dates.application_opens_at,
        handoff_deadline: providerDateOffset(dates.deadline, 1),
        expected_results_at: afterLastDeadline,
        support_starts_at: latestDate(afterLastDeadline, dates.expected_results_at),
        support_ends_at: dates.support_starts_at,
    };
}

function timelineErrors(dates) {
    const errors = {};
    const { deadline, handoff_deadline: handoff, expected_results_at: results, support_starts_at: start, support_ends_at: end } = dates;
    const lastDeadline = latestDate(deadline, handoff);
    if (deadline && dates.application_opens_at > deadline) {
        errors.application_opens_at = 'Applications must open on or before the application deadline.';
    }
    if (!deadline && (handoff || results || start || end)) {
        errors.deadline = 'Set the application deadline before adding formal application, results, or support dates.';
    }
    if (deadline && handoff && handoff <= deadline) {
        errors.handoff_deadline = 'The formal application deadline must be after applications close.';
    }
    if (results && lastDeadline && results <= lastDeadline) {
        errors.expected_results_at = 'Expected final results must be after the last application deadline.';
    }
    if (start && lastDeadline && start <= lastDeadline) {
        errors.support_starts_at = 'Support must start after the last application deadline.';
    } else if (start && results && start < results) {
        errors.support_starts_at = 'Support cannot start before the expected final results.';
    }
    if (end && !start) {
        errors.support_starts_at = 'Set the support start date before the support end date.';
    } else if (end && end < start) {
        errors.support_ends_at = 'The support end date must be on or after the support start date.';
    }
    return errors;
}

export function programConfigurationErrors(values = {}, { today = programDateToday(), savedDates = {}, preserveHistoricalDates = false } = {}) {
    const errors = {};
    const capacityFields = {
        slots_available: 'Recipient slots',
        application_limit: 'The application limit',
    };
    const isSet = (value) => value !== '' && value !== null && value !== undefined;
    for (const [field, label] of Object.entries(capacityFields)) {
        if (isSet(values[field]) && (!Number.isInteger(Number(values[field])) || Number(values[field]) < 1 || Number(values[field]) > 1_000_000)) {
            errors[field] = `${label} must be a whole number from 1 to 1,000,000.`;
        }
    }
    if (!errors.application_limit && !errors.slots_available
        && isSet(values.application_limit) && isSet(values.slots_available)
        && Number(values.application_limit) < Number(values.slots_available)) {
        errors.application_limit = 'The application limit must be at least the number of recipient slots.';
    }

    const dates = Object.fromEntries(Object.keys(dateLabels).map(field => [field, values[field] || '']));
    const unchanged = preserveHistoricalDates && Object.keys(dateLabels).every(field => dates[field] === (savedDates[field] || ''));
    const orderedErrors = unchanged ? {} : timelineErrors(dates);
    for (const [field, label] of Object.entries(dateLabels)) {
        const keepsHistoricalDate = preserveHistoricalDates && dates[field] === savedDates[field];
        if (dates[field] && dates[field] < today && !keepsHistoricalDate) {
            errors[field] = `The ${label} must be today or a future date.`;
        }
    }
    return { ...orderedErrors, ...errors };
}
