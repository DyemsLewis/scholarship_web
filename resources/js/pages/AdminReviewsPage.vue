<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const selectedStatus = ref('pending');
const selectedApplicantStatus = ref('pending');
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

const filteredProviders = computed(() => {
    if (selectedStatus.value === 'all') {
        return providers.value;
    }

    return providers.value.filter((provider) => provider.verification_status === selectedStatus.value);
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
const filteredApplicants = computed(() => applicants.value
    .filter((applicant) => selectedApplicantStatus.value === 'all'
        || applicantReviewStatus(applicant) === selectedApplicantStatus.value)
    .sort((first, second) => Number(second.verification_documents?.[0]?.id ?? 0)
        - Number(first.verification_documents?.[0]?.id ?? 0)));

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

async function loadReviewData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/reviews/data');

        stats.value = { ...stats.value, ...response.data.stats };
        providers.value = response.data.providers ?? [];
        applicants.value = response.data.applicants ?? [];
        scholarships.value = response.data.scholarships ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load review details.';
    } finally {
        isLoading.value = false;
    }
}

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
                                Reviews
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Provider, program, and applicant review
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Verify provider organizations and applicant proof files, then approve programs before publication.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                            @click="loadReviewData"
                        >
                            Refresh Reviews
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
                    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 p-5">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Provider Verification
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Approve scholarship providers
                            </h3>
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
                                    {{ filter.label }}
                                </button>
                            </div>
                        </div>

                        <div v-if="filteredProviders.length === 0" class="p-6">
                            <p class="text-sm font-bold text-slate-900">No provider reviews in this view</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                New provider registrations appear here after they submit their organization details and verification proof.
                            </p>
                            <a href="/admin/accounts/create" class="mt-3 inline-flex rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100">
                                Create an account
                            </a>
                        </div>

                        <div v-else class="m-3 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <article
                                v-for="provider in filteredProviders"
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
                                    View details
                                </a>
                            </article>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Program Review Queue
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Approve submitted scholarships
                                </h3>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs font-bold">
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-slate-700">
                                    {{ stats.pending_programs }} pending
                                </span>
                                <span class="rounded-md bg-emerald-100 px-2.5 py-1 text-emerald-800">
                                    {{ stats.published_programs }} published
                                </span>
                                <span class="rounded-md bg-rose-100 px-2.5 py-1 text-rose-800">
                                    {{ stats.rejected_programs }} rejected
                                </span>
                            </div>
                        </div>

                        <div v-if="scholarships.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">The program review queue is clear</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Scholarships submitted by verified providers will appear here before they become visible to applicants.
                            </p>
                        </div>

                        <div v-else class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <article
                                v-for="scholarship in scholarships"
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
                                    <p class="truncate text-sm font-bold text-slate-950 sm:text-base">
                                        {{ scholarship.title }}
                                    </p>
                                    <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">
                                        {{ scholarship.description || 'No program description provided.' }}
                                    </p>
                                </div>
                                <a
                                    :href="reviewProgramUrl(scholarship)"
                                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    View details
                                </a>
                            </article>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 p-5">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Applicant Review
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Review applicants
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                Open an applicant to compare their profile with the submitted proof and make a verification decision.
                            </p>

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
                                v-for="applicant in filteredApplicants"
                                :key="applicant.id"
                                class="grid gap-3 p-4 transition hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                            >
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="truncate text-sm font-bold text-slate-950">
                                            {{ applicant.name || applicant.username }}
                                        </h4>
                                        <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                            {{ applicantReviewStatusLabel(applicant) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ applicant.email }}</p>
                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                                        <span>{{ applicant.school || 'School not provided' }}</span>
                                        <span>{{ statusLabel(applicant.education_level || 'education not provided') }}</span>
                                        <span class="font-semibold text-slate-700">
                                            {{ applicant.verification_documents?.length || 0 }} proof file{{ applicant.verification_documents?.length === 1 ? '' : 's' }}
                                        </span>
                                    </div>
                                </div>

                                <a
                                    :href="applicantReviewUrl(applicant)"
                                    class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                                >
                                    View details
                                </a>
                            </article>
                        </div>
                    </section>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
