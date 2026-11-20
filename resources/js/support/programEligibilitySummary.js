export function eligibilitySummaryFromRules(rules = []) {
    return [
        ['required', 'Required to apply'],
        ['preferred', 'Preferred, not required to apply'],
    ].map(([mode, heading]) => {
        const items = rules.filter(rule => rule.mode === mode);
        return items.length ? `${heading}:\n${items.map(rule => `- ${rule.label}: ${rule.value}`).join('\n')}` : '';
    }).filter(Boolean).join('\n\n');
}
