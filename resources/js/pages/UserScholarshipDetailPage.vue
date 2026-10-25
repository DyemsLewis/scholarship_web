<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import ProviderApplicantPreviewToolbar from '../components/ProviderApplicantPreviewToolbar.vue';

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

const identity = computed(() => detail.value?.identity ?? {});
const decision = computed(() => detail.value?.decision ?? {});
const content = computed(() => detail.value?.content ?? {});
const snapshot = computed(() => detail.value?.snapshot ?? []);
const navigation = computed(() => detail.value?.navigation ?? []);
const activeView = computed(() => detail.value?.view ?? currentView.value);
const requirements = computed(() => content.value?.requirements ?? {});
const requirementReadiness = computed(() => requirements.value?.readiness ?? {});
const process = computed(() => content.value?.process ?? {});
const provider = computed(() => activeView.value === 'provider' ? content.value : {});
const providerLocation = computed(() => provider.value?.location ?? {});
const scholarshipImage = computed(() => identity.value?.provider?.logo_url);
const scholarshipVisual = computed(() => {
    const subject = `${identity.value?.title ?? ''} ${identity.value?.category ?? ''}`.toLowerCase();
    const chosenCover = identity.value?.image_url;

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
const sectionVisual = computed(() => ({
    summary: {
        image: scholarshipVisual.value.overview,
        alt: scholarshipVisual.value.overviewAlt,
        caption: 'Support built around a learner\'s education.',
    },
    eligibility: {
        image: '/images/filipino-senior-high.jpg',
        alt: 'Senior high school students in the Philippines',
        caption: 'Compare only the requirements that matter.',
    },
    award: {
        image: '/images/filipino-home-study.jpg',
        alt: 'A Filipino learner studying at home',
        caption: 'Know the support before you commit.',
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
    const blockers = content.value?.blockers ?? [];

    if (!profile.complete) {
        return {
            state: 'attention',
            eyebrow: 'Profile incomplete',
            title: 'Complete your profile for an accurate result',
            description: `${profile.missing?.length ?? 0} required profile detail(s) still need attention.`,
            icon: 'fa-solid fa-user-pen',
        };
    }

    if (!profile.verified) {
        return {
            state: 'attention',
            eyebrow: 'Profile verification required',
            title: 'Verify your profile before applying',
            description: 'You can review the criteria below while an authorized reviewer checks your supporting evidence.',
            icon: 'fa-solid fa-user-check',
        };
    }

    if (blockers.length) {
        return {
            state: 'blocked',
            eyebrow: 'Review needed',
            title: 'Some published rules do not match',
            description: 'Review the comparison below before deciding whether to continue.',
            icon: 'fa-solid fa-triangle-exclamation',
        };
    }

    return {
        state: 'ready',
        eyebrow: 'Profile match',
        title: 'No automatic blockers were found',
        description: 'The provider will still verify your information and supporting records.',
        icon: 'fa-solid fa-check',
    };
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
            badge: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        },
        positive: {
            icon: 'bg-emerald-100 text-emerald-800',
            badge: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        },
        attention: {
            icon: 'bg-amber-100 text-amber-900',
            badge: 'bg-amber-50 text-amber-900 ring-amber-200',
        },
        blocked: {
            icon: 'bg-rose-100 text-rose-800',
            badge: 'bg-rose-50 text-rose-800 ring-rose-200',
        },
        muted: {
            icon: 'bg-slate-200 text-slate-700',
            badge: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
        neutral: {
            icon: 'bg-slate-100 text-slate-700',
            badge: 'bg-slate-100 text-slate-700 ring-slate-200',
        },
    }[state] ?? {
        icon: 'bg-slate-100 text-slate-700',
        badge: 'bg-slate-100 text-slate-700 ring-slate-200',
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

function criterionStatus(criterion) {
    if (criterion.status === 'pass') {
        return { label: 'Matched', state: 'ready' };
    }

    if (criterion.status === 'fail') {
        return { label: 'Not matched', state: 'blocked' };
    }

    if (criterion.status === 'missing') {
        return { label: 'Missing info', state: 'attention' };
    }

    return { label: criterion.key === 'academic' && criterion.requirement ? 'Provider review' : 'Open', state: 'neutral' };
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

function handlePrimaryAction() {
    if (isPreview) return;

    if (decision.value.action?.kind === 'save') {
        toggleSave();
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

function readinessSection(item) {
    if (item?.key === 'eligibility') return 'eligibility';
    if (item?.key === 'files') return 'application';
    return 'award';
}

function blockPreviewAction(event) {
    if (isPreview) event.preventDefault();
}

onMounted(loadScholarship);
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
                    <header class="student-card overflow-hidden rounded-md border-slate-300 border-t-4 border-t-amber-400 shadow-[0_8px_24px_rgba(15,23,42,0.06)]" data-scholarship-detail-header>
                        <div class="grid lg:min-h-[390px] lg:grid-cols-[minmax(0,1.1fr)_minmax(25rem,0.9fr)]">
                            <div class="flex flex-col p-5 sm:p-7 lg:p-9">
                                <div class="min-w-0">
                                    <p class="student-kicker">Scholarship program</p>
                                    <h1 class="mt-2 max-w-3xl text-3xl font-bold leading-[1.08] text-slate-950 sm:text-4xl">{{ identity.title }}</h1>
                                    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-600">
                                        <a v-if="identity.provider?.href" :href="identity.provider.href" class="font-bold text-slate-800 hover:text-slate-950 hover:underline" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">
                                            {{ identity.provider.name }}
                                        </a>
                                        <span v-else class="font-bold text-slate-800">{{ identity.provider?.name }}</span>
                                        <span v-if="identity.provider?.verified" class="inline-flex items-center gap-1.5 font-semibold text-emerald-700">
                                            <i class="fa-solid fa-circle-check text-xs" aria-hidden="true"></i>
                                            Verified
                                        </span>
                                    </div>
                                    <p v-if="identity.category || identity.program_cycle" class="mt-1.5 text-xs font-semibold text-slate-500">
                                        {{ [identity.category, identity.program_cycle].filter(Boolean).join(' | ') }}
                                    </p>
                                </div>

                                <div class="mt-6 border-l-4 border-amber-400 bg-amber-50 px-4 py-3.5">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">{{ decision.eyebrow }}</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">{{ decision.title }}</h2>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">{{ decision.description }}</p>
                                </div>

                                <dl class="mt-6 grid border-y border-slate-200 sm:grid-cols-3">
                                    <div v-for="fact in snapshot" :key="fact.key" class="min-w-0 border-b border-slate-200 py-3 last:border-b-0 sm:border-b-0 sm:border-r sm:px-4 sm:first:pl-0 sm:last:border-r-0">
                                        <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{{ fact.label }}</dt>
                                        <dd class="mt-1 text-sm font-bold leading-5 text-slate-900">{{ fact.value }}</dd>
                                    </div>
                                </dl>

                                <div class="mt-auto flex flex-wrap items-center gap-2 pt-6">
                                    <button
                                        v-if="!isPreview && decision.action?.kind !== 'save'"
                                        type="button"
                                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                        :disabled="isSaving"
                                        @click="toggleSave"
                                    >
                                        <i :class="detail.saved?.is_saved ? 'fa-solid fa-bookmark text-amber-600' : 'fa-regular fa-bookmark'" aria-hidden="true"></i>
                                        {{ isSaving ? 'Saving...' : (detail.saved?.is_saved ? 'Saved' : 'Save') }}
                                    </button>
                                    <a
                                        v-if="!isPreview && decision.action?.kind === 'link'"
                                        :href="decision.action.href"
                                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800"
                                    >
                                        {{ decision.action.label }}
                                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </a>
                                    <button
                                        v-else-if="decision.action"
                                        type="button"
                                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60"
                                        :disabled="isSaving || isPreview"
                                        @click="handlePrimaryAction"
                                    >
                                        {{ isSaving ? 'Saving...' : decision.action.label }}
                                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>

                            <figure class="relative order-first h-56 overflow-hidden bg-slate-900 lg:order-last lg:h-auto">
                                <img :src="scholarshipVisual.hero" :alt="scholarshipVisual.heroAlt" class="absolute inset-0 h-full w-full object-cover">
                                <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/10 to-transparent"></div>
                                <figcaption class="absolute inset-x-0 bottom-0 flex items-end gap-3 p-5 sm:p-6">
                                    <span class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-md bg-white p-2.5 shadow-xl ring-1 ring-white/70 sm:h-20 sm:w-20">
                                        <img
                                            v-if="scholarshipImage && !imageFailed"
                                            :src="scholarshipImage"
                                            :alt="`${identity.title} program mark`"
                                            class="h-full w-full object-contain"
                                            @error="imageFailed = true"
                                        >
                                        <i v-else class="fa-solid fa-graduation-cap text-xl text-slate-500" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0 pb-1 text-white">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-300">Education opportunity</p>
                                        <p class="mt-1 text-sm font-bold leading-5">{{ identity.provider?.name }}</p>
                                    </div>
                                </figcaption>
                            </figure>
                        </div>
                    </header>

                    <nav class="student-card overflow-x-auto rounded-md border-slate-300 p-1.5" aria-label="Scholarship details" data-scholarship-detail-navigation>
                        <div class="flex min-w-max lg:min-w-0">
                            <a
                                v-for="item in navigation"
                                :key="item.key"
                                :href="item.href"
                                :aria-current="item.active ? 'page' : undefined"
                                :class="[
                                    'relative flex min-h-12 items-center justify-center rounded-md px-5 text-sm font-bold transition lg:flex-1',
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

                    <template v-if="activeView === 'summary'">
                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-overview>
                            <div class="grid lg:grid-cols-[minmax(0,1fr)_24rem]">
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
                                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ benefitDetails(content.primary_benefit) || content.primary_benefit.display_summary }}</p>
                                    </div>

                                    <a :href="navigation.find((item) => item.key === 'award')?.href" class="mt-6 inline-flex w-fit items-center gap-2 text-sm font-bold text-slate-800 hover:text-slate-950 hover:underline" @click="openPreviewSection($event, 'award')">
                                        See benefits and terms
                                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </a>
                                </div>

                                <figure class="relative min-h-56 overflow-hidden bg-slate-900 lg:min-h-full">
                                    <img :src="sectionVisual.image" :alt="sectionVisual.alt" class="absolute inset-0 h-full w-full object-cover">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/75 via-transparent to-transparent"></div>
                                    <figcaption class="absolute inset-x-0 bottom-0 p-5 text-sm font-bold text-white">{{ sectionVisual.caption }}</figcaption>
                                </figure>
                            </div>
                        </section>

                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-readiness>
                            <header class="border-b border-slate-200 bg-slate-50/70 px-4 py-4 sm:px-5">
                                <p class="student-kicker">Before you apply</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">Your readiness</h3>
                            </header>
                            <div class="divide-y divide-slate-200">
                                <a v-for="item in content.readiness" :key="item.key" :href="item.href" class="group flex items-start gap-3 px-4 py-3.5 transition hover:bg-slate-50 sm:px-5" @click="openPreviewSection($event, readinessSection(item))">
                                    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md', stateStyle(item.state).icon]">
                                        <i :class="item.icon" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-bold text-slate-950">{{ item.title }}</p>
                                            <span :class="['px-2.5 py-1 text-xs font-bold ring-1 ring-inset', stateStyle(item.state).badge]">{{ item.status }}</span>
                                        </div>
                                        <p class="mt-1 text-sm leading-5 text-slate-600">{{ item.description }}</p>
                                    </div>
                                    <i class="fa-solid fa-chevron-right mt-2.5 shrink-0 text-xs text-slate-300 transition group-hover:text-slate-700" aria-hidden="true"></i>
                                </a>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="activeView === 'eligibility'">
                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-eligibility>
                            <header class="grid border-b border-slate-200 bg-slate-50/70 lg:grid-cols-[minmax(0,1fr)_22rem]">
                                <div class="px-5 py-6 sm:px-7 sm:py-7">
                                    <p class="student-kicker">{{ eligibilityVerdict.eyebrow }}</p>
                                    <h3 class="mt-1 text-2xl font-bold text-slate-950">{{ eligibilityVerdict.title }}</h3>
                                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ eligibilityVerdict.description }}</p>
                                </div>
                                <figure class="relative h-44 overflow-hidden bg-slate-900 lg:h-auto">
                                    <img :src="sectionVisual.image" :alt="sectionVisual.alt" class="absolute inset-0 h-full w-full object-cover">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-transparent to-transparent"></div>
                                    <figcaption class="absolute inset-x-0 bottom-0 p-4 text-sm font-bold text-white">{{ sectionVisual.caption }}</figcaption>
                                </figure>
                            </header>

                            <div v-if="content.criteria?.length" class="divide-y divide-slate-200">
                                <article v-for="criterion in content.criteria" :key="criterion.key" class="px-4 py-3.5 sm:px-5">
                                    <div class="grid gap-3 lg:grid-cols-[minmax(13rem,1.2fr)_minmax(12rem,1fr)_minmax(12rem,1fr)_auto] lg:items-center lg:gap-6">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">{{ displayValue(criterion.label, 'Eligibility requirement') }}</p>
                                            <p v-if="criterion.comparison || criterion.note" class="mt-1 text-xs leading-5 text-slate-500">{{ criterion.comparison || criterion.note }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Your profile</p>
                                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ displayValue(criterion.student_value || criterion.studentValue, 'Not provided') }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Program rule</p>
                                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ criterion.status === 'info' ? 'Open to all' : displayValue(criterion.requirement, 'No restriction') }}</p>
                                        </div>
                                        <span :class="['w-fit px-2.5 py-1 text-xs font-bold ring-1 ring-inset', stateStyle(criterionStatus(criterion).state).badge]">
                                            {{ criterionStatus(criterion).label }}
                                        </span>
                                    </div>
                                </article>
                            </div>
                            <p v-else class="p-5 text-sm text-slate-600">This program has no structured eligibility restrictions.</p>
                        </section>

                        <details v-if="content.written_conditions || content.provider_review?.length" class="group student-card overflow-hidden rounded-md border-slate-300">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-4 sm:px-5 [&::-webkit-details-marker]:hidden">
                                <div>
                                    <p class="student-kicker">Provider review</p>
                                    <h3 class="mt-1 text-base font-bold text-slate-950">Conditions checked by a person</h3>
                                </div>
                                <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                            </summary>
                            <div class="border-t border-slate-200">
                                <p v-if="content.written_conditions" class="whitespace-pre-line px-4 py-4 text-sm leading-7 text-slate-700 sm:px-5">{{ content.written_conditions }}</p>
                                <div v-if="content.provider_review?.length" class="divide-y divide-slate-200 border-t border-slate-200">
                                    <div v-for="condition in content.provider_review" :key="condition.key || condition.label" class="px-4 py-3.5 sm:px-5">
                                        <p class="text-sm font-bold text-slate-900">{{ condition.label || 'Provider condition' }}</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ condition.note || condition.comparison || 'The provider confirms this during review.' }}</p>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </template>

                    <template v-else-if="activeView === 'award'">
                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-benefits>
                            <header class="grid border-b border-slate-200 bg-slate-50/70 lg:grid-cols-[minmax(0,1fr)_22rem]">
                                <div class="px-5 py-6 sm:px-7 sm:py-7">
                                    <p class="student-kicker">Benefits</p>
                                    <h3 class="mt-1 text-2xl font-bold text-slate-950">What recipients receive</h3>
                                    <p class="mt-2 text-sm font-semibold text-slate-500">Support period: {{ content.support_period }}</p>
                                </div>
                                <figure class="relative h-44 overflow-hidden bg-slate-900 lg:h-auto">
                                    <img :src="sectionVisual.image" :alt="sectionVisual.alt" class="absolute inset-0 h-full w-full object-cover">
                                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-transparent to-transparent"></div>
                                    <figcaption class="absolute inset-x-0 bottom-0 p-4 text-sm font-bold text-white">{{ sectionVisual.caption }}</figcaption>
                                </figure>
                            </header>
                            <div v-if="content.benefits?.length" class="divide-y divide-slate-200">
                                <article v-for="benefit in content.benefits" :key="benefit.id || `${benefit.type}-${benefit.title}`" class="flex items-start gap-3 px-4 py-4 sm:px-5">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                        <i :class="benefitIcon(benefit.type)" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <h4 class="text-base font-bold text-slate-950">{{ benefit.title }}</h4>
                                        <p class="mt-1 text-sm font-semibold leading-6 text-slate-800">{{ benefitDetails(benefit) || benefit.display_summary }}</p>
                                        <p v-if="benefit.description" class="mt-1 text-sm leading-6 text-slate-600">{{ benefit.description }}</p>
                                    </div>
                                </article>
                            </div>
                            <p v-else class="p-5 text-sm text-slate-600">The provider has not published a detailed benefit package.</p>
                        </section>

                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-terms>
                            <header class="border-b border-slate-200 bg-slate-50/70 px-4 py-4 sm:px-5">
                                <p class="student-kicker">Recipient terms</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">What recipients agree to</h3>
                            </header>
                            <div v-if="content.terms?.length" class="divide-y divide-slate-200">
                                <details v-for="term in content.terms" :key="term.key" class="group">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3.5 text-sm font-bold text-slate-900 hover:bg-slate-50 sm:px-5 [&::-webkit-details-marker]:hidden">
                                        {{ term.label }}
                                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                    </summary>
                                    <p class="whitespace-pre-line border-t border-slate-100 bg-slate-50/60 px-4 py-4 text-sm leading-7 text-slate-600 sm:px-5">{{ term.value }}</p>
                                </details>
                            </div>
                            <p v-else class="p-5 text-sm text-slate-600">No additional recipient responsibilities were published.</p>
                            <footer class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-500 sm:px-5">
                                Ask the provider about anything unclear before accepting an award.
                            </footer>
                        </section>

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

                    <template v-else-if="activeView === 'application'">
                        <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-application-files>
                            <header class="flex flex-col gap-4 border-b border-slate-200 bg-slate-50/70 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-5">
                                <div>
                                    <p class="student-kicker">Application files</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ requirementReadiness.title }}</h3>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">{{ requirementReadiness.description }}</p>
                                </div>
                                <a v-if="requirements.manage_files_href" :href="requirements.manage_files_href" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                    <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                                    My Documents
                                </a>
                                <span v-else-if="isPreview" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-md border border-slate-200 bg-slate-100 px-4 text-sm font-bold text-slate-500">
                                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                                    Applicant files
                                </span>
                            </header>

                            <div class="grid gap-3 border-b border-slate-200 px-4 py-3.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-5">
                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ requirements.review_method_label }}</p>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">{{ requirements.review_method_note }}</p>
                                </div>
                                <span :class="['w-fit px-2.5 py-1 text-xs font-bold ring-1 ring-inset', stateStyle(requirementReadiness.state === 'ready' ? 'ready' : requirementReadiness.state === 'needs_files' ? 'attention' : 'neutral').badge]">
                                    {{ requirementReadiness.ready_count || 0 }} of {{ requirementReadiness.required_count || 0 }} ready
                                </span>
                            </div>

                            <div v-if="requirementReadiness.items?.length" class="divide-y divide-slate-200">
                                <div v-for="item in requirementReadiness.items" :key="item.name" class="flex items-center gap-3 px-4 py-3.5 sm:px-5">
                                    <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md', item.status === 'ready' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900']">
                                        <i :class="item.status === 'ready' ? 'fa-solid fa-check' : 'fa-solid fa-upload'" aria-hidden="true"></i>
                                    </span>
                                    <p class="min-w-0 flex-1 text-sm font-bold text-slate-900">{{ item.name }}</p>
                                    <span class="text-xs font-bold text-slate-500">{{ item.status_label }}</span>
                                </div>
                            </div>
                            <p v-else class="px-4 py-4 text-sm text-slate-600 sm:px-5">No files are required before the initial review.</p>

                            <details v-if="requirements.optional_documents?.length || requirements.post_qualification_documents?.length" class="border-t border-slate-200">
                                <summary class="cursor-pointer px-4 py-4 text-sm font-bold text-slate-800 hover:bg-slate-50 sm:px-5">Files that may be requested later</summary>
                                <div class="border-t border-slate-200 px-4 py-4 text-sm text-slate-600 sm:px-5">
                                    <p v-for="item in [...(requirements.optional_documents || []), ...(requirements.post_qualification_documents || [])]" :key="item" class="flex items-start gap-2 py-1.5">
                                        <i class="fa-regular fa-file mt-1 text-slate-400" aria-hidden="true"></i>
                                        {{ item }}
                                    </p>
                                </div>
                            </details>
                        </section>

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
                            <ol class="divide-y divide-slate-200">
                                <li v-for="stage in process.stages" :key="stage.key" class="grid gap-3 px-4 py-4 sm:grid-cols-[2.5rem_minmax(0,1fr)_minmax(11rem,auto)] sm:items-center sm:gap-4 sm:px-5">
                                    <span class="grid h-9 w-9 place-items-center rounded-md bg-slate-950 text-sm font-bold text-amber-300">{{ stage.step }}</span>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <i :class="[stage.icon, 'w-4 text-center text-amber-700']" aria-hidden="true"></i>
                                            <h4 class="text-base font-bold text-slate-950">{{ stage.label }}</h4>
                                        </div>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ stage.description }}</p>
                                    </div>
                                    <div class="sm:text-right">
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
                                                <span :class="['inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-bold ring-1 ring-inset', stateStyle(provider.verified ? 'ready' : 'attention').badge]">
                                                    <i :class="provider.verified ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-info'" aria-hidden="true"></i>
                                                    {{ provider.verification_label }}
                                                </span>
                                            </div>
                                            <p class="mt-1 text-sm font-semibold text-slate-500">{{ provider.type_label }}</p>
                                        </div>
                                    </div>
                                    <p v-if="provider.summary" class="mt-4 max-w-3xl whitespace-pre-line text-sm leading-7 text-slate-600">{{ provider.summary }}</p>
                                    <a v-if="provider.profile_href" :href="provider.profile_href" class="mt-5 inline-flex min-h-10 w-fit items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">
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
                                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Organization type</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-900">{{ provider.type_label }}</dd>
                                </div>
                                <div class="border-b border-slate-200 px-5 py-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0 sm:px-6">
                                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Established</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-900">{{ provider.year_established || 'Not listed' }}</dd>
                                </div>
                                <div class="px-5 py-4 sm:px-6">
                                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Service area</dt>
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
                                            <p class="text-xs font-semibold text-slate-500">Contact</p>
                                            <p class="mt-1 text-sm font-bold text-slate-900">{{ [provider.contact.department, provider.contact.person].filter(Boolean).join(' | ') }}</p>
                                        </div>
                                        <div v-if="provider.contact.email">
                                            <p class="text-xs font-semibold text-slate-500">Email</p>
                                            <a :href="`mailto:${provider.contact.email}`" class="mt-1 block break-all text-sm font-bold text-slate-900 hover:underline" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">{{ provider.contact.email }}</a>
                                        </div>
                                        <div v-if="provider.contact.number">
                                            <p class="text-xs font-semibold text-slate-500">Phone</p>
                                            <a :href="`tel:${provider.contact.number}`" class="mt-1 block text-sm font-bold text-slate-900 hover:underline" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">{{ provider.contact.number }}</a>
                                        </div>
                                        <div v-if="provider.contact.office_hours">
                                            <p class="text-xs font-semibold text-slate-500">Office hours</p>
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
                                    <button type="button" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="showMapModal = true">
                                        <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
                                        View map
                                    </button>
                                </section>

                                <section v-if="provider.website || provider.official_program_url" class="px-5 py-5 sm:px-6">
                                    <div class="flex flex-wrap gap-3">
                                        <a v-if="provider.official_program_url" :href="provider.official_program_url" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">
                                            Official program page
                                            <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                        </a>
                                        <a v-if="provider.website" :href="provider.website" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center gap-2 rounded-md border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50" :aria-disabled="isPreview || undefined" @click="blockPreviewAction">
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
