<script setup>
import SiteNavbar from './SiteNavbar.vue';

defineProps({
    eyebrow: {
        type: String,
        default: 'Scholarship Portal',
    },
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        required: true,
    },
    switchHref: {
        type: String,
        default: '#',
    },
    switchLabel: {
        type: String,
        required: true,
    },
    switchText: {
        type: String,
        required: true,
    },
    panelBadge: {
        type: String,
        default: 'Student Funding Desk',
    },
    panelTitle: {
        type: String,
        required: true,
    },
    panelText: {
        type: String,
        required: true,
    },
    panelHighlights: {
        type: Array,
        default: () => [],
    },
    panelNote: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#eef4ff_0%,_#f8fafc_42%,_#edf2f7_100%)]">
        <SiteNavbar />

        <div class="mx-auto flex min-h-[calc(100vh-5rem)] max-w-6xl items-center px-4 py-8 sm:px-6 lg:px-8">
            <div class="grid w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[0_24px_80px_rgba(15,23,42,0.12)] lg:grid-cols-[0.92fr_1.08fr]">
                <aside class="relative border-b border-slate-200 bg-slate-900 px-6 py-8 text-white sm:px-8 lg:border-b-0 lg:border-r lg:px-10 lg:py-10">
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(250,204,21,0.18),_transparent_30%),linear-gradient(180deg,_rgba(15,23,42,0.96),_rgba(30,41,59,0.98))]"></div>
                    <div class="absolute inset-y-0 right-0 hidden w-px bg-white/10 lg:block"></div>

                    <div class="relative flex h-full flex-col justify-between gap-8">
                        <div>
                            <div class="inline-flex items-center gap-2 rounded-md border border-white/15 bg-white/8 px-3 py-1.5 text-xs font-medium uppercase tracking-[0.22em] text-amber-100/90">
                                {{ panelBadge }}
                            </div>

                            <h2 class="mt-5 font-display text-3xl leading-tight text-white sm:text-4xl">
                                {{ panelTitle }}
                            </h2>

                            <p class="mt-4 max-w-md text-sm leading-7 text-slate-300">
                                {{ panelText }}
                            </p>
                        </div>

                        <div class="space-y-4">
                            <div class="rounded-lg border border-white/10 bg-white/6 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-100/80">
                                    Portal Overview
                                </p>
                                <div class="mt-4 grid gap-3">
                                    <div
                                        v-for="highlight in panelHighlights"
                                        :key="highlight"
                                        class="flex items-start gap-3"
                                    >
                                        <span class="mt-1.5 h-2 w-2 rounded-full bg-amber-300"></span>
                                        <p class="text-sm leading-6 text-slate-200">
                                            {{ highlight }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-lg border border-amber-200/15 bg-amber-300/10 p-4">
                                <p class="text-sm leading-6 text-amber-50/95">
                                    {{ panelNote }}
                                </p>
                            </div>
                        </div>
                    </div>
                </aside>

                <section class="bg-white px-6 py-8 sm:px-8 lg:px-10 lg:py-10">
                    <div class="mx-auto max-w-xl">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">
                                    {{ eyebrow }}
                                </p>
                                <h1 class="mt-3 font-display text-3xl leading-tight text-slate-900 sm:text-[2.1rem]">
                                    {{ title }}
                                </h1>
                                <p class="mt-3 max-w-lg text-sm leading-7 text-slate-600">
                                    {{ description }}
                                </p>
                            </div>

                            <a
                                :href="switchHref"
                                class="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                            >
                                {{ switchLabel }}
                            </a>
                        </div>

                        <div class="mt-7 rounded-lg border border-slate-200 bg-slate-50/80 p-4 sm:p-5">
                            <slot />
                        </div>

                        <div class="mt-5 text-sm text-slate-500">
                            <span>{{ switchText }}</span>
                            <a :href="switchHref" class="ml-1 font-medium text-slate-900 transition hover:text-sky-700">
                                {{ switchLabel }}
                            </a>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</template>
