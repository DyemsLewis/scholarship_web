<script setup>
import { onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import WorkspaceGuide from '../../../components/provider/WorkspaceGuide.vue';
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
        const response = await window.axios.get('/provider/workspaces/recipients/dashboard/data');
        dashboard.value = normalizeWorkspaceDashboard(response.data);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The recipient dashboard could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page" aria-label="Recipient officer dashboard">
            <WorkspaceDashboardFrame class="provider-dashboard" :dashboard="dashboard" :loading="isLoading" :error-message="errorMessage">
                <template #actions>
                    <div class="flex shrink-0 items-center gap-3">
                        <WorkspaceGuide workspace="recipients" auto-offer />
                        <a v-if="dashboard.primary_action" :href="dashboard.primary_action.url" class="workspace-dashboard-action inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">{{ dashboard.primary_action.label }}<i :class="[dashboard.primary_action.icon, 'text-xs text-amber-300']" aria-hidden="true"></i></a>
                    </div>
                </template>
                <WorkspaceDashboardSection v-for="section in dashboard.sections" :key="section.key" :section="section">
                    <template #default="{ items }">
                        <WorkspaceDashboardItemList :items="items" fallback-icon="fa-solid fa-user-shield" />
                    </template>
                </WorkspaceDashboardSection>
            </WorkspaceDashboardFrame>
        </section>
    </main>
</template>
