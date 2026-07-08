<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const updatingId = ref(null);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const applications = ref([]);
const reviewNotes = ref({});
const decisionReasons = ref({});
const awardedAmounts = ref({});
const outcomeNotes = ref({});
const outcomeDates = ref({});
const documentStatuses = ref({});
const documentNotes = ref({});
const documentUpdatingId = ref(null);
const selectedQueueFilter = ref('all');
const selectedQueueSort = ref('priority');

const reviewFilterOptions = computed(() => [
    { value: 'all', label: 'All', count: applications.value.length },
    {
        value: 'pending_review',
        label: 'Pending review',
        count: applications.value.filter((application) => ['submitted', 'under_review'].includes(application.status ?? 'submitted')).length,
    },
    {
        value: 'document_issues',
        label: 'Document issues',
        count: applications.value.filter((application) => documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100).length,
    },
    {
        value: 'strong_candidates',
        label: 'Strong candidates',
        count: applications.value.filter((application) => Number(application.dss_score ?? 0) >= 80 || Number(application.eligibility_score ?? 0) >= 80).length,
    },
]);
const rankedApplications = computed(() => {
    const filteredApplications = applications.value.filter((application) => {
        if (selectedQueueFilter.value === 'pending_review') {
            return ['submitted', 'under_review'].includes(application.status ?? 'submitted');
        }

        if (selectedQueueFilter.value === 'document_issues') {
            return documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100;
        }

        if (selectedQueueFilter.value === 'strong_candidates') {
            return Number(application.dss_score ?? 0) >= 80 || Number(application.eligibility_score ?? 0) >= 80;
        }

        return true;
    });

    return [...filteredApplications].sort((first, second) => {
        if (selectedQueueSort.value === 'dss') {
            return Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
        }

        if (selectedQueueSort.value === 'documents') {
            return documentIssueCount(second) - documentIssueCount(first);
        }

        return reviewPriorityScore(second) - reviewPriorityScore(first) || Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
    });
});
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

function applicantAcademicLabel(applicant) {
    if (!applicant?.gwa) {
        return 'No academic value';
    }

    return applicant.grading_scale === 'grade_point'
        ? `${applicant.gwa} GWA/GPA`
        : `${applicant.gwa}%`;
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

function documentIssueCount(application) {
    return (application.documents ?? []).filter((document) => ['pending', 'needs_replacement', 'rejected'].includes(document.status ?? 'pending')).length;
}

function reviewPriorityScore(application) {
    const status = application.status ?? 'submitted';
    const readiness = Number(application.document_readiness?.percent ?? 0);
    const dssScore = Number(application.dss_score ?? 0);
    const eligibilityScore = Number(application.eligibility_score ?? 0);
    const issues = documentIssueCount(application);
    let score = 0;

    if (status === 'submitted') {
        score += 24;
    }

    if (status === 'under_review') {
        score += 16;
    }

    if (issues > 0) {
        score += Math.min(35, issues * 12);
    }

    if (readiness < 100) {
        score += readiness === 0 ? 22 : 14;
    }

    if (dssScore >= 80 || eligibilityScore >= 80) {
        score += 12;
    }

    if (application.dss_recommendation === 'needs_review') {
        score += 20;
    }

    if (application.dss_recommendation === 'not_recommended') {
        score += 10;
    }

    if (application.can_receive_student_response) {
        score += 18;
    }

    if (!application.review_notes && ['submitted', 'under_review'].includes(status)) {
        score += 5;
    }

    if (['approved', 'awarded', 'not_awarded', 'disbursed', 'renewed', 'rejected'].includes(status) && !application.can_receive_student_response) {
        score -= 25;
    }

    return Math.max(0, score);
}

function reviewPriorityLabel(application) {
    const score = reviewPriorityScore(application);

    if (score >= 60) {
        return 'High priority';
    }

    if (score >= 35) {
        return 'Needs review';
    }

    return 'Routine';
}

function reviewPriorityClass(application) {
    const score = reviewPriorityScore(application);

    if (score >= 60) {
        return 'bg-rose-100 text-rose-800';
    }

    if (score >= 35) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-200 text-slate-700';
}

function reviewReasons(application) {
    const reasons = [];
    const readiness = Number(application.document_readiness?.percent ?? 0);
    const issues = documentIssueCount(application);
    const dssScore = Number(application.dss_score ?? 0);
    const eligibilityScore = Number(application.eligibility_score ?? 0);

    if (['submitted', 'under_review'].includes(application.status ?? 'submitted')) {
        reasons.push('Awaiting provider decision');
    }

    if (issues > 0) {
        reasons.push(`${issues} document ${issues === 1 ? 'issue' : 'issues'}`);
    } else if (readiness < 100) {
        reasons.push(`${readiness}% document readiness`);
    }

    if (dssScore >= 80 || eligibilityScore >= 80) {
        reasons.push('Strong DSS or match signal');
    }

    if (application.dss_recommendation === 'needs_review') {
        reasons.push('DSS asks for manual review');
    }

    if (application.can_receive_student_response) {
        reasons.push('Waiting for applicant response');
    } else if (application.student_response_label) {
        reasons.push(application.student_response_label);
    }

    if (reasons.length === 0) {
        reasons.push('No urgent review flags');
    }

    return reasons.slice(0, 3);
}

function quickActionNote(application, action) {
    if (action === 'under_review') {
        return 'Application moved to provider review.';
    }

    if (action === 'missing_documents') {
        const missingDocuments = application.document_readiness?.missing ?? [];
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

async function applyQuickAction(application, action) {
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

    decisionReasons.value[application.id] = selected.reason;
    reviewNotes.value[application.id] = quickActionNote(application, action);
    await updateStatus(application, selected.status);
}

function labelFromKey(value) {
    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatFileSize(size) {
    if (!size) {
        return '0 KB';
    }

    return `${Math.max(1, Math.round(Number(size) / 1024))} KB`;
}

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/applications/data');

        user.value = response.data.user;
        scholarships.value = response.data.scholarships;
        applications.value = response.data.applications;
        reviewNotes.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.review_notes ?? '']),
        );
        decisionReasons.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.decision_reason ?? '']),
        );
        awardedAmounts.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.awarded_amount ?? '']),
        );
        outcomeNotes.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.outcome_notes ?? '']),
        );
        outcomeDates.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.outcome_at ?? '']),
        );
        documentStatuses.value = Object.fromEntries(
            applications.value.flatMap((application) => (application.documents ?? []).map((document) => [document.id, document.status ?? 'pending'])),
        );
        documentNotes.value = Object.fromEntries(
            applications.value.flatMap((application) => (application.documents ?? []).map((document) => [document.id, document.review_notes ?? ''])),
        );

    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider applications.';
    } finally {
        isLoading.value = false;
    }
}

async function updateStatus(application, status) {
    updatingId.value = application.id;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/applications/${application.id}/status`, {
            status,
            decision_reason: decisionReasons.value[application.id] ?? '',
            review_notes: reviewNotes.value[application.id] ?? '',
            awarded_amount: awardedAmounts.value[application.id] ?? '',
            outcome_notes: outcomeNotes.value[application.id] ?? '',
            outcome_at: outcomeDates.value[application.id] ?? '',
        });

        statusMessage.value = response.data.message ?? 'Application status updated.';
        await loadProviderData();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update application status.';
    } finally {
        updatingId.value = null;
    }
}

async function updateDocumentStatus(application, document) {
    documentUpdatingId.value = document.id;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/documents/${document.id}/status`, {
            status: documentStatuses.value[document.id] ?? 'pending',
            review_notes: documentNotes.value[document.id] ?? '',
        });

        applications.value = applications.value.map((item) => (item.id === application.id ? response.data.application : item));
        statusMessage.value = response.data.message ?? 'Document status updated.';
        await loadProviderData();
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

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Application Review
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Applicant activity queue
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Review submitted applications, document status, and DSS guidance for your programs.
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading application review page...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </div>

                    <details class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm shadow-sm">
                        <summary class="cursor-pointer font-bold text-slate-950">
                            DSS queue guide
                        </summary>
                        <p class="mt-2 leading-5 text-slate-600">
                            DSS helps rank applications, but the provider still makes the final decision.
                        </p>
                    </details>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Review Queue
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Submitted applications
                        </h3>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <a
                                href="/provider/export/applications"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Export CSV
                            </a>
                        </div>

                        <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="filter in reviewFilterOptions"
                                        :key="filter.value"
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueFilter === filter.value
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueFilter = filter.value"
                                    >
                                        {{ filter.label }} ({{ filter.count }})
                                    </button>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'priority'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'priority'"
                                    >
                                        Priority
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'dss'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'dss'"
                                    >
                                        DSS
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'documents'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'documents'"
                                    >
                                        Documents
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applicant records yet. Applications submitted by users will appear here.
                        </div>

                        <div v-else-if="rankedApplications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applications match this review filter.
                        </div>

                        <div v-else class="mt-5 grid gap-4 xl:grid-cols-2">
                            <article
                                v-for="application in rankedApplications"
                                :key="application.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                                            {{ application.scholarship?.title || 'Scholarship' }}
                                        </p>
                                        <h4 class="mt-2 text-lg font-bold text-slate-950">
                                            {{ application.applicant?.name || 'Applicant' }}
                                        </h4>
                                        <p class="mt-1 text-sm text-slate-500">
                                            Submitted {{ application.submitted_at || 'recently' }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', reviewPriorityClass(application)]">
                                            {{ reviewPriorityLabel(application) }}
                                        </span>
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                            {{ statusLabel(application.status) }}
                                        </span>
                                        <span
                                            v-if="application.can_receive_student_response"
                                            class="rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold uppercase text-amber-800"
                                        >
                                            Awaiting response
                                        </span>
                                        <span
                                            v-else-if="application.student_response_status"
                                            :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', responseClass(application.student_response_status)]"
                                        >
                                            {{ application.student_response_label || statusLabel(application.student_response_status) }}
                                        </span>
                                        <a
                                            :href="application.detail_url || `/provider/applications/${application.id}`"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        >
                                            View details
                                        </a>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span
                                        v-for="reason in reviewReasons(application)"
                                        :key="reason"
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200"
                                    >
                                        {{ reason }}
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <p class="font-semibold text-slate-500">
                                            DSS score
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.dss_score ?? 0 }}%
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <p class="font-semibold text-slate-500">
                                            Eligibility match
                                        </p>
                                        <p :class="['mt-1 inline-flex rounded-md px-2 py-1 text-xs font-bold', matchClass(application.eligibility_score)]">
                                            {{ application.eligibility_score ?? 0 }}%
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <p class="font-semibold text-slate-500">
                                            Documents
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.document_readiness?.percent ?? 0 }}% ready
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <p class="font-semibold text-slate-500">
                                            Review stage
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.status_progress?.label || statusLabel(application.status) }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-white p-3">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                        Quick review actions
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'under_review')"
                                        >
                                            Start review
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'missing_documents')"
                                        >
                                            Request documents
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'shortlisted')"
                                        >
                                            Shortlist
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'interview')"
                                        >
                                            Interview
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'approved')"
                                        >
                                            Approve
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'rejected')"
                                        >
                                            Reject
                                        </button>
                                    </div>
                                </div>

                            </article>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
