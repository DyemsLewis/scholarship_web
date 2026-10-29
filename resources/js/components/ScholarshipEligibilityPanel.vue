<script setup>
import { computed } from 'vue';
import { eligibilityValue, scholarshipEligibilityGroups, scholarshipEligibilityRows } from '../support/scholarshipEligibility';
import { withPreparationContext } from '../support/scholarshipPreparationContext';

const props = defineProps({
    content: { type: Object, required: true },
    verdict: { type: Object, required: true },
    visual: { type: Object, required: true },
    preview: { type: Boolean, default: false },
    previewScenario: { type: String, default: 'general' },
    applicationHref: { type: String, default: '' },
    scholarshipId: { type: [String, Number], default: '' },
});
defineEmits(['navigate']);

const rows = computed(() => scholarshipEligibilityRows(props.content.criteria ?? []));
const groups = computed(() => scholarshipEligibilityGroups(rows.value));
const openRows = computed(() => rows.value.filter((row) => row.result.status === 'info'));
const counts = computed(() => ({
    matched: rows.value.filter((row) => row.result.status === 'pass').length,
    attention: rows.value.filter((row) => row.attention).length,
    open: openRows.value.length,
}));
const generalPreview = computed(() => props.preview && props.previewScenario === 'general');
const profileAction = computed(() => props.content.profile?.complete
    ? { label: 'View verification', href: '/dashboard/profile?section=verification' }
    : { label: 'Complete profile', href: '/dashboard/profile' });
const hasReadiness = computed(() => (props.content.criteria ?? []).some((row) => row.category === 'readiness' || row.key === 'documents'));
const contextualHref = (href) => props.preview ? href : withPreparationContext(href, props.scholarshipId, 'eligibility');

function resultStyle(row) {
    return {
        ready: { badge: 'border-emerald-200 bg-emerald-50 text-emerald-800', icon: 'fa-solid fa-check' },
        blocked: { badge: 'border-rose-200 bg-rose-50 text-rose-800', icon: 'fa-solid fa-xmark' },
        attention: { badge: 'border-amber-200 bg-amber-50 text-amber-900', icon: 'fa-solid fa-circle-info' },
    }[row.result.state] ?? { badge: 'border-slate-200 bg-slate-50 text-slate-600', icon: 'fa-solid fa-minus' };
}

function actionLabel(row) {
    if (row.result.status === 'review') return 'View supporting evidence';
    return row.result.status === 'missing' ? 'Add profile details' : 'Review profile details';
}
</script>

<template>
    <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-eligibility aria-labelledby="scholarship-eligibility-title">
        <header class="grid border-b border-slate-200 lg:grid-cols-[minmax(0,1fr)_19rem]">
            <div class="px-5 py-5 sm:px-6 sm:py-6">
                <p class="student-kicker">{{ generalPreview ? 'Published eligibility' : verdict.eyebrow }}</p>
                <h3 id="scholarship-eligibility-title" class="mt-2 font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">{{ generalPreview ? 'Who this scholarship is for' : verdict.title }}</h3>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ generalPreview ? 'Applicants see these rules compared with their own saved profile.' : verdict.description }}</p>
                <a v-if="!preview && (!content.profile?.complete || !content.profile?.verified)" :href="contextualHref(profileAction.href)" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-800 transition hover:bg-slate-50">{{ profileAction.label }}<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
            </div>
            <figure class="relative hidden min-h-44 overflow-hidden bg-slate-100 lg:block">
                <img :src="visual.image" :alt="visual.alt" class="absolute inset-0 h-full w-full object-cover">
                <figcaption class="absolute inset-x-0 bottom-0 bg-slate-950/85 px-4 py-2 text-xs font-semibold leading-5 text-white">{{ visual.caption }}</figcaption>
            </figure>
        </header>

        <p v-if="preview && !generalPreview" class="border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-600 sm:px-6" data-preview-comparison-note>Illustrative sample only. These are not real applicant values or a recorded review decision.</p>

        <dl v-if="rows.length && !generalPreview" data-eligibility-summary class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-slate-200 bg-slate-50/70 px-5 py-3 text-xs sm:px-6" aria-label="Profile comparison summary">
            <div class="flex items-center gap-2"><dt class="text-slate-500">Matched</dt><dd class="font-bold tabular-nums text-slate-950">{{ counts.matched }}</dd></div>
            <div class="flex items-center gap-2"><dt class="text-slate-500">Need attention</dt><dd :class="['font-bold tabular-nums', counts.attention ? 'text-amber-900' : 'text-slate-950']">{{ counts.attention }}</dd></div>
            <div class="flex items-center gap-2"><dt class="text-slate-500">Unrestricted</dt><dd class="font-bold tabular-nums text-slate-950">{{ counts.open }}</dd></div>
        </dl>

        <section v-for="group in groups" :key="group.id" :data-eligibility-group="group.id" :aria-labelledby="`eligibility-${group.id}-title`">
            <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-slate-200 px-5 py-4 sm:px-6">
                <h4 :id="`eligibility-${group.id}-title`" class="text-sm font-bold text-slate-950">{{ group.label }} <span class="ml-1 text-xs font-normal text-slate-500">({{ group.rows.length }})</span></h4>
                <p class="text-xs leading-5 text-slate-500">{{ group.description }}</p>
            </header>
            <div class="hidden grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_12rem] gap-5 border-b border-slate-200 px-6 py-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500 md:grid" aria-hidden="true">
                <span>Program requirement</span><span>{{ preview ? 'Sample profile' : 'Your saved profile' }}</span><span>Comparison</span>
            </div>
            <div class="divide-y divide-slate-200">
                <article v-for="row in group.rows" :key="row.id" :data-eligibility-criterion="row.key" :data-comparison="row.result.status" class="px-5 py-4 sm:px-6">
                    <div class="grid min-w-0 gap-x-5 gap-y-3 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_12rem] md:items-start">
                        <div class="min-w-0">
                            <h5 class="text-sm font-bold text-slate-950">{{ eligibilityValue(row.label, '', 'Eligibility requirement') }}</h5>
                            <p class="mt-1 break-words text-sm leading-6 text-slate-600 [overflow-wrap:anywhere]">{{ row.requirementValue }}</p>
                        </div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400 md:sr-only">{{ preview ? 'Sample profile' : 'Your saved profile' }}</p>
                            <p class="break-words text-sm font-semibold leading-6 text-slate-800 [overflow-wrap:anywhere]">{{ generalPreview ? 'Compared when an applicant applies' : row.profileValue }}</p>
                        </div>
                        <div data-eligibility-result class="flex min-w-0 items-start md:justify-start">
                            <span v-if="generalPreview" class="inline-flex items-center gap-2 rounded-sm border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold leading-5 text-slate-600">Not compared</span>
                            <span v-else :class="['inline-flex items-center gap-2 rounded-sm border px-2.5 py-1 text-xs font-bold leading-5', resultStyle(row).badge]"><i :class="[resultStyle(row).icon, 'block shrink-0 text-[10px] leading-none']" aria-hidden="true"></i>{{ row.result.label }}</span>
                        </div>
                    </div>

                    <div v-if="row.attention && !generalPreview" data-eligibility-explanation class="mt-3 border-l-2 border-slate-200 pl-3">
                        <p class="text-xs leading-5 text-slate-600">{{ row.explanation || 'This comparison has not been confirmed. Review the published requirement and your saved information.' }}</p>
                        <a v-if="!preview && ['missing', 'review', 'fail'].includes(row.result.status)" :href="contextualHref(row.profileHref)" class="mt-1.5 inline-flex min-h-7 items-center gap-2 text-xs font-bold text-slate-800 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">{{ actionLabel(row) }}<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
                        <p v-if="row.result.status === 'fail'" class="mt-1 text-xs leading-5 text-slate-500">Only correct your profile if the saved information is inaccurate.</p>
                    </div>
                    <details v-else-if="row.explanation && !generalPreview" data-eligibility-explanation class="mt-2 text-xs">
                        <summary class="w-fit cursor-pointer py-1 font-semibold text-slate-500 hover:text-slate-800">How this was checked</summary>
                        <p class="mt-1 max-w-4xl border-l-2 border-slate-200 pl-3 leading-5 text-slate-600">{{ row.explanation }}</p>
                    </details>
                </article>
            </div>
        </section>

        <p v-if="!rows.length" data-eligibility-empty class="px-5 py-5 text-sm leading-6 text-slate-600 sm:px-6">The provider has not published structured eligibility rules. Review any written conditions below; your profile and application requirements still apply.</p>

        <details v-if="openRows.length" data-unrestricted-criteria class="group border-t border-slate-200 px-5 py-4 sm:px-6">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-bold text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-600 [&::-webkit-details-marker]:hidden"><span>No restrictions <span class="ml-1 text-xs font-normal text-slate-500">({{ openRows.length }})</span></span><i class="fa-solid fa-chevron-down text-[10px] text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i></summary>
            <p class="mt-2 text-xs leading-5 text-slate-500">The provider accepts any value for these criteria. They are not counted as profile matches.</p>
            <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-700"><li v-for="row in openRows" :key="row.id" class="inline-flex items-center gap-2"><i class="fa-solid fa-minus text-[10px] text-slate-400" aria-hidden="true"></i>{{ eligibilityValue(row.label) }}</li></ul>
        </details>

        <details v-if="content.written_conditions || content.provider_review?.length" data-provider-eligibility-conditions class="group border-t border-slate-200 px-5 py-4 sm:px-6">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-bold text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-600 [&::-webkit-details-marker]:hidden"><span>Conditions checked by the provider</span><i class="fa-solid fa-chevron-down text-[10px] text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i></summary>
            <p class="mt-2 text-xs leading-5 text-slate-500">These conditions need confirmation during application review.</p>
            <p v-if="content.written_conditions" class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-700">{{ eligibilityValue(content.written_conditions) }}</p>
            <ul v-if="content.provider_review?.length" class="mt-3 divide-y divide-slate-200">
                <li v-for="condition in content.provider_review" :key="condition.key || condition.label" class="py-3 first:pt-0"><p class="text-sm font-semibold text-slate-950">{{ eligibilityValue(condition.label, '', 'Provider condition') }}</p><p class="mt-1 text-sm leading-6 text-slate-600">{{ eligibilityValue(condition.statement || condition.note || condition.comparison, '', 'The provider confirms this during review.') }}</p><p v-if="condition.statement && condition.note && condition.statement !== condition.note" class="mt-1 text-xs leading-5 text-slate-500">{{ eligibilityValue(condition.note) }}</p></li>
            </ul>
        </details>

        <footer class="border-t border-slate-200 bg-slate-50/70 px-5 py-4 text-xs leading-5 text-slate-500 sm:px-6">
            <p>Your profile must be verified before you apply. Matching criteria does not guarantee selection.</p>
            <a v-if="hasReadiness && applicationHref" :href="applicationHref" class="mt-1.5 inline-flex min-h-7 items-center gap-2 font-bold text-slate-800 hover:underline" @click="$emit('navigate', $event, 'application')">View application files and process<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
        </footer>
    </section>
</template>
