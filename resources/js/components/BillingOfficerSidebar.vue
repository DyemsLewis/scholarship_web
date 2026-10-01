<script setup>
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    {
        href: '/admin/workspaces/billing',
        label: 'Billing workspace',
        icon: 'fa-solid fa-file-invoice-dollar',
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
        title="Billing Officer"
        subtitle="Service Desk"
        icon="fa-solid fa-file-invoice-dollar"
        home-href="/admin/workspaces/billing"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue managing paid service requests."
    />
</template>
