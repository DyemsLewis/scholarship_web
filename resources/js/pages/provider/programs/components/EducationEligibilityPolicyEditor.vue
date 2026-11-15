<script setup>
import { computed, ref, watch } from 'vue';
import EligibilityRuleModeSelect from './EligibilityRuleModeSelect.vue';
import EligibilityOptionsModal from './EligibilityOptionsModal.vue';
import FeedbackAlertModal from '../../../../components/FeedbackAlertModal.vue';
import { acceptedQualificationOptions, enrollmentStatusOptions, educationPolicyError } from '../../../../support/educationEligibilityPolicy.js';

const policy = defineModel({ type: Object, required: true });
const modes = defineModel('modes', { type: Object, required: true });
const props = defineProps({ inputClass: { type: String, required: true }, disabled: Boolean, conditions: { type: Array, default: () => [] } });
const configured = computed(() => policy.value.requires_completed_qualification || policy.value.enrollment_statuses.length > 0);
const open = ref(false);
const draft = ref(null);
const draftModes = ref({});
const error = ref('');
const description = computed(() => [
    policy.value.requires_completed_qualification
        ? policy.value.accepted_qualifications.map(value => acceptedQualificationOptions.find(option => option.value === value)?.label || value).join(', ') || 'Choose accepted qualifications'
        : 'No completed qualification required',
    policy.value.enrollment_statuses.length ? policy.value.enrollment_statuses.join(', ') : 'No additional enrollment restriction',
].join(' | '));
function show() {
    if (props.disabled) return;
    draft.value = { ...policy.value, accepted_qualifications: [...policy.value.accepted_qualifications], enrollment_statuses: [...policy.value.enrollment_statuses] };
    draftModes.value = { ...modes.value };
    error.value = ''; open.value = true;
}
function close() { open.value = false; error.value = ''; }
function apply() {
    if (props.disabled) return;
    error.value = educationPolicyError(draft.value, props.conditions) || '';
    if (error.value) return;
    policy.value = { ...draft.value, accepted_qualifications: [...draft.value.accepted_qualifications], enrollment_statuses: [...draft.value.enrollment_statuses] };
    modes.value = { ...modes.value, completed_qualification: draftModes.value.completed_qualification, enrollment_status: draftModes.value.enrollment_status };
    close();
}
function toggle(field, value) {
    draft.value[field] = draft.value[field].includes(value) ? draft.value[field].filter(item => item !== value) : [...draft.value[field], value];
}
watch(() => props.disabled, disabled => { if (disabled) close(); });
watch(policy, () => { if (open.value) close(); });
</script>

<template>
    <div class="mt-5 border-t border-slate-200 pt-5" data-education-policy-editor>
        <div class="flex items-center justify-between gap-4">
            <p class="text-xs font-bold text-slate-700">Completed qualifications and enrollment <span class="font-normal text-slate-400">Optional</span></p>
            <button id="program-education-policy" type="button" :disabled="disabled" aria-haspopup="dialog" :aria-expanded="open" aria-controls="program-education-policy-options" class="min-h-8 shrink-0 rounded-sm border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-50" @click="show">{{ configured ? 'Edit rules' : 'Configure' }}</button>
        </div>
        <p class="mt-2 text-xs leading-5 text-slate-500">{{ description }}</p>
        <EligibilityOptionsModal :open="open" id="program-education-policy" title="Qualifications and enrollment" @close="close" @apply="apply">
            <div v-if="draft">
                <p class="mt-2 text-xs leading-5 text-slate-500">These checks are separate from current-study filters. Leave those filters open if only a completed qualification matters.</p>
                <div class="mt-5 flex items-center justify-between gap-4">
                    <label class="flex items-center gap-3 text-sm font-semibold text-slate-800"><input v-model="draft.requires_completed_qualification" type="checkbox" class="border-slate-300 text-slate-950">Check a completed qualification</label>
                    <EligibilityRuleModeSelect v-if="draft.requires_completed_qualification" v-model="draftModes.completed_qualification" id-prefix="eligibility-policy-mode" rule-key="completed_qualification" label="Completed qualification" />
                </div>
                <div v-if="draft.requires_completed_qualification" class="mt-4">
                    <p class="text-xs leading-5 text-slate-600">Select only credentials this program accepts. An old-curriculum diploma, ALS certificate, vocational qualification, and degree are not automatically interchangeable.</p>
                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <label v-for="option in acceptedQualificationOptions" :key="option.value" class="flex min-h-11 items-center gap-2 border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">
                            <input type="checkbox" :checked="draft.accepted_qualifications.includes(option.value)" :value="option.value" @change="toggle('accepted_qualifications', option.value)">{{ option.label }}
                        </label>
                    </div>
                    <label class="mt-4 block text-xs font-bold text-slate-700" for="program-unlisted-qualification">Qualifications not listed</label>
                    <select id="program-unlisted-qualification" v-model="draft.unlisted_qualification" :class="[inputClass, 'mt-2']">
                        <option value="review">Send for provider review</option>
                        <option value="not_accepted">Treat as not matched</option>
                    </select>
                    <p class="mt-2 text-xs leading-5 text-slate-500">This setting applies to recognized credentials. Other or unclear qualifications still need review, even when a listed credential would not match.</p>
                </div>
                <div class="mt-5 border-t border-slate-200 pt-5">
                    <div class="flex items-center justify-between gap-4"><p class="text-xs font-bold text-slate-700">Accepted enrollment statuses</p><EligibilityRuleModeSelect v-if="draft.enrollment_statuses.length" v-model="draftModes.enrollment_status" id-prefix="eligibility-policy-mode" rule-key="enrollment_status" label="Enrollment status" /></div>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Leave all unchecked for no additional restriction. Pending transfer or return-to-study admission is not active enrollment.</p>
                    <div class="mt-3 grid grid-cols-3 gap-2">
                        <label v-for="status in enrollmentStatusOptions" :key="status" class="flex min-h-11 items-center gap-2 border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">
                            <input type="checkbox" :checked="draft.enrollment_statuses.includes(status)" :value="status" @change="toggle('enrollment_statuses', status)">{{ status }}
                        </label>
                    </div>
                </div>

            </div>
        </EligibilityOptionsModal>
        <FeedbackAlertModal v-model="error" title="Review qualification rules" />
    </div>
</template>
