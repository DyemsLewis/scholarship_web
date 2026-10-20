<script setup>
import { computed, onMounted, ref } from 'vue';
import WorkspaceMetricStrip from '../components/workspace-dashboard/WorkspaceMetricStrip.vue';
import AdminTableFrame from './admin/components/AdminTableFrame.vue';
import AdminWorkspaceHeader from './admin/components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from './admin/components/AdminWorkspacePage.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const summary = ref({ account_attention: 0, pending_reviews: 0, open_reports: 0, active_services: 0, month_revenue: 0, activity_today: 0, total_attention: 0 });
const priorities = ref([]);
const stableAreas = computed(() => priorities.value.filter((item) => Number(item.count) === 0).length);
const metrics = computed(() => [
    { key: 'attention', label: 'Needs attention', value: summary.value.total_attention, detail: 'Across all teams', tone: 'attention', icon: 'fa-solid fa-triangle-exclamation' },
    { key: 'revenue', label: 'Monthly revenue', value: money(summary.value.month_revenue), detail: 'Confirmed payments', icon: 'fa-solid fa-peso-sign' },
    { key: 'stable', label: 'Stable areas', value: `${stableAreas.value} of ${priorities.value.length}`, detail: 'No open attention item', icon: 'fa-solid fa-shield-check' },
]);

function money(amount) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(Number(amount ?? 0) / 100);
}

async function loadOverview() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await window.axios.get('/admin/workspaces/portal/data');
        summary.value = { ...summary.value, ...data.summary };
        priorities.value = data.priorities ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load portal operations.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadOverview);
</script>

<template>
    <AdminWorkspacePage workspace="portal_manager">
        <AdminWorkspaceHeader eyebrow="Portal operations" title="Operations overview" description="See workload and risk across each admin team." icon="fa-solid fa-shield-halved">
            <template #actions><button type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950" @click="loadOverview"><i class="fa-solid fa-rotate text-xs" aria-hidden="true"></i>Refresh</button></template>
        </AdminWorkspaceHeader>

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700" role="alert">{{ errorMessage }}</p>
        <WorkspaceMetricStrip :metrics="metrics" :loading="isLoading" />

        <AdminTableFrame title="Team workload" :description="`${summary.activity_today} platform events today`" icon="fa-solid fa-list-check" class="mt-4">
            <div v-if="isLoading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading operations...</div>
            <table v-else class="portal-data-table min-w-[52rem]">
                <thead><tr><th class="px-6">Area</th><th>Owner</th><th>Open items</th><th class="px-6 text-right">Workspace</th></tr></thead>
                <tbody>
                    <tr v-for="item in priorities" :key="item.key">
                        <td class="px-6 font-bold text-slate-950">{{ item.label }}</td>
                        <td class="text-slate-600">{{ item.owner }}</td>
                        <td><span :class="['inline-flex min-w-10 justify-center px-2.5 py-1 text-xs font-black', item.count ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600']">{{ item.count }}</span></td>
                        <td class="px-6 text-right"><a :href="item.url" class="inline-grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open ${item.label}`" title="Open overview"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                    </tr>
                </tbody>
            </table>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
