<script setup>
import { computed } from 'vue';
import EligibilityRuleModeSelect from './EligibilityRuleModeSelect.vue';
import { acceptedQualificationOptions, enrollmentStatusOptions } from '../../../../support/educationEligibilityPolicy.js';

const policy = defineModel({ type: Object, required: true });
const modes = defineModel('modes', { type: Object, required: true });
defineProps({ inputClass: { type: String, required: true } });
const configured = computed(() => policy.value.requires_completed_qualification || policy.value.enrollment_statuses.length > 0);
function toggle(field, value) {
    policy.value[field] = policy.value[field].includes(value) ? policy.value[field].filter((item) => item !== value) : [...policy.value[field], value];
}
</script>

<template>
    <details data-education-policy-editor :open="configured" class="group mt-6 border-y border-slate-200 py-5">
        <summary class="flex cursor-pointer items-center justify-between gap-4 text-sm font-bold text-slate-900">
            <span>Completed qualifications and enrollment <span class="font-normal text-slate-500">(optional)</span></span>
            <i class="fa-solid fa-chevron-down text-[10px] text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i>
        </summary>
        <p class="mt-2 text-xs leading-5 text-slate-500">These checks are separate from current-study filters. Leave those filters open if only a completed qualification matters.</p>
        <div class="mt-5 flex items-center justify-between gap-4">
            <label class="flex items-center gap-3 text-sm font-semibold text-slate-800"><input v-model="policy.requires_completed_qualification" type="checkbox" class="border-slate-300 text-slate-950">Check a completed qualification</label>
            <EligibilityRuleModeSelect v-if="policy.requires_completed_qualification" v-model="modes.completed_qualification" rule-key="completed_qualification" label="Completed qualification" />
        </div>
        <div v-if="policy.requires_completed_qualification" class="mt-4">
            <p class="text-xs leading-5 text-slate-600">Select only credentials this program accepts. An old-curriculum diploma, ALS certificate, vocational qualification, and degree are not automatically interchangeable.</p>
            <div class="mt-3 grid grid-cols-3 gap-2">
                <label v-for="option in acceptedQualificationOptions" :key="option.value" class="flex min-h-11 items-center gap-2 border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">
                    <input type="checkbox" :checked="policy.accepted_qualifications.includes(option.value)" :value="option.value" @change="toggle('accepted_qualifications', option.value)">{{ option.label }}
                </label>
            </div>
            <label class="mt-4 block text-xs font-bold text-slate-700" for="program-unlisted-qualification">Qualifications not listed</label>
            <select id="program-unlisted-qualification" v-model="policy.unlisted_qualification" :class="[inputClass, 'mt-2']">
                <option value="review">Send for provider review</option>
                <option value="not_accepted">Treat as not matched</option>
            </select>
            <p class="mt-2 text-xs leading-5 text-slate-500">This setting applies to recognized credentials. Other or unclear qualifications still need review, even when a listed credential would not match.</p>
        </div>
        <div class="mt-5 border-t border-slate-200 pt-5">
            <div class="flex items-center justify-between gap-4"><p class="text-xs font-bold text-slate-700">Accepted enrollment statuses</p><EligibilityRuleModeSelect v-if="policy.enrollment_statuses.length" v-model="modes.enrollment_status" rule-key="enrollment_status" label="Enrollment status" /></div>
            <p class="mt-1 text-xs leading-5 text-slate-500">Leave all unchecked for no additional restriction. Pending transfer or return-to-study admission is not active enrollment.</p>
            <div class="mt-3 grid grid-cols-3 gap-2">
                <label v-for="status in enrollmentStatusOptions" :key="status" class="flex min-h-11 items-center gap-2 border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">
                    <input type="checkbox" :checked="policy.enrollment_statuses.includes(status)" :value="status" @change="toggle('enrollment_statuses', status)">{{ status }}
                </label>
            </div>
        </div>
    </details>
</template>
