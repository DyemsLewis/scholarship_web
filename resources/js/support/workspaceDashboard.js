export const DASHBOARD_LIMITS = Object.freeze({
    metrics: 4,
    sections: 3,
    sectionItems: 5,
});

const allowedTones = new Set(['neutral', 'attention', 'positive']);

const dashboardIcons = [
    [['attention', 'needs', 'priority', 'issue', 'returned', 'setup'], 'fa-solid fa-triangle-exclamation'],
    [['review', 'assigned', 'unassigned', 'application'], 'fa-solid fa-file-circle-check'],
    [['selection', 'session', 'activity'], 'fa-solid fa-calendar-check'],
    [['decision', 'pending', 'waitlist', 'selected', 'capacity'], 'fa-solid fa-gavel'],
    [['recipient', 'agreement', 'onboarding'], 'fa-solid fa-award'],
    [['monitoring', 'check-in', 'follow-up', 'awaiting'], 'fa-solid fa-chart-line'],
    [['release', 'distribution'], 'fa-solid fa-hand-holding-dollar'],
    [['support', 'report'], 'fa-solid fa-headset'],
    [['billing', 'request', 'payment', 'service'], 'fa-solid fa-receipt'],
    [['team', 'account', 'staff'], 'fa-solid fa-users-gear'],
    [['profile', 'verification', 'identity'], 'fa-solid fa-building-shield'],
    [['program'], 'fa-solid fa-graduation-cap'],
    [['history', 'recent', 'closed', 'completed', 'recorded', 'updated'], 'fa-solid fa-clock-rotate-left'],
];

function cleanText(value, fallback = '') {
    const text = String(value ?? '').trim();
    return text || fallback;
}

function normalizeAction(action) {
    const label = cleanText(action?.label);
    const url = cleanText(action?.url);

    if (!label || !url) return null;

    return {
        label,
        url,
        icon: cleanText(action?.icon, 'fa-solid fa-arrow-right'),
    };
}

function dashboardIcon(key, fallback) {
    const normalizedKey = cleanText(key).toLowerCase();
    const match = dashboardIcons.find(([terms]) => terms.some((term) => normalizedKey.includes(term)));

    return match?.[1] ?? fallback;
}

export function normalizeWorkspaceDashboard(payload = {}) {
    const workspace = payload.workspace ?? {};
    const metrics = Array.isArray(payload.metrics) ? payload.metrics : [];
    const sections = Array.isArray(payload.sections) ? payload.sections : [];

    return {
        contract_version: Number(payload.contract_version || 1),
        workspace: {
            key: cleanText(workspace.key, 'workspace'),
            eyebrow: cleanText(workspace.eyebrow, 'Workspace'),
            title: cleanText(workspace.title, 'Dashboard'),
            description: cleanText(workspace.description),
            icon: cleanText(workspace.icon, 'fa-solid fa-gauge-high'),
            organization_name: cleanText(workspace.organization_name),
            access_scope: cleanText(workspace.access_scope),
        },
        primary_action: normalizeAction(payload.primary_action),
        metrics: metrics.slice(0, DASHBOARD_LIMITS.metrics).map((metric, index) => ({
            key: cleanText(metric?.key, `metric-${index + 1}`),
            label: cleanText(metric?.label, 'Metric'),
            value: metric?.value ?? 0,
            detail: cleanText(metric?.detail),
            tone: allowedTones.has(metric?.tone) ? metric.tone : 'neutral',
            url: cleanText(metric?.url) || null,
            icon: cleanText(metric?.icon) || dashboardIcon(metric?.key, 'fa-solid fa-chart-simple'),
        })),
        sections: sections.slice(0, DASHBOARD_LIMITS.sections).map((section, index) => ({
            key: cleanText(section?.key, `section-${index + 1}`),
            eyebrow: cleanText(section?.eyebrow),
            title: cleanText(section?.title, 'Work items'),
            description: cleanText(section?.description),
            empty_message: cleanText(section?.empty_message, 'Nothing needs attention here.'),
            icon: cleanText(section?.icon) || dashboardIcon(section?.key, 'fa-solid fa-list-check'),
            action: normalizeAction(section?.action),
            items: (Array.isArray(section?.items) ? section.items : [])
                .slice(0, DASHBOARD_LIMITS.sectionItems),
        })),
    };
}

export function dashboardToneClass(tone) {
    if (tone === 'attention') return 'text-amber-700';
    if (tone === 'positive') return 'text-emerald-700';
    return 'text-slate-950';
}
