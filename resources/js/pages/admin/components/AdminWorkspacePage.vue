<script setup>
import AdminWorkspaceSidebar from '../../../components/AdminWorkspaceSidebar.vue';

defineProps({
    workspace: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <main class="admin-shell">
        <AdminWorkspaceSidebar :workspace="workspace" />

        <section class="admin-page">
            <div class="admin-container min-w-0">
                <slot></slot>
            </div>
        </section>
    </main>
</template>
