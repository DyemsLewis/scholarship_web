export function programDraftSnapshot(form, coverSource = 'default', coverKey = '') {
    const { termsAccepted, ...fields } = form;
    return JSON.stringify({ fields, coverSource, coverKey: coverSource === 'system' ? coverKey : '' }, (_key, value) => (
        typeof value === 'number' ? String(value) : value
    ));
}

export function programSaveLabel({ saving, dirty, failed, saved }) {
    if (saving) return 'Saving changes...';
    if (dirty) return failed ? 'Changes not saved' : 'Unsaved changes';
    return saved ? 'All changes saved' : 'Not saved yet';
}

export function shouldGuardProgramLink(event, currentUrl) {
    const link = event.target?.closest?.('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
        || link.hasAttribute('download') || (link.target && link.target !== '_self')) return false;
    const next = new URL(link.href, currentUrl);
    const current = new URL(currentUrl);
    return ['http:', 'https:'].includes(next.protocol)
        && `${next.origin}${next.pathname}${next.search}` !== `${current.origin}${current.pathname}${current.search}`;
}
