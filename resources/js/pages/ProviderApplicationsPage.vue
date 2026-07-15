<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const appElement = document.getElementById('app');
const initialScholarshipId = appElement?.dataset.scholarshipId ?? new URLSearchParams(window.location.search).get('scholarship_id') ?? '';
const initialScholarshipTitle = appElement?.dataset.scholarshipTitle ?? '';
const isLoading = ref(true);
const errorMessage = ref('');
const applications = ref([]);
const selectedScholarshipContext = ref(initialScholarshipId ? {
    id: Number(initialScholarshipId),
    title: initialScholarshipTitle,
} : null);
const selectedQueueFilter = ref('all');
const selectedQueueSort = ref('priority');

const selectedScholarshipId = computed(() => selectedScholarshipContext.value?.id || initialScholarshipId);
const hasProgramContext = computed(() => Boolean(selectedScholarshipId.value));
const exportApplicationsUrl = computed(() => {
    if (!hasProgramContext.value) {
        return '/provider/export/applications';
    }

    return `/provider/export/applications?scholarship_id=${encodeURIComponent(selectedScholarshipId.value)}`;
});
const pageKicker = computed(() => (hasProgramContext.value ? 'Program Applicants' : 'Application Review'));
const pageTitle = computed(() => (hasProgramContext.value
    ? `Applicants for ${selectedScholarshipContext.value?.title || 'this program'}`
    : 'Applicant activity queue'));
const pageDescription = computed(() => (hasProgramContext.value
    ? 'Review only the applicants who submitted for this scholarship program.'
    : 'Review submitted applications, document status, and DSS guidance for your programs.'));
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
const customStatusLabels = {
    exam_qualified: 'Qualified for exam',
    exam_scheduled: 'Exam scheduled',
    exam_taken: 'Exam taken',
    exam_passed: 'Passed exam',
    exam_failed: 'Failed exam',
    distribution_scheduled: 'Distribution scheduled',
    disbursed: 'Distributed',
    for_exam: 'Meets exam eligibility',
    exam_completed: 'Exam completed',
    passed_exam: 'Passed exam',
    failed_exam: 'Failed exam',
};
function statusLabel(status) {
    if (customStatusLabels[status]) {
        return customStatusLabels[status];
    }

    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'exam_passed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded', 'exam_failed'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
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

    if (['exam_qualified', 'exam_scheduled', 'exam_taken'].includes(status)) {
        score += 14;
    }

    if (status === 'exam_passed') {
        score += 10;
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

    if (['approved', 'awarded'].includes(status) && !application.distribution_scheduled_for) {
        score += 18;
    }

    if (!application.review_notes && ['submitted', 'under_review'].includes(status)) {
        score += 5;
    }

    if (['not_awarded', 'disbursed', 'renewed', 'rejected', 'exam_failed'].includes(status)) {
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

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/applications/data', {
            params: hasProgramContext.value ? { scholarship_id: selectedScholarshipId.value } : {},
        });

        applications.value = response.data.applications;
        selectedScholarshipContext.value = response.data.selected_scholarship ?? selectedScholarshipContext.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider applications.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                {{ pageKicker }}
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ pageTitle }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                {{ pageDescription }}
                            </p>
                        </div>
                        <div v-if="hasProgramContext" class="flex flex-wrap gap-2">
                            <a
                                href="/provider/applications"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                All applications
                            </a>
                            <a
                                href="/provider/programs"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Programs
                            </a>
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
                            {{ hasProgramContext ? 'Submitted applicants' : 'Submitted applications' }}
                        </h3>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <a
                                :href="exportApplicationsUrl"
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

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No applications to review yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                {{ hasProgramContext
                                    ? 'Applicants for this program will appear here after eligible students submit the application wizard.'
                                    : 'Applications will appear after an approved scholarship is published and an eligible applicant submits the application wizard.' }}
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="/provider/programs" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800">Check programs</a>
                                <a href="/provider/programs/create" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100">Create scholarship</a>
                            </div>
                        </div>

                        <div v-else-if="rankedApplications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applications match this review filter.
                        </div>

                        <div v-else class="mt-5 grid gap-3 xl:grid-cols-2">
                            <article
                                v-for="application in rankedApplications"
                                :key="application.id"
                                class="overflow-hidden rounded-lg border border-slate-200 bg-white"
                            >
                                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            {{ application.scholarship?.title || 'Scholarship' }}
                                        </p>
                                        <h4 class="mt-1 truncate text-lg font-bold text-slate-950">
                                            {{ application.applicant?.name || 'Applicant' }}
                                        </h4>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Submitted {{ application.submitted_at || 'recently' }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', reviewPriorityClass(application)]">
                                            {{ reviewPriorityLabel(application) }}
                                        </span>
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                            {{ statusLabel(application.status) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="grid grid-cols-3 border-y border-slate-200 bg-slate-50 text-center text-xs">
                                    <div class="p-3">
                                        <p class="font-semibold text-slate-500">Suitability</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ application.dss_score ?? 0 }}%</p>
                                    </div>
                                    <div class="border-x border-slate-200 p-3">
                                        <p class="font-semibold text-slate-500">Match</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ application.eligibility_score ?? 0 }}%</p>
                                    </div>
                                    <div class="p-3">
                                        <p class="font-semibold text-slate-500">Documents</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ application.document_readiness?.percent ?? 0 }}%</p>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="min-w-0 truncate text-xs font-semibold text-slate-500">
                                        {{ application.distribution_scheduled_label
                                            ? `Distribution ${application.distribution_scheduled_label}`
                                            : (application.status_progress?.label || statusLabel(application.status)) }}
                                    </p>
                                    <a
                                        :href="application.detail_url || `/provider/applications/${application.id}`"
                                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                    >
                                        View details
                                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                    </a>
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
