<script setup>
import { computed } from 'vue';
import { programOnboardingPolicy } from '../../../../support/recipientOnboarding';

const props = defineProps({
    modelValue: { type: Object, default: null },
    fixed: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    textareaClass: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const required = computed(() => Boolean(props.modelValue?.final_agreement_required));
function update(value) {
    if (props.disabled || props.fixed) return;
    emit('update:modelValue', programOnboardingPolicy(value));
}
</script>

<template>
    <fieldset class="mt-6 border-t border-slate-200 pt-5" data-program-onboarding-policy :disabled="disabled || fixed">
        <legend class="sr-only">Final provider agreement</legend>
        <h3 class="text-base font-bold text-slate-950">Final provider agreement</h3>
        <p class="mt-1 text-sm leading-6 text-slate-500">Keep the actual contract with your organization. The applicant's offer response only confirms intention to proceed.</p>
        <label class="mt-4 flex items-start gap-3 text-sm font-semibold leading-6 text-slate-700"><input type="checkbox" :checked="required" class="mt-1 h-4 w-4 shrink-0" @change="update({ final_agreement_required: $event.target.checked, instructions: modelValue?.instructions || '' })"><span>Require completion of the final agreement before benefit distribution</span></label>
        <label v-if="required" class="mt-4 block text-sm font-semibold text-slate-700">How recipients complete the agreement<textarea :value="modelValue.instructions || ''" rows="3" maxlength="3000" :class="textareaClass" class="mt-2" placeholder="For example: complete the agreement during orientation or your first collection visit. Contact the scholarship office for arrangements." @input="update({ ...modelValue, instructions: $event.target.value })"></textarea><span class="mt-1.5 block text-xs font-normal leading-5 text-slate-500">Disclose the process, not the contract contents. Recipient staff can publish practical instructions after the applicant confirms intention.</span></label>
        <p v-if="fixed" class="mt-3 text-xs leading-5 text-slate-500">Fixed after the first application. Use a new intake to change this requirement; existing recipients will not inherit new obligations.</p>
    </fieldset>
</template>
