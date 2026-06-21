<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const isUploadingPrepared = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const stats = ref({
    applications: 0,
    prepared: 0,
    uploaded: 0,
    accepted: 0,
    pending: 0,
    needs_attention: 0,
});
const applications = ref([]);
const preparedDocuments = ref([]);
const documentOptions = ref([]);
const preparedFile = ref(null);
const preparedFileInput = ref(null);
const preparedForm = reactive({
    documentName: '',
});
const uploadForms = ref({});
const uploadFiles = ref({});
const uploadingId = ref(null);
const removingId = ref(null);
const removingPreparedId = ref(null);

const applicationsWithRequirements = computed(() => applications.value.map((application) => ({
    ...application,
    required_documents: documentRequirements(application.scholarship?.requirements),
})));
const preparedDocumentNames = computed(() => new Set(preparedDocuments.value.map((document) => document.document_name)));

function labelFromKey(value) {
    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function documentRequirements(requirements) {
    if (!requirements) {
        return [];
    }

    return String(requirements)
        .split(/\r\n|\r|\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function uploadedDocumentNames(application) {
    return new Set((application.documents ?? []).map((document) => document.document_name));
}

function applicationDocumentForRequirement(application, requirement) {
    return (application.documents ?? []).find((document) => document.document_name === requirement);
}

function preparedDocumentForRequirement(requirement) {
    return preparedDocuments.value.find((document) => document.document_name === requirement);
}

function missingApplicationDocuments(application) {
    const uploaded = uploadedDocumentNames(application);

    return documentRequirements(application.scholarship?.requirements).filter((requirement) => !uploaded.has(requirement));
}

function ensureUploadForm(application) {
    if (uploadForms.value[application.id]) {
        return;
    }

    const requiredDocuments = documentRequirements(application.scholarship?.requirements);

    uploadForms.value[application.id] = {
        documentName: missingApplicationDocuments(application)[0] ?? requiredDocuments[0] ?? '',
    };
}

function documentStatusClass(status) {
    if (status === 'accepted') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected' || status === 'needs_replacement') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function readinessClass(percent) {
    const value = Number(percent ?? 0);

    if (value >= 100) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (value >= 50) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

function formatFileSize(size) {
    if (!size) {
        return 'Unknown size';
    }

    if (size < 1024 * 1024) {
        return `${Math.round(size / 1024)} KB`;
    }

    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function handlePreparedFileChange(event) {
    preparedFile.value = event.target.files?.[0] ?? null;
}

function handleFileChange(application, event) {
    uploadFiles.value[application.id] = event.target.files?.[0] ?? null;
}

function replaceApplication(updatedApplication) {
    applications.value = applications.value.map((application) => (
        application.id === updatedApplication.id ? updatedApplication : application
    ));
    ensureUploadForm(updatedApplication);
}

async function loadDocuments() {
    isLoading.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/documents/data');

        user.value = response.data.user;
        stats.value = response.data.stats;
        applications.value = response.data.applications;
        preparedDocuments.value = response.data.prepared_documents ?? [];
        documentOptions.value = response.data.document_options ?? [];
        applications.value.forEach(ensureUploadForm);

        if (!preparedForm.documentName && documentOptions.value.length) {
            preparedForm.documentName = documentOptions.value[0];
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load documents.';
    } finally {
        isLoading.value = false;
    }
}

async function uploadPreparedDocument() {
    if (!preparedForm.documentName || !preparedFile.value) {
        errorMessage.value = 'Choose a document name and file before uploading.';
        return;
    }

    isUploadingPrepared.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', preparedForm.documentName);
    payload.append('document_file', preparedFile.value);

    try {
        const response = await window.axios.post('/dashboard/student-documents', payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        statusMessage.value = response.data.message ?? 'Prepared document saved.';
        preparedFile.value = null;

        if (preparedFileInput.value) {
            preparedFileInput.value.value = '';
        }

        await loadDocuments();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to save prepared document.';
    } finally {
        isUploadingPrepared.value = false;
    }
}

async function deletePreparedDocument(document) {
    removingPreparedId.value = document.id;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.delete(`/dashboard/student-documents/${document.id}`);

        statusMessage.value = response.data.message ?? 'Prepared document removed.';
        await loadDocuments();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to remove prepared document.';
    } finally {
        removingPreparedId.value = null;
    }
}

async function uploadDocument(application) {
    const uploadForm = uploadForms.value[application.id];
    const file = uploadFiles.value[application.id];

    if (!uploadForm?.documentName || !file) {
        errorMessage.value = 'Choose a document type and file before uploading.';
        return;
    }

    uploadingId.value = application.id;
    errorMessage.value = '';
    statusMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', uploadForm.documentName);
    payload.append('document_file', file);

    try {
        const response = await window.axios.post(`/dashboard/applications/${application.id}/documents`, payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        replaceApplication(response.data.application);
        uploadFiles.value[application.id] = null;
        statusMessage.value = response.data.message ?? 'Application document uploaded.';
        await refreshStats();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to upload document.';
    } finally {
        uploadingId.value = null;
    }
}

async function deleteDocument(application, document) {
    removingId.value = document.id;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.delete(`/dashboard/documents/${document.id}`);

        replaceApplication(response.data.application);
        statusMessage.value = response.data.message ?? 'Document removed.';
        await refreshStats();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to remove document.';
    } finally {
        removingId.value = null;
    }
}

async function refreshStats() {
    const response = await window.axios.get('/dashboard/documents/data');

    stats.value = response.data.stats;
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadDocuments);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar @logout="logout" />

        <section class="student-page">
            <div class="student-container">
                <header class="student-hero">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-2xl">
                            <p class="student-kicker">
                                Documents
                            </p>
                            <h2 class="mt-2 font-display text-2xl font-bold text-slate-950 sm:text-3xl">
                                Prepare documents before applying
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Upload common requirements once. Scholarship matching will use this library, and matching files are copied into your application when you apply.
                            </p>
                        </div>

                        <a
                            href="/dashboard/scholarships"
                            class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            Find scholarships
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading documents...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <p v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </p>

                    <section class="student-card overflow-hidden">
                        <div class="border-b border-slate-200 bg-[#f6faf8] p-5">
                            <p class="student-kicker">
                                Document Library
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Upload reusable requirements
                            </h3>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Use exact document names from scholarship requirements when possible, so the matching system can recognize them.
                            </p>
                        </div>

                        <div class="grid gap-5 p-5 lg:grid-cols-[0.9fr_1.1fr]">
                            <div class="rounded-lg border border-slate-200 bg-white p-4">
                                <div class="grid gap-3">
                                    <label>
                                        <span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Document name</span>
                                        <select
                                            v-model="preparedForm.documentName"
                                            class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                        >
                                            <option
                                                v-for="option in documentOptions"
                                                :key="option"
                                                :value="option"
                                            >
                                                {{ option }}{{ preparedDocumentNames.has(option) ? ' (replace)' : '' }}
                                            </option>
                                        </select>
                                    </label>

                                    <label>
                                        <span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">File</span>
                                        <input
                                            ref="preparedFileInput"
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                            class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-bold file:text-white hover:file:bg-slate-800"
                                            @change="handlePreparedFileChange"
                                        >
                                    </label>

                                    <button
                                        type="button"
                                        :disabled="isUploadingPrepared"
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                        @click="uploadPreparedDocument"
                                    >
                                        {{ isUploadingPrepared ? 'Saving...' : 'Save prepared document' }}
                                    </button>
                                </div>
                            </div>

                            <div>
                                <div v-if="preparedDocuments.length" class="grid gap-2">
                                    <div
                                        v-for="document in preparedDocuments"
                                        :key="document.id"
                                        class="flex flex-col gap-3 rounded-md border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-950">
                                                {{ document.document_name }}
                                            </p>
                                            <p class="mt-1 truncate text-xs text-slate-500">
                                                {{ document.original_name }} - {{ formatFileSize(document.size) }} - {{ document.uploaded_at }}
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <a
                                                :href="document.download_url"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            >
                                                Download
                                            </a>
                                            <button
                                                type="button"
                                                :disabled="removingPreparedId === document.id"
                                                class="rounded-md border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                                                @click="deletePreparedDocument(document)"
                                            >
                                                {{ removingPreparedId === document.id ? 'Removing...' : 'Remove' }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500">
                                    No prepared documents yet. Add files here before applying so scholarships can show better document readiness.
                                </div>
                            </div>
                        </div>
                    </section>

                    <section v-if="applications.length === 0" class="student-card p-6">
                        <div class="rounded-lg border border-dashed border-slate-300 bg-[#f6faf8] p-6 text-sm text-slate-500">
                            No submitted applications yet. You can still upload prepared documents above.
                        </div>
                    </section>

                    <section v-else class="grid gap-4">
                        <article
                            v-for="application in applicationsWithRequirements"
                            :key="application.id"
                            class="student-card overflow-hidden"
                        >
                            <div class="border-b border-slate-200 bg-[#f6faf8] p-5">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="flex min-w-0 gap-3">
                                        <img
                                            :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                            :alt="application.scholarship?.title || 'Scholarship'"
                                            class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">
                                                {{ application.scholarship?.provider?.name || 'Scholarship Provider' }}
                                            </p>
                                            <h3 class="mt-1 truncate text-lg font-bold text-slate-950">
                                                {{ application.scholarship?.title || 'Scholarship application' }}
                                            </h3>
                                            <p class="mt-1 text-xs text-slate-500">
                                                Submitted {{ application.submitted_at || 'recently' }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', readinessClass(application.document_readiness?.uploaded_percent)]">
                                            {{ application.document_readiness?.uploaded_percent ?? 0 }}% copied
                                        </span>
                                        <span class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                            {{ application.document_readiness?.accepted ?? 0 }} accepted
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-5 p-5 lg:grid-cols-[1fr_0.85fr]">
                                <section>
                                    <p class="student-kicker">
                                        Required Checklist
                                    </p>
                                    <div v-if="application.required_documents.length" class="mt-3 grid gap-2">
                                        <div
                                            v-for="requirement in application.required_documents"
                                            :key="requirement"
                                            class="flex flex-col gap-2 rounded-md border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-bold text-slate-950">
                                                    {{ requirement }}
                                                </p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ applicationDocumentForRequirement(application, requirement)?.original_name || preparedDocumentForRequirement(requirement)?.original_name || 'No matching file yet' }}
                                                </p>
                                            </div>
                                            <span
                                                v-if="applicationDocumentForRequirement(application, requirement)"
                                                :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', documentStatusClass(applicationDocumentForRequirement(application, requirement).status)]"
                                            >
                                                {{ labelFromKey(applicationDocumentForRequirement(application, requirement).status || 'pending') }}
                                            </span>
                                            <span v-else-if="preparedDocumentNames.has(requirement)" class="w-fit rounded-md bg-sky-100 px-2.5 py-1 text-xs font-bold uppercase text-sky-800">
                                                In library
                                            </span>
                                            <span v-else class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase text-slate-600">
                                                Missing
                                            </span>
                                        </div>
                                    </div>
                                    <p v-else class="mt-3 rounded-md border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">
                                        This application has no listed document requirements.
                                    </p>
                                </section>

                                <section>
                                    <p class="student-kicker">
                                        Application Copy
                                    </p>
                                    <div class="mt-3 rounded-md border border-slate-200 bg-white p-4">
                                        <p class="text-sm leading-6 text-slate-600">
                                            Prepared files are copied automatically when you apply. You can still upload or replace an application-specific file here.
                                        </p>
                                        <div class="mt-4 grid gap-3">
                                            <label>
                                                <span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Document type</span>
                                                <select
                                                    v-if="application.required_documents.length"
                                                    v-model="uploadForms[application.id].documentName"
                                                    class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                >
                                                    <option
                                                        v-for="requirement in application.required_documents"
                                                        :key="requirement"
                                                        :value="requirement"
                                                    >
                                                        {{ requirement }}{{ uploadedDocumentNames(application).has(requirement) ? ' (replace)' : '' }}
                                                    </option>
                                                </select>
                                                <input
                                                    v-else
                                                    v-model="uploadForms[application.id].documentName"
                                                    type="text"
                                                    placeholder="Document name"
                                                    class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                >
                                            </label>

                                            <label>
                                                <span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">File</span>
                                                <input
                                                    type="file"
                                                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                                    class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-bold file:text-white hover:file:bg-slate-800"
                                                    @change="handleFileChange(application, $event)"
                                                >
                                            </label>

                                            <button
                                                type="button"
                                                :disabled="uploadingId === application.id"
                                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                                @click="uploadDocument(application)"
                                            >
                                                {{ uploadingId === application.id ? 'Uploading...' : 'Upload to application' }}
                                            </button>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <section class="border-t border-slate-200 p-5">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">
                                            Submitted Files
                                        </p>
                                        <p class="mt-1 text-sm text-slate-500">
                                            These are the copies providers can review for this application.
                                        </p>
                                    </div>
                                    <span class="w-fit rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                        {{ application.documents?.length ?? 0 }} files
                                    </span>
                                </div>

                                <div v-if="application.documents?.length" class="mt-4 grid gap-2">
                                    <div
                                        v-for="document in application.documents"
                                        :key="document.id"
                                        class="flex flex-col gap-3 rounded-md border border-slate-200 bg-[#f6faf8] p-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-950">
                                                {{ document.document_name }}
                                            </p>
                                            <p class="mt-1 truncate text-xs text-slate-500">
                                                {{ document.original_name }} - {{ formatFileSize(document.size) }} - {{ document.uploaded_at }}
                                            </p>
                                            <p v-if="document.review_notes" class="mt-1 text-xs font-semibold text-amber-700">
                                                {{ document.review_notes }}
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <span :class="['h-fit rounded-md px-2.5 py-2 text-xs font-bold uppercase', documentStatusClass(document.status)]">
                                                {{ labelFromKey(document.status || 'pending') }}
                                            </span>
                                            <a
                                                :href="document.download_url"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            >
                                                Download
                                            </a>
                                            <button
                                                type="button"
                                                :disabled="removingId === document.id"
                                                class="rounded-md border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                                                @click="deleteDocument(application, document)"
                                            >
                                                {{ removingId === document.id ? 'Removing...' : 'Remove' }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-[#f6faf8] p-4 text-sm text-slate-500">
                                    No submitted file copies yet.
                                </p>
                            </section>
                        </article>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
