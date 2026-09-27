<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import EligibilityConditionList from '../components/EligibilityConditionList.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import RecipientAgreementPanel from '../components/RecipientAgreementPanel.vue';
import ScholarshipBenefitsPanel from '../components/ScholarshipBenefitsPanel.vue';
import { labelFromKey } from '../support/display';
import { providerObjectiveDetails } from '../support/providerObjectives';
import { selectionPlanFor } from '../support/selectionPlan';

const appElement = document.getElementById('app');
const scholarshipId = appElement?.dataset.scholarshipId;
const localTodayDate = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
    .toISOString()
    .slice(0, 10);
const isLoading = ref(true);
const errorMessage = ref('');
const isSaving = ref(false);
const user = ref(null);
const scholarship = ref(null);
const showMapModal = ref(false);
const showProfileCheckModal = ref(false);
const showRecipientTermsModal = ref(false);
const detailSections = [
    { id: 'overview', label: 'Overview', icon: 'fa-solid fa-gift' },
    { id: 'eligibility', label: 'Eligibility', icon: 'fa-solid fa-user-check' },
    { id: 'requirements', label: 'Files', icon: 'fa-solid fa-folder-open' },
    { id: 'process', label: 'Process', icon: 'fa-solid fa-route' },
    { id: 'provider', label: 'Provider', icon: 'fa-solid fa-building-shield' },
];
const activeDetailSection = ref(detailSections.some((section) => section.id === window.location.hash.slice(1))
    ? window.location.hash.slice(1)
    : 'overview');
const profileReadiness = ref({
    complete: false,
    completed: 0,
    total: 0,
    percent: 0,
    missing: [],
});
const applicationModeOptions = [
    { value: 'online', label: 'Portal review' },
    { value: 'onsite', label: 'Portal review with in-person verification' },
    { value: 'provider_review', label: 'Profile review only' },
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
const optionalDocumentItems = computed(() => scholarship.value?.application_mode === 'provider_review'
    ? []
    : documentRequirements(scholarship.value?.optional_requirements));
const postQualificationDocumentItems = computed(() =>
    documentRequirements(scholarship.value?.post_qualification_requirements));
const selectedProviderObjectives = computed(() => providerObjectiveDetails(scholarship.value?.provider_objectives));
const documentRequirementSummary = computed(() => scholarship.value?.application_mode === 'provider_review'
    ? 'No files required for the initial review'
    : (hasDocumentRequirements.value
        ? `${documentItems.value.length} requirement${documentItems.value.length === 1 ? '' : 's'}`
        : 'No documents listed'));
const selectionPlan = computed(() => selectionPlanFor(scholarship.value));
const canApply = computed(() => profileReadiness.value.complete);
const isEligible = computed(() => scholarship.value?.eligibility_match?.is_eligible !== false);
const eligibilityChecks = computed(() => new Map(
    (scholarship.value?.eligibility_match?.criteria ?? [])
        .map((criterion) => [criterion.key, criterion]),
));
const eligibilityStatusCounts = computed(() => scholarship.value?.eligibility_match?.status_counts ?? {
    matched: (scholarship.value?.eligibility_match?.criteria ?? []).filter((criterion) => criterion.status === 'pass' && criterion.key !== 'documents').length,
    different: (scholarship.value?.eligibility_match?.criteria ?? []).filter((criterion) => criterion.status === 'fail' && criterion.key !== 'documents').length,
    missing: (scholarship.value?.eligibility_match?.criteria ?? []).filter((criterion) => criterion.status === 'missing' && criterion.key !== 'documents').length,
    open: (scholarship.value?.eligibility_match?.criteria ?? []).filter((criterion) => criterion.status === 'info' && criterion.key !== 'documents').length,
});
const eligibilityConditionResults = computed(() => scholarship.value?.eligibility_match?.condition_results ?? []);
const isAcceptingApplications = computed(() => scholarship.value?.is_accepting_applications !== false);
const isUpcomingProgram = computed(() => Boolean(
    scholarship.value?.application_opens_date
    && scholarship.value.application_opens_date > localTodayDate,
));
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
            title: 'Your profile matches the structured restrictions',
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
            icon: 'fa-solid fa-gift',
            label: 'Benefits',
            value: current.benefits?.length
                ? `${current.benefits.length} included benefit${current.benefits.length === 1 ? '' : 's'}`
                : formatAmount(current.award_amount),
            detail: current.benefits?.length > 1
                ? 'See the complete support package below'
                : (current.benefits?.[0]?.title || 'Support listed by the provider'),
        },
        {
            icon: 'fa-regular fa-calendar',
            label: 'Pre-screen by',
            value: current.deadline || 'No deadline listed',
            detail: isUpcomingProgram.value
                ? `Opens ${current.application_opens_at}`
                : (isAcceptingApplications.value ? 'Pre-screening is open' : 'Pre-screening is closed'),
        },
        {
            icon: 'fa-solid fa-users',
            label: 'Available slots',
            value: current.slots_available ?? 'Not listed',
            detail: current.slots_available ? 'Planned recipients' : 'Ask the provider for availability',
        },
        {
            icon: 'fa-solid fa-calendar-days',
            label: 'Support period',
            value: current.support_starts_at && current.support_ends_at
                ? `${current.support_starts_at} - ${current.support_ends_at}`
                : 'Not fully announced',
            detail: current.support_ends_at
                ? `Recipient support is expected to end on ${current.support_ends_at}`
                : 'Ask the provider when this cycle of support ends',
        },
    ];
});
const applyPanelTitle = computed(() => {
    if (scholarship.value?.has_applied) {
        return 'Pre-screening submitted';
    }

    if (isUpcomingProgram.value) {
        return `Opens ${scholarship.value.application_opens_at}`;
    }

    if (!isAcceptingApplications.value) {
        return 'Pre-screening is closed';
    }

    if (!canApply.value) {
        return 'Complete profile first';
    }

    if (!isEligible.value) {
        return 'Not eligible right now';
    }

    return 'Ready for pre-screening';
});
const applyPanelDescription = computed(() => {
    if (scholarship.value?.has_applied) {
        return 'Open Applications to review your status and next step.';
    }

    if (isUpcomingProgram.value) {
        return 'Save this scholarship and return when pre-screening opens.';
    }

    if (!isAcceptingApplications.value) {
        return 'New submissions are closed. You can still save this scholarship.';
    }

    if (!canApply.value) {
        return 'Complete the required profile details to continue.';
    }

    if (!isEligible.value) {
        return applicationBlockedLabel.value;
    }

    return 'Review eligibility and files before submitting.';
});
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
    const normalizedValue = value === 'hybrid' ? 'onsite' : value;

    return applicationModeOptions.find((option) => option.value === normalizedValue)?.label ?? labelFromKey(value || 'not_listed');
}

function applicationModeDescription(value) {
    return {
        online: 'Your profile and required files are reviewed through the portal.',
        onsite: 'Upload the required files first. The provider may later ask to see the originals in person.',
        hybrid: 'Upload the required files first. The provider may later ask to see the originals in person.',
        provider_review: 'The provider checks your profile first. No program files are required for this initial review.',
    }[value] ?? 'The provider will review your portal submission before its formal application process.';
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

function eligibilityCriterionText(value, fallback = '') {
    if (Array.isArray(value)) {
        const items = value
            .map((item) => eligibilityCriterionText(item))
            .filter(Boolean);

        return items.length ? items.join(', ') : fallback;
    }

    const text = String(value ?? '').trim();

    return text ? labelFromKey(text) : fallback;
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

function criterionStatusLabel(criterion) {
    if (criterion.status === 'pass') {
        return 'Matched';
    }

    if (criterion.status === 'fail') {
        return 'Not matched';
    }

    if (criterion.status === 'missing') {
        return 'Missing info';
    }

    return criterion.key === 'academic' && criterion.requirement
        ? 'Provider review'
        : 'Open to all';
}

function selectDetailSection(sectionId, scrollToContent = false) {
    activeDetailSection.value = sectionId;
    const url = new URL(window.location.href);
    url.hash = sectionId;
    window.history.replaceState({}, '', url);

    if (scrollToContent) {
        window.requestAnimationFrame(() => {
            document.getElementById('scholarship-detail-content')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }
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
                <a href="/dashboard/scholarships" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to scholarships
                </a>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading scholarship details...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="scholarship" class="mt-5 space-y-4">
                    <header class="student-card flex flex-col overflow-hidden rounded-md border-slate-300 shadow-[0_12px_28px_rgba(15,23,42,0.08)]">
                        <div class="relative overflow-hidden bg-slate-950 p-4 text-white sm:p-5">
                            <span class="pointer-events-none absolute -right-14 -top-20 h-52 w-52 rounded-full border-[26px] border-white/[0.04]"></span>
                            <div class="relative flex flex-col gap-4 sm:flex-row sm:items-start">
                                <img
                                    :src="scholarship.image_url"
                                    :alt="scholarship.title"
                                    class="h-16 w-16 shrink-0 rounded-sm bg-white object-contain p-1.5 ring-1 ring-white/20 sm:h-18 sm:w-18"
                                >
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-sm bg-white/10 px-2 py-0.5 text-[10px] font-bold text-slate-100 ring-1 ring-white/10">
                                            {{ scholarship.category || providerTypeLabel(scholarship.provider?.type) }}
                                        </span>
                                        <span class="rounded-sm bg-white/10 px-2 py-0.5 text-[10px] font-bold text-slate-100 ring-1 ring-white/10">
                                            {{ targetApplicantLabel(scholarship) }}
                                        </span>
                                        <span v-if="scholarship.program_cycle" class="rounded-sm bg-amber-300 px-2 py-0.5 text-[10px] font-bold text-slate-950">
                                            {{ scholarship.program_cycle }}
                                        </span>
                                    </div>

                                    <h1 class="mt-2 font-display text-2xl font-bold leading-tight text-white sm:text-[1.85rem]">
                                        {{ scholarship.title }}
                                    </h1>
                                    <p class="mt-1.5 flex items-center gap-2 text-sm font-semibold text-slate-300">
                                        <i class="fa-solid fa-building-shield text-amber-300" aria-hidden="true"></i>
                                        {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                    </p>
                                    <p class="mt-3 line-clamp-2 max-w-4xl text-sm leading-5 text-slate-300">
                                        {{ scholarship.description || 'No program description has been posted yet.' }}
                                    </p>
                                    <p v-if="scholarship.expected_results_at" class="mt-3 inline-flex items-center gap-2 text-xs font-semibold text-slate-300">
                                        <i class="fa-regular fa-calendar-check text-amber-300" aria-hidden="true"></i>
                                        Initial results expected around {{ scholarship.expected_results_at }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <section class="grid bg-white sm:grid-cols-2 xl:grid-cols-4" aria-label="Scholarship facts">
                            <article
                                v-for="fact in keyFacts"
                                :key="fact.label"
                                class="flex gap-3 border-b border-slate-200 p-3 last:border-b-0 sm:[&:nth-child(odd)]:border-r sm:[&:nth-last-child(-n+2)]:border-b-0 xl:border-b-0 xl:border-r xl:last:border-r-0"
                            >
                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-sm bg-amber-100 text-xs text-amber-800">
                                    <i :class="fact.icon" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">{{ fact.label }}</p>
                                    <p class="mt-1 text-sm font-bold leading-5 text-slate-950">{{ fact.value }}</p>
                                </div>
                            </article>
                        </section>

                    </header>

                    <section class="student-card overflow-hidden rounded-md border-slate-300 border-l-4 border-l-amber-400 bg-white p-3.5 shadow-sm sm:p-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-sm', scholarship.has_applied || canStartApplication ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">
                                    <i :class="scholarship.has_applied ? 'fa-solid fa-check' : canStartApplication ? 'fa-solid fa-arrow-right' : 'fa-solid fa-circle-info'" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Your next step</p>
                                        <span :class="['rounded-sm px-2 py-1 text-[10px] font-bold', matchClass(scholarship.eligibility_match?.score)]">
                                            {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                        </span>
                                    </div>
                                    <h2 class="mt-1 text-base font-bold text-slate-950">{{ applyPanelTitle }}</h2>
                                    <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-600">{{ applyPanelDescription }}</p>
                                    <div v-if="!canApply && !scholarship.has_applied" class="mt-3 flex max-w-md items-center gap-3">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200"><div class="h-full bg-amber-400" :style="{ width: `${profileReadiness.percent}%` }"></div></div>
                                        <span class="text-xs font-bold text-slate-600">Profile {{ profileReadiness.percent }}%</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-col gap-2 sm:flex-row">
                                <a v-if="scholarship.has_applied" href="/dashboard/applications" class="rounded-sm bg-slate-950 px-4 py-2 text-center text-sm font-bold text-white transition hover:bg-slate-800">View application</a>
                                <a v-else-if="canStartApplication" :href="`/dashboard/applications?scholarship=${scholarship.id}`" class="rounded-sm bg-slate-950 px-4 py-2 text-center text-sm font-bold text-white transition hover:bg-slate-800">Start pre-screening</a>
                                <span v-else-if="isUpcomingProgram" class="rounded-sm bg-amber-100 px-4 py-2 text-center text-sm font-bold text-amber-900">Opens {{ scholarship.application_opens_at }}</span>
                                <span v-else-if="!isAcceptingApplications" class="rounded-sm bg-slate-200 px-4 py-2 text-center text-sm font-bold text-slate-600">Pre-screening closed</span>
                                <a v-else-if="!canApply" href="/dashboard/profile" class="rounded-sm bg-slate-950 px-4 py-2 text-center text-sm font-bold text-white transition hover:bg-slate-800">Complete profile</a>
                                <button v-else-if="!isEligible" type="button" class="rounded-sm bg-slate-950 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-800" @click="selectDetailSection('eligibility', true)">Review eligibility</button>
                                <span v-else class="rounded-sm bg-slate-200 px-4 py-2 text-center text-sm font-bold text-slate-600">Unavailable</span>

                                <button type="button" :disabled="isSaving" class="inline-flex items-center justify-center gap-2 rounded-sm border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60" @click="toggleSave">
                                    <i :class="scholarship.is_saved ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark'" aria-hidden="true"></i>
                                    {{ isSaving ? 'Saving...' : scholarship.is_saved ? 'Saved' : 'Save' }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <nav id="scholarship-detail-navigation" class="student-card overflow-hidden rounded-md border-slate-300 p-1.5" aria-label="Scholarship details">
                        <div class="flex gap-1 overflow-x-auto">
                            <button
                                v-for="section in detailSections"
                                :key="section.id"
                                type="button"
                                :aria-current="activeDetailSection === section.id ? 'page' : undefined"
                                :class="[
                                    'flex min-h-11 min-w-[8.5rem] flex-1 items-center justify-center gap-2 rounded-sm px-3 py-2.5 text-sm font-bold transition',
                                    activeDetailSection === section.id
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950',
                                ]"
                                @click="selectDetailSection(section.id)"
                            >
                                <i :class="[section.icon, activeDetailSection === section.id ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                                {{ section.label }}
                            </button>
                        </div>
                    </nav>

                    <div id="scholarship-detail-content" class="scroll-mt-5 space-y-5">
                        <section class="space-y-5">
                            <article v-if="activeDetailSection === 'overview'" class="student-card overflow-hidden rounded-md border-slate-300">
                                <header class="flex items-start gap-3 border-b border-slate-200 bg-slate-50 p-4 sm:p-5">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="student-kicker">Program overview</p>
                                        <h2 class="mt-1 text-xl font-bold text-slate-950">Support and purpose</h2>
                                    </div>
                                </header>

                                <section class="p-4 sm:p-5">
                                    <div class="flex items-center justify-between gap-3">
                                        <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Support package</p><h3 class="mt-1 font-bold text-slate-950">What recipients receive</h3></div>
                                        <span class="rounded-sm bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{{ scholarship.benefits?.length || 0 }} item{{ scholarship.benefits?.length === 1 ? '' : 's' }}</span>
                                    </div>
                                    <ScholarshipBenefitsPanel v-if="scholarship.benefits?.length" class="mt-4" :benefits="scholarship.benefits" uniform dense />
                                    <p v-else class="mt-4 rounded-sm bg-slate-50 p-4 text-sm text-slate-600">{{ formatAmount(scholarship.award_amount) }} listed by the provider.</p>
                                </section>

                                <section v-if="selectedProviderObjectives.length || scholarship.provider_objective_notes" class="border-t border-slate-200 p-4 sm:p-5">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Provider purpose</p>
                                    <h3 class="mt-1 font-bold text-slate-950">Why this scholarship is offered</h3>
                                    <div v-if="selectedProviderObjectives.length" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                        <div v-for="objective in selectedProviderObjectives" :key="objective.value" class="flex items-start gap-3 rounded-sm border border-slate-200 bg-slate-50 p-3.5">
                                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-sm bg-white text-slate-700 ring-1 ring-slate-200"><i :class="objective.icon" aria-hidden="true"></i></span>
                                            <p class="self-center text-sm font-bold text-slate-950">{{ objective.label }}</p>
                                        </div>
                                    </div>
                                    <p v-if="scholarship.provider_objective_notes" class="mt-4 line-clamp-3 border-l-2 border-amber-300 pl-3 text-sm leading-6 text-slate-700">{{ scholarship.provider_objective_notes }}</p>
                                </section>
                            </article>

                            <article v-if="activeDetailSection === 'overview'" class="student-card flex flex-col gap-4 rounded-md border-slate-300 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-file-signature" aria-hidden="true"></i></span>
                                    <div><p class="student-kicker">Recipient terms</p><h3 class="mt-1 font-bold text-slate-950">Support and responsibilities</h3><p class="mt-1 text-sm text-slate-500">Review what you receive and what is expected if selected.</p></div>
                                </div>
                                <button type="button" class="shrink-0 rounded-sm border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100" @click="showRecipientTermsModal = true">Review terms</button>
                            </article>

                            <article v-if="activeDetailSection === 'eligibility'" id="eligibility" class="student-card scroll-mt-6 overflow-hidden rounded-md border-slate-300">
                                <header class="flex flex-col gap-4 border-b border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
                                        <div><p class="student-kicker">Eligibility</p><h2 class="mt-1 text-xl font-bold text-slate-950">Can you apply?</h2></div>
                                    </div>
                                    <button v-if="scholarship.eligibility_match?.criteria?.length" type="button" class="inline-flex w-fit items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100" @click="showProfileCheckModal = true">
                                        See profile comparison<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </button>
                                </header>

                                <section class="p-4 sm:p-5">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-sm', eligibilityState.classes]"><i :class="eligibilityState.icon" aria-hidden="true"></i></span>
                                            <div>
                                                <h3 class="font-bold text-slate-950">{{ eligibilityState.title }}</h3>
                                                <p class="mt-1 line-clamp-2 max-w-3xl text-sm leading-6 text-slate-600">{{ scholarship.eligibility_match?.difference_summary || scholarship.eligibility_match?.summary || 'Review the program rules against your profile.' }}</p>
                                                <p v-if="canApply && !isEligible" class="mt-2 text-sm font-semibold text-rose-700">{{ applicationBlockedLabel }}</p>
                                            </div>
                                        </div>
                                        <span :class="['w-fit shrink-0 rounded-sm px-3 py-1.5 text-xs font-bold', matchClass(scholarship.eligibility_match?.score)]">{{ scholarship.eligibility_match?.score ?? 0 }}% match</span>
                                    </div>
                                    <p class="mt-4 border-t border-slate-200 pt-3 text-xs font-semibold text-slate-500">
                                        {{ eligibilityStatusCounts.matched }} matched
                                        <span v-if="eligibilityStatusCounts.different"> / {{ eligibilityStatusCounts.different }} different</span>
                                        <span v-if="eligibilityStatusCounts.missing"> / {{ eligibilityStatusCounts.missing }} missing</span>
                                        <span v-if="eligibilityStatusCounts.open"> / {{ eligibilityStatusCounts.open }} unrestricted</span>
                                    </p>
                                </section>

                                <section class="border-t border-slate-200 p-4 sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <i class="fa-solid fa-file-circle-check mt-1 text-amber-700" aria-hidden="true"></i>
                                        <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Required by the provider</p><p class="mt-1 whitespace-pre-line text-sm font-semibold leading-6 text-slate-900">{{ scholarship.eligibility || 'No separate written eligibility conditions were posted.' }}</p><p class="mt-2 text-xs leading-5 text-slate-500">The provider reviews conditions the portal cannot confirm online.</p></div>
                                    </div>
                                </section>

                                <section v-if="eligibilityConditionResults.length" class="border-t border-slate-200 bg-slate-50 p-4 sm:p-5">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Written condition review</p>
                                    <EligibilityConditionList class="mt-3" :conditions="eligibilityConditionResults" />
                                </section>
                            </article>

                            <article v-if="activeDetailSection === 'requirements'" id="documents" class="student-card scroll-mt-6 rounded-md border-slate-300 p-4 sm:p-5">
                                <div class="student-section-head">
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                                            <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="student-kicker">Documents</p>
                                            <h2 class="mt-1 text-xl font-bold text-slate-950">Files to prepare</h2>
                                        </div>
                                    </div>
                                    <a href="/dashboard/documents" class="w-fit rounded-sm border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                        Manage files
                                    </a>
                                </div>

                                <div v-if="hasDocumentRequirements" class="mt-5 overflow-hidden rounded-sm border border-slate-200">
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

                                <div v-if="optionalDocumentItems.length" class="mt-4 rounded-sm border border-amber-200 bg-amber-50 p-4">
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                            <i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="text-sm font-bold text-amber-950">Optional supporting files</p>
                                            <p class="mt-1 text-xs leading-5 text-amber-900">Helpful, but not required.</p>
                                        </div>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <span
                                            v-for="requirement in optionalDocumentItems"
                                            :key="requirement"
                                            class="rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-amber-200"
                                        >
                                            {{ requirement }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="postQualificationDocumentItems.length" class="mt-4 overflow-hidden rounded-sm border border-slate-200 bg-white">
                                    <div class="flex items-start gap-3 bg-slate-950 p-4 text-white">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                                            <i class="fa-solid fa-briefcase" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="text-sm font-bold">Prepare only if you qualify</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-300">
                                                Bring these to the provider only after you qualify.
                                            </p>
                                        </div>
                                    </div>
                                    <div class="grid gap-x-5 gap-y-2 p-4 sm:grid-cols-2">
                                        <div v-for="item in postQualificationDocumentItems" :key="item" class="flex items-start gap-2 text-sm leading-6 text-slate-700">
                                            <i class="fa-solid fa-circle-check mt-1.5 text-xs text-emerald-700" aria-hidden="true"></i>
                                            <span>{{ item }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="!hasDocumentRequirements" class="mt-5 flex items-start gap-3 rounded-sm border border-slate-200 bg-slate-50 p-4">
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
                                    <p><span class="font-bold text-slate-700">{{ applicationModeLabel(scholarship.application_mode) }}:</span> {{ applicationModeDescription(scholarship.application_mode) }} Passing this review does not guarantee the scholarship.</p>
                                </div>
                            </article>

                            <article v-if="activeDetailSection === 'process'" class="student-card overflow-hidden rounded-md border-slate-300">
                                <div class="student-section-head border-b border-slate-200 bg-slate-50 p-4 sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                                            <i class="fa-solid fa-route" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="student-kicker">Selection process</p>
                                            <h2 class="mt-1 text-xl font-bold text-slate-950">After pre-screening</h2>
                                        </div>
                                    </div>
                                    <span class="w-fit rounded-sm bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                        {{ selectionPlan.length }} stages
                                    </span>
                                </div>

                                <ol class="divide-y divide-slate-200 bg-white">
                                    <li
                                        v-for="(stage, index) in selectionPlan"
                                        :key="stage.value"
                                        class="grid gap-3 p-4 sm:grid-cols-[2.5rem_minmax(0,1fr)_minmax(10rem,auto)] sm:items-center sm:p-5"
                                    >
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-950 text-xs font-bold text-white">
                                            {{ index + 1 }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <i :class="[stage.icon, 'text-xs text-amber-700']" aria-hidden="true"></i>
                                                <p class="text-sm font-bold text-slate-950">{{ stage.label }}</p>
                                            </div>
                                            <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">{{ stage.detail }}</p>
                                        </div>
                                        <div class="sm:text-right"><p :class="['text-xs font-bold', stage.event ? 'text-amber-800' : 'text-slate-400']">{{ stage.event?.scheduled_label || 'Schedule to be announced' }}</p><p v-if="stage.event && programEventPlaceLabel(stage.event)" class="mt-1 text-xs leading-5 text-slate-500">{{ programEventPlaceLabel(stage.event) }}</p></div>
                                    </li>
                                </ol>

                            </article>
                        </section>

                        <section v-if="activeDetailSection === 'provider'" class="student-card overflow-hidden rounded-md border-slate-300">
                            <div class="student-section-head border-b border-slate-200 bg-slate-50 p-4 sm:p-5">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                                        <i class="fa-solid fa-building-shield" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="student-kicker">Scholarship provider</p>
                                        <h2 class="mt-1 text-xl font-bold text-slate-950">Who manages this program</h2>
                                    </div>
                                </div>
                                <a
                                    v-if="scholarship.official_program_url"
                                    :href="scholarship.official_program_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex w-fit items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Official program page
                                    <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                </a>
                            </div>

                            <div class="p-4 sm:p-5">
                                <div class="flex items-center gap-3">
                                    <img
                                        :src="scholarship.image_url"
                                        :alt="scholarship.provider?.name || 'Scholarship provider'"
                                        class="h-14 w-14 shrink-0 rounded-sm bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Organization</p>
                                        <h3 class="mt-1 text-base font-bold leading-5 text-slate-950">{{ scholarship.provider?.name || 'Scholarship provider' }}</h3>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ providerTypeLabel(scholarship.provider?.type) }}</p>
                                    </div>
                                </div>

                                <dl class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-sm border border-slate-200">
                                    <div class="grid gap-3 p-4 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-5">
                                        <dt class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-address-card text-amber-700" aria-hidden="true"></i>Public contact</dt>
                                        <dd v-if="scholarship.contact_person || scholarship.contact_department || scholarship.contact_email || scholarship.contact_number" class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-600">
                                            <span v-if="scholarship.contact_department || scholarship.contact_person" class="font-semibold text-slate-800">{{ scholarship.contact_department || scholarship.contact_person }}<span v-if="scholarship.contact_department && scholarship.contact_person" class="font-normal text-slate-500"> | {{ scholarship.contact_person }}</span></span>
                                            <a v-if="scholarship.contact_email" :href="`mailto:${scholarship.contact_email}`" class="inline-flex min-w-0 items-center gap-2 hover:text-slate-950"><i class="fa-regular fa-envelope text-slate-400" aria-hidden="true"></i><span class="break-all">{{ scholarship.contact_email }}</span></a>
                                            <a v-if="scholarship.contact_number" :href="`tel:${scholarship.contact_number}`" class="inline-flex items-center gap-2 hover:text-slate-950"><i class="fa-solid fa-phone text-slate-400" aria-hidden="true"></i>{{ scholarship.contact_number }}</a>
                                        </dd>
                                        <dd v-else class="text-sm text-slate-500">No public contact details listed.</dd>
                                    </div>

                                    <div class="grid gap-3 p-4 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-5">
                                        <dt class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-location-dot text-amber-700" aria-hidden="true"></i>Program location</dt>
                                        <dd class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div><p class="text-sm font-bold text-slate-800">{{ scholarship.location_name || 'Location not named' }}</p><p class="mt-1 text-sm leading-5 text-slate-600">{{ scholarship.location_address || scholarship.eligible_locations || 'No map address added yet.' }}</p><p v-if="scholarship.distance_label" class="mt-1 text-xs font-bold text-slate-600">About {{ scholarship.distance_label }} from your saved location</p></div>
                                            <button v-if="hasMapPreview" type="button" class="inline-flex w-fit shrink-0 items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100" @click="showMapModal = true"><i class="fa-solid fa-map-location-dot text-amber-700" aria-hidden="true"></i>View map</button>
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            <p class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-500 sm:px-6">
                                The provider makes the final qualification and award decision. Contact them when program instructions need clarification.
                            </p>
                        </section>
                    </div>
                </div>

            </div>
        </section>

        <Teleport to="body">
            <div v-if="showRecipientTermsModal && scholarship" class="fixed inset-0 z-[2500] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5" @click.self="showRecipientTermsModal = false">
                <section class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="recipient-terms-modal-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-file-signature" aria-hidden="true"></i></span>
                            <div><p class="student-kicker">Recipient terms</p><h2 id="recipient-terms-modal-title" class="mt-1 text-xl font-bold text-slate-950">Support and responsibilities</h2></div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-sm border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-slate-950" aria-label="Close recipient terms" @click="showRecipientTermsModal = false"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </header>
                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-3 sm:p-5">
                        <RecipientAgreementPanel :scholarship="scholarship" hide-header />
                    </div>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showProfileCheckModal && scholarship?.eligibility_match?.criteria?.length"
                class="fixed inset-0 z-[2500] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5"
                @click.self="showProfileCheckModal = false"
                @keydown.esc="showProfileCheckModal = false"
            >
                <section
                    class="flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="profile-check-modal-title"
                >
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Eligibility comparison</p>
                                <h2 id="profile-check-modal-title" class="mt-1 text-xl font-bold text-slate-950">How your profile was checked</h2>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                            aria-label="Close profile comparison"
                            @click="showProfileCheckModal = false"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <div
                                v-for="criterion in scholarship.eligibility_match.criteria"
                                :key="criterion.key"
                                class="border-b border-slate-200 p-4 last:border-b-0 sm:p-5"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-bold text-slate-950">{{ eligibilityCriterionText(criterion.label, 'Eligibility requirement') }}</p>
                                    <span :class="['w-fit shrink-0 rounded-md border px-2.5 py-1 text-xs font-bold', criterionClass(criterion.status)]">
                                        {{ criterionStatusLabel(criterion) }}
                                    </span>
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <div class="rounded-md bg-slate-50 px-3 py-2.5">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Your profile</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800">{{ eligibilityCriterionText(criterion.student_value || criterion.studentValue, 'Not provided') }}</p>
                                    </div>
                                    <div class="rounded-md bg-slate-50 px-3 py-2.5">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">This program accepts</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-800">
                                            {{ criterion.status === 'info' ? 'Open to all' : eligibilityCriterionText(criterion.requirement, 'No restriction') }}
                                        </p>
                                    </div>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-slate-500">{{ criterion.comparison || criterion.note }}</p>
                            </div>
                        </div>

                    </div>

                    <footer class="flex items-center justify-between gap-4 border-t border-slate-200 bg-white px-5 py-4 sm:px-6">
                        <p class="hidden text-xs leading-5 text-slate-500 sm:block">Matching helps with pre-screening; the provider still makes the final decision.</p>
                        <button
                            type="button"
                            class="ml-auto rounded-md bg-slate-950 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            @click="showProfileCheckModal = false"
                        >
                            Close
                        </button>
                    </footer>
                </section>
            </div>
        </Teleport>

        <LocationMapModal
            v-if="scholarship"
            :open="showMapModal"
            eyebrow="Program location"
            :title="scholarship.location_name || scholarship.title"
            :address="scholarshipMapAddress"
            :latitude="scholarship.latitude"
            :longitude="scholarship.longitude"
            :secondary-latitude="user?.latitude"
            :secondary-longitude="user?.longitude"
            :secondary-marker-text="userLocationLabel"
            :distance-label="scholarship.distance_label ? `About ${scholarship.distance_label}` : ''"
            :marker-text="scholarship.location_name || scholarship.title"
            :note="hasUserMapLocation && scholarship.distance_label ? `Your saved location is shown too: ${scholarship.distance_label} from this program.` : 'Add your profile map pin to compare distance with this program.'"
            @close="showMapModal = false"
        />
    </main>
</template>
