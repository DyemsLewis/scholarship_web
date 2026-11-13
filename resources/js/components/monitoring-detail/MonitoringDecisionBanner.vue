<script setup>
import { computed } from 'vue';

const props = defineProps({
    decision: { type: Object, required: true },
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
    <section data-monitoring-next-action :class="['relative flex min-w-0 flex-col overflow-hidden rounded-md border bg-white shadow-sm', attention ? 'border-amber-300' : 'border-slate-300']" aria-labelledby="monitoring-next-step-title">
        <span :class="['absolute inset-y-0 left-0 w-1.5', attention ? 'bg-amber-400' : 'bg-slate-800']" aria-hidden="true"></span>
        <div class="flex flex-1 flex-col justify-center px-5 py-6 sm:px-7">
            <div class="flex items-center gap-2.5">
                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-sm', presentation.color]"><i :class="presentation.icon" aria-hidden="true"></i></span>
                <p :class="['text-[11px] font-bold uppercase tracking-[0.16em]', attention ? 'text-amber-800' : 'text-slate-600']">{{ decision.actor === 'applicant' ? 'Your next step' : 'What happens next' }}</p>
            </div>
            <h2 id="monitoring-next-step-title" class="mt-3 font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">{{ decision.title }}</h2>
            <p class="mt-2 max-w-xl text-sm leading-6 text-slate-600">{{ decision.description }}</p>
            <p v-if="decision.due_label" class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600">
                <i class="fa-regular fa-calendar text-slate-500" aria-hidden="true"></i>
                <span>{{ dateLabel }}:</span>
                <span class="font-bold text-slate-800">{{ decision.due_label }}</span>
            </p>
        </div>

        <footer v-if="decision.action?.href || decision.tone === 'waiting'" class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-7">
            <p v-if="decision.tone === 'waiting' && decision.state !== 'benefit_issue_open'" class="mr-auto text-xs font-semibold text-slate-600">With the {{ decision.actor_label.toLowerCase() }}</p>
            <a v-if="decision.action?.href" :href="decision.action.href" :class="['student-button', decision.actor === 'applicant' ? 'student-button-primary' : 'student-button-secondary']">
                {{ decision.action.label }} <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
            </a>
        </footer>
    </section>
</template>
