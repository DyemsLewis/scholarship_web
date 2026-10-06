export const providerProgramFormSections = [
    { id: 'details', slug: 'basics', label: 'Basics', help: 'Name the scholarship and give applicants a short, clear summary.' },
    { id: 'support', slug: 'support', label: 'Support', help: 'List what recipients receive and how many can be selected.' },
    { id: 'logistics', slug: 'dates-location', label: 'Dates & location', help: 'Set the application dates, public contact, and program location.' },
    { id: 'eligibility', slug: 'eligibility', label: 'Eligible applicants', help: 'Set who can apply and how profile matching should work.' },
    { id: 'application', slug: 'application', label: 'Application', help: 'Choose the files, questions, and formal application instructions.' },
    { id: 'selection', slug: 'selection', label: 'Selection', help: 'Arrange the provider stages and review scoring.' },
    { id: 'review', slug: 'review', label: 'Review & submit', help: 'Check the program, save a draft, or send it for admin review.' },
];

export const providerProgramFormSubsections = {
    eligibility: [
        { id: 'requirements', label: 'Requirements', icon: 'fa-solid fa-list-check' },
        { id: 'matching', label: 'Matching rules', icon: 'fa-solid fa-sliders' },
    ],
    application: [
        { id: 'files', label: 'Portal files', icon: 'fa-solid fa-folder-open' },
        { id: 'questions', label: 'Questions', icon: 'fa-solid fa-message' },
        { id: 'handoff', label: 'Formal application', icon: 'fa-solid fa-arrow-right-to-bracket' },
        { id: 'expectations', label: 'Recipient terms', icon: 'fa-solid fa-handshake' },
    ],
    selection: [
        { id: 'flow', label: 'Process stages', icon: 'fa-solid fa-route' },
        { id: 'scoring', label: 'Review scoring', icon: 'fa-solid fa-star-half-stroke' },
    ],
};

const sectionBySlug = new Map(providerProgramFormSections.map((section) => [section.slug, section.id]));

export function sectionFromProviderProgramFormPath(pathname) {
    const slug = String(pathname ?? '').match(/\/provider\/programs\/\d+\/edit\/([a-z-]+)\/?$/)?.[1];

    return sectionBySlug.get(slug) ?? 'details';
}

export function providerProgramFormStepUrl(programId, sectionId) {
    const section = providerProgramFormSections.find((item) => item.id === sectionId);

    if (!programId || !section) return '';

    return `/provider/programs/${programId}/edit/${section.slug}`;
}
