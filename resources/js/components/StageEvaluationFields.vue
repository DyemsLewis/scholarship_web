<script setup>
import { computed } from 'vue';
import { stageScorePercentage } from '../support/stageEvaluation';

const props = defineProps({
    modelValue: { type: Object, required: true },
    stage: { type: String, required: true },
    idPrefix: { type: String, required: true },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const percentage = computed(() => stageScorePercentage(props.modelValue.score, props.modelValue.maximum_score));
function update(key, value) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>

<template>
    <fieldset :disabled="disabled" data-stage-evaluation-fields>
        <legend class="text-sm font-bold text-slate-950">{{ stage === 'exam' ? 'Examination score' : 'Interview evaluation' }}</legend>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ stage === 'exam' ? 'Use the actual exam result. Keep the score sheet with the activity proof.' : 'Use the interview panel\'s recorded total. Keep its evaluation sheet with the activity proof.' }}</p>
        <div class="mt-3 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_10rem] items-end gap-4">
            <label :for="`${idPrefix}-score`" class="text-xs font-semibold text-slate-600">Recorded score<input :id="`${idPrefix}-score`" :value="modelValue.score" type="number" min="0" max="1000000" step="0.0001" required class="mt-2 h-11 w-full rounded-[5px] border border-slate-300 bg-white px-3 text-sm font-normal text-slate-950 outline-none focus:border-slate-950 disabled:bg-slate-50" placeholder="Enter score" @input="update('score', $event.target.value)"></label>
            <label :for="`${idPrefix}-maximum`" class="text-xs font-semibold text-slate-600">Out of<input :id="`${idPrefix}-maximum`" :value="modelValue.maximum_score" type="number" min="0.0001" max="1000000" step="0.0001" required class="mt-2 h-11 w-full rounded-[5px] border border-slate-300 bg-white px-3 text-sm font-normal text-slate-950 outline-none focus:border-slate-950 disabled:bg-slate-50" @input="update('maximum_score', $event.target.value)"></label>
            <div class="flex h-11 items-center justify-between gap-2 rounded-[5px] border border-slate-200 bg-slate-50 px-3" aria-live="polite"><span class="text-xs text-slate-500">Percentage</span><span class="font-bold tabular-nums text-slate-950">{{ percentage === null ? '-' : `${percentage}%` }}</span></div>
        </div>
        <p v-if="modelValue.score !== '' && modelValue.maximum_score !== '' && percentage === null" class="mt-2 text-xs text-rose-700" role="status">Use a score from zero to the maximum, with up to four decimal places.</p>
    </fieldset>
</template>
