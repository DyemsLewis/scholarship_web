<script setup>
import RoleSidebar from './RoleSidebar.vue';

defineProps({
    active: {
        type: String,
        default: 'dashboard',
    },
});

const navLinks = [
    { key: 'dashboard', href: '/admin', label: 'Dashboard', icon: 'fa-solid fa-gauge-high' },
    { key: 'users', href: '/admin/manage-users', label: 'Manage Users', icon: 'fa-solid fa-users-gear' },
    { key: 'reviews', href: '/admin/reviews', label: 'Reviews', icon: 'fa-solid fa-clipboard-check' },
    { key: 'logs', href: '/admin/logs', label: 'Logs', icon: 'fa-solid fa-clock-rotate-left' },
    { key: 'profile', href: '/admin/profile', label: 'Profile', icon: 'fa-solid fa-id-badge' },
];
</script>

<template>
    <RoleSidebar
        :active="active"
        title="Admin"
        subtitle="Control Desk"
        icon="fa-solid fa-shield-halved"
        home-href="/admin"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue using the admin portal."
    />
</template>
