<script setup>
import { computed } from 'vue';
import { stageEvaluationResult, stageScorePercentage } from '../support/stageEvaluation';

const props = defineProps({
    modelValue: { type: Object, required: true },
    stage: { type: String, required: true },
    idPrefix: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    criteria: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);
const percentage = computed(() => stageEvaluationResult(props.modelValue, props.criteria, props.stage)?.percentage ?? null);
function update(key, value) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}
function updateCriterion(criterion, key, value) {
    emit('update:modelValue', { ...props.modelValue, criteria_scores: { ...props.modelValue.criteria_scores,
        [criterion.key]: { score: '', maximum_score: criterion.maximum_score, ...props.modelValue.criteria_scores?.[criterion.key], [key]: value } } });
}
</script>

<template>
    <fieldset :disabled="disabled" data-stage-evaluation-fields>
        <legend class="text-sm font-bold text-slate-950">{{ stage === 'exam' ? 'Examination score' : 'Interview evaluation' }}</legend>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ criteria.length ? (stage === 'exam' ? 'Enter the actual result for each exam section. Use its real maximum; the fixed weights calculate the stage total.' : 'Rate each criterion from 0 to 5 using the panel\'s assessment. The fixed weights calculate the stage total.') : (stage === 'exam' ? 'Use the actual exam result. Keep the score sheet with the activity proof.' : 'Use the interview panel\'s recorded total. Keep its evaluation sheet with the activity proof.') }}</p>
        <template v-if="criteria.length">
            <p v-if="stage === 'interview'" class="mt-3 rounded-[5px] border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600">0 Not demonstrated · 1 Limited · 2 Developing · 3 Satisfactory · 4 Strong · 5 Excellent. Use evidence from the answers, not personal appearance or background.</p>
            <div class="mt-3 divide-y divide-slate-200 rounded-[5px] border border-slate-200" data-stage-criteria>
                <div v-for="criterion in criteria" :key="criterion.key" class="grid grid-cols-[minmax(0,1fr)_8rem_7rem] items-center gap-4 px-4 py-3" :data-evaluation-criterion="criterion.key">
                    <div><label :for="`${idPrefix}-${criterion.key}-score`" class="text-sm font-bold text-slate-950">{{ criterion.label }}<span class="ml-2 text-xs font-semibold text-slate-500">{{ criterion.weight }}%</span></label><p class="mt-1 text-xs leading-5 text-slate-500">{{ criterion.description }}</p></div>
                    <label class="text-xs font-semibold text-slate-600">{{ stage === 'interview' ? 'Rating' : 'Score' }}<input :id="`${idPrefix}-${criterion.key}-score`" :value="modelValue.criteria_scores?.[criterion.key]?.score ?? ''" type="number" min="0" :max="modelValue.criteria_scores?.[criterion.key]?.maximum_score ?? criterion.maximum_score" step="0.0001" required class="mt-1 h-10 w-full rounded-[5px] border border-slate-300 bg-white px-3 text-sm tabular-nums text-slate-950 disabled:bg-slate-50" placeholder="Enter score" @input="updateCriterion(criterion, 'score', $event.target.value)"></label>
                    <div v-if="stage === 'interview'" class="text-right text-xs text-slate-500">Out of <strong class="text-slate-700">{{ criterion.maximum_score }}</strong></div>
                    <label v-else class="text-xs font-semibold text-slate-600">Out of<input :id="`${idPrefix}-${criterion.key}-maximum`" :value="modelValue.criteria_scores?.[criterion.key]?.maximum_score ?? criterion.maximum_score" type="number" min="0.0001" max="1000000" step="0.0001" required class="mt-1 h-10 w-full rounded-[5px] border border-slate-300 bg-white px-3 text-sm tabular-nums text-slate-950 disabled:bg-slate-50" @input="updateCriterion(criterion, 'maximum_score', $event.target.value)"></label>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between rounded-[5px] bg-slate-950 px-4 py-3 text-white" aria-live="polite"><div><p class="text-xs font-semibold">Calculated stage score</p><p class="mt-1 text-xs text-slate-300">{{ percentage === null ? 'Complete all criteria. Missing scores are not zero.' : 'Weighted total; the server verifies the calculation.' }}</p></div><strong class="text-xl tabular-nums">{{ percentage === null ? '-' : percentage }}<span class="ml-1 text-xs font-normal text-slate-300">/ 100</span></strong></div>
            <p class="mt-2 text-xs text-slate-500">Keep the examination paper or panel evaluation sheet with the activity proof. Grades and financial need are evaluated in screening, not scored again here.</p>
        </template>
        <div v-else class="mt-3 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_10rem] items-end gap-4">
            <label :for="`${idPrefix}-score`" class="text-xs font-semibold text-slate-600">Recorded score<input :id="`${idPrefix}-score`" :value="modelValue.score" type="number" min="0" max="1000000" step="0.0001" required class="mt-2 h-11 w-full rounded-[5px] border border-slate-300 bg-white px-3 text-sm font-normal text-slate-950 outline-none focus:border-slate-950 disabled:bg-slate-50" placeholder="Enter score" @input="update('score', $event.target.value)"></label>
            <label :for="`${idPrefix}-maximum`" class="text-xs font-semibold text-slate-600">Out of<input :id="`${idPrefix}-maximum`" :value="modelValue.maximum_score" type="number" min="0.0001" max="1000000" step="0.0001" required class="mt-2 h-11 w-full rounded-[5px] border border-slate-300 bg-white px-3 text-sm font-normal text-slate-950 outline-none focus:border-slate-950 disabled:bg-slate-50" @input="update('maximum_score', $event.target.value)"></label>
            <div class="flex h-11 items-center justify-between gap-2 rounded-[5px] border border-slate-200 bg-slate-50 px-3" aria-live="polite"><span class="text-xs text-slate-500">Percentage</span><span class="font-bold tabular-nums text-slate-950">{{ percentage === null ? '-' : `${percentage}%` }}</span></div>
        </div>
        <p v-if="!criteria.length && modelValue.score !== '' && modelValue.maximum_score !== '' && percentage === null" class="mt-2 text-xs text-rose-700" role="status">Use a score from zero to the maximum, with up to four decimal places.</p>
    </fieldset>
</template>
