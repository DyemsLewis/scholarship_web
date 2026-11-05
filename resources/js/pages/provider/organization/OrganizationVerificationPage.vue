<script setup>
import WorkspaceTextSummary from '../../../components/workspace-dashboard/WorkspaceTextSummary.vue';
import { computed, onMounted, reactive, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import ProviderActionDialog from '../../../components/ProviderActionDialog.vue';
import { labelFromKey } from '../../../support/display';
import { providerProofUploadType } from '../../../support/providerVerification.js';

const isLoading = ref(true);
const isSaving = ref(false);
const isUploading = ref(false);
const deletingId = ref(null);
const errorMessage = ref('');
const errors = ref({});
const profile = ref(null);
const documents = ref([]);
const fileInput = ref(null);
const selectedFile = ref(null);
const documentType = ref('organization_registration');
const termsAccepted = ref(false);
const deleteTarget = ref(null);
const deleteError = ref('');
const replacementTarget = ref(null);
const form = reactive({ legal_name: '', registration_authority: '', registration_number: '', registration_date: '' });
const fieldClass = 'mt-2 h-11 w-full border border-slate-300 bg-white px-3.5 text-sm text-slate-900 outline-none focus:border-slate-950';
const labelClass = 'text-sm font-bold text-slate-800';

const canUpload = computed(() => selectedFile.value && termsAccepted.value && !isUploading.value);

function firstError(field) {
    return errors.value?.[field]?.[0] ?? '';
}

function verificationClass(status) {
    if (status === 'approved') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (status === 'rejected') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (status === 'pending') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function documentStatusClass(status) {
    if (status === 'approved') return 'bg-emerald-100 text-emerald-800';
    if (status === 'rejected') return 'bg-rose-100 text-rose-800';
    return 'bg-amber-100 text-amber-800';
}

function statusLabel(status) {
    return status === 'approved' ? 'Approved' : status === 'rejected' ? 'Changes requested' : 'Under review';
}

function fileSize(bytes) {
    const size = Number(bytes || 0);
    if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} KB`;
    return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

function applyResponse(data) {
    profile.value = data.profile;
    documents.value = data.verification_documents ?? [];
    const values = data.profile?.values ?? {};
    for (const key of Object.keys(form)) form[key] = values[key] ?? '';
}

async function loadVerification() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/organization-profile/data');
        applyResponse(response.data);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The legal verification record could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

async function saveLegalDetails() {
    if (!profile.value || isLoading.value || isSaving.value) return;
    isSaving.value = true;
    errors.value = {};
    errorMessage.value = '';
    try {
        await window.axios.patch('/provider/workspaces/organization-profile/legal', form);
        await loadVerification();
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        errorMessage.value = error.response?.data?.message || 'The legal details could not be saved.';
    } finally {
        isSaving.value = false;
    }
}

function chooseFile(event) {
    selectedFile.value = event.target.files?.[0] ?? null;
}

async function uploadProof() {
    if (!canUpload.value) return;
    isUploading.value = true;
    errorMessage.value = '';
    const payload = new FormData();
    payload.append('document_type', documentType.value);
    payload.append('document_file', selectedFile.value);
    payload.append('terms_accepted', '1');
    if (replacementTarget.value) {
        payload.append('replaces_document_id', replacementTarget.value.id);
        payload.append('expected_revision', replacementTarget.value.revision);
    }
    try {
        await window.axios.post('/provider/verification-documents', payload, { headers: { 'Content-Type': 'multipart/form-data' } });
        selectedFile.value = null;
        replacementTarget.value = null;
        termsAccepted.value = false;
        if (fileInput.value) fileInput.value.value = '';
        await loadVerification();
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The verification proof could not be uploaded.';
    } finally {
        isUploading.value = false;
    }
}

function requestDelete(document) {
    deleteError.value = '';
    deleteTarget.value = document;
}

function replaceDocument(document) {
    if (isUploading.value) return;
    replacementTarget.value = document;
    documentType.value = providerProofUploadType(document.document_type);
    selectedFile.value = null;
    termsAccepted.value = false;
    if (fileInput.value) { fileInput.value.value = ''; fileInput.value.scrollIntoView({ block: 'center', behavior: 'smooth' }); fileInput.value.focus({ preventScroll: true }); }
}

function cancelDelete() {
    if (deletingId.value !== null) return;
    deleteTarget.value = null;
    deleteError.value = '';
}

async function deleteDocument() {
    const document = deleteTarget.value;
    if (!document || deletingId.value !== null) return;
    deletingId.value = document.id;
    deleteError.value = '';
    try {
        await window.axios.delete(`/provider/verification-documents/${document.id}`);
        deleteTarget.value = null;
        await loadVerification();
    } catch (error) {
        deleteError.value = error.response?.data?.message || 'The verification proof could not be removed.';
    } finally {
        deletingId.value = null;
    }
}

onMounted(loadVerification);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="organization-verification-title">
            <div class="provider-settings-workspace mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-building-shield" aria-hidden="true"></i></span><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Organization profile</p><h1 id="organization-verification-title" class="settings-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Legal verification</h1><p class="mt-1 text-sm leading-6 text-slate-600">Maintain the registered organization details and supporting proof.</p></div></div>
                        <span v-if="profile" :class="['inline-flex border px-3 py-2 text-xs font-bold uppercase tracking-wide', verificationClass(profile.verification_status)]">{{ profile.verification_status_label }}</span>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading legal verification...</p></div>

                <template v-else-if="profile">
                    <section v-if="profile?.verification_status === 'rejected'" class="mt-4 border border-rose-200 border-l-4 border-l-rose-600 bg-rose-50 px-5 py-4">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-rose-700">Administrator requested changes</p>
                        <WorkspaceTextSummary  class="mt-2 text-sm font-semibold text-rose-900" :text="profile.verification_notes || 'Review the legal details and replace the rejected proof.'" label="details" />
                    </section>

                    <form class="mt-4 border border-slate-200 bg-white shadow-sm" @submit.prevent="saveLegalDetails">
                        <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-5"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-id-card" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Registered organization</h2><p class="mt-1 text-sm text-slate-500">Enter details exactly as they appear on the registration document.</p></div></div><button type="submit" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"><i :class="['fa-solid', isSaving ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>{{ isSaving ? 'Saving...' : 'Save legal details' }}</button></header>
                        <div class="grid grid-cols-3 gap-5 px-6 py-6">
                            <label class="col-span-2"><span :class="labelClass">Legal organization name</span><input v-model="form.legal_name" type="text" :class="fieldClass"><span v-if="firstError('legal_name')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('legal_name') }}</span></label>
                            <label><span :class="labelClass">Registration authority</span><input v-model="form.registration_authority" type="text" :class="fieldClass" placeholder="SEC, DTI, DepEd"><span v-if="firstError('registration_authority')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('registration_authority') }}</span></label>
                            <label class="col-span-2"><span :class="labelClass">Registration number</span><input v-model="form.registration_number" type="text" :class="fieldClass"><span v-if="firstError('registration_number')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('registration_number') }}</span></label>
                            <label><span :class="labelClass">Registration date <span class="font-normal text-slate-600">(optional)</span></span><input v-model="form.registration_date" type="date" :class="fieldClass"><span v-if="firstError('registration_date')" class="mt-1 block text-xs font-semibold text-rose-700">{{ firstError('registration_date') }}</span></label>
                        </div>
                    </form>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="verification-proof-title">
                        <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div><h2 id="verification-proof-title" class="text-lg font-bold text-slate-950">Verification proof</h2><p class="mt-1 text-sm text-slate-500">Each file is reviewed separately. Accepted files do not verify the account until an administrator records the final decision.</p></div></header>
                        <form class="border-b border-slate-200 bg-slate-50 px-6 py-5" @submit.prevent="uploadProof">
                            <p v-if="replacementTarget" class="mb-4 flex items-center justify-between gap-3 text-sm text-slate-700"><span>Replacing <strong>{{ replacementTarget.original_name }}</strong>. The new file needs its own review.</span><button type="button" :disabled="isUploading" class="shrink-0 text-xs font-bold underline underline-offset-2" @click="replacementTarget = null">Cancel replacement</button></p>
                            <div class="grid grid-cols-[minmax(14rem,1fr)_minmax(0,1.5fr)_auto] items-end gap-4">
                                <label><span :class="labelClass">Document type</span><select v-model="documentType" :class="fieldClass"><option value="organization_registration">Organization registration</option><option value="authorization_letter">Authorization letter</option><option value="valid_id">Authorized representative ID</option><option value="school_or_office_proof">School or office proof</option><option value="other">Other supporting proof</option></select></label>
                                <label><span :class="labelClass">File</span><input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="mt-2 block h-11 w-full border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-xs file:font-bold" @change="chooseFile"></label>
                                <button type="submit" :disabled="!canUpload" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40"><i :class="['fa-solid', isUploading ? 'fa-spinner fa-spin' : 'fa-cloud-arrow-up']" aria-hidden="true"></i>{{ isUploading ? 'Uploading...' : 'Upload proof' }}</button>
                            </div>
                            <label class="mt-4 flex items-start gap-3 text-sm text-slate-600"><input v-model="termsAccepted" type="checkbox" class="mt-0.5 h-4 w-4 accent-slate-950"><span>I confirm this file is authorized, accurate, and may be reviewed for organization verification.</span></label>
                        </form>

                        <div v-if="!documents.length" class="px-6 py-14 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i></span><p class="mt-3 text-sm font-bold text-slate-800">No verification proof uploaded</p><p class="mt-1 text-xs text-slate-500">Add the organization registration or another accepted record.</p></div>
                        <div v-else class="staff-table-viewport">
                            <table class="w-full min-w-[56rem] table-fixed border-collapse text-left">
                                <colgroup><col><col class="w-36"><col class="w-40"><col class="w-32"><col class="w-48"></colgroup>
                                <caption class="sr-only">Organization verification documents</caption>
                                <thead class="bg-white"><tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-[0.16em] text-slate-600"><th class="px-5 py-3">Document</th><th class="px-5 py-3">Type</th><th class="px-5 py-3">Review status</th><th class="px-5 py-3">Uploaded</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                                <tbody class="divide-y divide-slate-200">
                                    <tr v-for="document in documents" :key="document.id" class="align-middle hover:bg-slate-50/70">
                                        <td class="px-5 py-4"><p class="settings-text text-sm font-bold text-slate-950">{{ document.original_name }}</p><p class="mt-1 text-xs text-slate-500">{{ fileSize(document.size) }}</p></td>
                                        <td class="px-5 py-4 text-sm text-slate-700">{{ labelFromKey(document.document_type) }}</td>
                                        <td class="px-5 py-4"><span :class="['inline-flex px-2.5 py-1 text-xs font-bold uppercase tracking-wide', documentStatusClass(document.status)]">{{ statusLabel(document.status) }}</span><p v-if="document.reviewed_at" class="mt-1 text-xs text-slate-500">Reviewed {{ document.reviewed_at }}</p><WorkspaceTextSummary v-if="document.review_notes" class="mt-2 text-xs text-slate-600" :text="document.review_notes" label="review note" /></td>
                                        <td class="px-5 py-4 text-xs font-semibold text-slate-600">{{ document.uploaded_at }}</td>
                                        <td class="px-5 py-4"><div class="flex justify-end gap-2"><a :href="document.view_url" target="_blank" rel="noopener" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600 hover:border-slate-950" aria-label="View document"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a><a :href="document.download_url" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600 hover:border-slate-950" aria-label="Download document"><i class="fa-solid fa-download text-xs" aria-hidden="true"></i></a><button type="button" :disabled="isUploading" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600 disabled:opacity-40" :aria-label="`Replace ${document.original_name}`" title="Replace document" @click="replaceDocument(document)"><i class="fa-solid fa-file-arrow-up text-xs" aria-hidden="true"></i></button><button type="button" :disabled="deletingId === document.id" class="grid h-9 w-9 place-items-center border border-rose-200 text-rose-700 hover:bg-rose-50 disabled:opacity-40" aria-label="Remove document" @click="requestDelete(document)"><i class="fa-solid fa-trash text-xs" aria-hidden="true"></i></button></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>
            </div>
        </section>
        <ProviderActionDialog :open="Boolean(deleteTarget)" title="Remove verification proof?" :message="deleteTarget ? `Remove ${deleteTarget.original_name} from the organization verification record?` : ''" confirm-label="Remove proof" :busy="deletingId !== null" :error="deleteError" danger @confirm="deleteDocument" @cancel="cancelDelete" />
    </main>
</template>
