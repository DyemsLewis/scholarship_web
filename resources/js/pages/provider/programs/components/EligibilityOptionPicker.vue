<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { addEligibilityOption, eligibilityListValues } from '../../../../support/programEligibilityOptions';
import { isOpenEligibilityRule } from '../../../../support/providerEligibilityRules';

const model = defineModel({ type: String, default: '' });
const needsChoice = defineModel('needsChoice', { type: Boolean, default: false });
const props = defineProps({
    id: { type: String, required: true }, options: { type: Array, default: () => [] },
    inputClass: { type: String, default: '' }, openLabel: { type: String, default: 'No restriction' },
    openValue: { type: String, default: '' }, customLabel: { type: String, default: 'Add an unlisted option' },
    allowCustom: { type: Boolean, default: true }, maxLength: { type: Number, default: 3000 },
    requireExplicitOpen: { type: Boolean, default: false },
});
const optionsOpen = ref(false);
const pending = ref('');
const custom = ref('');
const error = ref('');
const values = computed(() => eligibilityListValues(model.value));
const selected = computed(() => values.value.filter(value => !isOpenEligibilityRule(value)));
const hasValue = value => selected.value.some(item => item.toLowerCase() === value.toLowerCase());
watch(() => props.options, options => { if (!options.includes(pending.value)) pending.value = ''; });
function add(value) {
    if (!String(value ?? '').trim()) return;
    if (/[\r\n,]/.test(value)) { error.value = 'Add one option at a time, without commas.'; return; }
    const updated = addEligibilityOption(model.value, value);
    if (updated.length > props.maxLength) { error.value = 'The selected list is too long. Remove an option before adding another.'; return; }
    model.value = updated;
    needsChoice.value = false;
    pending.value = ''; custom.value = ''; error.value = '';
}
function remove(value) {
    const updated = values.value.filter(item => item !== value).join('\n');
    model.value = updated;
    needsChoice.value = props.requireExplicitOpen && !eligibilityListValues(updated).length;
    error.value = '';
}
function openToAll() {
    model.value = props.openValue; needsChoice.value = false; error.value = ''; optionsOpen.value = false;
    nextTick(() => document.getElementById(props.id)?.focus());
}
</script>

<template>
    <div :data-eligibility-picker="id" class="min-w-0 space-y-2">
        <div data-selection-field :class="['flex min-h-11 items-start gap-2 border bg-white p-1.5', needsChoice ? 'border-amber-400' : 'border-slate-300']">
            <ul v-if="selected.length" class="flex min-w-0 flex-1 flex-wrap gap-1.5" aria-label="Selected eligibility options">
                <li v-for="value in selected" :key="value" class="inline-flex max-w-full items-center gap-1.5 rounded-sm border border-slate-200 bg-slate-50 py-0.5 pl-2 pr-0.5 text-xs text-slate-800">
                    <span class="min-w-0 break-words">{{ value }}</span>
                    <button type="button" :aria-label="`Remove ${value}`" class="grid h-6 w-6 shrink-0 place-items-center rounded-sm text-slate-500 hover:bg-slate-200" @click="remove(value)"><i class="fa-solid fa-xmark text-[10px]" aria-hidden="true"></i></button>
                </li>
            </ul>
            <span v-else class="min-w-0 flex-1 px-1.5 py-1.5 text-xs text-slate-500" role="status">{{ needsChoice ? 'Choose replacement options' : openLabel }}</span>
            <button :id="id" type="button" data-toggle-options :aria-expanded="optionsOpen" :aria-controls="`${id}-options`" class="inline-flex min-h-7 shrink-0 items-center gap-1.5 rounded-sm px-2 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="optionsOpen = !optionsOpen"><i :class="optionsOpen ? 'fa-solid fa-chevron-up' : 'fa-solid fa-plus'" class="text-[10px]" aria-hidden="true"></i>{{ optionsOpen ? 'Done' : 'Add' }}</button>
        </div>
        <div v-if="optionsOpen" :id="`${id}-options`" class="space-y-3 border border-slate-200 bg-slate-50 p-3">
            <slot name="picker" :add="add">
                <div class="flex gap-2">
                    <select :id="`${id}-option`" v-model="pending" aria-label="Choose an eligibility option" :class="[inputClass, 'min-w-0 flex-1']">
                        <option value="">Choose an option</option>
                        <option v-for="option in options" :key="option" :value="option" :disabled="hasValue(option)">{{ option }}</option>
                    </select>
                    <button type="button" data-add-option :disabled="!pending" class="shrink-0 rounded-sm border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" @click="add(pending)">Add</button>
                </div>
            </slot>
            <details v-if="allowCustom" class="text-xs">
                <summary class="w-fit cursor-pointer font-semibold text-slate-600 underline underline-offset-2">{{ customLabel }}</summary>
                <div class="mt-2 flex gap-2">
                    <input :id="`${id}-custom`" v-model="custom" :aria-label="customLabel" :class="[inputClass, 'min-w-0 flex-1']" maxlength="255" placeholder="Enter one specific option" @keydown.enter.prevent="add(custom)">
                    <button type="button" :disabled="!custom.trim()" class="shrink-0 rounded-sm border border-slate-300 px-3 font-bold text-slate-700 disabled:opacity-40" @click="add(custom)">Add</button>
                </div>
            </details>
            <div class="flex items-center justify-between gap-3 text-xs">
                <button type="button" data-open-rule class="font-semibold text-slate-600 underline underline-offset-2" @click="openToAll">{{ openLabel }}</button>
                <span class="text-slate-500">{{ selected.length }} selected</span>
            </div>
        </div>
        <p v-if="needsChoice" data-choice-required class="text-xs leading-5 text-amber-800">Choose replacement values or explicitly select {{ openLabel }}. This rule is not automatically unrestricted.</p>
        <p v-if="error" role="alert" class="text-xs text-rose-700">{{ error }}</p>
    </div>
</template>
