<script setup>
import { computed } from 'vue';
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    { href: '/provider', label: 'Dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
    { href: '/provider/programs', label: 'Programs', icon: 'fa-solid fa-graduation-cap' },
    { href: '/provider/applications', label: 'Applications', icon: 'fa-solid fa-file-circle-check', permission: 'review_applications' },
    { href: '/provider/review', label: 'Review', icon: 'fa-solid fa-clipboard-list', permission: 'review_applications' },
    { href: '/provider/reports', label: 'Reports', icon: 'fa-solid fa-circle-exclamation', permission: 'manage_reports' },
    { href: '/provider/team', label: 'Team', icon: 'fa-solid fa-user-group', permission: 'manage_team' },
    { href: '/provider/profile', label: 'Profile', icon: 'fa-solid fa-building-user' },
];

const visibleNavLinks = computed(() => navLinks.filter((link) => (
    !link.permission
        || window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes(link.permission)
)));
</script>

<template>
    <RoleSidebar
        title="Provider"
        subtitle="Scholarship Desk"
        icon="fa-solid fa-building-columns"
        home-href="/provider"
        :nav-links="visibleNavLinks"
        logout-message="You will need to sign in again to continue using the provider portal."
    />
</template>
