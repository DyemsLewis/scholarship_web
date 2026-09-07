<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { labelFromKey } from '../support/display';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const searchQuery = ref('');
const requestedStatusFilter = new URLSearchParams(window.location.search).get('status');
const legacyStatusAliases = { draft: 'drafts', rejected: 'drafts', pending_review: 'review' };
const normalizedStatusFilter = legacyStatusAliases[requestedStatusFilter] ?? requestedStatusFilter;
const programStatusFilters = ['all', 'drafts', 'review', 'published', 'closed'];
const statusFilter = ref(programStatusFilters.includes(normalizedStatusFilter) ? normalizedStatusFilter : 'all');

const canPostScholarships = computed(() => user.value?.can_post_scholarships);
const canManagePrograms = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_programs'),
));
const canReviewApplications = computed(() => Boolean(
    window.portalUser?.can_post_scholarships
        && (
            window.portalUser?.has_full_access
            || window.portalUser?.permissions?.includes('review_applications')
        ),
));
const canManageProfile = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_profile'),
));
const verificationDocumentCount = computed(() => Number(user.value?.verification_documents_count ?? 0));
const lifecycleOptions = computed(() => {
    const options = [
        { value: 'drafts', label: 'Drafts', description: 'Finish setup or correct feedback.', icon: 'fa-solid fa-pen-ruler' },
        { value: 'review', label: 'In admin review', description: 'Waiting for a publishing decision.', icon: 'fa-solid fa-shield-halved' },
        { value: 'published', label: 'Published', description: 'Visible to eligible applicants.', icon: 'fa-solid fa-bullhorn' },
        { value: 'closed', label: 'Closed', description: 'Keep final program records.', icon: 'fa-solid fa-box-archive' },
    ];

    return options.map((option) => ({
        ...option,
        count: scholarships.value.filter((scholarship) => programLifecycle(scholarship) === option.value).length,
    }));
});
const filteredScholarships = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return scholarships.value.filter((scholarship) => {
        const matchesStatus = statusFilter.value === 'all' || programLifecycle(scholarship) === statusFilter.value;
        const searchableText = [
            scholarship.title,
            scholarship.category,
            scholarship.description,
            scholarship.eligible_education_levels,
        ].filter(Boolean).join(' ').toLowerCase();

        return matchesStatus && (!query || searchableText.includes(query));
    });
});
const verificationMessage = computed(() => {
    if (!user.value?.email_verified) {
        return !canManageProfile.value
            ? 'Verify your email from the sidebar. An authorized provider manager can handle organization proof.'
            : verificationDocumentCount.value
                ? 'Your proof is saved. Verify your email before an admin can complete the provider review.'
                : 'Verify your email and upload organization proof before creating scholarship programs.';
    }

    if (!canManageProfile.value) {
        return 'Ask the provider owner or staff with organization profile access to complete provider verification.';
    }

    if (user.value?.verification_status === 'rejected') {
        return 'The admin requested changes to your verification. Review the feedback and upload replacement proof.';
    }

    if (verificationDocumentCount.value === 0) {
        return 'Upload at least one organization proof so an admin can review the provider account.';
    }

    return 'Your verification proof is awaiting admin review. Program creation will become available after approval.';
});
function programLifecycle(scholarship) {
    if (['draft', 'rejected'].includes(scholarship.status)) return 'drafts';
    if (scholarship.status === 'pending_review') return 'review';
    if (scholarship.status === 'closed') return 'closed';

    return 'published';
}

function targetApplicantLabel(scholarship) {
    const levels = String(scholarship.eligible_education_levels ?? '')
        .split(/\r?\n|,/)
        .map((item) => item.trim())
        .filter(Boolean);

    if (levels.length === 0 || levels.length >= 7) {
        return 'All learners';
    }

    if (levels.includes('preschool') && levels.includes('elementary') && levels.length === 2) {
        return 'Preschool / Elementary';
    }

    return levels.slice(0, 2).map(labelFromKey).join(', ') + (levels.length > 2 ? ` +${levels.length - 2}` : '');
}

function programDeadlineLabel(deadline) {
    if (!deadline) {
        return 'No deadline';
    }

    const parsedDate = new Date(`${deadline}T00:00:00`);

    if (Number.isNaN(parsedDate.getTime())) {
        return deadline;
    }

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(parsedDate);
}

function programStatusLabel(status) {
    return {
        draft: 'Draft',
        pending_review: 'In admin review',
        published: 'Published',
        rejected: 'Needs changes',
        closed: 'Closed',
    }[status] ?? labelFromKey(status || 'draft');
}

function programStatusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'pending_review') {
        return 'bg-sky-100 text-sky-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function programPrimaryAction(scholarship) {
    if (['draft', 'rejected'].includes(scholarship.status) && canManagePrograms.value) {
        return {
            label: scholarship.status === 'rejected' ? 'Fix program' : 'Continue setup',
            href: `/provider/programs/${scholarship.id}/edit`,
        };
    }

    return {
        label: scholarship.status === 'closed'
            ? 'View records'
            : (scholarship.status === 'pending_review' ? 'View status' : 'Open control center'),
        href: `/provider/programs/${scholarship.id}`,
    };
}

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/dashboard/data');

        user.value = response.data.user;
        scholarships.value = Array.isArray(response.data.scholarships)
            ? response.data.scholarships
            : [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship programs.';
    } finally {
        isLoading.value = false;
    }
}

watch(statusFilter, (status) => {
    const url = new URL(window.location.href);

    if (status === 'all') url.searchParams.delete('status');
    else url.searchParams.set('status', status);

    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
});

onMounted(loadProviderData);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <header class="provider-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Provider Programs
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Scholarship programs
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Follow each program from setup and admin review through applicant management and closing.
                            </p>
                        </div>

                        <a
                            v-if="canPostScholarships && canManagePrograms"
                            href="/provider/programs/create"
                            class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            Create program
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading scholarship programs...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="provider-content-stack">
                    <div
                        v-if="!canPostScholarships"
                        class="flex flex-col gap-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div>
                            <p class="font-bold">
                                Verify your provider account first
                            </p>
                            <p class="mt-1 leading-6">
                                {{ verificationMessage }}
                            </p>
                            <p v-if="user?.verification_notes" class="mt-2 text-xs leading-5">
                                <span class="font-bold">Admin note:</span> {{ user.verification_notes }}
                            </p>
                        </div>
                        <a
                            href="/provider/profile#verification-documents"
                            class="shrink-0 rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            {{ canManageProfile && !verificationDocumentCount ? 'Upload proof' : 'View verification' }}
                        </a>
                    </div>

                    <section class="provider-panel p-5">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Program lifecycle</p>
                            <h3 class="mt-1 text-xl font-bold text-slate-950">Programs by current stage</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                Choose a stage, then continue the next required action for a program.
                            </p>
                        </div>

                        <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                            <button
                                v-for="option in lifecycleOptions"
                                :key="option.value"
                                type="button"
                                :class="[
                                    'flex min-h-24 items-start gap-3 rounded-md border p-3 text-left transition',
                                    statusFilter === option.value
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-200 bg-slate-50 text-slate-900 hover:border-slate-400 hover:bg-white',
                                ]"
                                @click="statusFilter = option.value"
                            >
                                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md', statusFilter === option.value ? 'bg-white/10 text-amber-300' : 'bg-white text-slate-600 ring-1 ring-slate-200']">
                                    <i :class="option.icon" aria-hidden="true"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="text-sm font-bold">{{ option.label }}</span>
                                        <span :class="['rounded px-2 py-0.5 text-xs font-bold', statusFilter === option.value ? 'bg-white/10' : 'bg-white text-slate-700 ring-1 ring-slate-200']">{{ option.count }}</span>
                                    </span>
                                    <span :class="['mt-1 block text-xs leading-5', statusFilter === option.value ? 'text-slate-300' : 'text-slate-500']">{{ option.description }}</span>
                                </span>
                            </button>
                        </div>

                        <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <label class="relative w-full sm:max-w-sm">
                                <span class="sr-only">Search programs</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="searchQuery"
                                    type="search"
                                    placeholder="Search programs"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                >
                            </label>
                            <div class="flex items-center gap-3">
                                <button type="button" :class="['rounded-md border px-3 py-2.5 text-xs font-bold transition', statusFilter === 'all' ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100']" @click="statusFilter = 'all'">
                                    All programs ({{ scholarships.length }})
                                </button>
                                <p class="hidden text-xs font-semibold text-slate-500 md:block">
                                Showing {{ filteredScholarships.length }} of {{ scholarships.length }}
                                </p>
                            </div>
                        </div>

                        <div v-if="scholarships.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No programs yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                {{ canPostScholarships
                                    ? 'Create your first program when its details and requirements are ready.'
                                    : 'Complete provider verification to unlock program creation.' }}
                            </p>
                        </div>

                        <template v-else>
                            <div v-if="filteredScholarships.length" class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                                <article
                                    v-for="scholarship in filteredScholarships"
                                    :key="scholarship.id"
                                    class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-3 py-3 transition last:border-b-0 hover:bg-slate-50 sm:flex-nowrap sm:px-4"
                                >
                                    <img
                                        :src="scholarship.image_url"
                                        :alt="scholarship.title"
                                        class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0 flex-1">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <h4 class="truncate text-sm font-bold text-slate-950 sm:text-base">
                                                {{ scholarship.title }}
                                            </h4>
                                            <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', programStatusClass(scholarship.status)]">
                                                {{ programStatusLabel(scholarship.status) }}
                                            </span>
                                        </div>
                                        <p class="mt-1 truncate text-xs leading-5 text-slate-500">
                                            {{ scholarship.category || 'Uncategorized' }}
                                            <span class="mx-1 text-slate-300">&middot;</span>
                                            {{ targetApplicantLabel(scholarship) }}
                                            <template v-if="canReviewApplications && ['published', 'closed'].includes(scholarship.status)">
                                                <span class="mx-1 text-slate-300">&middot;</span>
                                                {{ scholarship.applications_count ?? 0 }} applicant{{ Number(scholarship.applications_count ?? 0) === 1 ? '' : 's' }}
                                            </template>
                                            <span v-if="scholarship.deadline" class="hidden sm:inline"><span class="mx-1 text-slate-300">&middot;</span>{{ programDeadlineLabel(scholarship.deadline) }}</span>
                                        </p>
                                    </div>
                                    <span
                                        v-if="canReviewApplications && Number(scholarship.pending_review_applications_count ?? 0) > 0"
                                        class="hidden shrink-0 rounded-md bg-amber-100 px-2 py-1 text-[10px] font-bold text-amber-800 md:inline-flex"
                                    >
                                        {{ scholarship.pending_review_applications_count }} to review
                                    </span>
                                    <a
                                        :href="programPrimaryAction(scholarship).href"
                                        class="ml-14 inline-flex w-full shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 sm:ml-0 sm:w-auto"
                                    >
                                        {{ programPrimaryAction(scholarship).label }}
                                        <i class="fa-solid fa-arrow-right ml-2 text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </article>
                            </div>

                            <div v-else class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                                <p class="text-sm font-bold text-slate-900">No programs match this view</p>
                                <p class="mt-1 text-sm leading-6 text-slate-500">Choose another status or adjust your search.</p>
                                <button
                                    type="button"
                                    class="mt-3 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                    @click="searchQuery = ''; statusFilter = 'all'"
                                >
                                    Clear filters
                                </button>
                            </div>
                        </template>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
