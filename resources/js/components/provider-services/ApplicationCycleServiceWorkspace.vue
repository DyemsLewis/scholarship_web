<script setup>
import { computed } from 'vue';

const props = defineProps({
    purchase: { type: Object, required: true },
});

const steps = computed(() => props.purchase.milestones ?? []);
const completed = computed(() => steps.value.filter((step) => step.completed).length);
</script>

<template>
    <section class="overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between sm:px-5">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                    <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Application cycle workspace</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950">Cycle operations</h2>
                    <p class="mt-1 text-sm text-slate-500">Keep the selected workflow areas organized for one active cycle.</p>
                </div>
            </div>
            <span class="w-fit rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">{{ completed }} of {{ steps.length }} complete</span>
        </header>

        <div class="divide-y divide-slate-200">
            <div class="px-4 py-4 sm:px-5">
                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Requested support</p>
                <p class="mt-1.5 whitespace-pre-line text-sm leading-6 text-slate-700">{{ purchase.request_summary || 'The support team will confirm the application-cycle focus during the planning session.' }}</p>
            </div>
            <ol>
                <li v-for="(step, index) in steps" :key="step.id" class="grid gap-2 border-b border-slate-200 px-4 py-3 last:border-b-0 sm:grid-cols-[3rem_minmax(0,1fr)_auto] sm:items-center sm:px-5">
                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">{{ String(index + 1).padStart(2, '0') }}</span>
                    <p class="text-sm font-bold text-slate-900">{{ step.label }}</p>
                    <span :class="['w-fit rounded px-2.5 py-1 text-xs font-bold', step.completed ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">{{ step.completed ? 'Complete' : 'In queue' }}</span>
                </li>
            </ol>
            <div class="px-4 py-3 sm:px-5">
                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Cycle outcome</p>
                <p class="mt-1.5 text-sm leading-6 text-slate-700">{{ purchase.requested_outcome || 'A clear action plan for the selected application-cycle work.' }}</p>
            </div>
        </div>
    </section>
</template>
