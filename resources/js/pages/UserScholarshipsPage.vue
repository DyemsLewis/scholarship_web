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
const selectedEducationLevel = ref('all');
const selectedSchoolType = ref('all');
const deadlineFilter = ref('all');
const maxGwa = ref('');
const minimumMatch = ref('');
const courseFilter = ref('');
const yearFilter = ref('');
const locationFilter = ref('');
const savedOnly = ref(false);
const showAdvancedFilters = ref(false);
const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];
const dssGuideItems = [
    { label: 'Profile', description: 'Grades, level, course or strand, school type, and year level.' },
    { label: 'Provider rules', description: 'Location, income, and required documents listed by the scholarship.' },
    { label: 'Score', description: 'Matched rules divided by applicable rules. It is a guide, not final approval.' },
];

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
const educationLevels = computed(() => [
    'all',
    ...new Set(scholarships.value
        .flatMap((scholarship) => splitOptions(scholarship.eligible_education_levels))
        .filter(Boolean)),
]);
const schoolTypes = computed(() => [
    'all',
    ...new Set(scholarships.value
        .flatMap((scholarship) => splitOptions(scholarship.eligible_school_types))
        .filter(Boolean)),
]);
const activeFilterCount = computed(() => [
    search.value.trim(),
    selectedProviderType.value !== 'all',
    selectedCategory.value !== 'all',
    selectedIncome.value !== 'all',
    selectedEducationLevel.value !== 'all',
    selectedSchoolType.value !== 'all',
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
        const locationText = [scholarship.eligible_locations, scholarship.location_name, scholarship.location_address].filter(Boolean).join(' ');
        const matchesSearch = !keyword || [
            scholarship.title,
            scholarship.description,
            scholarship.provider?.name,
            scholarship.category,
            scholarship.eligibility,
            scholarship.eligible_education_levels,
            scholarship.eligible_courses,
            scholarship.eligible_school_types,
            scholarship.eligible_year_levels,
            scholarship.income_requirement,
            scholarship.requirements,
            scholarship.application_mode,
            scholarship.renewal_policy,
            scholarship.contact_email,
            scholarship.contact_number,
        ].filter(Boolean).some((value) => String(value).toLowerCase().includes(keyword)) || locationSearchMatches(locationText, search.value);
        const matchesProvider = selectedProviderType.value === 'all' || scholarship.provider?.type === selectedProviderType.value;
        const matchesCategory = selectedCategory.value === 'all' || scholarship.category === selectedCategory.value;
        const matchesGwa = matchesAcademicRequirement(scholarship, maxGwa.value);
        const matchesMinimum = !minimumMatch.value || matchScore >= Number(minimumMatch.value);
        const matchesCourse = textMatches(scholarship.eligible_courses, courseFilter.value);
        const matchesYear = textMatches(scholarship.eligible_year_levels, yearFilter.value);
        const matchesEducationLevel = selectedEducationLevel.value === 'all' || textMatches(scholarship.eligible_education_levels, selectedEducationLevel.value);
        const matchesSchoolType = selectedSchoolType.value === 'all' || textMatches(scholarship.eligible_school_types, selectedSchoolType.value);
        const matchesLocation = locationMatches(locationText, locationFilter.value);
        const matchesIncome = selectedIncome.value === 'all' || textMatches(scholarship.income_requirement, selectedIncome.value);
        const matchesSaved = !savedOnly.value || scholarship.is_saved;
        const matchesDeadline = deadlineMatches(scholarship);

        return matchesSearch
            && matchesProvider
            && matchesCategory
            && matchesGwa
            && matchesMinimum
            && matchesEducationLevel
            && matchesSchoolType
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
        return 'Not listed yet';
    }

    return inferGradeScale(scholarship.minimum_gwa) === 'grade_point'
        ? `Maximum GWA/GPA ${scholarship.minimum_gwa}`
        : `Minimum average ${scholarship.minimum_gwa}%`;
}

function matchesAcademicRequirement(scholarship, studentValue) {
    if (!studentValue || !scholarship.minimum_gwa) {
        return true;
    }

    const requiredScale = scholarship.minimum_grade_scale || inferGradeScale(scholarship.minimum_gwa);

    if (!['percentage', 'grade_point'].includes(requiredScale)) {
        return true;
    }

    const studentScale = inferGradeScale(studentValue);

    if (studentScale !== requiredScale) {
        return false;
    }

    const studentNumber = Number(studentValue);
    const requiredNumber = Number(scholarship.minimum_gwa);

    return requiredScale === 'grade_point'
        ? studentNumber <= requiredNumber
        : studentNumber >= requiredNumber;
}

function providerTypeLabel(type) {
    return String(type ?? 'Provider')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function labelFromKey(value) {
    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function applicationModeLabel(value) {
    return applicationModeOptions.find((option) => option.value === value)?.label ?? labelFromKey(value || 'not_listed');
}

function splitOptions(value) {
    if (!value) {
        return [];
    }

    return String(value)
        .split(/\r?\n|,/)
        .map((option) => option.trim())
        .filter(Boolean);
}

function targetApplicantLabel(scholarship) {
    const levels = splitOptions(scholarship.eligible_education_levels);

    if (levels.length === 0 || levels.length >= 7) {
        return 'All learners';
    }

    if (levels.includes('preschool') && levels.includes('elementary') && levels.length === 2) {
        return 'Preschool / Elementary';
    }

    return levels.slice(0, 2).map(labelFromKey).join(', ') + (levels.length > 2 ? ` +${levels.length - 2}` : '');
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

function matchLabel(score) {
    if (Number(score) >= 80) {
        return 'Strong match';
    }

    if (Number(score) >= 50) {
        return 'Review fit';
    }

    return 'Needs checking';
}

function canStartApplication(scholarship) {
    if (scholarship?.can_start_application !== undefined) {
        return Boolean(scholarship.can_start_application);
    }

    return scholarship?.eligibility_match?.is_eligible !== false;
}

function applicationBlockedLabel(scholarship) {
    const blockers = scholarship?.eligibility_match?.blocking_criteria ?? [];
    const labels = blockers
        .map((criterion) => criterion.label)
        .filter(Boolean)
        .slice(0, 2);

    return labels.length
        ? `Not eligible: ${labels.join(', ')}`
        : 'Complete your profile or review eligibility first.';
}

function applicationBlockedActionLabel(scholarship) {
    return scholarship?.eligibility_match?.is_eligible === false
        ? 'Not eligible'
        : 'Complete profile first';
}

function matchReasonHeading(scholarship) {
    return scholarship?.eligibility_match?.is_eligible === false
        ? 'What to check'
        : 'Why this fits';
}

function highlightedCriteria(scholarship) {
    const criteria = scholarship?.eligibility_match?.criteria ?? [];

    if (!criteria.length) {
        return [];
    }

    const failing = criteria.filter((criterion) => criterion.status === 'fail' && criterion.key !== 'documents');
    const missing = criteria.filter((criterion) => criterion.status === 'missing');
    const passing = criteria.filter((criterion) => criterion.status === 'pass');
    const selected = [...failing, ...missing, ...passing];

    return (selected.length ? selected : criteria).slice(0, 3);
}

function criterionReasonClass(status) {
    if (status === 'fail') {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }

    if (status === 'missing') {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    if (status === 'pass') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    }

    return 'border-slate-200 bg-white text-slate-600';
}

function criterionReasonText(criterion) {
    const label = criterion?.label || 'Requirement';

    if (criterion?.status === 'fail') {
        return `${label} not matched`;
    }

    if (criterion?.status === 'missing') {
        return `${label} missing`;
    }

    if (criterion?.status === 'pass') {
        return `${label} matched`;
    }

    return criterion?.note || `${label} open`;
}

function deadlineLabel(scholarship) {
    const days = deadlineDays(scholarship);

    if (days === null) {
        return 'No deadline listed';
    }

    if (days < 0) {
        return 'Deadline passed';
    }

    if (days === 0) {
        return 'Due today';
    }

    if (days === 1) {
        return 'Due tomorrow';
    }

    return `${scholarship.deadline} (${days} days left)`;
}

function deadlineClass(scholarship) {
    const days = deadlineDays(scholarship);

    if (days === null) {
        return 'bg-slate-100 text-slate-700';
    }

    if (days <= 7) {
        return 'bg-rose-100 text-rose-800';
    }

    if (days <= 30) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-emerald-100 text-emerald-800';
}

function textMatches(value, filter) {
    const needle = String(filter ?? '').trim().toLowerCase();

    if (!needle) {
        return true;
    }

    const haystack = String(value ?? '').toLowerCase();

    return haystack === '' || haystack.includes(needle) || needle.includes(haystack);
}

function normalizeLocation(value) {
    return String(value ?? '')
        .toLowerCase()
        .replace(/[.;:]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function isOpenPhilippineLocation(value) {
    const normalized = normalizeLocation(value);

    if (!normalized) {
        return false;
    }

    return [
        'all locations',
        'any location',
        'all regions',
        'any region',
        'nationwide',
        'philippines',
        'the philippines',
        'republic of the philippines',
        'nationwide philippines',
        'philippines nationwide',
        'anywhere in the philippines',
        'within the philippines',
        'all over the philippines',
        'all philippines',
    ].includes(normalized)
        || normalized.includes('open to all')
        || normalized.includes('no restriction')
        || (normalized.includes('nationwide') && !normalized.includes('not nationwide'));
}

function locationMatches(value, filter) {
    const needle = normalizeLocation(filter);
    const haystack = normalizeLocation(value);

    if (!needle) {
        return true;
    }

    if (!haystack || isOpenPhilippineLocation(haystack)) {
        return true;
    }

    if (isOpenPhilippineLocation(needle) && isOpenPhilippineLocation(haystack)) {
        return true;
    }

    return haystack.includes(needle) || needle.includes(haystack);
}

function locationSearchMatches(value, filter) {
    const needle = normalizeLocation(filter);
    const haystack = normalizeLocation(value);

    if (!needle || !haystack) {
        return false;
    }

    if (isOpenPhilippineLocation(needle) && isOpenPhilippineLocation(haystack)) {
        return true;
    }

    return haystack.includes(needle) || needle.includes(haystack);
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
    selectedEducationLevel.value = 'all';
    selectedSchoolType.value = 'all';
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
                    <div class="max-w-2xl">
                        <p class="student-kicker">
                            Scholarships
                        </p>
                        <h2 class="mt-2 font-display text-2xl font-bold text-slate-950 sm:text-3xl">
                            Find a program that fits
                        </h2>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            Browse approved scholarships and open the details page when a program looks relevant.
                        </p>
                    </div>
                </header>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading scholarships...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <section v-else class="mt-6 space-y-5">
                    <section class="student-card overflow-hidden">
                        <div class="flex flex-col gap-3 bg-slate-950 p-4 text-white lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-200">
                                    Filters
                                </p>
                                <h3 class="mt-1 font-display text-xl font-bold">
                                    Narrow the list
                                </h3>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <p class="rounded-md bg-white/10 px-3 py-2 text-xs font-bold text-slate-200">
                                    {{ filteredScholarships.length }} program{{ filteredScholarships.length === 1 ? '' : 's' }} matched
                                </p>
                                <p class="rounded-md bg-white/10 px-3 py-2 text-xs font-bold text-slate-200">
                                    {{ activeFilterCount }} filter{{ activeFilterCount === 1 ? '' : 's' }} active
                                </p>
                            </div>
                        </div>

                        <details class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <summary class="cursor-pointer text-sm font-bold text-slate-800">
                                How DSS matching works
                            </summary>
                            <div class="mt-3 grid gap-2 text-sm text-slate-600 md:grid-cols-3">
                                <div
                                    v-for="item in dssGuideItems"
                                    :key="item.label"
                                    class="rounded-md border border-slate-200 bg-white p-3"
                                >
                                    <p class="font-bold text-slate-900">{{ item.label }}</p>
                                    <p class="mt-1 leading-5">{{ item.description }}</p>
                                </div>
                            </div>
                        </details>

                        <div class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-[minmax(14rem,2fr)_auto_minmax(10rem,1fr)_minmax(10rem,1fr)_auto_auto] xl:items-center">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search program or provider"
                                class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                            >

                            <label class="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm font-semibold text-slate-700">
                                <span>Saved only</span>
                                <input
                                    v-model="savedOnly"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-200"
                                >
                            </label>

                            <select v-model="selectedCategory" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                <option v-for="category in categories" :key="category" :value="category">
                                    {{ category === 'all' ? 'All categories' : category }}
                                </option>
                            </select>

                            <select v-model="selectedProviderType" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
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

                            <div v-if="showAdvancedFilters" class="grid gap-3 border-t border-slate-200 pt-3 md:col-span-2 md:grid-cols-2 xl:col-span-6 xl:grid-cols-4">
                                <select v-model="selectedEducationLevel" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    <option v-for="level in educationLevels" :key="level" :value="level">
                                        {{ level === 'all' ? 'All education levels' : labelFromKey(level) }}
                                    </option>
                                </select>
                                <select v-model="selectedSchoolType" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    <option v-for="type in schoolTypes" :key="type" :value="type">
                                        {{ type === 'all' ? 'All school types' : labelFromKey(type) }}
                                    </option>
                                </select>
                                <input v-model="maxGwa" type="number" min="0" max="100" step="0.01" placeholder="My grade value, e.g. 85 or 2.00" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                <input v-model="minimumMatch" type="number" min="0" max="100" step="1" placeholder="Minimum match %" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                <input v-model="courseFilter" type="search" placeholder="Track / strand / course" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                <input v-model="yearFilter" type="search" placeholder="Grade / year level" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                <input v-model="locationFilter" type="search" placeholder="City, province, or region" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                <select v-model="selectedIncome" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    <option v-for="income in incomeRequirements" :key="income" :value="income">
                                        {{ income === 'all' ? 'All income rules' : income }}
                                    </option>
                                </select>
                                <select v-model="deadlineFilter" class="rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
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
                    </section>

                    <section class="space-y-4">
                        <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                            {{ statusMessage }}
                        </div>

                        <div v-if="scholarships.length === 0" class="student-card p-6">
                            <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                                No published scholarships yet. Once providers publish programs, they will show up here.
                            </div>
                        </div>

                        <div v-else-if="filteredScholarships.length === 0" class="student-card p-6">
                            <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                                No scholarships match your filters.
                            </div>
                        </div>

                        <div v-else class="grid gap-4">
                            <article
                                v-for="scholarship in filteredScholarships"
                                :key="scholarship.id"
                                class="group rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-amber-200 hover:shadow-md"
                            >
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="flex min-w-0 gap-3">
                                        <img
                                            :src="scholarship.image_url"
                                            :alt="scholarship.title"
                                            class="h-14 w-14 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                {{ scholarship.provider?.name || 'Scholarship Provider' }} - {{ scholarship.category || providerTypeLabel(scholarship.provider?.type) }}
                                            </p>
                                            <h3 class="mt-1 text-lg font-bold leading-tight text-slate-950">
                                                {{ scholarship.title }}
                                            </h3>
                                            <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-600">
                                                {{ scholarship.description }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 flex-wrap gap-2 lg:justify-end">
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                            {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                        </span>
                                        <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                            {{ scholarship.deadline || 'No deadline' }}
                                        </span>
                                    </div>
                                </div>

                                <dl class="mt-4 grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                    <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <dt class="font-semibold text-slate-500">Award</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ formatAmount(scholarship.award_amount) }}</dd>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <dt class="font-semibold text-slate-500">For</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ targetApplicantLabel(scholarship) }}</dd>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <dt class="font-semibold text-slate-500">Grades</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ academicRequirementLabel(scholarship) }}</dd>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <dt class="font-semibold text-slate-500">Documents</dt>
                                        <dd class="mt-1 line-clamp-2 font-bold text-slate-950">{{ requirementsLabel(scholarship.requirements) }}</dd>
                                    </div>
                                </dl>

                                <div
                                    v-if="highlightedCriteria(scholarship).length"
                                    class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3"
                                >
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            {{ matchReasonHeading(scholarship) }}
                                        </p>
                                        <p class="text-xs font-semibold text-slate-500">
                                            {{ scholarship.eligibility_match?.summary || 'Profile match will update as your details change.' }}
                                        </p>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="criterion in highlightedCriteria(scholarship)"
                                            :key="`${scholarship.id}-${criterion.key}`"
                                            :class="['rounded-md border px-2.5 py-1 text-xs font-bold', criterionReasonClass(criterion.status)]"
                                        >
                                            {{ criterionReasonText(criterion) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                                    <span class="rounded-md bg-white px-2.5 py-1 ring-1 ring-slate-200">
                                        {{ applicationModeLabel(scholarship.application_mode) }}
                                    </span>
                                    <span class="rounded-md bg-white px-2.5 py-1 ring-1 ring-slate-200">
                                        Slots: {{ scholarship.slots_available ?? 'Not listed' }}
                                    </span>
                                    <span v-if="scholarship.distance_label" class="rounded-md bg-white px-2.5 py-1 ring-1 ring-slate-200">
                                        {{ scholarship.distance_label }}
                                    </span>
                                </div>

                                <div class="mt-4 flex flex-col gap-2 border-t border-slate-200 pt-3 sm:flex-row sm:items-center sm:justify-end">
                                    <button
                                        type="button"
                                        :disabled="savingId === scholarship.id"
                                        class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        @click="toggleSave(scholarship)"
                                    >
                                        <i :class="[scholarship.is_saved ? 'fa-solid' : 'fa-regular', 'fa-bookmark mr-1.5']"></i>
                                        {{ savingId === scholarship.id ? 'Saving...' : scholarship.is_saved ? 'Saved' : 'Save' }}
                                    </button>
                                    <a
                                        :href="`/dashboard/scholarships/${scholarship.id}`"
                                        class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                    >
                                        View details
                                    </a>
                                    <a
                                        v-if="scholarship.has_applied"
                                        href="/dashboard/applications"
                                        class="rounded-md bg-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-600"
                                    >
                                        Already applied
                                    </a>
                                    <a
                                        v-else-if="canStartApplication(scholarship)"
                                        :href="`/dashboard/applications?scholarship=${scholarship.id}`"
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                    >
                                        Start application
                                    </a>
                                    <span
                                        v-else
                                        class="rounded-md bg-slate-200 px-4 py-2.5 text-center text-sm font-bold text-slate-600"
                                    >
                                        {{ applicationBlockedActionLabel(scholarship) }}
                                    </span>
                                </div>
                                <p v-if="!scholarship.has_applied && !canStartApplication(scholarship)" class="mt-2 text-right text-xs leading-5 text-slate-500">
                                    {{ applicationBlockedLabel(scholarship) }}
                                </p>
                            </article>
                        </div>
                    </section>
                </section>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
