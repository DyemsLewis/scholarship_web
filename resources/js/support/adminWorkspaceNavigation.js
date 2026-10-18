const sharedProfileLink = {
    key: 'profile',
    href: '/admin/profile',
    label: 'My profile',
    icon: 'fa-solid fa-id-badge',
    exact: true,
};

export const adminWorkspaceDefinitions = {
    super_admin: {
        title: 'Super Admin',
        subtitle: 'Governance Workspace',
        icon: 'fa-solid fa-shield-halved',
        homeHref: '/admin',
        logoutMessage: 'You will need to sign in again to continue managing the platform.',
        navLinks: [
            { key: 'dashboard', href: '/admin', label: 'Governance overview', icon: 'fa-solid fa-gauge-high', exact: true },
            { key: 'admin-team', href: '/admin/governance/team', label: 'Admin team', icon: 'fa-solid fa-user-shield', exact: true, activePaths: ['/admin/accounts'] },
            { key: 'platform-health', href: '/admin/governance/platform', label: 'Platform health', icon: 'fa-solid fa-heart-pulse', exact: true },
            sharedProfileLink,
        ],
    },
    account_manager: {
        title: 'Account Manager',
        subtitle: 'Access Workspace',
        icon: 'fa-solid fa-user-shield',
        homeHref: '/admin/workspaces/accounts',
        logoutMessage: 'You will need to sign in again to continue managing account access.',
        navLinks: [
            { key: 'accounts-dashboard', href: '/admin/workspaces/accounts', label: 'Account dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
            { key: 'accounts', href: '/admin/workspaces/accounts/directory', label: 'Account directory', icon: 'fa-solid fa-address-book', exact: true },
            { key: 'attention', href: '/admin/workspaces/accounts/attention', label: 'Needs attention', icon: 'fa-solid fa-triangle-exclamation', exact: true },
            { key: 'create-account', href: '/admin/workspaces/accounts/create', label: 'Create account', icon: 'fa-solid fa-user-plus', exact: true },
            sharedProfileLink,
        ],
    },
    review_officer: {
        title: 'Review Officer',
        subtitle: 'Verification Workspace',
        icon: 'fa-solid fa-clipboard-check',
        homeHref: '/admin/workspaces/reviews',
        logoutMessage: 'You will need to sign in again to continue verification work.',
        navLinks: [
            { key: 'review-overview', href: '/admin/workspaces/reviews', label: 'Review dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
            { key: 'providers', href: '/admin/workspaces/reviews/providers', label: 'Provider verification', icon: 'fa-solid fa-building-shield' },
            { key: 'programs', href: '/admin/workspaces/reviews/programs', label: 'Program review', icon: 'fa-solid fa-graduation-cap' },
            { key: 'applicants', href: '/admin/workspaces/reviews/applicants', label: 'Applicant verification', icon: 'fa-solid fa-user-check' },
            { key: 'selection-activities', href: '/admin/workspaces/reviews/selection-activities', label: 'Selection activities', icon: 'fa-solid fa-calendar-check' },
            { key: 'benefits', href: '/admin/workspaces/reviews/benefits', label: 'Benefit evidence', icon: 'fa-solid fa-receipt' },
            { key: 'monitoring', href: '/admin/workspaces/reviews/monitoring', label: 'Monitoring oversight', icon: 'fa-solid fa-chart-line' },
            sharedProfileLink,
        ],
    },
    support_officer: {
        title: 'Support Officer',
        subtitle: 'Resolution Workspace',
        icon: 'fa-solid fa-headset',
        homeHref: '/admin/workspaces/support',
        logoutMessage: 'You will need to sign in again to continue resolving support cases.',
        navLinks: [
            { key: 'support-overview', href: '/admin/workspaces/support', label: 'Support dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
            { key: 'support-inbox', href: '/admin/workspaces/support/needs-response', label: 'Needs response', icon: 'fa-solid fa-inbox', exact: true },
            { key: 'support-waiting', href: '/admin/workspaces/support/waiting', label: 'Waiting for provider', icon: 'fa-solid fa-clock', exact: true },
            { key: 'support-resolved', href: '/admin/workspaces/support/resolved', label: 'Resolved cases', icon: 'fa-solid fa-circle-check', exact: true },
            sharedProfileLink,
        ],
    },
    billing_officer: {
        title: 'Billing Officer',
        subtitle: 'Service Workspace',
        icon: 'fa-solid fa-file-invoice-dollar',
        homeHref: '/admin/workspaces/billing',
        logoutMessage: 'You will need to sign in again to continue service delivery work.',
        navLinks: [
            { key: 'billing-overview', href: '/admin/workspaces/billing', label: 'Service dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
            { key: 'billing-ready', href: '/admin/workspaces/billing/queues/ready', label: 'Ready to start', icon: 'fa-solid fa-play', exact: true },
            { key: 'billing-active', href: '/admin/workspaces/billing/queues/active', label: 'Active delivery', icon: 'fa-solid fa-list-check', exact: true },
            { key: 'billing-waiting', href: '/admin/workspaces/billing/queues/waiting', label: 'Waiting on provider', icon: 'fa-solid fa-clock', exact: true },
            { key: 'billing-completed', href: '/admin/workspaces/billing/queues/completed', label: 'Completed requests', icon: 'fa-solid fa-circle-check', exact: true },
            sharedProfileLink,
        ],
    },
    finance_officer: {
        title: 'Finance Officer',
        subtitle: 'Finance Workspace',
        icon: 'fa-solid fa-chart-line',
        homeHref: '/admin/workspaces/finance',
        logoutMessage: 'You will need to sign in again to continue financial oversight.',
        navLinks: [
            { key: 'finance', href: '/admin/workspaces/finance', label: 'Finance dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
            { key: 'payments', href: '/admin/workspaces/finance/payments', label: 'Confirmed payments', icon: 'fa-solid fa-receipt', exact: false, activePaths: ['/admin/workspaces/finance/receipts'] },
            { key: 'exceptions', href: '/admin/workspaces/finance/exceptions', label: 'Payment exceptions', icon: 'fa-solid fa-triangle-exclamation', exact: true },
            sharedProfileLink,
        ],
    },
    records_officer: {
        title: 'Records Officer',
        subtitle: 'Records Workspace',
        icon: 'fa-solid fa-box-archive',
        homeHref: '/admin/workspaces/records',
        logoutMessage: 'You will need to sign in again to continue records work.',
        navLinks: [
            { key: 'records-dashboard', href: '/admin/workspaces/records', label: 'Records dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
            { key: 'activity', href: '/admin/workspaces/records/activity', label: 'Activity records', icon: 'fa-solid fa-clock-rotate-left', exact: false },
            { key: 'exports', href: '/admin/workspaces/records/exports', label: 'Data exports', icon: 'fa-solid fa-file-export', exact: true },
            sharedProfileLink,
        ],
    },
    portal_manager: {
        title: 'Portal Manager',
        subtitle: 'Operations Workspace',
        icon: 'fa-solid fa-shield-halved',
        homeHref: '/admin/workspaces/portal',
        logoutMessage: 'You will need to sign in again to continue portal operations.',
        navLinks: [
            { key: 'overview', href: '/admin/workspaces/portal', label: 'Operations overview', icon: 'fa-solid fa-gauge-high', exact: true },
            {
                key: 'operations',
                href: '/admin/workspaces/portal/accounts',
                label: 'Operations',
                icon: 'fa-solid fa-list-check',
                activePaths: [
                    '/admin/workspaces/portal/accounts',
                    '/admin/workspaces/portal/reviews',
                    '/admin/workspaces/portal/support',
                    '/admin/workspaces/portal/billing',
                ],
                children: [
                    { key: 'accounts', href: '/admin/workspaces/portal/accounts', label: 'Accounts', exact: true },
                    { key: 'reviews', href: '/admin/workspaces/portal/reviews', label: 'Reviews', exact: true },
                    { key: 'support', href: '/admin/workspaces/portal/support', label: 'Support', exact: true },
                    { key: 'billing', href: '/admin/workspaces/portal/billing', label: 'Services', exact: true },
                ],
            },
            {
                key: 'oversight',
                href: '/admin/workspaces/portal/finance',
                label: 'Oversight',
                icon: 'fa-solid fa-chart-line',
                activePaths: ['/admin/workspaces/portal/finance', '/admin/workspaces/portal/records'],
                children: [
                    { key: 'finance', href: '/admin/workspaces/portal/finance', label: 'Finance', exact: true },
                    { key: 'records', href: '/admin/workspaces/portal/records/activity', label: 'Records', exact: false },
                ],
            },
            sharedProfileLink,
        ],
    },
};

function normalizeTitle(value) {
    return String(value ?? '').trim().toLowerCase().replace(/[\s-]+/g, '_');
}

function normalizedPermissions(permissions = []) {
    return [...new Set(permissions)].filter(Boolean).sort();
}

export function resolveAdminWorkspaceKey(user = {}) {
    if (adminWorkspaceDefinitions[user.admin_workspace_key]) {
        return user.admin_workspace_key;
    }

    if (user.has_full_access && !user.is_managed_account) {
        return 'super_admin';
    }

    const titleKey = normalizeTitle(user.account_title);

    if (adminWorkspaceDefinitions[titleKey] && titleKey !== 'super_admin') {
        return titleKey;
    }

    const permissions = normalizedPermissions(user.permissions);
    const permissionRoles = {
        manage_accounts: 'account_manager',
        manage_reviews: 'review_officer',
        manage_reports: 'support_officer',
        manage_billing: 'billing_officer',
        view_finance: 'finance_officer',
        'export_data,view_logs': 'records_officer',
        'export_data,manage_accounts,manage_billing,manage_reports,manage_reviews,view_finance,view_logs': 'portal_manager',
    };

    return permissionRoles[permissions.join(',')] ?? 'super_admin';
}

export function adminWorkspaceNavigation(workspaceKey) {
    return adminWorkspaceDefinitions[workspaceKey] ?? adminWorkspaceDefinitions.super_admin;
}
