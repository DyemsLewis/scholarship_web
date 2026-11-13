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
    const response = await window.axios.get('/admin/workspaces/accounts/dashboard/data', { signal });
    return normalizeWorkspaceDashboard(response.data);
}, { failureMessage: 'The account dashboard could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const dashboard = computed(() => resource.data.value ?? normalizeWorkspaceDashboard({ workspace: { key: 'account-manager', title: 'Account dashboard', eyebrow: 'Account access', description: 'Resolve access issues and manage portal accounts.', icon: 'fa-solid fa-users-gear' } }));

const loadDashboard = () => resource.load();

onMounted(loadDashboard);
</script>

<template>
    <AdminWorkspacePage workspace="account_manager">
        <WorkspaceDashboardFrame :dashboard="dashboard" :loading="loading" :error-message="errorMessage">
            <template #error><section class="admin-panel mt-4 overflow-hidden"><AdminLoadState :error="errorMessage" @retry="loadDashboard" /></section></template>
            <AdminDashboardPriority v-if="dashboard.priority" :priority="dashboard.priority" />
            <WorkspaceDashboardSection v-for="section in dashboard.sections" :key="section.key" :section="section">
                <template #default="{ items }">
                    <WorkspaceDashboardItemList :items="items" fallback-icon="fa-solid fa-user" />
                </template>
            </WorkspaceDashboardSection>
        </WorkspaceDashboardFrame>
    </AdminWorkspacePage>
</template>
