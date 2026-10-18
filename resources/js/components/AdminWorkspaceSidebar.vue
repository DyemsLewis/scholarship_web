<script setup>
import { computed } from 'vue';
import RoleSidebar from './RoleSidebar.vue';
import {
    adminWorkspaceNavigation,
    resolveAdminWorkspaceKey,
} from '../support/adminWorkspaceNavigation';

const props = defineProps({
    workspace: {
        type: String,
        default: '',
    },
    active: {
        type: String,
        default: '',
    },
});

const workspaceKey = computed(() => props.workspace || resolveAdminWorkspaceKey(window.portalUser ?? {}));
const navigation = computed(() => adminWorkspaceNavigation(workspaceKey.value));
</script>

<template>
    <RoleSidebar
        :active="active"
        :title="navigation.title"
        :subtitle="navigation.subtitle"
        :icon="navigation.icon"
        :home-href="navigation.homeHref"
        :nav-links="navigation.navLinks"
        :logout-message="navigation.logoutMessage"
        navigation-label="Workspace"
        sharp
    />
</template>
