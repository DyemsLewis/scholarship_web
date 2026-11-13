<script setup>
defineProps({
    evaluation: { type: Object, required: true },
    comparison: { type: Object, default: null },
    embedded: { type: Boolean, default: false },
    expanded: { type: Boolean, default: false },
});
const number = (value) => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
</script>

<template>
    <section :class="embedded ? 'bg-white' : 'mt-4 border border-slate-200 bg-white shadow-sm'" aria-label="Selection evaluation" data-selection-evaluation>
        <div class="flex items-center gap-4 px-6 py-4">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded bg-slate-100 text-slate-600"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span>
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-bold text-slate-950">Selection evaluation</h2>
                <p class="mt-1 text-sm text-slate-600">{{ evaluation.description }}</p>
            </div>
            <div class="shrink-0 border-l border-slate-200 pl-6 text-right">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ evaluation.score === null ? evaluation.label : 'Overall score' }}</p>
                <p v-if="evaluation.score !== null" class="mt-1 text-2xl font-bold tabular-nums text-slate-950">{{ number(evaluation.score) }}<span class="ml-1 text-sm font-normal text-slate-500">/ 100</span></p>
                <p v-else-if="evaluation.policy_frozen && evaluation.required" class="mt-1 text-sm font-bold text-slate-950">{{ evaluation.completed }} of {{ evaluation.required }} evaluations</p>
                <p v-else class="mt-1 text-sm font-semibold text-slate-600">No numeric ranking</p>
            </div>
        </div>
        <div v-if="comparison?.label" class="flex items-center justify-between gap-5 border-t border-slate-200 bg-slate-50 px-6 py-3 text-xs" data-selection-guidance>
            <p class="font-semibold text-slate-700"><span v-if="comparison.rank !== null && comparison.rank !== undefined" class="mr-3 font-bold text-slate-950">{{ comparison.historical ? 'Saved rank' : 'Rank' }} {{ comparison.rank }} of {{ comparison.ranked_count }}{{ comparison.tied ? ' (shared)' : '' }}</span>{{ comparison.label }}<span v-if="comparison.historical" class="ml-2 font-normal text-slate-500">At the time of this decision</span></p>
            <a v-if="!comparison.historical && comparison.comparison_url" :href="comparison.comparison_url" class="shrink-0 font-bold text-slate-700 hover:text-slate-950">Compare program applicants<i class="fa-solid fa-arrow-right ml-2 text-[10px]" aria-hidden="true"></i></a>
        </div>
        <details :open="expanded" class="group border-t border-slate-200" data-selection-breakdown>
            <summary class="flex cursor-pointer list-none items-center justify-between px-6 py-3 text-xs font-bold text-slate-700 hover:bg-slate-50">
                <span>View breakdown<span class="ml-2 font-normal text-slate-500">{{ evaluation.policy_frozen ? 'Fixed scoring plan' : 'Scoring plan preview' }}</span></span>
                <i class="fa-solid fa-chevron-down text-[10px] group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <div class="border-t border-slate-200 px-6 pb-4">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Stage scores and their contribution to the selection evaluation</caption>
                    <thead class="border-b border-slate-200 text-xs text-slate-500"><tr><th class="py-3 font-semibold">Stage</th><th class="py-3 font-semibold">Weight</th><th class="py-3 font-semibold">Evaluation</th><th class="py-3 text-right font-semibold">Points</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="stage in evaluation.stages" :key="stage.key">
                            <th scope="row" class="py-3 pr-4 font-semibold text-slate-900">{{ stage.label }}</th>
                            <td class="py-3 pr-4 text-slate-600">{{ stage.weight === null ? 'Qualifying check' : `${stage.weight}%` }}</td>
                            <td class="py-3 pr-4 text-slate-600"><span v-if="stage.score !== null" class="font-semibold tabular-nums text-slate-900">{{ number(stage.score) }} / 100</span><span v-else>{{ stage.status_label }}</span></td>
                            <td class="py-3 text-right font-semibold tabular-nums text-slate-900">{{ stage.contribution === null ? '-' : number(stage.contribution) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-3 border-l-2 border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-5 text-slate-700">Missing evaluations are not zero scores. Formal verification and document completeness do not earn ranking points.</p>
                <p v-if="evaluation.stages.find(stage => stage.key === 'screening')?.criteria?.length" class="mt-3 text-xs text-slate-500">Screening reuses the reviewed {{ evaluation.stages.find(stage => stage.key === 'screening').criteria.map(row => row.label.toLowerCase()).join(' and ') }} scores, in proportion to their rubric weights.</p>
                <p class="mt-2 text-xs leading-5 text-slate-500">{{ evaluation.notice }}</p>
            </div>
        </details>
    </section>
</template>
