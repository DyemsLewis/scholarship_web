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
        secondaryAction: 'Admin Access',
        secondaryHref: '/admin',
        metric: 'DSS Ready',
        metricText: 'Weighted scores, recommendations, analytics, and exports.',
    },
];

const currentSlide = computed(() => heroSlides[activeSlide.value]);

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
    },
    {
        title: 'Enter the right workspace',
        text: 'Applicants continue to scholarship access while providers use a provider dashboard.',
        image: '/images/scholarship-cards.jpg',
    },
    {
        title: 'Keep records organized',
        text: 'Admins can monitor account roles and review registered users from the admin panel.',
        image: '/images/application-documents.jpg',
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
    },
    {
        label: 'For providers',
        title: 'Manage scholarship provider access',
        text: 'Scholarship providers can register separately and access a workspace prepared for managing scholarship programs.',
        href: '/provider/register',
        action: 'Register as provider',
        image: '/images/scholarship-cards.jpg',
    },
];
</script>

<template>
    <main class="min-h-screen bg-white text-slate-900">
        <section class="relative flex min-h-[86vh] flex-col overflow-hidden bg-slate-900 text-white">
            <div class="absolute inset-0">
                <div
                    v-for="(slide, index) in heroSlides"
                    :key="slide.title"
                    :class="[
                        'absolute inset-0 bg-cover bg-center transition-opacity duration-700 ease-out',
                        activeSlide === index ? 'opacity-100' : 'opacity-0',
                    ]"
                    :style="{ backgroundImage: `url(${slide.image})` }"
                ></div>
                <div class="absolute inset-0 bg-[linear-gradient(90deg,_rgba(8,20,38,0.9),_rgba(8,20,38,0.62),_rgba(8,20,38,0.18))]"></div>
                <div class="absolute inset-x-0 bottom-0 h-44 bg-gradient-to-t from-slate-950/70 to-transparent"></div>
            </div>

            <SiteNavbar variant="transparent" />

            <div class="relative z-10 mx-auto grid w-full max-w-6xl flex-1 items-center gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_22rem] lg:px-8">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-200">
                        {{ currentSlide.eyebrow }}
                    </p>
                    <h1
                        :key="currentSlide.title"
                        class="mt-5 animate-[fadeIn_0.55s_ease-out] font-display text-4xl leading-tight font-bold text-white sm:text-5xl lg:text-6xl"
                    >
                        {{ currentSlide.title }}
                    </h1>
                    <p
                        :key="currentSlide.text"
                        class="mt-5 max-w-xl animate-[fadeIn_0.65s_ease-out] text-lg leading-8 text-slate-100"
                    >
                        {{ currentSlide.text }}
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a
                            :href="currentSlide.primaryHref"
                            class="rounded-md bg-amber-300 px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                        >
                            {{ currentSlide.primaryAction }}
                        </a>
                        <a
                            :href="currentSlide.secondaryHref"
                            class="rounded-md bg-white px-5 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-slate-100"
                        >
                            {{ currentSlide.secondaryAction }}
                        </a>
                    </div>
                </div>

                <aside class="rounded-lg border border-white/15 bg-white/10 p-5 shadow-2xl shadow-slate-950/30 backdrop-blur-md">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-200">
                        {{ currentSlide.metric }}
                    </p>
                    <p class="mt-3 text-sm leading-6 text-slate-100">
                        {{ currentSlide.metricText }}
                    </p>
                    <div class="mt-5 overflow-hidden rounded-md border border-white/10">
                        <img
                            :src="currentSlide.image"
                            :alt="currentSlide.title"
                            class="h-44 w-full object-cover"
                        >
                    </div>
                </aside>
            </div>

            <div class="relative z-10 mx-auto mb-8 flex w-full max-w-6xl flex-col gap-4 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between gap-4">
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
                            Prev
                        </button>
                        <button
                            type="button"
                            aria-label="Next slide"
                            class="rounded-md border border-white/30 bg-white/10 px-3 py-2 text-sm font-bold text-white transition hover:bg-white hover:text-slate-950"
                            @click="nextSlide"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section class="border-b border-slate-200 bg-white px-4 py-10 sm:px-6 lg:px-8">
            <div class="mx-auto grid max-w-6xl gap-6 sm:grid-cols-3">
                <div>
                    <p class="font-display text-3xl font-bold text-emerald-700">
                        01
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Account profile
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Register as an applicant or a scholarship provider.
                    </p>
                </div>

                <div>
                    <p class="font-display text-3xl font-bold text-amber-600">
                        02
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Scholarship access
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Applicants and providers enter the workspace built for their role.
                    </p>
                </div>

                <div>
                    <p class="font-display text-3xl font-bold text-sky-700">
                        03
                    </p>
                    <h2 class="mt-2 text-lg font-bold text-slate-950">
                        Admin oversight
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Admins can review users and role distribution.
                    </p>
                </div>
            </div>
        </section>

        <section class="border-b border-slate-200 bg-white px-4 py-14 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="max-w-2xl">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
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
                        <img :src="audience.image" :alt="audience.title" class="h-52 w-full object-cover">
                        <div class="p-6">
                            <p class="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">
                                {{ audience.label }}
                            </p>
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
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
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
                        <img :src="step.image" :alt="step.title" class="h-36 w-full object-cover">
                        <div class="p-5">
                            <h3 class="text-lg font-bold text-slate-950">
                                {{ step.title }}
                            </h3>
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
