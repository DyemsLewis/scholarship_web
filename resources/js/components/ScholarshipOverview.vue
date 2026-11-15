<script setup>
import { computed } from 'vue';
import { scholarshipBenefitFacts } from '../support/scholarshipBenefits';

const props = defineProps({
    content: { type: Object, required: true },
    navigation: { type: Array, default: () => [] },
    hasApplied: { type: Boolean, default: false },
    preview: { type: Boolean, default: false },
});
defineEmits(['navigate']);

const awardHref = computed(() => props.navigation.find(item => item.key === 'award')?.href);
const benefitFacts = computed(() => props.content.primary_benefit ? scholarshipBenefitFacts(props.content.primary_benefit) : []);
const resources = computed(() => [
    { key: 'eligibility', section: 'eligibility', title: 'Check eligibility', description: 'See the rules and how your profile compares.', icon: 'fa-solid fa-list-check' },
    { key: 'files', section: 'application', title: 'See how to apply', description: 'Prepare your files and follow the submission instructions.', icon: 'fa-regular fa-folder-open' },
    { key: 'process', section: 'application', title: 'Understand the selection', description: 'See the stages that follow pre-screening.', icon: 'fa-solid fa-route' },
].map(item => ({
    ...item,
    href: props.navigation.find(link => link.key === item.section)?.href,
    readiness: !props.preview && !props.hasApplied ? props.content.readiness?.find(row => row.key === item.key) : null,
})).filter(item => item.href));
const readinessClass = state => ({
    ready: 'student-badge-positive', attention: 'student-badge-attention', blocked: 'student-badge-negative',
}[state] || '');
</script>

<template>
    <div class="space-y-4" data-scholarship-overview>
        <section class="student-card overflow-hidden rounded-md border-slate-300" aria-labelledby="scholarship-about-title">
            <div class="min-w-0 px-5 py-6 sm:px-7">
                <p class="student-kicker">The opportunity</p>
                <h2 id="scholarship-about-title" class="mt-2 font-display text-2xl font-bold text-slate-950">About this scholarship</h2>
                <p class="mt-3 max-w-3xl whitespace-pre-line break-words text-sm leading-7 text-slate-600" data-program-description>{{ content.description || 'The provider has not added a program description.' }}</p>
            </div>

            <section class="border-t border-amber-200 bg-amber-50/70 px-5 py-5 sm:px-7" aria-labelledby="scholarship-benefit-title" data-overview-benefit>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">Primary benefit</p>
                        <h3 id="scholarship-benefit-title" class="mt-1 break-words text-lg font-bold text-slate-950">{{ content.primary_benefit?.title || 'Benefit details' }}</h3>
                    </div>
                    <a v-if="awardHref" :href="awardHref" class="inline-flex min-h-10 items-center gap-2 rounded-sm border border-amber-200 bg-white px-3 py-2 text-xs font-bold text-slate-800 hover:bg-amber-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600" @click="$emit('navigate', $event, 'award')">
                        <span v-if="content.additional_benefit_count">+{{ content.additional_benefit_count }} more <span aria-hidden="true">&middot;</span> </span>See benefits and terms<i class="fa-solid fa-arrow-right block text-xs leading-none" aria-hidden="true"></i>
                    </a>
                </div>
                <dl v-if="benefitFacts.length" class="mt-4 grid grid-cols-2 gap-x-5 gap-y-4 sm:grid-cols-4" data-overview-benefit-facts>
                    <div v-for="fact in benefitFacts" :key="fact.label" class="min-w-0">
                        <dt class="text-xs font-semibold text-slate-500">{{ fact.label }}</dt>
                        <dd class="mt-1 break-words text-sm font-bold leading-6 text-slate-950">{{ fact.value }}</dd>
                    </div>
                </dl>
                <p v-else class="mt-3 text-sm leading-6 text-slate-600">{{ content.primary_benefit?.display_summary || 'The provider has not published the benefit details yet.' }}</p>
            </section>
        </section>

        <section v-if="resources.length" class="student-card overflow-hidden rounded-md border-slate-300" aria-labelledby="scholarship-explore-title" data-overview-resources>
            <header class="border-b border-slate-200 px-5 py-4 sm:px-7">
                <h2 id="scholarship-explore-title" class="text-base font-bold text-slate-950">{{ hasApplied ? 'Explore the program' : 'Before you apply' }}</h2>
                <p class="mt-1 text-xs leading-5 text-slate-600">Review the details that matter. Meeting the rules does not guarantee selection.</p>
            </header>
            <div class="grid divide-y divide-slate-200 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                <a v-for="item in resources" :key="item.key" :href="item.href" class="group flex min-w-0 items-start gap-3 px-5 py-5 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-600 sm:px-6" :data-overview-resource="item.key" @click="$emit('navigate', $event, item.section)">
                    <span class="grid size-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i :class="[item.icon, 'block text-sm leading-none']" aria-hidden="true"></i></span>
                    <div class="flex min-w-0 flex-1 flex-col self-stretch">
                        <h3 class="text-sm font-bold text-slate-950">{{ item.title }}</h3>
                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ item.description }}</p>
                        <div v-if="item.readiness?.status" class="mt-auto pt-3"><span :class="['student-badge', readinessClass(item.readiness.state)]">{{ item.readiness.status }}</span></div>
                    </div>
                    <i class="fa-solid fa-arrow-right mt-1 block shrink-0 text-xs leading-none text-slate-400 group-hover:text-slate-800" aria-hidden="true"></i>
                </a>
            </div>
        </section>
    </div>
</template>
