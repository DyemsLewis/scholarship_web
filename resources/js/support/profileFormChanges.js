const numericFields = new Set(['gwa', 'household_size', 'latitude', 'longitude']);

function comparableValue(key, value) {
    // Decimal API strings and numeric inputs can represent the same saved value.
    if (numericFields.has(key) && ['string', 'number'].includes(typeof value)
        && String(value).trim() !== '' && Number.isFinite(Number(value))) {
        return Number(value);
    }
    return value;
}

export function profileFormHasChanges(current, saved) {
    return [...new Set([...Object.keys(current), ...Object.keys(saved)])]
        .some((key) => JSON.stringify(comparableValue(key, current[key])) !== JSON.stringify(comparableValue(key, saved[key])));
}
