<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../../composables/useModalAccessibility';
import { useApplicantEvidenceDecision } from '../../../composables/useApplicantEvidenceDecision.js';
import { evidenceLabel, evidenceProfileClaims, evidenceReviewDate, evidenceState } from '../../../support/applicantEvidenceReview.js';

const props = defineProps({
    document: { type: Object, default: null },
    applicant: { type: Object, required: true },
    allowed: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'saved', 'reload', 'busy']);
const dialog = ref(null);
const previewFailed = ref(false);
const headingId = `${useId()}-evidence-title`;
const label = computed(() => evidenceLabel(props.applicant, props.document?.document_type));
const claims = computed(() => evidenceProfileClaims(props.applicant, props.document));
const state = computed(() => evidenceState(props.document));
const retired = computed(() => props.document?.document_type === 'no_current_scholarship_proof');
const academic = computed(() => props.document?.document_type === 'academic_record');
const gradingScale = ref('percentage');
const academicResult = ref('');
const numericScale = computed(() => ['percentage', 'grade_point'].includes(gradingScale.value));
const resultMaximum = computed(() => gradingScale.value === 'grade_point' ? 5 : 100);
const academicValid = computed(() => !academic.value || (['percentage', 'grade_point', 'pass_fail', 'other'].includes(gradingScale.value)
    && (!numericScale.value || (String(academicResult.value).trim() !== '' && Number.isFinite(Number(academicResult.value))
        && Number(academicResult.value) > 0 && Number(academicResult.value) <= resultMaximum.value))));
const previewKind = computed(() => {
    if (props.document?.file_available === false || previewFailed.value) return '';
    if (['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(props.document?.mime_type)) return 'image';
    return props.document?.mime_type === 'application/pdf' ? 'pdf' : '';
});
const { outcome, notes, compared, saving, stale, error, canSave, reset, save } = useApplicantEvidenceDecision({
    document: () => props.document,
    allowed: () => props.allowed && !retired.value,
    submit: (...args) => window.axios.patch(...args),
    onSaved: (payload) => emit('saved', payload),
    extraValid: () => academicValid.value,
    extraPayload: () => academic.value ? { academic_grading_scale: gradingScale.value,
        academic_result: numericScale.value ? Number(academicResult.value) : null } : {},
});
const { handleKeydown } = useModalAccessibility(() => !!props.document, dialog, close);
watch(() => props.document, () => {
    reset(); previewFailed.value = false;
    gradingScale.value = props.applicant.grading_scale || props.document?.ocr_grading_scale || 'percentage';
    academicResult.value = props.applicant.gwa ?? props.document?.ocr_grade ?? '';
}, { immediate: true });
watch(saving, (value) => emit('busy', value), { flush: 'sync' });
function close() { if (!saving.value) emit('close'); }
</script>

<template>
    <Teleport to="body">
        <div v-if="document" class="fixed inset-0 z-[2000] grid place-items-center bg-slate-950/60 p-5" @click.self="close">
            <section ref="dialog" class="evidence-review-dialog flex w-full max-w-[90rem] flex-col overflow-hidden rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl" role="dialog" aria-modal="true" :aria-labelledby="headingId" :aria-busy="saving" tabindex="-1" @keydown="handleKeydown">
                <header class="flex shrink-0 items-start justify-between gap-5 border-b border-slate-200 px-6 py-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span>
                        <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Review supporting evidence</p><h2 :id="headingId" class="mt-1 text-xl font-bold text-slate-950">{{ label }}</h2><p class="mt-1 break-words text-xs text-slate-500">{{ applicant.name }} | {{ document.original_name }}</p></div>
                    </div>
                    <button type="button" :disabled="saving" class="grid h-10 w-10 shrink-0 place-items-center border border-slate-300 text-slate-600 disabled:opacity-40" aria-label="Close evidence review" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div class="evidence-review-body grid min-h-0 flex-1 grid-cols-[minmax(0,1.6fr)_minmax(22rem,1fr)] overflow-y-auto">
                    <div class="evidence-review-preview flex min-h-0 flex-col border-r border-slate-200 bg-slate-100 p-4">
                        <div class="mb-3 flex items-center justify-between gap-3 text-xs"><span class="font-bold text-slate-600">Submitted file <a v-if="document.file_available !== false" :href="document.view_url" download data-file-preview="false" class="ml-3 font-normal underline underline-offset-2">Download file</a></span><span :class="['px-2 py-1 font-bold', state.class]">{{ state.label }}</span></div>
                        <img v-if="previewKind === 'image'" :src="document.view_url" :alt="label + ' submitted by ' + applicant.name" class="min-h-0 w-full flex-1 object-contain" @error="previewFailed = true">
                        <iframe v-else-if="previewKind === 'pdf'" :key="document.revision" :src="document.view_url" :title="label + ' document preview'" class="min-h-[24rem] w-full flex-1 border border-slate-200 bg-white" @error="previewFailed = true"></iframe>
                        <div v-else class="grid min-h-[24rem] flex-1 place-content-center gap-3 px-5 text-center text-sm text-slate-600"><i class="fa-regular fa-file-lines text-3xl" aria-hidden="true"></i><p>{{ document.file_available === false ? 'The saved file is unavailable. Request a replacement.' : 'This file cannot be displayed here. Use the file preview to inspect it.' }}</p></div>
                        <a v-if="document.file_available !== false" :href="document.view_url" data-file-preview="true" :data-file-name="document.original_name" class="mt-3 inline-flex min-h-10 items-center justify-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700"><i class="fa-solid fa-expand" aria-hidden="true"></i>Open larger file preview</a>
                    </div>
                    <div class="min-w-0 space-y-5 px-6 py-5">
                        <section>
                            <h3 class="text-sm font-black text-slate-950">Compare with the profile</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">These details are self-declared. Accept only when the file supports them.</p>
                            <dl class="mt-4 grid grid-cols-2 gap-x-5 gap-y-4">
                                <div v-for="claim in claims" :key="claim.key" :class="['min-w-0', claim.span === 'two' || claim.span === 'full' ? 'col-span-2' : '']"><dt class="text-xs text-slate-500">{{ claim.label }}</dt><dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ claim.value || 'Not provided' }}</dd><dd v-if="claim.detail" class="mt-0.5 text-xs text-slate-500">{{ claim.detail }}</dd></div>
                            </dl>
                            <p v-if="!claims.length" class="mt-3 text-sm text-slate-600">{{ document.supports || 'Inspect the saved record and the applicant profile before deciding.' }}</p>
                        </section>
                        <details v-if="document.document_type === 'academic_record'" class="border-y border-slate-200 py-3">
                            <summary class="cursor-pointer text-xs font-bold text-slate-600">OCR extraction (not verified)</summary>
                            <p class="mt-2 text-xs leading-5 text-slate-600">{{ document.ocr_grade != null ? 'Extracted result: ' + document.ocr_grade : 'No academic result extracted.' }} {{ document.ocr_message }}</p>
                            <p class="mt-1 text-xs text-slate-500">Compare this suggestion with the file. Confirm the result below when accepting the academic record.</p>
                        </details>
                        <div v-if="document.reviewed_at || document.review_notes" class="border-l-2 border-slate-200 pl-3 text-xs leading-5 text-slate-600"><p v-if="document.reviewed_at">Last decision by <strong>{{ document.reviewed_by || 'Reviewer' }}</strong><br>{{ evidenceReviewDate(document.reviewed_at) }}</p><p v-if="document.review_notes" class="mt-1 whitespace-pre-line break-words">{{ document.review_notes }}</p></div>
                        <form v-if="allowed && !retired" id="evidence-decision" class="border-t border-slate-200 pt-4" @submit.prevent="save">
                            <p v-if="applicant.is_profile_verified" class="mb-4 border-l-2 border-amber-400 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950">Recording another decision on required evidence reopens this verified profile for a new overall review.</p>
                            <fieldset :disabled="saving || stale" class="space-y-4">
                                <legend class="mb-3 text-sm font-black text-slate-950">Document decision</legend>
                                <div class="flex flex-wrap gap-2">
                                    <label :class="['flex min-h-10 cursor-pointer items-center gap-2 border px-3 text-sm font-bold', outcome === 'approved' ? 'border-slate-950 bg-slate-50' : 'border-slate-300', document.file_available === false ? 'cursor-not-allowed opacity-40' : '']"><input v-model="outcome" type="radio" value="approved" name="evidence-outcome" :disabled="document.file_available === false">Accept document</label>
                                    <label :class="['flex min-h-10 cursor-pointer items-center gap-2 border px-3 text-sm font-bold', outcome === 'rejected' ? 'border-slate-950 bg-slate-50' : 'border-slate-300']"><input v-model="outcome" type="radio" value="rejected" name="evidence-outcome">Request replacement</label>
                                </div>
                                <section v-if="academic && outcome === 'approved'" data-academic-results class="border-y border-slate-200 py-4">
                                    <h3 class="text-sm font-black text-slate-950">Academic results</h3>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">Confirm or correct the result from this file. It saves with this document decision, not with final profile verification.</p>
                                    <div class="mt-3 grid grid-cols-2 gap-3">
                                        <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Grading scale</span><select v-model="gradingScale" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="percentage">Percentage</option><option value="grade_point">Grade point</option><option value="pass_fail">Pass / fail</option><option value="other">Other</option></select></label>
                                        <label v-if="numericScale"><span class="mb-1.5 block text-xs font-bold text-slate-700">Verified result</span><input v-model="academicResult" type="number" step="0.01" min="0.01" :max="resultMaximum" required class="h-11 w-full border border-slate-300 px-3 text-sm"></label>
                                    </div>
                                    <p v-if="!academicValid" class="mt-2 text-xs text-amber-900">Enter a result greater than 0 and at most {{ resultMaximum }}.</p>
                                    <p v-if="!numericScale" class="mt-2 text-xs text-slate-500">No numeric average is recorded for this scale.</p>
                                </section>
                                <label v-if="outcome === 'approved'" class="flex items-start gap-2 text-sm leading-5 text-slate-700"><input v-model="compared" type="checkbox" class="mt-1 shrink-0">I compared this file with the profile details shown above.</label>
                                <label v-if="outcome" class="block"><span class="mb-1.5 block text-xs font-bold text-slate-700">{{ outcome === 'rejected' ? 'What must the applicant correct?' : 'Reviewer note (optional)' }}</span><textarea v-model="notes" rows="3" maxlength="1500" :required="outcome === 'rejected'" class="w-full border border-slate-300 bg-white px-3 py-2 text-sm" :placeholder="outcome === 'rejected' ? 'For example: upload a clear record showing the current academic year.' : 'Add context only if needed.'"></textarea></label>
                            </fieldset>
                        </form>
                        <p v-else class="border-t border-slate-200 pt-4 text-sm text-slate-600">{{ retired ? 'Retired evidence: kept for reference, not required for profile verification.' : 'This is a read-only record. A review officer records document decisions.' }}</p>
                        <p class="text-xs leading-5 text-slate-500">Acceptance is a reviewer decision, not authentication by the issuing authority. It does not verify the whole profile or pass an application.</p>
                        <div v-if="error" role="alert" class="border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-950"><p>{{ error }}</p><button v-if="stale" type="button" class="mt-2 underline underline-offset-2 font-bold" @click="emit('reload')">Reload current record</button></div>
                    </div>
                </div>
                <footer class="flex shrink-0 items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-xs text-slate-500">One document at a time. Overall verification is a separate step.</p>
                    <div class="flex shrink-0 gap-3"><button type="button" :disabled="saving" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold disabled:opacity-40" @click="close">{{ allowed && !retired ? 'Cancel' : 'Close' }}</button><button v-if="allowed && !retired" type="submit" form="evidence-decision" :disabled="!canSave" class="h-10 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40">{{ saving ? 'Saving...' : outcome === 'rejected' ? 'Request replacement' : outcome === 'approved' ? 'Accept document' : 'Save document decision' }}</button></div>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.evidence-review-dialog { height: min(56rem, calc(100dvh - 2.5rem)); }
@media (max-height: 700px) { .evidence-review-preview { min-height: 34rem; } }
</style>
