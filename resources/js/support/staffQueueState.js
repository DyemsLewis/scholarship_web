const queueOrigin = 'https://staff-queue.invalid';
const providerProgramQueuePath = new RegExp('^/provider/workspaces/(?:'+[
    'reviews/(?:all|assigned|unassigned|returned|history)',
    'selection/(?:active|history|results|setup|unassigned)',
    'decisions/(?:pending|waitlist|recorded)',
    'recipients/(?:agreements|active|declined|closed)',
    'monitoring/(?:review|follow-ups|awaiting|history)',
    'releases/(?:issues|record|upcoming|history|schedule)',
].join('|')+')$');
const returnPaths = {
    accounts: /^\/admin\/workspaces\/accounts\/(directory|attention)$/,
    adminReviews: /^\/admin\/workspaces\/reviews\/(applicants|benefits|monitoring|programs|providers|selection-activities)$/,
    adminSupport: /^\/admin\/workspaces\/support\/(needs-response|waiting|resolved)$/,
    adminBilling: /^\/admin\/workspaces\/billing\/queues\/(ready|active|waiting|completed)$/,
    programs: /^\/provider\/programs(?:\/(drafts|review|published|closed))?$/,
    programWorkspaces: providerProgramQueuePath,
    selection: /^\/provider\/workspaces\/selection\/(active|history|results|setup|unassigned)$/,
    providerSupport: /^\/provider\/workspaces\/support\/(needs-response|waiting|platform|resolved)$/,
    providerBilling: /^\/provider\/workspaces\/billing\/(requests|action|active|waiting|completed)$/,
    team: /^\/provider\/workspaces\/team\/(setup|active|suspended)$/,
};

function positivePage(value) {
    if (!/^\d+$/.test(String(value))) return null;
    const number = Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
}

export function providerProgramQueueLink(href, programId) {
    const url = new URL(href, queueOrigin);
    if (url.origin !== queueOrigin || !providerProgramQueuePath.test(url.pathname)) return href;

    const id = positivePage(programId);
    if (id) url.searchParams.set('program_id', String(id));
    else url.searchParams.delete('program_id');
    url.searchParams.set('page', '1');

    return url.pathname + url.search + url.hash;
}

export function staffQueueLink(href, returnTo, origin = queueOrigin) {
    if (!href || !returnTo) return href;
    try {
        const url = new URL(href, origin);
        if (url.origin !== origin || !/^\/(admin|provider)\//.test(url.pathname)) return href;
        url.searchParams.set('return_to', returnTo);
        return url.pathname + url.search + url.hash;
    } catch { return href; }
}

export function staffQueueReturn(browser, family, fallback = null) {
    const value = new URLSearchParams(browser.location.search).get('return_to');
    if (!value || value.length > 1024 || !/^\/(?![/\\])/.test(value)) return fallback;
    try {
        const url = new URL(value, queueOrigin);
        if (url.origin === queueOrigin && returnPaths[family]?.test(url.pathname)) return url.pathname + url.search;
    } catch { /* Invalid links use the usual queue. */ }
    return fallback;
}

export function createStaffQueueState(browser, fields, { path } = {}) {
    const queuePath = (path ?? browser.portalPathname?.() ?? browser.location.pathname).replace(/\/$/, '');
    const mapping = Array.isArray(fields) ? Object.fromEntries(fields.map((name) => [name, name])) : fields;
    const explicit = new URLSearchParams(browser.location.search);
    const actorId = positivePage(browser.portalUser?.id);
    const key = actorId && /^\/(admin|provider)\//.test(queuePath) ? `scholarship:staff-queue:v1:${actorId}:${queuePath}` : null;
    const hasExplicitQuery = [...Object.keys(mapping), 'page'].some((name) => explicit.has(name));
    let saved = {};
    try {
        if (key && !hasExplicitQuery) saved = JSON.parse(browser.sessionStorage?.getItem(key) ?? '{}') ?? {};
    } catch { /* Storage is optional, including in private browsing. */ }

    function get(name) {
        if (!Object.hasOwn(mapping, name)) return null;
        const value = hasExplicitQuery ? explicit.get(name) : saved[name];
        return typeof value === 'string' && value.length <= 250 ? value : null;
    }

    function remember(params, pagination = {}) {
        if (!key) return;
        const state = { page: positivePage(pagination.current_page) ?? positivePage(params.page) ?? 1 };
        for (const [name, parameter] of Object.entries(mapping)) {
            const value = params[parameter];
            if ((typeof value === 'string' || typeof value === 'number') && String(value).length <= 250) state[name] = String(value);
        }
        try { browser.sessionStorage?.setItem(key, JSON.stringify(state)); } catch { /* The queue can still load. */ }
    }

    async function load(fetchPage, params, isCurrent = () => true) {
        let response = await fetchPage(params);
        const lastPage = positivePage(response.data?.pagination?.last_page);
        if (isCurrent() && lastPage && params.page > lastPage) {
            params = { ...params, page: lastPage };
            response = await fetchPage(params);
        }
        if (isCurrent()) remember(params, response.data?.pagination);
        return response;
    }

    const page = positivePage(hasExplicitQuery ? explicit.get('page') : saved.page) ?? 1;
    return { get, page, load, link: (href) => staffQueueLink(href, queuePath, browser.location.origin) };
}
