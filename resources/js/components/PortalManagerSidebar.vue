<script setup>
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    {
        href: '/admin/workspaces/portal',
        label: 'Operations overview',
        icon: 'fa-solid fa-gauge-high',
        exact: true,
    },
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
            { href: '/admin/workspaces/portal/accounts', label: 'Accounts', icon: 'fa-solid fa-users-gear', exact: true },
            { href: '/admin/workspaces/portal/reviews', label: 'Reviews', icon: 'fa-solid fa-clipboard-check' },
            { href: '/admin/workspaces/portal/support', label: 'Support', icon: 'fa-solid fa-headset', exact: true },
            { href: '/admin/workspaces/portal/billing', label: 'Services', icon: 'fa-solid fa-file-invoice-dollar' },
        ],
    },
    {
        key: 'oversight',
        href: '/admin/workspaces/portal/finance',
        label: 'Oversight',
        icon: 'fa-solid fa-chart-line',
        activePaths: [
            '/admin/workspaces/portal/finance',
            '/admin/workspaces/portal/records',
        ],
        children: [
            { href: '/admin/workspaces/portal/finance', label: 'Finance', icon: 'fa-solid fa-chart-column', exact: true },
            { href: '/admin/workspaces/portal/finance/receipts', label: 'Receipts', icon: 'fa-solid fa-receipt', exact: true },
            { href: '/admin/workspaces/portal/records/activity', label: 'Activity records', icon: 'fa-solid fa-clock-rotate-left', exact: true },
            { href: '/admin/workspaces/portal/records/exports', label: 'Data exports', icon: 'fa-solid fa-file-export', exact: true },
        ],
    },
    {
        href: '/admin/profile',
        label: 'My profile',
        icon: 'fa-solid fa-id-badge',
        exact: true,
    },
];
</script>

<template>
    <RoleSidebar
        title="Portal Manager"
        subtitle="Operations Control"
        icon="fa-solid fa-shield-halved"
        home-href="/admin/workspaces/portal"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue managing portal operations."
    />
</template>
