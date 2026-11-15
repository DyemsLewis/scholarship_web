<script setup>
import { computed } from 'vue';

const props = defineProps({
    progress: {
        type: Object,
        required: true,
    },
    description: { type: String, default: '' },
});

const percentage = computed(() => Math.min(100, Math.max(0, Number(props.progress.percent) || 0)));
const progressWidth = computed(() => `${percentage.value}%`);

function stepLabel(step) {
    if (step.result_label) return step.result_label;
    if (step.status === 'not_passed') return 'Not passed';
    if (['passed', 'complete', 'completed'].includes(step.status)) return 'Complete';
    if (step.current) return 'Current stage';
    if (['pending', 'upcoming'].includes(step.status)) return 'Not started';
    return 'Awaiting update';
}

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
    <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="application-progress-title" data-application-progress>
        <div class="border-b border-slate-200 bg-slate-50/70 px-5 py-5 sm:px-6">
            <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Your application path</p>
                    <h2 id="application-progress-title" class="mt-1 font-display text-xl font-bold text-slate-950 sm:text-2xl">Your selection journey</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ progress.completed_steps }} of {{ progress.total_steps }} stages complete</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-3xl font-bold leading-none tracking-tight text-slate-950">{{ percentage }}<span class="ml-0.5 text-lg">%</span></p>
                    <p class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-slate-500">Complete</p>
                </div>
            </div>

            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Application stages completed" :aria-valuenow="percentage" :aria-valuemin="0" :aria-valuemax="100">
                <div class="h-full rounded-full bg-amber-400" :style="{ width: progressWidth }"></div>
            </div>
        </div>

        <ol class="grid divide-y divide-slate-200 xl:grid-flow-col xl:auto-cols-fr xl:divide-x xl:divide-y-0">
            <li
                v-for="step in progress.steps"
                :key="step.key"
                :class="['relative flex min-w-0 items-center gap-3 px-5 py-4 xl:flex-col xl:items-start xl:gap-3 xl:px-5 xl:py-5', step.status === 'not_passed' ? 'bg-rose-50/60' : step.current ? 'bg-amber-50/70' : 'bg-white']"
                :aria-current="step.current ? 'step' : undefined"
                :data-application-step="step.key"
            >
                <span :class="['grid size-8 shrink-0 place-items-center rounded-sm border text-xs font-bold', stepClass(step)]">
                    <i v-if="String(stepIcon(step)).startsWith('fa-')" :class="[stepIcon(step), 'block leading-none']" aria-hidden="true"></i>
                    <span v-else>{{ stepIcon(step) }}</span>
                </span>
                <div class="min-w-0">
                    <p class="break-words text-sm font-bold leading-5 text-slate-900">{{ step.label }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ stepLabel(step) }}</p>
                </div>
            </li>
        </ol>
        <div v-if="description" class="border-t border-slate-200 px-5 py-4 sm:px-6" data-application-stage-context>
            <p class="text-xs font-bold text-slate-800">{{ progress.current_stage_label }}</p>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ description }}</p>
        </div>
    </section>
</template>
