import assert from 'node:assert/strict';
import test from 'node:test';

import {
    DASHBOARD_LIMITS,
    dashboardToneClass,
    normalizeWorkspaceDashboard,
} from '../../resources/js/support/workspaceDashboard.js';

test('dashboard normalization limits metrics, sections, and section records', () => {
    const dashboard = normalizeWorkspaceDashboard({
        metrics: Array.from({ length: 7 }, (_, index) => ({ label: `Metric ${index}` })),
        sections: Array.from({ length: 5 }, (_, index) => ({
            title: `Section ${index}`,
            items: Array.from({ length: 8 }, (_, item) => ({ id: item })),
        })),
    });

    assert.equal(dashboard.metrics.length, DASHBOARD_LIMITS.metrics);
    assert.equal(dashboard.sections.length, DASHBOARD_LIMITS.sections);
    assert.equal(dashboard.sections[0].items.length, DASHBOARD_LIMITS.sectionItems);
});

test('dashboard normalization supplies stable defaults and rejects partial actions', () => {
    const dashboard = normalizeWorkspaceDashboard({
        workspace: { title: '  Review dashboard  ' },
        primary_action: { label: 'Open queue' },
        metrics: [{ tone: 'decorative' }],
    });

    assert.equal(dashboard.workspace.title, 'Review dashboard');
    assert.equal(dashboard.workspace.eyebrow, 'Workspace');
    assert.equal(dashboard.primary_action, null);
    assert.equal(dashboard.metrics[0].tone, 'neutral');
    assert.equal(dashboard.metrics[0].icon, 'fa-solid fa-chart-simple');
    assert.equal(dashboardToneClass('attention'), 'text-amber-700');
});

test('dashboard normalization assigns restrained icons from workflow keys', () => {
    const dashboard = normalizeWorkspaceDashboard({
        metrics: [{ key: 'pending-decisions', label: 'Pending' }],
        sections: [{ key: 'release-priorities', title: 'Releases' }],
    });

    assert.equal(dashboard.metrics[0].icon, 'fa-solid fa-gavel');
    assert.equal(dashboard.sections[0].icon, 'fa-solid fa-hand-holding-dollar');
});
