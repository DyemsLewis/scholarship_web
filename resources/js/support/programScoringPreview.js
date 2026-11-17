const labels = { screening: 'Pre-screening', exam: 'Exam', interview: 'Interview' };
const optionalKey = (stages) => ['exam', 'interview'].filter(key => stages.includes(key)).join('+') || 'screening';
const percent = (value) => Number(value.toFixed(2));

export function programCriterionLabels(presets, policy = null) {
    return Object.fromEntries(['exam', 'interview'].map(stage => {
        const defaults = presets?.['exam+interview']?.stage_criteria?.[stage] ?? [];
        const saved = policy?.stage_criteria?.[stage] ?? [];
        return [stage, Object.fromEntries(defaults.map(criterion => [criterion.key,
            saved.find(row => row.key === criterion.key)?.label ?? policy?.stage_criterion_labels?.[stage]?.[criterion.key] ?? criterion.label]))];
    }));
}

export function programCriterionLabelErrors(labels) {
    const errors = {};
    for (const [stage, criteria] of Object.entries(labels ?? {})) {
        const used = new Set();
        for (const [key, value] of Object.entries(criteria)) {
            const label = typeof value === 'string' ? value.trim() : '';
            const comparison = label.replace(/\s+/gu, ' ').toLowerCase();
            if (!label || Array.from(label).length > 80 || /\p{C}/u.test(label) || used.has(comparison)) {
                errors[`stage_criterion_labels.${stage}.${key}`] = 'Use a different, non-empty label for each criterion in this stage (up to 80 characters).';
            }
            used.add(comparison);
        }
    }
    return errors;
}

export function programScoringPreview(presets, stages, fixedPolicy = null, applicationsCount = 0, examMethod = null, criterionLabels = null) {
    const fixed = Boolean(fixedPolicy) && (Number(applicationsCount) > 0 || (examMethod === null && criterionLabels === null));
    const legacy = !fixed && Number(applicationsCount) > 0;
    const base = fixed ? fixedPolicy : presets?.[optionalKey(stages)];
    let policy = legacy ? null : (!fixed && base && examMethod === 'total'
        ? { ...base, exam_scoring_method: 'total', stage_criteria: { ...base.stage_criteria, exam: [] } } : base);
    if (!fixed && policy && criterionLabels) {
        policy = { ...policy, stage_criteria: Object.fromEntries(Object.entries(policy.stage_criteria ?? {}).map(([stage, criteria]) => [stage,
            criteria.map(criterion => ({ ...criterion, label: criterionLabels[stage]?.[criterion.key]?.trim() || criterion.label })),
        ])) };
    }
    const criteria = policy?.screening_criteria ?? [];
    const subtotal = criteria.reduce((sum, criterion) => sum + Number(criterion.weight), 0);
    const screeningBasis = subtotal > 0
        ? criteria.map(criterion => `${criterion.label} ${percent(Number(criterion.weight) / subtotal * 100)}%`).join(' + ') + ' of the screening score.'
        : 'Eligibility and document review only.';
    const rows = Object.entries(labels)
        .filter(([key]) => Number(policy?.weights?.[key]) > 0)
        .map(([key, label]) => ({
            key, label, weight: Number(policy.weights[key]),
            criteria: policy?.stage_criteria?.[key] ?? [],
            basis: key === 'screening' ? screeningBasis : (policy?.stage_criteria?.[key]?.length
                ? policy.stage_criteria[key].map(row => `${row.label} ${row.weight}%`).join(' + ') + ' of this stage.'
                : (key === 'exam' && policy?.exam_scoring_method === 'total' ? 'Total exam score divided by its maximum, converted to a score out of 100.' : 'Recorded evaluation converted to a score out of 100.')),
        }));

    return {
        available: legacy || Boolean(policy), fixed, legacy, rows,
        examMethod: policy?.weights?.exam ? (policy.exam_scoring_method ?? (policy.stage_criteria?.exam?.length ? 'criteria' : 'total')) : null,
        total: rows.reduce((sum, row) => sum + row.weight, 0),
        formula: rows.map(row => `${row.label} score x ${row.weight}%`).join(' + '),
        stagesDiffer: fixed && optionalKey(policy.stages ?? []) !== optionalKey(stages),
    };
}
