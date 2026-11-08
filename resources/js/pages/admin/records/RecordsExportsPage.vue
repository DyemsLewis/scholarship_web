<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminLoadState from '../components/AdminLoadState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get('/admin/workspaces/records/exports/data', { signal });
    return response.data;
}, { failureMessage: 'The export desk could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const datasets = computed(() => resource.data.value?.datasets ?? []);
const recentExports = computed(() => resource.data.value?.recent_exports ?? []);
const monitoringStatus = ref('all');
const exporting = ref('');
const exportError = ref('');
const exportMessage = ref('');
const feedback = ref(null);
let exportController;
let downloadObjectUrl;
let cleanupTimer;
let disposed = false;
const loadData = () => resource.load();

function datasetIcon(key) {
    return { users: 'fa-solid fa-users', applications: 'fa-solid fa-folder-open', monitoring: 'fa-solid fa-chart-line' }[key] ?? 'fa-solid fa-file-csv';
}

function releaseDownload() {
    clearTimeout(cleanupTimer);
    if (downloadObjectUrl) URL.revokeObjectURL(downloadObjectUrl);
    downloadObjectUrl = null;
}

async function downloadDataset(dataset) {
    if (disposed || loading.value || errorMessage.value || exporting.value || !datasets.value.includes(dataset)) return;
    exportError.value = '';
    exportMessage.value = '';
    const filenames = { users: 'scholarship-users.csv', applications: 'scholarship-applications.csv', monitoring: 'recipient-monitoring.csv' };
    let url;
    try { url = new URL(dataset.download_url, window.location.origin); } catch { /* Invalid links remain in the export desk. */ }
    if (!filenames[dataset.key] || !url || url.origin !== window.location.origin || !url.pathname.startsWith('/admin/export/')) {
        exportError.value = 'This dataset has no valid download link. Reload the export desk.';
        feedback.value?.focus();
        return;
    }
    exporting.value = dataset.key;
    exportController = new AbortController();
    try {
        const response = await window.axios.get(url.pathname + url.search, {
            params: dataset.key === 'monitoring' ? { status: monitoringStatus.value } : undefined,
            responseType: 'blob', signal: exportController.signal, portalToast: false,
        });
        if (disposed) return;
        if (!String(response.headers?.['content-type'] ?? '').includes('text/csv')) throw new Error('Unexpected download response');
        releaseDownload();
        downloadObjectUrl = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = downloadObjectUrl;
        const disposition = response.headers?.['content-disposition'] ?? '';
        const filename = /filename="?([^";]+)"?/i.exec(disposition)?.[1];
        link.download = filename && /^[a-zA-Z0-9._-]+\.csv$/.test(filename) ? filename : filenames[dataset.key];
        document.body.appendChild(link);
        link.click();
        link.remove();
        cleanupTimer = setTimeout(releaseDownload, 30000);
        exportMessage.value = `${dataset.name} is ready. Your browser has started the download; check your downloads folder.`;
        await loadData();
    } catch (error) {
        if (disposed) return;
        let message = error.response?.data?.message;
        if (error.response?.data instanceof Blob) {
            try { message = JSON.parse(await error.response.data.text()).message; } catch { /* Non-JSON gateway failures use the fallback. */ }
        }
        if (!disposed) exportError.value = message || 'The download could not be completed. Try again when you are ready.';
    } finally {
        if (!disposed) {
            exporting.value = '';
            feedback.value?.focus();
        }
    }
}

onBeforeUnmount(() => {
    disposed = true;
    exportController?.abort();
    releaseDownload();
});
onMounted(loadData);
</script>

<template>
    <AdminWorkspacePage workspace="records_officer" class="admin-records-workspace">
        <AdminWorkspaceHeader
            eyebrow="Authorized data"
            title="Data exports"
            description="Download one approved platform dataset at a time."
            icon="fa-solid fa-file-export"
        >
            <template #actions><a href="/admin/workspaces/records/activity" class="inline-flex h-11 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950"><i class="fa-solid fa-clock-rotate-left text-xs" aria-hidden="true"></i>View activity</a></template>
        </AdminWorkspaceHeader>

        <div ref="feedback" tabindex="-1" class="admin-export-feedback">
            <p v-if="exportError" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ exportError }}</p>
            <p v-else-if="exportMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900" role="status">{{ exportMessage }}</p>
            <p v-else-if="exporting" class="mt-4 border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700" role="status">Preparing your CSV download. Keep this page open.</p>
        </div>
        <section v-if="loading || errorMessage" class="admin-panel mt-4"><AdminLoadState :loading="loading" :error="errorMessage" loading-message="Loading export desk..." @retry="loadData" /></section>

        <template v-else>
            <section class="admin-panel mt-4 overflow-hidden">
                <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-database" aria-hidden="true"></i></span><div><h2 class="text-base font-black text-slate-950">Available datasets</h2><p class="mt-0.5 text-sm text-slate-500">Each authorized export request is recorded. Downloaded files contain private data.</p></div></div><span class="inline-flex items-center gap-2 bg-amber-100 px-3 py-2 text-xs font-bold text-amber-900"><i class="fa-solid fa-lock" aria-hidden="true"></i>Authorized use only</span></header>
                <div class="divide-y divide-slate-200">
                    <article v-for="dataset in datasets" :key="dataset.key" class="grid grid-cols-[3rem_minmax(0,1fr)_8rem] items-center gap-4 px-6 py-5">
                        <span class="grid h-12 w-12 place-items-center bg-slate-950 text-amber-300"><i :class="datasetIcon(dataset.key)" aria-hidden="true"></i></span>
                        <div class="min-w-0"><h3 class="text-sm font-black text-slate-950">{{ dataset.name }}</h3><p class="mt-1 text-sm text-slate-500">{{ dataset.description }}</p><p class="mt-1 text-xs text-slate-500">Last export: {{ dataset.last_exported_at || 'Not exported yet' }}</p></div>
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Current records</p><p class="mt-1 text-lg font-black text-slate-950">{{ dataset.records }}</p></div>
                        <div class="col-start-2 col-end-4 flex items-center justify-end gap-2">
                            <select v-if="dataset.key === 'monitoring'" v-model="monitoringStatus" :disabled="!!exporting" aria-label="Monitoring export status" class="h-10 border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700"><option value="all">All records</option><option value="attention">Needs attention</option><option value="reviewed">Reviewed</option><option value="stable">Stable</option><option value="closed">Closed</option></select>
                            <button type="button" :disabled="!!exporting" :aria-label="`Download ${dataset.name} as CSV`" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50" @click="downloadDataset(dataset)"><i :class="exporting === dataset.key ? 'fa-solid fa-spinner fa-spin text-xs' : 'fa-solid fa-download text-xs'" aria-hidden="true"></i>{{ exporting === dataset.key ? 'Preparing...' : 'Download CSV' }}</button>
                        </div>
                    </article>
                </div>
            </section>

            <AdminTableFrame compact title="Recent exports" description="Latest export requests. Browser download completion cannot be verified." icon="fa-solid fa-clock-rotate-left" class="mt-4">
                    <table v-if="recentExports.length" class="portal-data-table admin-ledger-table">
                        <colgroup><col style="width: 29%"><col style="width: 25%"><col style="width: 18%"><col style="width: 18%"><col style="width: 10%"></colgroup>
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">Dataset</th><th class="border-b border-slate-200 px-4 py-3">Requested by</th><th class="border-b border-slate-200 px-4 py-3">Source</th><th class="border-b border-slate-200 px-4 py-3">Recorded</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr></thead>
                        <tbody class="divide-y divide-slate-200"><tr v-for="item in recentExports" :key="item.id" class="hover:bg-slate-50/70"><td class="px-5 py-3.5"><p class="text-sm font-bold text-slate-950">{{ item.description }}</p><p class="mt-0.5 text-xs text-slate-500">{{ item.action_label }}</p></td><td class="px-4 py-3.5 text-sm font-semibold text-slate-800">{{ item.actor_name }}</td><td class="px-4 py-3.5 font-mono text-xs text-slate-600">{{ item.ip_address || 'Not recorded' }}</td><td class="px-4 py-3.5 text-sm text-slate-600">{{ item.created_at }}</td><td class="px-5 py-3.5 text-right"><a :href="item.record_url" class="staff-row-action h-10 w-10" aria-label="Open export activity" title="Open record"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td></tr></tbody>
                    </table>
                    <AdminEmptyState v-else compact title="No exports recorded" description="Requested exports will appear here." icon="fa-solid fa-file-export" />
            </AdminTableFrame>
        </template>
    </AdminWorkspacePage>
</template>
