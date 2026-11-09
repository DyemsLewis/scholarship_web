<script setup>
import { computed } from 'vue';
import { scholarshipBenefitFacts } from '../support/scholarshipBenefits';

const props = defineProps({ content: { type: Object, required: true } });
const benefits = computed(() => (props.content.benefits ?? []).map((benefit) => ({ ...benefit, facts: scholarshipBenefitFacts(benefit) })));
const keyTerms = ['responsibilities', 'required_evidence', 'release_conditions', 'noncompliance_consequence', 'return_service_contract'];
const terms = computed(() => props.content.terms ?? []);
const importantTerms = computed(() => keyTerms.map((key) => terms.value.find((term) => term.key === key)).filter(Boolean));
const additionalTerms = computed(() => terms.value.filter((term) => !keyTerms.includes(term.key)));
</script>

<template>
    <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-benefits aria-labelledby="scholarship-benefits-title">
        <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 border-b border-slate-200 px-5 py-5 sm:px-6 sm:py-6">
            <div>
                <p class="student-kicker">Benefits</p>
                <h2 id="scholarship-benefits-title" class="mt-1.5 font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">What recipients receive</h2>
            </div>
            <div v-if="content.support_period && content.support_period !== 'Not specified'" class="sm:text-right">
                <p class="student-detail-label">Support period</p>
                <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ content.support_period }}</p>
            </div>
        </header>

        <ul v-if="benefits.length" class="divide-y divide-slate-200">
            <li v-for="(benefit, index) in benefits" :key="`${benefit.type}-${index}`" class="grid gap-3 px-5 py-4 sm:px-6 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:items-center lg:gap-6" data-scholarship-benefit>
                <div class="min-w-0">
                    <h3 class="text-base font-bold text-slate-950 [overflow-wrap:anywhere]">{{ benefit.title }}</h3>
                    <p v-if="benefit.description" class="mt-1 text-sm leading-6 text-slate-600 [overflow-wrap:anywhere]">{{ benefit.description }}</p>
                </div>
                <dl v-if="benefit.facts.length" class="grid min-w-0 grid-cols-2 gap-x-3 gap-y-2 border-slate-200 sm:grid-cols-[repeat(auto-fit,minmax(6rem,1fr))] lg:border-l lg:pl-5">
                    <div v-for="fact in benefit.facts" :key="fact.label" class="min-w-0">
                        <dt class="student-detail-label">{{ fact.label }}</dt>
                        <dd :class="['mt-0.5 text-sm font-bold leading-5 [overflow-wrap:anywhere]', fact.label === 'Amount' ? 'text-amber-900' : 'text-slate-900']">{{ fact.value }}</dd>
                    </div>
                </dl>
            </li>
        </ul>
        <p v-else class="px-5 py-5 text-sm text-slate-600 sm:px-6">The provider has not published a detailed benefit package.</p>
    </section>

    <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-terms aria-labelledby="scholarship-terms-title">
        <header class="border-b border-slate-200 px-5 py-5 sm:px-6">
            <p class="student-kicker">Recipient terms</p>
            <h2 id="scholarship-terms-title" class="mt-1.5 font-display text-2xl font-bold text-slate-950">What recipients agree to</h2>
            <p v-if="terms.length" class="mt-2 text-sm leading-6 text-slate-600">Review these conditions before accepting an award.</p>
        </header>
        <dl v-if="importantTerms.length" class="divide-y divide-slate-200" data-scholarship-key-terms>
            <div v-for="term in importantTerms" :key="term.key" class="grid gap-1 px-5 py-3.5 sm:grid-cols-[minmax(9rem,15rem)_minmax(0,1fr)] sm:gap-5 sm:px-6" :data-scholarship-term="term.key">
                <dt class="text-sm font-bold leading-6 text-slate-950">{{ term.label }}</dt>
                <dd class="whitespace-pre-line text-sm leading-6 text-slate-700 [overflow-wrap:anywhere]">{{ term.value }}</dd>
            </div>
        </dl>
        <details v-if="additionalTerms.length" class="group border-slate-200" :class="importantTerms.length && 'border-t'" data-scholarship-additional-terms>
            <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-4 bg-slate-50/70 px-5 py-3 text-sm font-bold text-slate-950 hover:bg-slate-100 sm:px-6 [&::-webkit-details-marker]:hidden">
                <span>More recipient terms <span class="ml-1 text-xs font-normal text-slate-600">({{ additionalTerms.length }})</span></span>
                <i class="fa-solid fa-chevron-down text-xs text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <dl class="divide-y divide-slate-200 border-t border-slate-200">
                <div v-for="term in additionalTerms" :key="term.key" class="grid gap-1 px-5 py-3.5 sm:grid-cols-[minmax(9rem,15rem)_minmax(0,1fr)] sm:gap-5 sm:px-6" :data-scholarship-term="term.key">
                    <dt class="text-sm font-bold leading-6 text-slate-950">{{ term.label }}</dt>
                    <dd class="whitespace-pre-line text-sm leading-6 text-slate-700 [overflow-wrap:anywhere]">{{ term.value }}</dd>
                </div>
            </dl>
        </details>
        <p v-if="!terms.length" class="px-5 py-5 text-sm text-slate-600 sm:px-6">No additional recipient terms were published.</p>
    </section>
</template>
