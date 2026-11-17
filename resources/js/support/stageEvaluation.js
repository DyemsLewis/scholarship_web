export function stageScorePercentage(score, maximum) {
    if ([score, maximum].some(value => !['number', 'string'].includes(typeof value) || String(value).trim() === '')) return null;
    const earned = Number(score);
    const possible = Number(maximum);
    if (!Number.isFinite(earned) || !Number.isFinite(possible) || earned < 0 || possible < 0.0001
        || earned > possible || possible > 1000000) return null;
    if ([earned, possible].some(value => Math.abs(value * 10000 - Math.round(value * 10000)) > 0.00001)) return null;
    return Math.round((earned / possible) * 10000) / 100;
}

export function stageScoreLabel(score, maximum) {
    const percentage = stageScorePercentage(score, maximum);
    return percentage === null ? '' : `${Number(score)} / ${Number(maximum)} (${percentage}%)`;
}

export function screeningEvaluationStatus(scores = {}, criteria = []) {
    const completed = criteria.filter(criterion => {
        const score = scores[criterion.key];
        return ['number', 'string'].includes(typeof score) && String(score).trim() !== ''
            && Number.isFinite(Number(score)) && Number(score) >= 0 && Number(score) <= 100;
    }).length;
    return { completed, total: criteria.length, valid: completed === criteria.length };
}

export function copyEvaluationDraft(value = {}) {
    return JSON.parse(JSON.stringify(value));
}

export function stageEvaluationForm(stage = {}) {
    return { score: stage.raw_score ?? '', maximum_score: stage.maximum_score ?? 100, note: '',
        criteria_scores: Object.fromEntries((stage.criteria ?? []).map(criterion => {
            const saved = stage.breakdown?.find(row => row.key === criterion.key);
            return [criterion.key, { score: saved?.score ?? '', maximum_score: saved?.maximum_score ?? criterion.maximum_score }];
        })) };
}

export function stageEvaluationResult(form, criteria = [], stage = '') {
    if (!criteria.length) {
        const percentage = stageScorePercentage(form.score, form.maximum_score);
        return percentage === null ? null : { score: Number(form.score), maximum_score: Number(form.maximum_score), percentage };
    }
    if (criteria.reduce((sum, row) => sum + Number(row.weight), 0) !== 100) return null;
    let total = 0;
    for (const criterion of criteria) {
        const row = form.criteria_scores?.[criterion.key];
        const percentage = stageScorePercentage(row?.score, row?.maximum_score);
        if (percentage === null || (stage === 'interview' && Number(row.maximum_score) !== Number(criterion.maximum_score))) return null;
        // Keep full precision until the final weighted total, matching the server.
        total += Number(row.score) / Number(row.maximum_score) * Number(criterion.weight);
    }
    const score = Math.round((total + Number.EPSILON) * 10000) / 10000;
    return { score, maximum_score: 100, percentage: Math.round(score * 100) / 100 };
}

export function stageEvaluationPayload(form, criteria = []) {
    return criteria.length ? { criteria_scores: Object.fromEntries(criteria.map(criterion => [criterion.key, {
        score: Number(form.criteria_scores[criterion.key].score), maximum_score: Number(form.criteria_scores[criterion.key].maximum_score),
    }])) } : { score: Number(form.score), maximum_score: Number(form.maximum_score) };
}
