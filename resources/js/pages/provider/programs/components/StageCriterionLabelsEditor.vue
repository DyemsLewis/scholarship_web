<script setup>
import { computed, ref, watch } from 'vue';
import { programCriterionLabelErrors } from '../../../../support/programScoringPreview.js';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    includedStages: { type: Array, default: null },
    disabled: { type: Boolean, default: false },
    fixed: { type: Boolean, default: false },
    inputClass: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const stages = computed(() => props.rows.filter(row => ['exam', 'interview'].includes(row.key)
    && (!props.includedStages || props.includedStages.includes(row.key)) && row.criteria?.length));
const stageKey = ref('');
const activeStage = computed(() => stages.value.find(stage => stage.key === stageKey.value));
const draft = ref({});
const errors = computed(() => activeStage.value ? programCriterionLabelErrors({ [stageKey.value]: draft.value }) : {});
const errorMessage = computed(() => Object.values(errors.value)[0] || '');

function show(key) {
    const stage = stages.value.find(row => row.key === key);
    if (props.disabled || !stage) return;
    draft.value = Object.fromEntries(stage.criteria.map(criterion => [
        criterion.key, (props.fixed ? criterion.label : props.modelValue[key]?.[criterion.key] ?? criterion.label),
    ]));
    stageKey.value = key;
}

function close() { stageKey.value = ''; }

function apply() {
    if (props.disabled || props.fixed) return;
    const stage = activeStage.value;
    if (!stage || errorMessage.value) return;
    const updated = Object.fromEntries(stage.criteria.map(criterion => [criterion.key, draft.value[criterion.key].trim()]));
    emit('update:modelValue', { ...props.modelValue, [stage.key]: { ...props.modelValue[stage.key], ...updated } });
    close();
}

watch([() => props.disabled, () => props.fixed, () => props.modelValue, () => props.rows, () => props.includedStages], close);
</script>

<template>
    <section v-if="stages.length" class="mt-4 space-y-2" data-stage-criterion-labels aria-label="Scoring criteria by stage">
        <div v-for="stage in stages" :key="stage.key" class="flex flex-wrap items-center justify-between gap-3 rounded-sm border border-slate-200 bg-white px-4 py-3" :data-criterion-stage="stage.key">
            <div>
                <h4 class="text-sm font-bold text-slate-950">{{ stage.label }} criteria</h4>
                <p class="mt-1 text-xs text-slate-500">{{ stage.criteria.length }} {{ stage.criteria.length === 1 ? 'criterion' : 'criteria' }}{{ fixed ? ' | Fixed after the first application' : '' }}</p>
            </div>
            <button type="button" :disabled="disabled" :aria-label="`${fixed ? 'View' : 'Edit'} ${stage.label} criteria`" aria-haspopup="dialog" :aria-expanded="stageKey === stage.key" class="min-h-8 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="show(stage.key)">{{ fixed ? 'View criteria' : 'Edit criteria' }}</button>
        </div>

        <EligibilityOptionsModal :open="Boolean(activeStage)" id="program-stage-criteria" :title="activeStage ? activeStage.label + ' criteria' : 'Scoring criteria'" eyebrow="Selection scoring" :description="fixed ? 'This intake is locked. Use a new intake to rename criteria.' : 'Rename the criteria to match your assessment. Weights and rating scales stay unchanged.'" apply-label="Apply changes" :apply-disabled="Boolean(errorMessage)" :view-only="fixed" :busy="disabled" @close="close" @apply="apply">
            <fieldset v-if="activeStage" class="space-y-3" :disabled="disabled || fixed">
                <legend class="sr-only">{{ activeStage.label }} criterion names</legend>
                <label v-for="(criterion, index) in activeStage.criteria" :key="criterion.key" :for="`criterion-label-${activeStage.key}-${criterion.key}`" class="block text-sm font-semibold text-slate-700">
                    <span class="mb-1.5 flex items-center justify-between gap-2 text-xs"><span>Criterion {{ index + 1 }}</span><span class="text-slate-500 tabular-nums">{{ criterion.weight }}% of stage score</span></span>
                    <input :id="`criterion-label-${activeStage.key}-${criterion.key}`" v-model="draft[criterion.key]" type="text" maxlength="80" :class="inputClass" :data-criterion-key="criterion.key" :aria-invalid="!fixed && Boolean(errors[`stage_criterion_labels.${activeStage.key}.${criterion.key}`]) || undefined" :aria-describedby="!fixed && errorMessage ? 'program-criterion-error' : undefined">
                </label>
            </fieldset>
            <p v-if="!fixed && errorMessage" id="program-criterion-error" class="text-xs font-semibold text-rose-700" role="status">{{ errorMessage }}</p>
        </EligibilityOptionsModal>
    </section>
</template>
