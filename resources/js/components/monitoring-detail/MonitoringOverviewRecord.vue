<script setup>
import { computed } from 'vue';

const props = defineProps({
    content: {
        type: Object,
        required: true,
    },
    navigation: {
        type: Array,
        required: true,
    },
});

const plan = computed(() => props.content.plan ?? {});
const checkInsHref = computed(() => props.navigation.find((item) => item.key === 'check-ins')?.href);
const benefitsHref = computed(() => props.navigation.find((item) => item.key === 'benefits')?.href);
const historyHref = computed(() => props.navigation.find((item) => item.key === 'history')?.href);

const supportPeriod = computed(() => {
    if (plan.value.starts_label && plan.value.ends_label) {
        return `${plan.value.starts_label} to ${plan.value.ends_label}`;
    }

    return plan.value.ends_label ? `Until ${plan.value.ends_label}` : 'Not specified';
});
</script>

<template>
    <div data-monitoring-overview class="mt-4 space-y-4">
        <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="flex items-start gap-3 border-b border-slate-200 px-4 py-4 sm:px-6">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                    <i class="fa-solid fa-shield-heart" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Support plan</p>
                    <h2 class="mt-1 font-display text-lg font-bold text-slate-950">{{ plan.mode_label }}</h2>
                </div>
            </header>

            <dl class="grid bg-slate-50/70 sm:grid-cols-3">
                <div class="px-4 py-4 sm:px-6">
                    <dt class="text-xs font-semibold text-slate-500">Review frequency</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ plan.frequency_label || 'No recurring review' }}</dd>
                </div>
                <div class="border-t border-slate-200 px-4 py-4 sm:border-l sm:border-t-0 sm:px-6">
                    <dt class="text-xs font-semibold text-slate-500">Support period</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ supportPeriod }}</dd>
                </div>
                <div class="border-t border-slate-200 px-4 py-4 sm:border-l sm:border-t-0 sm:px-6">
                    <dt class="text-xs font-semibold text-slate-500">Recipient agreement</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ plan.agreement_accepted ? 'Accepted' : 'Needs review' }}</dd>
                </div>
            </dl>

            <p v-if="plan.instructions" class="border-t border-slate-200 px-4 py-3 text-sm leading-6 text-slate-600 sm:px-6">
                {{ plan.instructions }}
            </p>
        </section>

        <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="border-b border-slate-200 px-4 py-4 sm:px-6">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Coming up</p>
                <h2 class="mt-1 font-display text-lg font-bold text-slate-950">Your support schedule</h2>
            </header>

            <div class="divide-y divide-slate-200">
                <article v-if="content.next_check_in" class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-700">
                        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Next check-in</p>
                        <h3 class="mt-1 text-sm font-bold text-slate-950">{{ content.next_check_in.title }}</h3>
                        <p class="mt-1 text-xs text-slate-500">{{ content.next_check_in.period_label }}<span v-if="content.next_check_in.due_label"> · Due {{ content.next_check_in.due_label }}</span></p>
                    </div>
                    <a :href="content.next_check_in.href || checkInsHref" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-sm border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        View check-in
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </article>

                <article v-if="content.next_release" class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-700">
                        <i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Next benefit release</p>
                        <h3 class="mt-1 text-sm font-bold text-slate-950">{{ content.next_release.title }}</h3>
                        <p class="mt-1 text-xs text-slate-500">{{ content.next_release.release_label || content.next_release.status_label }}</p>
                    </div>
                    <a :href="content.next_release.href || benefitsHref" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-sm border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        View release
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </article>

                <article v-if="content.latest_support_decision" class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-700">
                        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Latest support update</p>
                        <h3 class="mt-1 text-sm font-bold text-slate-950">{{ content.latest_support_decision.decision_label }}</h3>
                        <p class="mt-1 text-xs text-slate-500">{{ content.latest_support_decision.effective_label || content.latest_support_decision.decided_at }}</p>
                    </div>
                    <a :href="content.latest_support_decision.href || historyHref" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-sm border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        View outcome
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </article>

                <div v-if="!content.next_check_in && !content.next_release && !content.latest_support_decision" class="px-5 py-8 text-center">
                    <span class="mx-auto grid h-10 w-10 place-items-center rounded-sm bg-emerald-100 text-emerald-700">
                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                    </span>
                    <h3 class="mt-3 text-sm font-bold text-slate-950">Nothing is scheduled right now</h3>
                    <p class="mt-1 text-sm text-slate-500">New check-ins and benefit releases will appear here.</p>
                </div>
            </div>
        </section>
    </div>
</template>
