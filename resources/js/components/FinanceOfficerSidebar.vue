<script setup>
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    {
        href: '/admin/workspaces/finance',
        label: 'Finance overview',
        icon: 'fa-solid fa-chart-column',
        exact: true,
    },
    {
        href: '/admin/workspaces/finance/receipts',
        label: 'Payment receipts',
        icon: 'fa-solid fa-receipt',
        exact: true,
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
        title="Finance Officer"
        subtitle="Financial Oversight"
        icon="fa-solid fa-chart-line"
        home-href="/admin/workspaces/finance"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue reviewing financial records."
    />
</template>
