<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import { labelFromKey } from '../support/display';

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
const selectionStageOptions = [
    { value: 'screening', label: 'Screening', icon: 'fa-solid fa-list-check' },
    { value: 'exam', label: 'Exam', icon: 'fa-solid fa-clipboard-question' },
    { value: 'interview', label: 'Interview', icon: 'fa-solid fa-comments' },
    { value: 'distribution', label: 'Distribution', icon: 'fa-solid fa-hand-holding-dollar' },
];

const documentItems = computed(() => documentRequirements(scholarship.value?.requirements));
const hasDocumentRequirements = computed(() => !(documentItems.value.length === 1 && documentItems.value[0] === 'Not listed yet'));
const documentRequirementSummary = computed(() => hasDocumentRequirements.value
    ? `${documentItems.value.length} requirement${documentItems.value.length === 1 ? '' : 's'}`
    : 'No documents listed');
const selectionPlan = computed(() => {
    const selected = scholarship.value?.selection_stages ?? ['screening', 'distribution'];

    return selectionStageOptions.filter((stage) => selected.includes(stage.value));
});
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
const hasUserMapLocation = computed(() => hasCoordinates(user.value?.latitude, user.value?.longitude));
const userLocationLabel = computed(() => {
    const parts = [
        user.value?.address,
        user.value?.barangay,
        user.value?.city,
        user.value?.province,
        user.value?.region,
    ].filter(Boolean);

    return parts.length ? parts.join(', ') : 'Your saved profile location';
});
const keyFacts = computed(() => {
    const current = scholarship.value;

    if (!current) {
        return [];
    }

    return [
        { label: 'Award', value: formatAmount(current.award_amount), detail: current.slots_available ? `${current.slots_available} slot${Number(current.slots_available) === 1 ? '' : 's'} listed` : 'Slots not listed' },
        { label: 'Deadline', value: current.deadline || 'No deadline', detail: current.distance_label ? `About ${current.distance_label} away` : 'Check provider updates' },
        { label: 'Academic', value: academicRequirementLabel(current), detail: 'Based on posted grade rule' },
        { label: 'Documents', value: documentRequirementSummary.value, detail: preparedDocumentSummary(current) },
    ];
});
const fitHighlights = computed(() => {
    const current = scholarship.value;

    if (!current) {
        return [];
    }

    return [
        { label: 'Education level', value: criteriaLabel(current.eligible_education_levels) },
        { label: 'School type', value: criteriaLabel(current.eligible_school_types) },
        { label: 'Track, strand, course, or program', value: current.eligible_courses || 'Any' },
        { label: 'Grade / year level', value: current.eligible_year_levels || 'Any' },
        { label: 'Income rule', value: current.income_requirement || 'Any' },
        { label: 'Location coverage', value: current.eligible_locations || current.location_name || 'Any' },
    ];
});
const applyPanelTitle = computed(() => {
    if (scholarship.value?.has_applied) {
        return 'Application submitted';
    }

    if (!isEligible.value) {
        return 'Not eligible right now';
    }

    if (!canApply.value) {
        return 'Complete profile first';
    }

    return 'Ready to apply';
});
const applyPanelDescription = computed(() => {
    if (scholarship.value?.has_applied) {
        return 'You can review this program in your submitted applications.';
    }

    if (!isEligible.value) {
        return applicationBlockedLabel.value;
    }

    if (!canApply.value) {
        return 'Finish the required profile fields before starting the application wizard.';
    }

    return 'Your profile can start this application. Review the details once more before submitting.';
});
const hasContractDetails = computed(() => Boolean(
    scholarship.value?.renewal_policy
    || scholarship.value?.return_service_contract
    || scholarship.value?.other_contract_terms,
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

function hasCoordinates(latitude, longitude) {
    if (latitude === null || latitude === undefined || latitude === '' || longitude === null || longitude === undefined || longitude === '') {
        return false;
    }

    return Number.isFinite(Number(latitude)) && Number.isFinite(Number(longitude));
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

function preparedDocumentSummary(current) {
    const required = Number(current?.prepared_documents?.required ?? 0);
    const uploaded = Number(current?.prepared_documents?.uploaded ?? 0);

    if (!required) {
        return 'No listed document requirement';
    }

    return `${uploaded} of ${required} ready in your library`;
}

function contactLabel(current) {
    return [current?.contact_email, current?.contact_number].filter(Boolean).join(' / ') || 'Not listed yet';
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

onMounted(loadScholarship);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

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
                        <div class="grid lg:grid-cols-[minmax(0,1fr)_21rem]">
                            <div class="border-b border-slate-200 bg-white p-5 sm:p-6 lg:border-r lg:border-b-0">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                                    <img
                                        :src="scholarship.image_url"
                                        :alt="scholarship.title"
                                        class="h-20 w-20 shrink-0 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200/80"
                                    >
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                                {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                            </span>
                                            <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                {{ scholarship.category || providerTypeLabel(scholarship.provider?.type) }}
                                            </span>
                                            <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                                {{ targetApplicantLabel(scholarship) }}
                                            </span>
                                        </div>

                                        <h1 class="mt-3 font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">
                                            {{ scholarship.title }}
                                        </h1>
                                        <p class="mt-2 text-sm font-semibold text-slate-500">
                                            {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                        </p>

                                        <p class="mt-4 max-w-4xl text-sm leading-6 text-slate-600">
                                            {{ scholarship.description || 'No program description has been posted yet.' }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-slate-50 p-5 sm:p-6">
                                <p class="student-kicker">
                                    Applicant Snapshot
                                </p>
                                <h2 class="mt-2 text-lg font-bold text-slate-950">
                                    {{ applyPanelTitle }}
                                </h2>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    {{ applyPanelDescription }}
                                </p>

                                <div class="mt-4 rounded-md border border-slate-200 bg-white p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-bold text-slate-950">Profile readiness</p>
                                        <p class="text-sm font-bold text-slate-700">{{ profileReadiness.percent }}%</p>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-full rounded-full bg-slate-900" :style="{ width: `${profileReadiness.percent}%` }"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </header>

                    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <article
                            v-for="fact in keyFacts"
                            :key="fact.label"
                            class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                        >
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                {{ fact.label }}
                            </p>
                            <p class="mt-2 line-clamp-2 text-sm font-bold leading-6 text-slate-950">
                                {{ fact.value }}
                            </p>
                            <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">
                                {{ fact.detail }}
                            </p>
                        </article>
                    </section>

                    <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </div>

                    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
                        <section class="space-y-5">
                            <article class="student-card p-5">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Fit Overview</p>
                                        <h2 class="mt-2 text-xl font-bold text-slate-950">
                                            Who this program is for
                                        </h2>
                                    </div>
                                    <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ providerTypeLabel(scholarship.provider?.type) }}
                                    </span>
                                </div>

                                <p class="mt-4 text-sm leading-6 text-slate-600">
                                    {{ scholarship.eligibility || 'No eligibility description has been posted yet.' }}
                                </p>

                                <div class="mt-5 grid gap-3 md:grid-cols-2">
                                    <div
                                        v-for="item in fitHighlights"
                                        :key="item.label"
                                        class="rounded-md border border-slate-200/80 bg-slate-50 p-3 text-sm"
                                    >
                                        <p class="font-semibold text-slate-500">{{ item.label }}</p>
                                        <p class="mt-1 line-clamp-3 whitespace-pre-line font-semibold leading-6 text-slate-800">
                                            {{ item.value }}
                                        </p>
                                    </div>
                                </div>
                            </article>

                            <article class="student-card p-5">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Requirements</p>
                                        <h2 class="mt-2 text-xl font-bold text-slate-950">
                                            What to prepare
                                        </h2>
                                    </div>
                                    <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ applicationModeLabel(scholarship.application_mode) }}
                                    </span>
                                </div>

                                <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <p class="text-sm font-bold text-slate-950">Documents</p>
                                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                                    {{ preparedDocumentSummary(scholarship) }}
                                                </p>
                                            </div>
                                            <span class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                                {{ documentRequirementSummary }}
                                            </span>
                                        </div>

                                        <div class="mt-4 flex flex-wrap gap-2">
                                            <span
                                                v-for="requirement in documentItems"
                                                :key="requirement"
                                                class="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700"
                                            >
                                                {{ requirement }}
                                            </span>
                                        </div>
                                    </div>

                                    <div class="grid gap-3 text-sm">
                                        <div class="rounded-md border border-slate-200 bg-white p-3">
                                            <p class="font-semibold text-slate-500">Available slots</p>
                                            <p class="mt-1 font-bold text-slate-950">{{ scholarship.slots_available ?? 'Not listed yet' }}</p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-white p-3">
                                            <p class="font-semibold text-slate-500">Contact</p>
                                            <p class="mt-1 break-words font-bold text-slate-950">
                                                {{ contactLabel(scholarship) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">Selection process</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">The provider posts shared dates after applications reach each stage.</p>
                                        </div>
                                        <span class="text-xs font-bold text-slate-500">{{ selectionPlan.length }} stages</span>
                                    </div>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                        <div v-for="(stage, index) in selectionPlan" :key="stage.value" class="flex items-center gap-3 rounded-md border border-slate-200 bg-white p-3">
                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-slate-900 text-xs text-white">
                                                <i :class="stage.icon" aria-hidden="true"></i>
                                            </span>
                                            <div>
                                                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Step {{ index + 1 }}</p>
                                                <p class="text-sm font-bold text-slate-800">{{ stage.label }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <details v-if="hasContractDetails" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <summary class="cursor-pointer text-sm font-bold text-slate-800">
                                        Renewal or contract details
                                    </summary>
                                    <div class="mt-3 grid gap-3 text-sm">
                                        <div v-if="scholarship.renewal_policy" class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                            <p class="font-semibold text-slate-500">Renewal / continuation</p>
                                            <p class="mt-1 leading-6 text-slate-800">{{ scholarship.renewal_policy }}</p>
                                        </div>
                                        <div v-if="scholarship.return_service_contract" class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                            <p class="font-semibold text-slate-500">Return service contract</p>
                                            <p class="mt-1 whitespace-pre-line leading-6 text-slate-800">{{ scholarship.return_service_contract }}</p>
                                        </div>
                                        <div v-if="scholarship.other_contract_terms" class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                            <p class="font-semibold text-slate-500">Other contract terms</p>
                                            <p class="mt-1 whitespace-pre-line leading-6 text-slate-800">{{ scholarship.other_contract_terms }}</p>
                                        </div>
                                    </div>
                                </details>
                            </article>

                            <article class="student-card p-5">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Match Check</p>
                                        <h2 class="mt-2 text-xl font-bold text-slate-950">
                                            Your eligibility snapshot
                                        </h2>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                        {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                    </span>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">
                                                {{ scholarship.eligibility_match?.label || 'Needs review' }}
                                            </p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                                DSS compares your saved profile with provider rules. Provider review is still final.
                                            </p>
                                        </div>
                                        <p class="text-sm font-bold text-slate-700">
                                            {{ scholarship.eligibility_match?.passed ?? 0 }} of {{ scholarship.eligibility_match?.applicable ?? 0 }} checks
                                        </p>
                                    </div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                        <div
                                            class="h-full rounded-full bg-slate-900"
                                            :style="{ width: `${Math.min(Math.max(Number(scholarship.eligibility_match?.score ?? 0), 0), 100)}%` }"
                                        ></div>
                                    </div>
                                </div>

                                <div v-if="scholarship.eligibility_match?.criteria?.length" class="mt-4 overflow-hidden rounded-md border border-slate-200">
                                    <div
                                        v-for="criterion in scholarship.eligibility_match.criteria"
                                        :key="criterion.key"
                                        class="border-b border-slate-200 bg-white p-3 last:border-b-0"
                                    >
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold text-slate-950">{{ criterion.label }}</p>
                                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                                    Profile: {{ criterion.student_value || criterion.studentValue || 'Not set' }}
                                                </p>
                                                <p v-if="criterion.requirement" class="mt-1 text-xs leading-5 text-slate-500">
                                                    Required: {{ criterion.requirement }}
                                                </p>
                                            </div>
                                            <span :class="['w-fit rounded-md border px-2.5 py-1 text-xs font-bold', criterionClass(criterion.status)]">
                                                {{ criterionStatusLabel(criterion.status) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <p v-else class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-500">
                                    No pre-check details listed.
                                </p>
                            </article>
                        </section>

                        <aside class="space-y-4 xl:sticky xl:top-6">
                            <article class="student-card p-5">
                                <p class="student-kicker">Next Step</p>
                                <h2 class="mt-2 text-xl font-bold text-slate-950">
                                    {{ applyPanelTitle }}
                                </h2>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    {{ applyPanelDescription }}
                                </p>

                                <div class="mt-4 grid gap-3">
                                    <button
                                        type="button"
                                        :disabled="isSaving"
                                        class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60"
                                        @click="toggleSave"
                                    >
                                        <i :class="scholarship.is_saved ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark'"></i>
                                        {{ isSaving ? 'Saving...' : scholarship.is_saved ? 'Saved' : 'Save program' }}
                                    </button>
                                    <a
                                        v-if="scholarship.has_applied"
                                        href="/dashboard/applications"
                                        class="rounded-md bg-slate-200 px-4 py-2.5 text-center text-sm font-bold text-slate-700"
                                    >
                                        View application
                                    </a>
                                    <a
                                        v-else-if="canStartApplication"
                                        :href="`/dashboard/applications?scholarship=${scholarship.id}`"
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                    >
                                        Start application
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
                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                    >
                                        Complete profile
                                    </a>
                                </div>
                            </article>

                            <article class="student-card p-5">
                                <p class="student-kicker">Location</p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">
                                    {{ scholarship.location_name || 'Location not named' }}
                                </h3>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    {{ scholarship.location_address || scholarship.eligible_locations || 'No map address added yet.' }}
                                </p>
                                <p v-if="scholarship.distance_label" class="mt-2 text-xs font-bold text-slate-700">
                                    About {{ scholarship.distance_label }} from your saved location.
                                </p>

                                <button
                                    v-if="hasMapPreview"
                                    type="button"
                                    class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                    @click="showMapModal = true"
                                >
                                    <i class="fa-solid fa-map-location-dot"></i>
                                    Preview map
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
                        <p v-if="hasUserMapLocation && scholarship.distance_label" class="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">
                            Your saved location is shown too: {{ scholarship.distance_label }} from this program.
                        </p>
                        <p v-else-if="!hasUserMapLocation" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">
                            Add your profile map pin to compare distance here.
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
                        :secondary-latitude="user?.latitude"
                        :secondary-longitude="user?.longitude"
                        :secondary-marker-text="userLocationLabel"
                        :distance-label="scholarship.distance_label ? `About ${scholarship.distance_label}` : ''"
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
