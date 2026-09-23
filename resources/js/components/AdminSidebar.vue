<script setup>
import { computed } from 'vue';
import RoleSidebar from './RoleSidebar.vue';

const props = defineProps({
    active: {
        type: String,
        default: 'dashboard',
    },
});

const hasPermission = (permission) => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes(permission),
);
const operationLinks = [
    { href: '/admin/reports', label: 'Reported issues', permission: 'manage_reports', exact: true },
    { href: '/admin/billing', label: 'Service requests', permission: 'manage_billing', exact: true },
    { href: '/admin/finance', label: 'Platform finance', permission: 'view_finance', exact: true },
    { href: '/admin/logs', label: 'Activity records', permission: 'view_logs', exact: true },
].filter((link) => hasPermission(link.permission));
const navLinks = [
    { href: '/admin', label: 'Dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
    {
        href: '/admin/reviews',
        label: 'Reviews',
        icon: 'fa-solid fa-clipboard-check',
        permission: 'manage_reviews',
        activePaths: ['/admin/providers', '/admin/applicants', '/admin/scholarships'],
        children: [
            { href: '/admin/reviews', label: 'Provider reviews', exact: true, queryless: true },
            { href: '/admin/reviews?type=programs', label: 'Program reviews', exact: true },
            { href: '/admin/reviews?type=applicants', label: 'Applicant reviews', exact: true },
            { href: '/admin/reviews?type=benefits', label: 'Benefit evidence', exact: true },
        ],
    },
    {
        href: '/admin/manage-users',
        label: 'Accounts',
        icon: 'fa-solid fa-users-gear',
        permission: 'manage_accounts',
        activePaths: ['/admin/accounts'],
        children: [
            { href: '/admin/manage-users', label: 'Account directory', exact: true },
            { href: '/admin/accounts/create', label: 'Create account', exact: true },
        ],
    },
    ...(operationLinks.length ? [{
        href: operationLinks[0].href,
        label: 'Operations',
        icon: 'fa-solid fa-list-check',
        activePaths: ['/admin/reports', '/admin/billing', '/admin/finance', '/admin/logs'],
        children: operationLinks,
    }] : []),
    { href: '/admin/profile', label: 'Profile', icon: 'fa-solid fa-id-badge', exact: true },
];

const visibleNavLinks = computed(() => navLinks.filter((link) => (
    (!link.permission || hasPermission(link.permission))
        && (!link.anyPermission || link.anyPermission.some(hasPermission))
)));
</script>

<template>
    <RoleSidebar
        title="Admin"
        subtitle="Control Desk"
        icon="fa-solid fa-shield-halved"
        home-href="/admin"
        :nav-links="visibleNavLinks"
        logout-message="You will need to sign in again to continue using the admin portal."
    />
</template>
