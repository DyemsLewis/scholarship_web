<script setup>
import { onMounted, ref } from 'vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const loading = ref(true);
const errorMessage = ref('');
const datasets = ref([]);
const recentExports = ref([]);
const monitoringStatus = ref('all');

function datasetIcon(key) {
    return { users: 'fa-solid fa-users', applications: 'fa-solid fa-folder-open', monitoring: 'fa-solid fa-chart-line' }[key] ?? 'fa-solid fa-file-csv';
}

function downloadUrl(dataset) {
    if (dataset.key !== 'monitoring') return dataset.download_url;
    return `${dataset.download_url}?status=${encodeURIComponent(monitoringStatus.value)}`;
}

async function loadData() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/admin/workspaces/records/exports/data');
        datasets.value = response.data.datasets ?? [];
        recentExports.value = response.data.recent_exports ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The export desk could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function scheduleRefresh() {
    window.setTimeout(loadData, 1000);
}

onMounted(loadData);
</script>

<template>
    <AdminWorkspacePage workspace="records_officer">
        <AdminWorkspaceHeader
            eyebrow="Authorized data"
            title="Data exports"
            description="Download one approved platform dataset at a time."
            icon="fa-solid fa-file-export"
        >
            <template #actions><a href="/admin/workspaces/records/activity" class="inline-flex h-11 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950"><i class="fa-solid fa-clock-rotate-left text-xs" aria-hidden="true"></i>View activity</a></template>
        </AdminWorkspaceHeader>

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>
        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading export desk...</div>

        <template v-else>
            <section class="admin-panel mt-4 overflow-hidden">
                <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-4"><div><h2 class="text-base font-black text-slate-950">Available datasets</h2><p class="mt-1 text-sm text-slate-500">Every download is added to the audit trail.</p></div><span class="inline-flex items-center gap-2 bg-amber-100 px-3 py-2 text-xs font-bold text-amber-900"><i class="fa-solid fa-lock" aria-hidden="true"></i>Authorized use only</span></header>
                <div class="divide-y divide-slate-200">
                    <article v-for="dataset in datasets" :key="dataset.key" class="grid grid-cols-[3rem_minmax(0,1fr)_9rem_minmax(13rem,auto)] items-center gap-4 px-6 py-5">
                        <span class="grid h-12 w-12 place-items-center bg-slate-950 text-amber-300"><i :class="datasetIcon(dataset.key)" aria-hidden="true"></i></span>
                        <div class="min-w-0"><h3 class="text-sm font-black text-slate-950">{{ dataset.name }}</h3><p class="mt-1 text-sm text-slate-500">{{ dataset.description }}</p><p class="mt-1 text-xs text-slate-400">Last export: {{ dataset.last_exported_at || 'Not exported yet' }}</p></div>
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Current records</p><p class="mt-1 text-lg font-black text-slate-950">{{ dataset.records }}</p></div>
                        <div class="flex items-center justify-end gap-2">
                            <select v-if="dataset.key === 'monitoring'" v-model="monitoringStatus" aria-label="Monitoring export status" class="h-10 border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700"><option value="all">All records</option><option value="attention">Needs attention</option><option value="reviewed">Reviewed</option><option value="stable">Stable</option><option value="closed">Closed</option></select>
                            <a :href="downloadUrl(dataset)" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800" @click="scheduleRefresh"><i class="fa-solid fa-download text-xs" aria-hidden="true"></i>Download CSV</a>
                        </div>
                    </article>
                </div>
            </section>

            <section class="admin-panel mt-4 overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-black text-slate-950">Recent exports</h2><p class="mt-1 text-sm text-slate-500">The latest sensitive-data downloads.</p></header>
                <div class="staff-table-viewport">
                    <table v-if="recentExports.length" class="w-full min-w-[58rem] border-collapse text-left">
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">Dataset</th><th class="border-b border-slate-200 px-4 py-3">Downloaded by</th><th class="border-b border-slate-200 px-4 py-3">Source</th><th class="border-b border-slate-200 px-4 py-3">Recorded</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr></thead>
                        <tbody class="divide-y divide-slate-200"><tr v-for="item in recentExports" :key="item.id" class="hover:bg-slate-50/70"><td class="px-5 py-3.5"><p class="text-sm font-bold text-slate-950">{{ item.description }}</p><p class="mt-0.5 text-xs text-slate-500">{{ item.action_label }}</p></td><td class="px-4 py-3.5 text-sm font-semibold text-slate-800">{{ item.actor_name }}</td><td class="px-4 py-3.5 font-mono text-xs text-slate-600">{{ item.ip_address || 'Not recorded' }}</td><td class="px-4 py-3.5 text-sm text-slate-600">{{ item.created_at }}</td><td class="px-5 py-3.5 text-right"><a :href="item.record_url" class="inline-grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" aria-label="Open export activity" title="Open record"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td></tr></tbody>
                    </table>
                    <AdminEmptyState v-else title="No exports recorded" description="A completed download will appear here." icon="fa-solid fa-file-export" />
                </div>
            </section>
        </template>
    </AdminWorkspacePage>
</template>
