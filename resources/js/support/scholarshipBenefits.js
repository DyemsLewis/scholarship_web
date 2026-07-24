export function normalizeScholarshipBenefits(scholarship = {}) {
    if (Array.isArray(scholarship.benefits) && scholarship.benefits.length) {
        return scholarship.benefits.map((benefit) => ({
            type: benefit.type,
            title: benefit.title ?? benefit.type_label ?? '',
            amount: benefit.amount ?? '',
            coverage: benefit.coverage ?? '',
            frequency: benefit.frequency ?? 'one_time',
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
            description: '',
        }];
    }

    return [];
}

export function cashGrantAmount(benefits = []) {
    return benefits.find((benefit) => benefit.type === 'cash_grant')?.amount ?? '';
}
