<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const isLoading = ref(true);
const updatingId = ref(null);
const documentUpdatingId = ref(null);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const application = ref(null);
const reviewForm = ref(emptyReviewForm());
const documentStatuses = ref({});
const documentNotes = ref({});
const rubricScores = ref({});

const statusOptions = [
    { value: 'submitted', label: 'Submitted' },
    { value: 'under_review', label: 'Under review' },
    { value: 'qualified', label: 'Qualified' },
    { value: 'shortlisted', label: 'Shortlisted' },
    { value: 'interview', label: 'For interview' },
    { value: 'approved', label: 'Approved' },
    { value: 'awarded', label: 'Awarded' },
    { value: 'not_awarded', label: 'Not awarded' },
    { value: 'disbursed', label: 'Disbursed' },
    { value: 'renewed', label: 'Renewed' },
    { value: 'rejected', label: 'Rejected' },
];
const decisionReasonOptions = [
    { value: '', label: 'No reason selected' },
    { value: 'complete_requirements', label: 'Complete requirements' },
    { value: 'missing_documents', label: 'Missing documents' },
    { value: 'academic_requirement_not_met', label: 'Academic requirement not met' },
    { value: 'outside_eligibility', label: 'Outside eligibility' },
    { value: 'for_interview', label: 'For interview' },
    { value: 'approved_for_award', label: 'Approved for award' },
    { value: 'award_released', label: 'Award released' },
    { value: 'renewed_support', label: 'Renewed support' },
    { value: 'funds_limited', label: 'Funds limited' },
    { value: 'not_selected', label: 'Not selected' },
    { value: 'other', label: 'Other' },
];
const documentStatusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'needs_replacement', label: 'Needs replacement' },
    { value: 'rejected', label: 'Rejected' },
];
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100';
const labelClass = 'mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500';

const dssCriteria = computed(() => application.value?.dss_breakdown?.criteria ?? []);
const rubricReview = computed(() => application.value?.rubric_review ?? { criteria: [], completed: 0, total_criteria: 0 });
const timeline = computed(() => application.value?.timeline ?? []);
const confirmedDocuments = computed(() => application.value?.document_checklist ?? []);
const contractSnapshotSections = computed(() => {
    const snapshot = application.value?.provider_contract_terms_snapshot ?? {};
    const scholarship = application.value?.scholarship ?? {};

    return [
        { label: 'Return service contract', value: snapshot.return_service_contract ?? scholarship.return_service_contract },
        { label: 'Other contract terms', value: snapshot.other_contract_terms ?? scholarship.other_contract_terms },
        { label: 'Renewal / continuation', value: snapshot.renewal_policy ?? scholarship.renewal_policy },
    ].filter((section) => section.value && String(section.value).trim());
});

function emptyReviewForm() {
    return {
        status: 'submitted',
        decisionReason: '',
        awardedAmount: '',
        outcomeNotes: '',
        outcomeAt: '',
        reviewNotes: '',
    };
}

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
        return 'bg-slate-100 text-slate-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function responseClass(status) {
    if (status === 'accepted') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'declined') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
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

function recommendationClass(recommendation) {
    if (recommendation === 'highly_recommended') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (recommendation === 'recommended') {
        return 'bg-slate-100 text-slate-800';
    }

    if (recommendation === 'needs_review') {
        return 'bg-amber-100 text-amber-800';
    }

    if (recommendation === 'not_recommended') {
        return 'bg-slate-200 text-slate-700';
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

function applicantAcademicLabel(applicant) {
    if (!applicant?.gwa) {
        return 'No academic value';
    }

    return applicant.grading_scale === 'grade_point'
        ? `${applicant.gwa} GWA/GPA`
        : `${applicant.gwa}%`;
}

function formatFileSize(size) {
    if (!size) {
        return '0 KB';
    }

    return `${Math.max(1, Math.round(Number(size) / 1024))} KB`;
}

function applyApplication(payload) {
    application.value = payload;
    reviewForm.value = {
        status: payload?.status ?? 'submitted',
        decisionReason: payload?.decision_reason ?? '',
        awardedAmount: payload?.awarded_amount ?? '',
        outcomeNotes: payload?.outcome_notes ?? '',
        outcomeAt: payload?.outcome_at ?? '',
        reviewNotes: payload?.review_notes ?? '',
    };
    documentStatuses.value = Object.fromEntries(
        (payload?.documents ?? []).map((document) => [document.id, document.status ?? 'pending']),
    );
    documentNotes.value = Object.fromEntries(
        (payload?.documents ?? []).map((document) => [document.id, document.review_notes ?? '']),
    );
    rubricScores.value = Object.fromEntries(
        (payload?.rubric_review?.criteria ?? []).map((criterion) => [criterion.key, criterion.score ?? '']),
    );
}

function quickActionNote(action) {
    if (action === 'under_review') {
        return 'Application moved to provider review.';
    }

    if (action === 'missing_documents') {
        const missingDocuments = application.value?.document_readiness?.missing ?? [];
        const missingList = missingDocuments.length ? missingDocuments.slice(0, 3).join(', ') : 'remaining listed documents';

        return `Please upload or replace ${missingList}.`;
    }

    if (action === 'shortlisted') {
        return 'Applicant shortlisted for the next review step.';
    }

    if (action === 'interview') {
        return 'Applicant selected for interview or follow-up screening.';
    }

    if (action === 'approved') {
        return 'Application approved after provider review.';
    }

    if (action === 'rejected') {
        return 'Application was not selected after provider review.';
    }

    return '';
}

async function applyQuickAction(action) {
    const map = {
        under_review: { status: 'under_review', reason: '' },
        missing_documents: { status: 'under_review', reason: 'missing_documents' },
        shortlisted: { status: 'shortlisted', reason: 'complete_requirements' },
        interview: { status: 'interview', reason: 'for_interview' },
        approved: { status: 'approved', reason: 'approved_for_award' },
        rejected: { status: 'rejected', reason: 'not_selected' },
    };
    const selected = map[action];

    if (!selected) {
        return;
    }

    reviewForm.value.status = selected.status;
    reviewForm.value.decisionReason = selected.reason;
    reviewForm.value.reviewNotes = quickActionNote(action);
    await updateStatus();
}

async function loadApplication() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/applications/${applicationId}/data`);

        user.value = response.data.user;
        applyApplication(response.data.application);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load application details.';
    } finally {
        isLoading.value = false;
    }
}

async function updateStatus() {
    if (!application.value) {
        return;
    }

    if (['rejected', 'not_awarded'].includes(reviewForm.value.status) && !reviewForm.value.decisionReason) {
        errorMessage.value = 'Select a decision reason before saving a negative decision.';
        return;
    }

    updatingId.value = application.value.id;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const completedRubricScores = Object.fromEntries(
            Object.entries(rubricScores.value).filter(([, score]) => score !== '' && score !== null),
        );
        const response = await window.axios.patch(`/provider/applications/${application.value.id}/status`, {
            status: reviewForm.value.status,
            decision_reason: reviewForm.value.decisionReason,
            review_notes: reviewForm.value.reviewNotes,
            awarded_amount: reviewForm.value.awardedAmount,
            outcome_notes: reviewForm.value.outcomeNotes,
            outcome_at: reviewForm.value.outcomeAt,
            rubric_scores: completedRubricScores,
        });

        applyApplication(response.data.application);
        statusMessage.value = response.data.message ?? 'Application status updated.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update application status.';
    } finally {
        updatingId.value = null;
    }
}

async function updateDocumentStatus(document) {
    if (!application.value) {
        return;
    }

    const documentStatus = documentStatuses.value[document.id] ?? 'pending';
    const documentNote = documentNotes.value[document.id]?.trim() ?? '';

    if (['rejected', 'needs_replacement'].includes(documentStatus) && !documentNote) {
        errorMessage.value = 'Add a document note explaining why the file was rejected or needs replacement.';
        return;
    }

    documentUpdatingId.value = document.id;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/documents/${document.id}/status`, {
            status: documentStatus,
            review_notes: documentNote,
        });

        applyApplication(response.data.application);
        statusMessage.value = response.data.message ?? 'Document status updated.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update document status.';
    } finally {
        documentUpdatingId.value = null;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadApplication);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-3xl">
                            <a href="/provider/applications" class="inline-flex w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                Back to applications
                            </a>
                            <p class="mt-4 text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Application Details
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ application?.applicant?.name || 'Applicant record' }}
                            </h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ application?.scholarship?.title || 'Scholarship program' }}
                            </p>
                        </div>
                        <span v-if="application" :class="['w-fit rounded-md px-3 py-2 text-xs font-bold uppercase', statusClass(application.status)]">
                            {{ statusLabel(application.status) }}
                        </span>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading application details...
                </div>

                <div v-else-if="errorMessage && !application" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="application" class="mt-6 space-y-5">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <p v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </p>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                                    Submitted {{ application.submitted_at || 'recently' }}
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    {{ application.applicant?.name || 'Applicant' }}
                                </h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">
                                    {{ application.applicant?.email || 'Email not provided' }} - {{ application.applicant?.contact_number || 'Contact not provided' }}
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', recommendationClass(application.dss_recommendation)]">
                                    {{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}
                                </span>
                                <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', matchClass(application.eligibility_score)]">
                                    {{ application.eligibility_score ?? 0 }}% match
                                </span>
                            </div>
                        </div>

                        <div class="grid border-t border-slate-200 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div class="border-b border-slate-200 p-4 sm:border-r lg:border-b-0">
                                <p class="font-semibold text-slate-500">Suitability</p>
                                <p class="mt-1 font-bold text-slate-950">{{ application.dss_score ?? 0 }}%</p>
                            </div>
                            <div class="border-b border-slate-200 p-4 lg:border-r lg:border-b-0">
                                <p class="font-semibold text-slate-500">Documents</p>
                                <p class="mt-1 font-bold text-slate-950">{{ application.document_readiness?.percent ?? 0 }}% ready</p>
                            </div>
                            <div class="border-b border-slate-200 p-4 sm:border-r sm:border-b-0">
                                <p class="font-semibold text-slate-500">Stage</p>
                                <p class="mt-1 font-bold text-slate-950">{{ application.status_progress?.label || statusLabel(application.status) }}</p>
                            </div>
                            <div class="p-4">
                                <p class="font-semibold text-slate-500">Scholarship</p>
                                <p class="mt-1 font-bold text-slate-950">{{ application.scholarship?.title || 'Program' }}</p>
                            </div>
                        </div>
                    </section>

                    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <div class="space-y-5">
                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Provider Review
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Decision and notes
                                        </h3>
                                    </div>
                                    <button
                                        type="button"
                                        :disabled="updatingId === application.id"
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                        @click="updateStatus"
                                    >
                                        {{ updatingId === application.id ? 'Saving...' : 'Save review' }}
                                    </button>
                                </div>

                                <div class="mt-5 grid gap-3 md:grid-cols-2">
                                    <div class="md:col-span-2 rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            Quick actions
                                        </p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                :disabled="updatingId === application.id"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="applyQuickAction('under_review')"
                                            >
                                                Start review
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="updatingId === application.id"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="applyQuickAction('missing_documents')"
                                            >
                                                Request documents
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="updatingId === application.id"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="applyQuickAction('shortlisted')"
                                            >
                                                Shortlist
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="updatingId === application.id"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="applyQuickAction('interview')"
                                            >
                                                Interview
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="updatingId === application.id"
                                                class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="applyQuickAction('approved')"
                                            >
                                                Approve
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="updatingId === application.id"
                                                class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="applyQuickAction('rejected')"
                                            >
                                                Reject
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label :class="labelClass">Pipeline status</label>
                                        <select v-model="reviewForm.status" :class="inputClass">
                                            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label :class="labelClass">
                                            Decision reason <span v-if="['rejected', 'not_awarded'].includes(reviewForm.status)" class="text-rose-600">*</span>
                                        </label>
                                        <select v-model="reviewForm.decisionReason" :class="inputClass">
                                            <option v-for="option in decisionReasonOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label :class="labelClass">Awarded amount</label>
                                        <input v-model="reviewForm.awardedAmount" type="number" min="0" step="0.01" placeholder="Optional" :class="inputClass">
                                    </div>
                                    <div>
                                        <label :class="labelClass">Outcome date</label>
                                        <input v-model="reviewForm.outcomeAt" type="date" :class="inputClass">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label :class="labelClass">Outcome note</label>
                                        <input v-model="reviewForm.outcomeNotes" type="text" maxlength="2000" placeholder="Award, release, renewal, or closure note" :class="inputClass">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label :class="labelClass">Review note</label>
                                        <textarea v-model="reviewForm.reviewNotes" rows="4" maxlength="1500" placeholder="Example: Missing proof of income, qualified for interview, or approved for final review." :class="inputClass"></textarea>
                                    </div>
                                </div>
                            </section>

                            <section v-if="rubricReview.criteria?.length" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Review Rubric
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Consistent applicant scoring
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Score each criterion from 0 to 100. The weighted total appears when all criteria are complete.
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-slate-100 px-3 py-2 text-right">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                            Provider score
                                        </p>
                                        <p class="mt-1 text-lg font-bold text-slate-950">
                                            {{ rubricReview.total_score !== null ? `${rubricReview.total_score}%` : `${rubricReview.completed}/${rubricReview.total_criteria}` }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3">
                                    <div
                                        v-for="criterion in rubricReview.criteria"
                                        :key="criterion.key"
                                        class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_7rem] sm:items-center"
                                    >
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                                <span class="rounded bg-white px-2 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">
                                                    {{ criterion.weight }}%
                                                </span>
                                            </div>
                                            <p v-if="criterion.guidance" class="mt-1 text-xs leading-5 text-slate-500">
                                                {{ criterion.guidance }}
                                            </p>
                                        </div>
                                        <div>
                                            <label :for="`rubric-score-${criterion.key}`" class="sr-only">
                                                {{ criterion.label }} score
                                            </label>
                                            <input
                                                :id="`rubric-score-${criterion.key}`"
                                                v-model.number="rubricScores[criterion.key]"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="1"
                                                placeholder="0-100"
                                                :class="inputClass"
                                            >
                                        </div>
                                    </div>
                                </div>

                                <p class="mt-3 text-xs leading-5 text-slate-500">
                                    {{ rubricReview.decision_notice }} Save review above to keep these scores.
                                </p>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Decision Support
                                </p>
                                <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="text-xl font-bold text-slate-950">
                                            {{ application.dss_score ?? 0 }}% suitability
                                        </h3>
                                        <p class="mt-2 text-sm font-semibold leading-6 text-slate-800">
                                            {{ application.dss_explanation?.headline || application.dss_breakdown?.summary || 'DSS reviewed the current application data.' }}
                                        </p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            {{ application.dss_explanation?.next_action || 'Review eligibility, documents, and notes before deciding.' }}
                                        </p>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', recommendationClass(application.dss_recommendation)]">
                                        {{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}
                                    </span>
                                </div>

                                <div v-if="application.dss_explanation?.strengths?.length || application.dss_explanation?.needs_attention?.length" class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Strengths</p>
                                        <div class="mt-2 grid gap-2">
                                            <p v-for="item in application.dss_explanation?.strengths ?? []" :key="item" class="text-sm leading-6 text-slate-600">
                                                {{ item }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Needs attention</p>
                                        <div class="mt-2 grid gap-2">
                                            <p v-for="item in application.dss_explanation?.needs_attention ?? []" :key="item" class="text-sm leading-6 text-slate-600">
                                                {{ item }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="dssCriteria.length" class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div v-for="criterion in dssCriteria" :key="criterion.key" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                            <p class="text-xs font-bold text-slate-500">{{ criterion.weight }}%</p>
                                        </div>
                                        <p class="mt-2 text-xs font-bold uppercase tracking-[0.12em] text-slate-600">
                                            Score {{ criterion.score }}%
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            {{ criterion.note }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Documents
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Uploaded files and checklist
                                        </h3>
                                    </div>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ application.document_readiness?.uploaded ?? 0 }} uploaded
                                    </span>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-semibold text-slate-700">Confirmed checklist</p>
                                        <span class="text-xs font-bold text-slate-500">{{ application.document_readiness?.percent ?? 0 }}% ready</span>
                                    </div>
                                    <div v-if="confirmedDocuments.length" class="mt-2 flex flex-wrap gap-2">
                                        <span v-for="document in confirmedDocuments" :key="document" class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                            {{ document }}
                                        </span>
                                    </div>
                                    <p v-else class="mt-2 text-slate-500">No checklist items saved.</p>
                                </div>

                                <div v-if="application.documents?.length" class="mt-4 grid gap-3">
                                    <div v-for="document in application.documents" :key="document.id" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                            <div class="min-w-0">
                                                <p class="font-bold text-slate-950">{{ document.document_name }}</p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ document.original_name }} - {{ formatFileSize(document.size) }} - {{ document.uploaded_at }}
                                                </p>
                                            </div>
                                            <span :class="['h-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', documentStatusClass(document.status)]">
                                                {{ labelFromKey(document.status || 'pending') }}
                                            </span>
                                        </div>

                                        <div class="mt-3 grid gap-3 lg:grid-cols-[12rem_1fr_auto] lg:items-end">
                                            <div>
                                                <label :class="labelClass">Status</label>
                                                <select v-model="documentStatuses[document.id]" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100">
                                                    <option v-for="option in documentStatusOptions" :key="option.value" :value="option.value">
                                                        {{ option.label }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div>
                                                <label :class="labelClass">
                                                    Document note <span v-if="['rejected', 'needs_replacement'].includes(documentStatuses[document.id])" class="text-rose-600">*</span>
                                                </label>
                                                <input v-model="documentNotes[document.id]" type="text" maxlength="1000" placeholder="Explain missing, unclear, or invalid document details" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 outline-none transition focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100">
                                            </div>
                                            <div class="flex gap-2">
                                                <a :href="document.download_url" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-white">
                                                    Download
                                                </a>
                                                <button type="button" :disabled="documentUpdatingId === document.id" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:opacity-60" @click="updateDocumentStatus(document)">
                                                    {{ documentUpdatingId === document.id ? 'Saving...' : 'Save' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="mt-4 text-sm text-slate-500">
                                    No uploaded files yet.
                                </p>
                            </section>

                            <section v-if="timeline.length" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Timeline
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Review history
                                </h3>
                                <div class="mt-4 grid gap-2">
                                    <div v-for="event in timeline" :key="event.id" class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="font-bold text-slate-950">{{ statusLabel(event.to_status) }}</p>
                                            <p class="text-xs text-slate-500">{{ event.changed_at || 'Recently' }}</p>
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">
                                            By {{ event.actor || 'System' }}
                                            <span v-if="event.decision_reason"> - {{ labelFromKey(event.decision_reason) }}</span>
                                        </p>
                                        <p v-if="event.review_notes" class="mt-2 leading-6 text-slate-600">
                                            {{ event.review_notes }}
                                        </p>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <aside class="space-y-5">
                            <section v-if="application.status_progress" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Progress
                                </p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">
                                    {{ application.status_progress.label }}
                                </h3>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${application.status_progress.percent}%` }"></div>
                                </div>
                                <div class="mt-3 grid gap-2">
                                    <div v-for="step in application.status_progress.steps" :key="step.key" :class="['rounded-md px-2.5 py-2 text-xs font-bold', stepClass(step.state)]">
                                        {{ step.label }}
                                    </div>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ application.status_progress.next_action }}
                                </p>
                            </section>

                            <section
                                v-if="application.requires_student_response || application.student_response_status"
                                class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
                            >
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Applicant Response
                                </p>
                                <div class="mt-3">
                                    <span
                                        v-if="application.student_response_status"
                                        :class="['inline-flex rounded-md px-2.5 py-1 text-xs font-bold uppercase', responseClass(application.student_response_status)]"
                                    >
                                        {{ application.student_response_label || statusLabel(application.student_response_status) }}
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold uppercase text-amber-800"
                                    >
                                        Waiting for applicant
                                    </span>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    <template v-if="application.student_response_status">
                                        Recorded {{ application.student_responded_at || 'recently' }}.
                                    </template>
                                    <template v-else>
                                        The applicant can accept or decline from their application detail page.
                                    </template>
                                </p>
                                <p v-if="application.student_response_note" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                                    {{ application.student_response_note }}
                                </p>
                            </section>

                            <section
                                v-if="application.provider_contract_terms_accepted_at || contractSnapshotSections.length"
                                class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
                            >
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Contract Acceptance
                                </p>
                                <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                    <p class="font-bold text-slate-950">
                                        {{ application.provider_contract_terms_accepted_at ? 'Accepted by applicant' : 'Acceptance not recorded' }}
                                    </p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">
                                        {{ application.provider_contract_terms_accepted_at || 'This application was created before provider contract snapshots were tracked.' }}
                                    </p>
                                    <p v-if="application.provider_contract_terms_version" class="mt-1 text-xs leading-5 text-slate-500">
                                        Snapshot {{ application.provider_contract_terms_version }}
                                    </p>
                                </div>
                                <div v-if="contractSnapshotSections.length" class="mt-3 grid gap-2">
                                    <div
                                        v-for="section in contractSnapshotSections"
                                        :key="section.label"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                                    >
                                        <p class="font-bold text-slate-800">
                                            {{ section.label }}
                                        </p>
                                        <p class="mt-1 whitespace-pre-line leading-6 text-slate-600">
                                            {{ section.value }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Applicant
                                </p>
                                <div class="mt-3 grid gap-3 text-sm">
                                    <div>
                                        <p class="font-semibold text-slate-500">Education level</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.education_level || 'not_set') }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ application.applicant?.course_or_strand || 'Track not set' }} - {{ application.applicant?.year_level || 'Grade/year not set' }}</p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">School</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ application.applicant?.school || 'Not provided' }}</p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">Academic / income</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ applicantAcademicLabel(application.applicant) }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ application.applicant?.income_bracket || 'No income data' }}</p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">Location</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ application.applicant?.location || 'Not provided' }}</p>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Context
                                </p>
                                <div class="mt-3 grid gap-2 text-sm">
                                    <p class="rounded-md bg-slate-50 p-3 leading-6 text-slate-600">
                                        <span class="font-bold text-slate-800">Support needs:</span>
                                        {{ application.applicant?.support_needs || 'Not provided' }}
                                    </p>
                                    <p class="rounded-md bg-slate-50 p-3 leading-6 text-slate-600">
                                        <span class="font-bold text-slate-800">Goal:</span>
                                        {{ application.applicant?.scholarship_goal || 'Not provided' }}
                                    </p>
                                    <p class="rounded-md bg-slate-50 p-3 leading-6 text-slate-600">
                                        <span class="font-bold text-slate-800">Preferred locations:</span>
                                        {{ application.applicant?.preferred_locations || 'Not provided' }}
                                    </p>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Notes
                                </p>
                                <p class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                                    {{ application.notes || 'No applicant note added.' }}
                                </p>
                                <div v-if="application.review_notes" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                    <p class="font-semibold text-slate-700">Provider review note</p>
                                    <p class="mt-1 leading-6 text-slate-600">{{ application.review_notes }}</p>
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
