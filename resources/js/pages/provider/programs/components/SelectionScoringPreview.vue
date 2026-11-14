<script setup>
import { computed } from 'vue';
import { programScoringPreview } from '../../../../support/programScoringPreview';

const props = defineProps({
    presets: { type: Object, default: () => ({}) },
    stages: { type: Array, required: true },
    fixedPolicy: { type: Object, default: null },
    applicationsCount: { type: Number, default: 0 },
});
const preview = computed(() => programScoringPreview(props.presets, props.stages, props.fixedPolicy, props.applicationsCount));
</script>

<template>
    <section class="mt-7 w-full border-t border-slate-200 pt-6" data-program-scoring aria-labelledby="program-scoring-title">
        <div class="flex items-start justify-between gap-6">
            <div>
                <h4 id="program-scoring-title" class="text-sm font-bold text-slate-950">How the final DSS score is calculated</h4>
                <p class="mt-1 text-xs leading-5 text-slate-500">Decision support combines completed stage evaluations. You still make the final decision.</p>
            </div>
            <span class="shrink-0 border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-600" data-scoring-state>{{ preview.fixed ? 'Fixed for this intake' : (preview.legacy ? 'Existing intake' : 'Plan preview') }}</span>
        </div>

        <template v-if="preview.available">
            <p v-if="preview.stagesDiffer" role="status" class="mt-3 border-l-2 border-amber-400 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">These weights belong to the existing intake. Changing its stage path requires a new intake; this preview does not recalculate saved applicant scores.</p>
            <table v-if="preview.rows.length" class="mt-4 w-full border border-slate-200 text-left text-sm">
                <caption class="sr-only">Contribution of each evaluation to the final DSS score</caption>
                <thead class="bg-slate-50 text-xs text-slate-600">
                    <tr><th scope="col" class="w-44 px-4 py-2.5 font-bold">Evaluation</th><th scope="col" class="px-4 py-2.5 font-bold">Based on</th><th scope="col" class="w-32 px-4 py-2.5 text-right font-bold">Final weight</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr v-for="row in preview.rows" :key="row.key" :data-scoring-stage="row.key">
                        <th scope="row" class="px-4 py-3 font-bold text-slate-950">{{ row.label }}</th>
                        <td class="px-4 py-3 text-xs leading-5 text-slate-600">{{ row.basis }}</td>
                        <td class="px-4 py-3 text-right font-bold tabular-nums text-slate-950">{{ row.weight }}%</td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-slate-200 bg-slate-50">
                    <tr><td colspan="2" class="px-4 py-2.5 text-xs leading-5 text-slate-600">{{ preview.formula }}</td><td class="px-4 py-2.5 text-right text-sm font-bold tabular-nums text-slate-950">{{ preview.total }}%</td></tr>
                </tfoot>
            </table>
            <p v-else class="mt-4 border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ preview.legacy ? 'This existing intake has no fixed scoring plan. Its stage outcomes continue without adding a numeric ranking score.' : 'This intake uses qualifying checks only, without a numeric ranking score.' }}</p>
            <div class="mt-3 space-y-1.5 text-xs leading-5 text-slate-600">
                <p><strong class="text-slate-800">Qualifying only:</strong> Eligibility, document checks, and formal application verification do not add ranking points.</p>
                <p v-if="preview.rows.length"><strong class="text-slate-800">Ready for comparison:</strong> A final score appears only after all required evaluations and qualifying stages are complete and passed.</p>
                <p v-if="!preview.fixed && !preview.legacy" class="text-slate-500">Weights are fixed when the first application is submitted.</p>
            </div>
        </template>
        <p v-else class="mt-4 text-sm text-slate-600">The scoring preview could not be loaded. Reload the page before reviewing this plan.</p>
    </section>
</template>
