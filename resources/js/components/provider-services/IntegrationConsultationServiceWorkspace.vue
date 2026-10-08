<script setup>
import { computed } from 'vue';

const props = defineProps({
    purchase: { type: Object, required: true },
});

const steps = computed(() => props.purchase.milestones ?? []);
const phaseDescriptions = [
    'Document the current tools, records, and handoffs.',
    'Connect the existing process to portal workflows.',
    'Define practical implementation actions and constraints.',
];
</script>

<template>
    <section class="overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
        <header class="flex items-start gap-3 border-b border-slate-200 p-4 sm:px-5">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-sky-100 text-sky-800">
                <i class="fa-solid fa-diagram-project" aria-hidden="true"></i>
            </span>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Integration workspace</p>
                <h2 class="mt-1 text-lg font-bold text-slate-950">Process-to-portal map</h2>
                <p class="mt-1 text-sm text-slate-500">Move from discovery to an implementation-ready recommendation.</p>
            </div>
        </header>

        <ol class="divide-y divide-slate-200">
            <li v-for="(step, index) in steps" :key="step.id" class="flex gap-4 px-4 py-4 sm:px-5">
                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md text-xs font-bold', step.completed ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-950 text-white']">
                    <i v-if="step.completed" class="fa-solid fa-check" aria-hidden="true"></i>
                    <span v-else>{{ index + 1 }}</span>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-bold text-slate-950">{{ step.label }}</p>
                        <span :class="['text-xs font-bold', step.completed ? 'text-emerald-700' : 'text-slate-500']">{{ step.completed ? 'Complete' : 'Pending' }}</span>
                    </div>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ phaseDescriptions[index] || 'Complete this consultation phase.' }}</p>
                </div>
            </li>
        </ol>

        <div class="border-t border-slate-200 bg-slate-50 px-4 py-4 sm:px-5">
            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Implementation target</p>
            <p class="mt-1.5 text-sm leading-6 text-slate-700">{{ purchase.requested_outcome || purchase.request_summary || 'A mapped workflow with clear implementation recommendations.' }}</p>
        </div>
    </section>
</template>
