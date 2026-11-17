<script setup>
import { computed } from 'vue';
import { eligibilityValue, scholarshipEligibilityGroups, scholarshipEligibilityRows } from '../support/scholarshipEligibility';
import { withPreparationContext } from '../support/scholarshipPreparationContext';
import ApplicantEligibilityReview from './ApplicantEligibilityReview.vue';

const props = defineProps({
    content: { type: Object, required: true },
    verdict: { type: Object, required: true },
    preview: { type: Boolean, default: false },
    previewScenario: { type: String, default: 'general' },
    applicationHref: { type: String, default: '' },
    scholarshipId: { type: [String, Number], default: '' },
});
defineEmits(['navigate', 'review-changed']);

const rows = computed(() => scholarshipEligibilityRows(props.content.criteria ?? []));
const generalPreview = computed(() => props.preview && props.previewScenario === 'general');
const groups = computed(() => scholarshipEligibilityGroups(rows.value, generalPreview.value));
const openRows = computed(() => rows.value.filter((row) => row.result.status === 'info'));
const ruleCount = computed(() => rows.value.length - openRows.value.length);
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
    if (row.key === 'current_scholarship_support') return 'View your applications';
    if (row.result.status === 'review') return 'View supporting evidence';
    return row.result.status === 'missing' ? 'Add profile details' : 'Review profile details';
}

function shortResult(row) {
    return { pass: 'Matched', fail: 'Not met', exception: 'Exception approved', preferred: 'Preference differs', missing: 'Add details', review: 'Needs review', unknown: 'Not checked' }[row.result.status] ?? row.result.label;
}

function criterionIcon(key) {
    return {
        academic: 'fa-solid fa-chart-line', education_level: 'fa-solid fa-school',
        completed_qualification: 'fa-solid fa-graduation-cap', course: 'fa-solid fa-book-open',
        school_type: 'fa-solid fa-building-columns', year_level: 'fa-solid fa-layer-group',
        location: 'fa-solid fa-location-dot', income: 'fa-solid fa-wallet',
        current_scholarship_support: 'fa-solid fa-award', enrollment_status: 'fa-solid fa-user-graduate',
        citizenship: 'fa-solid fa-id-card', age: 'fa-regular fa-calendar',
    }[key] || 'fa-solid fa-list-check';
}

function groupDescription(group) {
    return {
        blocking: 'These required differences prevent a new application. Only correct your profile if the saved information is inaccurate.',
        review: 'Missing details or a check that needs provider review.',
        preferred: 'These differences do not block an application.',
        matched: 'Based on your saved profile, not a selection decision.',
        required: 'Published requirements for applicants.',
    }[group.id] ?? group.description;
}
</script>

<template>
    <section :class="['student-card eligibility-comparison overflow-hidden rounded-md border-slate-300', generalPreview && 'eligibility-published']" data-scholarship-eligibility aria-labelledby="scholarship-eligibility-title">
        <header class="border-t-2 border-t-slate-950 px-5 py-5 sm:px-6 sm:py-6">
            <div class="flex items-start justify-between gap-5">
                <div class="flex min-w-0 items-start gap-3 sm:gap-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300 sm:size-11"><i class="fa-solid fa-list-check block text-base leading-none" aria-hidden="true"></i></span>
                    <div class="min-w-0"><p class="student-kicker">{{ generalPreview ? 'Published eligibility' : 'Before you apply' }}</p><h2 id="scholarship-eligibility-title" class="mt-1.5 font-display text-xl font-bold leading-snug text-slate-950 sm:text-2xl">{{ generalPreview ? 'Who this scholarship is for' : 'Eligibility requirements' }}</h2></div>
                </div>
                <span v-if="ruleCount" class="hidden shrink-0 rounded-sm border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 sm:inline-flex">{{ ruleCount }} {{ ruleCount === 1 ? 'rule' : 'rules' }}</span>
            </div>
            <p class="mt-4 text-sm leading-6 text-slate-600">{{ generalPreview ? 'Review the published requirements and preferences. Applicants see a comparison with their own information.' : 'Compare the program rules with your saved information. Required rules and provider preferences are shown separately.' }}</p>
        </header>

        <p v-if="preview && !generalPreview" class="border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-600 sm:px-6" data-preview-comparison-note>Illustrative sample only. These are not real applicant values or a recorded review decision.</p>

        <div v-if="!generalPreview" class="border-y border-slate-200 bg-slate-50/80 px-5 py-4 sm:px-6" data-eligibility-comparison-notice>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1"><p class="text-xs font-semibold text-slate-500">Your comparison</p><p class="mt-1 text-sm font-bold leading-6 text-slate-950" data-eligibility-verdict>{{ verdict.title }}</p>
                    <details v-if="verdict.description" class="mt-1 text-xs" data-eligibility-verdict-details><summary class="w-fit cursor-pointer py-1 font-semibold text-slate-600 hover:text-slate-950">About this result</summary><p class="mt-1 max-w-3xl leading-5 text-slate-600">{{ verdict.description }}</p></details>
                </div>
                <a v-if="!preview && (!content.profile?.complete || !content.profile?.verified)" :href="contextualHref(profileAction.href)" class="student-button student-button-secondary shrink-0">{{ profileAction.label }}<i class="fa-solid fa-arrow-right block text-xs leading-none" aria-hidden="true"></i></a>
                <span v-else-if="!preview && content.profile?.verified" class="inline-flex items-center gap-2 py-1 text-xs font-semibold text-slate-600" data-eligibility-verified-profile><i class="fa-solid fa-circle-check block leading-none" aria-hidden="true"></i>Verified profile</span>
            </div>
        </div>

        <dl v-if="groups.length > 1 && !generalPreview" data-eligibility-summary class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-slate-200 px-5 py-3 text-xs sm:px-6" aria-label="Profile comparison summary">
            <div v-if="counts.blocking" class="flex items-center gap-2 text-rose-800"><dt>Not met</dt><dd class="font-bold tabular-nums">{{ counts.blocking }}</dd></div>
            <div v-if="counts.review" class="flex items-center gap-2 text-amber-900"><dt>Needs attention</dt><dd class="font-bold tabular-nums">{{ counts.review }}</dd></div>
            <div v-if="counts.preferred" class="flex items-center gap-2 text-slate-700"><dt>Preferences differ</dt><dd class="font-bold tabular-nums text-slate-950">{{ counts.preferred }}</dd></div>
            <div v-if="counts.matched" class="flex items-center gap-2 text-slate-700"><dt>Matched</dt><dd class="font-bold tabular-nums text-slate-950">{{ counts.matched }}</dd></div>
        </dl>

        <div v-if="groups.length" class="eligibility-grid eligibility-table-heading border-b border-slate-200 bg-slate-950 px-5 py-3 text-xs font-semibold text-white sm:px-6" data-eligibility-column-headings aria-hidden="true">
            <span>Eligibility rule</span><div class="eligibility-heading-values"><span>Program requirement</span><span v-if="!generalPreview">{{ preview ? 'Sample profile' : 'Your information' }}</span></div><span v-if="!generalPreview" class="text-right">Result</span>
        </div>

        <section v-for="group in groups" :key="group.id" class="border-b border-slate-200 last:border-b-0" :data-eligibility-group="group.id" :aria-labelledby="`eligibility-${group.id}-title`">
            <header :class="['flex items-start justify-between gap-3 border-l-2 bg-slate-50/70 px-5 py-3 sm:px-6', ['blocking', 'review'].includes(group.id) ? 'border-l-amber-400' : 'border-l-transparent']">
                <span class="min-w-0">
                    <span :id="`eligibility-${group.id}-title`" class="block text-sm font-bold text-slate-950">{{ group.label }} <span class="ml-1 text-xs font-normal text-slate-500">({{ group.rows.length }})</span></span>
                    <span class="mt-0.5 block text-xs leading-5 text-slate-600">{{ groupDescription(group) }}</span>
                </span>
            </header>
            <div class="divide-y divide-slate-200 border-t border-slate-200">
                <article v-for="row in group.rows" :key="row.id" :data-eligibility-criterion="row.key" :data-comparison="generalPreview ? undefined : row.result.status" class="eligibility-grid px-5 py-4 sm:px-6">
                    <div class="eligibility-rule flex min-w-0 items-start gap-3" data-eligibility-row-heading>
                        <span class="grid size-8 shrink-0 place-items-center rounded-sm border border-slate-200 bg-slate-50 text-slate-500"><i :class="[criterionIcon(row.key), 'block text-xs leading-none']" aria-hidden="true"></i></span>
                        <div class="min-w-0"><h3 class="text-sm font-bold leading-6 text-slate-950">{{ eligibilityValue(row.label, '', 'Eligibility requirement') }}</h3><p class="mt-0.5 text-xs font-semibold text-slate-500">{{ row.rule_mode === 'preferred' ? 'Preferred' : 'Required' }}</p></div>
                    </div>
                    <dl class="eligibility-values text-sm leading-6" data-eligibility-values>
                        <div class="min-w-0 [overflow-wrap:anywhere]" data-eligibility-requirement><dt class="eligibility-value-label mb-0.5 text-xs font-semibold text-slate-500">{{ row.rule_mode === 'preferred' ? 'Program preference' : 'Required' }}</dt><dd class="font-semibold text-slate-900">{{ row.requirementValue }}</dd></div>
                        <div v-if="!generalPreview" class="min-w-0 [overflow-wrap:anywhere]" data-eligibility-profile-value>
                            <dt class="eligibility-value-label mb-0.5 text-xs font-semibold text-slate-500">{{ preview ? 'Sample profile' : (row.key === 'current_scholarship_support' ? 'Platform record' : 'Your profile') }}</dt><dd class="text-slate-600">{{ row.profileValue }}</dd>
                            <a v-if="!preview && ['missing', 'review', 'fail'].includes(row.result.status)" :href="contextualHref(row.profileHref)" data-eligibility-profile-action class="mt-1 inline-flex min-h-9 items-center gap-2 text-xs font-bold text-slate-800 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">{{ actionLabel(row) }}<i class="fa-solid fa-arrow-right block text-xs leading-none" aria-hidden="true"></i></a>
                            <details v-if="row.explanation" data-eligibility-explanation class="mt-1 text-xs">
                                <summary class="w-fit cursor-pointer py-1 font-semibold text-slate-600 hover:text-slate-950">{{ row.attention ? 'Why this needs attention' : 'How this was checked' }}</summary>
                                <p class="mt-1 border-l-2 border-slate-200 pl-3 leading-5 text-slate-600">{{ row.explanation }}</p>
                            </details>
                        </div>
                    </dl>
                    <div v-if="!generalPreview" data-eligibility-result class="eligibility-result flex justify-end">
                        <span :class="['student-badge eligibility-status self-start justify-center', resultStyle(row).badge]" :aria-label="row.result.label"><i :class="[resultStyle(row).icon, 'block shrink-0 text-[10px] leading-none']" aria-hidden="true"></i>{{ shortResult(row) }}</span>
                    </div>
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

        <ApplicantEligibilityReview v-if="!preview && scholarshipId" :scholarship-id="scholarshipId" embedded @changed="$emit('review-changed')" />
        <footer class="border-t border-slate-200 bg-slate-50/70 px-5 py-4 text-xs leading-5 text-slate-500 sm:px-6">
            <p>Your profile must be verified before you apply. Matching criteria does not guarantee selection.</p>
            <a v-if="hasReadiness && applicationHref" :href="applicationHref" class="mt-1 inline-flex min-h-11 items-center gap-2 font-bold text-slate-800 hover:underline" @click="$emit('navigate', $event, 'application')">See how to apply<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
        </footer>
    </section>
</template>

<style scoped>
.eligibility-comparison {
    container-type: inline-size;
    container-name: scholarship-eligibility;
}
.eligibility-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    column-gap: 1rem;
    row-gap: 0.75rem;
}
.eligibility-table-heading { display: none; }
.eligibility-values {
    display: grid;
    grid-column: 1 / -1;
    gap: 0.75rem;
}
.eligibility-result { grid-column: 2; grid-row: 1; }
.eligibility-status { min-width: 6rem; max-width: 9.25rem; }
.eligibility-published .eligibility-rule { grid-column: 1 / -1; }
@container scholarship-eligibility (min-width: 54rem) {
    .eligibility-grid {
        grid-template-columns: minmax(10rem, 1fr) minmax(0, 2.65fr) 9.25rem;
        column-gap: 1.5rem;
        row-gap: 0.5rem;
    }
    .eligibility-table-heading { display: grid; }
    .eligibility-heading-values,
    .eligibility-values {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1.5rem;
    }
    .eligibility-values { grid-column: 2; grid-row: 1; }
    .eligibility-result { grid-column: 3; }
    .eligibility-status { width: 9.25rem; }
    .eligibility-value-label {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        padding: 0;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
    }
    .eligibility-published .eligibility-grid { grid-template-columns: minmax(10rem, 1fr) minmax(0, 3.55fr); }
    .eligibility-published .eligibility-rule { grid-column: 1; }
    .eligibility-published .eligibility-values,
    .eligibility-published .eligibility-heading-values { grid-template-columns: minmax(0, 1fr); }
}
</style>
