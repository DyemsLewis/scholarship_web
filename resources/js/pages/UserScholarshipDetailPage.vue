<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import ProviderApplicantPreviewToolbar from '../components/ProviderApplicantPreviewToolbar.vue';
import ScholarshipEligibilityPanel from '../components/ScholarshipEligibilityPanel.vue';
import ScholarshipApplicationGuide from '../components/ScholarshipApplicationGuide.vue';
import ScholarshipApplicationRequirements from '../components/ScholarshipApplicationRequirements.vue';
import ScholarshipFormalApplicationSchedule from '../components/ScholarshipFormalApplicationSchedule.vue';
import ScholarshipAwardDetails from '../components/ScholarshipAwardDetails.vue';
import ScholarshipDetailAction from '../components/ScholarshipDetailAction.vue';
import { eligibilityOverview } from '../support/applicantEligibility';
import { scholarshipBenefitFacts } from '../support/scholarshipBenefits';

const props = defineProps({
    embedded: { type: Boolean, default: false },
    scholarshipId: { type: [String, Number], default: '' },
    initialView: { type: String, default: 'summary' },
    previewDataUrl: { type: String, default: '' },
    previewStatus: { type: String, default: '' },
    initialScenario: { type: String, default: 'general' },
});

const appRoot = document.getElementById('app');
const scholarshipId = props.scholarshipId || appRoot?.dataset.scholarshipId;
const requestedView = props.embedded ? props.initialView : (appRoot?.dataset.scholarshipSection || 'summary');
const isPreview = props.embedded || appRoot?.dataset.providerPreview === 'true';
const previewDataUrl = props.previewDataUrl || appRoot?.dataset.previewDataUrl || '';
const previewBackUrl = appRoot?.dataset.previewBackUrl || '/provider/programs';
const previewStatus = props.previewStatus || appRoot?.dataset.previewStatus || 'Preview';
const previewScenario = ref(props.embedded
    ? props.initialScenario
    : (new URLSearchParams(window.location.search).get('scenario') || 'general'));
const currentView = ref(requestedView);

const user = ref(null);
const detail = ref(null);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const showMapModal = ref(false);
const imageFailed = ref(false);
const providerImageFailed = ref(false);
const sectionNavigation = ref(null);
const sectionContent = ref(null);
const sectionAnchor = '#scholarship-section';

const identity = computed(() => detail.value?.identity ?? {});
const decision = computed(() => detail.value?.decision ?? {});
const content = computed(() => detail.value?.content ?? {});
const snapshot = computed(() => detail.value?.snapshot ?? []);
const navigation = computed(() => detail.value?.navigation ?? []);
const activeView = computed(() => detail.value?.view ?? currentView.value);
const isOverview = computed(() => activeView.value === 'summary');
const isApplicationView = computed(() => activeView.value === 'application');
const sectionLabel = computed(() => navigation.value.find((item) => item.active)?.label);
const applicationGuideHref = computed(() => navigation.value.find((item) => item.key === 'application')?.href);
const actionProps = computed(() => ({
    decision: decision.value,
    preparation: detail.value?.preparation,
    scholarshipId,
    section: activeView.value,
    preview: isPreview,
    saving: isSaving.value,
    saved: Boolean(detail.value?.saved?.is_saved),
}));
const requirements = computed(() => content.value?.requirements ?? {});
const process = computed(() => content.value?.process ?? {});
const provider = computed(() => activeView.value === 'provider' ? content.value : {});
const providerLocation = computed(() => provider.value?.location ?? {});
const scholarshipImage = computed(() => identity.value?.logo_url || '/uploads/scholarship-default.jpg');
const scholarshipVisual = computed(() => {
    const subject = `${identity.value?.title ?? ''} ${identity.value?.category ?? ''}`.toLowerCase();
    const chosenCover = identity.value?.cover_image_url || identity.value?.image_url;

    if (/(stem|science|technology|engineering)/.test(subject)) {
        return {
            hero: chosenCover || '/images/filipino-students-science-class.jpg',
            heroAlt: chosenCover ? `${identity.value?.title} background` : 'Filipino students taking part in a science class',
            overview: chosenCover || '/images/filipino-students-science-experiment.jpg',
            overviewAlt: chosenCover ? `${identity.value?.title} background` : 'Filipino students learning through a classroom science activity',
        };
    }

    if (/(college|university|tertiary|graduate)/.test(subject)) {
        return {
            hero: chosenCover || '/images/filipino-graduates.jpg',
            heroAlt: chosenCover ? `${identity.value?.title} background` : 'Filipino graduates celebrating their education milestone',
            overview: chosenCover || '/images/filipino-campus.jpg',
            overviewAlt: chosenCover ? `${identity.value?.title} background` : 'Students walking through a campus in the Philippines',
        };
    }

    return {
        hero: chosenCover || '/images/filipino-students-studying.jpg',
        heroAlt: chosenCover ? `${identity.value?.title} background` : 'Filipino students studying together in a classroom',
        overview: chosenCover || '/images/filipino-students-group-study.jpg',
        overviewAlt: chosenCover ? `${identity.value?.title} background` : 'Filipino students working together during class',
    };
});
const hasDistinctOverviewImage = computed(() => scholarshipVisual.value.overview !== scholarshipVisual.value.hero);
const sectionVisual = computed(() => ({
    summary: {
        image: scholarshipVisual.value.overview,
        alt: scholarshipVisual.value.overviewAlt,
        caption: 'Support built around a learner\'s education.',
    },
    application: {
        image: '/images/application-documents.jpg',
        alt: 'A student preparing scholarship application documents',
        caption: 'Prepare once, then follow each stage.',
    },
    provider: {
        image: provider.value?.cover_url || '/images/filipino-campus.jpg',
        alt: provider.value?.cover_url ? `${provider.value?.name || 'Scholarship provider'} background` : 'Students on a campus in the Philippines',
        caption: 'Learn who will manage your application.',
    },
}[activeView.value]));

const eligibilityVerdict = computed(() => {
    const profile = content.value?.profile ?? {};

    if (!profile.complete) {
        return {
            title: 'Complete your profile for an accurate result',
            description: `${profile.missing?.length ?? 0} required profile detail(s) still need attention.`,
        };
    }

    if (!profile.verified) {
        const profileStep = detail.value?.preparation?.steps?.find((step) => step.key === 'profile');
        return {
            title: 'Verify your profile before applying',
            description: profileStep?.description || 'Upload the required supporting evidence and wait for profile verification before applying.',
        };
    }

    const preferences = content.value.preferred_mismatches?.length ?? 0;
    if (!isPreview && decision.value.can_apply === true && preferences && !content.value.review_criteria?.length && !content.value.missing_checks?.length) {
        return {
            title: preferences === 1 ? 'You can apply. One preference differs.' : `You can apply. ${preferences} preferences differ.`,
            description: 'The provider still reviews your eligibility and evidence before selecting recipients.',
        };
    }

    return eligibilityOverview({ ...content.value, blocking_criteria: content.value?.blockers });
});

const scholarshipMapAddress = computed(() => {
    const parts = [providerLocation.value.address, providerLocation.value.name].filter(Boolean);
    return parts.length ? [...new Set(parts), 'Philippines'].join(', ') : '';
});

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

const hasUserMapLocation = computed(() => hasCoordinates(user.value?.latitude, user.value?.longitude));

function stateStyle(state) {
    return {
        ready: {
            icon: 'bg-emerald-100 text-emerald-800',
            badge: 'student-badge-positive',
        },
        positive: {
            icon: 'bg-emerald-100 text-emerald-800',
            badge: 'student-badge-positive',
        },
        attention: {
            icon: 'bg-amber-100 text-amber-900',
            badge: 'student-badge-attention',
        },
        blocked: {
            icon: 'bg-rose-100 text-rose-800',
            badge: 'student-badge-negative',
        },
        muted: {
            icon: 'bg-slate-200 text-slate-700',
            badge: '',
        },
        neutral: {
            icon: 'bg-slate-100 text-slate-700',
            badge: '',
        },
    }[state] ?? {
        icon: 'bg-slate-100 text-slate-700',
        badge: '',
    };
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
    const amount = benefit.amount !== null && benefit.amount !== undefined && benefit.amount !== ''
        ? new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 0,
        }).format(Number(benefit.amount))
        : null;

    return [amount || benefit.coverage_label, benefit.frequency_label, benefit.duration]
        .filter(Boolean)
        .join(' | ');
}

function displayValue(value, fallback = 'Not specified') {
    if (Array.isArray(value)) {
        const values = value.map((item) => displayValue(item, '')).filter(Boolean);
        return values.length ? values.join(', ') : fallback;
    }

    const text = String(value ?? '').trim();
    if (!text) {
        return fallback;
    }

    return text.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function hasCoordinates(latitude, longitude) {
    if (latitude === null || latitude === undefined || latitude === '' || longitude === null || longitude === undefined || longitude === '') {
        return false;
    }

    return Number.isFinite(Number(latitude)) && Number.isFinite(Number(longitude));
}

async function loadScholarship() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(
            isPreview ? previewDataUrl : `/dashboard/scholarships/${scholarshipId}/data`,
            {
                params: {
                    section: currentView.value,
                    ...(isPreview ? { scenario: previewScenario.value } : {}),
                },
            },
        );

        user.value = response.data.user;
        detail.value = response.data.detail;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship details.';
    } finally {
        isLoading.value = false;
    }
}

async function toggleSave() {
    if (isPreview || !detail.value?.saved || isSaving.value) {
        return;
    }

    isSaving.value = true;
    errorMessage.value = '';

    try {
        const response = detail.value.saved.is_saved
            ? await window.axios.delete(detail.value.saved.endpoint)
            : await window.axios.post(detail.value.saved.endpoint);
        const isSaved = Boolean(response.data.scholarship.is_saved);

        detail.value.saved.is_saved = isSaved;
        detail.value.saved.label = isSaved ? 'Remove from saved' : 'Save scholarship';
        detail.value.saved.method = isSaved ? 'DELETE' : 'POST';

        if (['upcoming', 'full', 'closed'].includes(decision.value.state)) {
            detail.value.decision.action = isSaved
                ? { kind: 'link', label: 'View saved scholarships', href: '/dashboard/scholarships?view=saved' }
                : { kind: 'save', label: 'Save scholarship' };
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update your saved scholarships.';
    } finally {
        isSaving.value = false;
    }
}

function changePreviewScenario(scenario) {
    previewScenario.value = scenario;
    if (!props.embedded) {
        const url = new URL(window.location.href);
        url.searchParams.set('scenario', scenario);
        window.history.replaceState({}, '', url);
    }
    loadScholarship();
}

function openPreviewSection(event, section) {
    if (!props.embedded) return;

    event.preventDefault();
    if (section === currentView.value) return;

    currentView.value = section;
    loadScholarship();
}

function blockPreviewAction(event) {
    if (isPreview) event.preventDefault();
}

onMounted(async () => {
    await loadScholarship();
    if (props.embedded || !detail.value) return;

    const navigationType = window.performance.getEntriesByType('navigation')[0]?.type;
    if (navigationType === 'back_forward' || navigationType === 'reload') return;
    if (isOverview.value && window.location.hash !== sectionAnchor) return;

    await nextTick();
    sectionNavigation.value?.scrollIntoView({ block: 'start' });
    sectionContent.value?.focus({ preventScroll: true });
});
</script>

<template>
    <main :class="props.embedded ? 'min-h-full bg-slate-100' : isPreview ? 'min-h-screen bg-slate-200' : 'student-shell'" :data-embedded-preview="props.embedded || undefined">
        <ApplicantSidebar v-if="!isPreview" />

        <ProviderApplicantPreviewToolbar
            v-if="isPreview && !props.embedded"
            :back-url="previewBackUrl"
            :status="previewStatus"
            :scenario="previewScenario"
            show-scenario
            @update:scenario="changePreviewScenario"
        />

        <section :class="props.embedded ? 'min-h-full px-4 py-5 sm:px-6' : 'student-page'">
            <div :class="props.embedded ? 'mx-auto max-w-6xl pb-8' : 'student-container pb-10'">
                <a v-if="!isPreview" href="/dashboard/scholarships" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to scholarships
                </a>

                <div v-if="isLoading" class="mt-5 border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading scholarship details...
                </div>

                <div v-else-if="errorMessage && !detail" class="mt-5 border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-800 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="detail" class="mt-5 space-y-4">
                    <header class="student-card overflow-hidden rounded-md border-slate-300 border-t-4 border-t-amber-400" data-scholarship-detail-header>
                        <div :class="['grid', isOverview ? 'lg:grid-cols-[minmax(0,1fr)_minmax(14rem,29%)]' : '']">
                            <div class="flex min-w-0 items-start gap-3 p-4 sm:gap-4 sm:px-6 sm:py-5 lg:items-center">
                                <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-sm border border-slate-200 bg-white p-1.5 sm:size-14 sm:p-2">
                                    <img v-if="!imageFailed" :src="scholarshipImage" data-scholarship-logo :alt="`${identity.title} program logo`" class="size-full object-contain" @error="imageFailed = true">
                                    <i v-else class="fa-solid fa-graduation-cap text-lg text-slate-500" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="student-kicker">Scholarship program</p>
                                    <h1 :class="['mt-1 break-words font-display text-xl font-bold leading-tight text-slate-950 sm:text-2xl', isOverview && 'lg:text-3xl']">{{ identity.title }}</h1>
                                    <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-slate-600">
                                        <a v-if="identity.provider?.href" :href="identity.provider.href" class="break-words font-bold text-slate-800 hover:underline" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">{{ identity.provider.name }}</a>
                                        <span v-else class="break-words font-bold text-slate-800">{{ identity.provider?.name }}</span>
                                        <span v-if="identity.provider?.verified" class="student-badge student-badge-positive"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Verified provider</span>
                                    </div>
                                    <p v-if="isOverview && (identity.category || identity.program_cycle)" class="mt-2 text-xs font-semibold text-slate-500">
                                        {{ [identity.category, identity.program_cycle].filter(Boolean).join(' | ') }}
                                    </p>
                                </div>
                            </div>

                            <figure v-if="isOverview" class="relative hidden min-h-40 overflow-hidden border-l border-slate-200 bg-slate-100 lg:block" data-scholarship-cover>
                                <img :src="scholarshipVisual.hero" :alt="scholarshipVisual.heroAlt" class="absolute inset-0 h-full w-full object-cover">
                            </figure>
                        </div>

                        <dl v-if="isOverview && snapshot.length" class="grid grid-cols-2 border-t border-slate-200 bg-slate-50/70 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]" data-scholarship-key-facts>
                            <div v-for="fact in snapshot" :key="fact.key" :class="['min-w-0 border-slate-200 px-4 py-2.5 sm:px-6 sm:py-3', fact.key === 'benefit' ? 'col-span-2 border-b sm:col-span-1 sm:border-r sm:border-b-0' : fact.key === 'deadline' && 'border-r']">
                                <dt class="student-detail-label">{{ fact.label }}</dt>
                                <dd class="mt-0.5 break-words text-sm font-bold leading-5 text-slate-900">{{ fact.value }}</dd>
                                <dd v-if="fact.note" class="mt-0.5 text-xs leading-5 text-slate-600">{{ fact.note }}</dd>
                            </div>
                        </dl>
                    </header>

                    <ScholarshipDetailAction v-if="!isApplicationView" v-bind="actionProps" @save="toggleSave" />

                    <nav id="scholarship-section" ref="sectionNavigation" class="student-card scroll-mt-4 rounded-md border-slate-300 p-1.5" aria-label="Scholarship details" data-scholarship-detail-navigation>
                        <div class="grid grid-cols-6 gap-1 md:grid-cols-5">
                            <a
                                v-for="(item, index) in navigation"
                                :key="item.key"
                                :href="props.embedded ? item.href : `${item.href}${sectionAnchor}`"
                                :aria-current="item.active ? 'page' : undefined"
                                :class="[
                                    'flex min-h-11 min-w-0 items-center justify-center rounded-sm px-1.5 py-2 text-center text-xs font-bold leading-tight transition focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-amber-600 sm:text-sm md:col-span-1',
                                    index < 3 ? 'col-span-2' : 'col-span-3',
                                    item.active ? 'bg-slate-950 text-white shadow-sm' : 'bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                                ]"
                                @click="openPreviewSection($event, item.key)"
                            >
                                {{ item.label }}
                            </a>
                        </div>
                    </nav>

                    <p v-if="errorMessage" class="border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
                        {{ errorMessage }}
                    </p>

                    <div ref="sectionContent" class="space-y-4 focus:outline-none" role="region" :aria-label="sectionLabel" tabindex="-1" data-scholarship-section-content>
                    <template v-if="isOverview">
                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-overview>
                            <div :class="['grid', hasDistinctOverviewImage && 'lg:grid-cols-[minmax(0,1fr)_24rem]']">
                                <div class="flex flex-col px-5 py-6 sm:px-7 sm:py-7">
                                    <p class="student-kicker">Program overview</p>
                                    <h3 class="mt-1 text-2xl font-bold text-slate-950">What this scholarship provides</h3>
                                    <p class="mt-4 max-w-3xl whitespace-pre-line text-sm leading-7 text-slate-600">{{ content.description || 'The provider has not added a program description.' }}</p>

                                    <div v-if="content.primary_benefit" class="mt-5 border-l-4 border-amber-400 pl-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Primary benefit</p>
                                            <span v-if="content.additional_benefit_count" class="text-xs font-bold text-amber-900">+{{ content.additional_benefit_count }} more</span>
                                        </div>
                                        <h4 class="mt-1 text-lg font-bold text-slate-950">{{ content.primary_benefit.title }}</h4>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ scholarshipBenefitFacts(content.primary_benefit).map((fact) => fact.value).join(' / ') || content.primary_benefit.display_summary }}</p>
                                    </div>

                                    <div class="mt-5 flex flex-wrap items-center gap-x-5">
                                        <a :href="navigation.find((item) => item.key === 'award')?.href" class="inline-flex min-h-11 items-center gap-2 text-sm font-bold text-slate-800 hover:text-slate-950 hover:underline" @click="openPreviewSection($event, 'award')">
                                            See benefits and terms <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                        </a>
                                        <a v-if="applicationGuideHref && !decision.has_applied" :href="applicationGuideHref" class="inline-flex min-h-11 items-center gap-2 text-sm font-bold text-slate-800 hover:text-slate-950 hover:underline" @click="openPreviewSection($event, 'application')">
                                            See how to apply <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>

                                <figure :class="['relative min-h-56 overflow-hidden bg-slate-900', hasDistinctOverviewImage ? 'lg:min-h-full' : 'lg:hidden']">
                                    <img :src="sectionVisual.image" :alt="sectionVisual.alt" class="absolute inset-0 h-full w-full object-cover">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/75 via-transparent to-transparent"></div>
                                    <figcaption class="absolute inset-x-0 bottom-0 p-5 text-sm font-bold text-white">{{ sectionVisual.caption }}</figcaption>
                                </figure>
                            </div>
                        </section>

                    </template>

                    <ScholarshipEligibilityPanel
                        v-else-if="activeView === 'eligibility'"
                        :content="content"
                        :verdict="eligibilityVerdict"
                        :preview="isPreview"
                        :preview-scenario="content.preview_scenario || previewScenario"
                        :application-href="navigation.find((item) => item.key === 'application')?.href || ''"
                        :scholarship-id="scholarshipId"
                        @navigate="openPreviewSection"
                    />

                    <template v-else-if="activeView === 'award'">
                        <ScholarshipAwardDetails :content="content" />

                        <details v-if="content.provider_objectives?.length || content.provider_objective_notes" class="group student-card overflow-hidden rounded-md border-slate-300">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-4 sm:px-5 [&::-webkit-details-marker]:hidden">
                                <div>
                                    <p class="student-kicker">Program purpose</p>
                                    <h3 class="mt-1 text-base font-bold text-slate-950">Why the provider offers this support</h3>
                                </div>
                                <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                            </summary>
                            <div class="border-t border-slate-200 px-4 py-4 sm:px-5">
                                <ul v-if="content.provider_objectives?.length" class="grid gap-2">
                                    <li v-for="objective in content.provider_objectives" :key="objective" class="flex items-start gap-2 text-sm leading-6 text-slate-700">
                                        <i class="fa-solid fa-check mt-1.5 text-xs text-amber-700" aria-hidden="true"></i>
                                        {{ displayValue(objective) }}
                                    </li>
                                </ul>
                                <p v-if="content.provider_objective_notes" class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-600">{{ content.provider_objective_notes }}</p>
                            </div>
                        </details>
                    </template>

                    <template v-else-if="isApplicationView">
                        <div class="grid grid-cols-1 items-start gap-4" data-scholarship-how-to-apply>
                            <ScholarshipApplicationGuide
                                :preparation="detail.preparation"
                                :preview="isPreview"
                                :scholarship-id="scholarshipId"
                                @navigate="openPreviewSection"
                            >
                                <template #action>
                                    <ScholarshipDetailAction v-bind="actionProps" @save="toggleSave" />
                                </template>
                            </ScholarshipApplicationGuide>
                            <ScholarshipApplicationRequirements
                                :requirements="requirements"
                                :preparation="detail.preparation"
                                :scholarship-id="scholarshipId"
                                :preview="isPreview"
                            />
                        </div>

                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-application-process>
                            <header class="grid border-b border-slate-200 bg-slate-50/70 lg:grid-cols-[minmax(0,1fr)_22rem]">
                                <div class="px-5 py-6 sm:px-7 sm:py-7">
                                    <p class="student-kicker">Application process</p>
                                    <h3 class="mt-1 text-2xl font-bold text-slate-950">What happens after you apply</h3>
                                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ process.intro }}</p>
                                </div>
                                <figure class="relative h-44 overflow-hidden bg-slate-900 lg:h-auto">
                                    <img :src="sectionVisual.image" :alt="sectionVisual.alt" class="absolute inset-0 h-full w-full object-cover">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-transparent to-transparent"></div>
                                    <figcaption class="absolute inset-x-0 bottom-0 p-4 text-sm font-bold text-white">{{ sectionVisual.caption }}</figcaption>
                                </figure>
                            </header>
                            <p v-if="process.schedule_note" class="border-b border-slate-200 bg-slate-50/70 px-5 py-3 text-xs leading-5 text-slate-600 sm:px-6" data-program-schedule-note>{{ process.schedule_note }}</p>
                            <ol class="divide-y divide-slate-200">
                                <li v-for="stage in process.stages" :key="stage.key" :class="['grid gap-3 px-4 py-4 sm:gap-4 sm:px-5', stage.key === 'formal_application' && process.formal_application ? 'sm:grid-cols-[2.5rem_minmax(0,1fr)] sm:items-start' : 'sm:grid-cols-[2.5rem_minmax(0,1fr)_minmax(11rem,auto)] sm:items-center']">
                                    <span class="grid h-9 w-9 place-items-center rounded-md bg-slate-950 text-sm font-bold text-amber-300">{{ stage.step }}</span>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <i :class="[stage.icon, 'w-4 text-center text-amber-700']" aria-hidden="true"></i>
                                            <h4 class="text-base font-bold text-slate-950">{{ stage.label }}</h4>
                                        </div>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ stage.description }}</p>
                                        <ScholarshipFormalApplicationSchedule v-if="stage.key === 'formal_application' && process.formal_application" :schedule="process.formal_application" :preview="isPreview" />
                                    </div>
                                    <div v-if="stage.key !== 'formal_application' || !process.formal_application" class="sm:text-right">
                                        <p class="text-sm font-bold text-slate-900">{{ stage.timing?.label }}</p>
                                        <p v-if="stage.timing?.note" class="mt-1 text-xs font-semibold text-slate-500">{{ stage.timing.note }}</p>
                                    </div>
                                </li>
                            </ol>
                        </section>
                    </template>

                    <template v-else-if="activeView === 'provider'">
                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-provider>
                            <header class="grid border-b border-slate-200 bg-slate-50/70 lg:grid-cols-[minmax(0,1fr)_24rem]">
                                <div class="flex flex-col px-5 py-6 sm:px-7 sm:py-7">
                                    <div class="flex min-w-0 items-start gap-4">
                                        <span class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-md border border-slate-200 bg-white p-2.5">
                                            <img v-if="provider.logo_url && !providerImageFailed" :src="provider.logo_url" :alt="provider.name" class="h-full w-full object-contain" @error="providerImageFailed = true">
                                            <i v-else class="fa-solid fa-building-columns text-lg text-slate-400" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="student-kicker">Scholarship provider</p>
                                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                                <h3 class="text-2xl font-bold text-slate-950">{{ provider.name }}</h3>
                                                <span :class="['student-badge', stateStyle(provider.verified ? 'ready' : 'attention').badge]">
                                                    <i :class="provider.verified ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-info'" aria-hidden="true"></i>
                                                    {{ provider.verification_label }}
                                                </span>
                                            </div>
                                            <p class="mt-1 text-sm font-semibold text-slate-500">{{ provider.type_label }}</p>
                                        </div>
                                    </div>
                                    <p v-if="provider.summary" class="mt-4 max-w-3xl whitespace-pre-line text-sm leading-7 text-slate-600">{{ provider.summary }}</p>
                                    <a v-if="provider.profile_href" :href="provider.profile_href" class="student-button student-button-secondary mt-5 w-fit" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">
                                        Provider page
                                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </a>
                                </div>

                                <figure class="relative h-52 overflow-hidden bg-slate-900 lg:h-auto">
                                    <img :src="sectionVisual.image" :alt="sectionVisual.alt" class="absolute inset-0 h-full w-full object-cover">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-transparent to-transparent"></div>
                                    <figcaption class="absolute inset-x-0 bottom-0 p-5 text-sm font-bold text-white">{{ sectionVisual.caption }}</figcaption>
                                </figure>
                            </header>

                            <dl class="grid border-b border-slate-200 sm:grid-cols-3">
                                <div class="border-b border-slate-200 px-5 py-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0 sm:px-6">
                                    <dt class="student-detail-label">Organization type</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-900">{{ provider.type_label }}</dd>
                                </div>
                                <div class="border-b border-slate-200 px-5 py-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0 sm:px-6">
                                    <dt class="student-detail-label">Established</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-900">{{ provider.year_established || 'Not listed' }}</dd>
                                </div>
                                <div class="px-5 py-4 sm:px-6">
                                    <dt class="student-detail-label">Service area</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-900">{{ provider.service_area || 'Not listed' }}</dd>
                                </div>
                            </dl>

                            <div class="divide-y divide-slate-200">
                                <section class="px-5 py-5 sm:px-6">
                                    <div class="flex items-center gap-2">
                                        <i class="fa-solid fa-address-book w-4 text-center text-amber-700" aria-hidden="true"></i>
                                        <h4 class="text-base font-bold text-slate-950">Public contact</h4>
                                    </div>
                                    <div v-if="provider.contact?.available" class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <div v-if="provider.contact.department || provider.contact.person">
                                            <p class="student-detail-label">Contact</p>
                                            <p class="mt-1 text-sm font-bold text-slate-900">{{ [provider.contact.department, provider.contact.person].filter(Boolean).join(' | ') }}</p>
                                        </div>
                                        <div v-if="provider.contact.email">
                                            <p class="student-detail-label">Email</p>
                                            <a :href="`mailto:${provider.contact.email}`" class="mt-1 block break-all text-sm font-bold text-slate-900 hover:underline" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">{{ provider.contact.email }}</a>
                                        </div>
                                        <div v-if="provider.contact.number">
                                            <p class="student-detail-label">Phone</p>
                                            <a :href="`tel:${provider.contact.number}`" class="mt-1 block text-sm font-bold text-slate-900 hover:underline" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">{{ provider.contact.number }}</a>
                                        </div>
                                        <div v-if="provider.contact.office_hours">
                                            <p class="student-detail-label">Office hours</p>
                                            <p class="mt-1 text-sm font-bold text-slate-900">{{ provider.contact.office_hours }}</p>
                                        </div>
                                    </div>
                                    <p v-else class="mt-3 text-sm text-slate-600">The provider has not published a separate contact for this program.</p>
                                </section>

                                <section v-if="provider.location?.has_map" class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div class="flex items-start gap-3">
                                        <i class="fa-solid fa-location-dot mt-1 w-4 text-center text-amber-700" aria-hidden="true"></i>
                                        <div>
                                            <h4 class="text-base font-bold text-slate-950">Program location</h4>
                                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ [provider.location.name, provider.location.address].filter(Boolean).join(' | ') }}</p>
                                            <p v-if="provider.location.distance_label" class="mt-1 text-xs font-bold text-slate-500">{{ provider.location.distance_label }} from your saved location</p>
                                        </div>
                                    </div>
                                    <button type="button" class="student-button student-button-secondary shrink-0" @click="showMapModal = true">
                                        <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
                                        View map
                                    </button>
                                </section>

                                <section v-if="provider.website || provider.official_program_url" class="px-5 py-5 sm:px-6">
                                    <div class="flex flex-wrap gap-3">
                                        <a v-if="provider.official_program_url" :href="provider.official_program_url" target="_blank" rel="noopener noreferrer" class="student-button student-button-secondary" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">
                                            Official program page
                                            <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                        </a>
                                        <a v-if="provider.website" :href="provider.website" target="_blank" rel="noopener noreferrer" class="student-button student-button-secondary" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">
                                            Organization website
                                            <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </section>
                            </div>

                            <footer class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-500 sm:px-6">
                                {{ provider.decision_note }}
                            </footer>
                        </section>
                    </template>
                    </div>
                </div>
            </div>
        </section>

        <LocationMapModal
            v-if="detail && activeView === 'provider'"
            :open="showMapModal"
            eyebrow="Program location"
            :title="providerLocation.name || identity.title"
            :address="scholarshipMapAddress"
            :latitude="providerLocation.latitude"
            :longitude="providerLocation.longitude"
            :secondary-latitude="user?.latitude"
            :secondary-longitude="user?.longitude"
            :secondary-marker-text="userLocationLabel"
            :distance-label="providerLocation.distance_label ? `About ${providerLocation.distance_label}` : ''"
            :marker-text="providerLocation.name || identity.title"
            :note="hasUserMapLocation && providerLocation.distance_label ? `Your saved location is shown too: ${providerLocation.distance_label} from this program.` : 'Add your profile map pin to compare distance with this program.'"
            @close="showMapModal = false"
        />
    </main>
</template>
