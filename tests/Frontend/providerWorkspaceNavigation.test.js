import assert from 'node:assert/strict';
import test from 'node:test';

import { supplementalProviderWorkspaceLinks } from '../../resources/js/support/providerWorkspaceNavigation.js';

test('a specialist with one permission keeps a focused workspace navigation', () => {
    const links = supplementalProviderWorkspaceLinks({
        primaryWorkspaceUrl: '/provider/workspaces/reviews',
        permissions: ['verify_applications'],
        providerApproved: true,
    });

    assert.deepEqual(links, []);
});

test('additional permissions are exposed without duplicating the primary workspace', () => {
    const links = supplementalProviderWorkspaceLinks({
        primaryWorkspaceUrl: '/provider/workspaces/reviews',
        permissions: ['verify_applications', 'manage_selection_activities', 'manage_monitoring'],
        providerApproved: true,
    });

    assert.deepEqual(
        links.map(({ href }) => href),
        ['/provider/workspaces/selection', '/provider/workspaces/monitoring'],
    );
});

test('approval-gated workspaces stay hidden until the provider is approved', () => {
    const pendingLinks = supplementalProviderWorkspaceLinks({
        primaryWorkspaceUrl: '/provider/workspaces/team',
        permissions: ['manage_team', 'manage_reports', 'manage_billing'],
        providerApproved: false,
    });
    const approvedLinks = supplementalProviderWorkspaceLinks({
        primaryWorkspaceUrl: '/provider/workspaces/team',
        permissions: ['manage_team', 'manage_reports', 'manage_billing'],
        providerApproved: true,
    });

    assert.deepEqual(pendingLinks, []);
    assert.deepEqual(
        approvedLinks.map(({ href }) => href),
        ['/provider/workspaces/support', '/provider/workspaces/billing'],
    );
});

test('full-access accounts receive every authorized secondary workspace', () => {
    const links = supplementalProviderWorkspaceLinks({
        primaryWorkspaceUrl: '/provider/workspaces/programs',
        hasFullAccess: true,
        providerApproved: true,
    });

    assert.equal(links.some(({ href }) => href === '/provider/workspaces/programs'), false);
    assert.equal(links.length, 10);
});
