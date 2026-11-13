<script setup>
import { computed, ref } from 'vue';
import { addEligibilityOption, eligibilityListValues } from '../../../../support/programEligibilityOptions';
import { isOpenEligibilityRule } from '../../../../support/providerEligibilityRules';

const model = defineModel({ type: String, default: '' });
const props = defineProps({
    id: { type: String, required: true }, options: { type: Array, default: () => [] },
    inputClass: { type: String, default: '' }, openLabel: { type: String, default: 'No restriction' },
    openValue: { type: String, default: '' }, customLabel: { type: String, default: 'Add an unlisted option' },
    allowCustom: { type: Boolean, default: true }, maxLength: { type: Number, default: 3000 },
});
const pending = ref('');
const custom = ref('');
const error = ref('');
const values = computed(() => eligibilityListValues(model.value));
const unrestricted = computed(() => !values.value.length || values.value.some(isOpenEligibilityRule));
const selected = computed(() => values.value.filter(value => !isOpenEligibilityRule(value)));
const hasValue = value => selected.value.some(item => item.toLowerCase() === value.toLowerCase());
function add(value) {
    if (!String(value ?? '').trim()) return;
    if (/[\r\n,]/.test(value)) { error.value = 'Add one option at a time, without commas.'; return; }
    const updated = addEligibilityOption(model.value, value);
    if (updated.length > props.maxLength) { error.value = 'The selected list is too long. Remove an option before adding another.'; return; }
    model.value = updated;
    pending.value = ''; custom.value = ''; error.value = '';
}
function remove(value) {
    model.value = values.value.filter(item => item !== value).join('\n');
    error.value = '';
}
function openToAll() { model.value = props.openValue; error.value = ''; }
</script>

<template>
    <div :data-eligibility-picker="id" class="min-w-0 space-y-2.5">
        <div class="flex items-center justify-between gap-3 text-xs">
            <span class="text-slate-500" role="status">{{ unrestricted ? openLabel : `${selected.length} selected` }}</span>
            <button v-if="!unrestricted" type="button" data-open-rule class="font-semibold text-slate-600 underline underline-offset-2" @click="openToAll">{{ openLabel }}</button>
        </div>
        <slot name="picker" :add="add">
            <div class="flex gap-2">
                <select :id="id" v-model="pending" :class="[inputClass, 'min-w-0 flex-1']">
                    <option value="">Choose an option</option>
                    <option v-for="option in options" :key="option" :value="option" :disabled="hasValue(option)">{{ option }}</option>
                </select>
                <button type="button" data-add-option :disabled="!pending" class="shrink-0 rounded-sm border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" @click="add(pending)">Add</button>
            </div>
        </slot>
        <ul v-if="selected.length" class="flex flex-wrap gap-1.5" aria-label="Selected eligibility options">
            <li v-for="value in selected" :key="value" class="inline-flex max-w-full items-center gap-2 rounded-sm border border-slate-200 bg-slate-50 py-1 pl-2.5 pr-1 text-xs text-slate-800">
                <span class="min-w-0 break-words">{{ value }}</span>
                <button type="button" :aria-label="`Remove ${value}`" class="grid h-6 w-6 shrink-0 place-items-center rounded-sm text-slate-500 hover:bg-slate-200" @click="remove(value)"><i class="fa-solid fa-xmark text-[10px]" aria-hidden="true"></i></button>
            </li>
        </ul>
        <details v-if="allowCustom" class="text-xs">
            <summary class="w-fit cursor-pointer font-semibold text-slate-600 underline underline-offset-2">{{ customLabel }}</summary>
            <div class="mt-2 flex gap-2">
                <input :id="`${id}-custom`" v-model="custom" :aria-label="customLabel" :class="[inputClass, 'min-w-0 flex-1']" maxlength="255" placeholder="Enter one specific option" @keydown.enter.prevent="add(custom)">
                <button type="button" :disabled="!custom.trim()" class="shrink-0 rounded-sm border border-slate-300 px-3 font-bold text-slate-700 disabled:opacity-40" @click="add(custom)">Add</button>
            </div>
        </details>
        <p v-if="error" role="alert" class="text-xs text-rose-700">{{ error }}</p>
    </div>
</template>
