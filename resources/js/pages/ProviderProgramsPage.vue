<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
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
        { value: 'drafts', label: 'Drafts' },
        { value: 'review', label: 'In admin review' },
        { value: 'published', label: 'Published' },
        { value: 'closed', label: 'Closed' },
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
    return programTask(scholarship).action;
}

function programEditAction(scholarship) {
    if (!canManagePrograms.value || programPrimaryAction(scholarship).href.endsWith('/edit')) {
        return null;
    }

    return {
        label: 'Edit',
        href: `/provider/programs/${scholarship.id}/edit`,
    };
}

function programTask(scholarship) {
    if (['draft', 'rejected'].includes(scholarship.status)) {
        return {
            title: scholarship.status === 'rejected' ? 'Changes requested' : 'Setup incomplete',
            detail: scholarship.status === 'rejected' ? 'Review the admin feedback.' : 'Continue the program setup.',
            action: {
                label: canManagePrograms.value ? 'Continue setup' : 'View status',
                href: canManagePrograms.value
                    ? `/provider/programs/${scholarship.id}/edit`
                    : `/provider/programs/${scholarship.id}`,
            },
        };
    }

    if (scholarship.status === 'pending_review') {
        return {
            title: 'Awaiting admin review',
            detail: 'No provider action is needed.',
            action: {
                label: 'View status',
                href: `/provider/programs/${scholarship.id}`,
            },
        };
    }

    if (scholarship.status === 'closed') {
        return {
            title: 'Application cycle closed',
            detail: 'Applicant records remain available.',
            action: {
                label: 'View records',
                href: `/provider/programs/${scholarship.id}`,
            },
        };
    }

    const reviewCount = Number(scholarship.pending_review_applications_count ?? 0);

    if (canReviewApplications.value && reviewCount > 0) {
        return {
            title: `${reviewCount} application${reviewCount === 1 ? '' : 's'} need review`,
            detail: 'Continue from the review queue.',
            action: {
                label: 'Start review',
                href: `/provider/programs/${scholarship.id}/applications/review`,
            },
        };
    }

    const applicantCount = Number(scholarship.applications_count ?? 0);

    return {
        title: applicantCount > 0 ? 'Application cycle active' : 'Waiting for applicants',
        detail: applicantCount > 0
            ? `${applicantCount} applicant${applicantCount === 1 ? '' : 's'} in this program.`
            : 'The program is published.',
        action: {
            label: 'Open program',
            href: `/provider/programs/${scholarship.id}`,
        },
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
                <TaskPageHeader
                    theme="provider"
                    eyebrow="Programs"
                    title="Programs"
                    description="Choose a program to continue."
                    icon="fa-solid fa-graduation-cap"
                    :action-href="canPostScholarships && canManagePrograms ? '/provider/programs/create' : ''"
                    :action-label="canPostScholarships && canManagePrograms ? 'Create program' : ''"
                >
                    <template #meta>
                        <span>{{ scholarships.length }} program{{ scholarships.length === 1 ? '' : 's' }}</span>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="provider-panel mt-4 p-6 text-sm text-slate-500">
                    Loading scholarship programs...
                </div>

                <div v-else-if="errorMessage" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
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
                            href="/provider/profile/verification"
                            class="shrink-0 rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            {{ canManageProfile && !verificationDocumentCount ? 'Upload proof' : 'View verification' }}
                        </a>
                    </div>

                    <section class="provider-panel overflow-hidden">
                        <div class="grid gap-3 border-b border-slate-200 bg-slate-50/70 p-3 sm:grid-cols-[minmax(0,1fr)_13rem_auto] sm:items-center">
                            <label class="relative w-full">
                                <span class="sr-only">Search programs</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="searchQuery"
                                    type="search"
                                    placeholder="Search programs"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                >
                            </label>
                            <select v-model="statusFilter" aria-label="Program status" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-slate-500">
                                <option value="all">All stages ({{ scholarships.length }})</option>
                                <option v-for="option in lifecycleOptions" :key="option.value" :value="option.value">{{ option.label }} ({{ option.count }})</option>
                            </select>
                            <p class="text-xs font-semibold text-slate-500 sm:text-right">{{ filteredScholarships.length }} shown</p>
                        </div>

                        <div v-if="scholarships.length === 0" class="p-8 text-center">
                            <p class="text-sm font-bold text-slate-900">No programs yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                {{ canPostScholarships
                                    ? 'Create your first program when its details and requirements are ready.'
                                    : 'Complete provider verification to unlock program creation.' }}
                            </p>
                        </div>

                        <template v-else>
                            <div v-if="filteredScholarships.length" class="bg-white">
                                <div class="portal-record-head hidden grid-cols-[minmax(0,1fr)_minmax(12rem,18rem)_13rem] items-center gap-4 lg:grid">
                                    <span>Program</span>
                                    <span>Current step</span>
                                    <span class="text-center">Actions</span>
                                </div>
                                <article
                                    v-for="scholarship in filteredScholarships"
                                    :key="scholarship.id"
                                    class="portal-record-row grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(12rem,18rem)_13rem] lg:items-center lg:gap-4"
                                >
                                    <div class="flex min-w-0 items-center gap-3">
                                        <img
                                            :src="scholarship.image_url"
                                            :alt="scholarship.title"
                                            class="h-10 w-10 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0 flex-1">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <h4 class="line-clamp-2 text-sm font-bold leading-5 text-slate-950">
                                                    {{ scholarship.title }}
                                                </h4>
                                                <span :class="['inline-flex shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase', programStatusClass(scholarship.status)]">
                                                    {{ programStatusLabel(scholarship.status) }}
                                                </span>
                                            </div>
                                            <p class="mt-1 truncate text-xs leading-5 text-slate-500">
                                                {{ scholarship.category || 'Uncategorized' }}
                                                <span v-if="scholarship.deadline"><span class="mx-1 text-slate-300">&middot;</span>Due {{ programDeadlineLabel(scholarship.deadline) }}</span>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="min-w-0 rounded-md bg-slate-50 px-3 py-2 lg:bg-transparent lg:px-0 lg:py-0">
                                        <p class="text-sm font-bold text-slate-800">{{ programTask(scholarship).title }}</p>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ programTask(scholarship).detail }}</p>
                                    </div>
                                    <div class="portal-record-actions grid grid-cols-1 sm:ml-14 sm:flex sm:w-fit lg:ml-0 lg:w-full">
                                        <a
                                            v-if="programEditAction(scholarship)"
                                            :href="programEditAction(scholarship).href"
                                            class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        >
                                            <i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>
                                            {{ programEditAction(scholarship).label }}
                                        </a>
                                        <a
                                            :href="programPrimaryAction(scholarship).href"
                                            class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-slate-800"
                                        >
                                            {{ programPrimaryAction(scholarship).label }}
                                            <i class="fa-solid fa-arrow-right ml-2 text-[10px]" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </article>
                            </div>

                            <div v-else class="p-8 text-center">
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

            </div>
        </section>
    </main>
</template>
