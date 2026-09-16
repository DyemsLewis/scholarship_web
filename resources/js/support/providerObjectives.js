export const providerObjectiveOptions = [
    {
        value: 'education_access',
        label: 'Expand education access',
        detail: 'Help reduce financial barriers for qualified learners.',
        icon: 'fa-solid fa-door-open',
    },
    {
        value: 'priority_skills',
        label: 'Develop priority skills',
        detail: 'Support study paths connected to skills needed by communities or industries.',
        icon: 'fa-solid fa-gears',
    },
    {
        value: 'future_talent',
        label: 'Build a future talent pool',
        detail: 'Help learners prepare for fields relevant to the provider mission.',
        icon: 'fa-solid fa-seedling',
    },
    {
        value: 'community_development',
        label: 'Support community development',
        detail: 'Invest in learners who may contribute to local social and economic goals.',
        icon: 'fa-solid fa-people-group',
    },
    {
        value: 'equity_inclusion',
        label: 'Advance equity and inclusion',
        detail: 'Reach learner groups that face financial or participation barriers.',
        icon: 'fa-solid fa-scale-balanced',
    },
    {
        value: 'academic_excellence',
        label: 'Encourage academic excellence',
        detail: 'Recognize achievement and encourage continued learning progress.',
        icon: 'fa-solid fa-award',
    },
    {
        value: 'education_partnerships',
        label: 'Strengthen education partnerships',
        detail: 'Connect the provider with learners, schools, and education communities.',
        icon: 'fa-solid fa-handshake',
    },
    {
        value: 'other',
        label: 'Other mission-related outcome',
        detail: 'State another intended outcome that is specific to this program.',
        icon: 'fa-solid fa-bullseye',
    },
];

export function providerObjectiveDetails(values) {
    const selected = new Set(Array.isArray(values) ? values : []);

    return providerObjectiveOptions.filter((option) => selected.has(option.value));
}
