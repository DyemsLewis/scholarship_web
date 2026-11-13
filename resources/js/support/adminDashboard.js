import { normalizeWorkspaceDashboard } from './workspaceDashboard.js';

export function normalizeAdminDashboard(payload = {}) {
    const priority = payload.priority;
    const text = (value) => String(value ?? '').trim();
    const action = priority?.action;

    return {
        ...normalizeWorkspaceDashboard(payload),
        priority: priority && text(priority.title) && text(priority.reason) ? {
            title: text(priority.title),
            reason: text(priority.reason),
            owner: text(priority.owner),
            timing: text(priority.timing),
            state: ['actionable', 'waiting', 'clear', 'reference'].includes(priority.state) ? priority.state : 'reference',
            action: text(action?.label) && /^\/(?!\/)/.test(text(action?.url))
                ? { label: text(action.label), url: text(action.url) }
                : null,
        } : null,
    };
}
