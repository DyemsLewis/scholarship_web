import assert from 'node:assert/strict';
import test from 'node:test';

import {
    adminWorkspaceNavigation,
    resolveAdminWorkspaceKey,
} from '../../resources/js/support/adminWorkspaceNavigation.js';

test('each managed admin role resolves to its dedicated workspace', () => {
    const roles = {
        'Account manager': 'account_manager',
        'Review officer': 'review_officer',
        'Support officer': 'support_officer',
        'Billing officer': 'billing_officer',
        'Finance officer': 'finance_officer',
        'Records officer': 'records_officer',
        'Portal manager': 'portal_manager',
    };

    for (const [accountTitle, expectedKey] of Object.entries(roles)) {
        assert.equal(resolveAdminWorkspaceKey({ account_title: accountTitle }), expectedKey);
    }
});

test('backend workspace metadata takes priority over frontend inference', () => {
    assert.equal(resolveAdminWorkspaceKey({
        admin_workspace_key: 'review_officer',
        account_title: 'Support officer',
        permissions: ['manage_reports'],
    }), 'review_officer');
});

test('a primary admin receives the platform control navigation', () => {
    const key = resolveAdminWorkspaceKey({ has_full_access: true, is_managed_account: false });
    const navigation = adminWorkspaceNavigation(key);

    assert.equal(key, 'super_admin');
    assert.equal(navigation.homeHref, '/admin');
    assert.deepEqual(
        navigation.navLinks.map(({ label }) => label),
        ['Governance overview', 'Admin team', 'Platform health', 'My profile'],
    );
});

test('specialist navigation remains focused on assigned work', () => {
    const accounts = adminWorkspaceNavigation('account_manager');
    const reviews = adminWorkspaceNavigation('review_officer');

    assert.deepEqual(
        accounts.navLinks.map(({ href }) => href),
        [
            '/admin/workspaces/accounts',
            '/admin/workspaces/accounts/directory',
            '/admin/workspaces/accounts/attention',
            '/admin/workspaces/accounts/create',
            '/admin/profile',
        ],
    );
    assert.deepEqual(
        reviews.navLinks.map(({ href }) => href),
        [
            '/admin/workspaces/reviews',
            '/admin/workspaces/reviews/providers',
            '/admin/workspaces/reviews/programs',
            '/admin/workspaces/reviews/applicants',
            '/admin/workspaces/reviews/selection-activities',
            '/admin/workspaces/reviews/benefits',
            '/admin/workspaces/reviews/monitoring',
            '/admin/profile',
        ],
    );
    assert.equal(reviews.navLinks.some(({ href }) => href.includes('/billing')), false);
});

test('finance navigation keeps receipt records under confirmed payments', () => {
    const finance = adminWorkspaceNavigation('finance_officer');

    assert.deepEqual(
        finance.navLinks.map(({ href }) => href),
        [
            '/admin/workspaces/finance',
            '/admin/workspaces/finance/payments',
            '/admin/workspaces/finance/exceptions',
            '/admin/profile',
        ],
    );
    assert.deepEqual(finance.navLinks[1].activePaths, ['/admin/workspaces/finance/receipts']);
});

test('records navigation separates audit work from data exports', () => {
    const records = adminWorkspaceNavigation('records_officer');

    assert.deepEqual(
        records.navLinks.map(({ href }) => href),
        [
            '/admin/workspaces/records',
            '/admin/workspaces/records/activity',
            '/admin/workspaces/records/exports',
            '/admin/profile',
        ],
    );
    assert.equal(records.navLinks[0].exact, true);
    assert.equal(records.navLinks[1].exact, false);
    assert.equal(records.navLinks[2].exact, true);
});

test('portal manager navigation exposes oversight areas instead of officer task pages', () => {
    const portal = adminWorkspaceNavigation('portal_manager');

    assert.deepEqual(
        portal.navLinks.map(({ label }) => label),
        ['Operations overview', 'Operations', 'Oversight', 'My profile'],
    );
    assert.deepEqual(
        portal.navLinks[1].children.map(({ label }) => label),
        ['Accounts', 'Reviews', 'Support', 'Services'],
    );
    assert.deepEqual(
        portal.navLinks[2].children.map(({ label }) => label),
        ['Finance', 'Records'],
    );
});
