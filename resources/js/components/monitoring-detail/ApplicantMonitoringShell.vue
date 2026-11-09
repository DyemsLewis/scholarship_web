<script setup>
import { computed } from 'vue';
import ApplicantSidebar from '../ApplicantSidebar.vue';
import MonitoringDecisionBanner from './MonitoringDecisionBanner.vue';
import ApplicantRecordTabs from '../ApplicantRecordTabs.vue';

const props = defineProps({
    detail: {
        type: Object,
        required: true,
    },
});

const statusClass = computed(() => ({
    positive: 'student-badge-positive',
    attention: 'student-badge-attention',
    critical: 'student-badge-negative',
}[props.detail.status?.tone] ?? ''));
const hasSelectedRecord = computed(() => Boolean(props.detail.content?.selected));

const snapshot = computed(() => [
    {
        key: 'support',
        label: 'Support type',
        value: props.detail.status?.mode_label ?? 'Support record',
        note: props.detail.status?.label,
    },
    {
        key: 'check-ins',
        label: 'Check-ins',
        value: `${props.detail.summary?.check_ins?.open ?? 0} open`,
        note: props.detail.summary?.check_ins?.next_due_label
            ? `Next due ${props.detail.summary.check_ins.next_due_label}`
            : `${props.detail.summary?.check_ins?.completed ?? 0} completed`,
    },
    {
        key: 'benefits',
        label: 'Benefit releases',
        value: `${props.detail.summary?.benefits?.total ?? 0} recorded`,
        note: props.detail.summary?.benefits?.next_release_label
            ? `Next ${props.detail.summary.benefits.next_release_label}`
            : 'No upcoming release',
    },
]);

function hideBrokenImage(event) {
    event.currentTarget.classList.add('hidden');
}
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <a href="/dashboard/monitoring" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to Scholarship Support
                </a>

                <header data-monitoring-detail-header class="mt-4 overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm">
                    <div class="grid lg:min-h-[10rem] lg:grid-cols-[minmax(0,1.45fr)_minmax(19rem,0.55fr)]">
                        <div class="flex flex-col justify-center px-4 py-4 sm:px-6 sm:py-5">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Scholarship Support</p>
                                <span :class="['student-badge', statusClass]">
                                    {{ detail.status.label }}
                                </span>
                            </div>

                            <h1 class="mt-2 max-w-3xl font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">
                                {{ detail.identity.program.title }}
                            </h1>

                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-slate-600">
                                <span class="font-bold text-slate-800">{{ detail.identity.provider.name }}</span>
                                <span v-if="detail.identity.provider.verified" class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-700">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                    Verified provider
                                </span>
                            </div>

                            <div class="mt-3">
                                <a :href="detail.identity.application_href" class="student-button student-button-secondary">
                                    Application record
                                    <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>

                        <figure class="relative order-first h-32 overflow-hidden bg-slate-900 sm:h-40 lg:order-last lg:h-auto">
                            <img :src="'/images/filipino-students-studying.jpg'" alt="Filipino students studying together" class="absolute inset-0 h-full w-full object-cover object-center">
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/85 via-slate-950/10 to-transparent"></div>
                            <figcaption class="absolute inset-x-0 bottom-0 flex items-center gap-3 p-4">
                                <span class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-sm bg-white p-2 shadow-xl ring-1 ring-white/60">
                                    <i class="fa-solid fa-graduation-cap text-lg text-slate-400" aria-hidden="true"></i>
                                    <img
                                        :src="detail.identity.program.image_url"
                                        :alt="`${detail.identity.program.title} mark`"
                                        class="absolute inset-0 h-full w-full object-contain p-2"
                                        @error="hideBrokenImage"
                                    >
                                </span>
                                <div class="min-w-0 text-white">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-300">Support record</p>
                                    <p class="mt-1 truncate text-sm font-bold">{{ detail.status.mode_label }}</p>
                                </div>
                            </figcaption>
                        </figure>
                    </div>

                    <dl class="grid grid-cols-2 border-t border-slate-200 bg-slate-50/80 sm:grid-cols-3" data-record-snapshot>
                        <div
                            v-for="(item, index) in snapshot"
                            :key="item.key"
                            :class="['min-w-0 border-slate-200 px-3 py-2.5 sm:px-5 sm:py-3', index === 0 ? 'col-span-2 sm:col-span-1' : 'border-t sm:border-t-0', index === 1 ? 'sm:border-l' : '', index === 2 ? 'border-l' : '']"
                        >
                            <dt class="student-detail-label">{{ item.label }}</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ item.value }}</dd>
                            <dd v-if="item.key !== 'support' && item.note" class="mt-0.5 text-xs leading-5 text-slate-600">{{ item.note }}</dd>
                        </div>
                    </dl>
                </header>

                <MonitoringDecisionBanner v-if="detail.view === 'overview'" class="mt-4" :decision="detail.decision" />

                <ApplicantRecordTabs :items="detail.navigation" label="Support record sections" layout="grid" data-monitoring-detail-navigation />

                <section v-if="detail.view !== 'overview' && !hasSelectedRecord" class="relative mt-4 overflow-hidden rounded-md border border-slate-300 bg-white px-4 py-3 shadow-sm sm:px-5" data-record-section-intro>
                    <span class="absolute inset-y-0 left-0 w-1 bg-amber-400" aria-hidden="true"></span>
                    <h2 class="font-display text-lg font-bold text-slate-950 sm:text-xl">{{ detail.page.title }}</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-600">{{ detail.page.description }}</p>
                </section>

                <slot></slot>
            </div>
        </section>
    </main>
</template>
