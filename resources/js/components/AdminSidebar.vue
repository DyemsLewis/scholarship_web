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
const operationsHref = hasPermission('manage_reports')
    ? '/admin/reports'
    : hasPermission('manage_billing')
        ? '/admin/billing'
        : '/admin/logs';
const activeKey = computed(() => {
    if (['reports', 'billing', 'logs'].includes(props.active)) {
        return 'operations';
    }

    return props.active === 'users' ? 'accounts' : props.active;
});
const navLinks = [
    { key: 'dashboard', href: '/admin', label: 'Dashboard', icon: 'fa-solid fa-gauge-high', section: 'Workspace' },
    { key: 'reviews', href: '/admin/reviews', label: 'Reviews', icon: 'fa-solid fa-clipboard-check', permission: 'manage_reviews', section: 'Administration' },
    { key: 'accounts', href: '/admin/manage-users', label: 'Accounts', icon: 'fa-solid fa-users-gear', permission: 'manage_accounts', section: 'Administration' },
    {
        key: 'operations',
        href: operationsHref,
        label: 'Operations',
        icon: 'fa-solid fa-list-check',
        section: 'Administration',
        anyPermission: ['manage_reports', 'manage_billing', 'view_logs'],
        activePaths: ['/admin/reports', '/admin/billing', '/admin/logs'],
    },
    { key: 'profile', href: '/admin/profile', label: 'Profile', icon: 'fa-solid fa-id-badge', section: 'Account' },
];

const visibleNavLinks = computed(() => navLinks.filter((link) => (
    (!link.permission || hasPermission(link.permission))
        && (!link.anyPermission || link.anyPermission.some(hasPermission))
)));
</script>

<template>
    <RoleSidebar
        :active="activeKey"
        title="Admin"
        subtitle="Control Desk"
        icon="fa-solid fa-shield-halved"
        home-href="/admin"
        :nav-links="visibleNavLinks"
        logout-message="You will need to sign in again to continue using the admin portal."
    />
</template>
