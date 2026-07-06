<script setup>
import SiteFooter from './SiteFooter.vue';
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
    <main class="relative min-h-screen overflow-hidden bg-slate-100 text-slate-900">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_16%_18%,_rgba(252,211,77,0.16),_transparent_30%),radial-gradient(circle_at_86%_12%,_rgba(8,20,38,0.10),_transparent_28%),linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)]"></div>
        <div class="absolute inset-0 bg-[linear-gradient(120deg,_rgba(255,255,255,0.56)_0%,_transparent_34%,_rgba(15,23,42,0.04)_100%)]"></div>

        <div class="relative z-10 bg-slate-900">
            <SiteNavbar variant="transparent" show-icons />
        </div>

        <div class="relative z-10 mx-auto flex min-h-[calc(100vh-4.5rem)] max-w-6xl items-center px-4 py-8 sm:px-6 lg:px-8">
            <div class="grid w-full overflow-hidden rounded-lg border border-slate-300/80 bg-slate-950 shadow-[0_28px_90px_rgba(15,23,42,0.24)] lg:grid-cols-[0.92fr_1.08fr]">
                <aside class="relative border-b border-white/10 bg-slate-950 px-6 py-8 text-white sm:px-8 lg:border-b-0 lg:border-r lg:px-10 lg:py-10">
                    <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(250,204,21,0.18),_transparent_32%),linear-gradient(180deg,_rgba(15,23,42,0.9),_rgba(15,23,42,0.84))]"></div>
                    <div class="absolute inset-y-0 right-0 hidden w-px bg-white/10 lg:block"></div>

                    <div class="relative flex h-full flex-col justify-between gap-8">
                        <div>
                            <div class="inline-flex items-center gap-2 rounded-md border border-amber-200/25 bg-amber-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.22em] text-amber-100">
                                <i class="fa-solid fa-award"></i>
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
                            <div class="rounded-lg border border-white/10 bg-slate-950/35 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-100/80">
                                    Portal Overview
                                </p>
                                <div class="mt-4 grid gap-3">
                                    <div
                                        v-for="highlight in panelHighlights"
                                        :key="highlight"
                                        class="flex items-start gap-3"
                                    >
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-amber-300 text-[0.65rem] text-slate-950">
                                            <i class="fa-solid fa-check"></i>
                                        </span>
                                        <p class="text-sm leading-6 text-slate-200">
                                            {{ highlight }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-lg border border-amber-200/20 bg-amber-300/10 p-4">
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
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700">
                                    <i class="fa-solid fa-circle-nodes mr-2"></i>
                                    {{ eyebrow }}
                                </p>
                                <h1 class="mt-3 font-display text-3xl leading-tight text-slate-950 sm:text-[2.1rem]">
                                    {{ title }}
                                </h1>
                                <p class="mt-3 max-w-lg text-sm leading-7 text-slate-600">
                                    {{ description }}
                                </p>
                            </div>

                            <a
                                :href="switchHref"
                                class="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                            >
                                <i class="fa-solid fa-arrow-right-to-bracket mr-2"></i>
                                {{ switchLabel }}
                            </a>
                        </div>

                        <div class="mt-7 rounded-lg border border-slate-200 bg-slate-50/90 p-4 shadow-sm sm:p-5">
                            <slot />
                        </div>

                        <div class="mt-5 text-sm text-slate-500">
                            <span>{{ switchText }}</span>
                            <a :href="switchHref" class="ml-1 font-semibold text-slate-950 transition hover:text-amber-700">
                                {{ switchLabel }}
                            </a>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <div class="relative z-10">
            <SiteFooter variant="dark" />
        </div>
    </main>
</template>
