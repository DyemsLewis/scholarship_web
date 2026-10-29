const sections = ['summary', 'eligibility', 'award', 'application', 'provider'];

function scholarshipKey(value) {
    const text = String(value ?? '');
    return /^[1-9]\d*$/.test(text) && Number.isSafeInteger(Number(text)) ? text : '';
}

export function preparationQuery(search = '') {
    const params = new URLSearchParams(search);
    const id = scholarshipKey(params.get('preparing_scholarship'));
    return id ? { preparing_scholarship: id, return_section: sections.includes(params.get('return_section')) ? params.get('return_section') : 'application' } : {};
}

export function withPreparationContext(href, scholarshipId, section = 'application') {
    const id = scholarshipKey(scholarshipId);
    if (!id || typeof href !== 'string' || !/^\/dashboard\/(profile|documents)(?:[?#]|$)/.test(href) || /[\s\\]/.test(href)) return href;
    const url = new URL(href, 'https://scholarship.invalid');
    url.searchParams.set('preparing_scholarship', id);
    url.searchParams.set('return_section', sections.includes(section) ? section : 'application');
    return `${url.pathname}${url.search}${url.hash}`;
}

export function preparationReturn(context) {
    const id = scholarshipKey(context?.scholarship_id);
    if (!id || !sections.includes(context?.return_section) || typeof context?.scholarship_title !== 'string') return null;
    const href = `/dashboard/scholarships/${id}${context.return_section === 'summary' ? '' : `/${context.return_section}`}`;
    return context.return_href === href ? { ...context, href } : null;
}

export function hasUnsentApplicationAnswers(answers = {}, notes = '', accepted = false) {
    return accepted === true || String(notes ?? '').trim() !== ''
        || Object.values(answers ?? {}).some((value) => String(value ?? '').trim() !== '');
}
