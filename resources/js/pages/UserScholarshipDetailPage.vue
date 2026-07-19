<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import { labelFromKey } from '../support/display';
import { selectionPlanFor } from '../support/selectionPlan';

const appElement = document.getElementById('app');
const scholarshipId = appElement?.dataset.scholarshipId;
const isLoading = ref(true);
const errorMessage = ref('');
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
const preparedDocuments = computed(() => scholarship.value?.prepared_documents ?? {
    required: 0,
    uploaded: 0,
    percent: 100,
    required_documents: [],
    matched: [],
    missing: [],
});
const documentItems = computed(() => {
    if (Array.isArray(preparedDocuments.value.required_documents)) {
        return preparedDocuments.value.required_documents;
    }

    return documentRequirements(scholarship.value?.requirements);
});
const hasDocumentRequirements = computed(() => documentItems.value.length > 0);
const documentRequirementSummary = computed(() => hasDocumentRequirements.value
    ? `${documentItems.value.length} requirement${documentItems.value.length === 1 ? '' : 's'}`
    : 'No documents listed');
const selectionPlan = computed(() => selectionPlanFor(scholarship.value));
const canApply = computed(() => profileReadiness.value.complete);
const isEligible = computed(() => scholarship.value?.eligibility_match?.is_eligible !== false);
const isAcceptingApplications = computed(() => scholarship.value?.is_accepting_applications !== false);
const eligibilityState = computed(() => {
    if (!canApply.value) {
        return {
            title: 'Complete your profile for a full check',
            icon: 'fa-solid fa-circle-info',
            classes: 'bg-amber-100 text-amber-800',
        };
    }

    if (isEligible.value) {
        return {
            title: 'Your profile meets the listed rules',
            icon: 'fa-solid fa-check',
            classes: 'bg-emerald-100 text-emerald-800',
        };
    }

    return {
        title: 'Some rules do not match your profile',
        icon: 'fa-solid fa-triangle-exclamation',
        classes: 'bg-rose-100 text-rose-800',
    };
});
const canStartApplication = computed(() => {
    if (!scholarship.value) {
        return false;
    }

    if (scholarship.value.can_start_application !== undefined) {
        return Boolean(scholarship.value.can_start_application);
    }

    return isAcceptingApplications.value && canApply.value && isEligible.value && !scholarship.value.has_applied;
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
        {
            icon: 'fa-solid fa-peso-sign',
            label: 'Award',
            value: formatAmount(current.award_amount),
            detail: 'Financial support listed by the provider',
        },
        {
            icon: 'fa-regular fa-calendar',
            label: 'Apply by',
            value: current.deadline || 'No deadline listed',
            detail: isAcceptingApplications.value ? 'Applications are open' : 'Applications are closed',
        },
        {
            icon: 'fa-solid fa-users',
            label: 'Available slots',
            value: current.slots_available ?? 'Not listed',
            detail: current.slots_available ? 'Planned recipients' : 'Ask the provider for availability',
        },
        {
            icon: 'fa-solid fa-paper-plane',
            label: 'How to apply',
            value: applicationModeLabel(current.application_mode),
            detail: documentRequirementSummary.value,
        },
    ];
});
const fitHighlights = computed(() => {
    const current = scholarship.value;

    if (!current) {
        return [];
    }

    return [
        { icon: 'fa-solid fa-school', label: 'Education level', value: criteriaLabel(current.eligible_education_levels) },
        { icon: 'fa-solid fa-building-columns', label: 'School type', value: criteriaLabel(current.eligible_school_types) },
        { icon: 'fa-solid fa-book-open', label: 'Track, strand, course, or program', value: current.eligible_courses || 'Any' },
        { icon: 'fa-solid fa-layer-group', label: 'Grade / year level', value: current.eligible_year_levels || 'Any' },
        { icon: 'fa-solid fa-wallet', label: 'Household income', value: current.income_requirement || 'Any' },
        { icon: 'fa-solid fa-location-dot', label: 'Location coverage', value: current.eligible_locations || current.location_name || 'Any' },
        { icon: 'fa-solid fa-chart-line', label: 'Academic requirement', value: academicRequirementLabel(current) },
    ];
});
const applyPanelTitle = computed(() => {
    if (scholarship.value?.has_applied) {
        return 'Application submitted';
    }

    if (!isAcceptingApplications.value) {
        return 'Applications are closed';
    }

    if (!canApply.value) {
        return 'Complete profile first';
    }

    if (!isEligible.value) {
        return 'Not eligible right now';
    }

    return 'Ready to apply';
});
const applyPanelDescription = computed(() => {
    if (scholarship.value?.has_applied) {
        return 'You can review this program in your submitted applications.';
    }

    if (!isAcceptingApplications.value) {
        return 'This program is no longer accepting new applications. You can still save it for reference.';
    }

    if (!canApply.value) {
        return 'Finish the required profile fields before starting the application wizard.';
    }

    if (!isEligible.value) {
        return applicationBlockedLabel.value;
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

function programEventPlaceLabel(event) {
    if (!event) {
        return '';
    }

    const mode = {
        onsite: 'On-site',
        online: 'Online',
        hybrid: 'Hybrid',
        provider_managed: 'Provider managed',
    }[event.mode] ?? labelFromKey(event.mode || 'schedule');
    const place = ['onsite', 'hybrid'].includes(event.mode)
        ? (event.venue || event.location_address)
        : null;

    return [mode, place].filter(Boolean).join(' - ');
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
        return [];
    }

    return String(requirements)
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function isPreparedDocument(requirement) {
    return preparedDocuments.value.matched?.includes(requirement) ?? false;
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
    errorMessage.value = '';

    try {
        const response = scholarship.value.is_saved
            ? await window.axios.delete(`/dashboard/scholarships/${scholarship.value.id}/save`)
            : await window.axios.post(`/dashboard/scholarships/${scholarship.value.id}/save`);

        scholarship.value = response.data.scholarship;
    } catch (handledError) {
        void handledError;
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
                    eyebrow="Scholarship"
                    title="Program details"
                    description="Check what you receive, who can apply, what to prepare, and what happens next."
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
                        <div class="grid xl:grid-cols-[minmax(0,1fr)_21rem]">
                            <div class="border-b border-slate-200 bg-white p-5 sm:p-6 xl:border-r xl:border-b-0">
                                <div class="flex items-start gap-4">
                                    <img
                                        :src="scholarship.image_url"
                                        :alt="scholarship.title"
                                        class="h-16 w-16 shrink-0 rounded-md bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200 sm:h-20 sm:w-20 sm:p-2"
                                    >
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
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
                                        <p class="mt-2 flex items-center gap-2 text-sm font-semibold text-slate-500">
                                            <i class="fa-solid fa-building-shield text-amber-700" aria-hidden="true"></i>
                                            {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                        </p>
                                    </div>
                                </div>

                                <p class="mt-5 max-w-4xl text-sm leading-6 text-slate-600">
                                    {{ scholarship.description || 'No program description has been posted yet.' }}
                                </p>
                            </div>

                            <div class="bg-slate-950 p-5 text-white sm:p-6">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-300">
                                        Can I apply?
                                    </p>
                                    <span class="rounded-md bg-white/10 px-2.5 py-1 text-xs font-bold text-white ring-1 ring-white/15">
                                        {{ scholarship.eligibility_match?.score ?? 0 }}% profile match
                                    </span>
                                </div>
                                <h2 class="mt-3 text-xl font-bold text-white">
                                    {{ applyPanelTitle }}
                                </h2>
                                <p class="mt-2 text-sm leading-6 text-slate-300">
                                    {{ applyPanelDescription }}
                                </p>

                                <div v-if="!canApply && !scholarship.has_applied" class="mt-4 rounded-md bg-white/5 p-3 ring-1 ring-white/15">
                                    <div class="flex items-center justify-between gap-3 text-xs font-bold">
                                        <span class="text-slate-300">Profile readiness</span>
                                        <span class="text-white">{{ profileReadiness.percent }}%</span>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/15">
                                        <div class="h-full rounded-full bg-amber-300" :style="{ width: `${profileReadiness.percent}%` }"></div>
                                    </div>
                                </div>

                                <div class="mt-5 grid gap-2">
                                    <a
                                        v-if="scholarship.has_applied"
                                        href="/dashboard/applications"
                                        class="rounded-md bg-amber-300 px-4 py-2.5 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                                    >
                                        View my application
                                    </a>
                                    <a
                                        v-else-if="canStartApplication"
                                        :href="`/dashboard/applications?scholarship=${scholarship.id}`"
                                        class="rounded-md bg-amber-300 px-4 py-2.5 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                                    >
                                        Start application
                                    </a>
                                    <span
                                        v-else-if="!isAcceptingApplications"
                                        class="rounded-md bg-white/10 px-4 py-2.5 text-center text-sm font-bold text-slate-300 ring-1 ring-white/15"
                                    >
                                        Applications closed
                                    </span>
                                    <a
                                        v-else-if="!canApply"
                                        href="/dashboard/profile"
                                        class="rounded-md bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-950 transition hover:bg-slate-100"
                                    >
                                        Complete profile
                                    </a>
                                    <a
                                        v-else-if="!isEligible"
                                        href="#eligibility"
                                        class="rounded-md bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-950 transition hover:bg-slate-100"
                                    >
                                        Review eligibility
                                    </a>
                                    <span v-else class="rounded-md bg-white/10 px-4 py-2.5 text-center text-sm font-bold text-slate-300 ring-1 ring-white/15">
                                        Application unavailable
                                    </span>

                                    <button
                                        type="button"
                                        :disabled="isSaving"
                                        class="inline-flex items-center justify-center gap-2 rounded-md border border-white/20 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-white/10 disabled:opacity-60"
                                        @click="toggleSave"
                                    >
                                        <i :class="scholarship.is_saved ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark'" aria-hidden="true"></i>
                                        {{ isSaving ? 'Saving...' : scholarship.is_saved ? 'Saved for later' : 'Save for later' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <section class="grid border-t border-slate-200 sm:grid-cols-2 xl:grid-cols-4">
                            <article
                                v-for="fact in keyFacts"
                                :key="fact.label"
                                class="flex gap-3 border-b border-slate-200 p-4 last:border-b-0 sm:[&:nth-child(odd)]:border-r sm:[&:nth-last-child(-n+2)]:border-b-0 xl:border-b-0 xl:border-r xl:last:border-r-0"
                            >
                                <span class="student-icon-badge h-9 w-9">
                                    <i :class="fact.icon" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">{{ fact.label }}</p>
                                    <p class="mt-1 text-sm font-bold leading-5 text-slate-950">{{ fact.value }}</p>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ fact.detail }}</p>
                                </div>
                            </article>
                        </section>
                    </header>

                    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
                        <section class="space-y-5">
                            <article id="eligibility" class="student-card scroll-mt-6 p-5 sm:p-6">
                                <div class="student-section-head">
                                    <div class="flex items-start gap-3">
                                        <span class="student-section-mark">
                                            <i class="fa-solid fa-user-check" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="student-kicker">Eligibility</p>
                                            <h2 class="mt-1 text-xl font-bold text-slate-950">Do you qualify?</h2>
                                            <p class="mt-1 text-sm text-slate-500">Compare the provider's rules with your saved profile.</p>
                                        </div>
                                    </div>
                                    <span :class="['w-fit rounded-md px-3 py-1.5 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                        {{ scholarship.eligibility_match?.score ?? 0 }}% profile match
                                    </span>
                                </div>

                                <div class="mt-5 flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md', eligibilityState.classes]">
                                        <i :class="eligibilityState.icon" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">
                                            {{ canApply ? (scholarship.eligibility_match?.label || eligibilityState.title) : eligibilityState.title }}
                                        </p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            <template v-if="scholarship.eligibility_match?.applicable">
                                                {{ scholarship.eligibility_match?.passed ?? 0 }} of {{ scholarship.eligibility_match.applicable }} published checks match your profile.
                                            </template>
                                            <template v-else>
                                                The provider did not add enough structured rules for an automatic check.
                                            </template>
                                            The provider still makes the final decision.
                                        </p>
                                    </div>
                                </div>

                                <p class="mt-5 text-sm leading-6 text-slate-600">
                                    {{ scholarship.eligibility || 'The provider has not posted a separate eligibility description.' }}
                                </p>

                                <div class="mt-5 overflow-hidden rounded-lg border border-slate-200 bg-white">
                                    <div class="grid sm:grid-cols-2">
                                        <div
                                            v-for="item in fitHighlights"
                                            :key="item.label"
                                            class="flex gap-3 border-b border-slate-200 p-3.5 last:border-b-0 sm:[&:nth-child(odd)]:border-r sm:last:col-span-2 sm:last:border-r-0"
                                        >
                                            <span class="mt-0.5 text-sm text-amber-700">
                                                <i :class="item.icon" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="text-xs font-semibold text-slate-500">{{ item.label }}</p>
                                                <p class="mt-1 whitespace-pre-line text-sm font-bold leading-5 text-slate-800">{{ item.value }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <details v-if="scholarship.eligibility_match?.criteria?.length" class="group mt-4 rounded-lg border border-slate-200 bg-slate-50">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 text-sm font-bold text-slate-800">
                                        <span class="flex items-center gap-2">
                                            <i class="fa-solid fa-list-check text-amber-700" aria-hidden="true"></i>
                                            See how your profile was checked
                                        </span>
                                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                    </summary>
                                    <div class="border-t border-slate-200 bg-white">
                                        <div
                                            v-for="criterion in scholarship.eligibility_match.criteria"
                                            :key="criterion.key"
                                            class="flex flex-col gap-2 border-b border-slate-200 p-4 last:border-b-0 sm:flex-row sm:items-start sm:justify-between"
                                        >
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold text-slate-950">{{ criterion.label }}</p>
                                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                                    Your profile: {{ criterion.student_value || criterion.studentValue || 'Not set' }}
                                                </p>
                                                <p v-if="criterion.requirement" class="mt-0.5 text-xs leading-5 text-slate-500">
                                                    Provider rule: {{ criterion.requirement }}
                                                </p>
                                            </div>
                                            <span :class="['w-fit rounded-md border px-2.5 py-1 text-xs font-bold', criterionClass(criterion.status)]">
                                                {{ criterionStatusLabel(criterion.status) }}
                                            </span>
                                        </div>
                                    </div>
                                </details>
                            </article>

                            <article id="documents" class="student-card scroll-mt-6 p-5 sm:p-6">
                                <div class="student-section-head">
                                    <div class="flex items-start gap-3">
                                        <span class="student-section-mark">
                                            <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="student-kicker">Documents</p>
                                            <h2 class="mt-1 text-xl font-bold text-slate-950">What should you prepare?</h2>
                                            <p class="mt-1 text-sm text-slate-500">Files can be prepared before you start the application.</p>
                                        </div>
                                    </div>
                                    <a href="/dashboard/documents" class="w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-slate-500">
                                        Manage files
                                    </a>
                                </div>

                                <div v-if="hasDocumentRequirements" class="mt-5 overflow-hidden rounded-lg border border-slate-200">
                                    <div class="bg-slate-50 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <p class="text-sm font-bold text-slate-950">
                                                    {{ preparedDocuments.uploaded }} of {{ preparedDocuments.required }} files ready
                                                </p>
                                                <p class="mt-1 text-xs text-slate-500">Upload or replace files anytime before submitting.</p>
                                            </div>
                                            <span class="text-sm font-bold text-slate-700">{{ preparedDocuments.percent }}%</span>
                                        </div>
                                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                            <div class="h-full rounded-full bg-slate-900" :style="{ width: `${preparedDocuments.percent}%` }"></div>
                                        </div>
                                    </div>

                                    <div class="bg-white">
                                        <div
                                            v-for="requirement in documentItems"
                                            :key="requirement"
                                            class="flex items-center justify-between gap-3 border-t border-slate-200 px-4 py-3"
                                        >
                                            <div class="flex min-w-0 items-center gap-3">
                                                <span :class="[
                                                    'grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs',
                                                    isPreparedDocument(requirement) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500',
                                                ]">
                                                    <i :class="isPreparedDocument(requirement) ? 'fa-solid fa-check' : 'fa-solid fa-upload'" aria-hidden="true"></i>
                                                </span>
                                                <p class="text-sm font-bold text-slate-800">{{ requirement }}</p>
                                            </div>
                                            <span :class="[
                                                'shrink-0 text-xs font-bold',
                                                isPreparedDocument(requirement) ? 'text-emerald-700' : 'text-slate-500',
                                            ]">
                                                {{ isPreparedDocument(requirement) ? 'Ready' : 'Upload needed' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div v-else class="mt-5 flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <span class="student-icon-badge">
                                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">No specific files are listed</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">You can continue without preparing a program-specific document.</p>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-start gap-2 text-xs leading-5 text-slate-500">
                                    <i class="fa-solid fa-circle-info mt-1 text-amber-700" aria-hidden="true"></i>
                                    <p>Application method: <span class="font-bold text-slate-700">{{ applicationModeLabel(scholarship.application_mode) }}</span>. The provider may ask to verify original files later.</p>
                                </div>
                            </article>

                            <article class="student-card p-5 sm:p-6">
                                <div class="student-section-head">
                                    <div class="flex items-start gap-3">
                                        <span class="student-section-mark">
                                            <i class="fa-solid fa-route" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="student-kicker">Selection process</p>
                                            <h2 class="mt-1 text-xl font-bold text-slate-950">What happens after applying?</h2>
                                            <p class="mt-1 text-sm text-slate-500">These are the stages chosen by the provider.</p>
                                        </div>
                                    </div>
                                    <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                        {{ selectionPlan.length }} stages
                                    </span>
                                </div>

                                <ol class="mt-5 flex flex-wrap gap-3">
                                    <li
                                        v-for="(stage, index) in selectionPlan"
                                        :key="stage.value"
                                        class="flex min-w-[14rem] flex-1 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4"
                                    >
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-900 text-xs font-bold text-white">
                                            {{ index + 1 }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <i :class="[stage.icon, 'text-xs text-amber-700']" aria-hidden="true"></i>
                                                <p class="text-sm font-bold text-slate-950">{{ stage.label }}</p>
                                            </div>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ stage.detail }}</p>
                                            <p :class="['mt-2 text-xs font-bold', stage.event ? 'text-amber-800' : 'text-slate-400']">
                                                {{ stage.event?.scheduled_label || 'Schedule to be announced' }}
                                            </p>
                                            <p v-if="stage.event && programEventPlaceLabel(stage.event)" class="mt-1 text-xs leading-5 text-slate-500">
                                                {{ programEventPlaceLabel(stage.event) }}
                                            </p>
                                        </div>
                                    </li>
                                </ol>

                                <p class="mt-4 rounded-md bg-slate-50 px-3 py-2.5 text-xs leading-5 text-slate-500 ring-1 ring-slate-200">
                                    Private links and detailed instructions are sent only when you reach the relevant stage.
                                </p>
                            </article>

                            <details v-if="hasContractDetails" class="group student-card overflow-hidden">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-5 sm:p-6">
                                    <span class="flex items-start gap-3">
                                        <span class="student-section-mark">
                                            <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                                        </span>
                                        <span>
                                            <span class="student-kicker block">Award conditions</span>
                                            <span class="mt-1 block text-lg font-bold text-slate-950">Renewal and recipient responsibilities</span>
                                            <span class="mt-1 block text-sm text-slate-500">Read these conditions before submitting.</span>
                                        </span>
                                    </span>
                                    <i class="fa-solid fa-chevron-down text-sm text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                </summary>
                                <div class="grid gap-3 border-t border-slate-200 bg-slate-50 p-5 text-sm sm:p-6">
                                    <div v-if="scholarship.renewal_policy" class="rounded-md bg-white p-4 ring-1 ring-slate-200">
                                        <p class="font-bold text-slate-950">Renewal or continuation</p>
                                        <p class="mt-1 leading-6 text-slate-600">{{ scholarship.renewal_policy }}</p>
                                    </div>
                                    <div v-if="scholarship.return_service_contract" class="rounded-md bg-white p-4 ring-1 ring-slate-200">
                                        <p class="font-bold text-slate-950">Return service</p>
                                        <p class="mt-1 whitespace-pre-line leading-6 text-slate-600">{{ scholarship.return_service_contract }}</p>
                                    </div>
                                    <div v-if="scholarship.other_contract_terms" class="rounded-md bg-white p-4 ring-1 ring-slate-200">
                                        <p class="font-bold text-slate-950">Other responsibilities</p>
                                        <p class="mt-1 whitespace-pre-line leading-6 text-slate-600">{{ scholarship.other_contract_terms }}</p>
                                    </div>
                                </div>
                            </details>

                        </section>

                        <aside class="xl:sticky xl:top-6">
                            <article class="student-card overflow-hidden">
                                <div class="p-5">
                                    <p class="student-kicker">Provider</p>
                                    <div class="mt-3 flex items-center gap-3">
                                        <img
                                            :src="scholarship.image_url"
                                            :alt="scholarship.provider?.name || 'Scholarship provider'"
                                            class="h-12 w-12 shrink-0 rounded-md bg-slate-50 object-contain p-1 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0">
                                            <h3 class="text-base font-bold leading-5 text-slate-950">
                                                {{ scholarship.provider?.name || 'Scholarship provider' }}
                                            </h3>
                                            <p class="mt-1 text-xs font-semibold text-slate-500">
                                                {{ providerTypeLabel(scholarship.provider?.type) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="divide-y divide-slate-200 border-t border-slate-200">
                                    <section class="p-5">
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-address-card text-sm text-amber-700" aria-hidden="true"></i>
                                            <h4 class="text-sm font-bold text-slate-950">Contact</h4>
                                        </div>
                                        <div v-if="scholarship.contact_email || scholarship.contact_number" class="mt-3 grid gap-2 text-sm">
                                            <a
                                                v-if="scholarship.contact_email"
                                                :href="`mailto:${scholarship.contact_email}`"
                                                class="flex min-w-0 items-center gap-2 text-slate-600 hover:text-slate-950"
                                            >
                                                <i class="fa-regular fa-envelope w-4 shrink-0 text-slate-400" aria-hidden="true"></i>
                                                <span class="break-all">{{ scholarship.contact_email }}</span>
                                            </a>
                                            <a
                                                v-if="scholarship.contact_number"
                                                :href="`tel:${scholarship.contact_number}`"
                                                class="flex items-center gap-2 text-slate-600 hover:text-slate-950"
                                            >
                                                <i class="fa-solid fa-phone w-4 shrink-0 text-slate-400" aria-hidden="true"></i>
                                                {{ scholarship.contact_number }}
                                            </a>
                                        </div>
                                        <p v-else class="mt-2 text-sm text-slate-500">No contact details listed.</p>
                                    </section>

                                    <section class="p-5">
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-location-dot text-sm text-amber-700" aria-hidden="true"></i>
                                            <h4 class="text-sm font-bold text-slate-950">Program location</h4>
                                        </div>
                                        <p class="mt-3 text-sm font-bold leading-5 text-slate-800">
                                            {{ scholarship.location_name || 'Location not named' }}
                                        </p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            {{ scholarship.location_address || scholarship.eligible_locations || 'No map address added yet.' }}
                                        </p>
                                        <p v-if="scholarship.distance_label" class="mt-2 text-xs font-bold text-slate-700">
                                            About {{ scholarship.distance_label }} from your saved location
                                        </p>

                                        <button
                                            v-if="hasMapPreview"
                                            type="button"
                                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:border-slate-500 hover:bg-slate-50"
                                            @click="showMapModal = true"
                                        >
                                            <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
                                            View on map
                                        </button>
                                    </section>

                                    <section class="bg-slate-50 p-5">
                                        <p class="text-xs leading-5 text-slate-500">
                                            Need clarification? Contact the provider before submitting. Scholarship Portal does not make the final award decision.
                                        </p>
                                    </section>
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
