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
    const response = await window.axios.get('/admin/workspaces/billing/dashboard/data', { signal });
    return normalizeWorkspaceDashboard(response.data);
}, { failureMessage: 'The service dashboard could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const dashboard = computed(() => resource.data.value ?? normalizeWorkspaceDashboard({ workspace: {
    key: 'billing-officer', title: 'Service dashboard', eyebrow: 'Service delivery',
    description: 'Review service requests and continue delivery work.', icon: 'fa-solid fa-file-invoice-dollar',
} }));
const loadDashboard = () => resource.load();

onMounted(loadDashboard);
</script>

<template>
    <AdminWorkspacePage workspace="billing_officer">
        <WorkspaceDashboardFrame :dashboard="dashboard" :loading="loading" :error-message="errorMessage">
            <template #error><section class="admin-panel mt-4"><AdminLoadState :error="errorMessage" @retry="loadDashboard" /></section></template>
            <WorkspaceDashboardSection v-for="section in dashboard.sections" :key="section.key" :section="section">
                <template #default="{ items }">
                    <WorkspaceDashboardItemList :items="items" fallback-icon="fa-solid fa-file-invoice-dollar" />
                </template>
            </WorkspaceDashboardSection>
        </WorkspaceDashboardFrame>
    </AdminWorkspacePage>
</template>
