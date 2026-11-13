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
