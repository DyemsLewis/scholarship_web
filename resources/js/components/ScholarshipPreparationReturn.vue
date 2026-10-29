<script setup>
import { computed } from 'vue';
import { preparationReturn } from '../support/scholarshipPreparationContext';

const props = defineProps({ context: { type: Object, default: null }, busy: { type: Boolean, default: false } });
const destination = computed(() => preparationReturn(props.context));
</script>

<template>
    <aside v-if="destination" data-preparation-return class="student-card flex flex-col gap-3 rounded-md border-slate-300 border-l-[3px] border-l-amber-400 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Preparing an application</p>
            <p class="mt-1 text-sm font-bold text-slate-950 [overflow-wrap:anywhere]">{{ destination.scholarship_title }}</p>
            <p class="mt-1 text-xs leading-5 text-slate-500">Your scholarship choice is kept. Return to check your readiness; nothing is submitted here.</p>
        </div>
        <a v-if="!busy" :href="destination.href" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-800 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-600">Return to scholarship<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
        <span v-else class="text-xs font-semibold text-slate-500" role="status">Finish the current action before returning.</span>
    </aside>
</template>
