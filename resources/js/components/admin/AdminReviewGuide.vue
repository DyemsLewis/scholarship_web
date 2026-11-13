<script setup>
import { computed } from 'vue';
import { reviewStatusLabel } from '../../support/adminReviewPresentation.js';
import { profileVerificationLabel } from '../../support/applicantProfileLabels.js';

const props = defineProps({ workflow: { type: Object, default: null }, kind: { type: String, required: true } });
defineEmits(['continue']);
const headline = computed(() => {
    if (props.workflow?.outcome === 'approved' && props.workflow?.ready) return props.kind === 'provider' ? 'Organization verification recorded' : 'Profile verification recorded';
    if (props.workflow?.outcome === 'published') return 'Publication decision recorded';
    if (props.kind === 'program') return props.workflow?.ready ? 'Ready for a publication decision' : 'Check the publication blockers';
    if (props.workflow?.review_count) return `${props.workflow.review_count} document${props.workflow.review_count === 1 ? '' : 's'} to review`;
    if (props.workflow?.waiting_count) return 'Waiting for corrected or missing evidence';
    return 'Ready for the final verification decision';
});
const explanation = computed(() => {
    if (['approved', 'published'].includes(props.workflow?.outcome) && props.workflow?.ready) return 'The current overall decision is recorded below. Reopen or change it only when a further review is needed.';
    if (props.kind === 'program') return props.workflow?.ready ? 'Check the program details and applicant-facing terms before deciding.' : 'Publishing is unavailable until these issues are resolved. You can still return the program with a clear note.';
    if (props.workflow?.review_count) return 'Review the outstanding records below. Current accepted documents do not need another review.';
    return props.workflow?.waiting_count ? 'Record a clear correction request if needed. Do not approve while required evidence is outstanding.' : 'The required document checks are complete. Overall verification is still a separate decision.';
});
function date(value) { return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' }).format(new Date(value)); }
function outcomeLabel(value) {
    return props.kind === 'applicant' ? profileVerificationLabel(value) : reviewStatusLabel(value, props.kind === 'program' ? 'programs' : 'providers');
}
</script>

<template>
    <section v-if="workflow" class="admin-panel mt-4 overflow-hidden border-l-4 border-l-amber-400" data-admin-review-guide>
        <div class="flex items-center gap-5 px-6 py-4">
            <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600" aria-hidden="true"><i :class="workflow.ready ? 'fa-solid fa-check' : 'fa-solid fa-list-check'"></i></span>
            <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Next review action</p><h2 class="mt-1 text-lg font-black text-slate-950">{{ headline }}</h2><p class="mt-1 text-sm text-slate-600">{{ explanation }}</p></div>
            <button type="button" class="staff-row-action staff-row-action--primary shrink-0 px-4 py-3 text-sm" @click="$emit('continue')">{{ kind !== 'program' && workflow.review_count ? 'Review next document' : 'Open final decision' }}<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></button>
        </div>
        <div v-if="workflow.changes?.length" class="border-t border-slate-200 bg-slate-50 px-6 py-3 text-xs text-slate-700" data-review-changes><strong>Changed since the last overall decision:</strong> {{ workflow.changes.join(' · ') }}</div>
        <details v-if="workflow.blockers?.length" class="border-t border-slate-200 px-6 py-3"><summary class="cursor-pointer text-sm font-semibold text-amber-800">{{ workflow.blockers.length }} publication issue{{ workflow.blockers.length === 1 ? '' : 's' }} to resolve</summary><ul class="mt-3 list-inside list-disc space-y-1 text-sm leading-6 text-slate-600"><li v-for="blocker in workflow.blockers" :key="blocker">{{ blocker }}</li></ul></details>
        <details v-if="workflow.history?.length" class="border-t border-slate-200 px-6 py-3"><summary class="cursor-pointer text-xs font-bold text-slate-600"><i class="fa-solid fa-clock-rotate-left mr-2" aria-hidden="true"></i>Previous overall decisions<span v-if="workflow.previous_note" class="ml-2 font-normal">A previous review note is available</span></summary><ol class="mt-2 divide-y divide-slate-200"><li v-for="event in workflow.history" :key="event.id" class="py-3 text-sm"><p class="font-semibold text-slate-800">{{ outcomeLabel(event.outcome) }}<span class="ml-3 text-xs font-normal text-slate-500">{{ event.reviewer }} · {{ date(event.at) }}</span></p><p v-if="event.note" class="mt-1 whitespace-pre-line break-words text-slate-600">{{ event.note }}</p></li></ol></details>
    </section>
</template>
