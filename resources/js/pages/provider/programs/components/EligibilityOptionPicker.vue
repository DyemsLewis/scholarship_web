<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { addEligibilityOption, eligibilityListValues } from '../../../../support/programEligibilityOptions';
import { isOpenEligibilityRule } from '../../../../support/providerEligibilityRules';
import FeedbackAlertModal from '../../../../components/FeedbackAlertModal.vue';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const model = defineModel({ type: String, default: '' });
const needsChoice = defineModel('needsChoice', { type: Boolean, default: false });
const props = defineProps({
    id: { type: String, required: true }, options: { type: Array, default: () => [] },
    inputClass: { type: String, default: '' }, openLabel: { type: String, default: 'No restriction' },
    openValue: { type: String, default: '' }, customLabel: { type: String, default: 'Add an unlisted option' },
    allowCustom: { type: Boolean, default: true }, maxLength: { type: Number, default: 3000 },
    requireExplicitOpen: { type: Boolean, default: false },
    quickOptions: { type: Boolean, default: false },
    label: { type: String, default: 'Eligibility options' }, disabled: Boolean,
});
const optionsOpen = ref(false);
const draft = ref('');
const draftNeedsChoice = ref(false);
const pending = ref('');
const custom = ref('');
const error = ref('');
const choiceWarning = ref('');
function warnAboutChoice(required = needsChoice.value) {
    if (required) choiceWarning.value = `Choose replacement values or explicitly select ${props.openLabel}. This rule is not automatically unrestricted.`;
}
function toggleOptions() {
    if (props.disabled) return;
    draft.value = model.value;
    draftNeedsChoice.value = needsChoice.value;
    pending.value = ''; custom.value = ''; error.value = '';
    optionsOpen.value = true;
    warnAboutChoice();
}
function close() { optionsOpen.value = false; error.value = ''; choiceWarning.value = ''; }
const values = computed(() => eligibilityListValues(model.value));
const selected = computed(() => values.value.filter(value => !isOpenEligibilityRule(value)));
const draftSelected = computed(() => eligibilityListValues(draft.value).filter(value => !isOpenEligibilityRule(value)));
const hasValue = value => draftSelected.value.some(item => item.toLowerCase() === value.toLowerCase());
watch(() => props.options, options => { if (!options.includes(pending.value)) pending.value = ''; });
watch(() => props.disabled, disabled => { if (disabled) close(); });
watch([model, needsChoice], () => { if (optionsOpen.value) close(); });
watch([choiceWarning, error], ([warning, failure]) => {
    if (optionsOpen.value && !warning && !failure) nextTick(() => document.getElementById(`${props.id}-options`)?.focus({ preventScroll: true }));
});
function add(value) {
    if (props.disabled) return;
    if (!String(value ?? '').trim()) return;
    if (/[\r\n,]/.test(value)) { error.value = 'Add one option at a time, without commas.'; return; }
    const updated = addEligibilityOption(draft.value, value);
    if (updated.length > props.maxLength) { error.value = 'The selected list is too long. Remove an option before adding another.'; return; }
    draft.value = updated;
    draftNeedsChoice.value = false;
    pending.value = ''; custom.value = ''; error.value = '';
}
function remove(value) {
    if (props.disabled) return;
    const updated = values.value.filter(item => item !== value).join('\n');
    model.value = updated;
    needsChoice.value = props.requireExplicitOpen && !eligibilityListValues(updated).length;
    warnAboutChoice(props.requireExplicitOpen && !eligibilityListValues(updated).length);
    error.value = '';
}
function removeDraft(value) {
    draft.value = eligibilityListValues(draft.value).filter(item => item !== value).join('\n');
    draftNeedsChoice.value = props.requireExplicitOpen && !eligibilityListValues(draft.value).length;
}
function openToAll() {
    draft.value = props.openValue; draftNeedsChoice.value = false; error.value = '';
}
function apply() {
    if (props.disabled) return;
    if (draftNeedsChoice.value) { warnAboutChoice(true); return; }
    model.value = draft.value;
    needsChoice.value = false;
    close();
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
            <button :id="id" type="button" :disabled="disabled" data-toggle-options :aria-label="`${selected.length ? 'Edit' : 'Choose'} ${label}`" aria-haspopup="dialog" :aria-expanded="optionsOpen" :aria-controls="`${id}-options`" class="inline-flex min-h-7 shrink-0 items-center gap-1.5 rounded-sm px-2 text-xs font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-50" @click="toggleOptions"><i class="fa-solid fa-sliders text-[10px]" aria-hidden="true"></i>{{ selected.length ? 'Edit' : 'Choose' }}</button>
        </div>
        <EligibilityOptionsModal :open="optionsOpen" :id="id" :title="label" @close="close" @apply="apply">
            <div>
                <p class="mb-2 text-xs font-bold text-slate-700">Selected options <span class="font-normal text-slate-500">({{ draftSelected.length }})</span></p>
                <ul v-if="draftSelected.length" class="flex flex-wrap gap-2"><li v-for="value in draftSelected" :key="value" class="inline-flex max-w-full items-center gap-2 rounded-sm border border-slate-200 bg-slate-50 py-1 pl-2.5 pr-1 text-xs text-slate-800"><span class="break-words">{{ value }}</span><button type="button" :aria-label="`Remove ${value}`" class="grid size-6 shrink-0 place-items-center rounded-sm text-slate-500 hover:bg-slate-200" @click="removeDraft(value)"><i class="fa-solid fa-xmark block text-xs leading-none" aria-hidden="true"></i></button></li></ul>
                <p v-else class="text-xs leading-5 text-slate-500">{{ draftNeedsChoice ? 'Choose replacement options, or explicitly allow no restriction below.' : openLabel }}</p>
            </div>
            <slot name="picker" :add="add">
                <div v-if="quickOptions && options.length" data-quick-eligibility-options>
                    <p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-500">Quick add</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="option in options.slice(0, 8)" :key="option" type="button" :data-quick-option="option" :aria-label="`Add ${option}`" :disabled="hasValue(option)" class="min-h-8 rounded-sm border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-slate-700 disabled:cursor-default disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400" @click="add(option)">{{ option }}</button>
                    </div>
                </div>
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
                <span class="text-slate-500">{{ draftSelected.length }} selected</span>
            </div>
        </EligibilityOptionsModal>
        <FeedbackAlertModal v-model="choiceWarning" title="Review this eligibility rule" />
        <FeedbackAlertModal v-model="error" title="Eligibility option not added" />
    </div>
</template>
