<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantGuideStrip from '../components/ApplicantGuideStrip.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
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
const finderGuideItems = [
    {
        title: 'Filter lightly',
        text: 'Start with search or category, then add advanced filters only when needed.',
        icon: 'fa-solid fa-sliders',
    },
    {
        title: 'Check the fit',
        text: 'Use match badges and short reasons before opening full details.',
        icon: 'fa-solid fa-chart-simple',
    },
    {
        title: 'Save or apply',
        text: 'Save programs for later or start the application when your profile fits.',
        icon: 'fa-solid fa-bookmark',
    },
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
            scholarship.return_service_contract,
            scholarship.other_contract_terms,
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

function scholarshipSnapshot(scholarship) {
    return [
        { label: 'Award', value: formatAmount(scholarship.award_amount) },
        { label: 'For', value: targetApplicantLabel(scholarship) },
        { label: 'Docs', value: requirementsLabel(scholarship.requirements) },
    ];
}

function visibleMatchReasons(scholarship) {
    return highlightedCriteria(scholarship).slice(0, 2);
}

function slotLabel(scholarship) {
    const slots = scholarship?.slots_available;

    if (slots === null || slots === undefined || slots === '') {
        return 'Slots not listed';
    }

    return `${slots} slot${Number(slots) === 1 ? '' : 's'}`;
}

function coverageLabel(scholarship) {
    const coverage = scholarship?.eligible_locations || scholarship?.location_name || scholarship?.location_address;

    if (!coverage) {
        return 'Coverage not listed';
    }

    return String(coverage)
        .split(/\r?\n/)
        .map((item) => item.trim())
        .filter(Boolean)
        .join(', ');
}

function documentReadinessLabel(scholarship) {
    const readiness = scholarship?.prepared_documents;
    const required = Number(readiness?.required ?? 0);
    const uploaded = Number(readiness?.uploaded ?? 0);

    if (required === 0) {
        return 'No required documents listed';
    }

    return `${uploaded} of ${required} ready`;
}

function documentReadinessHint(scholarship) {
    const readiness = scholarship?.prepared_documents;
    const required = Number(readiness?.required ?? 0);
    const uploaded = Number(readiness?.uploaded ?? 0);

    if (required === 0) {
        return 'Provider has not listed document requirements yet.';
    }

    if (uploaded >= required) {
        return 'Your prepared documents cover this program.';
    }

    const missing = (readiness?.missing ?? []).slice(0, 2);

    return missing.length
        ? `Missing: ${missing.join(', ')}${(readiness?.missing ?? []).length > 2 ? '...' : ''}`
        : 'Upload matching documents before applying.';
}

function documentReadinessWidth(scholarship) {
    const percent = Number(scholarship?.prepared_documents?.percent ?? 0);

    return `${Math.min(Math.max(percent, 0), 100)}%`;
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
                <ApplicantPageHeader
                    eyebrow="Scholarships"
                    title="Find programs that fit"
                    description="Browse approved scholarships, compare fit, and open details when one looks right."
                    icon="fa-solid fa-magnifying-glass-chart"
                    action-href="/dashboard/applications"
                    action-label="Go to applications"
                    secondary-href="/dashboard/profile"
                    secondary-label="Improve profile"
                />

                <ApplicantGuideStrip class="mt-5" :items="finderGuideItems" />

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
                                class="group overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm transition hover:border-slate-300 hover:shadow-md"
                            >
                                <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_17rem]">
                                    <div class="p-4">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                            <img
                                                :src="scholarship.image_url"
                                                :alt="scholarship.title"
                                                class="h-16 w-16 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                            >
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                                        {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                                    </span>
                                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                        {{ scholarship.category || providerTypeLabel(scholarship.provider?.type) }}
                                                    </span>
                                                    <span
                                                        v-if="scholarship.return_service_contract"
                                                        class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700"
                                                    >
                                                        Return service
                                                    </span>
                                                    <span
                                                        v-if="scholarship.other_contract_terms"
                                                        class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700"
                                                    >
                                                        Contract terms
                                                    </span>
                                                </div>
                                                <h3 class="mt-2 text-lg font-bold leading-snug text-slate-950">
                                                    {{ scholarship.title }}
                                                </h3>
                                                <p class="mt-1 truncate text-sm font-semibold text-slate-500">
                                                    {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                                </p>
                                                <p v-if="scholarship.description" class="mt-2 line-clamp-1 text-sm leading-6 text-slate-600">
                                                    {{ scholarship.description }}
                                                </p>
                                            </div>
                                        </div>

                                        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                                            <div
                                                v-for="fact in scholarshipSnapshot(scholarship)"
                                                :key="fact.label"
                                                class="min-w-0 border-l-2 border-slate-200 pl-3"
                                            >
                                                <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">
                                                    {{ fact.label }}
                                                </dt>
                                                <dd class="mt-1 truncate text-sm font-bold text-slate-900">
                                                    {{ fact.value }}
                                                </dd>
                                            </div>
                                        </dl>

                                        <div
                                            v-if="visibleMatchReasons(scholarship).length"
                                            class="mt-4 rounded-md bg-slate-50 px-3 py-2.5"
                                        >
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                {{ matchReasonHeading(scholarship) }}
                                            </p>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <span
                                                    v-for="criterion in visibleMatchReasons(scholarship)"
                                                    :key="`${scholarship.id}-${criterion.key}`"
                                                    :class="['rounded-md border px-2.5 py-1 text-xs font-bold', criterionReasonClass(criterion.status)]"
                                                >
                                                    {{ criterionReasonText(criterion) }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                                            <div class="rounded-md border border-slate-200 bg-white px-3 py-2.5">
                                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Coverage
                                                </p>
                                                <p class="mt-1 line-clamp-2 text-sm font-semibold leading-5 text-slate-700">
                                                    {{ coverageLabel(scholarship) }}
                                                </p>
                                            </div>
                                            <div class="rounded-md border border-slate-200 bg-white px-3 py-2.5">
                                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Application
                                                </p>
                                                <p class="mt-1 line-clamp-2 text-sm font-semibold leading-5 text-slate-700">
                                                    {{ applicationModeLabel(scholarship.application_mode) }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <aside class="border-t border-slate-200 bg-slate-50 p-4 lg:border-l lg:border-t-0">
                                        <div class="grid gap-3">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Deadline
                                                </p>
                                                <p class="mt-1 text-sm font-bold text-slate-950">
                                                    {{ deadlineLabel(scholarship) }}
                                                </p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Grade rule
                                                </p>
                                                <p class="mt-1 line-clamp-2 text-sm font-semibold leading-5 text-slate-700">
                                                    {{ academicRequirementLabel(scholarship) }}
                                                </p>
                                            </div>
                                            <div class="rounded-md border border-slate-200 bg-white p-3">
                                                <div class="flex items-center justify-between gap-3">
                                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                        Documents
                                                    </p>
                                                    <p class="text-xs font-bold text-slate-700">
                                                        {{ documentReadinessLabel(scholarship) }}
                                                    </p>
                                                </div>
                                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                                    <div
                                                        class="h-full rounded-full bg-slate-900"
                                                        :style="{ width: documentReadinessWidth(scholarship) }"
                                                    ></div>
                                                </div>
                                                <p class="mt-2 line-clamp-2 text-xs leading-5 text-slate-500">
                                                    {{ documentReadinessHint(scholarship) }}
                                                </p>
                                            </div>
                                            <div class="flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                                                <span class="rounded-md bg-white px-2.5 py-1 ring-1 ring-slate-200">
                                                    {{ slotLabel(scholarship) }}
                                                </span>
                                                <span v-if="scholarship.distance_label" class="rounded-md bg-white px-2.5 py-1 ring-1 ring-slate-200">
                                                    {{ scholarship.distance_label }}
                                                </span>
                                            </div>
                                        </div>

                                        <div class="mt-4 grid gap-2">
                                            <a
                                                :href="`/dashboard/scholarships/${scholarship.id}`"
                                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
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
                                            <button
                                                type="button"
                                                :disabled="savingId === scholarship.id"
                                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="toggleSave(scholarship)"
                                            >
                                                <i :class="[scholarship.is_saved ? 'fa-solid' : 'fa-regular', 'fa-bookmark mr-1.5']"></i>
                                                {{ savingId === scholarship.id ? 'Saving...' : scholarship.is_saved ? 'Saved' : 'Save' }}
                                            </button>
                                        </div>
                                        <p v-if="!scholarship.has_applied && !canStartApplication(scholarship)" class="mt-3 text-xs leading-5 text-slate-500">
                                            {{ applicationBlockedLabel(scholarship) }}
                                        </p>
                                    </aside>
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
