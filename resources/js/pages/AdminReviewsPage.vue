<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const updatingId = ref(null);
const updatingScholarshipId = ref(null);
const errorMessage = ref('');
const statusMessage = ref('');
const selectedStatus = ref('pending');
const selectedApplicationFilter = ref('all');
const selectedApplicationSort = ref('priority');
const providerNotes = ref({});
const hiddenProviderIds = ref([]);
const stats = ref({
    providers: 0,
    pending_providers: 0,
    approved_providers: 0,
    rejected_providers: 0,
    recent_applications: 0,
    average_match_score: 0,
    average_dss_score: 0,
    pending_documents: 0,
    pending_programs: 0,
    published_programs: 0,
    rejected_programs: 0,
});
const providers = ref([]);
const applications = ref([]);
const scholarships = ref([]);

const filteredProviders = computed(() => {
    const visibleProviders = providers.value.filter((provider) => !hiddenProviderIds.value.includes(provider.id));

    if (selectedStatus.value === 'all') {
        return visibleProviders;
    }

    return visibleProviders.filter((provider) => provider.verification_status === selectedStatus.value);
});
const statusFilters = computed(() => [
    { value: 'pending', label: 'Pending', count: stats.value.pending_providers },
    { value: 'approved', label: 'Approved', count: stats.value.approved_providers },
    { value: 'rejected', label: 'Rejected', count: stats.value.rejected_providers },
    { value: 'all', label: 'All providers', count: stats.value.providers },
]);
const applicationFilters = computed(() => [
    { value: 'all', label: 'All', count: applications.value.length },
    {
        value: 'pending_documents',
        label: 'Document queue',
        count: applications.value.filter((application) => applicationDocumentIssueCount(application) > 0).length,
    },
    {
        value: 'high_dss',
        label: 'High DSS',
        count: applications.value.filter((application) => Number(application.dss_score ?? 0) >= 80).length,
    },
    {
        value: 'needs_review',
        label: 'Needs review',
        count: applications.value.filter((application) => application.dss_recommendation === 'needs_review' || ['submitted', 'under_review'].includes(application.status ?? 'submitted')).length,
    },
]);
const prioritizedApplications = computed(() => {
    const filteredApplications = applications.value.filter((application) => {
        if (selectedApplicationFilter.value === 'pending_documents') {
            return applicationDocumentIssueCount(application) > 0;
        }

        if (selectedApplicationFilter.value === 'high_dss') {
            return Number(application.dss_score ?? 0) >= 80;
        }

        if (selectedApplicationFilter.value === 'needs_review') {
            return application.dss_recommendation === 'needs_review' || ['submitted', 'under_review'].includes(application.status ?? 'submitted');
        }

        return true;
    });

    return [...filteredApplications].sort((first, second) => {
        if (selectedApplicationSort.value === 'dss') {
            return Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
        }

        if (selectedApplicationSort.value === 'documents') {
            return applicationDocumentIssueCount(second) - applicationDocumentIssueCount(first);
        }

        return applicationReviewScore(second) - applicationReviewScore(first) || Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
    });
});

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'published'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'pending_review'].includes(status)) {
        return 'bg-sky-100 text-sky-800';
    }

    return 'bg-amber-100 text-amber-800';
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

function inferGradeScale(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return Number(value) <= 5 ? 'grade_point' : 'percentage';
}

function academicRequirementLabel(scholarship) {
    if (scholarship?.minimum_grade_label) {
        return scholarship.minimum_grade_label;
    }

    if (!scholarship?.minimum_gwa) {
        return 'No academic minimum';
    }

    return inferGradeScale(scholarship.minimum_gwa) === 'grade_point'
        ? `Max GWA/GPA ${scholarship.minimum_gwa}`
        : `Min average ${scholarship.minimum_gwa}%`;
}

function applicationDocumentIssueCount(application) {
    if (application.documents?.length) {
        return application.documents.filter((document) => ['pending', 'needs_replacement', 'rejected'].includes(document.status ?? 'pending')).length;
    }

    return Number(application.documents_pending ?? 0);
}

function applicationReviewScore(application) {
    const status = application.status ?? 'submitted';
    const issues = applicationDocumentIssueCount(application);
    let score = 0;

    if (['submitted', 'under_review'].includes(status)) {
        score += status === 'submitted' ? 22 : 16;
    }

    if (issues > 0) {
        score += Math.min(35, issues * 12);
    }

    if (Number(application.dss_score ?? 0) >= 80) {
        score += 12;
    }

    if (Number(application.eligibility_score ?? 0) >= 80) {
        score += 8;
    }

    if (application.dss_recommendation === 'needs_review') {
        score += 20;
    }

    if (application.dss_recommendation === 'not_recommended') {
        score += 10;
    }

    if (['approved', 'awarded', 'not_awarded', 'disbursed', 'renewed', 'rejected'].includes(status)) {
        score -= 20;
    }

    return Math.max(0, score);
}

function applicationPriorityLabel(application) {
    const score = applicationReviewScore(application);

    if (score >= 55) {
        return 'High priority';
    }

    if (score >= 30) {
        return 'Needs review';
    }

    return 'Routine';
}

function applicationPriorityClass(application) {
    const score = applicationReviewScore(application);

    if (score >= 55) {
        return 'bg-rose-100 text-rose-800';
    }

    if (score >= 30) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-200 text-slate-700';
}

function applicationReviewReasons(application) {
    const reasons = [];
    const issues = applicationDocumentIssueCount(application);

    if (['submitted', 'under_review'].includes(application.status ?? 'submitted')) {
        reasons.push('Open review');
    }

    if (issues > 0) {
        reasons.push(`${issues} document ${issues === 1 ? 'issue' : 'issues'}`);
    }

    if (Number(application.dss_score ?? 0) >= 80) {
        reasons.push('High DSS candidate');
    }

    if (application.dss_recommendation === 'needs_review') {
        reasons.push('DSS needs manual check');
    }

    if (reasons.length === 0) {
        reasons.push('No urgent admin flags');
    }

    return reasons.slice(0, 3);
}

function statusLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatFileSize(size) {
    if (!size) {
        return '0 KB';
    }

    return `${Math.max(1, Math.round(Number(size) / 1024))} KB`;
}

function documentTypeLabel(type) {
    return String(type ?? 'Document')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function providerActionOptions(provider) {
    const status = provider.verification_status ?? 'pending';
    const actions = [];

    if (status !== 'approved') {
        actions.push({
            status: 'approved',
            label: 'Approve provider',
            className: 'bg-emerald-700 text-white hover:bg-emerald-800',
        });
    }

    if (status !== 'rejected') {
        actions.push({
            status: 'rejected',
            label: 'Reject',
            className: 'bg-rose-700 text-white hover:bg-rose-800',
        });
    }

    if (status !== 'pending') {
        actions.push({
            status: 'pending',
            label: 'Move to pending',
            className: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
        });
    }

    return actions;
}

function scholarshipActionOptions(scholarship) {
    const status = scholarship.status ?? 'pending_review';
    const actions = [];

    if (status !== 'published') {
        actions.push({
            status: 'published',
            label: 'Approve program',
            className: 'bg-emerald-700 text-white hover:bg-emerald-800',
        });
    }

    if (status !== 'rejected') {
        actions.push({
            status: 'rejected',
            label: 'Reject',
            className: 'bg-rose-700 text-white hover:bg-rose-800',
        });
    }

    if (status !== 'pending_review') {
        actions.push({
            status: 'pending_review',
            label: 'Move to review',
            className: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
        });
    }

    return actions;
}

async function loadReviewData(resetHiddenProviders = true) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/reviews/data');

        if (resetHiddenProviders) {
            hiddenProviderIds.value = [];
        }

        stats.value = response.data.stats;
        providers.value = response.data.providers;
        applications.value = response.data.applications;
        scholarships.value = response.data.scholarships ?? [];
        providerNotes.value = Object.fromEntries(
            providers.value.map((provider) => [provider.id, provider.verification_notes ?? '']),
        );
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load review details.';
    } finally {
        isLoading.value = false;
    }
}

async function updateScholarshipReview(scholarship, reviewStatus) {
    if ((scholarship.status ?? 'pending_review') === reviewStatus) {
        return;
    }

    updatingScholarshipId.value = scholarship.id;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.patch(`/admin/scholarships/${scholarship.id}/review`, {
            status: reviewStatus,
        });

        scholarships.value = scholarships.value.map((item) => (item.id === scholarship.id ? response.data.scholarship : item));
        statusMessage.value = response.data.message ?? `Program marked as ${statusLabel(reviewStatus)}.`;
        await loadReviewData(false);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update scholarship review.';
    } finally {
        updatingScholarshipId.value = null;
    }
}

async function updateProvider(provider, verificationStatus) {
    if ((provider.verification_status ?? 'pending') === verificationStatus) {
        return;
    }

    updatingId.value = provider.id;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.patch(`/admin/providers/${provider.id}/verification`, {
            verification_status: verificationStatus,
            verification_notes: providerNotes.value[provider.id] ?? '',
        });

        providers.value = providers.value.map((item) => (item.id === provider.id ? response.data.provider : item));
        hiddenProviderIds.value = [...new Set([...hiddenProviderIds.value, provider.id])];
        statusMessage.value = response.data.message ?? `Provider marked as ${statusLabel(verificationStatus)}.`;
        await loadReviewData(false);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update provider verification.';
    } finally {
        updatingId.value = null;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadReviewData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="reviews" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
                                Reviews
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Provider and application review
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Approve scholarship providers before they publish programs and monitor application review activity.
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
                    <p v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
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

                        <div v-if="filteredProviders.length === 0" class="p-6 text-sm text-slate-500">
                            No providers for this filter. Updated providers are hidden until you refresh.
                        </div>

                        <div v-else class="grid gap-3 p-3 md:grid-cols-2 xl:grid-cols-3">
                            <article
                                v-for="provider in filteredProviders"
                                :key="provider.id"
                                class="rounded-md border border-slate-200 bg-slate-50 p-3"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-bold uppercase tracking-[0.14em] text-sky-700">
                                            {{ provider.provider_type || 'provider' }}
                                        </p>
                                        <h4 class="mt-1 truncate text-base font-bold text-slate-950">
                                            {{ provider.provider_name || provider.name }}
                                        </h4>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">
                                            {{ provider.email }}
                                        </p>
                                    </div>
                                    <span :class="['shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(provider.verification_status)]">
                                        {{ statusLabel(provider.verification_status) }}
                                    </span>
                                </div>

                                <div class="mt-3 space-y-1.5 text-xs text-slate-600">
                                    <p class="truncate">
                                        <span class="font-semibold text-slate-500">Website:</span>
                                        {{ provider.provider_website || 'Not provided' }}
                                    </p>
                                    <p class="line-clamp-1">
                                        <span class="font-semibold text-slate-500">Address:</span>
                                        {{ provider.provider_address || 'Not provided' }}
                                    </p>
                                </div>

                                <div class="mt-3 rounded-md border border-slate-200 bg-white p-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">
                                            Verification proof
                                        </p>
                                        <span class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600">
                                            {{ provider.verification_documents?.length || 0 }} file{{ provider.verification_documents?.length === 1 ? '' : 's' }}
                                        </span>
                                    </div>
                                    <div v-if="provider.verification_documents?.length" class="mt-2 grid gap-2">
                                        <div
                                            v-for="document in provider.verification_documents"
                                            :key="document.id"
                                            class="flex flex-col gap-2 rounded-md border border-slate-200 bg-slate-50 p-2 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div class="min-w-0">
                                                <p class="truncate text-xs font-bold text-slate-950">
                                                    {{ documentTypeLabel(document.document_type) }}
                                                </p>
                                                <p class="mt-0.5 truncate text-[11px] text-slate-500">
                                                    {{ document.original_name }} - {{ formatFileSize(document.size) }}
                                                </p>
                                            </div>
                                            <a
                                                :href="document.download_url"
                                                class="rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100"
                                            >
                                                Download
                                            </a>
                                        </div>
                                    </div>
                                    <p v-else class="mt-2 text-xs text-slate-500">
                                        No proof documents uploaded yet.
                                    </p>
                                </div>

                                <div class="mt-3 rounded-md border border-slate-200 bg-white p-2.5">
                                    <label class="block text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">
                                        Admin note
                                    </label>
                                    <textarea
                                        v-model="providerNotes[provider.id]"
                                        rows="1"
                                        maxlength="1500"
                                        placeholder="Optional note for this provider."
                                        class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                    ></textarea>
                                </div>

                                <div class="mt-3 border-t border-slate-200 pt-3">
                                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">
                                        Verification action
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button
                                            v-for="action in providerActionOptions(provider)"
                                            :key="action.status"
                                            type="button"
                                            :disabled="updatingId === provider.id"
                                            :class="[
                                                'rounded-md px-3 py-2 text-xs font-bold transition disabled:cursor-not-allowed disabled:opacity-70',
                                                action.className,
                                            ]"
                                            @click="updateProvider(provider, action.status)"
                                        >
                                            {{ updatingId === provider.id ? 'Saving...' : action.label }}
                                        </button>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                    Program Review Queue
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Approve submitted scholarships
                                </h3>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs font-bold">
                                <span class="rounded-md bg-sky-100 px-2.5 py-1 text-sky-800">
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

                        <div v-if="scholarships.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No scholarship programs are waiting for review.
                        </div>

                        <div v-else class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            <article
                                v-for="scholarship in scholarships"
                                :key="scholarship.id"
                                class="rounded-md border border-slate-200 bg-slate-50 p-3"
                            >
                                <div class="flex items-start gap-3">
                                    <img
                                        :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                        :alt="scholarship.title"
                                        class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="truncate font-bold text-slate-950">
                                                    {{ scholarship.title }}
                                                </p>
                                                <p class="mt-1 truncate text-xs text-slate-500">
                                                    {{ scholarship.provider || 'Provider' }}
                                                </p>
                                            </div>
                                            <span :class="['shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(scholarship.status)]">
                                                {{ statusLabel(scholarship.status) }}
                                            </span>
                                        </div>
                                        <p class="mt-2 line-clamp-2 text-xs leading-5 text-slate-600">
                                            {{ scholarship.description || 'No description provided.' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-md bg-white p-2">
                                        <p class="font-semibold text-slate-500">Deadline</p>
                                        <p class="mt-1 truncate font-bold text-slate-950">{{ scholarship.deadline || 'Not set' }}</p>
                                    </div>
                                    <div class="rounded-md bg-white p-2">
                                        <p class="font-semibold text-slate-500">Academic requirement</p>
                                        <p class="mt-1 truncate font-bold text-slate-950">{{ academicRequirementLabel(scholarship) }}</p>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <button
                                        v-for="action in scholarshipActionOptions(scholarship)"
                                        :key="action.status"
                                        type="button"
                                        :disabled="updatingScholarshipId === scholarship.id"
                                        :class="[
                                            'rounded-md px-3 py-2 text-xs font-bold transition disabled:cursor-not-allowed disabled:opacity-70',
                                            action.className,
                                        ]"
                                        @click="updateScholarshipReview(scholarship, action.status)"
                                    >
                                        {{ updatingScholarshipId === scholarship.id ? 'Saving...' : action.label }}
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Application Review Snapshot
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Recent submitted applications
                        </h3>

                        <div v-if="applications.length" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="filter in applicationFilters"
                                        :key="filter.value"
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedApplicationFilter === filter.value
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedApplicationFilter = filter.value"
                                    >
                                        {{ filter.label }} ({{ filter.count }})
                                    </button>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedApplicationSort === 'priority'
                                                ? 'border-sky-900 bg-sky-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedApplicationSort = 'priority'"
                                    >
                                        Priority
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedApplicationSort === 'dss'
                                                ? 'border-sky-900 bg-sky-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedApplicationSort = 'dss'"
                                    >
                                        DSS
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedApplicationSort === 'documents'
                                                ? 'border-sky-900 bg-sky-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedApplicationSort = 'documents'"
                                    >
                                        Documents
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applications yet.
                        </div>

                        <div v-else-if="prioritizedApplications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applications match this review filter.
                        </div>

                        <div v-else class="mt-4 grid gap-2 md:grid-cols-2">
                            <div
                                v-for="application in prioritizedApplications"
                                :key="application.id"
                                class="grid gap-2 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                            >
                                <div class="flex gap-3">
                                    <img
                                        :src="application.scholarship_image_url || '/uploads/scholarship-default.jpg'"
                                        :alt="application.scholarship || 'Scholarship'"
                                        class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-slate-950">{{ application.scholarship }}</p>
                                        <p class="mt-1 truncate text-xs text-slate-500">
                                            {{ application.applicant }} - {{ application.provider }}
                                        </p>
                                        <p v-if="application.review_notes" class="mt-1 line-clamp-1 text-xs text-slate-500">
                                            Note: {{ application.review_notes }}
                                        </p>
                                        <p v-if="application.decision_reason" class="mt-1 truncate text-xs text-slate-500">
                                            Reason: {{ statusLabel(application.decision_reason) }}
                                        </p>
                                        <p v-if="application.awarded_amount || application.outcome_at" class="mt-1 truncate text-xs font-semibold text-emerald-700">
                                            Outcome: {{ application.awarded_amount || 'Amount not listed' }} <span v-if="application.outcome_at">on {{ application.outcome_at }}</span>
                                        </p>
                                        <p class="mt-2 inline-flex w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-indigo-700 ring-1 ring-indigo-100">
                                            DSS: {{ application.dss_score ?? 0 }}% - {{ statusLabel(application.dss_recommendation || 'needs_review') }}
                                        </p>
                                        <p v-if="application.dss_explanation?.headline" class="mt-2 line-clamp-2 text-xs font-semibold leading-5 text-slate-600">
                                            {{ application.dss_explanation.headline }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span :class="['h-fit w-fit rounded-md px-2.5 py-1 text-[11px] font-bold uppercase', applicationPriorityClass(application)]">
                                        {{ applicationPriorityLabel(application) }}
                                    </span>
                                    <span :class="['h-fit w-fit rounded-md px-2.5 py-1 text-[11px] font-bold uppercase', statusClass(application.status)]">
                                        {{ statusLabel(application.status) }}
                                    </span>
                                    <span v-if="application.status_progress" class="h-fit w-fit rounded-md bg-white px-2.5 py-1 text-[11px] font-bold uppercase text-slate-600 ring-1 ring-slate-200">
                                        {{ application.status_progress.percent }}% stage
                                    </span>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="reason in applicationReviewReasons(application)"
                                        :key="reason"
                                        class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200"
                                    >
                                        {{ reason }}
                                    </span>
                                </div>
                                <p class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                    {{ application.eligibility_score ?? 0 }}% match
                                </p>
                                <p class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                    {{ application.documents_uploaded }} files
                                    <span v-if="application.documents_pending">({{ application.documents_pending }} pending)</span>
                                </p>
                                <div v-if="application.documents?.length" class="mt-1 grid gap-2">
                                    <div
                                        v-for="document in application.documents"
                                        :key="document.id"
                                        class="flex flex-col gap-2 rounded-md border border-slate-200 bg-white p-2.5 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-bold text-slate-950">
                                                {{ document.document_name }}
                                            </p>
                                            <p class="mt-0.5 truncate text-[11px] text-slate-500">
                                                {{ document.original_name }} - {{ formatFileSize(document.size) }}
                                            </p>
                                            <p v-if="document.review_notes" class="mt-1 line-clamp-1 text-[11px] font-semibold text-amber-700">
                                                Note: {{ document.review_notes }}
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', documentStatusClass(document.status)]">
                                                {{ statusLabel(document.status || 'pending') }}
                                            </span>
                                            <a
                                                :href="document.download_url"
                                                class="rounded-md border border-slate-300 px-2.5 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100"
                                            >
                                                Download
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="mt-1 rounded-md border border-dashed border-slate-300 bg-white p-2.5 text-xs text-slate-500">
                                    No uploaded documents yet.
                                </p>
                            </div>
                        </div>
                    </section>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
