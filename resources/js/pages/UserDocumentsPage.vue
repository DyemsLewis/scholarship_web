<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { labelFromKey } from '../support/display';

const isLoading = ref(true);
const uploadingPreparedName = ref('');
const errorMessage = ref('');
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
const preparedDocumentTermsAccepted = ref(false);
const updatingPreparedId = ref(null);
const removingPreparedId = ref(null);
const previewDocument = ref(null);
const applicationsPerPage = 3;
const applicationsPage = ref(1);
const documentDescriptions = {
    'Latest report card or grades': 'Your latest available school grades or report card.',
    'Certificate of enrollment': 'Current proof that you are enrolled in school.',
    'School ID': 'A clear copy or photo of your current school ID.',
    'Proof of income': 'A recent income certificate, payslip, or similar household income proof.',
    'Certificate of indigency': 'A current certificate issued by your barangay or local office.',
    'Birth certificate': 'A clear copy of your birth certificate.',
    'Parent or guardian valid ID': 'Useful when a parent or guardian manages a younger applicant.',
    'Transcript of records': 'Your official transcript or latest certificate of grades for college-level applications.',
    'Good moral certificate': 'A current certificate of good moral character issued by your school.',
    'Barangay certificate of residency': 'Proof of your current residence issued by your barangay.',
    'Government-issued ID': 'A clear copy of your valid government-issued identification.',
    'Recent 2x2 ID photo': 'A recent, clear 2x2 identification photo with a plain background.',
    'Admission or acceptance letter': 'Proof that a school, college, university, or training institution accepted you.',
};
const applicationsWithRequirements = computed(() => applications.value.map((application) => ({
    ...application,
    required_documents: documentRequirements(application.scholarship?.requirements),
})));
const preparedDocumentNames = computed(() => new Set(preparedDocuments.value.map((document) => document.document_name)));
const preparedDocumentsByName = computed(() => new Map(
    preparedDocuments.value.map((document) => [document.document_name, document]),
));
const commonDocumentRows = computed(() => documentOptions.value.map((name) => ({
    name,
    description: documentDescriptions[name] ?? 'A reusable document commonly requested by scholarship providers.',
    document: preparedDocumentsByName.value.get(name) ?? null,
})));
const otherPreparedDocuments = computed(() => preparedDocuments.value
    .filter((document) => !documentOptions.value.includes(document.document_name)));
const commonDocumentReadyCount = computed(() => documentOptions.value
    .filter((option) => preparedDocumentNames.value.has(option))
    .length);
const applicationTotalPages = computed(() => pageCount(applicationsWithRequirements.value.length, applicationsPerPage));
const paginatedApplications = computed(() => paginateItems(applicationsWithRequirements.value, applicationsPage.value, applicationsPerPage));
const libraryStatusTitle = computed(() => {
    if (stats.value.needs_attention > 0) {
        return `${stats.value.needs_attention} application file${stats.value.needs_attention === 1 ? '' : 's'} need attention`;
    }

    if (commonDocumentReadyCount.value === 0) {
        return 'Prepare your most commonly requested files';
    }

    return `${commonDocumentReadyCount.value} of ${documentOptions.value.length} common files ready`;
});
const libraryStatusText = computed(() => {
    if (stats.value.needs_attention > 0) {
        return 'Open the related application to replace any rejected or outdated requirement.';
    }

    return 'Upload reusable documents here. Provider forms, essays, and other scholarship-specific papers are uploaded inside the application that requests them.';
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

function setApplicationsPage(page) {
    applicationsPage.value = clampPage(page, applicationTotalPages.value);
}

function clampPagination() {
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

    try {
        const response = await window.axios.get('/dashboard/documents/data');

        stats.value = response.data.stats;
        applications.value = response.data.applications;
        preparedDocuments.value = response.data.prepared_documents ?? [];
        documentOptions.value = response.data.document_options ?? [];
        clampPagination();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load documents.';
    } finally {
        isLoading.value = false;
    }
}

async function uploadPreparedDocument(documentName, event) {
    const file = event.target.files?.[0] ?? null;

    if (!file) {
        return;
    }

    if (!preparedDocumentTermsAccepted.value) {
        errorMessage.value = 'Please accept the document upload terms before saving.';
        event.target.value = '';
        return;
    }

    uploadingPreparedName.value = documentName;
    errorMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', documentName);
    payload.append('document_file', file);
    payload.append('terms_accepted', '1');

    try {
        await window.axios.post('/dashboard/student-documents', payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        await loadDocuments();
    } catch (handledError) {
        void handledError;
    } finally {
        uploadingPreparedName.value = '';
        event.target.value = '';
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

        await loadDocuments();
    } catch (handledError) {
        void handledError;
    } finally {
        updatingPreparedId.value = null;
        event.target.value = '';
    }
}

async function deletePreparedDocument(document) {
    removingPreparedId.value = document.id;
    errorMessage.value = '';

    try {
        await window.axios.delete(`/dashboard/student-documents/${document.id}`);

        await loadDocuments();
    } catch (handledError) {
        void handledError;
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
                    title="Prepare common documents"
                    description="Upload files commonly requested by scholarships. Provider-specific papers are added inside the application that requests them."
                    icon="fa-solid fa-folder-open"
                    action-href="#upload-document"
                    action-label="Prepare files"
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
                    <section class="student-card p-5">
                        <div class="flex items-start gap-4">
                            <span class="student-section-mark shrink-0">
                                <i class="fa-solid fa-list-check text-sm" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="student-kicker">Document readiness</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">{{ libraryStatusTitle }}</h2>
                                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ libraryStatusText }}</p>
                            </div>
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
                                    <h2 class="mt-1 text-xl font-bold text-slate-950">Common documents</h2>
                                </div>
                            </div>
                            <p class="text-sm font-semibold text-slate-500">
                                {{ commonDocumentReadyCount }} of {{ documentOptions.length }} ready
                            </p>
                        </header>

                        <div id="upload-document" class="scroll-mt-5 border-b border-slate-200 bg-slate-50 px-5 py-4">
                            <div class="flex items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-sm font-bold text-slate-950">Upload common files once</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        These files can be reused when a scholarship asks for the same requirement. Provider forms, essays, recommendation templates, and special certificates belong in that scholarship's application.
                                    </p>
                                    <p class="mt-1 text-xs font-semibold text-slate-500">Accepted: PDF, JPG, PNG, DOC or DOCX up to 5 MB.</p>
                                </div>
                            </div>
                        </div>

                        <div class="border-b border-slate-200 px-5 py-4">
                            <TermsAgreement
                                v-model="preparedDocumentTermsAccepted"
                                context="document"
                            />
                            <p class="mt-2 text-xs text-slate-500">Agree once, then click Upload beside the file you want to prepare.</p>
                        </div>

                        <div class="divide-y divide-slate-200">
                            <article
                                v-for="row in commonDocumentRows"
                                :key="row.name"
                                class="flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-center lg:justify-between"
                            >
                                <div class="flex min-w-0 items-start gap-3">
                                    <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-md text-sm', row.document ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-500']">
                                        <i :class="row.document ? 'fa-solid fa-file-circle-check' : 'fa-regular fa-file-lines'" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-sm font-bold text-slate-950">{{ row.name }}</h3>
                                            <span :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase', row.document ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500']">
                                                {{ row.document ? 'Ready' : 'Not uploaded' }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ row.description }}</p>
                                        <div v-if="row.document" class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                                            <span class="max-w-xs truncate">{{ row.document.original_name }}</span>
                                            <span>{{ formatFileSize(row.document.size) }}</span>
                                            <span class="font-semibold text-slate-700">{{ documentReuseLabel(row.document) }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-2 lg:justify-end">
                                    <button
                                        v-if="row.document"
                                        type="button"
                                        class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                        @click="openDocumentPreview(row.document)"
                                    >
                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        View
                                    </button>
                                    <label
                                        :class="[
                                            'inline-flex h-9 cursor-pointer items-center gap-2 rounded-md px-3 text-xs font-bold transition',
                                            row.document ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' : 'bg-slate-900 text-white hover:bg-slate-800',
                                            !preparedDocumentTermsAccepted || uploadingPreparedName === row.name || updatingPreparedId === row.document?.id ? 'pointer-events-none opacity-60' : '',
                                        ]"
                                    >
                                        <input
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                            class="sr-only"
                                            :disabled="!preparedDocumentTermsAccepted || uploadingPreparedName === row.name || updatingPreparedId === row.document?.id"
                                            @change="row.document ? updatePreparedDocument(row.document, $event) : uploadPreparedDocument(row.name, $event)"
                                        >
                                        <i :class="[
                                            uploadingPreparedName === row.name || updatingPreparedId === row.document?.id ? 'fa-solid fa-spinner fa-spin' : (row.document ? 'fa-solid fa-rotate' : 'fa-solid fa-upload'),
                                        ]" aria-hidden="true"></i>
                                        {{ uploadingPreparedName === row.name || updatingPreparedId === row.document?.id ? 'Saving...' : (row.document ? 'Replace' : 'Upload') }}
                                    </label>
                                    <button
                                        v-if="row.document"
                                        type="button"
                                        :disabled="removingPreparedId === row.document.id"
                                        class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-rose-200 bg-white text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                                        :aria-label="`Remove ${row.name}`"
                                        @click="deletePreparedDocument(row.document)"
                                    >
                                        <i :class="[removingPreparedId === row.document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can', 'text-xs']" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </article>
                        </div>

                        <div v-if="otherPreparedDocuments.length" class="border-t border-slate-200">
                            <div class="bg-slate-50 px-5 py-3">
                                <h3 class="text-sm font-bold text-slate-900">Other saved files</h3>
                                <p class="mt-1 text-xs text-slate-500">Existing files that are not part of the common list remain available here.</p>
                            </div>
                            <div class="divide-y divide-slate-200">
                                <article
                                    v-for="document in otherPreparedDocuments"
                                    :key="document.id"
                                    class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-[11px] font-black text-slate-600">
                                            {{ fileExtension(document.original_name) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-950">{{ document.document_name }}</p>
                                            <p class="mt-1 truncate text-xs text-slate-500">{{ document.original_name }}</p>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700" @click="openDocumentPreview(document)">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                            View
                                        </button>
                                        <label :class="['inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700', !preparedDocumentTermsAccepted || updatingPreparedId === document.id ? 'pointer-events-none opacity-60' : '']">
                                            <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="sr-only" :disabled="!preparedDocumentTermsAccepted || updatingPreparedId === document.id" @change="updatePreparedDocument(document, $event)">
                                            <i :class="[updatingPreparedId === document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-rotate', 'text-xs']" aria-hidden="true"></i>
                                            Replace
                                        </label>
                                        <button type="button" :disabled="removingPreparedId === document.id" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-rose-200 bg-white text-rose-700 disabled:opacity-60" :aria-label="`Remove ${document.document_name}`" @click="deletePreparedDocument(document)">
                                            <i :class="[removingPreparedId === document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can', 'text-xs']" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </article>
                            </div>
                        </div>
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
