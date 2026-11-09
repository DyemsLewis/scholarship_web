import { labelFromKey } from './display.js';

const benefitAmountFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', trailingZeroDisplay: 'stripIfInteger' });

export function scholarshipBenefitFacts(benefit) {
    const rawAmount = benefit.amount;
    const amount = (typeof rawAmount === 'number' || (typeof rawAmount === 'string' && rawAmount.trim())) && Number.isFinite(Number(rawAmount))
        ? benefitAmountFormat.format(Number(rawAmount)) : '';
    const label = (value) => /^[a-z][a-z0-9_]*_[a-z0-9_]+$/.test(value ?? '') ? labelFromKey(value) : value;
    return [
        { label: 'Amount', value: amount },
        { label: 'Coverage', value: label(benefit.coverage_label) },
        { label: 'Frequency', value: label(benefit.frequency_label) },
        { label: 'Duration', value: benefit.duration },
    ].filter((fact) => fact.value);
}

export function normalizeScholarshipBenefits(scholarship = {}) {
    if (Array.isArray(scholarship.benefits) && scholarship.benefits.length) {
        return scholarship.benefits.map((benefit) => ({
            type: benefit.type,
            title: benefit.title ?? benefit.type_label ?? '',
            amount: benefit.amount ?? '',
            coverage: benefit.coverage ?? '',
            frequency: benefit.frequency ?? 'one_time',
            duration: benefit.duration ?? '',
            description: benefit.description ?? '',
        }));
    }

    if (scholarship.award_amount !== null && scholarship.award_amount !== undefined && scholarship.award_amount !== '') {
        return [{
            type: 'cash_grant',
            title: 'Cash grant',
            amount: scholarship.award_amount,
            coverage: '',
            frequency: 'one_time',
            duration: '',
            description: '',
        }];
    }

    return [];
}

export function cashGrantAmount(benefits = []) {
    return benefits.find((benefit) => benefit.type === 'cash_grant')?.amount ?? '';
}
