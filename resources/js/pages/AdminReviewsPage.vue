<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const isProgramLoading = ref(false);
const errorMessage = ref('');
const requestedReviewType = new URLSearchParams(window.location.search).get('type');
const reviewTypeValues = ['providers', 'programs', 'applicants'];
const activeReviewType = ref(reviewTypeValues.includes(requestedReviewType) ? requestedReviewType : 'providers');
const reviewSearch = ref('');
const reviewPage = ref(1);
const reviewsPerPage = 10;
const selectedStatus = ref('pending');
const selectedApplicantStatus = ref('pending');
const selectedProgramStatus = ref('pending_review');
const stats = ref({
    providers: 0,
    pending_providers: 0,
    approved_providers: 0,
    rejected_providers: 0,
    applicants: 0,
    pending_applicants: 0,
    approved_applicants: 0,
    rejected_applicants: 0,
    unsubmitted_applicants: 0,
    applicant_proofs: 0,
    pending_programs: 0,
    published_programs: 0,
    rejected_programs: 0,
});
const providers = ref([]);
const applicants = ref([]);
const scholarships = ref([]);
const reviewTabs = computed(() => [
    {
        value: 'providers',
        label: 'Providers',
        count: stats.value.providers,
        pending: stats.value.pending_providers,
        description: 'Organization identity and publishing access',
    },
    {
        value: 'programs',
        label: 'Programs',
        count: stats.value.pending_programs + stats.value.published_programs + stats.value.rejected_programs,
        pending: stats.value.pending_programs,
        description: 'Scholarship details before publication',
    },
    {
        value: 'applicants',
        label: 'Applicants',
        count: stats.value.applicants,
        pending: stats.value.pending_applicants,
        description: 'Academic results and submitted grade records',
    },
]);

const filteredProviders = computed(() => {
    const query = reviewSearch.value.trim().toLowerCase();

    return providers.value.filter((provider) => {
        const matchesStatus = selectedStatus.value === 'all' || provider.verification_status === selectedStatus.value;
        const searchableText = [
            provider.provider_name,
            provider.name,
            provider.email,
            provider.provider_description,
            provider.provider_address,
        ].filter(Boolean).join(' ').toLowerCase();

        return matchesStatus && (!query || searchableText.includes(query));
    });
});
const statusFilters = computed(() => [
    { value: 'pending', label: 'Pending', count: stats.value.pending_providers },
    { value: 'approved', label: 'Approved', count: stats.value.approved_providers },
    { value: 'rejected', label: 'Rejected', count: stats.value.rejected_providers },
    { value: 'all', label: 'All providers', count: stats.value.providers },
]);
const applicantStatusFilters = computed(() => [
    { value: 'pending', label: 'Needs review', count: stats.value.pending_applicants },
    { value: 'approved', label: 'Verified', count: stats.value.approved_applicants },
    { value: 'rejected', label: 'Needs replacement', count: stats.value.rejected_applicants },
    { value: 'unsubmitted', label: 'No proof', count: stats.value.unsubmitted_applicants },
    { value: 'all', label: 'All applicants', count: stats.value.applicants },
]);
const programStatusFilters = computed(() => [
    { value: 'pending_review', label: 'Pending', count: stats.value.pending_programs },
    { value: 'published', label: 'Published', count: stats.value.published_programs },
    { value: 'rejected', label: 'Rejected', count: stats.value.rejected_programs },
]);
const filteredApplicants = computed(() => applicants.value
    .filter((applicant) => selectedApplicantStatus.value === 'all'
        || applicantReviewStatus(applicant) === selectedApplicantStatus.value)
    .filter((applicant) => {
        const query = reviewSearch.value.trim().toLowerCase();
        const searchableText = [
            applicant.name,
            applicant.username,
            applicant.email,
            applicant.school,
        ].filter(Boolean).join(' ').toLowerCase();

        return !query || searchableText.includes(query);
    })
    .sort((first, second) => Number(second.verification_documents?.[0]?.id ?? 0)
        - Number(first.verification_documents?.[0]?.id ?? 0)));
const filteredPrograms = computed(() => {
    const query = reviewSearch.value.trim().toLowerCase();

    return scholarships.value.filter((scholarship) => {
        const searchableText = [
            scholarship.title,
            scholarship.provider,
            scholarship.description,
            scholarship.category,
        ].filter(Boolean).join(' ').toLowerCase();

        return !query || searchableText.includes(query);
    });
});
const activeReviewItems = computed(() => ({
    providers: filteredProviders.value,
    programs: filteredPrograms.value,
    applicants: filteredApplicants.value,
}[activeReviewType.value] ?? []));
const totalReviewPages = computed(() => Math.max(1, Math.ceil(activeReviewItems.value.length / reviewsPerPage)));
const visibleReviewItems = computed(() => {
    const start = (reviewPage.value - 1) * reviewsPerPage;

    return activeReviewItems.value.slice(start, start + reviewsPerPage);
});
const reviewRange = computed(() => {
    if (activeReviewItems.value.length === 0) {
        return '0 records';
    }

    const start = (reviewPage.value - 1) * reviewsPerPage + 1;
    const end = Math.min(reviewPage.value * reviewsPerPage, activeReviewItems.value.length);

    return `${start}-${end} of ${activeReviewItems.value.length}`;
});

function selectReviewType(type) {
    activeReviewType.value = type;
    reviewSearch.value = '';
    reviewPage.value = 1;

    const url = new URL(window.location.href);
    url.searchParams.set('type', type);
    window.history.replaceState(window.history.state, '', url);
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'published'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'pending_review', 'distribution_scheduled', 'unsubmitted'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function applicantReviewStatus(applicant) {
    const status = applicant.applicant_verification_status;

    if (['approved', 'rejected'].includes(status)) {
        return status;
    }

    return applicant.verification_documents?.length ? 'pending' : 'unsubmitted';
}

function applicantReviewStatusLabel(applicant) {
    return {
        pending: 'Needs review',
        approved: 'Verified',
        rejected: 'Not verified',
        unsubmitted: 'No proof',
    }[applicantReviewStatus(applicant)];
}

function statusLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function reviewProgramUrl(scholarship) {
    return `/admin/scholarships/${scholarship.id}/review`;
}

function providerReviewUrl(provider) {
    return `/admin/providers/${provider.id}/review`;
}

function applicantReviewUrl(applicant) {
    return `/admin/applicants/${applicant.id}/review`;
}

function providerInitials(provider) {
    return String(provider.provider_name || provider.name || 'Provider')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase();
}

function applicantInitials(applicant) {
    return String(applicant.name || applicant.username || 'Applicant')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase();
}

async function selectProgramStatus(status) {
    if (status === selectedProgramStatus.value) {
        return;
    }

    selectedProgramStatus.value = status;
    reviewPage.value = 1;
    await loadReviewData({ programOnly: true });
}

async function loadReviewData(options = {}) {
    if (options.programOnly) {
        isProgramLoading.value = true;
    } else {
        isLoading.value = true;
    }
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/reviews/data', {
            params: {
                program_status: selectedProgramStatus.value,
            },
        });

        stats.value = { ...stats.value, ...response.data.stats };
        providers.value = response.data.providers ?? [];
        applicants.value = response.data.applicants ?? [];
        scholarships.value = response.data.scholarships ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load review details.';
    } finally {
        isLoading.value = false;
        isProgramLoading.value = false;
    }
}

watch([reviewSearch, selectedStatus, selectedApplicantStatus], () => {
    reviewPage.value = 1;
});

onMounted(loadReviewData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="reviews" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Review Workspace
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Review one queue at a time
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Choose providers, programs, or applicants, then open a guided review before recording a decision.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                            @click="loadReviewData()"
                        >
                            Refresh queue
                        </button>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading review details...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <nav class="grid gap-1 p-2 md:grid-cols-3" aria-label="Admin review queues">
                            <button
                                v-for="tab in reviewTabs"
                                :key="tab.value"
                                type="button"
                                :aria-current="activeReviewType === tab.value ? 'page' : undefined"
                                :class="[
                                    'flex min-w-0 items-center gap-3 rounded-md p-3 text-left transition',
                                    activeReviewType === tab.value
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-700 hover:bg-slate-50 hover:text-slate-950',
                                ]"
                                @click="selectReviewType(tab.value)"
                            >
                                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md text-sm font-bold', activeReviewType === tab.value ? 'bg-white/10' : 'bg-slate-100 text-slate-700']">
                                    {{ tab.count }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2 text-sm font-bold">
                                        {{ tab.label }}
                                        <span v-if="tab.pending" class="h-2 w-2 rounded-full bg-amber-400" aria-label="Pending reviews"></span>
                                    </span>
                                    <span :class="['mt-0.5 block truncate text-xs', activeReviewType === tab.value ? 'text-slate-300' : 'text-slate-500']">{{ tab.description }}</span>
                                </span>
                            </button>
                        </nav>
                        <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <label class="relative w-full sm:max-w-md">
                                <span class="sr-only">Search active review queue</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="reviewSearch"
                                    type="search"
                                    :placeholder="`Search ${activeReviewType}`"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                >
                            </label>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">Showing {{ reviewRange }}</p>
                        </div>
                    </section>

                    <section v-if="activeReviewType === 'providers'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Provider Verification
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Approve scholarship providers
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                Review organization details and proof before granting publishing access.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button
                                    v-for="filter in statusFilters"
                                    :key="filter.value"
                                    type="button"
                                    :class="[
                                        'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                        selectedStatus === filter.value
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                                    ]"
                                    @click="selectedStatus = filter.value"
                                >
                                    {{ filter.label }} ({{ filter.count }})
                                </button>
                            </div>
                        </div>

                        <div v-if="filteredProviders.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No provider reviews in this view</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                New provider registrations appear here after they submit their organization details and verification proof.
                            </p>
                        </div>

                        <div v-else class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <article
                                v-for="provider in visibleReviewItems"
                                :key="provider.id"
                                class="flex items-center gap-3 border-b border-slate-200 px-3 py-3 transition last:border-b-0 hover:bg-slate-50 sm:px-4"
                            >
                                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-bold tracking-[0.08em] text-white ring-1 ring-slate-200">
                                    {{ providerInitials(provider) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <h4 class="truncate text-sm font-bold text-slate-950 sm:text-base">
                                            {{ provider.provider_name || provider.name }}
                                        </h4>
                                        <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(provider.verification_status)]">
                                            {{ statusLabel(provider.verification_status) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">
                                        {{ provider.provider_description || provider.provider_address || 'No organization description provided.' }}
                                    </p>
                                </div>
                                <a
                                    :href="providerReviewUrl(provider)"
                                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    Review
                                </a>
                            </article>
                        </div>
                    </section>

                    <section v-else-if="activeReviewType === 'programs'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div>
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Program Review Queue
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Approve submitted scholarships
                                </h3>
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                    Review submitted programs before publication or return them for correction.
                                </p>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button
                                    v-for="filter in programStatusFilters"
                                    :key="filter.value"
                                    type="button"
                                    :class="[
                                        'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                        selectedProgramStatus === filter.value
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="selectProgramStatus(filter.value)"
                                >
                                    {{ filter.label }} ({{ filter.count }})
                                </button>
                            </div>
                        </div>

                        <div v-if="isProgramLoading" class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">
                            Loading {{ statusLabel(selectedProgramStatus).toLowerCase() }} programs...
                        </div>

                        <div v-else-if="filteredPrograms.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No {{ statusLabel(selectedProgramStatus).toLowerCase() }} programs</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Choose another status to review programs at a different stage.
                            </p>
                        </div>

                        <div v-else class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <article
                                v-for="scholarship in visibleReviewItems"
                                :id="`program-${scholarship.id}`"
                                :key="scholarship.id"
                                class="flex items-center gap-3 border-b border-slate-200 px-3 py-3 last:border-b-0 sm:px-4"
                            >
                                <img
                                    :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="scholarship.title"
                                    class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <p class="truncate text-sm font-bold text-slate-950 sm:text-base">{{ scholarship.title }}</p>
                                        <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(scholarship.status)]">
                                            {{ statusLabel(scholarship.status) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">
                                        {{ scholarship.description || 'No program description provided.' }}
                                    </p>
                                </div>
                                <a
                                    :href="reviewProgramUrl(scholarship)"
                                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    Review
                                </a>
                            </article>
                        </div>
                    </section>

                    <section v-else class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div>
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Applicant Review</p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">Review applicant proof</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                    Compare each applicant profile with the proof submitted for verification.
                                </p>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button
                                    v-for="filter in applicantStatusFilters"
                                    :key="filter.value"
                                    type="button"
                                    :class="[
                                        'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                        selectedApplicantStatus === filter.value
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                                    ]"
                                    @click="selectedApplicantStatus = filter.value"
                                >
                                    {{ filter.label }} ({{ filter.count }})
                                </button>
                            </div>
                        </div>

                        <div v-if="applicants.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No applicant accounts yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Applicant proof submissions will appear here after students or guardians upload a profile document.
                            </p>
                        </div>

                        <div v-else-if="filteredApplicants.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No applicants in this review status</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Choose another filter to view completed reviews or applicants who have not uploaded proof yet.
                            </p>
                        </div>

                        <div v-else class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <article
                                v-for="applicant in visibleReviewItems"
                                :key="applicant.id"
                                class="flex items-center gap-3 border-b border-slate-200 px-3 py-3 transition last:border-b-0 hover:bg-slate-50 sm:px-4"
                            >
                                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-bold tracking-[0.08em] text-white ring-1 ring-slate-200">
                                    {{ applicantInitials(applicant) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <h4 class="truncate text-sm font-bold text-slate-950 sm:text-base">{{ applicant.name || applicant.username }}</h4>
                                        <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(applicantReviewStatus(applicant))]">
                                            {{ applicantReviewStatusLabel(applicant) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">
                                        {{ applicant.email }} &middot; {{ applicant.school || 'School not provided' }} &middot; {{ applicant.verification_documents?.length ? 'Academic record submitted' : 'No academic record' }}
                                    </p>
                                </div>

                                <a
                                    :href="applicantReviewUrl(applicant)"
                                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    Review
                                </a>
                            </article>
                        </div>
                    </section>

                    <nav v-if="totalReviewPages > 1" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm" aria-label="Review queue pagination">
                        <p class="text-xs font-semibold text-slate-500">Page {{ reviewPage }} of {{ totalReviewPages }}</p>
                        <div class="flex gap-2">
                            <button type="button" :disabled="reviewPage === 1" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" @click="reviewPage -= 1">Previous</button>
                            <button type="button" :disabled="reviewPage === totalReviewPages" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" @click="reviewPage += 1">Next</button>
                        </div>
                    </nav>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
