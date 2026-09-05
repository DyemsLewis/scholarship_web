<script setup>
import { computed } from 'vue';
import RoleSidebar from './RoleSidebar.vue';

const hasPermission = (permission) => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes(permission),
);
const supportHref = hasPermission('manage_billing') ? '/provider/billing' : '/provider/reports';
const navLinks = [
    { href: '/provider', label: 'Overview', icon: 'fa-solid fa-gauge-high', exact: true, section: 'Workspace' },
    {
        href: '/provider/profile',
        label: 'Organization setup',
        icon: 'fa-solid fa-building-user',
        step: 1,
        section: 'Provider workflow',
        activePaths: ['/provider/profile', '/provider/team'],
    },
    {
        href: '/provider/programs',
        label: 'Programs',
        icon: 'fa-solid fa-graduation-cap',
        step: 2,
        section: 'Provider workflow',
    },
    {
        href: '/provider/applications',
        label: 'Applicant workflow',
        icon: 'fa-solid fa-user-check',
        step: 3,
        section: 'Provider workflow',
        permission: 'review_applications',
        requiresApproval: true,
    },
    {
        href: supportHref,
        label: 'Support',
        icon: 'fa-solid fa-headset',
        section: 'Support tools',
        anyPermission: ['manage_billing', 'manage_reports'],
        requiresApproval: true,
        activePaths: ['/provider/billing', '/provider/reports'],
    },
];

const visibleNavLinks = computed(() => navLinks.filter((link) => (
    (!link.requiresApproval || window.portalUser?.can_post_scholarships)
        && (
            (!link.permission || hasPermission(link.permission))
            && (!link.anyPermission || link.anyPermission.some(hasPermission))
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
