<script setup>
import { computed, onMounted } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import AdminLoadState from '../components/AdminLoadState.vue';
import WorkspaceDashboardFrame from '../../../components/workspace-dashboard/WorkspaceDashboardFrame.vue';
import WorkspaceDashboardItemList from '../../../components/workspace-dashboard/WorkspaceDashboardItemList.vue';
import WorkspaceDashboardSection from '../../../components/workspace-dashboard/WorkspaceDashboardSection.vue';
import { normalizeAdminDashboard as normalizeWorkspaceDashboard } from '../../../support/adminDashboard.js';
import AdminDashboardPriority from '../components/AdminDashboardPriority.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get('/admin/workspaces/records/dashboard/data', { signal });
    return normalizeWorkspaceDashboard(response.data);
}, { failureMessage: 'The records dashboard could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const dashboard = computed(() => resource.data.value ?? normalizeWorkspaceDashboard({ workspace: {
    key: 'records-officer', title: 'Records dashboard', description: 'Inspect platform activity and authorized data exports.', icon: 'fa-solid fa-box-archive',
} }));
const loadDashboard = () => resource.load();

onMounted(loadDashboard);
</script>

<template>
    <AdminWorkspacePage workspace="records_officer">
        <WorkspaceDashboardFrame :dashboard="dashboard" :loading="loading" :error-message="errorMessage">
            <AdminDashboardPriority v-if="dashboard.priority" :priority="dashboard.priority" />
            <template #error><section class="admin-panel mt-4"><AdminLoadState :error="errorMessage" @retry="loadDashboard" /></section></template>
            <WorkspaceDashboardSection v-for="section in dashboard.sections" :key="section.key" :section="section">
                <template #default="{ items }">
                    <WorkspaceDashboardItemList :items="items" fallback-icon="fa-solid fa-clock-rotate-left" />
                </template>
            </WorkspaceDashboardSection>
        </WorkspaceDashboardFrame>
    </AdminWorkspacePage>
</template>
