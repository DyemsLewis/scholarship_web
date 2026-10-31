<script setup>
import { computed, onMounted } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import AdminLoadState from '../components/AdminLoadState.vue';
import WorkspaceDashboardFrame from '../../../components/workspace-dashboard/WorkspaceDashboardFrame.vue';
import WorkspaceDashboardItemList from '../../../components/workspace-dashboard/WorkspaceDashboardItemList.vue';
import WorkspaceDashboardSection from '../../../components/workspace-dashboard/WorkspaceDashboardSection.vue';
import { normalizeWorkspaceDashboard } from '../../../support/workspaceDashboard.js';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get('/admin/workspaces/support/dashboard/data', { signal });
    return normalizeWorkspaceDashboard(response.data);
}, { failureMessage: 'The support dashboard could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const dashboard = computed(() => resource.data.value ?? normalizeWorkspaceDashboard({ workspace: {
    key: 'support-officer', title: 'Support dashboard', eyebrow: 'Platform support',
    description: 'Respond to open cases and track provider follow-up.', icon: 'fa-solid fa-headset',
} }));
const loadDashboard = () => resource.load();

onMounted(loadDashboard);
</script>

<template>
    <AdminWorkspacePage workspace="support_officer">
        <WorkspaceDashboardFrame :dashboard="dashboard" :loading="loading" :error-message="errorMessage">
            <template #error><section class="admin-panel mt-4"><AdminLoadState :error="errorMessage" @retry="loadDashboard" /></section></template>
            <WorkspaceDashboardSection v-for="section in dashboard.sections" :key="section.key" :section="section">
                <template #default="{ items }">
                    <WorkspaceDashboardItemList :items="items" fallback-icon="fa-solid fa-headset" />
                </template>
            </WorkspaceDashboardSection>
        </WorkspaceDashboardFrame>
    </AdminWorkspacePage>
</template>
