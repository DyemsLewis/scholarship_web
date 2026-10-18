const STAFF_WORKSPACE_PATTERN = /^\/(?:provider|admin)(?:\/|$)/;

const FILE_PREVIEW_PATTERNS = [
    /^\/documents\/\d+\/view\/?$/,
    /^\/service-files\/\d+\/view\/?$/,
    /^\/provider\/verification-documents\/\d+\/view\/?$/,
    /^\/provider\/applications\/\d+\/profile-proofs\/\d+\/view\/?$/,
    /^\/provider\/applications\/\d+\/profile-photo\/?$/,
    /^\/provider\/reports\/\d+\/(?:attachment|applicant-photo)\/?$/,
    /^\/provider\/monitoring-adjustment-requests\/\d+\/attachment\/?$/,
    /^\/provider\/monitoring-submissions\/\d+\/view\/?$/,
    /^\/provider\/benefit-release-records\/\d+\/receipt\/?$/,
    /^\/provider\/benefit-receipt-responses\/\d+\/files\/(?:evidence|resolution-proof)\/?$/,
    /^\/provider\/support-decisions\/\d+\/files\/(?:decision-document|applicant-response|resolution-proof)\/?$/,
    /^\/admin\/reports\/\d+\/attachment\/?$/,
    /^\/admin\/applicants\/\d+\/profile-photo\/?$/,
    /^\/admin\/applicant-verification-documents\/\d+\/view\/?$/,
    /^\/admin\/provider-verification-documents\/\d+\/view\/?$/,
    /^\/admin\/workspaces\/reviews\/selection-activities\/evidence\/\d+\/view\/?$/,
    /^\/admin\/monitoring-submissions\/\d+\/view\/?$/,
    /^\/admin\/monitoring-adjustments\/\d+\/attachment\/?$/,
    /^\/admin\/benefit-release-records\/\d+\/receipt\/?$/,
    /^\/admin\/benefit-receipt-responses\/\d+\/files\/(?:recipient-evidence|resolution-proof)\/?$/,
    /^\/admin\/support-decisions\/\d+\/files\/(?:decision-document|applicant-response|resolution-proof)\/?$/,
];

export function isStaffWorkspacePath(pathname) {
    return STAFF_WORKSPACE_PATTERN.test(pathname || '');
}

export function isStaffFilePreviewUrl(rawUrl, currentUrl) {
    try {
        const current = new URL(currentUrl);
        const target = new URL(rawUrl, current);

        if (!isStaffWorkspacePath(current.pathname) || target.origin !== current.origin) {
            return false;
        }

        return FILE_PREVIEW_PATTERNS.some((pattern) => pattern.test(target.pathname));
    } catch {
        return false;
    }
}

export function shouldPreviewStaffFileClick(event, anchor, currentUrl) {
    if (!anchor || anchor.dataset?.filePreview === 'false' || anchor.hasAttribute?.('download')) {
        return false;
    }

    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return false;
    }

    if (anchor.dataset?.filePreview === 'true') {
        try {
            const current = new URL(currentUrl);
            const target = new URL(anchor.href, current);

            return isStaffWorkspacePath(current.pathname) && target.origin === current.origin;
        } catch {
            return false;
        }
    }

    return isStaffFilePreviewUrl(anchor.href, currentUrl);
}
