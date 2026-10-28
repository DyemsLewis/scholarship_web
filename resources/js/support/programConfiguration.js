export function programConfigurationErrors(values = {}) {
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

    const pairs = [
        ['deadline', 'application_opens_at', 'application_opens_at', 'The application opening date must be on or before the deadline.'],
        ['expected_results_at', 'deadline', 'expected_results_at', 'The expected results date must be on or after the application deadline.'],
        ['handoff_deadline', 'deadline', 'handoff_deadline', 'The formal application deadline must be on or after the pre-screening deadline.'],
        ['support_ends_at', 'support_starts_at', 'support_ends_at', 'The support end date must be on or after the support start date.'],
    ];
    for (const [later, earlier, field, message] of pairs) {
        if (isSet(values[later]) && isSet(values[earlier]) && values[later] < values[earlier]) {
            errors[field] = message;
        }
    }
    return errors;
}
