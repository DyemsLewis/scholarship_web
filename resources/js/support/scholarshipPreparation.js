const stepKeys = ['profile', 'eligibility', 'requirements', 'submit'];

export function preparationSteps(preparation = {}) {
    const steps = Array.isArray(preparation?.steps) ? preparation.steps : [];
    return stepKeys.map((key) => steps.find((step) => step?.key === key)).filter(Boolean);
}

export function preparationAction(action, { preview = false, canStart = false } = {}) {
    if (!action || action.disabled || action.kind === 'disabled') return null;
    if (action.kind === 'save') return preview ? null : { ...action, href: null };
    const href = typeof action.href === 'string' ? action.href : '';
    if (action.kind !== 'link' || !/^\/(?!\/)/.test(href) || /[\s\\]/.test(href)) return null;
    if (preview && (!['eligibility', 'application'].includes(action.section)
        || !/^\/provider\/previews\/programs\/\d+\/(eligibility|application)(?:[?#]|$)/.test(href))) return null;
    if (!preview && /^\/dashboard\/applications\/start(?:\/|[?#]|$)/.test(href) && !canStart) return null;
    return action;
}

export function preparationRequirementSummary(details = {}, preview = false) {
    const count = (value) => Number.isSafeInteger(value) && value >= 0 ? value : null;
    const required = count(details.required_file_count);
    const saved = count(details.saved_file_count);
    const questions = count(details.question_count);
    const requiredQuestions = count(details.required_question_count);
    const parts = [];
    if (required > 0) {
        parts.push(preview || saved === null
            ? `${required} required file${required === 1 ? '' : 's'}`
            : `${Math.min(saved, required)} of ${required} required files saved`);
    }
    if (questions > 0) {
        parts.push(`${questions} question${questions === 1 ? '' : 's'} in the form${requiredQuestions > 0 ? ` (${Math.min(requiredQuestions, questions)} required)` : ' (optional)'}`);
    }
    return parts.join(' | ');
}
