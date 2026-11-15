<script setup>
import { computed, ref, watch } from 'vue';
import { labelFromKey } from '../../../../support/display';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] }, options: { type: Array, default: () => [] },
    id: { type: String, required: true }, label: { type: String, required: true },
    description: String, disabled: Boolean,
});
const emit = defineEmits(['update:modelValue']);
const open = ref(false);
const draft = ref([]);
const optionLabel = value => props.options.find(option => option[0] === value)?.[1] || labelFromKey(value);
function show() { if (props.disabled) return; draft.value = [...props.modelValue]; open.value = true; }
function apply() { if (props.disabled) return; emit('update:modelValue', [...draft.value]); open.value = false; }
watch(() => props.disabled, disabled => { if (disabled) open.value = false; });
watch(() => props.modelValue, () => { if (open.value) open.value = false; });
const chosenLabels = computed(() => props.modelValue.map(optionLabel));
</script>

<template>
    <div :data-checkbox-picker="id">
        <div class="flex min-h-11 items-start gap-3 border border-slate-300 bg-white p-1.5">
            <ul v-if="chosenLabels.length" class="flex min-w-0 flex-1 flex-wrap gap-1.5 py-0.5" :aria-label="`Selected ${label}`"><li v-for="text in chosenLabels" :key="text" class="rounded-sm border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800">{{ text }}</li></ul>
            <span v-else class="flex-1 px-1.5 py-1.5 text-xs text-slate-500">No restriction</span>
            <button :id="id" type="button" :disabled="disabled" class="min-h-7 shrink-0 rounded-sm px-2 text-xs font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-50" :aria-label="`${modelValue.length ? 'Edit' : 'Choose'} ${label}`" aria-haspopup="dialog" :aria-expanded="open" :aria-controls="`${id}-options`" @click="show">{{ modelValue.length ? 'Edit' : 'Choose' }}</button>
        </div>
        <EligibilityOptionsModal :open="open" :id="id" :title="label" :description="description" @close="open = false" @apply="apply">
            <p class="text-xs text-slate-500">Select the options this program accepts. Leave all unchecked for no restriction.</p>
            <div class="grid grid-cols-2 gap-2">
                <label v-for="([value, text]) in options" :key="value" :class="['flex min-h-11 cursor-pointer items-center gap-2 rounded-sm border px-3 py-2 text-xs font-semibold', draft.includes(value) ? 'border-slate-600 bg-slate-50 text-slate-950' : 'border-slate-200 text-slate-600']"><input v-model="draft" type="checkbox" :value="value" class="border-slate-300 accent-slate-950">{{ text }}</label>
            </div>
            <button type="button" class="text-xs font-bold text-slate-600 underline underline-offset-2" @click="draft = []">No restriction</button>
        </EligibilityOptionsModal>
    </div>
</template>
