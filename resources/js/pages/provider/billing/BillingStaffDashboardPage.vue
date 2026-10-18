<script setup>
import { onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import WorkspaceDashboardFrame from '../../../components/workspace-dashboard/WorkspaceDashboardFrame.vue';
import WorkspaceDashboardItemList from '../../../components/workspace-dashboard/WorkspaceDashboardItemList.vue';
import WorkspaceDashboardSection from '../../../components/workspace-dashboard/WorkspaceDashboardSection.vue';
import { normalizeWorkspaceDashboard } from '../../../support/workspaceDashboard.js';

const dashboard = ref(normalizeWorkspaceDashboard());
const isLoading = ref(true);
const errorMessage = ref('');

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/billing/dashboard/data');
        dashboard.value = normalizeWorkspaceDashboard(response.data);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The service request dashboard could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page" aria-label="Billing staff dashboard">
            <WorkspaceDashboardFrame :dashboard="dashboard" :loading="isLoading" :error-message="errorMessage">
                <WorkspaceDashboardSection v-for="section in dashboard.sections" :key="section.key" :section="section">
                    <template #default="{ items }">
                        <WorkspaceDashboardItemList :items="items" fallback-icon="fa-solid fa-receipt" />
                    </template>
                </WorkspaceDashboardSection>
            </WorkspaceDashboardFrame>
        </section>
    </main>
</template>
