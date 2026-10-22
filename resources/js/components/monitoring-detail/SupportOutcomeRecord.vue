<script setup>
import { computed, ref } from 'vue';
import FilePreviewModal from '../FilePreviewModal.vue';
import SupportOutcomeResponseModal from './SupportOutcomeResponseModal.vue';

const props = defineProps({
    outcome: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});

const emit = defineEmits(['refresh']);
const previewFile = ref(null);
const responseOpen = ref(false);

const outcomeTone = computed(() => ({
    renewed: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    completed: 'border-slate-200 bg-slate-100 text-slate-700',
    terminated: 'border-rose-200 bg-rose-50 text-rose-800',
}[props.outcome.decision] ?? 'border-slate-200 bg-slate-100 text-slate-700'));

const responseTone = computed(() => ({
    open: 'bg-amber-100 text-amber-900',
    resolved: 'bg-emerald-100 text-emerald-800',
    acknowledged: 'bg-emerald-100 text-emerald-800',
}[props.outcome.response_status] ?? 'bg-slate-100 text-slate-700'));

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

        <a :href="outcome.return_href" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
            All support history
        </a>

        <article class="overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm">
            <header class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                <div class="flex min-w-0 items-start gap-4">
                    <span :class="['grid h-11 w-11 shrink-0 place-items-center border', outcomeTone]">
                        <i :class="outcome.decision === 'terminated' ? 'fa-solid fa-circle-exclamation' : outcome.decision === 'completed' ? 'fa-solid fa-flag-checkered' : 'fa-solid fa-rotate'" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Provider outcome</p>
                        <h3 class="mt-1 font-display text-xl font-bold text-slate-950 sm:text-2xl">{{ outcome.decision_label }}</h3>
                        <p v-if="outcome.reason_category_label" class="mt-1 text-sm font-bold text-slate-600">{{ outcome.reason_category_label }}</p>
                    </div>
                </div>
                <div class="shrink-0 sm:text-right">
                    <span :class="['inline-flex px-2.5 py-1 text-[10px] font-bold uppercase', responseTone]">{{ outcome.response_status_label }}</span>
                    <p v-if="outcome.decided_at" class="mt-2 text-xs text-slate-500">Recorded {{ outcome.decided_at }}</p>
                </div>
            </header>

            <section v-if="outcome.can_respond" class="flex flex-col gap-3 border-y border-amber-200 bg-amber-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <p class="font-bold text-slate-950">Your response is needed</p>
                    <p class="mt-1 text-sm text-slate-600">Acknowledge the outcome or ask the provider a question.</p>
                </div>
                <button type="button" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800" @click="responseOpen = true">
                    Respond to outcome
                    <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                </button>
            </section>

            <div class="px-5 py-5 sm:px-6">
                <section>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Decision summary</p>
                    <p class="mt-2 max-w-4xl text-sm leading-6 text-slate-700">{{ outcome.reason || 'The provider recorded this support outcome.' }}</p>
                </section>

                <dl class="mt-5 grid border border-slate-200 bg-slate-50 sm:grid-cols-3">
                    <div class="px-4 py-3">
                        <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Effective date</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ outcome.effective_label || 'Not specified' }}</dd>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3 sm:border-l sm:border-t-0">
                        <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Support through</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ outcome.support_ends_label || (outcome.decision === 'terminated' ? outcome.effective_label : 'Not specified') }}</dd>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3 sm:border-l sm:border-t-0">
                        <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Next review</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ outcome.next_review_label || 'No review scheduled' }}</dd>
                    </div>
                </dl>

                <section v-if="outcome.next_period_terms" class="mt-5 border-l-4 border-amber-400 bg-amber-50 px-4 py-3">
                    <p class="text-xs font-bold text-slate-950">Next period terms</p>
                    <p class="mt-1 text-sm leading-6 text-slate-700">{{ outcome.next_period_terms }}</p>
                </section>

                <div v-if="outcome.decision_document" class="mt-5 border-t border-slate-200 pt-4">
                    <button type="button" class="inline-flex min-h-10 items-center gap-2 border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = outcome.decision_document">
                        <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
                        View decision file
                    </button>
                </div>
            </div>
        </article>

        <section v-if="outcome.applicant_response_label" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Your response</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">{{ outcome.applicant_response_label }}</h3>
                <p v-if="outcome.applicant_responded_at" class="mt-1 text-xs text-slate-500">Sent {{ outcome.applicant_responded_at }}</p>
            </header>
            <div class="px-5 py-4 sm:px-6">
                <p v-if="outcome.applicant_response_message" class="text-sm leading-6 text-slate-700">{{ outcome.applicant_response_message }}</p>
                <button v-if="outcome.applicant_response_file" type="button" class="mt-3 inline-flex min-h-10 items-center gap-2 border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = outcome.applicant_response_file">
                    <i class="fa-solid fa-paperclip" aria-hidden="true"></i>
                    View supporting file
                </button>
            </div>
        </section>

        <section v-if="outcome.resolution_notes || outcome.resolution_proof" class="overflow-hidden rounded-md border border-emerald-300 bg-white shadow-sm">
            <header class="border-b border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-emerald-700">Provider resolution</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">{{ outcome.resolution_outcome_label || 'Response resolved' }}</h3>
            </header>
            <div class="px-5 py-4 sm:px-6">
                <p v-if="outcome.resolution_notes" class="text-sm leading-6 text-slate-700">{{ outcome.resolution_notes }}</p>
                <p v-if="outcome.resolved_at" class="mt-2 text-xs text-slate-500">Resolved {{ outcome.resolved_at }}</p>
                <button v-if="outcome.resolution_proof" type="button" class="mt-3 inline-flex min-h-10 items-center gap-2 border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = outcome.resolution_proof">
                    <i class="fa-regular fa-file-circle-check" aria-hidden="true"></i>
                    View resolution file
                </button>
            </div>
        </section>
    </div>
</template>
