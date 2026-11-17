export async function registerPortalPwa(browser = window) {
    if (!browser.isSecureContext || !browser.navigator?.serviceWorker) return null;
    try {
        const base = new URL(`${(browser.appBaseUrl || browser.location.origin).replace(/\/$/, '')}/`);
        if (base.origin !== browser.location.origin) return null;
        return await browser.navigator.serviceWorker.register(new URL('service-worker.js', base).href, {
            scope: base.href,
            updateViaCache: 'none',
        });
    } catch {
        // Installation is optional; an unavailable worker must never block the portal.
        return null;
    }
}
