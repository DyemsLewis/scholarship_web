<script setup>
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    {
        href: '/admin/workspaces/accounts',
        label: 'Account workspace',
        icon: 'fa-solid fa-user-shield',
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
        title="Account Manager"
        subtitle="Access Desk"
        icon="fa-solid fa-user-shield"
        home-href="/admin/workspaces/accounts"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue managing accounts."
    />
</template>
