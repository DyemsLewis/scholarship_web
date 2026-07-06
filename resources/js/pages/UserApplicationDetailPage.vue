<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const isLoading = ref(true);
const isUploading = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const application = ref(null);
const uploadForm = ref({ documentName: '' });
const uploadFile = ref(null);
const fileInput = ref(null);
const previewDocument = ref(null);

const requiredDocuments = computed(() => documentRequirements(application.value?.scholarship?.requirements));
const uploadedDocumentNames = computed(() => new Set((application.value?.documents ?? []).map((document) => document.document_name)));
const dssCriteria = computed(() => application.value?.dss_breakdown?.criteria ?? []);
const timeline = computed(() => application.value?.timeline ?? []);
const confirmedDocuments = computed(() => application.value?.document_checklist ?? []);

function statusLabel(status) {
    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function recommendationClass(recommendation) {
    if (recommendation === 'highly_recommended') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (recommendation === 'recommended') {
        return 'bg-slate-100 text-slate-700';
    }

    if (recommendation === 'needs_review') {
        return 'bg-amber-100 text-amber-800';
    }

    if (recommendation === 'not_recommended') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-rose-100 text-rose-800';
}

function matchClass(score) {
    if (Number(score) >= 80) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (Number(score) >= 50) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-rose-100 text-rose-800';
}

function documentStatusClass(status) {
    if (status === 'accepted') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'needs_replacement') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

function criterionClass(status) {
    if (status === 'pass') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    }

    if (status === 'fail') {
        return 'border-rose-200 bg-rose-50 text-rose-800';
    }

    if (status === 'missing') {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    return 'border-slate-200 bg-slate-50 text-slate-600';
}

function stepClass(state) {
    if (state === 'complete') {
        return 'bg-slate-900 text-white';
    }

    if (state === 'current') {
        return 'bg-slate-100 text-slate-950 ring-1 ring-slate-300';
    }

    if (state === 'skipped') {
        return 'bg-rose-50 text-rose-700 ring-1 ring-rose-100';
    }

    return 'bg-white text-slate-500 ring-1 ring-slate-200';
}

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
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function formatFileSize(size) {
    if (!size) {
        return '0 KB';
    }

    return `${Math.max(1, Math.round(Number(size) / 1024))} KB`;
}

function criterionImpact(criterion) {
    const weightedScore = Number(criterion.weighted_score ?? 0);

    if (Number.isFinite(weightedScore) && weightedScore > 0) {
        return `${weightedScore.toFixed(weightedScore % 1 === 0 ? 0 : 1)} pts`;
    }

    const score = Number(criterion.score ?? 0);
    const weight = Number(criterion.weight ?? 0);

    if (!Number.isFinite(score) || !Number.isFinite(weight)) {
        return '0 pts';
    }

    const impact = (score * weight) / 100;

    return `${impact.toFixed(impact % 1 === 0 ? 0 : 1)} pts`;
}

function ensureUploadForm() {
    if (!uploadForm.value.documentName) {
        uploadForm.value.documentName = requiredDocuments.value[0] ?? '';
    }
}

function handleFileChange(event) {
    uploadFile.value = event.target.files?.[0] ?? null;
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

async function loadApplication() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/applications/${applicationId}/data`);

        user.value = response.data.user;
        application.value = response.data.application;
        ensureUploadForm();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load application details.';
    } finally {
        isLoading.value = false;
    }
}

async function uploadDocument() {
    if (!application.value || !uploadForm.value.documentName || !uploadFile.value) {
        errorMessage.value = 'Choose a document type and file before uploading.';
        return;
    }

    isUploading.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', uploadForm.value.documentName);
    payload.append('document_file', uploadFile.value);

    try {
        const response = await window.axios.post(`/dashboard/applications/${application.value.id}/documents`, payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        application.value = response.data.application;
        uploadFile.value = null;
        if (fileInput.value) {
            fileInput.value.value = '';
        }
        ensureUploadForm();
        statusMessage.value = response.data.message ?? 'Document uploaded.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to upload document.';
    } finally {
        isUploading.value = false;
    }
}

async function deleteDocument(document) {
    if (!application.value) {
        return;
    }

    isUploading.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.delete(`/dashboard/documents/${document.id}`);

        application.value = response.data.application;
        if (previewDocument.value?.id === document.id) {
            closeDocumentPreview();
        }
        ensureUploadForm();
        statusMessage.value = response.data.message ?? 'Document removed.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to remove document.';
    } finally {
        isUploading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadApplication);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar @logout="logout" />

        <section class="student-page">
            <div class="student-container">
                <header class="student-hero">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="max-w-2xl">
                            <a href="/dashboard/applications" class="inline-flex w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                Back to applications
                            </a>
                            <p class="student-kicker mt-4">
                                Application Details
                            </p>
                            <h2 class="mt-2 font-display text-2xl font-bold text-slate-950 sm:text-3xl">
                                {{ application?.scholarship?.title || 'Application record' }}
                            </h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ application?.scholarship?.provider?.name || 'Scholarship provider' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading application details...
                </div>

                <div v-else-if="errorMessage && !application" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="application" class="mt-6 space-y-4">
                    <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </div>

                    <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </div>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between">
                            <div class="flex min-w-0 gap-3">
                                <img
                                    :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="application.scholarship?.title || 'Scholarship'"
                                    class="h-14 w-14 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                        Submitted {{ application.submitted_at || 'recently' }}
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        {{ application.scholarship?.title || 'Scholarship' }}
                                    </h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        {{ application.scholarship?.description || 'No description provided.' }}
                                    </p>
                                </div>
                            </div>

                            <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                {{ statusLabel(application.status) }}
                            </span>
                        </div>

                        <div class="grid border-t border-slate-200 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div class="border-b border-slate-200 p-4 sm:border-r lg:border-b-0">
                                <p class="font-semibold text-slate-500">Stage</p>
                                <p class="mt-1 font-bold text-slate-950">{{ application.status_progress?.label || statusLabel(application.status) }}</p>
                            </div>
                            <div class="border-b border-slate-200 p-4 lg:border-r lg:border-b-0">
                                <p class="font-semibold text-slate-500">DSS score</p>
                                <p class="mt-1 font-bold text-slate-950">{{ application.dss_score ?? 0 }}%</p>
                            </div>
                            <div class="border-b border-slate-200 p-4 sm:border-r sm:border-b-0">
                                <p class="font-semibold text-slate-500">Documents</p>
                                <p class="mt-1 font-bold text-slate-950">{{ application.document_readiness?.percent ?? 0 }}% confirmed</p>
                            </div>
                            <div class="p-4">
                                <p class="font-semibold text-slate-500">Match</p>
                                <p class="mt-1 font-bold text-slate-950">{{ application.eligibility_score ?? 0 }}%</p>
                            </div>
                        </div>
                    </section>

                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_21rem]">
                        <div class="space-y-4">
                            <section v-if="application.status_progress" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Review Progress</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            {{ application.status_progress.label }}
                                        </h3>
                                    </div>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ application.status_progress.percent }}% through review
                                    </span>
                                </div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${application.status_progress.percent}%` }"></div>
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-5">
                                    <div
                                        v-for="step in application.status_progress.steps"
                                        :key="step.key"
                                        :class="['rounded-md px-2.5 py-2 text-xs font-bold', stepClass(step.state)]"
                                    >
                                        {{ step.label }}
                                    </div>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ application.status_progress.next_action }}
                                </p>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Decision Support</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            {{ application.dss_score ?? 0 }}% DSS score
                                        </h3>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', recommendationClass(application.dss_recommendation)]">
                                        {{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}
                                    </span>
                                </div>
                                <p class="mt-3 text-sm font-semibold leading-6 text-slate-800">
                                    {{ application.dss_explanation?.headline || application.dss_breakdown?.summary || 'DSS reviewed the current application data.' }}
                                </p>
                                <p class="mt-1 text-sm leading-6 text-slate-600">
                                    {{ application.dss_explanation?.next_action || 'Wait for provider review and document feedback.' }}
                                </p>

                                <div
                                    v-if="application.dss_explanation?.strengths?.length || application.dss_explanation?.needs_attention?.length"
                                    class="mt-4 grid gap-3 md:grid-cols-2"
                                >
                                    <div v-if="application.dss_explanation?.strengths?.length" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                            Strengths
                                        </p>
                                        <ul class="mt-2 space-y-1">
                                            <li
                                                v-for="item in application.dss_explanation.strengths"
                                                :key="item"
                                                class="text-sm leading-6 text-slate-600"
                                            >
                                                {{ item }}
                                            </li>
                                        </ul>
                                    </div>
                                    <div v-if="application.dss_explanation?.needs_attention?.length" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                            Needs attention
                                        </p>
                                        <ul class="mt-2 space-y-1">
                                            <li
                                                v-for="item in application.dss_explanation.needs_attention"
                                                :key="item"
                                                class="text-sm leading-6 text-slate-600"
                                            >
                                                {{ item }}
                                            </li>
                                        </ul>
                                    </div>
                                </div>

                                <details v-if="dssCriteria.length" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <summary class="cursor-pointer text-sm font-bold text-slate-800">
                                        Score breakdown
                                    </summary>
                                    <div class="mt-3 grid gap-2">
                                        <div
                                            v-for="criterion in dssCriteria"
                                            :key="criterion.key"
                                            class="rounded-md border border-slate-200 bg-white p-3 text-sm"
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                                <span class="text-xs font-bold text-slate-600">{{ criterionImpact(criterion) }}</span>
                                            </div>
                                            <p class="mt-1 text-xs font-bold uppercase tracking-[0.1em] text-slate-400">
                                                {{ criterion.score }}% score x {{ criterion.weight }}% weight
                                            </p>
                                            <p class="mt-1 leading-6 text-slate-600">
                                                {{ criterion.note }}
                                            </p>
                                        </div>
                                    </div>
                                </details>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Documents</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            Application files
                                        </h3>
                                    </div>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ application.document_readiness?.uploaded ?? 0 }} uploaded
                                    </span>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-semibold text-slate-700">
                                            Confirmed checklist
                                        </p>
                                        <span class="text-xs font-bold text-slate-500">
                                            {{ application.document_readiness?.percent ?? 0 }}% ready
                                        </span>
                                    </div>
                                    <div v-if="confirmedDocuments.length" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="document in confirmedDocuments"
                                            :key="document"
                                            class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200"
                                        >
                                            {{ document }}
                                        </span>
                                    </div>
                                    <p v-else class="mt-2 text-slate-500">
                                        No checklist items saved.
                                    </p>
                                </div>

                                <div v-if="application.documents?.length" class="mt-4 grid gap-2">
                                    <div
                                        v-for="document in application.documents"
                                        :key="document.id"
                                        class="flex flex-col gap-3 rounded-md border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div class="min-w-0">
                                            <p class="font-bold text-slate-950">
                                                {{ document.document_name }}
                                            </p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ document.original_name }} - {{ formatFileSize(document.size) }} - {{ document.uploaded_at }}
                                            </p>
                                            <p v-if="document.review_notes" class="mt-1 text-xs font-semibold text-slate-600">
                                                {{ document.review_notes }}
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <span :class="['h-fit rounded-md px-2.5 py-2 text-xs font-bold uppercase', documentStatusClass(document.status)]">
                                                {{ labelFromKey(document.status || 'pending') }}
                                            </span>
                                            <button
                                                v-if="document.view_url"
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
                                            <button
                                                type="button"
                                                :disabled="isUploading"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                                aria-label="Remove document"
                                                @click="deleteDocument(document)"
                                            >
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="mt-4 text-sm text-slate-500">
                                    No uploaded files yet.
                                </p>

                                <div class="mt-4 grid gap-3 border-t border-slate-200 pt-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                                    <div>
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            Requirement
                                        </label>
                                        <select
                                            v-if="requiredDocuments.length"
                                            v-model="uploadForm.documentName"
                                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-700 focus:ring-3 focus:ring-slate-100"
                                        >
                                            <option
                                                v-for="requirement in requiredDocuments"
                                                :key="requirement"
                                                :value="requirement"
                                            >
                                                {{ requirement }}{{ uploadedDocumentNames.has(requirement) ? ' (replace)' : '' }}
                                            </option>
                                        </select>
                                        <input
                                            v-else
                                            v-model="uploadForm.documentName"
                                            type="text"
                                            placeholder="Document name"
                                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-700 focus:ring-3 focus:ring-slate-100"
                                        >
                                    </div>

                                    <div>
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            File
                                        </label>
                                        <input
                                            ref="fileInput"
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-900 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white"
                                            @change="handleFileChange"
                                        >
                                    </div>

                                    <button
                                        type="button"
                                        :disabled="isUploading"
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                        @click="uploadDocument"
                                    >
                                        {{ isUploading ? 'Uploading...' : 'Upload' }}
                                    </button>
                                </div>
                            </section>

                            <section v-if="timeline.length" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Timeline</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    Application history
                                </h3>
                                <div class="mt-4 grid gap-2">
                                    <div
                                        v-for="event in timeline"
                                        :key="event.id"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                                    >
                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="font-bold text-slate-950">
                                                {{ statusLabel(event.to_status) }}
                                            </p>
                                            <p class="text-xs text-slate-500">
                                                {{ event.changed_at || 'Recently' }}
                                            </p>
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">
                                            By {{ event.actor || 'System' }}
                                        </p>
                                        <p v-if="event.review_notes" class="mt-2 leading-6 text-slate-600">
                                            {{ event.review_notes }}
                                        </p>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <aside class="space-y-4">
                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Application</p>
                                <div class="mt-3 grid gap-3 text-sm">
                                    <div>
                                        <p class="font-semibold text-slate-500">Applicant</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ user?.name || 'Applicant' }}</p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">Decision reason</p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.decision_reason ? labelFromKey(application.decision_reason) : 'Not set yet' }}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">Application mode</p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ labelFromKey(application.scholarship?.application_mode || 'not listed') }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Eligibility</p>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(application.eligibility_score)]">
                                        {{ application.eligibility_score ?? 0 }}% match
                                    </span>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ application.eligibility_breakdown?.label || 'Needs review' }}
                                    </span>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ application.eligibility_breakdown?.summary || 'Eligibility was checked against the scholarship profile rules.' }}
                                </p>
                                <div v-if="application.eligibility_breakdown?.criteria?.length" class="mt-3 grid gap-2">
                                    <div
                                        v-for="criterion in application.eligibility_breakdown.criteria"
                                        :key="criterion.key"
                                        :class="['rounded-md border px-3 py-2 text-xs font-bold', criterionClass(criterion.status)]"
                                    >
                                        {{ criterion.label }}: {{ criterion.status }}
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Notes</p>
                                <p class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                                    {{ application.notes || 'No applicant note added.' }}
                                </p>

                                <div v-if="application.review_notes" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                    <p class="font-semibold text-slate-700">Provider review note</p>
                                    <p class="mt-1 leading-6 text-slate-600">
                                        {{ application.review_notes }}
                                    </p>
                                </div>
                            </section>

                            <section
                                v-if="application.awarded_amount || application.outcome_notes || application.outcome_at || ['awarded', 'not_awarded', 'disbursed', 'renewed'].includes(application.status)"
                                class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                            >
                                <p class="student-kicker">Outcome</p>
                                <div class="mt-3 grid gap-2 text-sm">
                                    <p class="rounded-md bg-slate-50 px-3 py-2 font-bold text-slate-700 ring-1 ring-slate-200">
                                        Amount: {{ application.awarded_amount || 'Not listed' }}
                                    </p>
                                    <p class="rounded-md bg-slate-50 px-3 py-2 font-bold text-slate-700 ring-1 ring-slate-200">
                                        Date: {{ application.outcome_at || 'Not listed' }}
                                    </p>
                                    <p class="rounded-md bg-slate-50 px-3 py-2 font-bold text-slate-700 ring-1 ring-slate-200">
                                        Status: {{ statusLabel(application.status) }}
                                    </p>
                                </div>
                                <p v-if="application.outcome_notes" class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ application.outcome_notes }}
                                </p>
                            </section>
                        </aside>
                    </div>
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
                <header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
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
