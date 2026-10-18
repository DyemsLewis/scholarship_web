<script setup>
import AdminWorkspaceSidebar from './AdminWorkspaceSidebar.vue';

defineProps({
    active: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <AdminWorkspaceSidebar workspace="super_admin" :active="active" />
</template>
