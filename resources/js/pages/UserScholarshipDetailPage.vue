<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import EligibilityConditionList from '../components/EligibilityConditionList.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import RecipientAgreementPanel from '../components/RecipientAgreementPanel.vue';
import { labelFromKey } from '../support/display';
import { providerObjectiveDetails } from '../support/providerObjectives';

const appElement = document.getElementById('app');
const scholarshipId = appElement?.dataset.scholarshipId;
const isLoading = ref(true);
const errorMessage = ref('');
const isSaving = ref(false);
const user = ref(null);
const detail = ref(null);
const programImageLoaded = ref(false);
const programImageFailed = ref(false);
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
const overview = computed(() => detail.value?.sections?.overview ?? {});
const overviewBenefits = computed(() => overview.value.benefits ?? []);
const overviewTerms = computed(() => overview.value.terms_preview ?? []);
const selectedProviderObjectives = computed(() => providerObjectiveDetails(overview.value.provider_objectives));
const decision = computed(() => detail.value?.decision ?? null);
const detailFacts = computed(() => detail.value?.facts ?? []);
const scholarshipStatus = computed(() => ({
    applied: { label: 'Application started', classes: 'bg-emerald-100 text-emerald-800' },
    upcoming: { label: 'Opens soon', classes: 'bg-amber-100 text-amber-800' },
    full: { label: 'Application limit reached', classes: 'bg-slate-200 text-slate-700' },
    closed: { label: 'Applications closed', classes: 'bg-slate-200 text-slate-700' },
    profile_incomplete: { label: 'Accepting applications', classes: 'bg-amber-100 text-amber-800' },
    blocked: { label: 'Accepting applications', classes: 'bg-amber-100 text-amber-800' },
    eligible: { label: 'Accepting applications', classes: 'bg-emerald-100 text-emerald-800' },
}[decision.value?.state] ?? {
    label: 'Scholarship program',
    classes: 'bg-slate-100 text-slate-700',
}));
const agreementScholarship = computed(() => {
    const terms = overview.value.recipient_terms ?? {};

    return {
        benefits: overviewBenefits.value,
        benefit_summary: detailFacts.value.find((fact) => fact.key === 'benefit')?.value ?? '',
        renewal_policy: terms.renewal_policy ?? null,
        return_service_contract: terms.return_service_contract ?? null,
        other_contract_terms: terms.other_contract_terms ?? null,
        recipient_agreement: terms.agreement ?? {},
    };
});
const eligibilityOverview = computed(() => detail.value?.sections?.eligibility ?? {});
const eligibilityBlockers = computed(() => eligibilityOverview.value.blockers ?? []);
const eligibilityMissingChecks = computed(() => eligibilityOverview.value.missing_checks ?? []);
const eligibilityProfileMissing = computed(() => eligibilityOverview.value.profile?.missing ?? []);
const eligibilityProviderReview = computed(() => eligibilityOverview.value.provider_review ?? []);
const requirementsOverview = computed(() => detail.value?.sections?.requirements ?? {});
const requirementsReadiness = computed(() => requirementsOverview.value.readiness ?? {
    state: 'not_required',
    title: 'No files needed before you apply',
    description: 'The provider has not listed any files for the initial review.',
    items: [],
});
const optionalDocumentItems = computed(() => requirementsOverview.value.optional_documents ?? []);
const postQualificationDocumentItems = computed(() => requirementsOverview.value.post_qualification_documents ?? []);
const otherDocumentCount = computed(() => optionalDocumentItems.value.length + postQualificationDocumentItems.value.length);
const processOverview = computed(() => detail.value?.sections?.process ?? {});
const selectionPlan = computed(() => processOverview.value.stages ?? []);
const providerOverview = computed(() => detail.value?.sections?.provider ?? {});
const providerLocation = computed(() => providerOverview.value.location ?? {});
const requirementsStatusStyle = computed(() => ({
    ready: {
        icon: 'fa-solid fa-check',
        classes: 'bg-emerald-100 text-emerald-800',
    },
    needs_files: {
        icon: 'fa-solid fa-upload',
        classes: 'bg-amber-100 text-amber-800',
    },
    not_required: {
        icon: 'fa-solid fa-check',
        classes: 'bg-slate-100 text-slate-700',
    },
}[requirementsReadiness.value.state] ?? {
    icon: 'fa-solid fa-folder-open',
    classes: 'bg-slate-100 text-slate-700',
}));
const eligibilityVerdict = computed(() => {
    if (!eligibilityOverview.value.profile?.complete) {
        return {
            title: 'Complete your profile for an accurate check',
            description: `${eligibilityProfileMissing.value.length} required profile detail${eligibilityProfileMissing.value.length === 1 ? ' is' : 's are'} still missing.`,
            icon: 'fa-solid fa-circle-info',
            classes: 'bg-amber-100 text-amber-800',
        };
    }

    if (!eligibilityBlockers.value.length) {
        return {
            title: 'No automatic blockers found',
            description: 'Your saved profile meets the published rules the portal can check.',
            icon: 'fa-solid fa-check',
            classes: 'bg-emerald-100 text-emerald-800',
        };
    }

    return {
        title: 'Some requirements do not match',
        description: 'Review the items below before deciding whether to continue.',
        icon: 'fa-solid fa-triangle-exclamation',
        classes: 'bg-rose-100 text-rose-800',
    };
});
const scholarshipMapAddress = computed(() => {
    const parts = [
        providerLocation.value.address,
        providerLocation.value.name,
    ].filter(Boolean);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});
const hasMapPreview = computed(() => Boolean(providerLocation.value.has_map));
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
function decisionIcon(state) {
    return {
        applied: 'fa-solid fa-arrow-up-right-from-square',
        eligible: 'fa-solid fa-arrow-right',
        profile_incomplete: 'fa-solid fa-user-pen',
        blocked: 'fa-solid fa-list-check',
        upcoming: 'fa-regular fa-clock',
        full: 'fa-solid fa-users-slash',
        closed: 'fa-solid fa-lock',
    }[state] ?? 'fa-solid fa-circle-info';
}

function decisionTone(tone) {
    return {
        positive: {
            panel: 'border-emerald-200 border-l-emerald-500 bg-emerald-50/60',
            icon: 'bg-emerald-100 text-emerald-800',
        },
        blocked: {
            panel: 'border-rose-200 border-l-rose-500 bg-rose-50/60',
            icon: 'bg-rose-100 text-rose-800',
        },
        muted: {
            panel: 'border-slate-300 border-l-slate-500 bg-slate-50',
            icon: 'bg-slate-200 text-slate-700',
        },
        attention: {
            panel: 'border-amber-200 border-l-amber-400 bg-amber-50/70',
            icon: 'bg-amber-100 text-amber-800',
        },
    }[tone] ?? {
        panel: 'border-slate-300 border-l-slate-500 bg-slate-50',
        icon: 'bg-slate-200 text-slate-700',
    };
}
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

function benefitIcon(type) {
    return {
        cash_grant: 'fa-solid fa-peso-sign',
        tuition_coverage: 'fa-solid fa-school',
        allowance: 'fa-solid fa-wallet',
        school_supplies: 'fa-solid fa-book-open',
        device_support: 'fa-solid fa-laptop',
        transportation: 'fa-solid fa-bus',
        accommodation: 'fa-solid fa-house',
        training: 'fa-solid fa-certificate',
        mentorship: 'fa-solid fa-people-arrows',
        fee_waiver: 'fa-solid fa-receipt',
    }[type] ?? 'fa-solid fa-gift';
}

function benefitDetails(benefit) {
    return [benefit.coverage_label, benefit.frequency_label, benefit.duration]
        .filter(Boolean)
        .join(' | ');
}

function hasCoordinates(latitude, longitude) {
    if (latitude === null || latitude === undefined || latitude === '' || longitude === null || longitude === undefined || longitude === '') {
        return false;
    }

    return Number.isFinite(Number(latitude)) && Number.isFinite(Number(longitude));
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
        detail.value = response.data.detail;
        programImageLoaded.value = false;
        programImageFailed.value = false;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship details.';
    } finally {
        isLoading.value = false;
    }
}

async function toggleSave() {
    if (!detail.value?.saved) {
        return;
    }

    isSaving.value = true;
    errorMessage.value = '';

    try {
        const response = detail.value.saved.is_saved
            ? await window.axios.delete(detail.value.saved.endpoint)
            : await window.axios.post(detail.value.saved.endpoint);

        const isSaved = Boolean(response.data.scholarship.is_saved);

        if (detail.value?.saved) {
            detail.value.saved.is_saved = isSaved;
            detail.value.saved.label = isSaved ? 'Remove from saved' : 'Save scholarship';
            detail.value.saved.method = isSaved ? 'DELETE' : 'POST';
        }

        if (['upcoming', 'full', 'closed'].includes(decision.value?.state)) {
            detail.value.decision.action = isSaved
                ? {
                    kind: 'link',
                    label: 'View saved scholarships',
                    href: '/dashboard/scholarships?view=saved',
                }
                : {
                    kind: 'save',
                    label: 'Save scholarship',
                };
        }
    } catch (handledError) {
        void handledError;
    } finally {
        isSaving.value = false;
    }
}

function handleDecisionAction() {
    const action = decision.value?.action;

    if (!action) {
        return;
    }

    if (action.kind === 'section') {
        selectDetailSection(action.target, true);
        return;
    }

    if (action.kind === 'save') {
        toggleSave();
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

                <div v-else-if="detail" class="mt-5 space-y-4">
                    <header class="student-card overflow-hidden rounded-md border-slate-300 border-t-4 border-t-amber-400 shadow-[0_8px_22px_rgba(15,23,42,0.07)]">
                        <div class="flex flex-col gap-4 bg-[linear-gradient(120deg,#ffffff_0%,#ffffff_72%,#f8fafc_100%)] p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div class="flex min-w-0 items-center gap-4">
                                <div class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-md border border-slate-200 bg-slate-100 text-lg text-slate-400">
                                    <i v-if="!programImageLoaded" class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                    <img
                                        v-if="!programImageFailed"
                                        :src="detail.identity.image_url"
                                        :alt="detail.identity.title"
                                        :class="['absolute inset-0 h-full w-full bg-white object-contain p-1.5 transition-opacity', programImageLoaded ? 'opacity-100' : 'opacity-0']"
                                        @load="programImageLoaded = true"
                                        @error="programImageFailed = true"
                                    >
                                </div>

                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Scholarship opportunity</p>
                                    <h1 class="mt-1 text-xl font-bold leading-tight text-slate-950 sm:text-2xl">
                                        {{ detail.identity.title }}
                                    </h1>
                                    <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-slate-500">
                                        <a
                                            v-if="detail.identity.provider.href"
                                            :href="detail.identity.provider.href"
                                            class="truncate transition hover:text-slate-950"
                                        >
                                            {{ detail.identity.provider.name || 'Scholarship provider' }}
                                        </a>
                                        <span v-else class="truncate">{{ detail.identity.provider.name || 'Scholarship provider' }}</span>
                                        <span v-if="detail.identity.category" class="text-slate-300" aria-hidden="true">|</span>
                                        <span v-if="detail.identity.category" class="text-xs">{{ detail.identity.category }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2 sm:justify-end">
                                <span :class="['rounded-md px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide', scholarshipStatus.classes]">
                                    {{ scholarshipStatus.label }}
                                </span>
                                <button
                                    type="button"
                                    :disabled="isSaving"
                                    class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60"
                                    :aria-label="detail.saved.label"
                                    :title="detail.saved.label"
                                    @click="toggleSave"
                                >
                                    <i :class="detail.saved.is_saved ? 'fa-solid fa-bookmark text-amber-700' : 'fa-regular fa-bookmark'" aria-hidden="true"></i>
                                    <span>{{ isSaving ? 'Saving' : detail.saved.is_saved ? 'Saved' : 'Save' }}</span>
                                </button>
                            </div>
                        </div>

                        <dl class="grid border-t border-slate-200 bg-slate-50 text-sm sm:grid-cols-2 lg:grid-cols-4" aria-label="Scholarship facts">
                            <div
                                v-for="fact in detailFacts"
                                :key="fact.key"
                                class="border-b border-slate-200 px-4 py-3 last:border-b-0 sm:[&:nth-child(odd)]:border-r sm:[&:nth-last-child(-n+2)]:border-b-0 sm:px-5 lg:border-b-0 lg:border-r lg:last:border-r-0"
                            >
                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">{{ fact.label }}</dt>
                                <dd class="mt-1 line-clamp-2 font-bold leading-5 text-slate-900">{{ fact.value }}</dd>
                            </div>
                        </dl>
                    </header>

                    <section :class="['rounded-md border border-l-4 p-4 shadow-sm sm:p-5', decisionTone(decision.tone).panel]" aria-labelledby="scholarship-next-action">
                        <div class="grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center">
                            <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-md', decisionTone(decision.tone).icon]">
                                <i :class="decisionIcon(decision.state)" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">{{ decision.eyebrow }}</p>
                                <h2 id="scholarship-next-action" class="mt-1 text-lg font-bold text-slate-950">{{ decision.title }}</h2>
                                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ decision.description }}</p>
                            </div>

                            <a
                                v-if="decision.action?.kind === 'link'"
                                :href="decision.action.href"
                                class="inline-flex min-h-10 w-full shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                            >
                                {{ decision.action.label }}
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </a>
                            <button
                                v-else-if="decision.action"
                                type="button"
                                :disabled="decision.action.kind === 'save' && isSaving"
                                class="inline-flex min-h-10 w-full shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                                @click="handleDecisionAction"
                            >
                                {{ decision.action.kind === 'save' && isSaving ? 'Saving...' : decision.action.label }}
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </button>
                        </div>
                    </section>

                    <nav id="scholarship-detail-navigation" class="overflow-x-auto rounded-md border border-slate-300 bg-white p-1 shadow-sm" aria-label="Scholarship details">
                        <div class="flex min-w-max gap-1 sm:min-w-0" role="tablist">
                            <button
                                v-for="section in detailSections"
                                :key="section.id"
                                type="button"
                                role="tab"
                                :aria-selected="activeDetailSection === section.id"
                                :class="[
                                    'flex min-h-10 items-center justify-center gap-2 rounded-sm px-3 py-2.5 text-sm font-bold transition sm:flex-1',
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
                                <header class="border-b border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                                    <div>
                                        <p class="student-kicker">Program overview</p>
                                        <h2 class="mt-1 text-lg font-bold text-slate-950">About this scholarship</h2>
                                        <p class="mt-1 text-sm leading-5 text-slate-500">Review the support, purpose, and key terms before applying.</p>
                                    </div>
                                </header>

                                <section class="p-4 sm:p-5">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">About the program</p>
                                    <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-700">
                                        {{ overview.description || 'The provider has not added a program description yet.' }}
                                    </p>
                                </section>

                                <section class="border-t border-slate-200 p-4 sm:p-5">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Support package</p>
                                            <h3 class="mt-1 font-bold text-slate-950">What recipients receive</h3>
                                        </div>
                                        <span class="shrink-0 text-xs font-bold text-slate-500">{{ overviewBenefits.length }} item{{ overviewBenefits.length === 1 ? '' : 's' }}</span>
                                    </div>

                                    <ul v-if="overviewBenefits.length" class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-sm border border-slate-200">
                                        <li v-for="benefit in overviewBenefits" :key="`${benefit.type}-${benefit.title}`" class="flex items-start gap-3 p-3.5 sm:p-4">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-950 text-xs text-amber-300">
                                                <i :class="benefitIcon(benefit.type)" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                                    <p class="text-sm font-bold text-slate-950">{{ benefit.title }}</p>
                                                    <p v-if="benefit.amount" class="shrink-0 text-sm font-bold text-amber-800">{{ formatAmount(benefit.amount) }}</p>
                                                </div>
                                                <p v-if="benefitDetails(benefit)" class="mt-1 text-xs font-semibold text-slate-500">{{ benefitDetails(benefit) }}</p>
                                                <p v-if="benefit.description" class="mt-1 line-clamp-2 text-sm leading-5 text-slate-600">{{ benefit.description }}</p>
                                            </div>
                                        </li>
                                    </ul>
                                    <p v-else class="mt-4 border-l-2 border-amber-300 pl-3 text-sm text-slate-600">The provider has not listed the support package yet.</p>
                                </section>

                                <section v-if="selectedProviderObjectives.length || overview.provider_objective_notes" class="border-t border-slate-200 p-4 sm:p-5">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Provider purpose</p>
                                    <h3 class="mt-1 font-bold text-slate-950">Why this scholarship is offered</h3>
                                    <ul v-if="selectedProviderObjectives.length" class="mt-3 flex flex-wrap gap-x-6 gap-y-2.5">
                                        <li v-for="objective in selectedProviderObjectives" :key="objective.value" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                            <i :class="[objective.icon, 'w-4 text-center text-amber-700']" aria-hidden="true"></i>
                                            {{ objective.label }}
                                        </li>
                                    </ul>
                                    <p v-if="overview.provider_objective_notes" class="mt-3 max-w-4xl border-l-2 border-amber-300 pl-3 text-sm leading-6 text-slate-700">{{ overview.provider_objective_notes }}</p>
                                </section>

                                <section class="border-t border-slate-200 bg-slate-50 p-4 sm:p-5">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Before you apply</p>
                                            <h3 class="mt-1 font-bold text-slate-950">Key recipient terms</h3>
                                        </div>
                                        <button
                                            v-if="overview.has_full_terms"
                                            type="button"
                                            class="inline-flex w-fit shrink-0 items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                            @click="showRecipientTermsModal = true"
                                        >
                                            Review full terms
                                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                        </button>
                                    </div>

                                    <dl v-if="overviewTerms.length" class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-sm border border-slate-200 bg-white">
                                        <div v-for="term in overviewTerms" :key="term.key" class="grid gap-1 px-3.5 py-3 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-5 sm:px-4">
                                            <dt class="text-xs font-bold text-slate-700">{{ term.label }}</dt>
                                            <dd class="line-clamp-2 text-sm leading-5 text-slate-600">{{ term.value }}</dd>
                                        </div>
                                    </dl>
                                    <p v-else class="mt-3 text-sm text-slate-600">No recipient commitment is listed for this program.</p>
                                </section>
                            </article>

                            <article v-if="activeDetailSection === 'eligibility'" id="eligibility" class="student-card scroll-mt-6 overflow-hidden rounded-md border-slate-300">
                                <header class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                                    <div>
                                        <p class="student-kicker">Eligibility</p>
                                        <h2 class="mt-1 text-lg font-bold text-slate-950">Your eligibility check</h2>
                                        <p class="mt-1 text-sm leading-5 text-slate-500">See how your saved profile compares with the published rules.</p>
                                    </div>
                                    <button v-if="eligibilityOverview.criteria?.length" type="button" class="inline-flex w-fit items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100" @click="showProfileCheckModal = true">
                                        View full comparison<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </button>
                                </header>

                                <section class="p-4 sm:p-5">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-sm', eligibilityVerdict.classes]"><i :class="eligibilityVerdict.icon" aria-hidden="true"></i></span>
                                        <div class="min-w-0">
                                            <h3 class="font-bold text-slate-950">{{ eligibilityVerdict.title }}</h3>
                                            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ eligibilityVerdict.description }}</p>
                                        </div>
                                    </div>
                                </section>

                                <section v-if="eligibilityProfileMissing.length" class="border-t border-slate-200 p-4 sm:p-5">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">Profile details needed</p>
                                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ eligibilityProfileMissing.map((item) => item.label).join(', ') }}</p>
                                        </div>
                                        <a href="/dashboard/profile" class="inline-flex w-fit shrink-0 items-center gap-2 rounded-sm bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                                            Complete profile<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </section>

                                <section v-else-if="eligibilityBlockers.length" class="border-t border-slate-200">
                                    <div v-for="blocker in eligibilityBlockers" :key="blocker.key" class="border-b border-slate-200 p-4 last:border-b-0 sm:p-5">
                                        <p class="text-sm font-bold text-slate-950">{{ blocker.label }}</p>
                                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                            <div><dt class="text-xs font-semibold text-slate-500">Your profile</dt><dd class="mt-1 font-semibold text-slate-900">{{ blocker.student_value || 'Not provided' }}</dd></div>
                                            <div><dt class="text-xs font-semibold text-slate-500">Program requires</dt><dd class="mt-1 font-semibold text-slate-900">{{ blocker.requirement || 'Different information' }}</dd></div>
                                        </dl>
                                    </div>
                                </section>

                                <section v-else-if="eligibilityMissingChecks.length" class="border-t border-slate-200 p-4 sm:p-5">
                                    <p class="text-sm font-bold text-slate-950">More profile information may be needed</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ eligibilityMissingChecks.map((item) => item.label).join(', ') }}</p>
                                </section>

                                <details v-if="eligibilityOverview.written_conditions || eligibilityProviderReview.length" class="group border-t border-slate-200 bg-slate-50">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4 sm:p-5">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">Other conditions the provider reviews</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">Open this only if you want the written conditions and manual checks.</p>
                                        </div>
                                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                    </summary>
                                    <div class="border-t border-slate-200 p-4 sm:p-5">
                                        <p v-if="eligibilityOverview.written_conditions" class="whitespace-pre-line text-sm font-semibold leading-6 text-slate-900">{{ eligibilityOverview.written_conditions }}</p>
                                        <EligibilityConditionList v-if="eligibilityProviderReview.length" :class="eligibilityOverview.written_conditions ? 'mt-4' : ''" :conditions="eligibilityProviderReview" />
                                    </div>
                                </details>

                                <footer class="border-t border-slate-200 px-4 py-3 text-xs leading-5 text-slate-500 sm:px-5">
                                    This check uses your saved profile. The provider makes the final decision.
                                </footer>
                            </article>

                            <article v-if="activeDetailSection === 'requirements'" id="documents" class="student-card scroll-mt-6 overflow-hidden rounded-md border-slate-300">
                                <header class="student-section-head border-b border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                                    <div>
                                        <p class="student-kicker">Documents</p>
                                        <h2 class="mt-1 text-lg font-bold text-slate-950">Files to prepare</h2>
                                        <p class="mt-1 text-sm leading-5 text-slate-500">Check what is ready and what still needs to be uploaded.</p>
                                    </div>
                                    <a :href="requirementsOverview.manage_files_href || '/dashboard/documents'" class="inline-flex w-fit items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                        Open My Documents<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </a>
                                </header>

                                <section class="p-4 sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-sm', requirementsStatusStyle.classes]">
                                            <i :class="requirementsStatusStyle.icon" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <h3 class="font-bold text-slate-950">{{ requirementsReadiness.title }}</h3>
                                            <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ requirementsReadiness.description }}</p>
                                        </div>
                                    </div>
                                </section>

                                <section v-if="requirementsReadiness.items?.length" class="border-t border-slate-200">
                                    <div
                                        v-for="item in requirementsReadiness.items"
                                        :key="item.name"
                                        class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3.5 last:border-b-0 sm:px-5"
                                    >
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span :class="[
                                                'grid h-8 w-8 shrink-0 place-items-center rounded-sm text-xs',
                                                item.status === 'ready' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500',
                                            ]">
                                                <i :class="item.status === 'ready' ? 'fa-solid fa-check' : 'fa-solid fa-file-arrow-up'" aria-hidden="true"></i>
                                            </span>
                                            <p class="text-sm font-bold text-slate-800">{{ item.name }}</p>
                                        </div>
                                        <span :class="['shrink-0 text-xs font-bold', item.status === 'ready' ? 'text-emerald-700' : 'text-slate-500']">
                                            {{ item.status_label }}
                                        </span>
                                    </div>
                                </section>

                                <details v-if="otherDocumentCount" class="group border-t border-slate-200 bg-slate-50">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4 sm:p-5">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">Other files to know about</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ otherDocumentCount }} optional or later-stage item{{ otherDocumentCount === 1 ? '' : 's' }}</p>
                                        </div>
                                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                    </summary>
                                    <div class="border-t border-slate-200 bg-white">
                                        <section v-if="optionalDocumentItems.length" class="p-4 sm:p-5">
                                            <p class="text-sm font-bold text-slate-950">Optional with your application</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">These may support your application but are not required.</p>
                                            <ul class="mt-3 divide-y divide-slate-200 border-y border-slate-200">
                                                <li v-for="item in optionalDocumentItems" :key="item" class="flex items-start gap-2 py-2.5 text-sm text-slate-700">
                                                    <i class="fa-regular fa-file mt-1 text-xs text-slate-400" aria-hidden="true"></i><span>{{ item }}</span>
                                                </li>
                                            </ul>
                                        </section>
                                        <section v-if="postQualificationDocumentItems.length" :class="['p-4 sm:p-5', optionalDocumentItems.length ? 'border-t border-slate-200' : '']">
                                            <p class="text-sm font-bold text-slate-950">Only after you qualify</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">Do not prepare these yet unless the provider asks.</p>
                                            <ul class="mt-3 divide-y divide-slate-200 border-y border-slate-200">
                                                <li v-for="item in postQualificationDocumentItems" :key="item" class="flex items-start gap-2 py-2.5 text-sm text-slate-700">
                                                    <i class="fa-regular fa-file mt-1 text-xs text-slate-400" aria-hidden="true"></i><span>{{ item }}</span>
                                                </li>
                                            </ul>
                                        </section>
                                    </div>
                                </details>

                                <footer class="flex items-start gap-2 border-t border-slate-200 px-4 py-3 text-xs leading-5 text-slate-500 sm:px-5">
                                    <i class="fa-solid fa-shield-halved mt-1 text-amber-700" aria-hidden="true"></i>
                                    <p><span class="font-bold text-slate-700">{{ requirementsOverview.review_method_label }}.</span> {{ requirementsOverview.review_method_note }}</p>
                                </footer>
                            </article>

                            <article v-if="activeDetailSection === 'process'" class="student-card overflow-hidden rounded-md border-slate-300">
                                <header class="student-section-head border-b border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                                    <div>
                                        <p class="student-kicker">Selection process</p>
                                        <h2 class="mt-1 text-lg font-bold text-slate-950">What happens after you apply</h2>
                                        <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-500">{{ processOverview.intro }}</p>
                                    </div>
                                    <span class="w-fit rounded-sm bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                        {{ selectionPlan.length }} steps
                                    </span>
                                </header>

                                <ol class="divide-y divide-slate-200 bg-white">
                                    <li
                                        v-for="stage in selectionPlan"
                                        :key="stage.key"
                                        class="grid grid-cols-[2.5rem_minmax(0,1fr)] gap-3 p-4 sm:grid-cols-[2.5rem_minmax(0,1fr)_minmax(10rem,auto)] sm:items-center sm:p-5"
                                    >
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-950 text-xs font-bold text-white">
                                            {{ stage.step }}
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <i :class="[stage.icon, 'text-xs text-amber-700']" aria-hidden="true"></i>
                                                <p class="text-sm font-bold text-slate-950">{{ stage.label }}</p>
                                            </div>
                                            <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">{{ stage.description }}</p>
                                        </div>
                                        <div class="col-start-2 sm:col-start-auto sm:text-right">
                                            <p :class="['text-xs font-bold', stage.timing?.state === 'scheduled' ? 'text-amber-800' : 'text-slate-700']">{{ stage.timing?.label }}</p>
                                            <p v-if="stage.timing?.note" class="mt-1 text-xs leading-5 text-slate-500">{{ stage.timing.note }}</p>
                                        </div>
                                    </li>
                                </ol>
                            </article>
                        </section>

                        <section v-if="activeDetailSection === 'provider'" class="student-card overflow-hidden rounded-md border-slate-300">
                            <header class="student-section-head border-b border-slate-200 bg-slate-50/70 p-4 sm:p-5">
                                <div>
                                    <p class="student-kicker">Scholarship provider</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">Who manages this program</h2>
                                    <p class="mt-1 text-sm leading-5 text-slate-500">View the organization and its public contact details.</p>
                                </div>
                                <a
                                    v-if="providerOverview.profile_href"
                                    :href="providerOverview.profile_href"
                                    class="inline-flex w-fit items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    View provider<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                </a>
                            </header>

                            <section class="p-4 sm:p-5">
                                <div class="flex items-center gap-3">
                                    <img
                                        :src="providerOverview.logo_url || detail.identity.image_url"
                                        :alt="providerOverview.name || 'Scholarship provider'"
                                        class="h-14 w-14 shrink-0 rounded-sm bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Organization</p>
                                        <h3 class="mt-1 text-base font-bold leading-5 text-slate-950">{{ providerOverview.name || 'Scholarship provider' }}</h3>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">
                                            {{ providerOverview.type_label }}<span v-if="providerOverview.year_established"> | Established {{ providerOverview.year_established }}</span>
                                        </p>
                                    </div>
                                </div>

                                <p v-if="providerOverview.summary" class="mt-4 max-w-4xl text-sm leading-6 text-slate-600">
                                    {{ providerOverview.summary }}
                                </p>
                                <p v-if="providerOverview.service_area" class="mt-3 inline-flex items-start gap-2 text-xs font-semibold leading-5 text-slate-600">
                                    <i class="fa-solid fa-map mt-1 text-amber-700" aria-hidden="true"></i>Serves {{ providerOverview.service_area }}
                                </p>
                            </section>

                            <dl class="divide-y divide-slate-200 border-t border-slate-200">
                                <div class="grid gap-3 p-4 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-5 sm:p-5">
                                    <dt class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-address-card text-amber-700" aria-hidden="true"></i>Public contact</dt>
                                    <dd v-if="providerOverview.contact?.available" class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-600">
                                        <span v-if="providerOverview.contact.department || providerOverview.contact.person" class="font-semibold text-slate-800">{{ providerOverview.contact.department || providerOverview.contact.person }}<span v-if="providerOverview.contact.department && providerOverview.contact.person" class="font-normal text-slate-500"> | {{ providerOverview.contact.person }}</span></span>
                                        <a v-if="providerOverview.contact.email" :href="`mailto:${providerOverview.contact.email}`" class="inline-flex min-w-0 items-center gap-2 hover:text-slate-950"><i class="fa-regular fa-envelope text-slate-400" aria-hidden="true"></i><span class="break-all">{{ providerOverview.contact.email }}</span></a>
                                        <a v-if="providerOverview.contact.number" :href="`tel:${providerOverview.contact.number}`" class="inline-flex items-center gap-2 hover:text-slate-950"><i class="fa-solid fa-phone text-slate-400" aria-hidden="true"></i>{{ providerOverview.contact.number }}</a>
                                        <span v-if="providerOverview.contact.office_hours" class="inline-flex items-center gap-2"><i class="fa-regular fa-clock text-slate-400" aria-hidden="true"></i>{{ providerOverview.contact.office_hours }}</span>
                                    </dd>
                                    <dd v-else class="text-sm text-slate-500">No public contact details listed.</dd>
                                </div>

                                <div class="grid gap-3 p-4 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-5 sm:p-5">
                                    <dt class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-location-dot text-amber-700" aria-hidden="true"></i>Program location</dt>
                                    <dd class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div><p class="text-sm font-bold text-slate-800">{{ providerLocation.name || 'Location not named' }}</p><p class="mt-1 text-sm leading-5 text-slate-600">{{ providerLocation.address || 'No map address added yet.' }}</p><p v-if="providerLocation.distance_label" class="mt-1 text-xs font-bold text-slate-600">About {{ providerLocation.distance_label }} from your saved location</p></div>
                                        <button v-if="hasMapPreview" type="button" class="inline-flex w-fit shrink-0 items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100" @click="showMapModal = true"><i class="fa-solid fa-map-location-dot text-amber-700" aria-hidden="true"></i>View map</button>
                                    </dd>
                                </div>

                                <div v-if="providerOverview.official_program_url || providerOverview.website" class="grid gap-3 p-4 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-5 sm:p-5">
                                    <dt class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-link text-amber-700" aria-hidden="true"></i>Official links</dt>
                                    <dd class="flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold text-slate-700">
                                        <a v-if="providerOverview.official_program_url" :href="providerOverview.official_program_url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 hover:text-slate-950">Program page<i class="fa-solid fa-arrow-up-right-from-square text-xs text-slate-400" aria-hidden="true"></i></a>
                                        <a v-if="providerOverview.website" :href="providerOverview.website" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 hover:text-slate-950">Provider website<i class="fa-solid fa-arrow-up-right-from-square text-xs text-slate-400" aria-hidden="true"></i></a>
                                    </dd>
                                </div>
                            </dl>

                            <p class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-500 sm:px-6">
                                {{ providerOverview.decision_note }}
                            </p>
                        </section>
                    </div>
                </div>

            </div>
        </section>

        <Teleport to="body">
            <div v-if="showRecipientTermsModal && detail" class="fixed inset-0 z-[2500] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5" @click.self="showRecipientTermsModal = false">
                <section class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="recipient-terms-modal-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-file-signature" aria-hidden="true"></i></span>
                            <div><p class="student-kicker">Recipient terms</p><h2 id="recipient-terms-modal-title" class="mt-1 text-xl font-bold text-slate-950">Support and responsibilities</h2></div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-sm border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-slate-950" aria-label="Close recipient terms" @click="showRecipientTermsModal = false"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </header>
                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-3 sm:p-5">
                        <RecipientAgreementPanel :scholarship="agreementScholarship" hide-header />
                    </div>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showProfileCheckModal && eligibilityOverview.criteria?.length"
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
                                v-for="criterion in eligibilityOverview.criteria"
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
            v-if="detail"
            :open="showMapModal"
            eyebrow="Program location"
            :title="providerLocation.name || detail.identity.title"
            :address="scholarshipMapAddress"
            :latitude="providerLocation.latitude"
            :longitude="providerLocation.longitude"
            :secondary-latitude="user?.latitude"
            :secondary-longitude="user?.longitude"
            :secondary-marker-text="userLocationLabel"
            :distance-label="providerLocation.distance_label ? `About ${providerLocation.distance_label}` : ''"
            :marker-text="providerLocation.name || detail.identity.title"
            :note="hasUserMapLocation && providerLocation.distance_label ? `Your saved location is shown too: ${providerLocation.distance_label} from this program.` : 'Add your profile map pin to compare distance with this program.'"
            @close="showMapModal = false"
        />
    </main>
</template>
