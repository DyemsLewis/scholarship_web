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
        const matchesLocation = textMatches(scholarship.eligible_locations, locationFilter.value);
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
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900">
        <ApplicantSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
                                Scholarships
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Available scholarship programs
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Browse published scholarship programs from providers. Application submission can be connected next.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <img
                                :src="'/images/scholarship-cards.jpg'"
                                alt="Students in a learning space"
                                class="mb-3 h-28 w-full rounded-md object-cover sm:w-56"
                            >
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Applicant
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.name || 'Applicant' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading scholarships...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <section v-else class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div v-if="statusMessage" class="border-b border-emerald-100 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700">
                        {{ statusMessage }}
                    </div>

                    <div class="border-b border-slate-200 p-5">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Published Programs
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            {{ filteredScholarships.length }} of {{ scholarships.length }} program{{ scholarships.length === 1 ? '' : 's' }} available
                        </h3>
                        <p class="mt-2 text-sm text-slate-500">
                            Results are ranked by eligibility match first, then nearest deadline.
                        </p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search title, provider, or eligibility"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100 xl:col-span-2"
                            >
                            <select
                                v-model="selectedProviderType"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                                <option
                                    v-for="type in providerTypes"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ type === 'all' ? 'All provider types' : providerTypeLabel(type) }}
                                </option>
                            </select>
                            <select
                                v-model="selectedCategory"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                                <option
                                    v-for="category in categories"
                                    :key="category"
                                    :value="category"
                                >
                                    {{ category === 'all' ? 'All categories' : category }}
                                </option>
                            </select>
                            <input
                                v-model="maxGwa"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                placeholder="My GWA / avg"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                            <input
                                v-model="minimumMatch"
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                placeholder="Min match %"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                            <input
                                v-model="courseFilter"
                                type="search"
                                placeholder="Course / strand"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                            <input
                                v-model="yearFilter"
                                type="search"
                                placeholder="Year level"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                            <input
                                v-model="locationFilter"
                                type="search"
                                placeholder="City, province, or region"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                            <select
                                v-model="selectedIncome"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                                <option
                                    v-for="income in incomeRequirements"
                                    :key="income"
                                    :value="income"
                                >
                                    {{ income === 'all' ? 'All income rules' : income }}
                                </option>
                            </select>
                            <select
                                v-model="deadlineFilter"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                            >
                                <option value="all">All deadlines</option>
                                <option value="next_7_days">Due in 7 days</option>
                                <option value="next_30_days">Due in 30 days</option>
                                <option value="no_deadline">No deadline</option>
                            </select>
                            <label class="flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700">
                                <input
                                    v-model="savedOnly"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-sky-700 focus:ring-sky-200"
                                >
                                Saved only
                            </label>
                        </div>
                    </div>

                    <div v-if="scholarships.length === 0" class="p-6">
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No published scholarships yet. Once providers publish programs, they will show up here.
                        </div>
                    </div>

                    <div v-else-if="filteredScholarships.length === 0" class="p-6">
                        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No scholarships match your filters.
                        </div>
                    </div>

                    <div v-else class="grid gap-4 p-4 lg:grid-cols-2">
                        <article
                            v-for="scholarship in filteredScholarships"
                            :key="scholarship.id"
                            class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                        >
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">
                                        {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                    </p>
                                    <h3 class="mt-2 text-lg font-bold text-slate-950">
                                        {{ scholarship.title }}
                                    </h3>
                                    <p class="mt-1 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                                        {{ scholarship.category || providerTypeLabel(scholarship.provider?.type) }}
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <span :class="['inline-flex rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                        {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                    </span>
                                    <span class="inline-flex rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                        {{ scholarship.deadline || 'No deadline' }}
                                    </span>
                                </div>
                            </div>

                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ scholarship.description }}
                            </p>

                            <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                <div class="rounded-md bg-white p-3">
                                    <p class="font-semibold text-slate-500">
                                        Award
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ formatAmount(scholarship.award_amount) }}
                                    </p>
                                </div>

                                <div class="rounded-md bg-white p-3">
                                    <p class="font-semibold text-slate-500">
                                        Eligibility
                                    </p>
                                    <p class="mt-1 text-slate-700">
                                        {{ scholarship.eligibility || 'Not listed yet' }}
                                    </p>
                                </div>

                                <div class="rounded-md bg-white p-3">
                                    <p class="font-semibold text-slate-500">
                                        Minimum GWA / avg
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ scholarship.minimum_gwa || 'Not listed yet' }}
                                    </p>
                                </div>
                                <div class="rounded-md bg-white p-3">
                                    <p class="font-semibold text-slate-500">
                                        Saved by students
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ scholarship.bookmarks_count || 0 }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-4 rounded-lg border border-emerald-100 bg-white p-3 text-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="font-semibold text-slate-700">
                                            Eligibility pre-check
                                        </p>
                                        <p class="mt-1 leading-6 text-slate-600">
                                            {{ scholarship.eligibility_match?.summary || scholarship.eligibility_guide?.note || 'Review the listed requirements before applying.' }}
                                        </p>
                                    </div>
                                    <span :class="['shrink-0 rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                        {{ scholarship.eligibility_match?.label || 'Needs review' }}
                                    </span>
                                </div>

                                <div v-if="scholarship.eligibility_match?.criteria?.length" class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div
                                        v-for="criterion in scholarship.eligibility_match.criteria"
                                        :key="criterion.key"
                                        :class="['rounded-md border p-2.5 text-xs', criterionClass(criterion.status)]"
                                    >
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-bold">{{ criterion.label }}</span>
                                            <span class="font-bold uppercase">{{ criterion.status }}</span>
                                        </div>
                                        <p class="mt-1 leading-5">
                                            Profile: {{ criterion.student_value || criterion.studentValue || 'Not set' }}
                                        </p>
                                        <p v-if="criterion.requirement" class="mt-1 leading-5">
                                            Required: {{ criterion.requirement }}
                                        </p>
                                        <p class="mt-1 leading-5 opacity-80">
                                            {{ criterion.note }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 rounded-lg border border-sky-100 bg-white p-3 text-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="font-semibold text-slate-700">
                                        Document requirements
                                    </p>
                                    <span class="rounded-md bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">
                                        {{ documentRequirements(scholarship.requirements).length }} item{{ documentRequirements(scholarship.requirements).length === 1 ? '' : 's' }}
                                    </span>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span
                                        v-for="requirement in documentRequirements(scholarship.requirements)"
                                        :key="requirement"
                                        class="rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-bold text-slate-700"
                                    >
                                        {{ requirement }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-2 sm:grid-cols-[auto_1fr]">
                                <button
                                    type="button"
                                    :disabled="savingId === scholarship.id"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-white disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="toggleSave(scholarship)"
                                >
                                    {{ savingId === scholarship.id ? 'Saving...' : scholarship.is_saved ? 'Remove saved' : 'Save' }}
                                </button>
                                <a
                                    :href="`/dashboard/applications?scholarship=${scholarship.id}`"
                                    class="block rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                >
                                    Start application wizard
                                </a>
                            </div>
                        </article>
                    </div>
                </section>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
