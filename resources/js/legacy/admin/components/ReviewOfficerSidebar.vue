<script setup>
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    {
        href: '/admin/workspaces/reviews',
        label: 'Review workspace',
        icon: 'fa-solid fa-clipboard-check',
        activePrefixes: ['/admin/workspaces/reviews'],
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
        title="Review Officer"
        subtitle="Verification Desk"
        icon="fa-solid fa-clipboard-check"
        home-href="/admin/workspaces/reviews"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue reviewing records."
    />
</template>
