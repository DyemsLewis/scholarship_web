<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import SiteFooter from '../components/SiteFooter.vue';
import SiteNavbar from '../components/SiteNavbar.vue';

const activeSlide = ref(0);
let carouselTimer = null;

const heroSlides = [
    {
        eyebrow: 'Scholarship access for applicants',
        title: 'Find scholarship opportunities with a clearer path',
        text: 'Students can build a profile, compare programs, save scholarships, and track applications from one portal.',
        image: '/images/scholarship-hero.jpg',
        primaryAction: 'Create Account',
        primaryHref: '/register',
        secondaryAction: 'Sign In',
        secondaryHref: '/login',
        metric: 'Student Workspace',
        metricText: 'Profiles, saved programs, applications, and DSS guidance.',
    },
    {
        eyebrow: 'Provider workspace',
        title: 'Manage scholarship programs and applicant reviews',
        text: 'Providers get a separate panel for posting scholarships, reviewing documents, and tracking decision support signals.',
        image: '/images/scholarship-cards.jpg',
        primaryAction: 'Provider Register',
        primaryHref: '/provider/register',
        secondaryAction: 'Provider Portal',
        secondaryHref: '/provider',
        metric: 'Provider Panel',
        metricText: 'Program creation, document review, and application rankings.',
    },
    {
        eyebrow: 'Decision support system',
        title: 'Use data to support fairer scholarship decisions',
        text: 'Eligibility match, document readiness, academic merit, and financial need are combined into transparent DSS guidance.',
        image: '/images/application-documents.jpg',
        primaryAction: 'Explore Programs',
        primaryHref: '/register',
        secondaryAction: 'Learn More',
        secondaryHref: '/register',
        metric: 'DSS Ready',
        metricText: 'Eligibility guidance, recommendations, and application support.',
    },
];

const currentSlide = computed(() => heroSlides[activeSlide.value]);

function assetUrl(path) {
    return window.appAssetUrl ? window.appAssetUrl(path) : path;
}

function goToSlide(index) {
    activeSlide.value = (index + heroSlides.length) % heroSlides.length;
    restartCarousel();
}

function nextSlide() {
    goToSlide(activeSlide.value + 1);
}

function previousSlide() {
    goToSlide(activeSlide.value - 1);
}

function startCarousel() {
    carouselTimer = window.setInterval(() => {
        activeSlide.value = (activeSlide.value + 1) % heroSlides.length;
    }, 6000);
}

function stopCarousel() {
    if (carouselTimer) {
        window.clearInterval(carouselTimer);
        carouselTimer = null;
    }
}

function restartCarousel() {
    stopCarousel();
    startCarousel();
}

onMounted(startCarousel);
onBeforeUnmount(stopCarousel);

const scholarshipSteps = [
    {
        title: 'Choose your role',
        text: 'Applicants and providers each have a dedicated registration path.',
        image: '/images/student-dashboard.jpg',
        icon: 'fa-solid fa-user-check',
    },
    {
        title: 'Enter the right workspace',
        text: 'Applicants continue to scholarship access while providers use a provider dashboard.',
        image: '/images/scholarship-cards.jpg',
        icon: 'fa-solid fa-door-open',
    },
    {
        title: 'Keep records organized',
        text: 'Applicants and providers can keep scholarship records in one organized portal.',
        image: '/images/application-documents.jpg',
        icon: 'fa-solid fa-folder-tree',
    },
];

const supportTools = [
    {
        title: 'Scholarship recommendations',
        text: 'See programs that may fit the learner profile and eligibility rules.',
        icon: 'fa-solid fa-wand-magic-sparkles',
    },
    {
        title: 'Document checklist',
        text: 'Know which requirements are prepared and which ones are still missing.',
        icon: 'fa-solid fa-list-check',
    },
    {
        title: 'Guided application',
        text: 'Apply through simple steps instead of one long confusing form.',
        icon: 'fa-solid fa-route',
    },
    {
        title: 'Deadline reminders',
        text: 'Receive portal reminders for deadlines, missing details, and updates.',
        icon: 'fa-solid fa-bell',
    },
    {
        title: 'Location view',
        text: 'Check scholarship location details and see how far a program may be.',
        icon: 'fa-solid fa-map-location-dot',
    },
];

const audiences = [
    {
        label: 'For applicants',
        title: 'Find and continue scholarship opportunities',
        text: 'Students can create an applicant profile, sign in, and continue their scholarship journey through the portal.',
        href: '/register',
        action: 'Register as applicant',
        image: '/images/student-dashboard.jpg',
        icon: 'fa-solid fa-user-graduate',
    },
    {
        label: 'For providers',
        title: 'Manage scholarship provider access',
        text: 'Scholarship providers can register separately and access a workspace prepared for managing scholarship programs.',
        href: '/provider/register',
        action: 'Register as provider',
        image: '/images/scholarship-cards.jpg',
        icon: 'fa-solid fa-building-columns',
    },
];
</script>

<template>
    <main class="min-h-screen bg-white text-slate-900">
        <section class="relative flex h-[720px] min-h-[640px] flex-col overflow-hidden bg-slate-900 text-white sm:h-[760px] lg:h-[86vh] lg:min-h-[700px]">
            <div class="absolute inset-0">
                <div
                    v-for="(slide, index) in heroSlides"
                    :key="slide.title"
                    :class="[
                        'absolute inset-0 bg-cover bg-center transition-opacity duration-700 ease-out',
                        activeSlide === index ? 'opacity-100' : 'opacity-0',
                    ]"
                    :style="{ backgroundImage: `url('${assetUrl(slide.image)}')` }"
                ></div>
                <div class="absolute inset-0 bg-[linear-gradient(90deg,_rgba(8,20,38,0.9),_rgba(8,20,38,0.62),_rgba(8,20,38,0.18))]"></div>
                <div class="absolute inset-x-0 bottom-0 h-44 bg-gradient-to-t from-slate-950/70 to-transparent"></div>
            </div>

            <SiteNavbar variant="transparent" show-icons />

            <div class="relative z-10 mx-auto flex w-full max-w-6xl flex-1 items-center px-4 pt-10 pb-24 sm:px-6 sm:pt-12 sm:pb-28 lg:px-8">
                <div class="max-w-3xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-200">
                        {{ currentSlide.eyebrow }}
                    </p>
                    <h1
                        :key="currentSlide.title"
                        class="mt-5 min-h-[9rem] animate-[fadeIn_0.55s_ease-out] font-display text-4xl leading-tight font-bold text-white sm:min-h-[11.25rem] sm:text-5xl lg:min-h-[13.5rem] lg:text-6xl"
                    >
                        {{ currentSlide.title }}
                    </h1>
                    <p
                        :key="currentSlide.text"
                        class="mt-5 min-h-[6rem] max-w-xl animate-[fadeIn_0.65s_ease-out] text-lg leading-8 text-slate-100"
                    >
                        {{ currentSlide.text }}
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a
                            :href="currentSlide.primaryHref"
                            class="rounded-md bg-amber-300 px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                        >
                            <i class="fa-solid fa-arrow-right mr-2"></i>
                            {{ currentSlide.primaryAction }}
                        </a>
                        <a
                            :href="currentSlide.secondaryHref"
                            class="rounded-md bg-white px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-slate-100"
                        >
                            <i class="fa-solid fa-circle-info mr-2"></i>
                            {{ currentSlide.secondaryAction }}
                        </a>
                    </div>
                </div>

            </div>

            <div class="absolute inset-x-0 bottom-6 z-10 px-4 sm:px-6 lg:bottom-8 lg:px-8">
                <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4">
                    <div class="flex gap-2">
                        <button
                            v-for="(slide, index) in heroSlides"
                            :key="slide.title"
                            type="button"
                            :aria-label="`Go to slide ${index + 1}`"
                            :class="[
                                'h-2.5 rounded-full transition-all',
                                activeSlide === index ? 'w-9 bg-amber-300' : 'w-2.5 bg-white/45 hover:bg-white/80',
                            ]"
                            @click="goToSlide(index)"
                        ></button>
                    </div>

                    <div class="flex gap-2">
                        <button
                            type="button"
                            aria-label="Previous slide"
                            class="rounded-md border border-white/30 bg-white/10 px-3 py-2 text-sm font-bold text-white transition hover:bg-white hover:text-slate-950"
                            @click="previousSlide"
                        >
                            <i class="fa-solid fa-chevron-left mr-2"></i>
                            Prev
                        </button>
                        <button
                            type="button"
                            aria-label="Next slide"
                            class="rounded-md border border-white/30 bg-white/10 px-3 py-2 text-sm font-bold text-white transition hover:bg-white hover:text-slate-950"
                            @click="nextSlide"
                        >
                            Next
                            <i class="fa-solid fa-chevron-right ml-2"></i>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-slate-200 bg-white px-4 py-10 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-6xl gap-6 sm:grid-cols-3">
                <div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-md bg-amber-100 text-amber-800">
                        <i class="fa-solid fa-id-card"></i>
                    </span>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Account profile
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Register as an applicant or a scholarship provider.
                    </p>
                </div>

                <div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-md bg-amber-100 text-amber-700">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </span>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Scholarship access
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Applicants and providers enter the workspace built for their role.
                    </p>
                </div>

                <div>
                    <span class="flex h-11 w-11 items-center justify-center rounded-md bg-slate-100 text-slate-800">
                        <i class="fa-solid fa-shield-halved"></i>
                    </span>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Organized records
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Applicants and providers can keep scholarship activity easier to track.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-b border-slate-200 bg-slate-50 px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Helpful Tools
                    </p>
                    <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                        Built to make scholarship searching easier
                    </h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">
                        The platform keeps the important parts clear: suitable programs, requirements, reminders, applications, and location details.
                    </p>
                </div>

                <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <article
                        v-for="tool in supportTools"
                        :key="tool.title"
                        class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                    >
                        <span class="flex h-10 w-10 items-center justify-center rounded-md bg-slate-950 text-amber-200">
                            <i :class="[tool.icon, 'text-sm']"></i>
                        </span>
                        <h3 class="mt-4 font-bold leading-6 text-slate-950">
                            {{ tool.title }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            {{ tool.text }}
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="border-b border-slate-200 bg-white px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Built for both sides
                    </p>
                    <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                        Applicants and providers use the same portal with different roles
                    </h2>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-2">
                    <article
                        v-for="audience in audiences"
                        :key="audience.label"
                        class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50"
                    >
                        <img :src="assetUrl(audience.image)" :alt="audience.title" class="h-52 w-full object-cover">
                        <div class="p-6">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-md bg-slate-900 text-white">
                                    <i :class="[audience.icon, 'text-sm']"></i>
                                </span>
                                <p class="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">
                                    {{ audience.label }}
                                </p>
                            </div>
                            <h3 class="mt-3 text-xl font-bold text-slate-950">
                                {{ audience.title }}
                            </h3>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ audience.text }}
                            </p>
                            <a
                                :href="audience.href"
                                class="mt-5 inline-flex rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                {{ audience.action }}
                            </a>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section class="bg-slate-50 px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Portal Flow
                    </p>
                    <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">
                        A clearer path from registration to the right dashboard
                    </h2>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-3">
                    <article
                        v-for="step in scholarshipSteps"
                        :key="step.title"
                        class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
                    >
                        <img :src="assetUrl(step.image)" :alt="step.title" class="h-36 w-full object-cover">
                        <div class="p-5">
                            <div class="flex items-center gap-3">
                                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-amber-100 text-amber-800">
                                    <i :class="[step.icon, 'text-sm']"></i>
                                </span>
                                <h3 class="text-lg font-bold text-slate-950">
                                    {{ step.title }}
                                </h3>
                            </div>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ step.text }}
                            </p>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <SiteFooter variant="dark" />
    </main>
</template>

<style scoped>
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
