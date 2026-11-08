<script setup>
import { computed, ref } from 'vue';
import { formatServiceDateTime, formatServiceFileSize, useProviderServiceWorkspace } from '../../../composables/useProviderServiceWorkspace.js';
import BillingServiceWorkspaceShell from './components/BillingServiceWorkspaceShell.vue';

const {
    purchaseId,
    purchase,
    workspace,
    isLoading,
    errorMessage,
    successMessage,
    canModify,
    directoryUrl,
    applyPurchase,
    actionError,
} = useProviderServiceWorkspace('files');

const isUploading = ref(false);
const files = computed(() => purchase.value?.files ?? []);

function fileType(file) {
    return file.category === 'deliverable' ? 'Platform deliverable' : 'Your supporting file';
}

async function uploadFile(event) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file || !canModify.value || isLoading.value || isUploading.value) return;
    isUploading.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    const data = new FormData();
    data.append('service_file', file);

    try {
        const response = await window.axios.post(`/provider/billing/${purchaseId}/files`, data);
        applyPurchase(response.data.purchase);
        successMessage.value = response.data.message || 'Supporting file uploaded.';
    } catch (error) {
        actionError(error, 'Choose a supported file up to 10 MB.');
    } finally {
        input.value = '';
        isUploading.value = false;
    }
}
</script>

<template>
    <BillingServiceWorkspaceShell
        :purchase="purchase"
        :workspace="workspace"
        active-section="files"
        :loading="isLoading"
        :error-message="errorMessage"
        :success-message="successMessage"
        :directory-url="directoryUrl"
    >
        <section class="mt-4 border border-slate-200 bg-white shadow-sm">
            <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
                <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-regular fa-folder-open" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">{{ workspace.filesTitle }}</h2><p class="mt-1 text-xs text-slate-500">{{ workspace.filesDescription }}</p></div></div>
                <label v-if="canModify" class="inline-flex h-10 cursor-pointer items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">
                    <i :class="['fa-solid text-amber-300', isUploading ? 'fa-spinner fa-spin' : 'fa-upload']" aria-hidden="true"></i>
                    {{ isUploading ? 'Uploading...' : 'Upload file' }}
                    <input type="file" class="sr-only" :disabled="isUploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="uploadFile">
                </label>
            </header>

            <div v-if="!files.length" class="px-6 py-14 text-center">
                <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-regular fa-folder-open" aria-hidden="true"></i></span>
                <p class="mt-3 text-sm font-bold text-slate-800">No files have been shared.</p>
                <p class="mt-1 text-xs text-slate-500">Supporting files and platform deliverables will appear together here.</p>
            </div>
            <div v-else class="staff-table-viewport staff-table-viewport--compact">
                <table class="w-full min-w-[56rem] table-fixed border-collapse text-left">
                    <colgroup><col><col class="w-52"><col class="w-44"><col class="w-36"></colgroup>
                    <thead class="bg-slate-50 text-xs font-bold uppercase tracking-[0.16em] text-slate-600">
                        <tr><th class="px-5 py-3">File</th><th class="px-5 py-3">Source</th><th class="px-5 py-3">Shared</th><th class="px-5 py-3 text-right">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="file in files" :key="file.id" class="hover:bg-slate-50/70">
                            <td class="px-5 py-4"><p class="service-text text-sm font-bold text-slate-950">{{ file.original_name }}</p><p class="mt-1 text-xs text-slate-500">{{ formatServiceFileSize(file.size) }}</p></td>
                            <td class="px-5 py-4"><p class="text-sm font-semibold text-slate-800">{{ fileType(file) }}</p><p class="mt-1 text-xs text-slate-500">{{ file.uploaded_by }}</p></td>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ formatServiceDateTime(file.created_at) }}</td>
                            <td class="px-5 py-4 text-right"><div class="inline-flex gap-2"><a :href="file.view_url" data-file-preview="true" :data-file-name="file.original_name" class="staff-row-action h-9 w-9" title="Preview file" :aria-label="`Preview ${file.original_name}`"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i><span class="sr-only">Preview file</span></a><a :href="file.download_url" class="staff-row-action staff-row-action--primary h-9 w-9" title="Download file" :aria-label="`Download ${file.original_name}`"><i class="fa-solid fa-download text-xs" aria-hidden="true"></i><span class="sr-only">Download file</span></a></div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </BillingServiceWorkspaceShell>
</template>
