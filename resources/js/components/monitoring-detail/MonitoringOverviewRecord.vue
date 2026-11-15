<script setup>
import { computed } from 'vue';
import MonitoringDecisionBanner from './MonitoringDecisionBanner.vue';
import SupportCloseoutSummary from './SupportCloseoutSummary.vue';

const props = defineProps({
    content: { type: Object, required: true },
});

const plan = computed(() => props.content.plan);
const decision = computed(() => props.content.next_action);
const schedule = computed(() => props.content.schedule ?? []);
const latestUpdate = computed(() => {
    const update = props.content.latest_support_decision;
    return update && update.href !== decision.value.action?.href ? update : null;
});
const showPlan = computed(() => plan.value.mode !== 'not_scheduled'
    || plan.value.agreement_accepted || plan.value.agreement?.href);
const supportPeriod = computed(() => {
    const { starts_label: start, ends_label: end } = plan.value;
    if (start && end) return start + ' to ' + end;
    if (start) return 'From ' + start;
    return end ? 'Until ' + end : null;
});
</script>

<template>
    <div data-monitoring-overview class="mt-4 space-y-4">
        <SupportCloseoutSummary v-if="content.closeout" :closeout="content.closeout" :next-step="decision" />
        <MonitoringDecisionBanner v-else :decision="decision" illustrated>
            <template #summary>
                <dl data-overview-summary :class="['grid divide-y divide-slate-200 border-t border-slate-200 bg-white sm:divide-x sm:divide-y-0', supportPeriod ? 'sm:grid-cols-3' : 'sm:grid-cols-2']">
                    <div class="min-w-0 px-5 py-4 sm:px-6"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Support type</dt><dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ plan.mode_label }}</dd></div>
                    <div v-if="supportPeriod" class="min-w-0 px-5 py-4 sm:px-6"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Support period</dt><dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ supportPeriod }}</dd></div>
                    <div class="min-w-0 px-5 py-4 sm:px-6"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Recipient agreement</dt><dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ plan.agreement.label }}</dd></div>
                </dl>
            </template>
        </MonitoringDecisionBanner>

            <section v-if="!content.closeout && plan.agreement_accepted && decision.state !== 'not_active'" data-overview-schedule class="min-w-0 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="overview-schedule-title">
                <header class="flex items-center gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-white text-slate-700 ring-1 ring-slate-200"><i class="fa-regular fa-calendar text-sm leading-none" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="overview-schedule-title" class="text-base font-bold text-slate-950">Coming up next</h2>
                        <p class="mt-0.5 text-xs text-slate-600">{{ schedule.length ? 'Other published dates for this scholarship.' : 'Your main next step is shown above.' }}</p>
                    </div>
                </header>
                <ul v-if="schedule.length" class="divide-y divide-slate-200">
                    <li v-for="item in schedule" :key="item.href">
                        <a :href="item.href" class="group flex flex-col gap-3 px-5 py-4 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-500 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-6">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i :class="[item.section === 'benefits' ? 'fa-solid fa-gift' : 'fa-regular fa-calendar-check', 'text-sm leading-none']" aria-hidden="true"></i></span>
                                <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-amber-800">{{ item.kind }}</p><p class="mt-1 break-words text-sm font-bold text-slate-950">{{ item.title }}</p><p v-if="item.context" class="mt-1 break-words text-xs leading-5 text-slate-600">{{ item.context }}</p></div>
                            </div>
                            <div class="flex min-w-0 items-center justify-between gap-4 border-t border-slate-100 pt-2.5 sm:shrink-0 sm:border-0 sm:pt-0"><p class="text-xs font-bold text-slate-800">{{ item.date_label }}</p><span class="grid h-8 w-8 shrink-0 place-items-center rounded-sm border border-slate-200 bg-white text-slate-600 group-hover:border-slate-400"><i class="fa-solid fa-arrow-right text-xs leading-none" aria-hidden="true"></i></span>
                            </div>
                        </a>
                    </li>
                </ul>
                <div v-else data-overview-schedule-empty class="flex items-start gap-3 px-5 py-5 sm:px-6"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-500"><i class="fa-regular fa-calendar leading-none" aria-hidden="true"></i></span><div class="min-w-0"><h3 class="text-sm font-bold text-slate-800">No additional dates published</h3><p class="mt-1 text-sm leading-6 text-slate-600">{{ plan.mode === 'one_time' ? 'Delivery instructions and release updates remain available in Benefit releases.' : 'The provider will post additional check-in and benefit dates here when they are ready.' }}</p></div></div>
            </section>

        <section v-if="latestUpdate && !content.closeout" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-slate-300 bg-white px-5 py-4 shadow-sm sm:px-6" aria-labelledby="overview-update-title">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-700"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <p id="overview-update-title" class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Latest support update</p>
                    <p class="mt-0.5 text-sm font-bold text-slate-950">{{ latestUpdate.decision_label }}</p>
                    <p v-if="latestUpdate.effective_label || latestUpdate.decided_at" class="mt-0.5 text-xs text-slate-600">{{ latestUpdate.effective_label || latestUpdate.decided_at }}</p>
                </div>
            </div>
            <a :href="latestUpdate.href" class="student-button student-button-secondary">View outcome <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
        </section>

        <details v-if="showPlan" data-overview-plan class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 bg-slate-50 px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-500 sm:px-6 [&::-webkit-details-marker]:hidden">
                <span class="flex min-w-0 items-center gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-700"><i class="fa-solid fa-file-signature" aria-hidden="true"></i></span>
                    <span>
                        <span class="block text-base font-bold text-slate-950">Support plan and agreement</span>
                        <span class="mt-0.5 block text-xs text-slate-600">{{ plan.mode_label }}</span>
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2 text-xs font-bold text-slate-600"><span class="hidden sm:inline group-open:hidden">View details</span><i class="fa-solid fa-chevron-down transition group-open:rotate-180" aria-hidden="true"></i></span>
            </summary>
            <div class="space-y-4 border-t border-slate-200 bg-slate-50/60 px-5 py-5 sm:px-6">
                <dl v-if="plan.frequency_label || content.closeout" class="grid gap-4 sm:grid-cols-3">
                    <div v-if="plan.frequency_label">
                        <dt class="text-xs font-semibold text-slate-600">Review frequency</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ plan.frequency_label }}</dd>
                    </div>
                    <div v-if="content.closeout && supportPeriod">
                        <dt class="text-xs font-semibold text-slate-600">Support period</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ supportPeriod }}</dd>
                    </div>
                    <div v-if="content.closeout">
                        <dt class="text-xs font-semibold text-slate-600">Recipient agreement</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ plan.agreement.label }}</dd>
                    </div>
                </dl>
                <p v-if="plan.instructions" class="max-w-3xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ plan.instructions }}</p>
                <a v-if="plan.agreement.href" :href="plan.agreement.href" class="student-button student-button-secondary">Open agreement record <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
            </div>
        </details>
    </div>
</template>
