<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
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
const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];

const documentItems = computed(() => documentRequirements(scholarship.value?.requirements));
const hasDocumentRequirements = computed(() => !(documentItems.value.length === 1 && documentItems.value[0] === 'Not listed yet'));
const documentRequirementSummary = computed(() => hasDocumentRequirements.value
    ? `${documentItems.value.length} requirement${documentItems.value.length === 1 ? '' : 's'}`
    : 'No documents listed');
const canApply = computed(() => profileReadiness.value.complete);
const isEligible = computed(() => scholarship.value?.eligibility_match?.is_eligible !== false);
const canStartApplication = computed(() => {
    if (!scholarship.value) {
        return false;
    }

    if (scholarship.value.can_start_application !== undefined) {
        return Boolean(scholarship.value.can_start_application);
    }

    return canApply.value && isEligible.value && !scholarship.value.has_applied;
});
const applicationBlockedLabel = computed(() => {
    const blockers = scholarship.value?.eligibility_match?.blocking_criteria ?? [];
    const labels = blockers
        .map((criterion) => criterion.label)
        .filter(Boolean)
        .slice(0, 3);

    if (labels.length) {
        return `Your profile does not meet: ${labels.join(', ')}.`;
    }

    return 'Your profile does not meet this scholarship eligibility.';
});
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

function criteriaLabel(value) {
    if (!value) {
        return 'Any';
    }

    const items = String(value)
        .split(/\r?\n|,/)
        .map((item) => item.trim())
        .filter(Boolean)
        .map(labelFromKey);

    return items.length ? items.join(', ') : 'Any';
}

function targetApplicantLabel(scholarship) {
    const levels = String(scholarship?.eligible_education_levels ?? '')
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

function criterionStatusLabel(status) {
    if (status === 'pass') {
        return 'Matched';
    }

    if (status === 'fail') {
        return 'Not matched';
    }

    if (status === 'missing') {
        return 'Missing info';
    }

    return 'Info';
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
                <ApplicantPageHeader
                    eyebrow="Scholarship Details"
                    title="Review the program"
                    description="Check fit, requirements, and location."
                    icon="fa-solid fa-graduation-cap"
                    action-href="/dashboard/scholarships"
                    action-label="Back to scholarships"
                    secondary-href="/dashboard/applications"
                    secondary-label="Applications"
                />

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading scholarship details...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="scholarship" class="mt-6 space-y-5">
                    <header class="student-card overflow-hidden">
                        <div class="border-b border-slate-200 bg-white p-5 sm:p-6">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="flex min-w-0 gap-4">
                                    <img
                                        :src="scholarship.image_url"
                                        :alt="scholarship.title"
                                        class="h-16 w-16 shrink-0 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200/80 sm:h-20 sm:w-20"
                                    >
                                    <div class="min-w-0">
                                        <p class="student-kicker">
                                            {{ scholarship.category || providerTypeLabel(scholarship.provider?.type) }}
                                        </p>
                                        <h1 class="mt-2 font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">
                                            {{ scholarship.title }}
                                        </h1>
                                        <p class="mt-2 text-sm font-semibold text-slate-500">
                                            {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2 lg:justify-end">
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                        {{ scholarship.eligibility_match?.label || 'Needs review' }}
                                    </span>
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        <i class="fa-solid fa-users mr-1.5"></i>
                                        {{ targetApplicantLabel(scholarship) }}
                                    </span>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ scholarship.deadline || 'No deadline' }}
                                    </span>
                                    <span v-if="scholarship.distance_label" class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ scholarship.distance_label }}
                                    </span>
                                </div>
                            </div>

                            <p class="mt-5 line-clamp-3 max-w-4xl text-sm leading-6 text-slate-600">
                                {{ scholarship.description }}
                            </p>
                        </div>

                        <div class="grid gap-px bg-slate-200 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div class="bg-slate-50 p-4">
                                <p class="font-semibold text-slate-500">Award</p>
                                <p class="mt-1 font-bold text-slate-950">{{ formatAmount(scholarship.award_amount) }}</p>
                            </div>
                            <div class="bg-slate-50 p-4">
                                <p class="font-semibold text-slate-500">Academic</p>
                                <p class="mt-1 font-bold text-slate-950">{{ academicRequirementLabel(scholarship) }}</p>
                            </div>
                            <div class="bg-slate-50 p-4">
                                <p class="font-semibold text-slate-500">Documents</p>
                                <p class="mt-1 font-bold text-slate-950">{{ documentRequirementSummary }}</p>
                            </div>
                            <div class="bg-slate-50 p-4">
                                <p class="font-semibold text-slate-500">Apply through</p>
                                <p class="mt-1 font-bold text-slate-950">{{ applicationModeLabel(scholarship.application_mode) }}</p>
                            </div>
                        </div>
                    </header>

                    <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </div>

                    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
                        <section class="space-y-5">
                            <article class="student-card p-5">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="student-kicker">
                                            Overview
                                        </p>
                                        <h2 class="mt-2 text-xl font-bold text-slate-950">
                                            Who this scholarship is for
                                        </h2>
                                    </div>
                                    <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ providerTypeLabel(scholarship.provider?.type) }}
                                    </span>
                                </div>

                                <p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600">
                                    {{ scholarship.eligibility || 'No eligibility description has been posted yet.' }}
                                </p>

                                <div class="mt-5 grid gap-3 md:grid-cols-2">
                                    <div class="rounded-md border border-slate-200/80 bg-slate-50 p-3 text-sm">
                                        <p class="font-semibold text-slate-500">Education level</p>
                                        <p class="mt-1 font-semibold text-slate-800">{{ criteriaLabel(scholarship.eligible_education_levels) }}</p>
                                    </div>
                                    <div class="rounded-md border border-slate-200/80 bg-slate-50 p-3 text-sm">
                                        <p class="font-semibold text-slate-500">School type</p>
                                        <p class="mt-1 font-semibold text-slate-800">{{ criteriaLabel(scholarship.eligible_school_types) }}</p>
                                    </div>
                                    <div class="rounded-md border border-slate-200/80 bg-slate-50 p-3 text-sm md:col-span-2">
                                        <p class="font-semibold text-slate-500">Track, strand, course, or program</p>
                                        <p class="mt-1 whitespace-pre-line font-semibold text-slate-800">{{ scholarship.eligible_courses || 'Any' }}</p>
                                    </div>
                                    <div class="rounded-md border border-slate-200/80 bg-slate-50 p-3 text-sm">
                                        <p class="font-semibold text-slate-500">Grade / year level</p>
                                        <p class="mt-1 whitespace-pre-line font-semibold text-slate-800">{{ scholarship.eligible_year_levels || 'Any' }}</p>
                                    </div>
                                    <div class="rounded-md border border-slate-200/80 bg-slate-50 p-3 text-sm">
                                        <p class="font-semibold text-slate-500">Income rule</p>
                                        <p class="mt-1 font-semibold text-slate-800">{{ scholarship.income_requirement || 'Any' }}</p>
                                    </div>
                                </div>
                            </article>

                            <article class="student-card overflow-hidden">
                                <div class="border-b border-slate-200 bg-slate-50 p-5">
                                    <p class="student-kicker">
                                        Requirements
                                    </p>
                                    <h2 class="mt-2 text-xl font-bold text-slate-950">
                                        What to prepare
                                    </h2>
                                </div>

                                <div class="grid gap-0 lg:grid-cols-[1fr_0.9fr]">
                                    <div class="border-b border-slate-200 p-5 lg:border-b-0 lg:border-r">
                                        <p class="text-sm font-bold text-slate-950">
                                            Documents
                                        </p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <span
                                                v-for="requirement in documentItems"
                                                :key="requirement"
                                                class="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700"
                                            >
                                                {{ requirement }}
                                            </span>
                                        </div>
                                        <p class="mt-3 text-xs leading-5 text-slate-500">
                                            Prepared in your document library: {{ scholarship.prepared_documents?.uploaded ?? 0 }} of {{ scholarship.prepared_documents?.required ?? 0 }}
                                        </p>
                                    </div>

                                    <div class="p-5">
                                        <p class="text-sm font-bold text-slate-950">
                                            Application details
                                        </p>
                                        <div class="mt-3 grid gap-3 text-sm">
                                            <div class="rounded-md bg-slate-50 p-3">
                                                <p class="font-semibold text-slate-500">Available slots</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ scholarship.slots_available ?? 'Not listed yet' }}</p>
                                            </div>
                                            <div class="rounded-md bg-slate-50 p-3">
                                                <p class="font-semibold text-slate-500">Contact</p>
                                                <p class="mt-1 break-words font-bold text-slate-950">
                                                    {{ scholarship.contact_email || scholarship.contact_number || 'Not listed yet' }}
                                                </p>
                                            </div>
                                        </div>
                                        <div v-if="scholarship.renewal_policy" class="mt-3 rounded-md bg-slate-50 p-3 text-sm">
                                            <p class="font-semibold text-slate-500">Renewal / continuation</p>
                                            <p class="mt-1 leading-6 text-slate-800">{{ scholarship.renewal_policy }}</p>
                                        </div>
                                        <div v-if="scholarship.return_service_contract" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                            <p class="font-semibold text-slate-500">Return service contract</p>
                                            <p class="mt-1 whitespace-pre-line leading-6 text-slate-800">{{ scholarship.return_service_contract }}</p>
                                        </div>
                                        <div v-if="scholarship.other_contract_terms" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                            <p class="font-semibold text-slate-500">Other contract terms</p>
                                            <p class="mt-1 whitespace-pre-line leading-6 text-slate-800">{{ scholarship.other_contract_terms }}</p>
                                        </div>
                                    </div>
                                </div>
                            </article>

                            <article class="student-card p-5">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">
                                            Match Check
                                        </p>
                                        <h2 class="mt-2 text-xl font-bold text-slate-950">
                                            Your eligibility snapshot
                                        </h2>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                        {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                    </span>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                                    <p class="font-bold text-slate-900">How the DSS reads this</p>
                                    <p class="mt-1">
                                        DSS compares your profile with provider rules. Provider review is still final.
                                    </p>
                                </div>

                                <details v-if="scholarship.eligibility_match?.criteria?.length" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <summary class="cursor-pointer text-sm font-bold text-slate-700">
                                        View DSS checklist
                                    </summary>
                                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                                        <div
                                            v-for="criterion in scholarship.eligibility_match.criteria"
                                            :key="criterion.key"
                                            :class="['rounded-md border p-3 text-sm', criterionClass(criterion.status)]"
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="font-bold">{{ criterion.label }}</p>
                                                <p class="text-xs font-bold uppercase">{{ criterionStatusLabel(criterion.status) }}</p>
                                            </div>
                                            <p class="mt-2 text-xs leading-5">
                                                Profile: {{ criterion.student_value || criterion.studentValue || 'Not set' }}
                                            </p>
                                            <p v-if="criterion.requirement" class="mt-1 text-xs leading-5">
                                                Required: {{ criterion.requirement }}
                                            </p>
                                        </div>
                                    </div>
                                </details>

                                <p v-else class="mt-3 text-sm text-slate-500">
                                    No pre-check details listed.
                                </p>
                            </article>
                        </section>

                        <aside class="space-y-4 xl:sticky xl:top-6">
                            <article class="student-card overflow-hidden">
                                <div class="bg-slate-950 p-5 text-white">
                                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-200">
                                        Apply
                                    </p>
                                    <h2 class="mt-2 text-xl font-bold">
                                        Ready to continue?
                                    </h2>
                                    <p class="mt-2 text-sm leading-6 text-slate-300">
                                        Save this program or start the wizard.
                                    </p>
                                </div>

                                <div class="p-5">
                                    <div class="rounded-md bg-slate-50 p-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-sm font-bold text-slate-950">
                                                Profile readiness
                                            </p>
                                            <p class="text-sm font-bold text-slate-700">
                                                {{ profileReadiness.percent }}%
                                            </p>
                                        </div>
                                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                            <div class="h-full rounded-full bg-slate-900" :style="{ width: `${profileReadiness.percent}%` }"></div>
                                        </div>
                                    </div>

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
                                            v-else-if="canStartApplication"
                                            :href="`/dashboard/applications?scholarship=${scholarship.id}`"
                                            class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                        >
                                            Start application wizard
                                        </a>
                                        <span
                                            v-else-if="!isEligible"
                                            class="rounded-md bg-slate-200 px-4 py-2.5 text-center text-sm font-bold text-slate-600"
                                        >
                                            Not eligible
                                        </span>
                                        <a
                                            v-else
                                            href="/dashboard/profile"
                                            class="rounded-md bg-amber-500 px-4 py-2.5 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-400"
                                        >
                                            Complete profile first
                                        </a>
                                        <p v-if="!isEligible && !scholarship.has_applied" class="text-xs leading-5 text-slate-500">
                                            {{ applicationBlockedLabel }}
                                        </p>
                                        <p v-if="!canApply && isEligible && !scholarship.has_applied" class="text-xs leading-5 text-slate-500">
                                            Complete your profile before applying. You can still explore and save this program.
                                        </p>
                                    </div>
                                </div>
                            </article>

                            <article class="student-card p-5">
                                <p class="student-kicker">
                                    Location
                                </p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">
                                    {{ scholarship.location_name || 'Location not named' }}
                                </h3>
                                <p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-600">
                                    {{ scholarship.location_address || scholarship.eligible_locations || 'No map address added yet.' }}
                                </p>
                                <p v-if="scholarship.distance_label" class="mt-2 text-xs font-bold text-slate-700">
                                    About {{ scholarship.distance_label }} from your saved location.
                                </p>

                                <button
                                    v-if="hasMapPreview"
                                    type="button"
                                    class="mt-4 w-full rounded-md border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                    @click="showMapModal = true"
                                >
                                    Preview Map
                                </button>
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
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Map Preview</p>
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
