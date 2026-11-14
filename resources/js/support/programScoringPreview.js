const labels = { screening: 'Pre-screening', exam: 'Exam', interview: 'Interview' };
const optionalKey = (stages) => ['exam', 'interview'].filter(key => stages.includes(key)).join('+') || 'screening';
const percent = (value) => Number(value.toFixed(2));

export function programScoringPreview(presets, stages, fixedPolicy = null, applicationsCount = 0) {
    const fixed = Boolean(fixedPolicy);
    const legacy = !fixed && Number(applicationsCount) > 0;
    const policy = legacy ? null : (fixedPolicy ?? presets?.[optionalKey(stages)]);
    const criteria = policy?.screening_criteria ?? [];
    const subtotal = criteria.reduce((sum, criterion) => sum + Number(criterion.weight), 0);
    const screeningBasis = subtotal > 0
        ? criteria.map(criterion => `${criterion.label} ${percent(Number(criterion.weight) / subtotal * 100)}%`).join(' + ') + ' of the screening score.'
        : 'Eligibility and document review only.';
    const rows = Object.entries(labels)
        .filter(([key]) => Number(policy?.weights?.[key]) > 0)
        .map(([key, label]) => ({
            key, label, weight: Number(policy.weights[key]),
            basis: key === 'screening' ? screeningBasis : 'Recorded evaluation converted to a score out of 100.',
        }));

    return {
        available: legacy || Boolean(policy), fixed, legacy, rows,
        total: rows.reduce((sum, row) => sum + row.weight, 0),
        formula: rows.map(row => `${row.label} score x ${row.weight}%`).join(' + '),
        stagesDiffer: fixed && optionalKey(policy.stages ?? []) !== optionalKey(stages),
    };
}
