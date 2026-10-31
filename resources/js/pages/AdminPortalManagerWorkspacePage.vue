<script setup>
import { computed, onMounted } from 'vue';
import { useAdminResource } from '../composables/useAdminResource.js';
import AdminLoadState from './admin/components/AdminLoadState.vue';
import AdminEmptyState from './admin/components/AdminEmptyState.vue';
import WorkspaceMetricStrip from '../components/workspace-dashboard/WorkspaceMetricStrip.vue';
import AdminTableFrame from './admin/components/AdminTableFrame.vue';
import AdminWorkspaceHeader from './admin/components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from './admin/components/AdminWorkspacePage.vue';

const resource = useAdminResource(async ({ signal }) => {
    const { data } = await window.axios.get('/admin/workspaces/portal/data', { signal });
    return data;
}, { failureMessage: 'Unable to load portal operations.' });
const { isLoading, errorMessage } = resource;
const summary = computed(() => resource.data.value?.summary ?? {});
const priorities = computed(() => resource.data.value?.priorities ?? []);
const stableAreas = computed(() => priorities.value.filter((item) => Number(item.count) === 0).length);
const metrics = computed(() => [
    { key: 'attention', label: 'Needs attention', value: summary.value.total_attention, detail: 'Queue items across teams', tone: 'attention', icon: 'fa-solid fa-triangle-exclamation' },
    { key: 'revenue', label: 'Monthly revenue', value: money(summary.value.month_revenue), detail: 'Confirmed payments', icon: 'fa-solid fa-peso-sign' },
    { key: 'stable', label: 'Stable areas', value: `${stableAreas.value} of ${priorities.value.length}`, detail: 'No open attention item', icon: 'fa-solid fa-shield-check' },
]);

function money(amount) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(Number(amount ?? 0) / 100);
}

const loadOverview = () => resource.load();

onMounted(loadOverview);
</script>

<template>
    <AdminWorkspacePage workspace="portal_manager" class="admin-portal-workspace">
        <AdminWorkspaceHeader eyebrow="Portal operations" title="Operations overview" description="See workload and risk across each admin team." icon="fa-solid fa-shield-halved">
            <template #actions><button type="button" :disabled="isLoading" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-50" @click="loadOverview"><i class="fa-solid fa-rotate text-xs" aria-hidden="true"></i>Refresh</button></template>
        </AdminWorkspaceHeader>

        <section v-if="isLoading || errorMessage" class="admin-panel mt-4"><AdminLoadState :loading="isLoading" :error="errorMessage" loading-message="Loading portal operations..." @retry="loadOverview" /></section>
        <WorkspaceMetricStrip v-else :metrics="metrics" />

        <AdminTableFrame v-if="!isLoading && !errorMessage" title="Team workload" :description="`${summary.activity_today} platform events today`" icon="fa-solid fa-list-check" class="mt-4">
            <table v-if="priorities.length" class="portal-data-table portal-oversight-table">
                <colgroup><col style="width: 31%"><col style="width: 31%"><col style="width: 20%"><col style="width: 18%"></colgroup>
                <thead><tr><th class="px-6">Area</th><th>Owner</th><th>Open items</th><th class="px-6 text-right">Workspace</th></tr></thead>
                <tbody>
                    <tr v-for="item in priorities" :key="item.key">
                        <td class="px-6 font-bold text-slate-950">{{ item.label }}</td>
                        <td class="text-slate-600">{{ item.owner }}</td>
                        <td><span :class="['inline-flex min-w-10 justify-center px-2.5 py-1 text-xs font-black', item.count ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600']">{{ item.count }}</span></td>
                        <td class="px-6 text-right"><a :href="item.url" class="inline-grid h-10 w-10 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open ${item.label}`" title="Open overview"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else title="No workload summary available" description="Refresh to check for the latest team workload." />
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
