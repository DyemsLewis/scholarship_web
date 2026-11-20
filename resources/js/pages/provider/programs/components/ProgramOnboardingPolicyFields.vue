<script setup>
import { computed, ref, watch } from 'vue';
import { onboardingPolicyError, programOnboardingPolicy } from '../../../../support/recipientOnboarding.js';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';

const props = defineProps({
    modelValue: { type: Object, default: null },
    fixed: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    textareaClass: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
const required = computed(() => Boolean(props.modelValue?.final_agreement_required));
const open = ref(false);
const draftRequired = ref(false);
const draftInstructions = ref('');
const draftPolicy = computed(() => programOnboardingPolicy({ final_agreement_required: draftRequired.value, instructions: draftInstructions.value }));
const issue = computed(() => onboardingPolicyError(draftPolicy.value));
const currentIssue = computed(() => onboardingPolicyError(props.modelValue));

function show() {
    if (props.disabled) return;
    draftRequired.value = required.value;
    draftInstructions.value = props.modelValue?.instructions || '';
    open.value = true;
}

function close() { open.value = false; }

function apply() {
    if (props.disabled || props.fixed) return;
    if (!open.value || issue.value) return;
    emit('update:modelValue', draftPolicy.value);
    close();
}

watch([() => props.disabled, () => props.fixed, () => props.modelValue], close);
</script>

<template>
    <section class="rounded-sm border border-slate-200 bg-white px-4 py-3" aria-labelledby="program-final-agreement-title" data-program-onboarding-policy>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h4 id="program-final-agreement-title" class="text-sm font-bold text-slate-950">Final provider agreement</h4>
                <p class="mt-1 text-xs text-slate-500">{{ fixed ? 'Fixed for this intake after the first application.' : 'A separate onboarding step before benefits are distributed.' }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-sm px-2 py-1 text-xs font-semibold" :class="currentIssue ? 'bg-amber-50 text-amber-900' : required ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'" aria-live="polite">{{ currentIssue ? 'Needs instructions' : required ? 'Required' : 'Not required' }}</span>
                <button type="button" class="min-h-8 rounded-sm border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" :disabled="disabled" :aria-label="fixed ? 'View final provider agreement' : 'Edit final provider agreement'" aria-haspopup="dialog" :aria-expanded="open" @click="show">{{ fixed ? 'View agreement' : 'Edit agreement' }}</button>
            </div>
        </div>

        <EligibilityOptionsModal :open="open" id="program-agreement-policy" title="Final provider agreement" eyebrow="Recipient onboarding" :description="fixed ? 'This setting is fixed after the first application. Use a new intake to change it.' : 'Keep the contract with your organization. The offer response only confirms the applicant intends to proceed.'" apply-label="Apply changes" :apply-disabled="Boolean(issue)" :busy="disabled" :view-only="fixed" @close="close" @apply="apply">
            <fieldset class="space-y-4" :disabled="disabled || fixed">
                <legend class="sr-only">Agreement requirement and instructions</legend>
                <label class="flex items-start gap-3 text-sm font-semibold leading-6 text-slate-700"><input v-model="draftRequired" type="checkbox" class="mt-1 h-4 w-4 shrink-0"><span>Require completion before benefit distribution</span></label>
                <label v-if="draftRequired" class="block text-sm font-semibold text-slate-700">How recipients complete the agreement <span class="text-xs text-amber-700">Required</span><textarea v-model="draftInstructions" rows="4" maxlength="3000" :class="[textareaClass, 'mt-1.5']" placeholder="For example: complete the agreement during orientation or the first benefit collection visit."></textarea><span class="mt-1.5 block text-xs font-normal text-slate-500">{{ issue || 'Describe the process, not the contents of the contract.' }}</span></label>
            </fieldset>
        </EligibilityOptionsModal>
    </section>
</template>
