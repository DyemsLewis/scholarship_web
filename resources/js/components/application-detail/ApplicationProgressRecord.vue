<script setup>
import { computed } from 'vue';

const props = defineProps({
    progress: {
        type: Object,
        required: true,
    },
});

const progressWidth = computed(() => `${Math.min(100, Math.max(0, Number(props.progress.percent) || 0))}%`);

function stepIcon(step) {
    if (step.status === 'not_passed') {
        return 'fa-solid fa-xmark';
    }

    if (['passed', 'complete', 'completed'].includes(step.status)) {
        return 'fa-solid fa-check';
    }

    if (step.current) {
        return 'fa-solid fa-arrow-right';
    }

    return String(step.position);
}

function stepClass(step) {
    if (step.status === 'not_passed') {
        return 'border-rose-200 bg-rose-100 text-rose-700';
    }

    if (['passed', 'complete', 'completed'].includes(step.status)) {
        return 'border-slate-950 bg-slate-950 text-white';
    }

    if (step.current) {
        return 'border-amber-400 bg-amber-300 text-slate-950';
    }

    return 'border-slate-200 bg-slate-100 text-slate-500';
}
</script>

<template>
    <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-4 py-5 sm:px-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Your application path</p>
                    <h2 class="mt-1 font-display text-xl font-bold text-slate-950">{{ progress.current_stage_label }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ progress.completed_steps }} of {{ progress.total_steps }} stages complete</p>
                </div>
                <div class="flex items-baseline gap-2">
                    <p class="text-3xl font-bold leading-none text-slate-950">{{ progress.percent }}%</p>
                    <span class="text-xs font-semibold text-slate-500">complete</span>
                </div>
            </div>

            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
                <div class="h-full rounded-full bg-amber-400 transition-all" :style="{ width: progressWidth }"></div>
            </div>
        </div>

        <ol class="grid gap-px bg-slate-200 lg:grid-flow-col lg:auto-cols-fr">
            <li
                v-for="step in progress.steps"
                :key="step.key"
                :class="['relative flex min-w-0 items-center gap-3 bg-white px-4 py-4', step.current ? 'after:absolute after:inset-x-0 after:bottom-0 after:h-1 after:bg-amber-400' : '']"
            >
                <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded border text-[11px] font-bold', stepClass(step)]">
                    <i v-if="String(stepIcon(step)).startsWith('fa-')" :class="stepIcon(step)" aria-hidden="true"></i>
                    <span v-else>{{ stepIcon(step) }}</span>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold text-slate-900">{{ step.label }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ step.current ? 'Current stage' : (step.result_label || (['pending', 'upcoming'].includes(step.status) ? 'Not started' : 'Complete')) }}
                    </p>
                </div>
            </li>
        </ol>
    </section>
</template>
