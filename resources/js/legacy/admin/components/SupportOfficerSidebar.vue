<script setup>
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    {
        href: '/admin/workspaces/support',
        label: 'Support workspace',
        icon: 'fa-solid fa-headset',
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
        title="Support Officer"
        subtitle="Resolution Desk"
        icon="fa-solid fa-headset"
        home-href="/admin/workspaces/support"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue handling support reports."
    />
</template>
