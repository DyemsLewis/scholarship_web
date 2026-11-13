<script setup>
import { ref } from 'vue';
import SelectionEvaluationSummary from './SelectionEvaluationSummary.vue';
import { useModalAccessibility } from '../composables/useModalAccessibility';

const props = defineProps({ record: { type: Object, default: null }, recordHref: { type: String, default: '' } });
const emit = defineEmits(['close']);
const dialog = ref(null);
const { handleKeydown } = useModalAccessibility(() => Boolean(props.record), dialog, () => emit('close'));
</script>

<template>
    <Teleport to="body">
        <div v-if="record" class="fixed inset-0 z-[3000] flex items-center justify-center bg-slate-950/60 p-5" @click.self="emit('close')">
            <section ref="dialog" role="dialog" aria-modal="true" aria-labelledby="selection-breakdown-title" tabindex="-1" class="flex max-h-[calc(100vh-2.5rem)] w-full max-w-3xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white shadow-2xl outline-none" data-selection-breakdown-dialog @keydown="handleKeydown">
                <header class="flex items-start justify-between gap-5 border-b border-slate-200 px-6 py-5"><div><p class="text-xs font-bold uppercase tracking-widest text-amber-700">Evaluation breakdown</p><h2 id="selection-breakdown-title" class="mt-1 text-xl font-bold text-slate-950">{{ record.applicant.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ record.program.title }}</p></div><button type="button" class="grid h-9 w-9 place-items-center rounded-[5px] border border-slate-300 text-slate-600" aria-label="Close evaluation breakdown" @click="emit('close')"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                <div class="min-h-0 overflow-y-auto"><SelectionEvaluationSummary :evaluation="record.selection_evaluation" embedded expanded /></div>
                <footer class="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><a :href="recordHref" class="inline-flex h-10 items-center rounded-[5px] border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700">Open decision record<i class="fa-solid fa-arrow-right ml-2 text-[10px]" aria-hidden="true"></i></a><button type="button" class="h-10 rounded-[5px] bg-slate-950 px-4 text-xs font-bold text-white" @click="emit('close')">Close</button></footer>
            </section>
        </div>
    </Teleport>
</template>
