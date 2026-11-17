<script setup>
import { computed, ref, watch } from 'vue';
import StageEvaluationFields from './StageEvaluationFields.vue';
import { useModalAccessibility } from '../composables/useModalAccessibility';
import { copyEvaluationDraft, screeningEvaluationStatus, stageEvaluationResult } from '../support/stageEvaluation';

const props = defineProps({
    open: { type: Boolean, default: false },
    modelValue: { type: Object, required: true },
    stage: { type: String, required: true },
    criteria: { type: Array, default: () => [] },
    applicantName: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'apply']);
const dialog = ref(null);
const draft = ref({});
const screening = computed(() => props.stage === 'screening');
const stageLabel = computed(() => ({ screening: 'Pre-screening', exam: 'Exam', interview: 'Interview' })[props.stage] || 'Stage');
const screeningStatus = computed(() => screeningEvaluationStatus(draft.value, props.criteria));
const valid = computed(() => ['screening', 'exam', 'interview'].includes(props.stage) && (screening.value
    ? props.criteria.length > 0 && screeningStatus.value.valid
    : stageEvaluationResult(draft.value, props.criteria, props.stage) !== null));
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);
watch(() => props.open, (open) => {
    if (open) draft.value = copyEvaluationDraft(props.modelValue);
}, { immediate: true });

function close() {
    if (!props.disabled) emit('close');
}
function apply() {
    if (!props.open || props.disabled || !valid.value) return;
    emit('apply', screening.value
        ? Object.fromEntries(props.criteria.map(criterion => [criterion.key, Number(draft.value[criterion.key])]))
        : copyEvaluationDraft(draft.value));
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[3000] flex items-center justify-center bg-slate-950/60 p-5" @click.self="close">
            <form ref="dialog" role="dialog" aria-modal="true" aria-labelledby="stage-evaluation-entry-title" aria-describedby="stage-evaluation-entry-notice" tabindex="-1" class="flex max-h-[calc(100vh-2.5rem)] w-full max-w-3xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white shadow-2xl outline-none" data-stage-evaluation-entry-dialog :data-evaluation-stage="stage" @keydown="handleKeydown" @submit.prevent="apply">
                <header class="flex shrink-0 items-start justify-between gap-5 border-b border-slate-200 px-6 py-5">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-[5px] bg-slate-950 text-amber-300"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
                        <div><p class="text-xs font-bold uppercase tracking-widest text-amber-700">Stage evaluation</p><h2 id="stage-evaluation-entry-title" class="mt-1 text-xl font-bold text-slate-950">{{ stageLabel }} evaluation</h2><p class="mt-1 text-sm text-slate-500">{{ applicantName }}</p></div>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-[5px] border border-slate-300 text-slate-600" aria-label="Close evaluation" :disabled="disabled" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div class="min-h-0 overflow-y-auto px-6 py-5">
                    <p id="stage-evaluation-entry-notice" class="mb-5 rounded-[5px] border border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-600">Use evaluation adds these scores to your pending result. Nothing is recorded until you review and confirm the stage outcome.</p>
                    <fieldset v-if="screening" :disabled="disabled" data-screening-evaluation-fields>
                        <legend class="text-sm font-bold text-slate-950">Published review rubric</legend>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Score each criterion from 0 to 100 using the reviewed records.</p>
                        <div class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-[5px] border border-slate-200">
                            <label v-for="criterion in criteria" :key="criterion.key" :for="`screening-evaluation-${criterion.key}`" class="grid grid-cols-[minmax(0,1fr)_8rem] items-center gap-6 px-4 py-4" :data-screening-criterion="criterion.key">
                                <span class="min-w-0"><span class="block text-sm font-bold text-slate-950">{{ criterion.label }}<span class="ml-2 text-xs font-semibold text-slate-500">{{ criterion.weight }}%</span></span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ criterion.guidance }}</span></span>
                                <span class="text-xs font-semibold text-slate-600">Score / 100<input :id="`screening-evaluation-${criterion.key}`" v-model="draft[criterion.key]" type="number" min="0" max="100" step="0.01" required class="mt-1 h-10 w-full rounded-[5px] border border-slate-300 bg-white px-3 text-sm tabular-nums text-slate-950 disabled:bg-slate-50" placeholder="Enter score"></span>
                            </label>
                        </div>
                        <p class="mt-3 text-xs font-semibold text-slate-600" aria-live="polite">{{ screeningStatus.completed }} of {{ screeningStatus.total }} criteria complete. Missing scores are not zero.</p>
                        <p class="mt-2 text-xs leading-5 text-slate-500">The DSS reuses only the scoring criteria in this intake's plan. Eligibility and document checks do not earn ranking points.</p>
                    </fieldset>
                    <StageEvaluationFields v-else v-model="draft" :stage="stage" :criteria="criteria" id-prefix="stage-entry" :disabled="disabled" />
                </div>
                <footer class="flex shrink-0 items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-xs leading-5 text-slate-500">Cancel discards changes made in this modal.</p>
                    <div class="flex shrink-0 gap-3"><button type="button" class="h-10 rounded-[5px] border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" :disabled="disabled" @click="close">Cancel</button><button type="submit" class="h-10 rounded-[5px] bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-50" :disabled="disabled || !valid">Use evaluation</button></div>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
