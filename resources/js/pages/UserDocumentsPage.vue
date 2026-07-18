<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { labelFromKey } from '../support/display';

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
const preparedDocumentTermsAccepted = ref(false);
const updatingPreparedId = ref(null);
const removingPreparedId = ref(null);
const previewDocument = ref(null);
const preparedDocumentsPerPage = 5;
const applicationsPerPage = 3;
const preparedDocumentsPage = ref(1);
const applicationsPage = ref(1);
const applicationsWithRequirements = computed(() => applications.value.map((application) => ({
    ...application,
    required_documents: documentRequirements(application.scholarship?.requirements),
})));
const preparedDocumentNames = computed(() => new Set(preparedDocuments.value.map((document) => document.document_name)));
const availableDocumentOptions = computed(() => documentOptions.value
    .filter((option) => !preparedDocumentNames.value.has(option)));
const preparedDocumentTotalPages = computed(() => pageCount(preparedDocuments.value.length, preparedDocumentsPerPage));
const paginatedPreparedDocuments = computed(() => paginateItems(preparedDocuments.value, preparedDocumentsPage.value, preparedDocumentsPerPage));
const applicationTotalPages = computed(() => pageCount(applicationsWithRequirements.value.length, applicationsPerPage));
const paginatedApplications = computed(() => paginateItems(applicationsWithRequirements.value, applicationsPage.value, applicationsPerPage));
const reusableDocumentCount = computed(() => preparedDocuments.value
    .filter((document) => documentReuseCount(document) > 0)
    .length);
const libraryStatusTitle = computed(() => {
    if (stats.value.needs_attention > 0) {
        return `${stats.value.needs_attention} application file${stats.value.needs_attention === 1 ? '' : 's'} need attention`;
    }

    if (preparedDocuments.value.length === 0) {
        return 'Start your reusable document library';
    }

    return `${preparedDocuments.value.length} file${preparedDocuments.value.length === 1 ? '' : 's'} ready to reuse`;
});
const libraryStatusText = computed(() => {
    if (stats.value.needs_attention > 0) {
        return 'Open the related application to replace any rejected or outdated requirement.';
    }

    if (preparedDocuments.value.length === 0) {
        return 'Add common requirements now so future scholarship applications take less time.';
    }

    if (applications.value.length === 0) {
        return 'Your saved files will be available when a scholarship asks for the same requirement.';
    }

    return 'Keep each file current and review application-specific requirements before submitting.';
});

function documentRequirements(requirements) {
    if (!requirements) {
        return [];
    }

    return String(requirements)
        .split(/\r\n|\r|\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function pageCount(total, perPage) {
    return Math.max(1, Math.ceil(Number(total || 0) / perPage));
}

function clampPage(page, totalPages) {
    return Math.min(Math.max(Number(page) || 1, 1), totalPages);
}

function paginateItems(items, page, perPage) {
    const currentPage = clampPage(page, pageCount(items.length, perPage));
    const start = (currentPage - 1) * perPage;

    return items.slice(start, start + perPage);
}

function paginationSummary(total, page, perPage) {
    if (!total) {
        return '0 items';
    }

    const currentPage = clampPage(page, pageCount(total, perPage));
    const start = (currentPage - 1) * perPage + 1;
    const end = Math.min(start + perPage - 1, total);

    return `${start}-${end} of ${total}`;
}

function setPreparedDocumentsPage(page) {
    preparedDocumentsPage.value = clampPage(page, preparedDocumentTotalPages.value);
}

function setApplicationsPage(page) {
    applicationsPage.value = clampPage(page, applicationTotalPages.value);
}

function clampPagination() {
    preparedDocumentsPage.value = clampPage(preparedDocumentsPage.value, preparedDocumentTotalPages.value);
    applicationsPage.value = clampPage(applicationsPage.value, applicationTotalPages.value);
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

function readinessBarClass(percent) {
    const value = Number(percent ?? 0);

    if (value >= 100) {
        return 'bg-emerald-500';
    }

    if (value >= 50) {
        return 'bg-amber-500';
    }

    return 'bg-slate-500';
}

function readinessWidth(percent) {
    return `${Math.min(Math.max(Number(percent) || 0, 0), 100)}%`;
}

function documentReuseCount(document) {
    return applicationsWithRequirements.value
        .filter((application) => application.required_documents.includes(document.document_name))
        .length;
}

function documentReuseLabel(document) {
    const count = documentReuseCount(document);

    if (count === 0) {
        return 'Reusable when a program asks for it';
    }

    return `Reusable for ${count} submitted application${count === 1 ? '' : 's'}`;
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

function fileExtension(filename) {
    const extension = String(filename || '').split('.').pop();

    if (!extension || extension === filename) {
        return 'FILE';
    }

    return extension.slice(0, 4).toUpperCase();
}

function handlePreparedFileChange(event) {
    preparedFile.value = event.target.files?.[0] ?? null;
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

function handlePreviewKeydown(event) {
    if (event.key === 'Escape' && previewDocument.value) {
        closeDocumentPreview();
    }
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
        clampPagination();

        if (!availableDocumentOptions.value.includes(preparedForm.documentName)) {
            preparedForm.documentName = availableDocumentOptions.value[0] ?? '';
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load documents.';
    } finally {
        isLoading.value = false;
    }
}

async function uploadPreparedDocument() {
    if (availableDocumentOptions.value.length === 0) {
        errorMessage.value = 'All listed document types are already saved. Use Update beside a file to replace it.';
        return;
    }

    if (!preparedForm.documentName || !preparedFile.value) {
        errorMessage.value = 'Choose a document name and file before uploading.';
        return;
    }

    if (!preparedDocumentTermsAccepted.value) {
        errorMessage.value = 'Please accept the document upload terms before saving.';
        return;
    }

    isUploadingPrepared.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', preparedForm.documentName);
    payload.append('document_file', preparedFile.value);
    payload.append('terms_accepted', '1');

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

async function updatePreparedDocument(document, event) {
    const file = event.target.files?.[0] ?? null;

    if (!file) {
        return;
    }

    if (!preparedDocumentTermsAccepted.value) {
        errorMessage.value = 'Please accept the document upload terms before updating.';
        event.target.value = '';
        return;
    }

    updatingPreparedId.value = document.id;
    errorMessage.value = '';
    statusMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', document.document_name);
    payload.append('document_file', file);
    payload.append('terms_accepted', '1');

    try {
        await window.axios.post('/dashboard/student-documents', payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        statusMessage.value = 'Prepared document updated.';
        await loadDocuments();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update prepared document.';
    } finally {
        updatingPreparedId.value = null;
        event.target.value = '';
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

onMounted(() => {
    window.addEventListener('keydown', handlePreviewKeydown);
    loadDocuments();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handlePreviewKeydown);
});
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <ApplicantPageHeader
                    eyebrow="Documents"
                    title="Your document library"
                    description="Keep scholarship requirements organized, current, and ready to reuse."
                    icon="fa-solid fa-folder-open"
                    action-href="#upload-document"
                    action-label="Add document"
                    secondary-href="/dashboard/applications"
                    secondary-label="View applications"
                />

                <div v-if="isLoading" class="student-card mt-5 p-6 text-sm text-slate-500">
                    Loading documents...
                </div>

                <div v-else class="mt-5 space-y-5">
                    <div v-if="errorMessage" class="flex items-start gap-3 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                        <i class="fa-solid fa-circle-exclamation mt-0.5" aria-hidden="true"></i>
                        <p>{{ errorMessage }}</p>
                    </div>
                    <div v-if="statusMessage" class="flex items-start gap-3 rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">
                        <i class="fa-solid fa-circle-check mt-0.5" aria-hidden="true"></i>
                        <p>{{ statusMessage }}</p>
                    </div>

                    <section class="student-card overflow-hidden">
                        <div class="grid lg:grid-cols-[minmax(0,1fr)_25rem]">
                            <div class="flex items-start gap-4 p-5">
                                <span class="student-section-mark shrink-0">
                                    <i class="fa-solid fa-list-check text-sm" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="student-kicker">Library status</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">{{ libraryStatusTitle }}</h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">{{ libraryStatusText }}</p>
                                </div>
                            </div>

                            <dl class="grid grid-cols-3 divide-x divide-slate-200 border-t border-slate-200 lg:border-l lg:border-t-0">
                                <div class="px-4 py-5">
                                    <dt class="text-xs font-bold text-slate-500">Saved</dt>
                                    <dd class="mt-1 text-xl font-bold text-slate-950">{{ preparedDocuments.length }}</dd>
                                </div>
                                <div class="px-4 py-5">
                                    <dt class="text-xs font-bold text-slate-500">In use</dt>
                                    <dd class="mt-1 text-xl font-bold text-slate-950">{{ reusableDocumentCount }}</dd>
                                </div>
                                <div class="px-4 py-5">
                                    <dt class="text-xs font-bold text-slate-500">Applications</dt>
                                    <dd class="mt-1 text-xl font-bold text-slate-950">{{ stats.applications }}</dd>
                                </div>
                            </dl>
                        </div>
                    </section>

                    <section class="student-card overflow-hidden">
                        <header class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark shrink-0">
                                    <i class="fa-solid fa-folder-open text-sm" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="student-kicker">Reusable files</p>
                                    <h2 class="mt-1 text-xl font-bold text-slate-950">Document library</h2>
                                </div>
                            </div>
                            <p class="text-sm font-semibold text-slate-500">
                                {{ preparedDocuments.length }} saved file{{ preparedDocuments.length === 1 ? '' : 's' }}
                            </p>
                        </header>

                        <div id="upload-document" class="scroll-mt-5 border-b border-slate-200 bg-slate-50/70">
                            <div class="flex min-h-12 flex-col border-b border-slate-200 px-5 sm:flex-row sm:items-center sm:justify-between">
                                <span class="inline-flex min-h-12 items-center gap-2 border-b-2 border-slate-950 text-sm font-bold text-slate-950">
                                    <i class="fa-solid fa-cloud-arrow-up text-xs" aria-hidden="true"></i>
                                    Add a new file
                                </span>
                                <span class="py-2 text-xs font-semibold text-slate-500">PDF, JPG, PNG, DOC or DOCX up to 5 MB</span>
                            </div>

                            <div v-if="availableDocumentOptions.length" class="grid gap-4 p-5 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)_auto] lg:items-end">
                                <label class="min-w-0 flex-1">
                                    <span class="text-sm font-bold text-slate-700">Requirement type</span>
                                    <select
                                        v-model="preparedForm.documentName"
                                        class="mt-2 min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                    >
                                        <option
                                            v-for="option in availableDocumentOptions"
                                            :key="option"
                                            :value="option"
                                        >
                                            {{ option }}
                                        </option>
                                    </select>
                                </label>

                                <label class="min-w-0 cursor-pointer">
                                    <span class="text-sm font-bold text-slate-700">Choose file</span>
                                    <span class="mt-2 flex min-h-11 items-center gap-2 rounded-md border border-dashed border-slate-400 bg-white px-3 py-2.5 text-sm text-slate-700 transition hover:border-slate-500 hover:bg-slate-50">
                                        <i class="fa-solid fa-paperclip text-xs text-slate-500" aria-hidden="true"></i>
                                        <span :class="['min-w-0 flex-1 truncate font-semibold', preparedFile ? 'text-slate-900' : 'text-slate-400']">
                                            {{ preparedFile?.name || 'Select from your device' }}
                                        </span>
                                        <span class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">
                                            Browse
                                        </span>
                                    </span>
                                    <input
                                        ref="preparedFileInput"
                                        type="file"
                                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                        class="sr-only"
                                        @change="handlePreparedFileChange"
                                    >
                                </label>

                                <button
                                    type="button"
                                    :disabled="isUploadingPrepared"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70 lg:min-w-[9.5rem]"
                                    @click="uploadPreparedDocument"
                                >
                                    <i :class="[isUploadingPrepared ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-upload', 'text-xs']" aria-hidden="true"></i>
                                    {{ isUploadingPrepared ? 'Saving...' : 'Save document' }}
                                </button>
                            </div>

                            <div v-if="availableDocumentOptions.length" class="px-5 pb-5">
                                <TermsAgreement
                                    v-model="preparedDocumentTermsAccepted"
                                    context="document"
                                />
                            </div>
                            <div v-else class="flex items-start gap-3 p-5 text-sm">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-700">
                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="font-bold text-slate-950">All available document types are saved</p>
                                    <p class="mt-1 leading-6 text-slate-500">Use the Update button beside a saved file when you need to replace it.</p>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-base font-bold text-slate-950">Saved files</h3>
                                <p class="mt-1 text-xs text-slate-500">View, download, replace, or remove files from your reusable library.</p>
                            </div>
                            <p v-if="preparedDocuments.length" class="text-xs font-bold text-slate-500">
                                Showing {{ paginationSummary(preparedDocuments.length, preparedDocumentsPage, preparedDocumentsPerPage) }}
                            </p>
                        </div>

                        <div v-if="preparedDocuments.length" class="divide-y divide-slate-200">
                            <article
                                v-for="document in paginatedPreparedDocuments"
                                :key="document.id"
                                class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-xs font-black text-slate-600">
                                        {{ fileExtension(document.original_name) }}
                                    </span>
                                    <div class="min-w-0">
                                        <h4 class="truncate text-sm font-bold text-slate-950">{{ document.document_name }}</h4>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ document.original_name }}</p>
                                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                            <span>{{ formatFileSize(document.size) }}</span>
                                            <span>{{ document.uploaded_at }}</span>
                                            <span class="font-semibold text-slate-700">{{ documentReuseLabel(document) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                        @click="openDocumentPreview(document)"
                                    >
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        View
                                    </button>
                                    <a
                                        :href="document.download_url"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                                        title="Download document"
                                        aria-label="Download document"
                                    >
                                        <i class="fa-solid fa-download text-xs" aria-hidden="true"></i>
                                    </a>
                                    <label
                                        :class="[
                                            'inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-50',
                                            updatingPreparedId === document.id ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                                        ]"
                                    >
                                        <input
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                            class="sr-only"
                                            :disabled="updatingPreparedId === document.id"
                                            @change="updatePreparedDocument(document, $event)"
                                        >
                                        <i :class="[updatingPreparedId === document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-rotate', 'text-xs']" aria-hidden="true"></i>
                                        {{ updatingPreparedId === document.id ? 'Updating...' : 'Update' }}
                                    </label>
                                    <button
                                        type="button"
                                        :disabled="removingPreparedId === document.id"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-rose-200 bg-white text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                                        title="Remove document"
                                        aria-label="Remove document"
                                        @click="deletePreparedDocument(document)"
                                    >
                                        <i :class="[removingPreparedId === document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can', 'text-xs']" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </article>
                        </div>

                        <div v-else class="flex flex-col items-center px-5 py-9 text-center">
                            <span class="grid h-12 w-12 place-items-center rounded-md bg-slate-100 text-slate-500">
                                <i class="fa-regular fa-file-lines text-lg" aria-hidden="true"></i>
                            </span>
                            <h3 class="mt-3 text-sm font-bold text-slate-950">No saved files yet</h3>
                            <p class="mt-1 max-w-xl text-sm leading-6 text-slate-500">
                                Start with a school ID, latest grades, certificate of enrollment, or proof of income.
                            </p>
                            <a href="#upload-document" class="mt-3 text-sm font-bold text-slate-800 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">
                                Choose your first file
                            </a>
                        </div>

                        <footer v-if="preparedDocumentTotalPages > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3">
                            <button
                                type="button"
                                :disabled="preparedDocumentsPage <= 1"
                                class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="setPreparedDocumentsPage(preparedDocumentsPage - 1)"
                            >
                                <i class="fa-solid fa-chevron-left text-[10px]" aria-hidden="true"></i>
                                Previous
                            </button>
                            <span class="text-xs font-bold text-slate-500">Page {{ preparedDocumentsPage }} of {{ preparedDocumentTotalPages }}</span>
                            <button
                                type="button"
                                :disabled="preparedDocumentsPage >= preparedDocumentTotalPages"
                                class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="setPreparedDocumentsPage(preparedDocumentsPage + 1)"
                            >
                                Next
                                <i class="fa-solid fa-chevron-right text-[10px]" aria-hidden="true"></i>
                            </button>
                        </footer>
                    </section>

                    <section class="student-card overflow-hidden">
                        <header class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark shrink-0">
                                    <i class="fa-solid fa-file-circle-check text-sm" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="student-kicker">Submitted applications</p>
                                    <h2 class="mt-1 text-xl font-bold text-slate-950">Application documents</h2>
                                </div>
                            </div>
                            <p class="text-sm font-semibold text-slate-500">
                                {{ applications.length }} application{{ applications.length === 1 ? '' : 's' }}
                            </p>
                        </header>

                        <div v-if="applications.length === 0" class="flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-500">
                                    <i class="fa-solid fa-briefcase" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold text-slate-950">No application files to track yet</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                        Once you submit an application, its document progress and provider review status will appear here.
                                    </p>
                                </div>
                            </div>
                            <a
                                href="/dashboard/scholarships"
                                class="inline-flex shrink-0 items-center gap-2 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Browse scholarships
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </a>
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <article
                                v-for="application in paginatedApplications"
                                :key="application.id"
                                class="grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(15rem,0.6fr)_auto] lg:items-center"
                            >
                                <div class="flex min-w-0 gap-3">
                                    <img
                                        :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                        :alt="application.scholarship?.title || 'Scholarship'"
                                        class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0">
                                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                                            <p class="max-w-full truncate text-xs font-bold text-slate-500">
                                                {{ application.scholarship?.provider?.name || 'Scholarship Provider' }}
                                            </p>
                                            <span class="rounded bg-slate-100 px-2 py-1 text-[11px] font-bold text-slate-600">
                                                {{ labelFromKey(application.status || 'submitted') }}
                                            </span>
                                        </div>
                                        <h3 class="mt-1 truncate text-base font-bold text-slate-950">
                                            {{ application.scholarship?.title || 'Scholarship application' }}
                                        </h3>
                                        <p class="mt-1 text-xs text-slate-500">Submitted {{ application.submitted_at || 'recently' }}</p>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <div class="flex items-center justify-between gap-3 text-xs">
                                        <span class="font-bold text-slate-700">Document readiness</span>
                                        <span :class="['rounded px-2 py-1 font-bold', readinessClass(application.document_readiness?.uploaded_percent)]">
                                            {{ application.document_readiness?.uploaded_percent ?? 0 }}%
                                        </span>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                        <div
                                            :class="['h-full rounded-full', readinessBarClass(application.document_readiness?.uploaded_percent)]"
                                            :style="{ width: readinessWidth(application.document_readiness?.uploaded_percent) }"
                                        ></div>
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500">
                                        {{ application.document_readiness?.uploaded ?? 0 }} of {{ application.document_readiness?.required ?? application.required_documents.length }} uploaded
                                        <span v-if="application.document_readiness?.accepted"> - {{ application.document_readiness.accepted }} accepted</span>
                                    </p>
                                </div>

                                <a
                                    :href="application.detail_url || `/dashboard/applications/${application.id}`"
                                    class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 lg:w-auto"
                                >
                                    <i class="fa-solid fa-eye text-xs" aria-hidden="true"></i>
                                    View details
                                </a>
                            </article>
                        </div>

                        <footer v-if="applicationTotalPages > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3">
                            <button
                                type="button"
                                :disabled="applicationsPage <= 1"
                                class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="setApplicationsPage(applicationsPage - 1)"
                            >
                                <i class="fa-solid fa-chevron-left text-[10px]" aria-hidden="true"></i>
                                Previous
                            </button>
                            <span class="text-xs font-bold text-slate-500">Page {{ applicationsPage }} of {{ applicationTotalPages }}</span>
                            <button
                                type="button"
                                :disabled="applicationsPage >= applicationTotalPages"
                                class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="setApplicationsPage(applicationsPage + 1)"
                            >
                                Next
                                <i class="fa-solid fa-chevron-right text-[10px]" aria-hidden="true"></i>
                            </button>
                        </footer>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>

        <div
            v-if="previewDocument"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="document-preview-title"
            @click.self="closeDocumentPreview"
        >
            <section class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-5">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-[11px] font-black text-slate-600">
                            {{ fileExtension(previewDocument.original_name) }}
                        </span>
                        <div class="min-w-0">
                            <h2 id="document-preview-title" class="truncate text-sm font-bold text-slate-950">
                                {{ previewDocument.document_name }}
                            </h2>
                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                {{ previewDocument.original_name }}
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <a
                            :href="previewDocument.download_url"
                            class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                            Download
                        </a>
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                            aria-label="Close preview"
                            @click="closeDocumentPreview"
                        >
                            <i class="fa-solid fa-xmark text-sm" aria-hidden="true"></i>
                        </button>
                    </div>
                </header>

                <div class="h-[72vh] bg-slate-100">
                    <iframe
                        :src="previewDocument.view_url || previewDocument.download_url"
                        :title="previewDocument.document_name"
                        class="h-full w-full border-0 bg-white"
                    ></iframe>
                </div>
            </section>
        </div>
    </main>
</template>
