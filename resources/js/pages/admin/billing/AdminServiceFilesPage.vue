<script setup>
import { computed } from 'vue';
import { formatAdminServiceDateTime, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';

const { purchaseId, purchase, isLoading, loadError, loadRecord, isSaving: isUploading, canEdit, errorMessage, successMessage, queueUrl, runAction } = useAdminServiceWorkspace('files');
const files = computed(() => purchase.value?.files ?? []);

function sourceLabel(file) {
    return file.category === 'deliverable' ? 'Platform deliverable' : 'Provider file';
}

async function uploadDeliverable(event) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file || isUploading.value || !canEdit.value) return;
    if (file.size > 10 * 1024 * 1024) { errorMessage.value = 'Choose a supported file up to 10 MB.'; input.value = ''; return; }
    const data = new FormData();
    data.append('service_file', file);
    try {
        await runAction((config) => window.axios.post(`/admin/billing/${purchaseId}/deliverables`, data, config), 'Deliverable shared.');
    } finally {
        input.value = '';
    }
}
</script>

<template>
    <AdminServiceWorkspaceShell :purchase="purchase" active-section="files" :loading="isLoading" :load-error="loadError" @retry="loadRecord" :error-message="errorMessage" :success-message="successMessage" :queue-url="queueUrl">
        <section class="admin-panel mt-4 overflow-hidden">
            <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
                <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-regular fa-folder-open" aria-hidden="true"></i></span><div><h2 class="text-lg font-black text-slate-950">Service files</h2><p class="mt-0.5 text-sm text-slate-500">Review provider material and share final work in one list.</p></div></div>
                <label v-if="canEdit" :class="['inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-slate-950', isUploading ? 'cursor-wait opacity-50' : 'cursor-pointer']"><i class="fa-solid fa-upload text-amber-300" aria-hidden="true"></i>{{ isUploading ? 'Uploading...' : 'Upload deliverable' }}<input type="file" class="sr-only" :disabled="isUploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="uploadDeliverable"></label>
            </header>
            <p v-if="canEdit" class="border-b border-slate-200 bg-slate-50 px-6 py-3 text-sm text-slate-600">Deliverables are shared with the provider immediately. Supported documents and images, up to 10 MB.</p>

            <div v-if="!files.length" class="px-6 py-16 text-center"><i class="fa-regular fa-folder-open text-2xl text-slate-300" aria-hidden="true"></i><p class="mt-3 text-sm font-bold text-slate-900">No files shared yet</p><p class="mt-1 text-sm text-slate-500">Provider uploads and platform deliverables will appear here.</p></div>
            <div v-else class="staff-table-viewport staff-table-viewport--compact">
                <table class="portal-data-table min-w-[900px] table-fixed">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="w-[38%] border-b border-slate-200 px-5 py-3">File</th><th class="w-[24%] border-b border-slate-200 px-5 py-3">Source</th><th class="w-[22%] border-b border-slate-200 px-5 py-3">Shared</th><th class="w-[16%] border-b border-slate-200 px-5 py-3 text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 bg-white"><tr v-for="file in files" :key="file.id" class="align-middle hover:bg-slate-50/70">
                        <td class="px-5 py-4"><p class="billing-text text-sm font-bold text-slate-950" data-file-name>{{ file.original_name }}</p></td>
                        <td class="px-5 py-4"><p class="text-sm font-semibold text-slate-800">{{ sourceLabel(file) }}</p><p v-if="file.uploaded_by" class="billing-text mt-0.5 text-xs text-slate-500">{{ file.uploaded_by }}</p></td>
                        <td class="px-5 py-4 text-sm text-slate-600">{{ formatAdminServiceDateTime(file.created_at) }}</td>
                        <td class="px-5 py-4 text-right"><div class="inline-flex gap-2"><a :href="file.view_url" data-file-preview="true" :data-file-name="file.original_name" :data-download-url="file.download_url" class="staff-row-action h-10 w-10" :aria-label="`Preview ${file.original_name}`" title="Preview file"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a><a :href="file.download_url" download data-file-preview="false" class="staff-row-action staff-row-action--primary h-10 w-10" :aria-label="`Download ${file.original_name}`" title="Download file"><i class="fa-solid fa-download text-xs" aria-hidden="true"></i></a></div></td>
                    </tr></tbody>
                </table>
            </div>
        </section>
    </AdminServiceWorkspaceShell>
</template>
