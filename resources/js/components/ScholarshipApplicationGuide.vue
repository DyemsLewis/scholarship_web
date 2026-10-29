<script setup>
import { computed } from 'vue';
import { preparationAction, preparationRequirementSummary, preparationSteps } from '../support/scholarshipPreparation';
import { withPreparationContext } from '../support/scholarshipPreparationContext';

const props = defineProps({
    preparation: { type: Object, default: () => ({}) },
    preview: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    scholarshipId: { type: [String, Number], default: '' },
});
const emit = defineEmits(['navigate', 'save']);
const guide = computed(() => props.preparation ?? {});
const isPreview = computed(() => props.preview || guide.value.mode === 'preview');
const steps = computed(() => preparationSteps(guide.value));
const hasApplied = computed(() => !isPreview.value && guide.value.has_applied === true);
const unavailable = computed(() => !isPreview.value && ['upcoming', 'closed', 'full'].includes(guide.value.state));
const nextAction = computed(() => actionFor(guide.value.next_action));
const startAction = computed(() => {
    if (hasApplied.value || unavailable.value) return null;
    const action = actionFor(steps.value.find((step) => step.key === 'submit')?.action);
    return action?.kind === 'link' && /^\/dashboard\/applications\/start\/\d+(?:[?#]|$)/.test(action.href) ? action : null;
});
const readinessNeedsAttention = computed(() => !isPreview.value && !unavailable.value && steps.value.some((step) => step.blocks_start || step.status === 'email_unverified'));
const fileDetails = computed(() => steps.value.find((step) => step.key === 'requirements')?.details ?? {});
const formRequirements = computed(() => {
    const files = fileDetails.value.required_file_count;
    const questions = fileDetails.value.question_count;
    return [
        files > 0 ? 'In Requirements, accept the document upload terms before using Upload or Replace for any missing or outdated files. Check that the correct file is shown for each required item.'
            : files === 0 ? 'In Requirements, no initial files are required for this scholarship.'
                : 'In Requirements, check which files the provider requests. Accept the document upload terms before uploading any files.',
        questions > 0 ? 'Answer the provider questions marked as required, then choose Continue to Confirm.'
            : questions === 0 ? 'There are no provider questions to answer. Choose Continue to Confirm.'
                : 'Answer any required provider questions, then choose Continue to Confirm.',
    ];
});
const requirementSummary = (step) => preparationRequirementSummary(step.details, isPreview.value);
const contextualHref = (href) => isPreview.value ? href : withPreparationContext(href, props.scholarshipId, 'summary');

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
        positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        attention: 'border-amber-200 bg-amber-50 text-amber-900',
        blocked: 'border-rose-200 bg-rose-50 text-rose-800',
    }[step.tone] ?? 'border-slate-200 bg-slate-50 text-slate-600';
}

function follow(event, action) {
    if (isPreview.value && action?.section) emit('navigate', event, action.section);
}
</script>

<template>
    <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-readiness data-scholarship-application-guide aria-labelledby="scholarship-application-guide-title">
        <header class="border-b border-slate-200 px-5 py-5 sm:px-6">
            <p class="student-kicker">{{ hasApplied ? 'Application submitted' : 'Application instructions' }}</p>
            <h3 id="scholarship-application-guide-title" class="mt-1.5 font-display text-2xl font-bold text-slate-950">{{ hasApplied ? 'Continue with your application' : 'How to apply' }}</h3>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ hasApplied ? 'Your current stage, provider updates, and next action are in your application record.' : isPreview ? 'Read-only preview. These instructions describe the applicant form; no application can be sent from this preview.' : 'Apply through this portal using the instructions below. Preparing your documents alone does not send an application.' }}</p>
        </header>

        <div v-if="hasApplied" class="px-5 py-5 sm:px-6" data-guide-existing-application>
            <a v-if="nextAction?.kind === 'link'" :href="nextAction.href" class="inline-flex min-h-11 items-center justify-center gap-3 rounded-sm bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-600">{{ nextAction.label }}<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
            <p class="mt-3 text-xs leading-5 text-slate-500">You do not need to submit another application for this scholarship.</p>
        </div>

        <template v-else>
            <div v-if="unavailable" data-guide-intake-notice class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-5 py-3 sm:px-6">
                <p class="max-w-2xl text-xs leading-5 text-slate-600">{{ guide.state === 'upcoming' ? 'You can prepare now, but must wait until applications open to start.' : 'This intake is not accepting applications. You can still review the requirements below.' }}</p>
                <button v-if="nextAction?.kind === 'save'" type="button" :disabled="saving" class="inline-flex min-h-10 items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 text-xs font-bold text-slate-800 hover:bg-slate-100 disabled:opacity-60" @click="emit('save')"><i class="fa-regular fa-bookmark" aria-hidden="true"></i>{{ saving ? 'Saving...' : nextAction.label }}</button>
                <a v-else-if="nextAction?.kind === 'link'" :href="nextAction.href" class="inline-flex min-h-10 items-center gap-2 text-xs font-bold text-slate-800 hover:underline">{{ nextAction.label }}<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
            </div>

            <template v-if="steps.length">
                <div class="px-5 py-5 sm:px-6" data-how-to-apply>
                    <ol class="space-y-5" aria-label="How to submit this application" data-application-instructions>
                        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-4" data-application-instruction="open">
                            <span class="grid h-8 w-8 place-items-center rounded-sm bg-slate-950 text-xs font-bold text-amber-300" aria-hidden="true">1</span>
                            <div class="min-w-0">
                                <h4 class="text-sm font-bold leading-6 text-slate-950">Open the application form</h4>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Choose <strong class="font-semibold text-slate-800">Start pre-screening</strong> on this page. This scholarship will already be selected. Opening the form does not submit it.</p>
                                <a v-if="startAction" :href="startAction.href" class="mt-3 inline-flex min-h-11 items-center gap-3 rounded-sm bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-600" data-guide-start>{{ startAction.label }}<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                                <p v-else-if="isPreview" class="mt-2 text-xs font-semibold leading-5 text-slate-500">Applicant-only action. The form cannot be opened from this preview.</p>
                                <p v-else class="mt-2 text-xs font-semibold leading-5 text-amber-900">{{ unavailable ? 'You can start only while this intake is accepting applications.' : 'The start action is unavailable. See Before you start below for the check that needs attention.' }}</p>
                            </div>
                        </li>
                        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-4" data-application-instruction="review">
                            <span class="grid h-8 w-8 place-items-center rounded-sm bg-slate-100 text-xs font-bold text-slate-600" aria-hidden="true">2</span>
                            <div class="min-w-0"><h4 class="text-sm font-bold leading-6 text-slate-950">Review the scholarship and your eligibility</h4><p class="mt-1 text-sm leading-6 text-slate-600">In <strong class="font-semibold text-slate-800">Program</strong>, review the selected scholarship and choose <strong class="font-semibold text-slate-800">Continue to Eligibility</strong>. Check the results, then choose <strong class="font-semibold text-slate-800">Continue to Requirements</strong>.</p></div>
                        </li>
                        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-4" data-application-instruction="complete">
                            <span class="grid h-8 w-8 place-items-center rounded-sm bg-slate-100 text-xs font-bold text-slate-600" aria-hidden="true">3</span>
                            <div class="min-w-0"><h4 class="text-sm font-bold leading-6 text-slate-950">Complete the Requirements screen</h4><p v-for="(instruction, index) in formRequirements" :key="index" class="mt-1 text-sm leading-6 text-slate-600">{{ instruction }}</p></div>
                        </li>
                        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-4" data-application-instruction="send">
                            <span class="grid h-8 w-8 place-items-center rounded-sm bg-slate-100 text-xs font-bold text-slate-600" aria-hidden="true">4</span>
                            <div class="min-w-0"><h4 class="text-sm font-bold leading-6 text-slate-950">Confirm and send your application</h4><p class="mt-1 text-sm leading-6 text-slate-600">In <strong class="font-semibold text-slate-800">Confirm</strong>, check the application summary, files, and any answers. Read and accept the application terms, then choose <strong class="font-semibold text-slate-800">Submit application</strong>. This final button sends your application to the provider.</p></div>
                        </li>
                    </ol>
                    <div class="mt-5 border-l-2 border-amber-400 bg-slate-50 px-4 py-3" data-guide-after-submit>
                        <p class="text-xs font-bold leading-5 text-slate-800">After you submit</p>
                        <p class="mt-1 text-sm leading-6 text-slate-600">Open Applications in the sidebar, then open this scholarship's application record to check its status and provider requests. Complete a formal application, exam, or interview only if the provider advances you and sends instructions.</p>
                    </div>
                </div>

                <details class="group/readiness border-t border-slate-200" data-guide-readiness :open="readinessNeedsAttention">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 bg-slate-50 px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                        <div class="min-w-0"><h4 class="text-sm font-bold text-slate-950">Before you start</h4><p class="mt-1 text-xs leading-5 text-slate-600">{{ readinessNeedsAttention ? 'A required check needs attention. Review the highlighted item below.' : isPreview ? 'Applicants see their own profile, eligibility, and document readiness here.' : 'Check your profile verification, eligibility, and document readiness.' }}</p></div>
                        <i class="fa-solid fa-chevron-down shrink-0 text-xs text-slate-500 transition group-open/readiness:rotate-180" aria-hidden="true"></i>
                    </summary>
            <ol class="divide-y divide-slate-200 border-t border-slate-200" aria-label="Application preparation checks">
                <li v-for="(step, index) in steps" :key="step.key" :data-preparation-step="step.key" :data-guide-next="isNext(step) || undefined" :aria-current="isNext(step) ? 'step' : undefined" :class="['border-l-[3px] px-4 py-4 sm:px-5 sm:py-5', isNext(step) ? 'border-l-amber-400 bg-amber-50/40' : 'border-l-transparent']">
                    <div class="grid min-w-0 grid-cols-[2rem_minmax(0,1fr)] gap-x-4 gap-y-3 xl:grid-cols-[2rem_minmax(0,1fr)_11.5rem_13rem] xl:items-start">
                        <span :class="['grid h-8 w-8 place-items-center rounded-sm text-xs font-bold tabular-nums', isNext(step) ? 'bg-slate-950 text-amber-300' : 'bg-slate-100 text-slate-500']" aria-hidden="true">{{ index + 1 }}</span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <h4 class="text-sm font-bold leading-6 text-slate-950"><span class="sr-only">Step {{ index + 1 }}: </span>{{ step.title }}</h4>
                                <span v-if="isNext(step)" class="text-[10px] font-bold uppercase tracking-[0.12em] text-amber-800">{{ isPreview ? 'Example next step' : step.status === 'pending' ? 'Waiting for review' : 'Next step' }}</span>
                            </div>
                            <p class="mt-1 text-sm leading-6 text-slate-600 [overflow-wrap:anywhere]">{{ step.description }}</p>
                            <p v-if="step.key === 'requirements' && requirementSummary(step)" data-guide-requirement-summary class="mt-2 text-xs font-semibold leading-5 text-slate-700">{{ requirementSummary(step) }}</p>
                            <details v-if="!isPreview && step.key === 'profile' && missingItems(step).length" data-guide-missing class="mt-2 text-xs">
                                <summary class="w-fit cursor-pointer py-1 font-bold text-slate-700 hover:text-slate-950">See what is missing ({{ missingItems(step).length }})</summary>
                                <ul class="mt-2 list-disc space-y-1 border-l border-slate-200 pl-6 text-slate-600"><li v-for="(label, itemIndex) in missingItems(step)" :key="itemIndex" class="leading-5">{{ label }}</li></ul>
                            </details>
                        </div>
                        <div data-guide-status class="col-start-2 min-w-0 xl:col-start-auto">
                            <span :class="['inline-flex max-w-full rounded-sm border px-2.5 py-1 text-xs font-bold leading-5', badgeStyle(step)]">{{ step.status_label || 'Review this step' }}</span>
                            <p v-if="!isPreview && step.blocks_start" class="mt-1.5 text-[11px] leading-5 text-slate-500">Needed before starting</p>
                            <p v-else-if="!isPreview && step.required_before === 'submission' && (step.key === 'submit' || step.details?.required_file_count > 0 || step.details?.required_question_count > 0)" class="mt-1.5 text-[11px] leading-5 text-slate-500">Complete before submitting</p>
                        </div>
                        <div data-guide-action class="col-start-2 min-w-0 xl:col-start-auto">
                            <a v-if="actionFor(step.action)?.kind === 'link'" :href="contextualHref(step.action.href)" :class="['inline-flex min-h-11 max-w-full items-center gap-2 rounded-sm text-xs font-bold leading-5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-600', isNext(step) ? 'bg-slate-950 px-3 py-2 text-white hover:bg-slate-800' : 'py-2 text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950']" @click="follow($event, step.action)">{{ step.action.label }}<i class="fa-solid fa-arrow-right shrink-0 text-[10px]" aria-hidden="true"></i></a>
                            <span v-else class="inline-flex min-h-11 items-center gap-2 text-xs leading-5 text-slate-400" aria-disabled="true"><i class="fa-solid fa-lock shrink-0 text-[10px]" aria-hidden="true"></i>{{ isPreview ? 'Applicant-only action' : step.action?.label || 'Complete earlier steps first' }}</span>
                        </div>
                    </div>
                </li>
            </ol>
                </details>
            </template>
            <p v-else class="px-5 py-5 text-sm leading-6 text-slate-600 sm:px-6">Application guidance is unavailable. Review the Eligibility and Application sections before continuing.</p>

            <footer class="border-t border-slate-200 bg-slate-50/70 px-5 py-4 sm:px-6">
                <p class="flex items-start gap-2 text-xs font-semibold leading-5 text-slate-700"><i class="fa-solid fa-circle-info mt-1 shrink-0 text-[10px] text-slate-500" aria-hidden="true"></i>Submitting starts pre-screening, not a scholarship award.</p>
                <details v-if="guide.submission?.note" data-guide-submission-note class="mt-2 text-xs">
                    <summary class="w-fit cursor-pointer py-1 font-semibold text-slate-500 hover:text-slate-800">{{ isPreview ? 'About this preview' : 'What is checked when I submit?' }}</summary>
                    <p class="mt-1 max-w-4xl border-l border-slate-200 pl-3 leading-6 text-slate-600">{{ guide.submission.note }}</p>
                </details>
            </footer>
        </template>
    </section>
</template>
