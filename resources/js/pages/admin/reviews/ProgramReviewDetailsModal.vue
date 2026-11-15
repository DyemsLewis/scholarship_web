<script setup>
import { computed, ref, useId, watch } from 'vue';
import EducationEligibilitySummary from '../../../components/EducationEligibilitySummary.vue';
import RecipientAgreementDetails from '../../../components/RecipientAgreementDetails.vue';
import { useModalAccessibility } from '../../../composables/useModalAccessibility';
import { programReviewEligibility, programReviewSections, safeProgramReviewUrl } from '../../../support/adminProgramReview.js';
import { reviewRequirementItems } from '../../../support/adminReviewPresentation.js';
import { labelFromKey } from '../../../support/display.js';
import { providerObjectiveDetails } from '../../../support/providerObjectives.js';
import { selectionStageDefinitions } from '../../../support/selectionPlan.js';

const props = defineProps({ open: Boolean, program: { type: Object, required: true }, section: { type: String, default: 'overview' } });
const emit = defineEmits(['close']);
const id = useId();
const dialog = ref(null);
const content = ref(null);
const tabs = ref(null);
const active = ref('overview');
const current = computed(() => programReviewSections.find(section => section.key === active.value));
const rules = computed(() => programReviewEligibility(props.program));
const objectives = computed(() => providerObjectiveDetails(props.program.provider_objectives));
const documents = computed(() => [
    { label: 'Required pre-screening files', items: reviewRequirementItems(props.program.requirements) },
    { label: 'Optional files', items: reviewRequirementItems(props.program.optional_requirements) },
    { label: 'After-qualification documents', items: reviewRequirementItems(props.program.post_qualification_requirements) },
]);
const stages = computed(() => Array.isArray(props.program.selection_stages) ? props.program.selection_stages : []);
const overviewFacts = computed(() => [
    ['Category', props.program.category], ['Program cycle', props.program.program_cycle ? labelFromKey(props.program.program_cycle) : 'Not specified'],
    ['Applications open', props.program.application_opens_at], ['Application deadline', props.program.deadline],
    ['Expected results', props.program.expected_results_at], ['Available slots', props.program.slots_available ?? 'Not limited'],
    ['Application limit', props.program.application_limit ?? 'Not limited'],
    ['Support period', [props.program.support_starts_at, props.program.support_ends_at].filter(Boolean).join(' to ')],
]);
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, () => emit('close'));
watch(() => props.open, open => { if (open) active.value = programReviewSections.some(item => item.key === props.section) ? props.section : 'overview'; });
watch(active, () => content.value?.scrollTo({ top: 0 }), { flush: 'post' });

function changeTab(event, index) {
    const keys = { ArrowRight: (index + 1) % programReviewSections.length, ArrowLeft: (index + programReviewSections.length - 1) % programReviewSections.length, Home: 0, End: programReviewSections.length - 1 };
    if (!(event.key in keys)) return;
    event.preventDefault();
    active.value = programReviewSections[keys[event.key]].key;
    tabs.value?.querySelectorAll('[role="tab"]')[keys[event.key]]?.focus();
}
function stageLabel(key) { return selectionStageDefinitions.find(stage => stage.value === key)?.label || labelFromKey(key); }
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2000] grid place-items-center bg-slate-950/60 p-6" @click.self="$emit('close')">
            <section ref="dialog" data-program-details-modal class="program-details-dialog flex w-full max-w-6xl flex-col overflow-hidden rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl" role="dialog" aria-modal="true" :aria-labelledby="`${id}-title`" tabindex="-1" @keydown="handleKeydown">
                <header class="flex shrink-0 items-start justify-between gap-6 border-b border-slate-200 px-7 py-5">
                    <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Program details</p><h2 :id="`${id}-title`" class="mt-1 break-words font-display text-2xl font-bold text-slate-950">{{ program.title }}</h2><p class="mt-1 break-words text-sm text-slate-500">{{ program.provider }}</p></div>
                    <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-300 text-slate-600 hover:bg-slate-50" aria-label="Close program details" @click="$emit('close')"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div ref="tabs" role="tablist" aria-label="Program information" class="grid shrink-0 grid-cols-5 border-b border-slate-200 bg-slate-50 px-7">
                    <button v-for="(item, index) in programReviewSections" :id="`${id}-tab-${item.key}`" :key="item.key" type="button" role="tab" :aria-selected="active === item.key" :aria-controls="`${id}-panel`" :tabindex="active === item.key ? 0 : -1" :class="['flex min-h-12 items-center justify-center gap-2 border-b-2 px-3 text-sm font-bold', active === item.key ? 'border-amber-400 bg-white text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-950']" @click="active = item.key" @keydown="changeTab($event, index)"><i :class="[item.icon, 'text-xs']" aria-hidden="true"></i>{{ item.label }}</button>
                </div>
                <div :id="`${id}-panel`" ref="content" role="tabpanel" :aria-labelledby="`${id}-tab-${active}`" tabindex="0" class="min-h-0 flex-1 space-y-6 overflow-y-auto overscroll-contain px-7 py-6 [overflow-wrap:anywhere]">
                    <h3 class="text-lg font-bold text-slate-950">{{ current.title }}</h3>
                    <template v-if="active === 'overview'">
                        <p class="whitespace-pre-line text-sm leading-7 text-slate-600">{{ program.description || 'No description provided.' }}</p>
                        <dl class="grid grid-cols-2 gap-x-8 border-y border-slate-200"><div v-for="[label, value] in overviewFacts" :key="label" class="min-w-0 border-b border-slate-100 py-3 last:border-0"><dt class="text-xs text-slate-500">{{ label }}</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ value === 0 ? 0 : value || 'Not specified' }}</dd></div></dl>
                        <section v-if="objectives.length || program.provider_objective_notes"><h4 class="text-sm font-bold text-slate-950">Provider objectives</h4><ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600"><li v-for="objective in objectives" :key="objective.value">{{ objective.label }}</li></ul><p v-if="program.provider_objective_notes" class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ program.provider_objective_notes }}</p></section>
                        <section class="border-t border-slate-200 pt-5"><h4 class="text-sm font-bold text-slate-950">Location and contact</h4><dl class="mt-3 grid grid-cols-2 gap-5 text-sm"><div><dt class="text-xs text-slate-500">Public location</dt><dd class="mt-1 whitespace-pre-line leading-6 text-slate-800">{{ [program.location_name, program.location_address].filter(Boolean).join('\n') || 'Not specified' }}</dd><a v-if="safeProgramReviewUrl(program.map_url)" :href="safeProgramReviewUrl(program.map_url)" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-2 font-bold text-slate-700 underline underline-offset-4"><i class="fa-solid fa-location-dot text-xs" aria-hidden="true"></i>Open map</a></div><div><dt class="text-xs text-slate-500">Program contact</dt><dd class="mt-1 space-y-1 text-slate-800"><p>{{ [program.contact_person, program.contact_department].filter(Boolean).join(' · ') || 'Not specified' }}</p><p>{{ program.contact_email || program.provider_contact_email || program.provider_email }}</p><p>{{ program.contact_number || program.provider_contact_number }}</p></dd></div></dl><a v-if="safeProgramReviewUrl(program.official_program_url)" :href="safeProgramReviewUrl(program.official_program_url)" target="_blank" rel="noopener noreferrer" class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-slate-700 underline underline-offset-4">Official program page<i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i></a></section>
                    </template>
                    <template v-else-if="active === 'eligibility'">
                        <p class="whitespace-pre-line text-sm leading-7 text-slate-600">{{ program.eligibility || 'No general eligibility note.' }}</p>
                        <dl v-if="rules.length" class="divide-y divide-slate-200 border-y border-slate-200"><div v-for="rule in rules" :key="rule.key" class="grid grid-cols-[12rem_minmax(0,1fr)_6rem] items-start gap-5 py-4 text-sm"><dt class="font-semibold text-slate-700">{{ rule.label }}</dt><dd class="whitespace-pre-line leading-6 text-slate-600">{{ rule.value }}</dd><dd class="text-right"><span :class="['inline-block rounded-sm px-2 py-1 text-xs font-bold', rule.mode === 'preferred' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600']">{{ rule.mode === 'preferred' ? 'Preferred' : 'Required' }}</span></dd></div></dl><p v-else class="text-sm text-slate-500">No additional profile restrictions configured.</p>
                        <EducationEligibilitySummary :policy="program.education_eligibility_policy" :modes="program.eligibility_rule_modes" />
                        <section v-if="program.eligibility_conditions?.length"><h4 class="text-sm font-bold text-slate-950">Additional conditions</h4><ul class="mt-3 divide-y divide-slate-200"><li v-for="(condition, index) in program.eligibility_conditions" :key="index" class="py-3 text-sm leading-6 text-slate-600"><p class="font-semibold text-slate-800">{{ condition.label || labelFromKey(condition.key) }}</p><p v-if="condition.statement">{{ condition.statement }}</p></li></ul></section>
                    </template>
                    <template v-else-if="active === 'benefits'">
                        <section><h4 class="text-sm font-bold text-slate-950">Support offered</h4><ul v-if="program.benefits?.length" class="mt-3 divide-y divide-slate-200 border-y border-slate-200"><li v-for="(benefit, index) in program.benefits" :key="index" class="py-4"><p class="text-sm font-bold text-slate-950">{{ benefit.title || benefit.type_label }}</p><p class="mt-1 text-sm leading-6 text-slate-600">{{ benefit.display_summary }}</p><p v-if="benefit.description" class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ benefit.description }}</p></li></ul><p v-else class="mt-2 text-sm text-slate-600">{{ program.benefit_summary || 'No benefits specified.' }}</p></section>
                        <section class="border-t border-slate-200 pt-5"><h4 class="mb-4 text-sm font-bold text-slate-950">Recipient agreement</h4><RecipientAgreementDetails :scholarship="program" /></section>
                        <section v-if="program.renewal_policy" class="border-t border-slate-200 pt-5"><h4 class="text-sm font-bold text-slate-950">Renewal policy</h4><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ program.renewal_policy }}</p></section>
                    </template>
                    <template v-else-if="active === 'application'">
                        <p class="text-sm text-slate-600"><span class="font-semibold text-slate-800">Pre-screening method:</span> {{ { online: 'Portal review', onsite: 'Portal review with in-person verification', provider_review: 'Profile review only' }[program.application_mode] || labelFromKey(program.application_mode || 'Not specified') }}</p>
                        <section v-for="group in documents" :key="group.label" class="border-t border-slate-200 pt-5"><h4 class="text-sm font-bold text-slate-950">{{ group.label }}</h4><ul v-if="group.items.length" class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-600"><li v-for="(requirement, index) in group.items" :key="index">{{ requirement }}</li></ul><p v-else class="mt-2 text-sm text-slate-500">None listed.</p></section>
                        <section v-if="program.application_questions?.length" class="border-t border-slate-200 pt-5"><h4 class="text-sm font-bold text-slate-950">Application questions</h4><ol class="mt-3 list-decimal space-y-2 pl-5 text-sm leading-6 text-slate-600"><li v-for="(question, index) in program.application_questions" :key="index">{{ question.label || question.question || question.prompt || (typeof question === 'string' ? question : 'Question text not specified') }}<span v-if="question.required" class="ml-2 text-xs font-semibold text-slate-500">Required</span></li></ol></section>
                        <section class="border-t border-slate-200 pt-5"><h4 class="text-sm font-bold text-slate-950">Formal application / provider handoff</h4><dl class="mt-3 space-y-4 text-sm"><div><dt class="text-xs text-slate-500">Method and deadline</dt><dd class="mt-1 text-slate-800">{{ { onsite: 'In person', online: 'Official link', provider_contact: 'Wait for provider contact' }[program.handoff_mode] || 'Not specified' }} · {{ program.handoff_deadline || 'Deadline not set' }}</dd></div><div><dt class="text-xs text-slate-500">Instructions</dt><dd class="mt-1 whitespace-pre-line leading-6 text-slate-600">{{ program.handoff_instructions || 'No external handoff instructions.' }}</dd></div><div v-if="program.handoff_location_name || program.handoff_location_address"><dt class="text-xs text-slate-500">Venue</dt><dd class="mt-1 whitespace-pre-line leading-6 text-slate-600">{{ [program.handoff_location_name, program.handoff_location_address].filter(Boolean).join('\n') }}</dd></div></dl><a v-if="safeProgramReviewUrl(program.handoff_url)" :href="safeProgramReviewUrl(program.handoff_url)" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-2 text-sm font-bold text-slate-700 underline underline-offset-4">Official application link<i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i></a></section>
                    </template>
                    <template v-else-if="active === 'selection'">
                        <ol v-if="stages.length" class="divide-y divide-slate-200 border-y border-slate-200"><li v-for="(stage, index) in stages" :key="stage" class="flex items-center gap-4 py-3"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-sm bg-slate-100 text-xs font-bold text-slate-600">{{ index + 1 }}</span><span class="text-sm font-semibold text-slate-800">{{ stageLabel(stage) }}</span></li></ol><p v-else class="text-sm text-slate-500">No selection stages specified.</p>
                        <section class="border-t border-slate-200 pt-5"><h4 class="text-sm font-bold text-slate-950">Evaluation criteria</h4><dl v-if="program.review_rubric?.length" class="mt-3 divide-y divide-slate-200"><div v-for="(criterion, index) in program.review_rubric" :key="index" class="flex items-start justify-between gap-5 py-3 text-sm"><dt class="text-slate-700">{{ criterion.label || criterion.name || labelFromKey(criterion.key) }}<span v-if="criterion.description" class="mt-1 block text-xs leading-5 text-slate-500">{{ criterion.description }}</span></dt><dd class="shrink-0 font-semibold text-slate-950">{{ criterion.weight ?? 'Not set' }}<template v-if="criterion.weight !== undefined && criterion.weight !== null">%</template></dd></div></dl><p v-else class="mt-2 text-sm text-slate-500">No rubric criteria listed.</p></section>
                        <section class="border-t border-slate-200 pt-5"><h4 class="text-sm font-bold text-slate-950">Published activities</h4><ul v-if="program.program_events?.length" class="mt-3 divide-y divide-slate-200"><li v-for="(event, index) in program.program_events" :key="index" class="py-4 text-sm"><p class="font-semibold text-slate-800">{{ event.title || stageLabel(event.stage || event.type) }}</p><p class="mt-1 text-slate-600">{{ event.scheduled_label || event.scheduled_at || 'Date not set' }}<span v-if="event.mode"> · {{ labelFromKey(event.mode) }}</span></p><p v-if="event.venue || event.location_address" class="mt-1 text-slate-600">{{ [event.venue, event.location_address].filter(Boolean).join(' · ') }}</p><a v-if="safeProgramReviewUrl(event.online_url)" :href="safeProgramReviewUrl(event.online_url)" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block font-semibold text-slate-700 underline underline-offset-4">Activity link</a><p v-if="event.instructions" class="mt-2 whitespace-pre-line leading-6 text-slate-600">{{ event.instructions }}</p></li></ul><p v-else class="mt-2 text-sm text-slate-500">No published activities. Assigned applicant sessions are handled separately.</p></section>
                    </template>
                </div>
                <footer class="flex shrink-0 items-center justify-between gap-5 border-t border-slate-200 bg-slate-50 px-7 py-4"><p class="text-xs text-slate-500"><i class="fa-solid fa-lock mr-2" aria-hidden="true"></i>Read-only information. Record the publication decision on the review page.</p><button type="button" class="h-10 shrink-0 rounded-sm border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 hover:border-slate-950" @click="$emit('close')">Close details</button></footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.program-details-dialog { height: min(49rem, calc(100dvh - 3rem)); }
</style>
