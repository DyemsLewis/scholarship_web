<script setup>
import { computed } from 'vue';
import { eligibilityValue, scholarshipEligibilityGroups, scholarshipEligibilityRows } from '../support/scholarshipEligibility';
import { withPreparationContext } from '../support/scholarshipPreparationContext';

const props = defineProps({
    content: { type: Object, required: true },
    verdict: { type: Object, required: true },
    preview: { type: Boolean, default: false },
    previewScenario: { type: String, default: 'general' },
    applicationHref: { type: String, default: '' },
    scholarshipId: { type: [String, Number], default: '' },
});
defineEmits(['navigate']);

const rows = computed(() => scholarshipEligibilityRows(props.content.criteria ?? []));
const generalPreview = computed(() => props.preview && props.previewScenario === 'general');
const groups = computed(() => scholarshipEligibilityGroups(rows.value, generalPreview.value));
const openRows = computed(() => rows.value.filter((row) => row.result.status === 'info'));
const counts = computed(() => Object.fromEntries(groups.value.map((group) => [group.id, group.rows.length])));
const profileAction = computed(() => props.content.profile?.complete
    ? { label: 'View verification', href: '/dashboard/profile?section=verification' }
    : { label: 'Complete profile', href: '/dashboard/profile' });
const hasReadiness = computed(() => (props.content.criteria ?? []).some((row) => row.category === 'readiness' || row.key === 'documents'));
const contextualHref = (href) => props.preview ? href : withPreparationContext(href, props.scholarshipId, 'eligibility');

function resultStyle(row) {
    return {
        ready: { badge: 'student-badge-positive', icon: 'fa-solid fa-check' },
        blocked: { badge: 'student-badge-negative', icon: 'fa-solid fa-xmark' },
        attention: { badge: 'student-badge-attention', icon: 'fa-solid fa-circle-info' },
    }[row.result.state] ?? { badge: '', icon: 'fa-solid fa-minus' };
}

function actionLabel(row) {
    if (row.result.status === 'review') return 'View supporting evidence';
    return row.result.status === 'missing' ? 'Add profile details' : 'Review profile details';
}

function shortResult(row) {
    return { pass: 'Matched', fail: 'Not met', preferred: 'Preference differs', missing: 'Add details', review: 'Needs review', unknown: 'Not checked' }[row.result.status] ?? row.result.label;
}

function groupDescription(group) {
    return {
        blocking: 'These required differences prevent a new application.',
        review: 'Missing details or a check that needs provider review.',
        preferred: 'These differences do not block an application.',
        matched: 'Based on your saved profile, not a selection decision.',
        required: 'Published requirements for applicants.',
    }[group.id] ?? group.description;
}
</script>

<template>
    <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-eligibility aria-labelledby="scholarship-eligibility-title">
        <header class="border-b border-slate-200 px-5 py-5 sm:px-6">
            <p class="student-kicker">{{ generalPreview ? 'Published eligibility' : 'Profile comparison' }}</p>
            <h2 id="scholarship-eligibility-title" class="mt-1.5 font-display text-2xl font-bold leading-tight text-slate-950">{{ generalPreview ? 'Who this scholarship is for' : 'Eligibility at a glance' }}</h2>
            <p class="mt-2 text-sm font-semibold leading-6 text-slate-700" data-eligibility-verdict>{{ generalPreview ? 'Applicants see these rules compared with their own saved profile.' : verdict.title }}</p>
            <details v-if="!generalPreview && verdict.description" class="mt-1 text-xs" data-eligibility-verdict-details>
                <summary class="w-fit cursor-pointer py-1 font-semibold text-slate-600 hover:text-slate-950">About this result</summary>
                <p class="mt-1 max-w-3xl leading-5 text-slate-600">{{ verdict.description }}</p>
            </details>
            <a v-if="!preview && (!content.profile?.complete || !content.profile?.verified)" :href="contextualHref(profileAction.href)" class="student-button student-button-secondary mt-4">{{ profileAction.label }}<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
        </header>

        <p v-if="preview && !generalPreview" class="border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-600 sm:px-6" data-preview-comparison-note>Illustrative sample only. These are not real applicant values or a recorded review decision.</p>

        <dl v-if="groups.length && !generalPreview" data-eligibility-summary class="flex flex-wrap items-center gap-x-5 gap-y-2 border-b border-slate-200 bg-slate-50/70 px-5 py-3 text-xs sm:px-6" aria-label="Profile comparison summary">
            <div v-if="counts.blocking" class="flex items-center gap-2 text-rose-800"><dt>Not met</dt><dd class="font-bold tabular-nums">{{ counts.blocking }}</dd></div>
            <div v-if="counts.review" class="flex items-center gap-2 text-amber-900"><dt>Needs attention</dt><dd class="font-bold tabular-nums">{{ counts.review }}</dd></div>
            <div v-if="counts.preferred" class="flex items-center gap-2 text-slate-700"><dt>Preferences differ</dt><dd class="font-bold tabular-nums text-slate-950">{{ counts.preferred }}</dd></div>
            <div v-if="counts.matched" class="flex items-center gap-2 text-slate-700"><dt>Matched</dt><dd class="font-bold tabular-nums text-slate-950">{{ counts.matched }}</dd></div>
        </dl>

        <component :is="group.collapsed ? 'details' : 'section'" v-for="group in groups" :key="group.id" class="group border-b border-slate-200" :data-eligibility-group="group.id" :aria-labelledby="`eligibility-${group.id}-title`">
            <component :is="group.collapsed ? 'summary' : 'header'" :class="['flex min-h-14 items-center justify-between gap-3 bg-slate-50/60 px-5 py-3 sm:px-6', group.collapsed && 'cursor-pointer list-none hover:bg-slate-100 [&::-webkit-details-marker]:hidden']">
                <span class="min-w-0">
                    <span :id="`eligibility-${group.id}-title`" class="block text-sm font-bold text-slate-950">{{ group.label }} <span class="ml-1 text-xs font-normal text-slate-500">({{ group.rows.length }})</span></span>
                    <span class="mt-0.5 block text-xs leading-5 text-slate-600">{{ groupDescription(group) }}</span>
                </span>
                <i v-if="group.collapsed" class="fa-solid fa-chevron-down shrink-0 text-xs text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i>
            </component>
            <div class="divide-y divide-slate-200 border-t border-slate-200">
                <article v-for="row in group.rows" :key="row.id" :data-eligibility-criterion="row.key" :data-comparison="generalPreview ? undefined : row.result.status" class="px-5 py-4 sm:px-6">
                    <div class="flex min-w-0 items-start justify-between gap-3" data-eligibility-row-heading>
                        <div class="min-w-0">
                            <h3 class="text-sm font-bold text-slate-950">{{ eligibilityValue(row.label, '', 'Eligibility requirement') }}</h3>
                        </div>
                        <div v-if="!generalPreview" data-eligibility-result class="flex shrink-0 items-start">
                            <span :class="['student-badge min-w-24 justify-center', resultStyle(row).badge]" :aria-label="row.result.label"><i :class="[resultStyle(row).icon, 'block shrink-0 text-[10px] leading-none']" aria-hidden="true"></i>{{ shortResult(row) }}</span>
                        </div>
                    </div>
                    <dl class="mt-2 space-y-1 text-sm leading-6" data-eligibility-values>
                        <div class="min-w-0 [overflow-wrap:anywhere]"><dt class="inline text-slate-500">{{ row.rule_mode === 'preferred' ? 'Program preference' : 'Required' }}: </dt><dd class="inline font-semibold text-slate-900">{{ row.requirementValue }}</dd></div>
                        <div v-if="!generalPreview" class="min-w-0 [overflow-wrap:anywhere]"><dt class="inline text-slate-500">{{ preview ? 'Sample profile' : 'Your profile' }}: </dt><dd class="inline text-slate-700">{{ row.profileValue }}</dd></div>
                    </dl>
                    <p v-if="!generalPreview && row.result.status === 'fail'" class="mt-2 text-xs leading-5 text-slate-600">Only correct your profile if the saved information is inaccurate.</p>
                    <a v-if="!preview && ['missing', 'review', 'fail'].includes(row.result.status)" :href="contextualHref(row.profileHref)" data-eligibility-profile-action class="mt-1 inline-flex min-h-11 items-center gap-2 text-xs font-bold text-slate-800 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">{{ actionLabel(row) }}<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
                    <details v-if="row.explanation && !generalPreview" data-eligibility-explanation class="mt-1 text-xs">
                        <summary class="w-fit cursor-pointer py-1 font-semibold text-slate-600 hover:text-slate-950">{{ row.attention ? 'Why this needs attention' : 'How this was checked' }}</summary>
                        <p class="mt-1 max-w-4xl border-l-2 border-slate-200 pl-3 leading-5 text-slate-600">{{ row.explanation }}</p>
                    </details>
                </article>
            </div>
        </component>

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
            <a v-if="hasReadiness && applicationHref" :href="applicationHref" class="mt-1 inline-flex min-h-11 items-center gap-2 font-bold text-slate-800 hover:underline" @click="$emit('navigate', $event, 'application')">See how to apply<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
        </footer>
    </section>
</template>
