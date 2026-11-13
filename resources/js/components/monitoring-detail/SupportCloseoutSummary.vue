<script setup>
import SupportCloseoutBenefits from './SupportCloseoutBenefits.vue';

defineProps({ closeout: { type: Object, required: true }, nextStep: { type: Object, required: true } });

const icons = { completed: 'fa-solid fa-flag-checkered', withdrawn: 'fa-solid fa-door-open', ended_early: 'fa-regular fa-calendar-xmark' };
</script>

<template>
    <article data-support-closeout :data-closeout-kind="closeout.kind" class="overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm">
        <header class="flex flex-wrap items-start justify-between gap-3 px-5 py-5 sm:px-6">
            <div class="flex min-w-0 items-start gap-3.5">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i :class="icons[closeout.kind]" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">Scholarship closeout</p>
                    <h2 class="mt-1 break-words font-display text-xl font-bold text-slate-950 sm:text-2xl">{{ closeout.title }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ closeout.description }}</p>
                </div>
            </div>
            <p v-if="closeout.effective_label" class="inline-flex items-center gap-2 rounded-sm border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700"><i class="fa-regular fa-calendar" aria-hidden="true"></i> Effective {{ closeout.effective_label }}</p>
        </header>

        <section v-if="closeout.reason_label || closeout.note" class="border-t border-slate-200 px-5 py-4 sm:px-6">
            <h3 class="font-bold text-slate-950">Reason support ended</h3>
            <p v-if="closeout.reason_label" class="mt-1 text-sm font-semibold text-slate-700">{{ closeout.reason_label }}</p>
            <p v-if="closeout.note" class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-600">{{ closeout.note }}</p>
        </section>

        <SupportCloseoutBenefits :closeout="closeout" />

        <footer class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 px-5 py-5 sm:px-6">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">What happens next</p>
                <h3 class="mt-1 font-bold text-slate-950">{{ nextStep.title }}</h3>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ nextStep.description }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a v-if="closeout.withdrawal_href" :href="closeout.withdrawal_href" class="student-button student-button-secondary">Withdrawal request</a>
                <a v-if="closeout.outcome_href && nextStep.action?.href !== closeout.outcome_href" :href="closeout.outcome_href" class="student-button student-button-secondary">View decision</a>
                <a v-if="nextStep.action?.href" :href="nextStep.action.href" class="student-button student-button-primary">{{ nextStep.action.label }} <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
            </div>
        </footer>
    </article>
</template>
