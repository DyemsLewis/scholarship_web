<script setup>
import { computed } from 'vue';
import { preparationAction, preparationSteps } from '../support/scholarshipPreparation';
import { withPreparationContext } from '../support/scholarshipPreparationContext';

const props = defineProps({
    preparation: { type: Object, default: () => ({}) },
    preview: { type: Boolean, default: false },
    scholarshipId: { type: [String, Number], default: '' },
});
const emit = defineEmits(['navigate']);
const guide = computed(() => props.preparation ?? {});
const isPreview = computed(() => props.preview || guide.value.mode === 'preview');
const steps = computed(() => preparationSteps(guide.value));
const hasApplied = computed(() => !isPreview.value && guide.value.has_applied === true);
const unavailable = computed(() => !isPreview.value && ['upcoming', 'closed', 'full'].includes(guide.value.state));
const readinessSteps = computed(() => steps.value.filter((step) => ['profile', 'eligibility'].includes(step.key) || step.status === 'email_unverified'));
const emailNeedsVerification = computed(() => readinessSteps.value.some((step) => step.status === 'email_unverified'));
const readinessNeedsAttention = computed(() => !isPreview.value && !unavailable.value && readinessSteps.value.some((step) => step.blocks_start || step.status === 'email_unverified'));
const fileDetails = computed(() => steps.value.find((step) => step.key === 'requirements')?.details ?? {});
const formRequirements = computed(() => {
    const files = fileDetails.value.required_file_count;
    const questions = fileDetails.value.question_count;
    return [
        files > 0 ? 'Attach the required files.'
            : files === 0 ? 'No initial files are required.'
                : 'Check which files the provider requests.',
        questions === 0 ? '' : 'Answer the provider questions.',
    ].filter(Boolean).join(' ');
});
const contextualHref = (href) => isPreview.value ? href : withPreparationContext(href, props.scholarshipId, 'application');

function actionFor(action) {
    return preparationAction(action, { preview: isPreview.value, canStart: guide.value.can_start_application === true });
}

function isNext(step) {
    return !unavailable.value && !hasApplied.value && guide.value.next_step === step.key;
}

function missingItems(step) {
    const details = step.details ?? {};
    return [...(Array.isArray(details.missing_fields) ? details.missing_fields : []),
        ...(Array.isArray(details.missing_evidence) ? details.missing_evidence : [])]
        .map((item) => item?.label).filter((label) => typeof label === 'string' && label.trim());
}

function badgeStyle(step) {
    return {
        positive: 'student-badge-positive',
        attention: 'student-badge-attention',
        blocked: 'student-badge-negative',
    }[step.tone] ?? '';
}

function follow(event, action) {
    if (isPreview.value && action?.section) emit('navigate', event, action.section);
}
</script>

<template>
    <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-readiness data-scholarship-application-guide aria-labelledby="scholarship-application-guide-title">
        <header class="px-5 pt-5 pb-4 sm:px-6">
            <p class="student-kicker">{{ hasApplied ? 'Your submission' : 'Application instructions' }}</p>
            <h2 id="scholarship-application-guide-title" class="mt-1.5 font-display text-2xl font-bold text-slate-950">{{ hasApplied ? 'Your application' : 'How to apply' }}</h2>
            <p v-if="!hasApplied && isPreview" class="mt-2 text-sm leading-6 text-slate-600">Read-only preview of the steps applicants follow.</p>
        </header>

        <div v-if="$slots.action" class="px-5 pb-5 sm:px-6" :data-guide-existing-application="hasApplied || undefined" :data-guide-intake-notice="unavailable || undefined">
            <slot name="action" />
        </div>

        <template v-if="!hasApplied">
            <template v-if="steps.length">
                <div class="border-t border-slate-200 px-5 py-4 sm:px-6" data-how-to-apply>
                    <ol class="space-y-3" aria-label="How to submit this application" data-application-instructions>
                        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-4" data-application-instruction="open">
                            <span class="grid h-8 w-8 place-items-center rounded-sm bg-slate-950 text-xs font-bold text-amber-300" aria-hidden="true">1</span>
                            <div class="min-w-0">
                                <h4 class="text-sm font-bold leading-6 text-slate-950">Start pre-screening</h4>
                                <p class="text-sm leading-6 text-slate-600">Open the form for this scholarship.</p>
                            </div>
                        </li>
                        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-4" data-application-instruction="review">
                            <span class="grid h-8 w-8 place-items-center rounded-sm bg-slate-100 text-xs font-bold text-slate-600" aria-hidden="true">2</span>
                            <div class="min-w-0"><h4 class="text-sm font-bold leading-6 text-slate-950">Check eligibility</h4><p class="text-sm leading-6 text-slate-600">Review the program rules and your eligibility results.</p></div>
                        </li>
                        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-4" data-application-instruction="complete">
                            <span class="grid h-8 w-8 place-items-center rounded-sm bg-slate-100 text-xs font-bold text-slate-600" aria-hidden="true">3</span>
                            <div class="min-w-0"><h4 class="text-sm font-bold leading-6 text-slate-950">Complete requirements</h4><p class="text-sm leading-6 text-slate-600">{{ formRequirements }}</p></div>
                        </li>
                        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-4" data-application-instruction="send">
                            <span class="grid h-8 w-8 place-items-center rounded-sm bg-slate-100 text-xs font-bold text-slate-600" aria-hidden="true">4</span>
                            <div class="min-w-0"><h4 class="text-sm font-bold leading-6 text-slate-950">Confirm and submit</h4><p class="text-sm leading-6 text-slate-600">Review your answers, accept the terms, then select <strong class="font-semibold text-slate-800">Submit application</strong>.</p><p v-if="emailNeedsVerification" class="mt-1 text-sm font-semibold text-amber-900">Verify your email before submitting.</p></div>
                        </li>
                    </ol>
                </div>

                <details v-if="readinessSteps.length" class="group/readiness border-t border-slate-200" data-guide-readiness :open="readinessNeedsAttention">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 bg-slate-50 px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                        <div class="min-w-0"><h4 class="text-sm font-bold text-slate-950">Application readiness</h4><p v-if="readinessNeedsAttention" class="mt-1 text-xs font-semibold text-amber-800">A required check needs attention.</p></div>
                        <i class="fa-solid fa-chevron-down shrink-0 text-xs text-slate-500 transition group-open/readiness:rotate-180" aria-hidden="true"></i>
                    </summary>
                    <ul class="divide-y divide-slate-200 border-t border-slate-200" aria-label="Application readiness checks">
                        <li v-for="step in readinessSteps" :key="step.key" :data-preparation-step="step.key" :data-guide-next="isNext(step) || undefined" :class="['px-5 py-4 sm:px-6', isNext(step) && 'bg-amber-50/40']">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <h4 class="text-sm font-bold leading-6 text-slate-950">{{ step.status === 'email_unverified' ? 'Email verification' : step.title }}</h4>
                                <span data-guide-status :class="['student-badge', badgeStyle(step)]">{{ step.status_label || 'Review this check' }}</span>
                            </div>
                            <p class="mt-1 text-sm leading-6 text-slate-600 [overflow-wrap:anywhere]">{{ step.description }}</p>
                            <p v-if="!isPreview && step.blocks_start" class="mt-1 text-xs font-semibold text-slate-600">Needed before starting</p>
                            <details v-if="!isPreview && step.key === 'profile' && missingItems(step).length" data-guide-missing class="mt-2 text-xs">
                                <summary class="w-fit cursor-pointer py-1 font-bold text-slate-700 hover:text-slate-950">See what is missing ({{ missingItems(step).length }})</summary>
                                <ul class="mt-2 list-disc space-y-1 border-l border-slate-200 pl-6 text-slate-600"><li v-for="(label, itemIndex) in missingItems(step)" :key="itemIndex" class="leading-5">{{ label }}</li></ul>
                            </details>
                            <a v-if="actionFor(step.action)?.kind === 'link'" data-guide-action :href="contextualHref(step.action.href)" class="inline-flex min-h-11 max-w-full items-center gap-2 text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950" @click="follow($event, step.action)">{{ step.action.label }}<i class="fa-solid fa-arrow-right shrink-0 text-[10px]" aria-hidden="true"></i></a>
                        </li>
                    </ul>
                </details>
            </template>
            <p v-else class="px-5 py-5 text-sm leading-6 text-slate-600 sm:px-6">Application guidance is unavailable. Review the Eligibility and Application sections before continuing.</p>

            <footer class="border-t border-slate-200 bg-slate-50/70 px-5 py-4 sm:px-6">
                <p class="flex items-start gap-2 text-xs leading-5 text-slate-600" data-guide-after-submit><i class="fa-solid fa-circle-info mt-1 shrink-0 text-[10px] text-slate-500" aria-hidden="true"></i>Track updates in Applications. Submission is not a scholarship award.</p>
                <details v-if="steps.length || guide.submission?.note" data-guide-submission-note class="mt-2 text-xs">
                    <summary class="w-fit cursor-pointer py-1 font-semibold text-slate-700 hover:text-slate-950">{{ isPreview ? 'About this preview' : 'Form tips and submission checks' }}</summary>
                    <ul class="mt-2 list-disc space-y-2 border-l border-slate-200 pl-5 leading-5 text-slate-600">
                        <li v-if="steps.length">Use Continue to Eligibility, Continue to Requirements, then Continue to Confirm.</li>
                        <li v-if="fileDetails.required_file_count !== 0">Accept the document upload terms before using Upload or Replace.</li>
                        <li v-if="guide.submission?.note">{{ guide.submission.note }}</li>
                    </ul>
                </details>
            </footer>
        </template>
    </section>
</template>
