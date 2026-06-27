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
    { value: 'gwa_not_met', label: 'GWA not met' },
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
const dssFormula = [
    { label: 'Eligibility match', weight: '35%', detail: 'Structured fit against program rules.' },
    { label: 'Documents', weight: '25%', detail: 'Confirmed, uploaded, and accepted files.' },
    { label: 'Academic merit', weight: '20%', detail: 'GWA or average against the minimum.' },
    { label: 'Financial need', weight: '15%', detail: 'Income bracket support priority.' },
    { label: 'Review status', weight: '5%', detail: 'Pipeline progress and decision reason.' },
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
        return 'bg-sky-100 text-sky-800';
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
        return 'bg-sky-100 text-sky-800';
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

    return 'bg-sky-100 text-sky-800';
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

    if (!application.review_notes && ['submitted', 'under_review'].includes(status)) {
        score += 5;
    }

    if (['approved', 'awarded', 'not_awarded', 'disbursed', 'renewed', 'rejected'].includes(status)) {
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

    if (reasons.length === 0) {
        reasons.push('No urgent review flags');
    }

    return reasons.slice(0, 3);
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
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Application Review
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Applicant activity queue
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Review applications submitted through the applicant wizard.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Provider
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.provider_name || user?.name || 'Provider' }}
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

                    <section class="rounded-lg border border-indigo-100 bg-white p-6 shadow-sm">
                        <div>
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-700">
                                    Decision Support Formula
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Queue ranking guide
                                </h3>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    Applications are sorted by DSS score to help reviewers prioritize complete and eligible records. Final decisions still require provider review.
                                </p>
                            </div>
                        </div>
                        <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div
                                v-for="item in dssFormula"
                                :key="item.label"
                                class="rounded-md border border-slate-200 bg-slate-50 p-3"
                            >
                                <p class="text-sm font-bold text-slate-950">
                                    {{ item.label }}
                                </p>
                                <p class="mt-1 text-xs font-bold uppercase tracking-[0.12em] text-indigo-700">
                                    Weight {{ item.weight }}
                                </p>
                                <p class="mt-2 text-xs leading-5 text-slate-500">
                                    {{ item.detail }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Review Queue
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Submitted applications
                        </h3>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm leading-6 text-slate-600">
                                Move applications through the review pipeline and export records when needed.
                            </p>
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
                                                ? 'border-sky-900 bg-sky-900 text-white'
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
                                                ? 'border-sky-900 bg-sky-900 text-white'
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
                                                ? 'border-sky-900 bg-sky-900 text-white'
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
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-700">
                                            {{ application.scholarship?.title || 'Scholarship' }}
                                        </p>
                                        <h4 class="mt-2 text-lg font-bold text-slate-950">
                                            {{ application.applicant?.name || 'Applicant' }}
                                        </h4>
                                        <p class="mt-1 text-sm text-slate-500">
                                            Submitted {{ application.submitted_at || 'recently' }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', reviewPriorityClass(application)]">
                                            {{ reviewPriorityLabel(application) }}
                                        </span>
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                            {{ statusLabel(application.status) }}
                                        </span>
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
                                    <div class="rounded-md border border-indigo-100 bg-indigo-50 p-3">
                                        <p class="font-semibold text-indigo-800">
                                            DSS recommendation
                                        </p>
                                        <div class="mt-2 flex flex-wrap items-center gap-2">
                                            <span class="rounded-md bg-white px-2.5 py-1 text-xs font-bold uppercase text-indigo-800 ring-1 ring-indigo-100">
                                                DSS {{ application.dss_score ?? 0 }}%
                                            </span>
                                            <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', recommendationClass(application.dss_recommendation)]">
                                                {{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}
                                            </span>
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-indigo-900">
                                            {{ application.dss_breakdown?.summary || 'Decision support score based on applicant data.' }}
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Eligibility match
                                        </p>
                                        <p :class="['mt-1 inline-flex rounded-md px-2 py-1 text-xs font-bold', matchClass(application.eligibility_score)]">
                                            {{ application.eligibility_score ?? 0 }}%
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Education level
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ labelFromKey(application.applicant?.education_level || 'not_set') }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ application.applicant?.course_or_strand || 'Track not set' }} - {{ application.applicant?.year_level || 'Grade/year not set' }}
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            GWA / income
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.applicant?.gwa || 'No GWA' }} - {{ application.applicant?.income_bracket || 'No income data' }}
                                        </p>
                                        <p v-if="application.applicant?.household_size" class="mt-1 text-xs text-slate-500">
                                            Household size: {{ application.applicant.household_size }}
                                        </p>
                                    </div>
                                </div>

                                <div
                                    v-if="application.applicant?.support_needs || application.applicant?.scholarship_goal || application.applicant?.preferred_categories || application.applicant?.preferred_locations"
                                    class="mt-4 rounded-md bg-white p-3 text-sm"
                                >
                                    <p class="font-semibold text-slate-500">
                                        Student support context
                                    </p>
                                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                        <p v-if="application.applicant?.preferred_categories" class="rounded-md bg-slate-50 p-2 text-xs leading-5 text-slate-600">
                                            <span class="font-bold text-slate-800">Preferred types:</span> {{ application.applicant.preferred_categories }}
                                        </p>
                                        <p v-if="application.applicant?.preferred_locations" class="rounded-md bg-slate-50 p-2 text-xs leading-5 text-slate-600">
                                            <span class="font-bold text-slate-800">Preferred locations:</span> {{ application.applicant.preferred_locations }}
                                        </p>
                                        <p v-if="application.applicant?.support_needs" class="rounded-md bg-slate-50 p-2 text-xs leading-5 text-slate-600">
                                            <span class="font-bold text-slate-800">Support needs:</span> {{ application.applicant.support_needs }}
                                        </p>
                                        <p v-if="application.applicant?.scholarship_goal" class="rounded-md bg-slate-50 p-2 text-xs leading-5 text-slate-600">
                                            <span class="font-bold text-slate-800">Goal:</span> {{ application.applicant.scholarship_goal }}
                                        </p>
                                    </div>
                                    <p v-if="application.applicant?.willing_to_relocate" class="mt-2 w-fit rounded-md bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">
                                        Relocation: {{ labelFromKey(application.applicant.willing_to_relocate) }}
                                    </p>
                                </div>

                                <div v-if="application.dss_breakdown?.criteria?.length" class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <p class="font-semibold text-slate-500">
                                        DSS weighted criteria
                                    </p>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        <div
                                            v-for="criterion in application.dss_breakdown.criteria"
                                            :key="criterion.key"
                                            class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                        >
                                            <div class="flex items-center justify-between gap-3">
                                                <p class="font-bold text-slate-950">
                                                    {{ criterion.label }}
                                                </p>
                                                <p class="text-xs font-bold text-slate-500">
                                                    {{ criterion.weight }}%
                                                </p>
                                            </div>
                                            <p class="mt-2 text-xs font-bold uppercase tracking-[0.12em] text-slate-600">
                                                Score {{ criterion.score }}%
                                            </p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                                {{ criterion.note }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="font-semibold text-slate-500">
                                                Pipeline status
                                            </p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                Update where this application sits in review.
                                            </p>
                                        </div>
                                        <select
                                            :value="application.status"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100 disabled:opacity-60"
                                            @change="updateStatus(application, $event.target.value)"
                                        >
                                            <option
                                                v-for="option in statusOptions"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="mt-4 border-t border-slate-200 pt-3">
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            Decision reason
                                        </label>
                                        <select
                                            v-model="decisionReasons[application.id]"
                                            class="mb-3 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                        >
                                            <option
                                                v-for="option in decisionReasonOptions"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>

                                        <div class="mb-3 grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 md:grid-cols-3">
                                            <div>
                                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Awarded amount
                                                </label>
                                                <input
                                                    v-model="awardedAmounts[application.id]"
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    placeholder="Optional"
                                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                >
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Outcome date
                                                </label>
                                                <input
                                                    v-model="outcomeDates[application.id]"
                                                    type="date"
                                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                >
                                            </div>
                                            <div>
                                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Outcome note
                                                </label>
                                                <input
                                                    v-model="outcomeNotes[application.id]"
                                                    type="text"
                                                    maxlength="2000"
                                                    placeholder="Award, release, renewal, or closure note"
                                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                >
                                            </div>
                                        </div>

                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            Review note
                                        </label>
                                        <textarea
                                            v-model="reviewNotes[application.id]"
                                            rows="3"
                                            maxlength="1500"
                                            placeholder="Example: Missing proof of income, qualified for interview, or approved for final review."
                                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                        ></textarea>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="mt-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                            @click="updateStatus(application, application.status)"
                                        >
                                            {{ updatingId === application.id ? 'Saving...' : 'Save note' }}
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Email
                                        </p>
                                        <p class="mt-1 break-words font-bold text-slate-950">
                                            {{ application.applicant?.email || 'Not provided' }}
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Contact number
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.applicant?.contact_number || 'Not provided' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-semibold text-slate-500">
                                            Confirmed documents
                                        </p>
                                        <span class="rounded-md bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">
                                            {{ application.document_readiness?.percent ?? 0 }}% ready
                                        </span>
                                    </div>
                                    <div v-if="application.document_checklist?.length" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="document in application.document_checklist"
                                            :key="document"
                                            class="rounded-md bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800"
                                        >
                                            {{ document }}
                                        </span>
                                    </div>
                                    <p v-else class="mt-2 text-slate-500">
                                        No checklist items saved.
                                    </p>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-semibold text-slate-500">
                                            Uploaded files
                                        </p>
                                        <span class="rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                            {{ application.document_readiness?.uploaded ?? 0 }} uploaded
                                        </span>
                                    </div>

                                    <div v-if="application.documents?.length" class="mt-3 grid gap-3">
                                        <div
                                            v-for="document in application.documents"
                                            :key="document.id"
                                            class="rounded-md border border-slate-200 bg-slate-50 p-3"
                                        >
                                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                <div>
                                                    <p class="font-bold text-slate-950">
                                                        {{ document.document_name }}
                                                    </p>
                                                    <p class="mt-1 text-xs text-slate-500">
                                                        {{ document.original_name }} - {{ formatFileSize(document.size) }} - {{ document.uploaded_at }}
                                                    </p>
                                                </div>
                                                <span :class="['h-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', documentStatusClass(document.status)]">
                                                    {{ labelFromKey(document.status || 'pending') }}
                                                </span>
                                            </div>

                                            <div class="mt-3 grid gap-2 md:grid-cols-[12rem_1fr_auto] md:items-end">
                                                <div>
                                                    <label class="mb-1 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                        Status
                                                    </label>
                                                    <select
                                                        v-model="documentStatuses[document.id]"
                                                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                    >
                                                        <option
                                                            v-for="option in documentStatusOptions"
                                                            :key="option.value"
                                                            :value="option.value"
                                                        >
                                                            {{ option.label }}
                                                        </option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                        Document note
                                                    </label>
                                                    <input
                                                        v-model="documentNotes[document.id]"
                                                        type="text"
                                                        maxlength="1000"
                                                        placeholder="Optional note for applicant"
                                                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                    >
                                                </div>
                                                <div class="flex gap-2">
                                                    <a
                                                        :href="document.download_url"
                                                        class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-white"
                                                    >
                                                        Download
                                                    </a>
                                                    <button
                                                        type="button"
                                                        :disabled="documentUpdatingId === document.id"
                                                        class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:opacity-60"
                                                        @click="updateDocumentStatus(application, document)"
                                                    >
                                                        {{ documentUpdatingId === document.id ? 'Saving...' : 'Save' }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="mt-2 text-slate-500">
                                        No uploaded files yet.
                                    </p>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <p class="font-semibold text-slate-500">
                                        Applicant note
                                    </p>
                                    <p class="mt-1 leading-6 text-slate-700">
                                        {{ application.notes || 'No note added.' }}
                                    </p>
                                </div>

                                <div v-if="application.timeline?.length" class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <p class="font-semibold text-slate-500">
                                        Review timeline
                                    </p>
                                    <div class="mt-3 grid gap-2">
                                        <div
                                            v-for="event in application.timeline"
                                            :key="event.id"
                                            class="rounded-md border border-slate-200 bg-slate-50 p-3"
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
                                                <span v-if="event.decision_reason"> - {{ labelFromKey(event.decision_reason) }}</span>
                                            </p>
                                            <p v-if="event.review_notes" class="mt-2 leading-5 text-slate-600">
                                                {{ event.review_notes }}
                                            </p>
                                        </div>
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
