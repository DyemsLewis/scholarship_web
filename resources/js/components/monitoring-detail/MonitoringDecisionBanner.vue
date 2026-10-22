<script setup>
import { computed } from 'vue';

const props = defineProps({
    decision: {
        type: Object,
        required: true,
    },
});

const presentation = computed(() => ({
    attention: {
        frame: 'border-amber-300',
        accent: 'bg-amber-400',
        icon: 'bg-amber-300 text-slate-950',
        eyebrow: 'text-amber-800',
        symbol: 'fa-solid fa-arrow-right',
    },
    positive: {
        frame: 'border-emerald-300',
        accent: 'bg-emerald-500',
        icon: 'bg-emerald-100 text-emerald-700',
        eyebrow: 'text-emerald-700',
        symbol: 'fa-solid fa-check',
    },
    scheduled: {
        frame: 'border-sky-300',
        accent: 'bg-sky-500',
        icon: 'bg-sky-100 text-sky-700',
        eyebrow: 'text-sky-700',
        symbol: 'fa-regular fa-calendar',
    },
    critical: {
        frame: 'border-rose-300',
        accent: 'bg-rose-500',
        icon: 'bg-rose-100 text-rose-700',
        eyebrow: 'text-rose-700',
        symbol: 'fa-solid fa-triangle-exclamation',
    },
    waiting: {
        frame: 'border-slate-300',
        accent: 'bg-slate-950',
        icon: 'bg-slate-100 text-slate-700',
        eyebrow: 'text-slate-500',
        symbol: 'fa-regular fa-clock',
    },
}[props.decision.tone] ?? {
    frame: 'border-slate-300',
    accent: 'bg-slate-500',
    icon: 'bg-slate-100 text-slate-700',
    eyebrow: 'text-slate-500',
    symbol: 'fa-solid fa-circle-info',
}));
</script>

<template>
    <section data-monitoring-next-action :class="['relative overflow-hidden rounded-md border bg-white px-4 py-5 shadow-sm sm:px-6', presentation.frame]">
        <span :class="['absolute inset-y-0 left-0 w-1.5', presentation.accent]" aria-hidden="true"></span>
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-sm', presentation.icon]">
                    <i :class="presentation.symbol" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <p :class="['text-[10px] font-bold uppercase tracking-[0.16em]', presentation.eyebrow]">{{ decision.eyebrow }}</p>
                        <span v-if="decision.due_label" class="text-xs font-semibold text-slate-500">{{ decision.due_label }}</span>
                    </div>
                    <h2 class="mt-1 font-display text-lg font-bold text-slate-950 sm:text-xl">{{ decision.title }}</h2>
                    <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-600">{{ decision.description }}</p>
                </div>
            </div>

            <a v-if="decision.action?.href" :href="decision.action.href" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-sm bg-slate-950 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                {{ decision.action.label }}
                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
            </a>

            <div v-else-if="decision.state !== 'no_action'" class="shrink-0 border-t border-slate-200 pt-3 sm:border-l sm:border-t-0 sm:pl-4 sm:pt-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Waiting on</p>
                <span class="mt-1 inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                    <i class="fa-regular fa-clock text-slate-400" aria-hidden="true"></i>
                    {{ decision.actor_label }}
                </span>
            </div>
        </div>
    </section>
</template>
