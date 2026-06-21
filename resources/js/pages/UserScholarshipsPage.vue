<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const statusMessage = ref('');
const savingId = ref(null);
const user = ref(null);
const scholarships = ref([]);
const search = ref('');
const selectedProviderType = ref('all');
const selectedCategory = ref('all');
const selectedIncome = ref('all');
const deadlineFilter = ref('all');
const maxGwa = ref('');
const minimumMatch = ref('');
const courseFilter = ref('');
const yearFilter = ref('');
const locationFilter = ref('');
const savedOnly = ref(false);
const showAdvancedFilters = ref(false);

const providerTypes = computed(() => [
    'all',
    ...new Set(scholarships.value.map((scholarship) => scholarship.provider?.type).filter(Boolean)),
]);
const categories = computed(() => [
    'all',
    ...new Set(scholarships.value.map((scholarship) => scholarship.category).filter(Boolean)),
]);
const incomeRequirements = computed(() => [
    'all',
    ...new Set(scholarships.value.map((scholarship) => scholarship.income_requirement).filter(Boolean)),
]);
const activeFilterCount = computed(() => [
    search.value.trim(),
    selectedProviderType.value !== 'all',
    selectedCategory.value !== 'all',
    selectedIncome.value !== 'all',
    deadlineFilter.value !== 'all',
    maxGwa.value,
    minimumMatch.value,
    courseFilter.value.trim(),
    yearFilter.value.trim(),
    locationFilter.value.trim(),
    savedOnly.value,
].filter(Boolean).length);
const filteredScholarships = computed(() => scholarships.value
    .filter((scholarship) => {
        const keyword = search.value.trim().toLowerCase();
        const matchScore = Number(scholarship.eligibility_match?.score ?? 0);
        const matchesSearch = !keyword || [
            scholarship.title,
            scholarship.description,
            scholarship.provider?.name,
            scholarship.category,
            scholarship.eligibility,
            scholarship.eligible_courses,
            scholarship.eligible_year_levels,
            scholarship.eligible_locations,
            scholarship.income_requirement,
            scholarship.location_name,
            scholarship.location_address,
            scholarship.requirements,
        ].filter(Boolean).some((value) => String(value).toLowerCase().includes(keyword));
        const matchesProvider = selectedProviderType.value === 'all' || scholarship.provider?.type === selectedProviderType.value;
        const matchesCategory = selectedCategory.value === 'all' || scholarship.category === selectedCategory.value;
        const studentGwa = Number(maxGwa.value);
        const minimumGwa = Number(scholarship.minimum_gwa);
        const matchesGwa = !maxGwa.value
            || !scholarship.minimum_gwa
            || (studentGwa <= 5 && minimumGwa <= 5 ? studentGwa <= minimumGwa : minimumGwa <= studentGwa);
        const matchesMinimum = !minimumMatch.value || matchScore >= Number(minimumMatch.value);
        const matchesCourse = textMatches(scholarship.eligible_courses, courseFilter.value);
        const matchesYear = textMatches(scholarship.eligible_year_levels, yearFilter.value);
        const matchesLocation = textMatches([scholarship.eligible_locations, scholarship.location_name, scholarship.location_address].filter(Boolean).join(' '), locationFilter.value);
        const matchesIncome = selectedIncome.value === 'all' || textMatches(scholarship.income_requirement, selectedIncome.value);
        const matchesSaved = !savedOnly.value || scholarship.is_saved;
        const matchesDeadline = deadlineMatches(scholarship);

        return matchesSearch
            && matchesProvider
            && matchesCategory
            && matchesGwa
            && matchesMinimum
            && matchesCourse
            && matchesYear
            && matchesLocation
            && matchesIncome
            && matchesSaved
            && matchesDeadline;
    })
    .sort((first, second) => {
        const scoreDifference = Number(second.eligibility_match?.score ?? 0) - Number(first.eligibility_match?.score ?? 0);

        return scoreDifference || deadlineValue(first) - deadlineValue(second);
    }));

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
        return [];
    }

    return String(requirements)
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function requirementsLabel(requirements) {
    const count = documentRequirements(requirements).length;

    return count === 0 ? 'Not listed yet' : `${count} item${count === 1 ? '' : 's'}`;
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

function textMatches(value, filter) {
    const needle = String(filter ?? '').trim().toLowerCase();

    if (!needle) {
        return true;
    }

    const haystack = String(value ?? '').toLowerCase();

    return haystack === '' || haystack.includes(needle) || needle.includes(haystack);
}

function deadlineValue(scholarship) {
    const parsed = Date.parse(scholarship.deadline ?? '');

    return Number.isNaN(parsed) ? Number.POSITIVE_INFINITY : parsed;
}

function deadlineDays(scholarship) {
    const due = deadlineValue(scholarship);

    if (!Number.isFinite(due)) {
        return null;
    }

    const today = new Date();
    const startOfToday = new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime();

    return Math.ceil((due - startOfToday) / 86400000);
}

function deadlineMatches(scholarship) {
    const days = deadlineDays(scholarship);

    if (deadlineFilter.value === 'no_deadline') {
        return days === null;
    }

    if (deadlineFilter.value === 'next_7_days') {
        return days !== null && days >= 0 && days <= 7;
    }

    if (deadlineFilter.value === 'next_30_days') {
        return days !== null && days >= 0 && days <= 30;
    }

    return true;
}

function resetFilters() {
    search.value = '';
    selectedProviderType.value = 'all';
    selectedCategory.value = 'all';
    selectedIncome.value = 'all';
    deadlineFilter.value = 'all';
    maxGwa.value = '';
    minimumMatch.value = '';
    courseFilter.value = '';
    yearFilter.value = '';
    locationFilter.value = '';
    savedOnly.value = false;
}

async function toggleSave(scholarship) {
    savingId.value = scholarship.id;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = scholarship.is_saved
            ? await window.axios.delete(`/dashboard/scholarships/${scholarship.id}/save`)
            : await window.axios.post(`/dashboard/scholarships/${scholarship.id}/save`);

        scholarships.value = scholarships.value.map((item) => (item.id === scholarship.id ? response.data.scholarship : item));
        statusMessage.value = response.data.message ?? 'Saved scholarships updated.';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update saved scholarship.';
    } finally {
        savingId.value = null;
    }
}

async function loadScholarships() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/data');

        user.value = response.data.user;
        scholarships.value = response.data.scholarships;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarships.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadScholarships);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar @logout="logout" />

        <section class="student-page">
            <div class="student-container">
                <header class="student-hero">
                    <p class="student-kicker">
                        Scholarships
                    </p>
                    <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 class="font-display text-2xl font-bold text-slate-950 sm:text-3xl">
                                Find a program that fits
                            </h2>
                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Search first, then open details only when a scholarship looks relevant.
                            </p>
                        </div>
                        <p class="student-chip">
                            {{ filteredScholarships.length }} of {{ scholarships.length }} shown
                        </p>
                    </div>
                </header>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading scholarships...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <section v-else class="mt-6 grid gap-5 xl:grid-cols-[18rem_1fr]">
                    <aside class="student-card h-fit overflow-hidden xl:sticky xl:top-6">
                        <div class="bg-slate-950 p-5 text-white">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-200">
                                Finder Controls
                            </p>
                            <h3 class="mt-2 font-display text-xl font-bold">
                                Narrow the list
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-300">
                                Use simple filters first. Open a full program only when it looks worth reviewing.
                            </p>
                        </div>

                        <div class="grid gap-3 p-4">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search program or provider"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >

                            <label class="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-[#f6faf8] px-3.5 py-2.5 text-sm font-semibold text-slate-700">
                                <span>Saved only</span>
                                <input
                                    v-model="savedOnly"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-sky-700 focus:ring-sky-200"
                                >
                            </label>

                            <select v-model="selectedCategory" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                <option v-for="category in categories" :key="category" :value="category">
                                    {{ category === 'all' ? 'All categories' : category }}
                                </option>
                            </select>

                            <select v-model="selectedProviderType" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                <option v-for="type in providerTypes" :key="type" :value="type">
                                    {{ type === 'all' ? 'All provider types' : providerTypeLabel(type) }}
                                </option>
                            </select>

                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                @click="showAdvancedFilters = !showAdvancedFilters"
                            >
                                {{ showAdvancedFilters ? 'Hide advanced' : 'Show advanced' }}
                            </button>

                            <div v-if="showAdvancedFilters" class="grid gap-3 border-t border-slate-200 pt-3">
                                <input v-model="maxGwa" type="number" min="0" max="100" step="0.01" placeholder="My GWA / avg" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                <input v-model="minimumMatch" type="number" min="0" max="100" step="1" placeholder="Minimum match %" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                <input v-model="courseFilter" type="search" placeholder="Course / strand" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                <input v-model="yearFilter" type="search" placeholder="Year level" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                <input v-model="locationFilter" type="search" placeholder="City, province, or region" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                <select v-model="selectedIncome" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                    <option v-for="income in incomeRequirements" :key="income" :value="income">
                                        {{ income === 'all' ? 'All income rules' : income }}
                                    </option>
                                </select>
                                <select v-model="deadlineFilter" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                    <option value="all">All deadlines</option>
                                    <option value="next_7_days">Due in 7 days</option>
                                    <option value="next_30_days">Due in 30 days</option>
                                    <option value="no_deadline">No deadline</option>
                                </select>
                            </div>

                            <button type="button" class="rounded-md bg-slate-900 px-3.5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800" @click="resetFilters">
                                Reset filters
                            </button>
                        </div>
                    </aside>

                    <section class="space-y-4">
                        <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                            {{ statusMessage }}
                        </div>

                        <div class="overflow-hidden rounded-lg border border-slate-800 bg-slate-950 text-white shadow-[0_24px_60px_rgba(15,23,42,0.18)]">
                            <div class="grid gap-4 p-5 lg:grid-cols-[1fr_auto] lg:items-center">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-200">
                                        Program Catalog
                                    </p>
                                    <h3 class="mt-2 font-display text-2xl font-bold">
                                        {{ filteredScholarships.length }} programs matched
                                    </h3>
                                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                                        Sorted by eligibility match first, then upcoming deadlines.
                                    </p>
                                </div>
                                <div class="grid grid-cols-2 gap-2 text-center sm:grid-cols-3">
                                    <div class="rounded-md bg-white/10 px-3 py-2">
                                        <p class="text-xl font-bold">{{ scholarships.length }}</p>
                                        <p class="text-xs text-slate-300">Total</p>
                                    </div>
                                    <div class="rounded-md bg-white/10 px-3 py-2">
                                        <p class="text-xl font-bold">{{ activeFilterCount }}</p>
                                        <p class="text-xs text-slate-300">Filters</p>
                                    </div>
                                    <div class="rounded-md bg-white/10 px-3 py-2">
                                        <p class="text-xl font-bold">{{ scholarships.filter((item) => item.is_saved).length }}</p>
                                        <p class="text-xs text-slate-300">Saved</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="scholarships.length === 0" class="student-card p-6">
                            <div class="rounded-lg border border-dashed border-slate-300 bg-[#f6faf8] p-6 text-sm text-slate-500">
                                No published scholarships yet. Once providers publish programs, they will show up here.
                            </div>
                        </div>

                        <div v-else-if="filteredScholarships.length === 0" class="student-card p-6">
                            <div class="rounded-lg border border-dashed border-slate-300 bg-[#f6faf8] p-6 text-sm text-slate-500">
                                No scholarships match your filters.
                            </div>
                        </div>

                        <div v-else class="grid gap-4">
                            <article
                                v-for="scholarship in filteredScholarships"
                                :key="scholarship.id"
                                class="group relative overflow-hidden rounded-lg border border-slate-200/80 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-sky-200 hover:shadow-[0_18px_45px_rgba(15,23,42,0.10)]"
                            >
                                <div class="absolute inset-y-0 left-0 w-1.5 bg-slate-900"></div>
                                <div class="pl-3">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div class="flex min-w-0 gap-3">
                                            <img
                                                :src="scholarship.image_url"
                                                :alt="scholarship.title"
                                                class="mt-0.5 h-14 w-14 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200/80"
                                            >
                                            <div class="min-w-0">
                                                <p class="truncate text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">
                                                    {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                                </p>
                                                <h3 class="mt-1 truncate text-xl font-bold text-slate-950">
                                                    {{ scholarship.title }}
                                                </h3>
                                                <p class="mt-1 truncate text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                                                    {{ scholarship.category || providerTypeLabel(scholarship.provider?.type) }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            <span :class="['inline-flex rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                                {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                            </span>
                                            <span class="inline-flex rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                {{ scholarship.deadline || 'No deadline' }}
                                            </span>
                                            <span v-if="scholarship.distance_label" class="inline-flex rounded-md bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800">
                                                {{ scholarship.distance_label }}
                                            </span>
                                        </div>
                                    </div>

                                    <p class="mt-3 line-clamp-2 text-sm leading-6 text-slate-600">
                                        {{ scholarship.description }}
                                    </p>

                                    <div class="mt-4 grid gap-3 text-sm md:grid-cols-3">
                                        <div class="rounded-md bg-[#f6faf8] p-3 ring-1 ring-slate-200/70">
                                            <p class="font-semibold text-slate-500">Award</p>
                                            <p class="mt-1 font-bold text-slate-950">{{ formatAmount(scholarship.award_amount) }}</p>
                                        </div>
                                        <div class="rounded-md bg-[#f6faf8] p-3 ring-1 ring-slate-200/70">
                                            <p class="font-semibold text-slate-500">GWA / avg</p>
                                            <p class="mt-1 font-bold text-slate-950">{{ scholarship.minimum_gwa || 'Not listed yet' }}</p>
                                        </div>
                                        <div class="rounded-md bg-[#f6faf8] p-3 ring-1 ring-slate-200/70">
                                            <p class="font-semibold text-slate-500">Requirements</p>
                                            <p class="mt-1 font-bold text-slate-950">{{ requirementsLabel(scholarship.requirements) }}</p>
                                            <p v-if="scholarship.prepared_documents?.required" class="mt-1 text-xs font-semibold text-sky-700">
                                                {{ scholarship.prepared_documents.uploaded }} ready in Documents
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-3 grid gap-2 border-t border-slate-200 pt-3 sm:grid-cols-3">
                                        <button
                                            type="button"
                                            :disabled="savingId === scholarship.id"
                                            class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="toggleSave(scholarship)"
                                        >
                                            {{ savingId === scholarship.id ? 'Saving...' : scholarship.is_saved ? 'Remove saved' : 'Save' }}
                                        </button>
                                        <a
                                            :href="`/dashboard/scholarships/${scholarship.id}`"
                                            class="block rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                        >
                                            View details
                                        </a>
                                        <a
                                            v-if="scholarship.has_applied"
                                            href="/dashboard/applications"
                                            class="block rounded-md bg-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-600"
                                        >
                                            Already applied
                                        </a>
                                        <a
                                            v-else
                                            :href="`/dashboard/applications?scholarship=${scholarship.id}`"
                                            class="block rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                        >
                                            Start application
                                        </a>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>
                </section>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
