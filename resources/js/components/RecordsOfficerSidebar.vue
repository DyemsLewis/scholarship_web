<script setup>
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    {
        href: '/admin/workspaces/records/activity',
        label: 'Activity records',
        icon: 'fa-solid fa-clock-rotate-left',
        exact: true,
    },
    {
        href: '/admin/workspaces/records/exports',
        label: 'Data exports',
        icon: 'fa-solid fa-file-export',
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
        title="Records Officer"
        subtitle="Audit and Export Desk"
        icon="fa-solid fa-box-archive"
        home-href="/admin/workspaces/records/activity"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue reviewing records."
    />
</template>
