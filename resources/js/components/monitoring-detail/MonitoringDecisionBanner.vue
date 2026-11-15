<script setup>
import { computed } from 'vue';

const props = defineProps({
    decision: { type: Object, required: true },
    illustrated: { type: Boolean, default: false },
});

const attention = computed(() => props.decision.tone === 'attention');
const presentation = computed(() => ({
    attention: { icon: 'fa-solid fa-arrow-right', color: 'bg-amber-100 text-amber-900' },
    scheduled: { icon: 'fa-regular fa-calendar-check', color: 'bg-amber-100 text-amber-900' },
    positive: { icon: 'fa-solid fa-check', color: 'bg-emerald-100 text-emerald-800' },
    waiting: { icon: 'fa-regular fa-clock', color: 'bg-slate-100 text-slate-700' },
}[props.decision.tone] ?? { icon: 'fa-solid fa-circle-info', color: 'bg-slate-100 text-slate-700' }));
const dateLabel = computed(() => ({
    benefit_confirmation_required: 'Release date',
    benefit_payout_required: 'Scheduled release',
    benefit_scheduled: 'Scheduled release',
    check_in_scheduled: 'Appointment',
    check_in_required: 'Deadline',
    outcome_response_required: 'Effective date',
}[props.decision.state] ?? 'Record date'));
</script>

<template>
    <section data-monitoring-next-action :class="['relative flex min-w-0 flex-col overflow-hidden rounded-md border bg-white shadow-sm', illustrated ? 'border-slate-300 border-t-4 border-t-amber-400' : attention ? 'border-amber-300' : 'border-slate-300']" aria-labelledby="monitoring-next-step-title">
        <span v-if="!illustrated" :class="['absolute inset-y-0 left-0 w-1.5', attention ? 'bg-amber-400' : 'bg-slate-800']" aria-hidden="true"></span>
        <div :class="['relative isolate flex flex-1 flex-col justify-center px-5 py-6 sm:px-7', illustrated ? 'overflow-hidden bg-portal-navy text-white lg:min-h-56 lg:py-7' : '']">
            <div v-if="illustrated" aria-hidden="true" class="pointer-events-none absolute inset-y-0 right-0 hidden w-[42%] md:block"><img :src="'/images/filipino-students-group-study.jpg'" alt="" class="h-full w-full object-cover opacity-65"><div class="absolute inset-0 bg-linear-to-r from-portal-navy via-portal-navy/50 to-portal-navy/15"></div></div>
            <div :class="['relative z-10 min-w-0', illustrated ? 'md:w-[64%]' : '']">
            <div class="flex items-center gap-2.5">
                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-sm', illustrated ? 'border border-white/15 bg-white/10 text-amber-300' : presentation.color]"><i :class="presentation.icon" class="leading-none" aria-hidden="true"></i></span>
                <p :class="['text-[11px] font-bold uppercase tracking-[0.16em]', illustrated ? 'text-amber-300' : attention ? 'text-amber-800' : 'text-slate-600']">{{ decision.actor === 'applicant' ? 'Your next step' : 'What happens next' }}</p>
            </div>
            <h2 id="monitoring-next-step-title" :class="['mt-3 break-words font-display text-2xl font-bold leading-tight sm:text-3xl', illustrated ? 'text-white' : 'text-slate-950']">{{ decision.title }}</h2>
            <p :class="['mt-2 max-w-xl text-sm leading-6', illustrated ? 'text-slate-200' : 'text-slate-600']">{{ decision.description }}</p>
            <p v-if="decision.due_label" :class="['mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs', illustrated ? 'text-slate-200' : 'text-slate-600']">
                <i class="fa-regular fa-calendar leading-none" aria-hidden="true"></i>
                <span>{{ dateLabel }}:</span>
                <span :class="['font-bold', illustrated ? 'text-white' : 'text-slate-800']">{{ decision.due_label }}</span>
            </p>
            </div>
        </div>

        <footer v-if="decision.action?.href || decision.tone === 'waiting'" class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-7">
            <p v-if="decision.tone === 'waiting' && decision.state !== 'benefit_issue_open'" class="mr-auto text-xs font-semibold text-slate-600">With the {{ decision.actor_label.toLowerCase() }}</p>
            <a v-if="decision.action?.href" :href="decision.action.href" :class="['student-button', decision.actor === 'applicant' ? 'student-button-primary' : 'student-button-secondary']">
                {{ decision.action.label }} <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
            </a>
        </footer>
        <slot name="summary"></slot>
    </section>
</template>
