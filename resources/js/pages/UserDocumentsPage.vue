<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantGuideStrip from '../components/ApplicantGuideStrip.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import TermsAgreement from '../components/TermsAgreement.vue';

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
const documentGuideItems = [
    {
        title: 'Choose type',
        text: 'Select the requirement.',
        icon: 'fa-solid fa-list-check',
    },
    {
        title: 'Upload once',
        text: 'Reuse files later.',
        icon: 'fa-solid fa-cloud-arrow-up',
    },
    {
        title: 'Keep updated',
        text: 'Replace when needed.',
        icon: 'fa-solid fa-rotate',
    },
];

const applicationsWithRequirements = computed(() => applications.value.map((application) => ({
    ...application,
    required_documents: documentRequirements(application.scholarship?.requirements),
})));
const preparedDocumentNames = computed(() => new Set(preparedDocuments.value.map((document) => document.document_name)));
const preparedDocumentTotalPages = computed(() => pageCount(preparedDocuments.value.length, preparedDocumentsPerPage));
const paginatedPreparedDocuments = computed(() => paginateItems(preparedDocuments.value, preparedDocumentsPage.value, preparedDocumentsPerPage));
const applicationTotalPages = computed(() => pageCount(applicationsWithRequirements.value.length, applicationsPerPage));
const paginatedApplications = computed(() => paginateItems(applicationsWithRequirements.value, applicationsPage.value, applicationsPerPage));
const reusableDocumentCount = computed(() => preparedDocuments.value
    .filter((document) => documentReuseCount(document) > 0)
    .length);

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

function handlePreparedFileChange(event) {
    preparedFile.value = event.target.files?.[0] ?? null;
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
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
                <ApplicantPageHeader
                    eyebrow="Documents"
                    title="Prepare files once"
                    description="Upload reusable requirements before applying."
                    icon="fa-solid fa-folder-open"
                    action-href="/dashboard/scholarships"
                    action-label="Find scholarships"
                    secondary-href="/dashboard/applications"
                    secondary-label="View applications"
                />

                <ApplicantGuideStrip class="mt-5" :items="documentGuideItems" />

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

                    <section class="student-card flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex flex-wrap items-center gap-x-7 gap-y-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">Prepared files</p>
                                <p class="mt-1 text-lg font-bold text-slate-950">{{ preparedDocuments.length }}</p>
                            </div>
                            <div class="border-l border-slate-200 pl-7">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">Reusable files</p>
                                <p class="mt-1 text-lg font-bold text-slate-950">{{ reusableDocumentCount }}</p>
                            </div>
                        </div>
                        <a
                            href="/dashboard/scholarships"
                            class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            Find matching programs
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </section>

                    <section class="student-card overflow-hidden">
                        <div class="border-b border-slate-200 bg-slate-50 p-5">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark">
                                    <i class="fa-solid fa-folder-plus text-sm"></i>
                                </span>
                                <div>
                                    <p class="student-kicker">
                                        Library
                                    </p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">
                                        Reusable requirements
                                    </h3>
                                </div>
                            </div>
                        </div>

                        <div class="border-b border-slate-200 bg-white">
                            <div class="flex items-center border-b border-slate-200 px-4 pt-3">
                                <span class="-mb-px inline-flex items-center gap-2 border-b-2 border-slate-900 px-1 pb-3 text-sm font-bold text-slate-950">
                                    <i class="fa-solid fa-upload text-xs"></i>
                                    Upload file
                                </span>
                            </div>

                            <div class="grid gap-3 p-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)_auto] lg:items-end">
                                <label class="min-w-0 flex-1">
                                    <span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Document name</span>
                                    <select
                                        v-model="preparedForm.documentName"
                                        class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
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

                                <label class="min-w-0 cursor-pointer">
                                    <span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">File</span>
                                    <span class="mt-2 flex min-h-[42px] items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50">
                                        <i class="fa-solid fa-paperclip text-xs text-slate-500"></i>
                                        <span :class="['min-w-0 flex-1 truncate font-semibold', preparedFile ? 'text-slate-900' : 'text-slate-400']">
                                            {{ preparedFile?.name || 'Choose file' }}
                                        </span>
                                        <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">
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
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70 lg:min-w-[8.5rem]"
                                    @click="uploadPreparedDocument"
                                >
                                    {{ isUploadingPrepared ? 'Saving...' : preparedDocumentNames.has(preparedForm.documentName) ? 'Update document' : 'Save document' }}
                                </button>
                            </div>

                            <div class="px-4 pb-4">
                                <TermsAgreement
                                    v-model="preparedDocumentTermsAccepted"
                                    context="document"
                                />
                            </div>
                        </div>

                        <div class="p-5">
                            <div v-if="preparedDocuments.length" class="grid gap-2">
                                <div class="mb-1 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="text-xs font-bold text-slate-500">
                                        Showing {{ paginationSummary(preparedDocuments.length, preparedDocumentsPage, preparedDocumentsPerPage) }} documents
                                    </p>
                                    <div v-if="preparedDocumentTotalPages > 1" class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            :disabled="preparedDocumentsPage <= 1"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="setPreparedDocumentsPage(preparedDocumentsPage - 1)"
                                        >
                                            Previous
                                        </button>
                                        <span class="text-xs font-bold text-slate-500">
                                            {{ preparedDocumentsPage }} / {{ preparedDocumentTotalPages }}
                                        </span>
                                        <button
                                            type="button"
                                            :disabled="preparedDocumentsPage >= preparedDocumentTotalPages"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="setPreparedDocumentsPage(preparedDocumentsPage + 1)"
                                        >
                                            Next
                                        </button>
                                    </div>
                                </div>
                                <div
                                    v-for="document in paginatedPreparedDocuments"
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
                                        <p class="mt-1 text-xs font-semibold text-slate-600">
                                            {{ documentReuseLabel(document) }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            @click="openDocumentPreview(document)"
                                        >
                                            View
                                        </button>
                                        <a
                                            :href="document.download_url"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                        >
                                            Download
                                        </a>
                                        <label
                                            :class="[
                                                'rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50',
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
                                            {{ updatingPreparedId === document.id ? 'Updating...' : 'Update' }}
                                        </label>
                                        <button
                                            type="button"
                                            :disabled="removingPreparedId === document.id"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-rose-200 bg-white text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                                            title="Remove document"
                                            aria-label="Remove document"
                                            @click="deletePreparedDocument(document)"
                                        >
                                            <i :class="[removingPreparedId === document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can', 'text-xs']"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="rounded-lg border border-dashed border-slate-300 bg-white p-6">
                                <p class="text-sm font-bold text-slate-900">
                                    No prepared documents yet
                                </p>
                                <p class="mt-1 text-sm leading-6 text-slate-500">
                                    Upload common files like Certificate of Enrollment, grades, school ID, or proof of income so applications are faster later.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section v-if="applications.length === 0" class="student-card p-6">
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">
                                No submitted applications yet
                            </p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Prepared documents will stay here and can be reused once you apply for a scholarship.
                            </p>
                            <a
                                href="/dashboard/scholarships"
                                class="mt-4 inline-flex rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Browse scholarships
                            </a>
                        </div>
                    </section>

                    <section v-else class="grid gap-4">
                        <div class="flex flex-col gap-2 rounded-md border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs font-bold text-slate-500">
                                Showing {{ paginationSummary(applicationsWithRequirements.length, applicationsPage, applicationsPerPage) }} applications
                            </p>
                            <div v-if="applicationTotalPages > 1" class="flex items-center gap-2">
                                <button
                                    type="button"
                                    :disabled="applicationsPage <= 1"
                                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="setApplicationsPage(applicationsPage - 1)"
                                >
                                    Previous
                                </button>
                                <span class="text-xs font-bold text-slate-500">
                                    {{ applicationsPage }} / {{ applicationTotalPages }}
                                </span>
                                <button
                                    type="button"
                                    :disabled="applicationsPage >= applicationTotalPages"
                                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="setApplicationsPage(applicationsPage + 1)"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                        <article
                            v-for="application in paginatedApplications"
                            :key="application.id"
                            class="overflow-hidden rounded-lg border border-slate-200 border-l-4 border-l-slate-900 bg-white shadow-sm"
                        >
                            <div class="p-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="flex min-w-0 gap-3">
                                        <img
                                            :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                            :alt="application.scholarship?.title || 'Scholarship'"
                                            class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
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

                                    <a
                                        :href="application.detail_url || `/dashboard/applications/${application.id}`"
                                        class="w-full rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800 lg:w-auto"
                                    >
                                        View details
                                    </a>
                                </div>

                                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                                    <span :class="['rounded-md px-2.5 py-1 uppercase', readinessClass(application.document_readiness?.uploaded_percent)]">
                                        {{ application.document_readiness?.uploaded_percent ?? 0 }}% uploaded
                                    </span>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-slate-700">
                                        {{ application.document_readiness?.accepted ?? 0 }} accepted
                                    </span>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-slate-700">
                                        {{ application.documents?.length ?? 0 }} files
                                    </span>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-slate-700">
                                        {{ application.required_documents.length }} requirements
                                    </span>
                                </div>
                            </div>
                        </article>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>

        <div
            v-if="previewDocument"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4"
            @click.self="closeDocumentPreview"
        >
            <section class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-950">
                            {{ previewDocument.document_name }}
                        </p>
                        <p class="truncate text-xs text-slate-500">
                            {{ previewDocument.original_name }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <a
                            :href="previewDocument.download_url"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                        >
                            Download
                        </a>
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                            aria-label="Close preview"
                            @click="closeDocumentPreview"
                        >
                            <i class="fa-solid fa-xmark text-sm"></i>
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
