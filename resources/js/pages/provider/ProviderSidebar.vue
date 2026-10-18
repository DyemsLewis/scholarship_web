<script setup>
import RoleSidebar from '../../components/RoleSidebar.vue';

const permissions = new Set(window.portalUser?.permissions ?? []);
const navLinks = [
    {
        href: '/provider',
        label: 'Dashboard',
        icon: 'fa-solid fa-gauge-high',
        exact: true,
    },
];

if (permissions.has('manage_programs')) {
    navLinks.push({
        href: '/provider/programs',
        label: 'Programs',
        icon: 'fa-solid fa-graduation-cap',
        key: 'programs',
        children: [
            { href: '/provider/programs', label: 'All programs', exact: true },
            { href: '/provider/programs/create', label: 'Create program', exact: true },
            { href: '/provider/programs/drafts', label: 'Drafts & changes', exact: true },
            { href: '/provider/programs/review', label: 'Under review', exact: true },
            { href: '/provider/programs/published', label: 'Published', exact: true },
            { href: '/provider/programs/closed', label: 'Closed', exact: true },
        ],
    });
}

if (permissions.has('verify_applications')) {
    navLinks.push({
        href: '/provider/workspaces/reviews',
        label: 'Application review',
        icon: 'fa-solid fa-clipboard-check',
        key: 'reviews',
        children: [
            { href: '/provider/workspaces/reviews', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/reviews/assigned', label: 'My reviews', exact: true },
            { href: '/provider/workspaces/reviews/unassigned', label: 'Unassigned', exact: true },
            { href: '/provider/workspaces/reviews/returned', label: 'Returned updates', exact: true },
            { href: '/provider/workspaces/reviews/history', label: 'Review history', exact: true },
        ],
    });
}

if (permissions.has('manage_selection_activities')) {
    navLinks.push({
        href: '/provider/workspaces/selection',
        label: 'Selection activities',
        icon: 'fa-solid fa-calendar-check',
        key: 'selection',
        children: [
            { href: '/provider/workspaces/selection', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/selection/active', label: 'Sessions', exact: true },
            { href: '/provider/workspaces/selection/unassigned', label: 'Unassigned applicants', exact: true },
            { href: '/provider/workspaces/selection/results', label: 'Results to record', exact: true },
            { href: '/provider/workspaces/selection/history', label: 'History', exact: true },
        ],
    });
}

if (permissions.has('record_final_decisions')) {
    navLinks.push({
        href: '/provider/workspaces/decisions',
        label: 'Final decisions',
        icon: 'fa-solid fa-gavel',
        key: 'decisions',
        children: [
            { href: '/provider/workspaces/decisions', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/decisions/pending', label: 'Pending decisions', exact: true },
            { href: '/provider/workspaces/decisions/waitlist', label: 'Waitlist', exact: true },
            { href: '/provider/workspaces/decisions/recorded', label: 'Decision history', exact: true },
        ],
    });
}

if (permissions.has('manage_recipients')) {
    navLinks.push({
        href: '/provider/workspaces/recipients',
        label: 'Recipients',
        icon: 'fa-solid fa-user-shield',
        key: 'recipients',
        children: [
            { href: '/provider/workspaces/recipients', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/recipients/agreements', label: 'Agreement responses', exact: true },
            { href: '/provider/workspaces/recipients/active', label: 'Active recipients', exact: true },
            { href: '/provider/workspaces/recipients/declined', label: 'Declined responses', exact: true },
            { href: '/provider/workspaces/recipients/closed', label: 'Closed recipients', exact: true },
        ],
    });
}

if (permissions.has('manage_monitoring')) {
    navLinks.push({
        href: '/provider/workspaces/monitoring',
        label: 'Monitoring',
        icon: 'fa-solid fa-chart-line',
        key: 'monitoring',
        children: [
            { href: '/provider/workspaces/monitoring', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/monitoring/review', label: 'Needs review', exact: true },
            { href: '/provider/workspaces/monitoring/follow-ups', label: 'Follow-ups', exact: true },
            { href: '/provider/workspaces/monitoring/awaiting', label: 'Awaiting uploads', exact: true },
            { href: '/provider/workspaces/monitoring/history', label: 'Check-in history', exact: true },
        ],
    });
}

if (permissions.has('manage_benefit_releases')) {
    navLinks.push({
        href: '/provider/workspaces/releases',
        label: 'Benefit releases',
        icon: 'fa-solid fa-hand-holding-dollar',
        key: 'releases',
        children: [
            { href: '/provider/workspaces/releases', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/releases/issues', label: 'Reported issues', exact: true },
            { href: '/provider/workspaces/releases/record', label: 'Record distribution', exact: true },
            { href: '/provider/workspaces/releases/upcoming', label: 'Upcoming releases', exact: true },
            { href: '/provider/workspaces/releases/history', label: 'Release history', exact: true },
            { href: '/provider/workspaces/releases/schedule', label: 'Schedule release', exact: true },
        ],
    });
}

if (permissions.has('manage_profile')) {
    navLinks.push({
        href: '/provider/workspaces/organization-profile',
        label: 'Organization profile',
        icon: 'fa-solid fa-building-shield',
        key: 'organization-profile',
        children: [
            { href: '/provider/workspaces/organization-profile', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/organization-profile/readiness', label: 'Profile readiness', exact: true },
            { href: '/provider/workspaces/organization-profile/public', label: 'Public profile', exact: true },
            { href: '/provider/workspaces/organization-profile/contact', label: 'Contact & location', exact: true },
            { href: '/provider/workspaces/organization-profile/verification', label: 'Legal verification', exact: true },
        ],
    });
}

if (permissions.has('manage_team')) {
    navLinks.push({
        href: '/provider/workspaces/team',
        label: 'Team access',
        icon: 'fa-solid fa-users-gear',
        key: 'team',
        children: [
            { href: '/provider/workspaces/team', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/team/setup', label: 'Setup required', exact: true },
            { href: '/provider/workspaces/team/active', label: 'Active accounts', exact: true },
            { href: '/provider/workspaces/team/suspended', label: 'Suspended accounts', exact: true },
            { href: '/provider/team/accounts/create', label: 'Add team member', exact: true },
        ],
    });
}

if (permissions.has('manage_reports')) {
    navLinks.push({
        href: '/provider/workspaces/support',
        label: 'Support cases',
        icon: 'fa-solid fa-headset',
        key: 'support',
        children: [
            { href: '/provider/workspaces/support', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/support/needs-response', label: 'Needs response', exact: true },
            { href: '/provider/workspaces/support/waiting', label: 'Waiting for platform', exact: true },
            { href: '/provider/workspaces/support/platform', label: 'Platform reports', exact: true },
            { href: '/provider/workspaces/support/resolved', label: 'Resolved cases', exact: true },
            { href: '/provider/workspaces/support/report-platform-issue', label: 'Report an issue', exact: true },
        ],
    });
}

if (permissions.has('manage_billing')) {
    navLinks.push({
        href: '/provider/workspaces/billing',
        label: 'Service requests',
        icon: 'fa-solid fa-receipt',
        key: 'billing',
        activePaths: ['/provider/billing'],
        children: [
            { href: '/provider/workspaces/billing', label: 'Dashboard', exact: true },
            { href: '/provider/workspaces/billing/action', label: 'Needs action', exact: true },
            { href: '/provider/workspaces/billing/active', label: 'In progress', exact: true },
            { href: '/provider/workspaces/billing/waiting', label: 'Waiting to start', exact: true },
            { href: '/provider/workspaces/billing/completed', label: 'Completed', exact: true },
            { href: '/provider/workspaces/billing/services', label: 'Browse services', exact: true },
        ],
    });
}
</script>

<template>
    <RoleSidebar
        title="Provider"
        subtitle="Scholarship Workspace"
        icon="fa-solid fa-building-columns"
        home-href="/provider"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue using the provider portal."
        sharp
        navigation-label="Workspace"
    />
</template>
