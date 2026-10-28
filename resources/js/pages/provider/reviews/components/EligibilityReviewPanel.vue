<script setup>
import { computed } from 'vue';
import EligibilityCriteriaTable from './EligibilityCriteriaTable.vue';
import EligibilityConditionList from '../../../../components/EligibilityConditionList.vue';

const props = defineProps({
    breakdown: { type: Object, default: () => ({}) },
    explanation: { type: Object, default: () => ({}) },
    score: { type: Object, default: () => ({}) },
    embedded: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});
const summary = computed(() => props.explanation?.comparison ?? {});
const context = computed(() => props.explanation?.eligibility_context ?? {});
const counts = computed(() => [
    { label: 'Required discrepancies', value: summary.value.required_not_met ?? 0 },
    { label: 'Preference differences', value: summary.value.preferred_not_met ?? 0 },
    { label: 'Evidence review', value: summary.value.manual_review ?? 0 },
    { label: 'Missing information', value: summary.value.missing ?? 0 },
]);
</script>

<template>
    <section :class="embedded ? '' : 'mt-4 border border-slate-200 bg-white shadow-sm'" data-provider-eligibility-review>
        <header class="flex items-start justify-between gap-6 border-b border-slate-200 px-6 py-5">
            <div class="flex items-start gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-scale-balanced text-sm" aria-hidden="true"></i></span>
                <div><h2 class="text-xl font-bold text-slate-950">Eligibility comparison</h2><p class="mt-1 text-sm text-slate-500">{{ context.label || 'Saved application comparison' }}</p></div>
            </div>
            <span class="shrink-0 border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">{{ summary.label || 'Comparison unavailable' }}</span>
        </header>
        <div class="border-b border-slate-200 bg-slate-50/60 px-6 py-4">
            <p class="text-sm leading-6 text-slate-700">{{ summary.interpretation || 'Review the saved values and supporting evidence before deciding.' }}</p>
            <dl class="mt-3 flex flex-wrap gap-x-7 gap-y-2 text-xs text-slate-500"><div v-for="count in counts" :key="count.label" class="flex gap-2"><dt>{{ count.label }}</dt><dd class="font-bold text-slate-900">{{ count.value }}</dd></div></dl>
            <p v-if="context.legacy_required_defaults" class="mt-2 text-xs leading-5 text-slate-500">Checks without saved rule-mode metadata remain Required. Current preferences are not applied retroactively.</p>
        </div>
        <div v-if="context.has_changes" class="border-b border-amber-200 bg-amber-50 px-6 py-4 text-sm text-amber-950" data-eligibility-snapshot-notice>
            <p class="font-bold">Information has changed since this comparison was saved.</p>
            <p v-if="context.rule_changes?.length" class="mt-1 text-xs leading-5">Program rules: {{ context.rule_changes.join(', ') }}.</p>
            <p v-if="context.profile_changes?.length" class="mt-1 text-xs leading-5">Applicant values: {{ context.profile_changes.join(', ') }}.</p>
            <p class="mt-1 text-xs leading-5">The saved comparison below is unchanged. Open the current comparison only when you need to inspect the differences.</p>
        </div>
        <details v-if="compact" class="group">
            <summary class="cursor-pointer px-6 py-4 text-sm font-bold text-slate-700">View saved rule comparison</summary>
            <EligibilityCriteriaTable :criteria="breakdown?.criteria ?? []" />
        </details>
        <EligibilityCriteriaTable v-else :criteria="breakdown?.criteria ?? []" />
        <details v-if="breakdown?.condition_results?.length" class="border-t border-slate-200">
            <summary class="cursor-pointer px-6 py-4 text-sm font-bold text-slate-700">Additional eligibility conditions <span class="ml-2 text-xs font-normal text-slate-500">Declarations and provider checks</span></summary>
            <div class="px-6 pb-4"><EligibilityConditionList :conditions="breakdown.condition_results" audience="provider" /></div>
        </details>
        <details v-if="context.has_changes && context.current_comparison" class="border-t border-slate-200" data-current-eligibility-comparison>
            <summary class="cursor-pointer px-6 py-4 text-sm font-bold text-slate-700">Current comparison <span class="ml-2 text-xs font-normal text-slate-500">Advisory, not the saved submission</span></summary>
            <p class="border-y border-slate-200 bg-slate-50 px-6 py-3 text-sm leading-6 text-slate-600">{{ context.current_comparison.summary?.interpretation }}</p>
            <EligibilityCriteriaTable :criteria="context.current_comparison.criteria ?? []" />
        </details>
        <details class="border-t border-slate-200" data-eligibility-advisory-score>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-4 text-sm font-bold text-slate-700"><span><i class="fa-solid fa-circle-info mr-2 text-slate-400" aria-hidden="true"></i>About the advisory DSS score</span><span>{{ score?.score ?? 'Not calculated' }}<template v-if="score?.score !== undefined && score?.score !== null"> / 100</template></span></summary>
            <div class="space-y-2 bg-slate-50 px-6 py-4 text-xs leading-5 text-slate-600"><p>{{ context.score_basis }}</p><p>File readiness is separate from suitability. Required discrepancies, preferred differences, and uncertain grades must not be reduced to a single pass/fail percentage.</p><p>{{ score?.decision_notice || 'The provider records the decision after reviewing the complete record. No result is recorded automatically.' }}</p></div>
        </details>
    </section>
</template>
