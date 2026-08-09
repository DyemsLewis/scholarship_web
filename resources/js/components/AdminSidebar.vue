<script setup>
import { computed } from 'vue';
import RoleSidebar from './RoleSidebar.vue';

defineProps({
    active: {
        type: String,
        default: 'dashboard',
    },
});

const navLinks = [
    { section: 'Workspace', key: 'dashboard', href: '/admin', label: 'Dashboard', icon: 'fa-solid fa-gauge-high' },
    { section: 'Workspace', key: 'reviews', href: '/admin/reviews', label: 'Reviews', icon: 'fa-solid fa-clipboard-check', permission: 'manage_reviews' },
    { section: 'Workspace', key: 'reports', href: '/admin/reports', label: 'Reported Issues', icon: 'fa-solid fa-circle-exclamation', permission: 'manage_reports' },
    { section: 'Administration', key: 'users', href: '/admin/manage-users', label: 'Manage Users', icon: 'fa-solid fa-users-gear', permission: 'manage_accounts' },
    { section: 'Administration', key: 'billing', href: '/admin/billing', label: 'Service Payments', icon: 'fa-solid fa-receipt', permission: 'manage_billing' },
    { section: 'Administration', key: 'logs', href: '/admin/logs', label: 'Activity Logs', icon: 'fa-solid fa-clock-rotate-left', permission: 'view_logs' },
    { section: 'Account', key: 'profile', href: '/admin/profile', label: 'Profile', icon: 'fa-solid fa-id-badge' },
];

const visibleNavLinks = computed(() => navLinks.filter((link) => (
    !link.permission
        || window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes(link.permission)
)));
</script>

<template>
    <RoleSidebar
        :active="active"
        title="Admin"
        subtitle="Control Desk"
        icon="fa-solid fa-shield-halved"
        home-href="/admin"
        :nav-links="visibleNavLinks"
        logout-message="You will need to sign in again to continue using the admin portal."
    />
</template>
