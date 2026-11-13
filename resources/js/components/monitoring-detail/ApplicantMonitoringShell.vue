<script setup>
import { computed } from 'vue';
import ApplicantSidebar from '../ApplicantSidebar.vue';
import ApplicantRecordTabs from '../ApplicantRecordTabs.vue';

const props = defineProps({
    detail: { type: Object, required: true },
});

const statusClass = computed(() => ({
    positive: 'student-badge-positive',
    attention: 'student-badge-attention',
    critical: 'student-badge-negative',
}[props.detail.status?.tone] ?? 'bg-slate-100 text-slate-700'));
const hasSelectedRecord = computed(() => Boolean(props.detail.content?.selected));

function useLogoFallback(event) {
    const image = event.currentTarget;
    if (!image.src.endsWith('/uploads/scholarship-default.jpg')) image.src = '/uploads/scholarship-default.jpg';
    else image.classList.add('hidden');
}
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <a href="/dashboard/monitoring" class="inline-flex min-h-8 items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to Scholarship Support
                </a>

                <header data-monitoring-detail-header class="mt-3 flex flex-col gap-4 rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white px-4 py-4 shadow-sm sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                        <span class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-sm border border-slate-200 bg-slate-50 text-slate-500 sm:h-[68px] sm:w-[68px]">
                            <i class="fa-solid fa-graduation-cap text-xl" aria-hidden="true"></i>
                            <img :src="detail.identity.program.logo_url || detail.identity.program.image_url" alt="" class="absolute inset-0 h-full w-full bg-white object-contain p-1.5" @error="useLogoFallback">
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800">Scholarship Support</p>
                            <h1 class="mt-1 break-words font-display text-xl font-bold leading-tight text-slate-950 sm:text-2xl lg:text-3xl">{{ detail.identity.program.title }}</h1>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-slate-600 sm:text-sm">
                                <span class="font-semibold">{{ detail.identity.provider.name }}</span>
                                <span v-if="detail.identity.provider.verified" class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Verified provider
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center justify-between gap-2.5 lg:justify-end">
                        <span :class="['student-badge', statusClass]">{{ detail.status.label }}</span>
                        <a :href="detail.identity.application_href" class="student-button student-button-secondary">
                            Application record <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </a>
                    </div>
                </header>

                <ApplicantRecordTabs :items="detail.navigation" label="Support record sections" layout="grid" data-monitoring-detail-navigation />

                <section v-if="!['overview', 'history'].includes(detail.view) && !hasSelectedRecord" class="relative mt-4 overflow-hidden rounded-md border border-slate-300 bg-white px-4 py-3 shadow-sm sm:px-5" data-record-section-intro>
                    <span class="absolute inset-y-0 left-0 w-1 bg-amber-400" aria-hidden="true"></span>
                    <h2 class="font-display text-lg font-bold text-slate-950 sm:text-xl">{{ detail.page.title }}</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-600">{{ detail.page.description }}</p>
                </section>

                <slot></slot>
            </div>
        </section>
    </main>
</template>
