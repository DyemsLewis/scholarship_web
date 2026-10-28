<script setup>
import { computed } from 'vue';
import { eligibilityCriterionPresentation } from '../../../../support/applicantEligibility';
import { labelFromKey } from '../../../../support/display';

const props = defineProps({ criteria: { type: Array, default: () => [] } });
const rows = computed(() => props.criteria.filter((item) => item.category !== 'readiness' && item.key !== 'documents'));
const presentation = eligibilityCriterionPresentation;
const tones = {
    ready: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    blocked: 'border-rose-200 bg-rose-50 text-rose-800',
    attention: 'border-amber-200 bg-amber-50 text-amber-900',
    neutral: 'border-slate-200 bg-slate-50 text-slate-600',
};

function display(value, fallback) {
    const text = String(value ?? '').trim();
    return text ? (text.includes('_') ? labelFromKey(text) : text) : fallback;
}

function reviewNote(criterion) {
    const status = presentation(criterion).status;
    if (status === 'review') return criterion.comparison_mode === 'scale_mismatch'
        ? 'Different grading scales. Confirm the academic record manually; this is not missing profile data or an automatic failure.'
        : 'Review the academic evidence manually. This check is not an automatic match or failure.';
    if (status === 'preferred') return 'A provider preference, not a submission blocker. Consider it with the complete applicant record.';
    if (status === 'fail') return 'Confirm this discrepancy against the saved profile and supporting evidence before deciding.';
    if (status === 'missing') return 'This information was unavailable in this comparison. Check the profile and evidence before deciding.';
    return '';
}
</script>

<template>
    <div class="overflow-x-auto" data-provider-eligibility-criteria>
        <table v-if="rows.length" class="w-full min-w-[52rem] table-fixed border-collapse text-left">
            <caption class="sr-only">Eligibility rules, applicant values, program requirements, and review results</caption>
            <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">
                <tr><th scope="col" class="w-[26%] px-6 py-3">Rule</th><th scope="col" class="w-[24%] px-4 py-3">Applicant value</th><th scope="col" class="w-[29%] px-4 py-3">Program requirement</th><th scope="col" class="w-[21%] px-6 py-3 text-right">Result</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <tr v-for="criterion in rows" :key="criterion.key">
                    <th scope="row" class="break-words px-6 py-4 align-top text-sm font-bold text-slate-900">
                        {{ criterion.label }}
                        <span v-if="presentation(criterion).modeLabel" class="mt-1 block text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ presentation(criterion).modeLabel }}</span>
                        <span v-if="reviewNote(criterion)" class="mt-2 block text-xs font-normal leading-5 text-slate-500">{{ reviewNote(criterion) }}</span>
                    </th>
                    <td class="break-words px-4 py-4 align-top text-sm leading-6 text-slate-700">{{ display(criterion.student_value ?? criterion.studentValue, 'Not provided') }}</td>
                    <td class="break-words px-4 py-4 align-top text-sm leading-6 text-slate-700">
                        {{ presentation(criterion).status === 'info' ? 'No restriction' : display(criterion.requirement, 'Not specified') }}
                        <details v-if="presentation(criterion).status === 'review' && criterion.equivalence" class="mt-2 text-xs">
                            <summary class="cursor-pointer font-semibold text-slate-600">Reference grade equivalents</summary>
                            <p class="mt-1">{{ criterion.equivalence.applicant }} / {{ criterion.equivalence.requirement }}</p>
                            <p class="mt-1 text-slate-500">{{ criterion.equivalence.notice }}</p>
                        </details>
                    </td>
                    <td class="px-6 py-4 text-right align-top"><span :class="['inline-flex border px-2.5 py-1 text-left text-[10px] font-bold uppercase tracking-wide', tones[presentation(criterion).state]]">{{ presentation(criterion).label }}</span></td>
                </tr>
            </tbody>
        </table>
        <p v-else class="px-6 py-8 text-sm text-slate-500">No saved rule checks are available. Review the evidence rather than treating an empty comparison as a match.</p>
    </div>
</template>
