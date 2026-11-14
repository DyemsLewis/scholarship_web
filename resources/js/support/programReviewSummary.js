import { labelFromKey } from './display.js';
import { scholarshipBenefitFacts } from './scholarshipBenefits.js';
import { hasLocationPin } from './locationPins.js';

const text = (value, fallback = 'Not set') => String(value ?? '').trim() || fallback;
const lines = (value) => String(value ?? '').split(/\r?\n/).map(item => item.trim()).filter(Boolean);
const optionLabel = (value, catalog = []) => catalog.find(([key]) => key === value)?.[1] || labelFromKey(value) || 'Not set';
const count = (value) => value === '' || value === null || value === undefined ? 'Not set'
    : Number.isFinite(Number(value)) ? Number(value).toLocaleString('en-PH') : String(value);
const fact = (label, value, wide = false) => ({ label, value, wide });

export function programReviewDate(value, fallback = 'Not set') {
    if (!value) return fallback;
    const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/);
    if (!match) return String(value);
    const [, year, month, day] = match;
    const date = new Date(Number(year), Number(month) - 1, Number(day));
    if (date.getFullYear() !== Number(year) || date.getMonth() !== Number(month) - 1 || date.getDate() !== Number(day)) return String(value);
    return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function programReviewSummary(form, { eligibilitySummary = '', rules = [], scoring = {}, catalogs = {}, coverLabel = '', hasLogo = false } = {}) {
    const agreement = form.recipientAgreement ?? {};
    const requirements = lines(form.requirements);
    const optional = lines(form.optionalRequirements);
    const qualified = lines(form.postQualificationRequirements);
    const ongoing = !['none', 'provider_briefing'].includes(agreement.commitment_type);
    const handoff = [
        fact('Instructions', text(form.handoffInstructions)),
        ...(form.handoffMode === 'onsite' ? [
            fact('Formal application venue', [form.handoffLocationName, form.handoffLocationAddress].filter(Boolean).join('\n') || 'Not set'),
            fact('Formal application map pin', hasLocationPin(form.handoffLatitude, form.handoffLongitude) ? 'Selected' : 'Not set'),
        ] : []),
        ...(form.handoffMode === 'online' ? [fact('Official link', text(form.handoffUrl))] : []),
    ];
    const weights = scoring.legacy ? 'Existing intake without a numeric ranking score'
        : !scoring.available ? 'Scoring preview unavailable'
            : scoring.rows?.length ? `${scoring.fixed ? 'Fixed intake: ' : ''}${scoring.formula}` : 'Qualifying checks only; no numeric ranking score';

    return {
        basics: {
            facts: [fact('Program title', text(form.title), true), fact('Category', text(form.category)), fact('Program cycle', text(form.programCycle)),
                fact('Scholarship logo', hasLogo ? 'Added' : 'Not set'), fact('Background', text(coverLabel))],
            detailsLabel: 'Program description and official link',
            details: [fact('Program description', text(form.description)), fact('Official program link', text(form.officialProgramUrl, 'Not specified'))],
        },
        support: {
            facts: [fact('Recipient slots', count(form.slotsAvailable)), fact('Application limit', count(form.applicationLimit)),
                fact('Support period', `${programReviewDate(form.supportStartsAt)} to ${programReviewDate(form.supportEndsAt)}`, true)],
            benefits: (form.benefits ?? []).map(benefit => ({
                title: text(benefit.title, optionLabel(benefit.type, catalogs.benefitTypes)),
                summary: scholarshipBenefitFacts({
                    ...benefit,
                    coverage_label: benefit.coverage ? optionLabel(benefit.coverage, [['full', 'Full coverage'], ['partial', 'Partial coverage'], ['fixed', 'Fixed value']]) : '',
                    frequency_label: optionLabel(benefit.frequency, catalogs.frequencyOptions),
                }).map(item => item.value).join(' / ') || 'No additional benefit details',
            })),
            detailsLabel: 'Benefit inclusions',
            details: (form.benefits ?? []).filter(benefit => String(benefit.description ?? '').trim()).map(benefit => fact(text(benefit.title, optionLabel(benefit.type, catalogs.benefitTypes)), benefit.description)),
        },
        'dates-location': {
            facts: [fact('Applications open', programReviewDate(form.applicationOpensAt, 'Not specified')), fact('Application deadline', programReviewDate(form.deadline)),
                fact('Expected results', programReviewDate(form.expectedResultsAt, 'Not specified')),
                fact('Public map pin', hasLocationPin(form.latitude, form.longitude) ? 'Selected' : 'Not set'),
                fact('Public venue', [form.locationName, form.locationAddress].filter(Boolean).join('\n') || 'Not set', true),
                fact('Contact email', text(form.contactEmail, 'Not specified')), fact('Contact number', text(form.contactNumber, 'Not specified'))],
            detailsLabel: 'Public contact person',
            details: [form.contactPerson && fact('Contact person', form.contactPerson), form.contactDepartment && fact('Office / department', form.contactDepartment)].filter(Boolean),
        },
        eligibility: {
            facts: [fact('Target education', rules.find(rule => rule.key === 'education_level')?.value || 'No education-level restriction'),
                fact('Academic rule', rules.find(rule => rule.key === 'academic')?.value || 'No academic restriction'),
                fact('Required rules', String(rules.filter(rule => rule.mode === 'required').length)),
                fact('Provider preferences', String(rules.filter(rule => rule.mode === 'preferred').length))],
            detailsLabel: 'All eligibility rules and applicant-facing summary',
            details: [fact('Applicant-facing eligibility summary', text(eligibilitySummary))],
        },
        application: {
            facts: [fact('Pre-screening method', optionLabel(form.applicationMode, [['online', 'Portal review'], ['onsite', 'Portal + in-person'], ['provider_review', 'Profile review only']])),
                fact('Formal application method', optionLabel(form.handoffMode, [['onsite', 'In person'], ['online', 'Official link'], ['provider_contact', 'Wait for provider contact']])),
                fact('Pre-screening files', form.applicationMode === 'provider_review' ? 'No uploads required' : `${requirements.length} required / ${optional.length} optional`),
                fact('After qualification', `${qualified.length} document requirement${qualified.length === 1 ? '' : 's'}`),
                fact('Formal application deadline', programReviewDate(form.handoffDeadline, 'Not specified'))],
            detailsLabel: 'Document lists and formal application instructions',
            details: [
                ...(form.applicationMode !== 'provider_review' ? [{ label: 'Required pre-screening files', items: requirements }, ...(optional.length ? [{ label: 'Optional files', items: optional }] : [])] : []),
                { label: 'After-qualification documents', items: qualified }, ...handoff,
            ],
        },
        selection: {
            facts: [fact('Stage order', (form.selectionStages ?? []).map(key => optionLabel(key, catalogs.stages)).join(' > ') || 'Not set', true),
                fact('DSS scoring basis', weights, true),
                fact('Recipient commitment', optionLabel(agreement.commitment_type, [
                    ['provider_briefing', 'Not selected'], ['none', 'No ongoing recipient commitment'], ['renewal', 'Enrollment or renewal requirements'],
                    ['service', 'Service or community commitment'], ['activities', 'Required program activities'], ['reporting', 'Progress or utilization reports'], ['custom', 'Custom commitment'],
                ]), true)],
            detailsLabel: 'Recipient agreement and release conditions',
            details: [fact('Conditions for receiving benefits', text(agreement.release_conditions)),
                ...(ongoing ? [fact('Recipient responsibilities', text(agreement.responsibilities)), fact('Required evidence', text(agreement.required_evidence)),
                    fact('Commitment duration', text(agreement.duration)), fact('If requirements are not met', text(agreement.noncompliance_consequence)),
                    fact('Exception or withdrawal process', text(agreement.exit_or_exception_process))] : [])],
            note: scoring.stagesDiffer ? 'The chosen stages differ from the fixed intake. Return to Selection to review this before submitting.' : '',
        },
    };
}
