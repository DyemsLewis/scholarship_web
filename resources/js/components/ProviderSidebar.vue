<script setup>
import { computed } from 'vue';
import RoleSidebar from './RoleSidebar.vue';

const hasPermission = (permission) => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes(permission),
);
const canManagePrograms = hasPermission('manage_programs');
const canReviewApplications = hasPermission('review_applications') && window.portalUser?.can_post_scholarships;
const canManageProfile = hasPermission('manage_profile');
const canManageTeam = hasPermission('manage_team');
const canManageBilling = hasPermission('manage_billing') && window.portalUser?.can_post_scholarships;
const canManageReports = hasPermission('manage_reports') && window.portalUser?.can_post_scholarships;
const navLinks = computed(() => [
    { href: '/provider', label: 'Dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
    ...(canManagePrograms || hasPermission('review_applications') ? [{
        href: '/provider/programs',
        label: 'Programs',
        icon: 'fa-solid fa-graduation-cap',
        children: [
            { href: '/provider/programs', label: 'All programs', exact: true },
            ...(canManagePrograms ? [{ href: '/provider/programs/create', label: 'Create program', exact: true }] : []),
        ],
    }] : []),
    ...(canReviewApplications ? [{
        href: '/provider/applications/review',
        label: 'All applications',
        icon: 'fa-solid fa-user-check',
        children: [
            { href: '/provider/applications/review', label: 'Applicant review', exact: true },
            { href: '/provider/applications/activities', label: 'Activity schedules', exact: true },
            { href: '/provider/applications/results', label: 'Record results', exact: true },
            { href: '/provider/applications/decisions', label: 'Final decisions', exact: true },
        ],
    }] : []),
    ...(canReviewApplications ? [{
        href: '/provider/applications/recipients',
        label: 'All recipients',
        icon: 'fa-solid fa-award',
        children: [
            { href: '/provider/applications/recipients', label: 'Selected recipients', exact: true },
            { href: '/provider/applications/waitlist', label: 'Waitlist', exact: true },
            { href: '/provider/monitoring', label: 'Recipient monitoring', exact: true },
        ],
    }] : []),
    {
        href: '/provider/profile/details',
        label: 'Organization',
        icon: 'fa-solid fa-building-user',
        activePaths: ['/provider/profile', '/provider/team'],
        children: [
            { href: '/provider/profile/details', label: canManageProfile ? 'Provider details' : 'View provider details', exact: true },
            { href: '/provider/profile/verification', label: 'Verification', exact: true },
            { href: '/provider/profile/representative', label: 'Representative account', exact: true },
            ...(canManageTeam ? [{ href: '/provider/team', label: 'Team and access', exact: true }] : []),
        ],
    },
    ...((canManageBilling || canManageReports) ? [{
        href: canManageBilling ? '/provider/billing' : '/provider/reports',
        label: 'Support',
        icon: 'fa-solid fa-headset',
        children: [
            ...(canManageBilling ? [
                { href: '/provider/billing', label: 'Service options', exact: true },
                { href: '/provider/billing/requests', label: 'Your requests', exact: true },
            ] : []),
            ...(canManageReports ? [{ href: '/provider/reports', label: 'Reports', exact: true }] : []),
        ],
    }] : []),
]);
</script>

<template>
    <RoleSidebar
        title="Provider"
        subtitle="Scholarship Desk"
        icon="fa-solid fa-building-columns"
        home-href="/provider"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue using the provider portal."
        mobile-collapsible
    />
</template>
