<script setup>
import { computed } from 'vue';
import { providerProgramQueueLink, staffQueueLink } from '../support/staffQueueState.js';

const props = defineProps({
    programId: { type: [String, Number], default: '' },
    programs: { type: Array, required: true },
});

const program = computed(() => props.programs.find((option) => String(option.id) === String(props.programId)));
const queuePath = (window.portalPathname?.() ?? window.location.pathname).replace(/\/$/, '');
const programUrl = computed(() => program.value
    ? staffQueueLink(`/provider/programs/${program.value.id}`, providerProgramQueueLink(queuePath, program.value.id))
    : null);
</script>

<template>
    <div v-if="program" class="flex items-center justify-between gap-4 border-t border-slate-200 pt-4" aria-label="Program filter context" data-provider-program-context>
        <div class="flex min-w-0 items-center gap-2 text-sm">
            <i class="fa-solid fa-graduation-cap text-slate-500" aria-hidden="true"></i>
            <span class="shrink-0 text-slate-600">Program:</span>
            <span class="min-w-0 break-words font-semibold text-slate-900">{{ program.title }}</span>
        </div>
        <a :href="programUrl" class="inline-flex shrink-0 items-center gap-2 rounded-[5px] border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-600 hover:text-slate-950">
            Open program <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
        </a>
    </div>
</template>
