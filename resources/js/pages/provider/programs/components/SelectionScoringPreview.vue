<script setup>
import { computed } from 'vue';
import { programScoringPreview } from '../../../../support/programScoringPreview';

const props = defineProps({
    presets: { type: Object, default: () => ({}) },
    stages: { type: Array, required: true },
    fixedPolicy: { type: Object, default: null },
    applicationsCount: { type: Number, default: 0 },
    examMethod: { type: String, default: null },
    criterionLabels: { type: Object, default: null },
});
const preview = computed(() => programScoringPreview(props.presets, props.stages, props.fixedPolicy, props.applicationsCount, props.examMethod, props.criterionLabels));
</script>

<template>
    <section class="mt-4 rounded-sm border border-slate-200 bg-white px-4 py-3" data-program-scoring aria-labelledby="program-scoring-title">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h4 id="program-scoring-title" class="text-sm font-bold text-slate-950">How the final DSS score is calculated</h4>
                <p class="mt-1 text-xs leading-5 text-slate-500">Decision support combines completed stage evaluations. You still make the final decision.</p>
            </div>
            <span class="shrink-0 border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-600" data-scoring-state>{{ preview.fixed ? 'Fixed for this intake' : (preview.legacy ? 'Existing intake' : 'Plan preview') }}</span>
        </div>

        <template v-if="preview.available">
            <p v-if="preview.stagesDiffer" role="status" class="mt-3 border-l-2 border-amber-400 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">These weights belong to the existing intake. Changing its stage path requires a new intake; this preview does not recalculate saved applicant scores.</p>
            <details v-if="preview.rows.length" class="group mt-2" data-scoring-breakdown>
                <summary class="flex w-fit cursor-pointer list-none items-center gap-2 py-1.5 text-xs font-bold text-slate-700 [&::-webkit-details-marker]:hidden">
                    <i class="fa-solid fa-chevron-right text-[10px] group-open:rotate-90" aria-hidden="true"></i>View scoring breakdown
                </summary>
                <div class="mt-2 overflow-x-auto">
                    <table class="w-full border border-slate-200 text-left text-sm">
                        <caption class="sr-only">Contribution of each evaluation to the final DSS score</caption>
                        <thead class="bg-slate-50 text-xs text-slate-600">
                            <tr><th scope="col" class="px-3 py-2 font-bold sm:w-44">Evaluation</th><th scope="col" class="px-3 py-2 font-bold">Based on</th><th scope="col" class="px-3 py-2 text-right font-bold sm:w-32">Final weight</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="row in preview.rows" :key="row.key" :data-scoring-stage="row.key">
                                <th scope="row" class="px-3 py-2 font-bold text-slate-950">{{ row.label }}</th>
                                <td class="px-3 py-2 text-xs leading-5 text-slate-600">{{ row.basis }}</td>
                                <td class="px-3 py-2 text-right font-bold tabular-nums text-slate-950">{{ row.weight }}%</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-slate-200 bg-slate-50">
                            <tr><td colspan="2" class="px-3 py-2 text-xs leading-5 text-slate-600">{{ preview.formula }}</td><td class="px-3 py-2 text-right text-sm font-bold tabular-nums text-slate-950">{{ preview.total }}%</td></tr>
                        </tfoot>
                    </table>
                </div>
                <div class="mt-3 space-y-1.5 text-xs leading-5 text-slate-600">
                    <p><strong class="text-slate-800">Qualifying only:</strong> Eligibility, document checks, and formal application verification do not add ranking points.</p>
                    <p><strong class="text-slate-800">Ready for comparison:</strong> A final score appears only after all required evaluations and qualifying stages are complete and passed.</p>
                    <p v-if="!preview.fixed" class="text-slate-500">Weights are fixed when the first application is submitted.</p>
                </div>
            </details>
            <p v-else class="mt-2 text-xs text-slate-600">{{ preview.legacy ? 'This existing intake has no fixed scoring plan. Stage outcomes do not add a numeric ranking score.' : 'This intake uses qualifying checks only, without a numeric ranking score.' }}</p>
        </template>
        <p v-else class="mt-4 text-sm text-slate-600">The scoring preview could not be loaded. Reload the page before reviewing this plan.</p>
    </section>
</template>
