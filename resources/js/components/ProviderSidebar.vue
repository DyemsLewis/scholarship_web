<script setup>
import { computed } from 'vue';
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    { section: 'Workspace', href: '/provider', label: 'Dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
    { section: 'Workspace', href: '/provider/programs', label: 'Programs', icon: 'fa-solid fa-graduation-cap' },
    { section: 'Workspace', href: '/provider/applications', label: 'Applicants', icon: 'fa-solid fa-user-check', permission: 'review_applications', requiresApproval: true },
    { section: 'Workspace', href: '/provider/reports', label: 'Reported Issues', icon: 'fa-solid fa-circle-exclamation', permission: 'manage_reports', requiresApproval: true },
    { section: 'Organization', href: '/provider/profile', label: 'Organization Profile', icon: 'fa-solid fa-building-user' },
    { section: 'Organization', href: '/provider/team', label: 'Team & Access', icon: 'fa-solid fa-user-group', permission: 'manage_team' },
    { section: 'Organization', href: '/provider/billing', label: 'Support Services', icon: 'fa-solid fa-headset', permission: 'manage_billing', requiresApproval: true },
];

const visibleNavLinks = computed(() => navLinks.filter((link) => (
    (!link.requiresApproval || window.portalUser?.can_post_scholarships)
        && (
            !link.permission
            || window.portalUser?.has_full_access
            || window.portalUser?.permissions?.includes(link.permission)
        )
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
