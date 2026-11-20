<script setup>
import { computed, ref, watch } from 'vue';
import { normalizeProgramRecipientAgreement, recipientCommitmentOptions, recipientTermsFields, recipientTermsIssue } from '../../../../support/programRecipientAgreement.js';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
    disabled: Boolean,
    inputClass: { type: String, default: '' },
    textareaClass: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const terms = computed(() => normalizeProgramRecipientAgreement(props.modelValue));
const issue = computed(() => recipientTermsIssue(terms.value));
const hasCommitmentType = computed(() => terms.value.commitment_type !== 'provider_briefing');
const open = ref(false);
const draft = ref(normalizeProgramRecipientAgreement());
const fields = computed(() => recipientTermsFields(draft.value.commitment_type));

function changeCommitment(value) {
    if (props.disabled || !recipientCommitmentOptions.slice(1).some(([key]) => key === value)) return;
    emit('update:modelValue', { ...terms.value, commitment_type: value });
}

function show() {
    if (props.disabled || !hasCommitmentType.value) return;
    draft.value = { ...terms.value };
    open.value = true;
}

function close() { open.value = false; }

function apply() {
    if (!open.value || props.disabled || draft.value.commitment_type !== terms.value.commitment_type) return;
    emit('update:modelValue', { ...draft.value });
    close();
}

watch(() => props.disabled, disabled => { if (disabled) close(); });
watch(() => props.modelValue, close);
</script>

<template>
    <section class="rounded-sm border border-slate-200 bg-white px-4 py-3" aria-labelledby="recipient-terms-title" data-program-recipient-terms>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h4 id="recipient-terms-title" class="text-sm font-bold text-slate-950">Recipient terms</h4>
                <p class="mt-1 text-xs text-slate-500">What recipients must do and when they receive benefits.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-sm px-2 py-1 text-xs font-semibold" :class="issue ? 'bg-amber-50 text-amber-900' : 'bg-emerald-50 text-emerald-800'" aria-live="polite">{{ !hasCommitmentType ? 'Choose a commitment' : issue ? 'Needs details' : 'Ready' }}</span>
                <button type="button" class="min-h-8 rounded-sm border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" :disabled="disabled || !hasCommitmentType" aria-label="Edit recipient terms" aria-haspopup="dialog" :aria-expanded="open" @click="show">Edit terms</button>
            </div>
        </div>
        <label class="mt-3 block max-w-md text-xs font-bold text-slate-700">
            Recipient commitment <span class="text-amber-700">Required</span>
            <select :value="terms.commitment_type" :class="[inputClass, 'mt-1']" :disabled="disabled" @change="changeCommitment($event.target.value)">
                <option v-for="([key, label]) in recipientCommitmentOptions" :key="key" :value="key" :disabled="key === 'provider_briefing'">{{ label }}</option>
            </select>
        </label>

        <EligibilityOptionsModal :open="open" id="program-recipient-terms" title="Recipient terms" eyebrow="Program setup" description="Explain what recipients agree to and how they keep receiving support. You can apply partial details and finish them before submission." apply-label="Apply changes" :busy="disabled" @close="close" @apply="apply">
            <div class="grid gap-4 sm:grid-cols-2">
                <label v-for="field in fields" :key="field.key" class="block text-sm font-semibold text-slate-700" :class="field.key === 'release_conditions' ? 'sm:col-span-2' : ''">
                    {{ field.label }} <span class="text-xs text-amber-700">Required</span>
                    <input v-if="field.key === 'duration'" v-model="draft[field.key]" type="text" :maxlength="field.maxLength" :class="[inputClass, 'mt-1.5']" :data-recipient-terms-field="field.key">
                    <textarea v-else v-model="draft[field.key]" rows="3" :maxlength="field.maxLength" :class="[textareaClass, 'mt-1.5']" :data-recipient-terms-field="field.key" :placeholder="field.key === 'release_conditions' ? 'State when and how support will be released.' : undefined"></textarea>
                </label>
            </div>
        </EligibilityOptionsModal>
    </section>
</template>
