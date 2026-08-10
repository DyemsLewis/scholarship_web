<script setup>
import { computed, onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { labelFromKey } from '../support/display';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const selectedPreviewScholarship = ref(null);
const selectedMapScholarship = ref(null);
const duplicatingId = ref(null);
const searchQuery = ref('');
const statusFilter = ref('all');
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const canPostScholarships = computed(() => user.value?.can_post_scholarships);
const canManagePrograms = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_programs'),
));
const canManageProfile = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_profile'),
));
const canReviewApplications = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('review_applications'),
));
const verificationDocumentCount = computed(() => Number(user.value?.verification_documents_count ?? 0));
const statusFilterOptions = computed(() => {
    const options = [
        { value: 'all', label: 'All programs', count: scholarships.value.length },
        { value: 'published', label: 'Published' },
        { value: 'pending_review', label: 'In review' },
        { value: 'draft', label: 'Drafts' },
        { value: 'rejected', label: 'Needs changes' },
        { value: 'closed', label: 'Closed' },
    ];

    return options.map((option) => ({
        ...option,
        count: option.value === 'all'
            ? scholarships.value.length
            : scholarships.value.filter((scholarship) => scholarship.status === option.value).length,
    }));
});
const filteredScholarships = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return scholarships.value.filter((scholarship) => {
        const matchesStatus = statusFilter.value === 'all' || scholarship.status === statusFilter.value;
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
const selectedMapAddress = computed(() => {
    const parts = [
        selectedMapScholarship.value?.location_address,
        selectedMapScholarship.value?.location_name,
    ].filter(Boolean);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});

function openMapModal(scholarship) {
    selectedPreviewScholarship.value = null;
    selectedMapScholarship.value = scholarship;
}

function closeMapModal() {
    selectedMapScholarship.value = null;
}

function openPreviewModal(scholarship) {
    selectedPreviewScholarship.value = scholarship;
}

function closePreviewModal() {
    selectedPreviewScholarship.value = null;
}

function hasScholarshipMapPreview(scholarship) {
    return Boolean(
        (scholarship.latitude && scholarship.longitude)
        || scholarship.location_address
        || scholarship.location_name,
    );
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

function programSlotLabel(scholarship) {
    const selected = Number(scholarship.awarded_slots_count ?? 0);
    const capacity = Number(scholarship.slots_available ?? 0);

    return capacity > 0
        ? `${selected}/${capacity} slots used`
        : `${selected} selected`;
}

function previewRequirements(scholarship) {
    return String(scholarship.requirements ?? '')
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean)
        .slice(0, 5);
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

function programStatusGuidance(status) {
    return {
        draft: 'Finish the program details, then submit it for administrator review.',
        pending_review: 'The program is waiting for an administrator decision. You can still review its setup.',
        published: 'This program is visible to applicants and can receive applications until it closes.',
        rejected: 'Review the administrator feedback, update the program, and submit it again.',
        closed: 'This program is no longer accepting new applications.',
    }[status] ?? 'Review the program details and choose the next management action.';
}

function programRequirementCount(scholarship) {
    const count = String(scholarship.requirements ?? '')
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean)
        .length;

    return `${count} requirement${count === 1 ? '' : 's'}`;
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

async function duplicateProgram(scholarship) {
    const confirmed = await requestConfirmation({
        title: 'Duplicate this program?',
        message: `A new draft copy of ${scholarship.title} will be added to your program list.`,
        confirmLabel: 'Duplicate program',
    });

    if (!confirmed) {
        return;
    }

    duplicatingId.value = scholarship.id;
    errorMessage.value = '';

    try {
        await window.axios.post(`/provider/scholarships/${scholarship.id}/duplicate`);

        closePreviewModal();
        await loadProviderData();
    } catch (handledError) {
        void handledError;
    } finally {
        duplicatingId.value = null;
    }
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Provider Programs
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Manage scholarship programs
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Create programs, track publishing status, and open each program's applicant workspace.
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

                <div v-else class="mt-6 space-y-6">
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

                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <h3 class="text-xl font-bold text-slate-950">Your programs</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                    Select a program to preview it, edit its details, or manage applicants.
                                </p>
                            </div>
                            <label class="relative w-full lg:max-w-sm">
                                <span class="sr-only">Search programs</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="searchQuery"
                                    type="search"
                                    placeholder="Search programs"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                >
                            </label>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2 border-y border-slate-200 py-3">
                            <span class="mr-1 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Status</span>
                                <button
                                    v-for="option in statusFilterOptions"
                                    :key="option.value"
                                    type="button"
                                    :class="[
                                        'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                        statusFilter === option.value
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="statusFilter = option.value"
                                >
                                    {{ option.label }} ({{ option.count }})
                                </button>
                            <p class="ml-auto text-xs font-semibold text-slate-500">
                                Showing {{ filteredScholarships.length }} of {{ scholarships.length }}
                            </p>
                        </div>

                        <div v-if="scholarships.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No scholarship programs yet</p>
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
                                    class="flex items-center gap-3 border-b border-slate-200 px-3 py-3 transition last:border-b-0 hover:bg-slate-50 sm:px-4"
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
                                        <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">
                                            {{ scholarship.description || 'No program description provided.' }}
                                        </p>
                                        <div class="mt-1 hidden flex-wrap items-center gap-x-3 gap-y-1 text-[11px] font-semibold text-slate-500 sm:flex">
                                            <span>{{ scholarship.category || 'Uncategorized' }} - {{ targetApplicantLabel(scholarship) }}</span>
                                            <span>{{ programDeadlineLabel(scholarship.deadline) }}</span>
                                            <span>{{ scholarship.applications_count ?? 0 }} applicants</span>
                                            <span v-if="Number(scholarship.pending_review_applications_count ?? 0) > 0" class="text-amber-700">
                                                {{ scholarship.pending_review_applications_count }} to review
                                            </span>
                                            <span>{{ programSlotLabel(scholarship) }}</span>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                        @click="openPreviewModal(scholarship)"
                                    >
                                        Manage
                                    </button>
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

        <div
            v-if="selectedPreviewScholarship"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="provider-program-manage-title"
            @click.self="closePreviewModal"
        >
            <section class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="relative overflow-hidden bg-[#081426] px-5 py-5 text-white sm:px-6">
                    <div class="pointer-events-none absolute inset-y-0 right-0 w-1/2 bg-[radial-gradient(circle_at_top_right,_rgba(251,191,36,0.2),_transparent_65%)]"></div>
                    <div class="relative flex items-start gap-4">
                        <img
                            :src="selectedPreviewScholarship.image_url"
                            :alt="selectedPreviewScholarship.title"
                            class="h-14 w-14 shrink-0 rounded-md bg-white object-contain p-2 ring-1 ring-white/20"
                        >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-300">Program workspace</p>
                                <span :class="['rounded-md px-2 py-1 text-[9px] font-bold uppercase', programStatusClass(selectedPreviewScholarship.status)]">
                                    {{ programStatusLabel(selectedPreviewScholarship.status) }}
                                </span>
                            </div>
                            <h3 id="provider-program-manage-title" class="mt-1 text-xl font-bold leading-tight text-white sm:text-2xl">
                                {{ selectedPreviewScholarship.title }}
                            </h3>
                            <p class="mt-1 text-xs font-semibold text-slate-300">
                                {{ selectedPreviewScholarship.category || 'Scholarship program' }} - {{ targetApplicantLabel(selectedPreviewScholarship) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-white/20 text-white transition hover:bg-white hover:text-slate-950"
                            aria-label="Close program workspace"
                            @click="closePreviewModal"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(17rem,0.65fr)]">
                        <div class="space-y-4">
                            <section class="rounded-lg border border-slate-200 p-4">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                        <i class="fa-solid fa-arrow-trend-up text-sm" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Current next step</p>
                                        <p class="mt-1 text-sm font-semibold leading-6 text-slate-800">
                                            {{ programStatusGuidance(selectedPreviewScholarship.status) }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Program overview</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    {{ selectedPreviewScholarship.description || 'No program description has been added yet.' }}
                                </p>
                            </section>

                            <section class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-200 text-amber-900">
                                        <i class="fa-solid fa-gift text-sm" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-800">Benefits</p>
                                        <p class="mt-1 text-sm font-semibold leading-6 text-slate-900">
                                            {{ selectedPreviewScholarship.benefit_summary || 'No benefit summary has been added yet.' }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Main requirements</p>
                                    <span class="text-xs font-semibold text-slate-500">{{ programRequirementCount(selectedPreviewScholarship) }}</span>
                                </div>
                                <div v-if="previewRequirements(selectedPreviewScholarship).length" class="mt-3 flex flex-wrap gap-2">
                                    <span
                                        v-for="requirement in previewRequirements(selectedPreviewScholarship)"
                                        :key="requirement"
                                        class="rounded-md bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700"
                                    >
                                        {{ requirement }}
                                    </span>
                                </div>
                                <p v-else class="mt-2 text-sm text-slate-500">No document requirements added yet.</p>
                            </section>
                        </div>

                        <aside class="h-fit rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">At a glance</p>
                            <dl class="mt-3 divide-y divide-slate-200">
                                <div class="flex items-center justify-between gap-4 py-3 first:pt-0">
                                    <dt class="text-xs font-semibold text-slate-500">Deadline</dt>
                                    <dd class="text-right text-sm font-bold text-slate-900">{{ programDeadlineLabel(selectedPreviewScholarship.deadline) }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-3">
                                    <dt class="text-xs font-semibold text-slate-500">Applicants</dt>
                                    <dd class="text-right text-sm font-bold text-slate-900">
                                        {{ selectedPreviewScholarship.applications_count ?? 0 }}
                                        <span v-if="Number(selectedPreviewScholarship.pending_review_applications_count ?? 0) > 0" class="block text-[10px] text-amber-700">
                                            {{ selectedPreviewScholarship.pending_review_applications_count }} to review
                                        </span>
                                    </dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-3">
                                    <dt class="text-xs font-semibold text-slate-500">Award slots</dt>
                                    <dd class="text-right text-sm font-bold text-slate-900">{{ programSlotLabel(selectedPreviewScholarship) }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-3">
                                    <dt class="text-xs font-semibold text-slate-500">Target</dt>
                                    <dd class="max-w-44 text-right text-sm font-bold text-slate-900">{{ targetApplicantLabel(selectedPreviewScholarship) }}</dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-3 last:pb-0">
                                    <dt class="text-xs font-semibold text-slate-500">Location</dt>
                                    <dd class="max-w-44 truncate text-right text-sm font-bold text-slate-900">
                                        {{ selectedPreviewScholarship.location_name || selectedPreviewScholarship.location_address || 'Not listed' }}
                                    </dd>
                                </div>
                            </dl>
                        </aside>
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <div>
                        <p class="text-sm font-bold text-slate-900">Manage this program</p>
                        <p class="mt-0.5 text-xs text-slate-500">Choose an action without leaving your program list.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="hasScholarshipMapPreview(selectedPreviewScholarship)"
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                            @click="openMapModal(selectedPreviewScholarship)"
                        >
                            <i class="fa-solid fa-location-dot mr-1.5" aria-hidden="true"></i>
                            Map
                        </button>
                        <button
                            v-if="canManagePrograms"
                            type="button"
                            :disabled="duplicatingId === selectedPreviewScholarship.id"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                            @click="duplicateProgram(selectedPreviewScholarship)"
                        >
                            <i v-if="duplicatingId !== selectedPreviewScholarship.id" class="fa-regular fa-copy mr-1.5" aria-hidden="true"></i>
                            {{ duplicatingId === selectedPreviewScholarship.id ? 'Duplicating...' : 'Duplicate' }}
                        </button>
                        <a
                            v-if="canManagePrograms"
                            :href="`/provider/programs/${selectedPreviewScholarship.id}/edit`"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                        >
                            <i class="fa-solid fa-pen mr-1.5" aria-hidden="true"></i>
                            Edit program
                        </a>
                        <a
                            v-if="canReviewApplications"
                            :href="`/provider/programs/${selectedPreviewScholarship.id}/applications`"
                            class="rounded-md bg-slate-900 px-3 py-2 text-center text-xs font-bold text-white transition hover:bg-slate-800"
                        >
                            <i class="fa-solid fa-users mr-1.5" aria-hidden="true"></i>
                            Applicants
                        </a>
                    </div>
                </div>
            </section>
        </div>

        <div
            v-if="selectedMapScholarship"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6"
            @click.self="closeMapModal"
        >
            <section class="max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-lg bg-white shadow-2xl">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">
                            Provider Map Preview
                        </p>
                        <h3 class="mt-1 text-xl font-bold text-slate-950">
                            {{ selectedMapScholarship.location_name || selectedMapScholarship.title }}
                        </h3>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ selectedMapScholarship.location_address || 'No map address added yet.' }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        @click="closeMapModal"
                    >
                        Close
                    </button>
                </div>

                <div class="bg-slate-100 p-4">
                    <LeafletMapPreview
                        :address="selectedMapAddress"
                        :latitude="selectedMapScholarship.latitude"
                        :longitude="selectedMapScholarship.longitude"
                        :title="selectedMapScholarship.location_name || selectedMapScholarship.title"
                        :marker-text="selectedMapScholarship.location_name || selectedMapScholarship.title"
                        height="55vh"
                        auto-geocode
                    />
                </div>

                <div class="flex flex-col gap-2 border-t border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-5 text-slate-500">
                        This Leaflet/OpenStreetMap preview is similar to what students will see when browsing scholarship locations.
                    </p>
                    <a
                        v-if="selectedMapScholarship.map_url"
                        :href="selectedMapScholarship.map_url"
                        target="_blank"
                        rel="noreferrer"
                        class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                    >
                        Open Full Map
                    </a>
                </div>
            </section>
        </div>
    </main>
</template>
