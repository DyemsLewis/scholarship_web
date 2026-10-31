export function installOpaqueRecordNavigation(browser = window, page = document) {
    let prefix = page.querySelector('meta[name="portal-route-prefix"]')?.content;
    let opaquePrefix = page.querySelector('meta[name="portal-route-opaque-prefix"]')?.content;
    const matches = (path, base) => path === base || path.startsWith(`${base}/`);
    browser.portalPathname = () => prefix && opaquePrefix && matches(browser.location.pathname, opaquePrefix)
        ? prefix + browser.location.pathname.slice(opaquePrefix.length)
        : browser.location.pathname;
    browser.portalSetRecordContext = (context) => {
        if (!context?.prefix?.startsWith('/') || !context?.opaque_prefix?.startsWith('/')
            || context.prefix.startsWith('//') || context.opaque_prefix.startsWith('//')) return;
        prefix = context.prefix;
        opaquePrefix = context.opaque_prefix;
    };

    const opaqueUrl = (value) => {
        const url = new URL(value, browser.location.href);
        if (!prefix || !opaquePrefix || url.origin !== browser.location.origin || !matches(url.pathname, prefix)) return value;
        url.pathname = opaquePrefix + url.pathname.slice(prefix.length);
        return `${url.pathname}${url.search}${url.hash}`;
    };
    for (const name of ['pushState', 'replaceState']) {
        const original = browser.history[name].bind(browser.history);
        browser.history[name] = (state, unused, url) => original(state, unused, url == null ? url : opaqueUrl(url));
    }
    // Non-navigation clients can render the legacy URL directly; keep the browser canonical too.
    if (prefix && opaquePrefix && matches(browser.location.pathname, prefix)) {
        browser.history.replaceState(browser.history.state, '', opaqueUrl(browser.location.href));
    }
}
