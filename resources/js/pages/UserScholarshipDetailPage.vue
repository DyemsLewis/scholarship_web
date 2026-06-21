<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';

const appElement = document.getElementById('app');
const scholarshipId = appElement?.dataset.scholarshipId;
const isLoading = ref(true);
const errorMessage = ref('');
const statusMessage = ref('');
const isSaving = ref(false);
const user = ref(null);
const scholarship = ref(null);
const showMapModal = ref(false);
const profileReadiness = ref({
    complete: false,
    completed: 0,
    total: 0,
    percent: 0,
    missing: [],
});

const documentItems = computed(() => documentRequirements(scholarship.value?.requirements));
const canApply = computed(() => profileReadiness.value.complete);
const scholarshipMapAddress = computed(() => {
    const parts = [
        scholarship.value?.location_address,
        scholarship.value?.location_name,
    ].filter(Boolean);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});
const hasMapPreview = computed(() => Boolean(
    (scholarship.value?.latitude && scholarship.value?.longitude)
    || scholarship.value?.location_address
    || scholarship.value?.location_name,
));

function formatAmount(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return 'Amount not set';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(amount));
}

function providerTypeLabel(type) {
    return String(type ?? 'Provider')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function documentRequirements(requirements) {
    if (!requirements) {
        return ['Not listed yet'];
    }

    return String(requirements)
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function matchClass(score) {
    if (Number(score) >= 80) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (Number(score) >= 50) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-rose-100 text-rose-800';
}

function criterionClass(status) {
    if (status === 'pass') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    }

    if (status === 'fail') {
        return 'border-rose-200 bg-rose-50 text-rose-800';
    }

    if (status === 'missing') {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    return 'border-slate-200 bg-slate-50 text-slate-600';
}

async function loadScholarship() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/scholarships/${scholarshipId}/data`);

        user.value = response.data.user;
        profileReadiness.value = response.data.profile_readiness ?? profileReadiness.value;
        scholarship.value = response.data.scholarship;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship details.';
    } finally {
        isLoading.value = false;
    }
}

async function toggleSave() {
    if (!scholarship.value) {
        return;
    }

    isSaving.value = true;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = scholarship.value.is_saved
            ? await window.axios.delete(`/dashboard/scholarships/${scholarship.value.id}/save`)
            : await window.axios.post(`/dashboard/scholarships/${scholarship.value.id}/save`);

        scholarship.value = response.data.scholarship;
        statusMessage.value = response.data.message ?? 'Saved scholarships updated.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update saved scholarship.';
    } finally {
        isSaving.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadScholarship);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar @logout="logout" />

        <section class="student-page">
            <div class="student-container">
                <div class="mb-4">
                    <a href="/dashboard/scholarships" class="text-sm font-bold text-sky-700 transition hover:text-sky-900">
                        Back to scholarships
                    </a>
                </div>

                <div v-if="isLoading" class="student-card p-6 text-sm text-slate-500">
                    Loading scholarship details...
                </div>

                <div v-else-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="scholarship" class="space-y-6">
                    <header class="student-hero">
                        <div class="grid gap-5 lg:grid-cols-[1fr_18rem] lg:items-start">
                            <div>
                                <p class="student-kicker">
                                    {{ scholarship.category || providerTypeLabel(scholarship.provider?.type) }}
                                </p>
                                <h1 class="mt-2 font-display text-2xl font-bold text-slate-950 sm:text-3xl">
                                    {{ scholarship.title }}
                                </h1>
                                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                                    {{ scholarship.description }}
                                </p>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                        {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                    </span>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ scholarship.deadline || 'No deadline' }}
                                    </span>
                                    <span v-if="scholarship.distance_label" class="rounded-md bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800">
                                        {{ scholarship.distance_label }}
                                    </span>
                                </div>
                            </div>

                            <div class="student-soft-card p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    Provider
                                </p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">
                                    {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                </h2>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ providerTypeLabel(scholarship.provider?.type) }}
                                </p>

                                <div class="mt-4 grid gap-3 text-sm">
                                    <div>
                                        <p class="font-semibold text-slate-500">Award</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ formatAmount(scholarship.award_amount) }}</p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">Minimum GWA / average</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ scholarship.minimum_gwa || 'Not listed yet' }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </header>

                    <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </div>

                    <div class="grid gap-4 xl:grid-cols-[1.15fr_0.85fr]">
                        <section class="space-y-4">
                            <article class="student-card p-5">
                                <p class="student-kicker">
                                    Eligibility
                                </p>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ scholarship.eligibility || 'No eligibility description has been posted yet.' }}
                                </p>

                                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-md border border-slate-200/80 bg-[#f6faf8] p-3 text-sm">
                                        <p class="font-semibold text-slate-500">Courses / strands</p>
                                        <p class="mt-1 text-slate-800">{{ scholarship.eligible_courses || 'Any' }}</p>
                                    </div>
                                    <div class="rounded-md border border-slate-200/80 bg-[#f6faf8] p-3 text-sm">
                                        <p class="font-semibold text-slate-500">Year levels</p>
                                        <p class="mt-1 text-slate-800">{{ scholarship.eligible_year_levels || 'Any' }}</p>
                                    </div>
                                    <div class="rounded-md border border-slate-200/80 bg-[#f6faf8] p-3 text-sm">
                                        <p class="font-semibold text-slate-500">Eligible locations</p>
                                        <p class="mt-1 text-slate-800">{{ scholarship.eligible_locations || 'Any' }}</p>
                                    </div>
                                    <div class="rounded-md border border-slate-200/80 bg-[#f6faf8] p-3 text-sm">
                                        <p class="font-semibold text-slate-500">Income rule</p>
                                        <p class="mt-1 text-slate-800">{{ scholarship.income_requirement || 'Any' }}</p>
                                    </div>
                                </div>
                            </article>

                            <article class="student-card p-5">
                                <p class="student-kicker">
                                    Eligibility Pre-check
                                </p>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ scholarship.eligibility_match?.summary || 'Review the listed requirements before applying.' }}
                                </p>

                                <details v-if="scholarship.eligibility_match?.criteria?.length" class="mt-4 rounded-md border border-slate-200 bg-[#f6faf8] p-3">
                                    <summary class="cursor-pointer text-sm font-bold text-slate-700">
                                        View matching checklist
                                    </summary>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        <div
                                            v-for="criterion in scholarship.eligibility_match.criteria"
                                            :key="criterion.key"
                                            :class="['rounded-md border p-3 text-sm', criterionClass(criterion.status)]"
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="font-bold">{{ criterion.label }}</p>
                                                <p class="text-xs font-bold uppercase">{{ criterion.status }}</p>
                                            </div>
                                            <p class="mt-2 text-xs leading-5">
                                                Profile: {{ criterion.student_value || criterion.studentValue || 'Not set' }}
                                            </p>
                                            <p v-if="criterion.requirement" class="mt-1 text-xs leading-5">
                                                Required: {{ criterion.requirement }}
                                            </p>
                                            <p class="mt-1 text-xs leading-5 opacity-80">
                                                {{ criterion.note }}
                                            </p>
                                        </div>
                                    </div>
                                </details>
                            </article>

                            <article class="student-card p-5">
                                <p class="student-kicker">
                                    Document Requirements
                                </p>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <span
                                        v-for="requirement in documentItems"
                                        :key="requirement"
                                        class="rounded-md border border-slate-200 bg-[#f6faf8] px-3 py-2 text-xs font-bold text-slate-700"
                                    >
                                        {{ requirement }}
                                    </span>
                                </div>
                            </article>
                        </section>

                        <aside class="space-y-4">
                            <article class="student-card p-5">
                                <p class="student-kicker">
                                    Map Location
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    {{ scholarship.location_name || 'Location not named' }}
                                </h3>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    {{ scholarship.location_address || 'No map address added yet.' }}
                                </p>
                                <p v-if="scholarship.distance_label" class="mt-2 text-xs font-bold text-sky-700">
                                    About {{ scholarship.distance_label }} from your saved location.
                                </p>

                                <button
                                    v-if="hasMapPreview"
                                    type="button"
                                    class="mt-4 w-full rounded-md border border-sky-200 px-4 py-2.5 text-sm font-bold text-sky-700 transition hover:bg-sky-50"
                                    @click="showMapModal = true"
                                >
                                    Preview Map
                                </button>
                            </article>

                            <article class="student-card p-5">
                                <p class="student-kicker">
                                    Actions
                                </p>
                                <div class="mt-4 grid gap-3">
                                    <button
                                        type="button"
                                        :disabled="isSaving"
                                        class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60"
                                        @click="toggleSave"
                                    >
                                        {{ isSaving ? 'Saving...' : scholarship.is_saved ? 'Remove saved' : 'Save scholarship' }}
                                    </button>
                                    <a
                                        v-if="scholarship.has_applied"
                                        href="/dashboard/applications"
                                        class="rounded-md bg-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-600"
                                    >
                                        Already applied
                                    </a>
                                    <a
                                        v-else-if="canApply"
                                        :href="`/dashboard/applications?scholarship=${scholarship.id}`"
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                    >
                                        Start application wizard
                                    </a>
                                    <a
                                        v-else
                                        href="/dashboard/profile"
                                        class="rounded-md bg-amber-500 px-4 py-2.5 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-400"
                                    >
                                        Complete profile first
                                    </a>
                                    <p v-if="!canApply && !scholarship.has_applied" class="text-xs leading-5 text-slate-500">
                                        Your profile is {{ profileReadiness.percent }}% complete. Finish it before applying.
                                    </p>
                                </div>
                            </article>
                        </aside>
                    </div>
                </div>

                <ApplicantFooter />
            </div>
        </section>

        <div
            v-if="showMapModal && scholarship"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6"
            @click.self="showMapModal = false"
        >
            <section class="max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-lg bg-white shadow-2xl">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-700">Map Preview</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-950">
                            {{ scholarship.location_name || scholarship.title }}
                        </h3>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ scholarship.location_address || 'No map address added yet.' }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        @click="showMapModal = false"
                    >
                        Close
                    </button>
                </div>

                <div class="bg-slate-100 p-4">
                    <LeafletMapPreview
                        :address="scholarshipMapAddress"
                        :latitude="scholarship.latitude"
                        :longitude="scholarship.longitude"
                        :title="scholarship.location_name || scholarship.title"
                        :marker-text="scholarship.location_name || scholarship.title"
                        height="55vh"
                        auto-geocode
                    />
                </div>

                <div class="flex flex-col gap-2 border-t border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-5 text-slate-500">
                        Map preview uses Leaflet CDN with OpenStreetMap address search.
                    </p>
                    <a
                        v-if="scholarship.map_url"
                        :href="scholarship.map_url"
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
