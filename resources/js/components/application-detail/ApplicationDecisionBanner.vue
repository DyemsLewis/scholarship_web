<script setup>
import { computed } from 'vue';

const props = defineProps({
    decision: {
        type: Object,
        required: true,
    },
});

const presentation = computed(() => {
    if (props.decision.tone === 'positive') {
        return {
            frame: 'border-emerald-200 bg-emerald-50/70',
            icon: 'bg-emerald-100 text-emerald-700',
            eyebrow: 'text-emerald-700',
            symbol: 'fa-solid fa-check',
        };
    }

    if (props.decision.tone === 'attention') {
        return {
            frame: 'border-amber-300 bg-amber-50/80',
            icon: 'bg-amber-300 text-slate-950',
            eyebrow: 'text-amber-800',
            symbol: 'fa-solid fa-arrow-right',
        };
    }

    if (props.decision.tone === 'negative') {
        return {
            frame: 'border-rose-200 bg-rose-50/70',
            icon: 'bg-rose-100 text-rose-700',
            eyebrow: 'text-rose-700',
            symbol: 'fa-solid fa-circle-xmark',
        };
    }

    if (props.decision.tone === 'muted') {
        return {
            frame: 'border-slate-300 bg-slate-50',
            icon: 'bg-slate-200 text-slate-600',
            eyebrow: 'text-slate-500',
            symbol: 'fa-solid fa-ban',
        };
    }

    return {
        frame: 'border-slate-300 bg-white',
        icon: 'bg-slate-100 text-slate-700',
        eyebrow: 'text-slate-500',
        symbol: props.decision.state === 'waiting' ? 'fa-regular fa-clock' : 'fa-solid fa-flag-checkered',
    };
});
</script>

<template>
    <section :class="['border px-4 py-4 shadow-sm sm:px-5', presentation.frame]">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-md', presentation.icon]">
                    <i :class="presentation.symbol" aria-hidden="true"></i>
                </span>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <p :class="['text-[10px] font-bold uppercase tracking-[0.16em]', presentation.eyebrow]">
                            {{ decision.eyebrow }}
                        </p>
                        <span v-if="decision.due_label" class="text-xs font-semibold text-slate-500">
                            {{ decision.due_label }}
                        </span>
                    </div>
                    <h2 class="mt-1 text-base font-bold text-slate-950 sm:text-lg">{{ decision.title }}</h2>
                    <p class="mt-1 max-w-4xl text-sm leading-5 text-slate-600">{{ decision.description }}</p>
                </div>
            </div>

            <a
                v-if="decision.action?.href"
                :href="decision.action.href"
                class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2"
            >
                {{ decision.action.label }}
                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
            </a>

            <span v-else class="inline-flex shrink-0 items-center gap-2 text-sm font-bold text-slate-600">
                <i class="fa-regular fa-clock text-slate-400" aria-hidden="true"></i>
                {{ decision.actor_label }}
            </span>
        </div>
    </section>
</template>
