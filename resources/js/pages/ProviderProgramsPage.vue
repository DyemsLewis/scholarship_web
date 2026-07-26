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
const canReviewApplications = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('review_applications'),
));
const verificationDocumentCount = computed(() => Number(user.value?.verification_documents_count ?? 0));
const statusFilterOptions = computed(() => {
    const options = [
        { value: 'all', label: 'All programs', count: scholarships.value.length },
        { value: 'draft', label: 'Drafts' },
        { value: 'pending_review', label: 'In review' },
        { value: 'published', label: 'Published' },
        { value: 'rejected', label: 'Needs changes' },
        { value: 'closed', label: 'Closed' },
    ];

    return options
        .map((option) => ({
            ...option,
            count: option.value === 'all'
                ? scholarships.value.length
                : scholarships.value.filter((scholarship) => scholarship.status === option.value).length,
        }))
        .filter((option) => option.value === 'all' || option.count > 0);
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
        return verificationDocumentCount.value
            ? 'Your proof is saved. Verify your email before an admin can complete the provider review.'
            : 'Verify your email and upload organization proof before creating scholarship programs.';
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
            <div class="mx-auto max-w-6xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Scholarship Programs
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Program directory
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Manage scholarship listings, draft updates, and programs awaiting admin review.
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
                            {{ verificationDocumentCount ? 'View verification' : 'Upload proof' }}
                        </a>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Your Programs
                                </p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    Scholarship directory
                                </h3>
                            </div>
                            <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                {{ scholarships.length }} {{ scholarships.length === 1 ? 'program' : 'programs' }}
                            </span>
                        </div>

                        <div v-if="scholarships.length === 0" class="p-6 text-sm text-slate-500">
                            {{ canPostScholarships
                                ? 'No scholarships yet. Create your first scholarship program from the Create program page.'
                                : 'No scholarships yet. Complete provider verification to unlock program creation.' }}
                        </div>

                        <div v-else class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="grid flex-1 gap-2 sm:grid-cols-[minmax(0,1fr)_12rem]">
                                <label class="relative">
                                    <span class="sr-only">Search programs</span>
                                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                    <input
                                        v-model="searchQuery"
                                        type="search"
                                        placeholder="Search programs"
                                        class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                    >
                                </label>
                                <label>
                                    <span class="sr-only">Filter program status</span>
                                    <select v-model="statusFilter" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none transition focus:border-slate-500">
                                        <option v-for="option in statusFilterOptions" :key="option.value" :value="option.value">
                                            {{ option.label }} ({{ option.count }})
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">
                                Showing {{ filteredScholarships.length }} of {{ scholarships.length }}
                            </p>
                        </div>

                        <div v-if="filteredScholarships.length" class="divide-y divide-slate-200">
                            <article
                                v-for="scholarship in filteredScholarships"
                                :key="scholarship.id"
                                class="flex flex-col gap-3 p-4 transition hover:bg-slate-50 lg:flex-row lg:items-center"
                            >
                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                    <img
                                        :src="scholarship.image_url"
                                        :alt="scholarship.title"
                                        class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0 flex-1">
                                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                                            <h4 class="min-w-0 truncate text-sm font-bold leading-5 text-slate-950">
                                                {{ scholarship.title }}
                                            </h4>
                                            <span :class="['shrink-0 rounded-md px-2 py-1 text-[9px] font-bold uppercase', programStatusClass(scholarship.status)]">
                                                {{ programStatusLabel(scholarship.status) }}
                                            </span>
                                        </div>
                                        <p class="mt-1 truncate text-xs text-slate-500">
                                            {{ scholarship.category || 'Uncategorized' }} - {{ targetApplicantLabel(scholarship) }}
                                        </p>
                                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] font-semibold text-slate-500">
                                            <span class="inline-flex items-center gap-1.5">
                                                <i class="fa-regular fa-calendar text-slate-400" aria-hidden="true"></i>
                                                {{ programDeadlineLabel(scholarship.deadline) }}
                                            </span>
                                            <span class="inline-flex items-center gap-1.5">
                                                <i class="fa-solid fa-users text-slate-400" aria-hidden="true"></i>
                                                {{ scholarship.applications_count ?? 0 }} applicants
                                            </span>
                                            <span
                                                v-if="Number(scholarship.pending_review_applications_count ?? 0) > 0"
                                                class="inline-flex items-center gap-1.5 text-amber-700"
                                            >
                                                <i class="fa-solid fa-inbox" aria-hidden="true"></i>
                                                {{ scholarship.pending_review_applications_count }} to review
                                            </span>
                                            <span class="inline-flex items-center gap-1.5">
                                                <i class="fa-solid fa-user-check text-slate-400" aria-hidden="true"></i>
                                                {{ programSlotLabel(scholarship) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-wrap gap-2 lg:justify-end">
                                    <button
                                        type="button"
                                        class="min-w-20 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                        @click="openPreviewModal(scholarship)"
                                    >
                                        Preview
                                    </button>

                                    <a
                                        v-if="canReviewApplications"
                                        :href="`/provider/programs/${scholarship.id}/applications`"
                                        class="min-w-24 rounded-md bg-slate-900 px-3 py-2 text-center text-xs font-bold text-white transition hover:bg-slate-800"
                                    >
                                        Workspace
                                    </a>

                                    <a
                                        v-if="canManagePrograms"
                                        :href="`/provider/programs/${scholarship.id}/edit`"
                                        class="min-w-20 rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    >
                                        Edit
                                    </a>

                                    <button
                                        v-if="canManagePrograms"
                                        type="button"
                                        :disabled="duplicatingId === scholarship.id"
                                        class="min-w-20 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        @click="duplicateProgram(scholarship)"
                                    >
                                        {{ duplicatingId === scholarship.id ? 'Duplicating...' : 'Duplicate' }}
                                    </button>
                                </div>
                            </article>
                        </div>

                        <div v-else class="p-6 text-center">
                            <p class="text-sm font-bold text-slate-900">No matching programs</p>
                            <p class="mt-1 text-sm text-slate-500">Try another search or program status.</p>
                            <button
                                type="button"
                                class="mt-3 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                @click="searchQuery = ''; statusFilter = 'all'"
                            >
                                Clear filters
                            </button>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>

        <div
            v-if="selectedPreviewScholarship"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6"
            @click.self="closePreviewModal"
        >
            <section class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-lg bg-white shadow-2xl">
                <div class="flex items-start gap-4 border-b border-slate-200 p-5">
                    <img
                        :src="selectedPreviewScholarship.image_url"
                        :alt="selectedPreviewScholarship.title"
                        class="h-14 w-14 shrink-0 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200"
                    >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Applicant preview</p>
                            <span :class="['rounded-md px-2 py-1 text-[9px] font-bold uppercase', programStatusClass(selectedPreviewScholarship.status)]">
                                {{ programStatusLabel(selectedPreviewScholarship.status) }}
                            </span>
                        </div>
                        <h3 class="mt-1 text-xl font-bold text-slate-950">
                            {{ selectedPreviewScholarship.title }}
                        </h3>
                        <p class="mt-1 text-xs font-semibold text-slate-500">
                            {{ selectedPreviewScholarship.category || 'Scholarship program' }} - {{ targetApplicantLabel(selectedPreviewScholarship) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        @click="closePreviewModal"
                    >
                        Close
                    </button>
                </div>

                <div class="space-y-4 p-5">
                    <p class="text-sm leading-6 text-slate-600">
                        {{ selectedPreviewScholarship.description || 'No public description has been added yet.' }}
                    </p>

                    <div class="grid gap-2 sm:grid-cols-3">
                        <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Deadline</p>
                            <p class="mt-1 text-sm font-bold text-slate-900">{{ programDeadlineLabel(selectedPreviewScholarship.deadline) }}</p>
                        </div>
                        <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Applicants</p>
                            <p class="mt-1 text-sm font-bold text-slate-900">{{ selectedPreviewScholarship.applications_count ?? 0 }}</p>
                        </div>
                        <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Capacity</p>
                            <p class="mt-1 text-sm font-bold text-slate-900">{{ programSlotLabel(selectedPreviewScholarship) }}</p>
                        </div>
                    </div>

                    <div class="rounded-md border border-amber-200 bg-amber-50 p-4">
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Benefits</p>
                        <p class="mt-1 text-sm font-semibold leading-6 text-slate-900">
                            {{ selectedPreviewScholarship.benefit_summary || 'Benefits will be confirmed by the provider.' }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Who can apply</p>
                            <p class="mt-2 text-sm font-semibold text-slate-800">{{ targetApplicantLabel(selectedPreviewScholarship) }}</p>
                            <p v-if="selectedPreviewScholarship.minimum_grade_label" class="mt-1 text-xs leading-5 text-slate-500">
                                {{ selectedPreviewScholarship.minimum_grade_label }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Main requirements</p>
                            <ul v-if="previewRequirements(selectedPreviewScholarship).length" class="mt-2 space-y-1.5 text-sm text-slate-700">
                                <li v-for="requirement in previewRequirements(selectedPreviewScholarship)" :key="requirement" class="flex gap-2">
                                    <i class="fa-solid fa-check mt-1 text-[9px] text-emerald-600" aria-hidden="true"></i>
                                    <span>{{ requirement }}</span>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm text-slate-500">No document requirements added yet.</p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2 border-t border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-500">Read-only preview of the information applicants use to evaluate this program.</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="hasScholarshipMapPreview(selectedPreviewScholarship)"
                            type="button"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                            @click="openMapModal(selectedPreviewScholarship)"
                        >
                            View map
                        </button>
                        <a
                            v-if="canReviewApplications"
                            :href="`/provider/programs/${selectedPreviewScholarship.id}/applications`"
                            class="rounded-md bg-slate-900 px-3 py-2 text-center text-xs font-bold text-white transition hover:bg-slate-800"
                        >
                            Open workspace
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
