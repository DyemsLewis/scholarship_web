<script setup>
import { computed, ref } from 'vue';
import FilePreviewModal from '../../../components/FilePreviewModal.vue';
import { formatAdminServiceDateTime, formatAdminServiceFileSize, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';

const { purchaseId, purchase, isLoading, errorMessage, successMessage, queueUrl, applyPurchase, handleError } = useAdminServiceWorkspace('files');
const isUploading = ref(false);
const previewFile = ref(null);
const files = computed(() => purchase.value?.files ?? []);

function sourceLabel(file) {
    return file.category === 'deliverable' ? 'Platform deliverable' : 'Provider file';
}

async function uploadDeliverable(event) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file || isUploading.value) return;
    isUploading.value = true;
    errorMessage.value = '';
    const data = new FormData();
    data.append('service_file', file);
    try {
        const response = await window.axios.post(`/admin/billing/${purchaseId}/deliverables`, data);
        applyPurchase(response.data.purchase);
        successMessage.value = response.data.message || 'Deliverable shared.';
    } catch (error) {
        handleError(error, 'Choose a supported file up to 10 MB.');
    } finally {
        input.value = '';
        isUploading.value = false;
    }
}
</script>

<template>
    <AdminServiceWorkspaceShell :purchase="purchase" active-section="files" :loading="isLoading" :error-message="errorMessage" :success-message="successMessage" :queue-url="queueUrl">
        <section class="admin-panel mt-4 overflow-hidden">
            <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
                <div><h2 class="text-lg font-black text-slate-950">Service files</h2><p class="mt-1 text-sm text-slate-500">Review provider material and share final work in one list.</p></div>
                <label v-if="purchase.fulfillment_status !== 'completed'" class="inline-flex h-10 cursor-pointer items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white"><i class="fa-solid fa-upload text-amber-300" aria-hidden="true"></i>{{ isUploading ? 'Uploading...' : 'Upload deliverable' }}<input type="file" class="sr-only" :disabled="isUploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="uploadDeliverable"></label>
            </header>

            <div v-if="!files.length" class="min-h-96 px-6 py-16 text-center"><i class="fa-regular fa-folder-open text-2xl text-slate-300" aria-hidden="true"></i><p class="mt-3 text-sm font-bold text-slate-900">No files shared yet</p><p class="mt-1 text-sm text-slate-500">Provider uploads and platform deliverables will appear here.</p></div>
            <div v-else class="staff-table-viewport">
                <table class="w-full min-w-[900px] table-fixed border-collapse text-left">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="w-[38%] border-b border-slate-200 px-5 py-3">File</th><th class="w-[24%] border-b border-slate-200 px-5 py-3">Source</th><th class="w-[22%] border-b border-slate-200 px-5 py-3">Shared</th><th class="w-[16%] border-b border-slate-200 px-5 py-3 text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 bg-white"><tr v-for="file in files" :key="file.id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-4"><p class="truncate text-sm font-bold text-slate-950">{{ file.original_name }}</p><p class="mt-1 text-xs text-slate-500">{{ formatAdminServiceFileSize(file.size) }}</p></td>
                        <td class="px-5 py-4"><p class="text-sm font-semibold text-slate-800">{{ sourceLabel(file) }}</p><p class="mt-1 text-xs text-slate-500">{{ file.uploaded_by }}</p></td>
                        <td class="px-5 py-4 text-sm text-slate-600">{{ formatAdminServiceDateTime(file.created_at) }}</td>
                        <td class="px-5 py-4 text-right"><div class="inline-flex gap-2"><button type="button" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-700 hover:border-slate-950" title="Preview file" @click="previewFile = file"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i><span class="sr-only">Preview file</span></button><a :href="file.download_url" class="grid h-9 w-9 place-items-center bg-slate-950 text-white" title="Download file"><i class="fa-solid fa-download text-xs" aria-hidden="true"></i><span class="sr-only">Download file</span></a></div></td>
                    </tr></tbody>
                </table>
            </div>
        </section>
    </AdminServiceWorkspaceShell>
    <FilePreviewModal v-if="previewFile" :file="previewFile" :title="previewFile.original_name" context="Provider service file" @close="previewFile = null" />
</template>
