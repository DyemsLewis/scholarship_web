<script setup>
import { onMounted, ref } from 'vue';
import WorkspaceDashboardFrame from '../../../components/workspace-dashboard/WorkspaceDashboardFrame.vue';
import WorkspaceDashboardItemList from '../../../components/workspace-dashboard/WorkspaceDashboardItemList.vue';
import WorkspaceDashboardSection from '../../../components/workspace-dashboard/WorkspaceDashboardSection.vue';
import { normalizeWorkspaceDashboard } from '../../../support/workspaceDashboard.js';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const dashboard = ref(normalizeWorkspaceDashboard());
const loading = ref(true);
const errorMessage = ref('');

async function loadDashboard() {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/accounts/dashboard/data');
        dashboard.value = normalizeWorkspaceDashboard(response.data);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The account dashboard could not be loaded.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <AdminWorkspacePage workspace="account_manager">
        <WorkspaceDashboardFrame :dashboard="dashboard" :loading="loading" :error-message="errorMessage">
            <WorkspaceDashboardSection v-for="section in dashboard.sections" :key="section.key" :section="section">
                <template #default="{ items }">
                    <WorkspaceDashboardItemList :items="items" fallback-icon="fa-solid fa-user" />
                </template>
            </WorkspaceDashboardSection>
        </WorkspaceDashboardFrame>
    </AdminWorkspacePage>
</template>
