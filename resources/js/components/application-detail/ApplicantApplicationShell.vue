<script setup>
import { computed } from 'vue';
import ApplicantSidebar from '../ApplicantSidebar.vue';
import ApplicationDecisionBanner from './ApplicationDecisionBanner.vue';

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
});

const statusClass = computed(() => ({
    positive: 'bg-emerald-100 text-emerald-800',
    negative: 'bg-rose-100 text-rose-800',
    attention: 'bg-amber-100 text-amber-800',
    muted: 'bg-slate-100 text-slate-600',
    active: 'bg-sky-100 text-sky-800',
    neutral: 'bg-slate-100 text-slate-700',
}[props.detail.identity?.status?.tone] ?? 'bg-slate-100 text-slate-700'));

const applicationVisual = computed(() => {
    const view = props.detail.view ?? 'summary';
    const subject = `${props.detail.identity?.program?.title ?? ''} ${props.detail.identity?.program?.category ?? ''}`.toLowerCase();

    const sectionVisuals = {
        submission: {
            src: '/images/application-documents.jpg',
            alt: 'A student preparing scholarship application documents',
            focus: 'object-center',
        },
        activities: {
            src: '/images/filipino-students-workshop.jpg',
            alt: 'Filipino students participating in a learning workshop',
            focus: 'object-center',
        },
        outcome: {
            src: props.detail.identity?.status?.tone === 'positive'
                ? '/images/filipino-graduates.jpg'
                : '/images/filipino-campus.jpg',
            alt: props.detail.identity?.status?.tone === 'positive'
                ? 'Filipino graduates celebrating an education milestone'
                : 'Students walking through a campus in the Philippines',
            focus: 'object-center',
        },
        history: {
            src: '/images/filipino-campus.jpg',
            alt: 'Students walking through a campus in the Philippines',
            focus: 'object-center',
        },
    };

    if (sectionVisuals[view]) {
        return sectionVisuals[view];
    }

    if (/(stem|science|technology|engineering)/.test(subject)) {
        return {
            src: '/images/filipino-students-science-class.jpg',
            alt: 'Filipino students participating in a science class',
            focus: 'object-center',
        };
    }

    if (/(college|university|tertiary|graduate)/.test(subject)) {
        return {
            src: '/images/filipino-graduates.jpg',
            alt: 'Filipino graduates celebrating an education milestone',
            focus: 'object-center',
        };
    }

    return {
        src: '/images/filipino-students-studying.jpg',
        alt: 'Filipino students studying together in a classroom',
        focus: 'object-center',
    };
});

function hideBrokenImage(event) {
    event.currentTarget.classList.add('hidden');
}
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <a
                    href="/dashboard/applications"
                    class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950"
                >
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to applications
                </a>

                <header class="mt-5 overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm">
                    <div class="grid lg:min-h-[15rem] lg:grid-cols-[minmax(0,1.35fr)_minmax(22rem,0.65fr)]">
                        <div class="flex flex-col justify-center px-5 py-6 sm:px-8 sm:py-8 lg:px-9">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Application record</p>
                                <span :class="['inline-flex rounded-sm px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass]">
                                    {{ detail.identity.status.label }}
                                </span>
                            </div>

                            <h1 class="mt-4 max-w-3xl font-display text-3xl font-bold leading-tight text-slate-950 sm:text-4xl">
                                {{ detail.identity.program.title }}
                            </h1>

                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-slate-600">
                                <a
                                    v-if="detail.identity.provider.href"
                                    :href="detail.identity.provider.href"
                                    class="font-bold text-slate-800 hover:text-slate-950 hover:underline"
                                >
                                    {{ detail.identity.provider.name }}
                                </a>
                                <span v-else class="font-bold text-slate-800">{{ detail.identity.provider.name }}</span>
                                <span v-if="detail.identity.provider.verified" class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                    Verified provider
                                </span>
                            </div>

                            <p v-if="detail.identity.submitted_at" class="mt-6 text-xs font-semibold text-slate-500">Submitted {{ detail.identity.submitted_at }}</p>
                        </div>

                        <figure class="relative order-first h-52 overflow-hidden bg-slate-900 lg:order-last lg:h-auto">
                            <img
                                :src="applicationVisual.src"
                                :alt="applicationVisual.alt"
                                :class="['absolute inset-0 h-full w-full object-cover', applicationVisual.focus]"
                            >
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/5 to-transparent"></div>
                            <figcaption class="absolute inset-x-0 bottom-0 flex items-end gap-3 p-5 sm:p-6">
                                <span class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-sm bg-white p-2 shadow-xl ring-1 ring-white/70 sm:h-16 sm:w-16">
                                    <i class="fa-solid fa-graduation-cap text-lg text-slate-400" aria-hidden="true"></i>
                                    <img
                                        :src="detail.identity.program.image_url"
                                        :alt="`${detail.identity.program.title} mark`"
                                        class="absolute inset-0 h-full w-full object-contain p-2"
                                        @error="hideBrokenImage"
                                    >
                                </span>
                                <div class="min-w-0 pb-1 text-white">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-300">Application record</p>
                                    <p class="mt-1 truncate text-sm font-bold">{{ detail.identity.provider.name }}</p>
                                </div>
                            </figcaption>
                        </figure>
                    </div>

                    <dl class="grid border-t border-slate-200 bg-slate-50/80 sm:grid-cols-2 xl:grid-cols-4">
                        <div
                            v-for="(item, index) in detail.snapshot"
                            :key="item.key"
                            :class="[
                                'px-4 py-3 sm:px-5',
                                index > 0 ? 'border-t border-slate-200 sm:border-t-0 sm:border-l' : '',
                                index === 2 ? 'sm:border-l-0 xl:border-l' : '',
                                index >= 2 ? 'sm:border-t xl:border-t-0' : '',
                            ]"
                        >
                            <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">{{ item.label }}</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ item.value }}</dd>
                        </div>
                    </dl>
                </header>

                <section
                    v-if="detail.view !== 'summary'"
                    class="relative mt-4 overflow-hidden rounded-md border border-slate-300 bg-white px-5 py-4 shadow-sm sm:px-6"
                    aria-labelledby="application-section-title"
                >
                    <span class="absolute inset-y-0 left-0 w-1 bg-amber-400" aria-hidden="true"></span>
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">{{ detail.page.eyebrow }}</p>
                    <h2 id="application-section-title" class="mt-1 font-display text-xl font-bold text-slate-950 sm:text-2xl">
                        {{ detail.page.title }}
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ detail.page.description }}</p>
                </section>

                <ApplicationDecisionBanner
                    v-if="detail.view === 'summary'"
                    class="mt-4 rounded-md"
                    :decision="detail.decision"
                />

                <nav class="mt-4 overflow-x-auto rounded-md border border-slate-300 bg-white p-1 shadow-sm" aria-label="Application record">
                    <div class="grid min-w-[42rem] grid-cols-5 gap-1">
                        <a
                            v-for="item in detail.navigation"
                            :key="item.key"
                            :href="item.href"
                            :aria-current="item.active ? 'page' : undefined"
                            :class="[
                                'flex min-h-11 items-center justify-center gap-2 rounded-sm border-b-2 px-3 py-2 text-sm font-bold transition',
                                item.active
                                    ? 'border-amber-400 bg-slate-950 text-white'
                                    : 'border-transparent text-slate-600 hover:bg-slate-100 hover:text-slate-950',
                            ]"
                        >
                            <i :class="[item.icon, 'w-4 text-center text-xs']" aria-hidden="true"></i>
                            <span>{{ item.label }}</span>
                            <span
                                v-if="item.count"
                                :class="[
                                    'grid h-5 min-w-5 place-items-center rounded px-1 text-[10px]',
                                    item.active ? 'bg-amber-300 text-slate-950' : 'bg-amber-100 text-amber-800',
                                ]"
                            >
                                {{ item.count }}
                            </span>
                        </a>
                    </div>
                </nav>

                <slot></slot>
            </div>
        </section>
    </main>
</template>
