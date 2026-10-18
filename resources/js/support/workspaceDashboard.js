export const DASHBOARD_LIMITS = Object.freeze({
    metrics: 4,
    sections: 3,
    sectionItems: 5,
});

const allowedTones = new Set(['neutral', 'attention', 'positive']);

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
        })),
        sections: sections.slice(0, DASHBOARD_LIMITS.sections).map((section, index) => ({
            key: cleanText(section?.key, `section-${index + 1}`),
            eyebrow: cleanText(section?.eyebrow),
            title: cleanText(section?.title, 'Work items'),
            description: cleanText(section?.description),
            empty_message: cleanText(section?.empty_message, 'Nothing needs attention here.'),
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
