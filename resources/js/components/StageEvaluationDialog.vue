<script setup>
import { computed, ref, watch } from 'vue';
import StageEvaluationFields from './StageEvaluationFields.vue';
import { useModalAccessibility } from '../composables/useModalAccessibility';
import { confirmOutcome } from '../support/outcomeConfirmation';
import { stageScoreLabel, stageScorePercentage } from '../support/stageEvaluation';

const props = defineProps({
    evaluation: { type: Object, default: null },
    applicantName: { type: String, default: '' },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
const emit = defineEmits(['close', 'save']);
const dialog = ref(null);
const confirming = ref(false);
const form = ref({ score: '', maximum_score: 100, note: '' });
const correcting = computed(() => props.evaluation?.score !== null && props.evaluation?.score !== undefined);
const valid = computed(() => stageScorePercentage(form.value.score, form.value.maximum_score) !== null
    && (!correcting.value || form.value.note.trim().length >= 10));
const { handleKeydown } = useModalAccessibility(() => Boolean(props.evaluation), dialog, close);
watch(() => props.evaluation, (stage) => {
    if (!stage) return;
    form.value = { score: stage.raw_score ?? '', maximum_score: stage.maximum_score ?? 100, note: '' };
});
function close() {
    if (!props.busy && !confirming.value) emit('close');
}
async function save() {
    if (!valid.value || props.busy || confirming.value || !props.evaluation?.can_edit) return;
    const evaluation = props.evaluation;
    const snapshot = JSON.stringify(form.value);
    confirming.value = true;
    try {
        const accepted = await confirmOutcome({
            title: correcting.value ? 'Confirm score correction' : 'Confirm stage evaluation',
            record: props.applicantName,
            outcome: `${evaluation.label} evaluation`,
            details: [`Score: ${stageScoreLabel(form.value.score, form.value.maximum_score)}`, correcting.value ? `Reason: ${form.value.note.trim()}` : ''],
            consequence: 'This updates the selection evaluation only. It does not change a stage result, select the applicant, or alter the waitlist. Earlier scores remain in history.',
            confirmLabel: correcting.value ? 'Save correction' : 'Save evaluation',
        }, () => Boolean(props.evaluation?.can_edit) && props.evaluation === evaluation && !props.busy && JSON.stringify(form.value) === snapshot);
        if (accepted) emit('save', { stage: evaluation, payload: {
            score: Number(form.value.score), maximum_score: Number(form.value.maximum_score),
            expected_stage_revision: evaluation.stage_revision, expected_revision: evaluation.revision,
            note: form.value.note.trim() || null,
        } });
    } finally {
        confirming.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="evaluation" class="fixed inset-0 z-[3000] flex items-center justify-center bg-slate-950/60 p-5" @click.self="close">
            <form ref="dialog" role="dialog" aria-modal="true" aria-labelledby="stage-evaluation-dialog-title" tabindex="-1" class="flex max-h-[calc(100vh-2.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white shadow-2xl outline-none" data-stage-evaluation-dialog @keydown="handleKeydown" @submit.prevent="save">
                <header class="flex shrink-0 items-start justify-between gap-5 border-b border-slate-200 px-6 py-5"><div><p class="text-xs font-bold uppercase tracking-widest text-amber-700">{{ evaluation.label }}</p><h2 id="stage-evaluation-dialog-title" class="mt-1 text-xl font-bold text-slate-950">{{ correcting ? 'Correct recorded score' : 'Add evaluation score' }}</h2><p class="mt-1 text-sm text-slate-500">{{ applicantName }}</p></div><button type="button" class="grid h-9 w-9 place-items-center rounded-[5px] border border-slate-300 text-slate-600" aria-label="Close evaluation" :disabled="busy || confirming" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                <div class="min-h-0 overflow-y-auto px-6 py-5">
                    <p v-if="error" role="alert" class="mb-4 border-l-4 border-rose-400 bg-rose-50 px-3 py-3 text-sm text-rose-800">{{ error }}</p>
                    <p v-if="correcting" class="mb-4 text-xs text-slate-500">Current score: <strong class="text-slate-800">{{ evaluation.raw_score }} / {{ evaluation.maximum_score }}</strong>. The original is kept in history.</p>
                    <StageEvaluationFields v-model="form" :stage="evaluation.key" id-prefix="stage-correction" :disabled="busy || confirming" />
                    <label class="mt-5 block text-sm font-semibold text-slate-700">{{ correcting ? 'Reason for correction' : 'Internal note (optional)' }}<textarea v-model="form.note" :required="correcting" :minlength="correcting ? 10 : undefined" maxlength="2000" rows="3" :disabled="busy || confirming" class="mt-2 w-full rounded-[5px] border border-slate-300 px-3 py-3 font-normal text-slate-950 outline-none focus:border-slate-950" :placeholder="correcting ? 'Explain why the recorded score needs correcting.' : 'Add a short reference to the score sheet if needed.'"></textarea></label>
                    <details v-if="evaluation.history?.length" class="mt-4 border-t border-slate-200 pt-3"><summary class="cursor-pointer text-xs font-bold text-slate-600">Recent score history</summary><ol class="mt-2 divide-y divide-slate-200"><li v-for="entry in evaluation.history" :key="entry.revision" class="py-3"><div class="flex items-start justify-between gap-5"><p class="text-xs text-slate-500">{{ entry.recorded_by }} | {{ entry.recorded_at }}</p><p class="shrink-0 text-xs font-bold text-slate-900">{{ stageScoreLabel(entry.score, entry.maximum_score) }}</p></div><p v-if="entry.note" class="mt-2 whitespace-pre-line text-xs leading-5 text-slate-600">{{ entry.note }}</p></li></ol></details>
                </div>
                <footer class="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 rounded-[5px] border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" :disabled="busy || confirming" @click="close">Cancel</button><button type="submit" class="h-10 rounded-[5px] bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-50" :disabled="!valid || busy || confirming">{{ busy ? 'Saving...' : 'Review evaluation' }}</button></footer>
            </form>
        </div>
    </Teleport>
</template>
