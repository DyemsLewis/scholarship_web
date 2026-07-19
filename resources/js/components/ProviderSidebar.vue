<script setup>
import RoleSidebar from './RoleSidebar.vue';

const navLinks = [
    { href: '/provider', label: 'Dashboard', icon: 'fa-solid fa-gauge-high', exact: true },
    { href: '/provider/programs', label: 'Programs', icon: 'fa-solid fa-graduation-cap' },
    { href: '/provider/applications', label: 'Applications', icon: 'fa-solid fa-file-circle-check' },
    { href: '/provider/review', label: 'Review', icon: 'fa-solid fa-clipboard-list' },
    { href: '/provider/profile', label: 'Profile', icon: 'fa-solid fa-building-user' },
];
</script>

<template>
    <RoleSidebar
        title="Provider"
        subtitle="Scholarship Desk"
        icon="fa-solid fa-building-columns"
        home-href="/provider"
        :nav-links="navLinks"
        logout-message="You will need to sign in again to continue using the provider portal."
    />
</template>
