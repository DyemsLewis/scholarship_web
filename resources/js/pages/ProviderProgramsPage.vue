<script setup>
import { computed, onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';

const isLoading = ref(true);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const selectedMapScholarship = ref(null);
const duplicatingId = ref(null);
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const canPostScholarships = computed(() => user.value?.can_post_scholarships);
const selectedMapAddress = computed(() => {
    const parts = [
        selectedMapScholarship.value?.location_address,
        selectedMapScholarship.value?.location_name,
    ].filter(Boolean);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});

function openMapModal(scholarship) {
    selectedMapScholarship.value = scholarship;
}

function closeMapModal() {
    selectedMapScholarship.value = null;
}

function hasScholarshipMapPreview(scholarship) {
    return Boolean(
        (scholarship.latitude && scholarship.longitude)
        || scholarship.location_address
        || scholarship.location_name,
    );
}

function statusLabel(status) {
    return String(status ?? 'draft')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'pending_review') {
        return 'bg-slate-100 text-slate-700';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function labelFromKey(value) {
    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
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

function formatAmount(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return 'Not set';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(amount));
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

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/profile/data');

        user.value = response.data.user;
        scholarships.value = response.data.scholarships;
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
    statusMessage.value = '';

    try {
        const response = await window.axios.post(`/provider/scholarships/${scholarship.id}/duplicate`);

        statusMessage.value = response.data.message ?? 'Program duplicated as a draft.';
        await loadProviderData();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to duplicate this program.';
    } finally {
        duplicatingId.value = null;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

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
                                Scholarship Programs
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Program directory
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Manage scholarship listings, draft updates, and programs awaiting admin review.
                            </p>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a
                                href="/provider/programs/create"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Create program
                            </a>
                            <a
                                href="/provider"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                            >
                                Back to Dashboard
                            </a>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading scholarship programs...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </div>

                    <div
                        v-if="!canPostScholarships"
                        class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm"
                    >
                        <p class="font-bold">
                            Provider verification required
                        </p>
                        <p class="mt-1 leading-6">
                            Your provider account is currently {{ user?.verification_status || 'pending' }}. An admin must approve the provider account before scholarships can be created or updated.
                        </p>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Saved Programs
                                </p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    Created scholarships
                                </h3>
                            </div>

                            <a
                                href="/provider/programs/create"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                            >
                                New program
                            </a>
                        </div>

                        <div v-if="scholarships.length === 0" class="p-6 text-sm text-slate-500">
                            No scholarships yet. Create your first scholarship program from the Create program page.
                        </div>

                        <div v-else class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                            <article
                                v-for="scholarship in scholarships"
                                :key="scholarship.id"
                                class="flex flex-col rounded-lg border border-slate-200 bg-slate-50 p-3"
                            >
                                <img
                                    :src="scholarship.image_url"
                                    :alt="scholarship.title"
                                    class="mb-3 h-14 w-14 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                >

                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h4 class="truncate text-base font-bold text-slate-950">
                                            {{ scholarship.title }}
                                        </h4>
                                        <p class="mt-1 truncate text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                            {{ scholarship.category || 'Uncategorized' }}
                                        </p>
                                        <p class="mt-2 inline-flex items-center rounded-md bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                            <i class="fa-solid fa-users mr-1.5"></i>
                                            {{ targetApplicantLabel(scholarship) }}
                                        </p>
                                    </div>

                                    <span :class="['shrink-0 rounded-md px-2 py-1 text-[11px] font-bold uppercase', statusClass(scholarship.status)]">
                                        {{ statusLabel(scholarship.status) }}
                                    </span>
                                </div>

                                <p class="mt-3 line-clamp-1 text-sm leading-5 text-slate-600">
                                    {{ scholarship.description }}
                                </p>

                                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-md bg-white px-3 py-2">
                                        <p class="font-semibold text-slate-500">
                                            Award
                                        </p>
                                        <p class="mt-1 truncate font-bold text-slate-950">
                                            {{ formatAmount(scholarship.award_amount) }}
                                        </p>
                                    </div>

                                    <div class="rounded-md bg-white px-3 py-2">
                                        <p class="font-semibold text-slate-500">
                                            Deadline
                                        </p>
                                        <p class="mt-1 truncate font-bold text-slate-950">
                                            {{ scholarship.deadline || 'Not set' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                                    <span class="rounded-md bg-white px-2.5 py-1">
                                        {{ academicRequirementLabel(scholarship) }}
                                    </span>
                                    <span
                                        v-if="scholarship.return_service_contract"
                                        class="rounded-md bg-white px-2.5 py-1"
                                    >
                                        Return service
                                    </span>
                                    <span
                                        v-if="scholarship.other_contract_terms"
                                        class="rounded-md bg-white px-2.5 py-1"
                                    >
                                        Contract terms
                                    </span>
                                    <span class="rounded-md bg-white px-2.5 py-1">
                                        {{ scholarship.bookmarks_count || 0 }} saved
                                    </span>
                                </div>

                                <div class="mt-auto flex flex-wrap gap-2 border-t border-slate-200 pt-3">
                                    <a
                                        :href="`/provider/programs/${scholarship.id}/edit`"
                                        class="min-w-24 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    >
                                        Edit
                                    </a>

                                    <a
                                        :href="`/provider/programs/${scholarship.id}/applications`"
                                        class="min-w-24 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    >
                                        Applicants
                                    </a>

                                    <button
                                        type="button"
                                        :disabled="duplicatingId === scholarship.id"
                                        class="min-w-24 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        @click="duplicateProgram(scholarship)"
                                    >
                                        {{ duplicatingId === scholarship.id ? 'Duplicating...' : 'Duplicate' }}
                                    </button>

                                    <button
                                        v-if="hasScholarshipMapPreview(scholarship)"
                                        type="button"
                                        class="min-w-24 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                        @click="openMapModal(scholarship)"
                                    >
                                        Map
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>

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
