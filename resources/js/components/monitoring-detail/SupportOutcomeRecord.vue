<script setup>
import { computed, ref } from 'vue';
import FilePreviewModal from '../FilePreviewModal.vue';
import SupportOutcomeResponseModal from './SupportOutcomeResponseModal.vue';
import SupportCloseoutBenefits from './SupportCloseoutBenefits.vue';
import RecipientCaseReviewLauncher from '../case-reviews/RecipientCaseReviewLauncher.vue';
import { checkInStatusClass } from '../../support/checkInStatus.js';

const props = defineProps({
    outcome: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});

const emit = defineEmits(['refresh']);
const previewFile = ref(null);
const responseOpen = ref(false);

const responseTone = computed(() => ({
    open: 'info',
    resolved: 'positive',
    acknowledged: 'positive',
}[props.outcome.response_status] ?? (props.outcome.can_respond ? 'attention' : 'neutral')));

const facts = computed(() => [
    ['Effective date', props.outcome.effective_label],
    ['Support through', props.outcome.support_ends_label],
    ['Next review', props.outcome.next_review_label],
    ['Notice given', props.outcome.notice_given_label],
].filter(([, value]) => value));

const nextStep = computed(() => {
    if (props.outcome.can_respond) {
        return {
            title: 'Review and respond',
            description: props.outcome.case_review_url
                ? 'Acknowledge that you read the decision. To ask for a review, open the case below.'
                : 'Acknowledge the decision or ask the provider for clarification. Acknowledging does not mean you agree.',
        };
    }
    if (props.outcome.response_status === 'open') {
        return { title: 'Wait for the provider', description: 'Your request is on file. The provider’s reply will appear on this record.' };
    }
    return { title: 'No response needed', description: 'Keep this record for reference.' };
});

function saved() {
    emit('refresh');
}
</script>

<template>
    <div data-support-outcome-record class="mt-4 space-y-4">
        <FilePreviewModal
            :file="previewFile"
            :title="previewFile?.original_name || 'Support outcome file'"
            :context="programTitle"
            @close="previewFile = null"
        />
        <SupportOutcomeResponseModal
            :open="responseOpen"
            :outcome="outcome"
            @close="responseOpen = false"
            @saved="saved"
        />

        <a :href="outcome.return_href" class="inline-flex min-h-9 items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
            All support history
        </a>

        <article class="overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm">
            <header class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                <div class="flex min-w-0 items-start gap-4">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300">
                        <i :class="outcome.withdrawal_approved ? 'fa-solid fa-door-open' : outcome.decision === 'terminated' ? 'fa-solid fa-circle-exclamation' : outcome.decision === 'completed' ? 'fa-solid fa-flag-checkered' : 'fa-solid fa-rotate'" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Provider outcome</p>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            <h2 class="break-words font-display text-xl font-bold text-slate-950 sm:text-2xl">{{ outcome.decision_label }}</h2>
                            <span :class="['rounded-sm border px-2 py-0.5 text-xs font-bold', checkInStatusClass(responseTone)]">{{ outcome.response_status_label }}</span>
                        </div>
                        <p v-if="outcome.decided_at" class="mt-2 text-xs text-slate-600">Recorded {{ outcome.decided_at }}</p>
                    </div>
                </div>
            </header>

            <p v-if="outcome.closeout && !outcome.closeout.current" class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-600 sm:px-6">This is an earlier decision. See the overview for your current support status.</p>

            <div class="grid border-t border-slate-200 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <section class="min-w-0 p-4 sm:px-6 sm:py-5">
                    <h3 class="font-bold text-slate-950">Decision summary</h3>
                    <p v-if="outcome.reason_category_label" class="mt-1 text-sm font-semibold text-slate-700">{{ outcome.reason_category_label }}</p>
                    <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ outcome.reason || 'The provider did not include a note with this decision.' }}</p>

                    <dl v-if="facts.length" class="mt-4 grid grid-cols-2 gap-4 border-t border-slate-200 pt-4">
                        <div v-for="[label, value] in facts" :key="label">
                            <dt class="student-detail-label">{{ label }}</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ value }}</dd>
                        </div>
                    </dl>
                    <section v-if="outcome.next_period_terms" class="mt-4 border-l-2 border-amber-400 bg-amber-50 px-3 py-2.5">
                        <h4 class="text-sm font-bold text-slate-950">Next period terms</h4>
                        <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ outcome.next_period_terms }}</p>
                    </section>
                    <div v-if="outcome.decision_document" class="mt-4">
                        <button type="button" class="student-button student-button-secondary" @click="previewFile = outcome.decision_document">
                            <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
                            View decision file
                        </button>
                    </div>
                </section>

                <section data-support-outcome-next-step :class="['border-t border-slate-200 p-4 sm:px-6 sm:py-5 md:border-l md:border-t-0', outcome.can_respond ? 'bg-amber-50' : 'bg-slate-50']">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-600">Your next step</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ nextStep.title }}</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-700">{{ nextStep.description }}</p>
                    <button v-if="outcome.can_respond" type="button" class="student-button student-button-primary mt-4" @click="responseOpen = true">
                        Respond to decision <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </button>
                    <RecipientCaseReviewLauncher v-if="outcome.case_review_url" class="mt-4" :read-url="outcome.case_review_url" />
                </section>
            </div>
            <SupportCloseoutBenefits v-if="outcome.closeout" :closeout="outcome.closeout" />
        </article>

        <section v-if="outcome.applicant_response_label" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Your response</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">{{ outcome.applicant_response_label }}</h3>
                <p v-if="outcome.applicant_responded_at" class="mt-1 text-xs text-slate-500">Sent {{ outcome.applicant_responded_at }}</p>
            </header>
            <div v-if="outcome.applicant_response_message || outcome.applicant_response_file" class="px-5 py-4 sm:px-6">
                <p v-if="outcome.applicant_response_message" class="whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ outcome.applicant_response_message }}</p>
                <button v-if="outcome.applicant_response_file" type="button" class="student-button student-button-secondary mt-3" @click="previewFile = outcome.applicant_response_file">
                    <i class="fa-solid fa-paperclip" aria-hidden="true"></i>
                    View supporting file
                </button>
            </div>
        </section>

        <section v-if="outcome.resolution_outcome_label || outcome.resolution_notes || outcome.resolution_proof || outcome.resolved_at" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="border-b border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-700">Provider resolution</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">{{ outcome.resolution_outcome_label || 'Response resolved' }}</h3>
            </header>
            <div class="px-5 py-4 sm:px-6">
                <p v-if="outcome.resolution_notes" class="whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ outcome.resolution_notes }}</p>
                <p v-if="outcome.resolved_at" class="mt-2 text-xs text-slate-500">Resolved {{ outcome.resolved_at }}</p>
                <button v-if="outcome.resolution_proof" type="button" class="student-button student-button-secondary mt-3" @click="previewFile = outcome.resolution_proof">
                    <i class="fa-regular fa-file-circle-check" aria-hidden="true"></i>
                    View resolution file
                </button>
            </div>
        </section>
    </div>
</template>
