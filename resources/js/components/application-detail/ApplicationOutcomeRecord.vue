<script setup>
import { computed } from 'vue';

const props = defineProps({
    outcome: {
        type: Object,
        required: true,
    },
});

const result = computed(() => props.outcome.result ?? {});
const award = computed(() => result.value.award ?? {});
const resultPresentation = computed(() => ({
    positive: {
        frame: 'border-emerald-300',
        icon: 'bg-emerald-100 text-emerald-700',
        symbol: 'fa-solid fa-award',
    },
    negative: {
        frame: 'border-slate-300',
        icon: 'bg-slate-100 text-slate-600',
        symbol: 'fa-solid fa-circle-info',
    },
    attention: {
        frame: 'border-amber-300',
        icon: 'bg-amber-100 text-amber-800',
        symbol: 'fa-solid fa-hourglass-half',
    },
}[result.value.tone] ?? {
    frame: 'border-slate-300',
    icon: 'bg-slate-100 text-slate-600',
    symbol: 'fa-solid fa-flag-checkered',
}));
</script>

<template>
    <section id="application-result" :class="['scroll-mt-4 overflow-hidden rounded-md border bg-white shadow-sm', resultPresentation.frame]">
        <header class="flex flex-col gap-4 px-4 py-6 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex min-w-0 items-start gap-3">
                <span :class="['grid h-12 w-12 shrink-0 place-items-center rounded-sm', resultPresentation.icon]">
                    <i :class="resultPresentation.symbol" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Final decision</p>
                    <h2 class="mt-1 font-display text-2xl font-bold text-slate-950">{{ result.label }}</h2>
                </div>
            </div>
            <p v-if="result.recorded_at" class="text-xs font-semibold text-slate-500">Decision recorded {{ result.recorded_at }}</p>
        </header>

        <div v-if="result.feedback" class="border-t border-slate-200 px-4 py-4 sm:px-5">
            <p class="text-xs font-bold text-slate-500">Provider feedback</p>
            <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ result.feedback }}</p>
        </div>

        <dl v-if="result.waitlist_position" class="border-t border-slate-200 bg-amber-50/70 px-4 py-4 sm:px-5">
            <dt class="text-xs font-bold text-amber-800">Waitlist position</dt>
            <dd class="mt-1 text-lg font-bold text-slate-950">{{ result.waitlist_position }}</dd>
        </dl>

        <template v-if="award && result.key === 'selected'">
            <dl v-if="award.amount_label || award.support_period" class="flex flex-col border-t border-slate-200 bg-slate-50/80 sm:flex-row sm:divide-x sm:divide-slate-200">
                <div v-if="award.amount_label" class="flex-1 px-4 py-4 sm:px-5">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Recorded award</dt>
                    <dd class="mt-1 text-lg font-bold text-slate-950">{{ award.amount_label }}</dd>
                </div>
                <div v-if="award.support_period" class="flex-1 border-t border-slate-200 px-4 py-4 sm:border-t-0 sm:px-5">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Support period</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ award.support_period }}</dd>
                </div>
            </dl>

            <details v-if="award.benefits?.length" class="group border-t border-slate-200">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-3 hover:bg-slate-50 sm:px-5 [&::-webkit-details-marker]:hidden">
                    <div>
                        <h3 class="text-sm font-bold text-slate-950">Included support</h3>
                        <p class="mt-0.5 text-xs text-slate-500">{{ award.benefits.length }} {{ award.benefits.length === 1 ? 'benefit' : 'benefits' }} recorded</p>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                </summary>
                <ul class="divide-y divide-slate-200 border-t border-slate-200">
                    <li v-for="benefit in award.benefits" :key="`${benefit.type}-${benefit.title}`" class="flex items-start gap-3 px-4 py-3 sm:px-5">
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded bg-amber-100 text-amber-800">
                            <i class="fa-solid fa-gift text-xs" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-slate-950">{{ benefit.title }}</p>
                            <p class="mt-0.5 text-xs leading-5 text-slate-600">{{ benefit.display_summary || benefit.description || benefit.type_label }}</p>
                        </div>
                    </li>
                </ul>
            </details>
        </template>
    </section>
</template>
