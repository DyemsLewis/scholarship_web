<script setup>
import { computed } from 'vue';

const props = defineProps({
    purchase: { type: Object, required: true },
});

const steps = computed(() => props.purchase.milestones ?? []);
</script>

<template>
    <section class="overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
        <header class="flex items-start gap-3 border-b border-slate-200 p-4 sm:px-5">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
            </span>
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Program setup workspace</p>
                <h2 class="mt-1 text-lg font-bold text-slate-950">Path to publication</h2>
                <p class="mt-1 text-sm text-slate-500">Complete the program foundation before the final readiness check.</p>
            </div>
        </header>

        <ol class="grid border-b border-slate-200 md:grid-cols-3">
            <li v-for="(step, index) in steps" :key="step.id" class="flex gap-3 border-b border-slate-200 p-4 last:border-b-0 md:border-b-0 md:border-r md:last:border-r-0">
                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs font-bold', step.completed ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">
                    <i v-if="step.completed" class="fa-solid fa-check" aria-hidden="true"></i>
                    <span v-else>{{ index + 1 }}</span>
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-900">{{ step.label }}</p>
                    <p :class="['mt-1 text-xs font-semibold', step.completed ? 'text-emerald-700' : 'text-slate-500']">{{ step.completed ? 'Complete' : 'Pending' }}</p>
                </div>
            </li>
        </ol>

        <div class="divide-y divide-slate-200">
            <div class="px-4 py-3 sm:px-5">
                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Program note</p>
                <p class="mt-1.5 whitespace-pre-line text-sm leading-6 text-slate-700">{{ purchase.request_summary || 'The setup session will use the program information shared during the meeting.' }}</p>
            </div>
            <div class="px-4 py-3 sm:px-5">
                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Expected handoff</p>
                <p class="mt-1.5 text-sm leading-6 text-slate-700">{{ purchase.requested_outcome || 'A reviewed program setup with clear publishing actions.' }}</p>
            </div>
        </div>
    </section>
</template>
