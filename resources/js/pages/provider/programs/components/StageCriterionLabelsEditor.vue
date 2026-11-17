<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    fixed: { type: Boolean, default: false },
    inputClass: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const stages = computed(() => props.rows.filter(row => ['exam', 'interview'].includes(row.key) && row.criteria?.length));

function updateLabel(stage, key, value) {
    if (props.disabled || props.fixed) return;
    emit('update:modelValue', { ...props.modelValue, [stage]: { ...props.modelValue[stage], [key]: value } });
}
</script>

<template>
    <section v-if="stages.length" class="mt-6 border-t border-slate-200 pt-5" data-stage-criterion-labels aria-labelledby="criterion-labels-title">
        <h4 id="criterion-labels-title" class="text-sm font-bold text-slate-950">Criterion labels</h4>
        <p class="mt-1 text-xs leading-5 text-slate-500">{{ fixed ? 'Labels are fixed after the first application. Use a new intake to rename them.' : 'Rename the criteria to match your assessment. Weights and rating scales stay unchanged.' }}</p>
        <fieldset v-for="stage in stages" :key="stage.key" class="mt-4" :disabled="disabled || fixed" :data-criterion-stage="stage.key">
            <legend class="text-xs font-bold text-slate-700">{{ stage.label }}</legend>
            <div class="mt-2 grid grid-cols-3 gap-4">
                <label v-for="(criterion, index) in stage.criteria" :key="criterion.key" :for="`criterion-label-${stage.key}-${criterion.key}`">
                    <span class="mb-2 flex items-center justify-between gap-2 text-xs font-semibold text-slate-600"><span>Criterion {{ index + 1 }}</span><span class="tabular-nums">{{ criterion.weight }}%</span></span>
                    <input :id="`criterion-label-${stage.key}-${criterion.key}`" :value="modelValue[stage.key]?.[criterion.key] ?? criterion.label" type="text" maxlength="80" required :class="inputClass" :data-criterion-key="criterion.key" @input="updateLabel(stage.key, criterion.key, $event.target.value)">
                </label>
            </div>
        </fieldset>
    </section>
</template>
