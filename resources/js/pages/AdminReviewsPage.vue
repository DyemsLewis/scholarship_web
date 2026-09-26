<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isLoading = ref(true);
const isProgramLoading = ref(false);
const errorMessage = ref('');
const requestedReviewType = new URLSearchParams(window.location.search).get('type');
const reviewTypeValues = ['providers', 'programs', 'applicants', 'benefits'];
const activeReviewType = ref(reviewTypeValues.includes(requestedReviewType) ? requestedReviewType : 'providers');
const reviewSearch = ref('');
const reviewPage = ref(1);
const reviewsPerPage = 10;
const selectedStatus = ref('pending');
const selectedApplicantStatus = ref('pending');
const selectedProgramStatus = ref('pending_review');
const selectedBenefitStatus = ref('attention');
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
    benefit_records: 0,
    benefits_released: 0,
    benefits_with_receipts: 0,
    benefits_note_only: 0,
    benefits_needing_attention: 0,
});
const providers = ref([]);
const applicants = ref([]);
const scholarships = ref([]);
const benefitRecords = ref([]);
const reviewPageConfig = computed(() => ({
    providers: {
        eyebrow: 'Provider reviews',
        title: 'Verify provider organizations',
        description: 'Confirm organization details and proof before granting publishing access.',
        icon: 'fa-solid fa-building-shield',
        total: stats.value.providers,
        pending: stats.value.pending_providers,
        searchPlaceholder: 'Search provider name or address',
    },
    programs: {
        eyebrow: 'Program reviews',
        title: 'Review scholarship programs',
        description: 'Check the offer, eligibility, and application process before publication.',
        icon: 'fa-solid fa-graduation-cap',
        total: stats.value.pending_programs + stats.value.published_programs + stats.value.rejected_programs,
        pending: stats.value.pending_programs,
        searchPlaceholder: 'Search program or provider',
    },
    applicants: {
        eyebrow: 'Applicant reviews',
        title: 'Verify applicant records',
        description: 'Compare saved academic information with the supporting records submitted.',
        icon: 'fa-solid fa-user-check',
        total: stats.value.applicants,
        pending: stats.value.pending_applicants,
        searchPlaceholder: 'Search applicant, email, or school',
    },
    benefits: {
        eyebrow: 'Benefit evidence',
        title: 'Review benefit release records',
        description: 'Check provider evidence and flags that require administrative attention.',
        icon: 'fa-solid fa-file-shield',
        total: stats.value.benefit_records,
        pending: stats.value.benefits_needing_attention,
        searchPlaceholder: 'Search recipient, provider, or program',
    },
}[activeReviewType.value]));

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
const benefitStatusFilters = computed(() => [
    { value: 'attention', label: 'Needs review', count: stats.value.benefits_needing_attention },
    { value: 'released', label: 'Released', count: stats.value.benefits_released },
    { value: 'receipt', label: 'Receipt-backed', count: stats.value.benefits_with_receipts },
    { value: 'note', label: 'Note only', count: stats.value.benefits_note_only },
    { value: 'all', label: 'All records', count: stats.value.benefit_records },
]);
const activeStatusFilters = computed(() => ({
    providers: statusFilters.value,
    programs: programStatusFilters.value,
    applicants: applicantStatusFilters.value,
    benefits: benefitStatusFilters.value,
}[activeReviewType.value] ?? []));
const activeStatusValue = computed(() => ({
    providers: selectedStatus.value,
    programs: selectedProgramStatus.value,
    applicants: selectedApplicantStatus.value,
    benefits: selectedBenefitStatus.value,
}[activeReviewType.value]));
const filteredBenefitRecords = computed(() => {
    const query = reviewSearch.value.trim().toLowerCase();

    return benefitRecords.value.filter((record) => {
        const matchesStatus = selectedBenefitStatus.value === 'all'
            || (selectedBenefitStatus.value === 'attention' && record.oversight_status === 'attention')
            || (selectedBenefitStatus.value === 'released' && record.status === 'released')
            || record.evidence_type === selectedBenefitStatus.value;
        const searchableText = [
            record.provider_name,
            record.program_title,
            record.applicant_name,
            record.applicant_email,
            record.release_title,
            record.benefit_description,
            record.notes,
        ].filter(Boolean).join(' ').toLowerCase();

        return matchesStatus && (!query || searchableText.includes(query));
    });
});
const activeReviewItems = computed(() => ({
    providers: filteredProviders.value,
    programs: filteredPrograms.value,
    applicants: filteredApplicants.value,
    benefits: filteredBenefitRecords.value,
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

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'published'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded', 'benefits_terminated'].includes(status)) {
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

function benefitStatusClass(status) {
    if (status === 'released') return 'bg-emerald-100 text-emerald-800';
    if (['missed', 'withheld'].includes(status)) return 'bg-rose-100 text-rose-800';
    if (status === 'prepared') return 'bg-sky-100 text-sky-800';
    return 'bg-slate-100 text-slate-700';
}

function oversightClass(status) {
    if (status === 'documented') return 'bg-emerald-100 text-emerald-800';
    if (status === 'attention') return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-700';
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

async function selectActiveStatus(status) {
    reviewPage.value = 1;

    if (activeReviewType.value === 'programs') {
        await selectProgramStatus(status);
        return;
    }

    if (activeReviewType.value === 'providers') selectedStatus.value = status;
    if (activeReviewType.value === 'applicants') selectedApplicantStatus.value = status;
    if (activeReviewType.value === 'benefits') selectedBenefitStatus.value = status;
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
        benefitRecords.value = response.data.benefit_records ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load review details.';
    } finally {
        isLoading.value = false;
        isProgramLoading.value = false;
    }
}

watch([reviewSearch, selectedStatus, selectedApplicantStatus, selectedBenefitStatus], () => {
    reviewPage.value = 1;
});

onMounted(loadReviewData);
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="reviews" />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    :eyebrow="reviewPageConfig.eyebrow"
                    :title="reviewPageConfig.title"
                    :description="reviewPageConfig.description"
                    :icon="reviewPageConfig.icon"
                >
                    <template #meta>
                        <span>{{ reviewPageConfig.pending }} awaiting attention</span>
                        <span>{{ reviewPageConfig.total }} total records</span>
                    </template>
                    <template #actions>
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                            @click="loadReviewData()"
                        >
                            Refresh queue
                        </button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">
                    Loading review details...
                </div>

                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <section class="admin-panel overflow-hidden">
                        <div class="flex flex-col gap-3 bg-slate-50 p-3 lg:flex-row lg:items-center">
                            <label class="relative w-full sm:max-w-md">
                                <span class="sr-only">Search active review queue</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="reviewSearch"
                                    type="search"
                                    :placeholder="reviewPageConfig.searchPlaceholder"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                >
                            </label>
                            <label class="flex min-w-0 items-center gap-2 lg:ml-auto">
                                <span class="shrink-0 text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Status</span>
                                <select
                                    :value="activeStatusValue"
                                    class="min-w-44 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-slate-500"
                                    @change="selectActiveStatus($event.target.value)"
                                >
                                    <option v-for="filter in activeStatusFilters" :key="filter.value" :value="filter.value">
                                        {{ filter.label }} ({{ filter.count }})
                                    </option>
                                </select>
                            </label>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">{{ reviewRange }}</p>
                        </div>
                    </section>

                    <section v-if="activeReviewType === 'providers'" class="admin-panel overflow-hidden">
                        <div v-if="filteredProviders.length === 0" class="portal-table-empty">
                            <p class="portal-table-empty-title">No provider reviews in this view</p>
                            <p class="portal-table-empty-copy">
                                New provider registrations appear here after they submit their organization details and verification proof.
                            </p>
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <article
                                v-for="provider in visibleReviewItems"
                                :key="provider.id"
                                class="portal-record-row flex items-center gap-3"
                            >
                                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-[11px] font-bold tracking-[0.08em] text-white ring-1 ring-slate-200">
                                    {{ providerInitials(provider) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <h4 class="line-clamp-2 text-sm font-bold leading-5 text-slate-950">
                                            {{ provider.provider_name || provider.name }}
                                        </h4>
                                        <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(provider.verification_status)]">
                                            {{ statusLabel(provider.verification_status) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 truncate text-xs leading-5 text-slate-500">
                                        {{ provider.provider_type ? statusLabel(provider.provider_type) : 'Provider organization' }}
                                        <template v-if="provider.provider_address">
                                            <span class="mx-1 text-slate-300">&middot;</span>
                                            {{ provider.provider_address }}
                                        </template>
                                    </p>
                                </div>
                                <a
                                    :href="providerReviewUrl(provider)"
                                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    Review
                                </a>
                            </article>
                        </div>
                    </section>

                    <section v-else-if="activeReviewType === 'programs'" class="admin-panel overflow-hidden">
                        <div v-if="isProgramLoading" class="p-5 text-sm text-slate-500">
                            Loading {{ statusLabel(selectedProgramStatus).toLowerCase() }} programs...
                        </div>

                        <div v-else-if="filteredPrograms.length === 0" class="portal-table-empty">
                            <p class="portal-table-empty-title">No {{ statusLabel(selectedProgramStatus).toLowerCase() }} programs</p>
                            <p class="portal-table-empty-copy">
                                Choose another status to review programs at a different stage.
                            </p>
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <article
                                v-for="scholarship in visibleReviewItems"
                                :id="`program-${scholarship.id}`"
                                :key="scholarship.id"
                                class="portal-record-row flex items-center gap-3"
                            >
                                <img
                                    :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="scholarship.title"
                                    class="h-10 w-10 shrink-0 rounded-md bg-white object-contain p-1 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <p class="line-clamp-2 text-sm font-bold leading-5 text-slate-950">{{ scholarship.title }}</p>
                                        <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(scholarship.status)]">
                                            {{ statusLabel(scholarship.status) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 truncate text-xs leading-5 text-slate-500">
                                        {{ scholarship.provider || 'Provider' }}
                                        <span class="mx-1 text-slate-300">&middot;</span>
                                        {{ scholarship.category || 'Uncategorized' }}
                                    </p>
                                </div>
                                <a
                                    :href="reviewProgramUrl(scholarship)"
                                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    Review
                                </a>
                            </article>
                        </div>
                    </section>

                    <section v-else-if="activeReviewType === 'applicants'" class="admin-panel overflow-hidden">
                        <div v-if="applicants.length === 0" class="p-6">
                            <p class="text-sm font-bold text-slate-900">No applicant accounts yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Applicant proof submissions will appear here after students or guardians upload a profile document.
                            </p>
                        </div>

                        <div v-else-if="filteredApplicants.length === 0" class="p-6">
                            <p class="text-sm font-bold text-slate-900">No applicants in this review status</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Choose another filter to view completed reviews or applicants who have not uploaded proof yet.
                            </p>
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <article
                                v-for="applicant in visibleReviewItems"
                                :key="applicant.id"
                                class="portal-record-row flex items-center gap-3"
                            >
                                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-[11px] font-bold tracking-[0.08em] text-white ring-1 ring-slate-200">
                                    {{ applicantInitials(applicant) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <h4 class="line-clamp-2 text-sm font-bold leading-5 text-slate-950">{{ applicant.name || applicant.username }}</h4>
                                        <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(applicantReviewStatus(applicant))]">
                                            {{ applicantReviewStatusLabel(applicant) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">
                                        {{ applicant.email }} &middot; {{ applicant.school || 'School not provided' }} &middot; {{ applicant.verification_documents?.length ? 'Academic record submitted' : 'No academic record' }}
                                    </p>
                                    <p v-if="applicantReviewStatus(applicant) === 'approved'" class="mt-1 line-clamp-1 text-xs font-semibold text-slate-600">
                                        {{ applicant.verification_oversight?.source_label || 'Earlier record' }}
                                        <template v-if="applicant.verification_oversight?.provider_organization || applicant.verification_oversight?.reviewer_name">
                                            &middot; {{ applicant.verification_oversight.provider_organization || applicant.verification_oversight.reviewer_name }}
                                        </template>
                                        <template v-if="applicant.verification_oversight?.verified_at">
                                            &middot; {{ applicant.verification_oversight.verified_at }}
                                        </template>
                                    </p>
                                </div>

                                <a
                                    :href="applicantReviewUrl(applicant)"
                                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    Open record
                                </a>
                            </article>
                        </div>
                    </section>

                    <section v-else class="admin-panel overflow-hidden">
                        <div v-if="benefitRecords.length === 0" class="px-5 py-10 text-center sm:px-6">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
                            <p class="mt-3 font-bold text-slate-950">No benefit releases recorded yet</p>
                            <p class="mt-1 text-sm text-slate-500">Records appear after a provider schedules support for selected recipients.</p>
                        </div>

                        <div v-else-if="filteredBenefitRecords.length === 0" class="px-5 py-10 text-center sm:px-6">
                            <p class="font-bold text-slate-950">No records match this filter</p>
                            <p class="mt-1 text-sm text-slate-500">Choose another evidence status or change the search.</p>
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <article v-for="record in visibleReviewItems" :key="record.id" class="px-5 py-4 sm:px-6">
                                <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="font-bold text-slate-950">{{ record.applicant_name || 'Applicant' }}</h4>
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', benefitStatusClass(record.status)]">{{ record.status_label }}</span>
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', oversightClass(record.oversight_status)]">{{ record.oversight_label }}</span>
                                        </div>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ record.provider_name || 'Provider' }} &middot; {{ record.program_title || 'Scholarship program' }}</p>
                                        <p class="mt-2 text-sm font-bold leading-5 text-slate-800">{{ record.release_title || 'Benefit release' }}</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ record.benefit_description }}<span v-if="record.amount_label"> &middot; {{ record.amount_label }}</span></p>
                                    </div>

                                    <dl class="grid shrink-0 gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 text-xs sm:grid-cols-3 xl:w-[520px]">
                                        <div class="bg-slate-50 px-3 py-2.5"><dt class="font-bold uppercase tracking-[0.08em] text-slate-500">Scheduled</dt><dd class="mt-1 font-semibold text-slate-800">{{ record.scheduled_at || 'Not recorded' }}</dd></div>
                                        <div class="bg-slate-50 px-3 py-2.5"><dt class="font-bold uppercase tracking-[0.08em] text-slate-500">Evidence</dt><dd class="mt-1 font-semibold text-slate-800">{{ record.evidence_label }}</dd></div>
                                        <div class="bg-slate-50 px-3 py-2.5"><dt class="font-bold uppercase tracking-[0.08em] text-slate-500">Original check</dt><dd class="mt-1 font-semibold text-slate-800">{{ record.requires_original_verification ? (record.originals_verified ? 'Verified' : 'Not recorded') : 'Not required' }}</dd></div>
                                    </dl>
                                </div>

                                <div v-if="record.oversight_flags?.length" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5">
                                    <p v-for="flag in record.oversight_flags" :key="flag" class="text-xs font-semibold leading-5 text-amber-900"><i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>{{ flag }}</p>
                                </div>
                                <p v-if="record.notes" class="mt-3 text-sm leading-6 text-slate-600"><strong class="text-slate-900">Provider note:</strong> {{ record.notes }}</p>

                                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                                    <a v-if="record.receipt" :href="record.receipt.view_url" target="_blank" rel="noopener noreferrer" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-file-shield mr-1.5" aria-hidden="true"></i>View receipt proof</a>
                                    <a v-if="record.applicant_review_url" :href="record.applicant_review_url" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Applicant record</a>
                                    <a v-if="record.program_review_url" :href="record.program_review_url" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Program record</a>
                                    <p class="ml-auto text-xs text-slate-500">Recorded {{ record.recorded_at || 'not yet' }}<span v-if="record.recorded_by"> by {{ record.recorded_by }}</span></p>
                                </div>
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

            </div>
        </section>
    </main>
</template>
