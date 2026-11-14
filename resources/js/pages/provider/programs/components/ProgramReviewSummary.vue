<script setup>
import EligibilityRulesSummary from './EligibilityRulesSummary.vue';

defineProps({
    steps: { type: Array, required: true },
    summaries: { type: Object, required: true },
    checks: { type: Object, required: true },
    issues: { type: Object, required: true },
    rules: { type: Array, default: () => [] },
    logo: { type: String, default: '' },
    background: { type: String, default: '' },
});
defineEmits(['edit']);
</script>

<template>
    <div class="mt-5 w-full space-y-4" data-program-review>
        <section v-for="(step, index) in steps" :key="step.id" :data-review-section="step.id" class="border border-slate-200" :aria-labelledby="`program-review-${step.id}`">
            <header class="flex items-center gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                <span class="program-icon grid h-8 w-8 shrink-0 place-items-center bg-white text-slate-600" aria-hidden="true"><i :class="step.icon"></i></span>
                <h4 :id="`program-review-${step.id}`" class="min-w-0 flex-1 text-sm font-bold text-slate-950">{{ index + 1 }}. {{ step.label }}</h4>
                <span :class="['text-xs font-semibold', checks[step.id] ? 'text-emerald-700' : 'text-amber-800']" data-review-status>{{ checks[step.id] ? 'Complete' : 'Needs attention' }}</span>
                <button type="button" class="program-action ml-2 inline-flex items-center gap-2 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950" :aria-label="`Edit ${step.label}`" @click="$emit('edit', step.id)"><i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>Edit</button>
            </header>
            <div class="px-5 py-4">
                <p v-if="!checks[step.id]" class="mb-4 border-l-2 border-amber-400 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900" data-review-issue>{{ issues[step.id] }}</p>
                <div v-if="step.id === 'basics' && (logo || background)" class="mb-4 flex items-center gap-5 border-b border-slate-100 pb-4">
                    <figure v-if="logo" class="flex items-center gap-2.5"><img :src="logo" alt="Selected scholarship logo" class="h-12 w-12 border border-slate-200 bg-white object-contain p-1"><figcaption class="text-xs text-slate-500">Scholarship logo</figcaption></figure>
                    <figure v-if="background" class="flex items-center gap-2.5"><img :src="background" alt="Selected scholarship background" class="h-12 w-20 border border-slate-200 object-cover"><figcaption class="text-xs text-slate-500">Background</figcaption></figure>
                </div>
                <dl class="grid grid-cols-2 gap-x-8 gap-y-3">
                    <div v-for="item in summaries[step.id].facts" :key="item.label" :class="['min-w-0', item.wide ? 'col-span-2' : '']">
                        <dt class="text-xs text-slate-500">{{ item.label }}</dt>
                        <dd class="mt-1 whitespace-pre-line break-words text-sm font-semibold leading-5 text-slate-950">{{ item.value }}</dd>
                    </div>
                </dl>
                <div v-if="step.id === 'support'" class="mt-4 divide-y divide-slate-100 border-y border-slate-200">
                    <div v-for="(benefit, benefitIndex) in summaries.support.benefits" :key="benefitIndex" class="py-3">
                        <p class="break-words text-sm font-bold text-slate-950">{{ benefit.title }}</p>
                        <p class="mt-1 break-words text-xs leading-5 text-slate-600">{{ benefit.summary }}</p>
                    </div>
                    <p v-if="!summaries.support.benefits.length" class="py-3 text-sm text-slate-500">No benefits added.</p>
                </div>
                <p v-if="summaries[step.id].note" class="mt-3 text-xs leading-5 text-amber-800">{{ summaries[step.id].note }}</p>
                <details v-if="summaries[step.id].details.length" class="group mt-4 border-t border-slate-200 pt-3" :data-review-details="step.id">
                    <summary class="cursor-pointer text-xs font-bold leading-5 text-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-slate-800">{{ summaries[step.id].detailsLabel }}</summary>
                    <EligibilityRulesSummary v-if="step.id === 'eligibility'" class="mt-4" :rules="rules" />
                    <dl class="mt-4 space-y-4">
                        <div v-for="item in summaries[step.id].details" :key="item.label" class="min-w-0">
                            <dt class="text-xs font-bold text-slate-700">{{ item.label }}</dt>
                            <dd class="mt-1 whitespace-pre-line break-words text-sm leading-6 text-slate-600">
                                <template v-if="!item.items">{{ item.value }}</template>
                                <ul v-else-if="item.items.length" class="list-disc space-y-1 pl-5"><li v-for="(itemText, itemIndex) in item.items" :key="itemIndex">{{ itemText }}</li></ul>
                                <span v-else>Not set</span>
                            </dd>
                        </div>
                    </dl>
                </details>
            </div>
        </section>
    </div>
</template>
