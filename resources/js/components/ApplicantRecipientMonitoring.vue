<script setup>
import { ref } from 'vue';
import FilePreviewModal from './FilePreviewModal.vue';
import { showPortalToast } from '../support/portalToast';

const props = defineProps({
    monitoring: { type: Object, required: true },
    applicationId: { type: [Number, String], required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['application-updated']);
const fileInput = ref(null);
const activeCycle = ref(null);
const uploadingCycleId = ref(null);
const savingManualCycleId = ref(null);
const acceptedTerms = ref({});
const manualForms = ref({});
const previewFile = ref(null);

function statusClass(status) {
    if (status === 'open') return 'bg-emerald-100 text-emerald-800';
    if (status === 'upcoming') return 'bg-sky-100 text-sky-800';
    if (status === 'action_needed') return 'bg-amber-100 text-amber-800';
    return 'bg-slate-100 text-slate-600';
}

function comparisonClass(status) {
    if (status === 'pass') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (status === 'fail') return 'border-rose-200 bg-rose-50 text-rose-700';
    return 'border-amber-200 bg-amber-50 text-amber-800';
}

function reviewClass(status) {
    if (status === 'met') return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    if (status === 'not_met') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (status === 'needs_correction') return 'border-amber-200 bg-amber-50 text-amber-900';
    if (status === 'excused') return 'border-sky-200 bg-sky-50 text-sky-900';
    return 'border-slate-200 bg-slate-50 text-slate-700';
}

function reviewIcon(status) {
    if (status === 'met') return 'fa-solid fa-circle-check';
    if (status === 'not_met') return 'fa-solid fa-circle-xmark';
    if (status === 'needs_correction') return 'fa-solid fa-rotate';
    if (status === 'excused') return 'fa-solid fa-shield-heart';
    return 'fa-regular fa-clock';
}

function releaseStatusClass(status) {
    if (status === 'released') return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    if (status === 'prepared') return 'border-sky-200 bg-sky-50 text-sky-900';
    if (status === 'missed' || status === 'withheld') return 'border-rose-200 bg-rose-50 text-rose-800';
    return 'border-amber-200 bg-amber-50 text-amber-900';
}

function releaseStatusIcon(status) {
    if (status === 'released') return 'fa-solid fa-circle-check';
    if (status === 'prepared') return 'fa-solid fa-box-open';
    if (status === 'missed') return 'fa-solid fa-calendar-xmark';
    if (status === 'withheld') return 'fa-solid fa-circle-pause';
    return 'fa-regular fa-calendar-check';
}

function openFilePicker(cycle) {
    if (!cycle.submission && !acceptedTerms.value[cycle.id]) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that this grade record is yours and matches the listed academic period.',
        });
        return;
    }

    activeCycle.value = cycle;
    if (fileInput.value) {
        fileInput.value.value = '';
        fileInput.value.click();
    }
}

async function uploadGradeRecord(event) {
    const file = event.target.files?.[0];
    const cycle = activeCycle.value;
    if (!file || !cycle) return;

    uploadingCycleId.value = cycle.id;
    const data = new FormData();
    data.append('grade_record', file);
    data.append('terms_accepted', '1');

    try {
        const response = await window.axios.post(
            `/dashboard/applications/${props.applicationId}/monitoring-cycles/${cycle.id}/grade-record`,
            data,
        );
        emit('application-updated', response.data.application);
        showPortalToast({ type: 'success', title: 'Grade record saved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Upload not completed',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to upload this grade record.'),
        });
    } finally {
        uploadingCycleId.value = null;
        activeCycle.value = null;
    }
}

function manualForm(cycle) {
    if (!manualForms.value[cycle.id]) {
        manualForms.value[cycle.id] = {
            grade: cycle.submission?.grade ?? '',
            grading_scale: cycle.submission?.grading_scale ?? cycle.grading_scale ?? 'percentage',
        };
    }

    return manualForms.value[cycle.id];
}

async function saveManualGrade(cycle) {
    const form = manualForm(cycle);
    if (!form.grade) {
        showPortalToast({ type: 'error', title: 'Result required', message: 'Enter the final result shown on the uploaded grade record.' });
        return;
    }

    savingManualCycleId.value = cycle.id;
    try {
        const response = await window.axios.patch(
            `/dashboard/applications/${props.applicationId}/monitoring-cycles/${cycle.id}/manual-grade`,
            form,
        );
        emit('application-updated', response.data.application);
        showPortalToast({ type: 'success', title: 'Result saved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Result not saved',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to save this result.'),
        });
    } finally {
        savingManualCycleId.value = null;
    }
}
</script>

<template>
    <FilePreviewModal
        :file="previewFile"
        :title="previewFile?.original_name || 'Academic progress record'"
        :context="programTitle"
        @close="previewFile = null"
    />

    <section v-if="monitoring.benefit_releases?.length" class="mb-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <header class="student-section-head p-4 sm:p-5">
            <div class="flex items-start gap-3">
                <span class="student-section-mark"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i></span>
                <div>
                    <p class="student-kicker">Scholarship support</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Benefit releases</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Review your release schedule, provider instructions, and recorded receipt status.</p>
                </div>
            </div>
        </header>

        <div class="divide-y divide-slate-200 border-t border-slate-200">
            <article v-for="release in monitoring.benefit_releases" :key="release.record_id" class="p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2"><h4 class="font-bold text-slate-950">{{ release.title }}</h4><span :class="['rounded-md border px-2 py-1 text-[10px] font-bold uppercase', releaseStatusClass(release.status)]">{{ release.status_label }}</span></div>
                        <p class="mt-1 text-sm font-semibold text-slate-700">{{ release.benefit_description }}<span v-if="release.amount_label"> · {{ release.amount_label }}</span></p>
                    </div>
                    <div class="shrink-0 sm:text-right"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Release schedule</p><p class="mt-1 text-sm font-bold text-slate-950">{{ release.release_label }}</p></div>
                </div>

                <div class="mt-3 grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-3">
                    <div class="bg-slate-50 px-3 py-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Method</p><p class="mt-1 text-sm font-bold text-slate-950">{{ release.release_method_label }}</p></div>
                    <div class="bg-slate-50 px-3 py-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Location</p><p class="mt-1 text-sm font-bold text-slate-950">{{ release.location || 'Provider-coordinated' }}</p></div>
                    <div class="bg-slate-50 px-3 py-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Original records</p><p class="mt-1 text-sm font-bold text-slate-950">{{ release.requires_original_verification ? (release.originals_verified ? 'Verified' : 'Bring for verification') : 'Not required at release' }}</p></div>
                </div>

                <p v-if="release.instructions" class="mt-3 text-sm leading-6 text-slate-600"><strong class="text-slate-900">Provider instruction:</strong> {{ release.instructions }}</p>
                <div :class="['mt-3 flex items-start gap-3 rounded-md border px-3 py-3', releaseStatusClass(release.status)]">
                    <i :class="[releaseStatusIcon(release.status), 'mt-0.5 shrink-0']" aria-hidden="true"></i>
                    <div class="min-w-0 flex-1"><p class="text-sm font-bold">{{ release.status_label }}</p><p v-if="release.notes" class="mt-1 text-xs leading-5">{{ release.notes }}</p><p v-else-if="release.status === 'scheduled'" class="mt-1 text-xs leading-5">Follow the schedule and instructions above. The provider will update this record after the release.</p><p v-if="release.recorded_at" class="mt-1 text-[11px] opacity-70">Updated {{ release.recorded_at }}<span v-if="release.recorded_by"> by {{ release.recorded_by }}</span></p></div>
                    <button v-if="release.receipt" type="button" class="shrink-0 rounded-md border border-current/20 bg-white/70 px-3 py-2 text-xs font-bold" @click="previewFile = release.receipt">View receipt</button>
                </div>
            </article>
        </div>
    </section>

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <header class="student-section-head p-4 sm:p-5">
            <div class="flex items-start gap-3">
                <span class="student-section-mark"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
                <div>
                    <p class="student-kicker">Recipient monitoring</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Academic progress updates</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Upload the requested grade record. OCR.space will read the final result, and the provider will verify it against the file.</p>
                </div>
            </div>
            <span v-if="monitoring.pending_count" class="rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800">{{ monitoring.pending_count }} to submit</span>
        </header>

        <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadGradeRecord">

        <div v-if="!monitoring.cycles?.length" class="border-t border-slate-200 px-5 py-8 text-center">
            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span>
            <p class="mt-3 font-bold text-slate-950">No academic update is requested</p>
            <p class="mt-1 text-sm text-slate-500">The provider will notify you when a monitoring period opens.</p>
        </div>

        <div v-else class="divide-y divide-slate-200 border-t border-slate-200">
            <article v-for="cycle in monitoring.cycles" :key="cycle.id" class="p-4 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="font-bold text-slate-950">{{ cycle.title }}</h4>
                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(cycle.status)]">{{ cycle.status }}</span>
                        </div>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ cycle.academic_period || 'Academic progress period' }}<span v-if="cycle.school_year"> · {{ cycle.school_year }}</span></p>
                    </div>
                    <div class="shrink-0 sm:text-right"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Due date</p><p class="mt-1 text-sm font-bold text-slate-950">{{ cycle.due_label }}</p></div>
                </div>

                <div class="mt-3 grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-2">
                    <div class="bg-slate-50 px-3 py-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Required result</p><p class="mt-1 text-sm font-bold text-slate-950">{{ cycle.requirement_label }}</p></div>
                    <div class="bg-slate-50 px-3 py-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">File accepted</p><p class="mt-1 text-sm font-bold text-slate-950">PDF, JPG, JPEG, or PNG</p></div>
                </div>
                <p v-if="cycle.instructions" class="mt-3 text-sm leading-6 text-slate-600"><strong class="text-slate-900">Provider instruction:</strong> {{ cycle.instructions }}</p>

                <div v-if="cycle.submission" class="mt-4 overflow-hidden rounded-md border border-slate-200">
                    <div class="flex flex-col gap-3 bg-slate-50 px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ cycle.submission.original_name }}</p><p class="mt-1 text-xs text-slate-500">Submitted {{ cycle.submission.submitted_at }}</p></div>
                        <div class="flex shrink-0 gap-2"><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = cycle.submission">View record</button><button v-if="cycle.can_submit" type="button" :disabled="uploadingCycleId === cycle.id" :class="['rounded-md px-3 py-2 text-xs font-bold disabled:opacity-60', cycle.correction_requested ? 'bg-slate-950 text-white hover:bg-slate-800' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50']" @click="openFilePicker(cycle)">{{ cycle.correction_requested ? 'Upload replacement' : 'Replace' }}</button></div>
                    </div>
                    <div class="border-t border-slate-200 px-3 py-3">
                        <div :class="['mb-3 flex items-start gap-3 rounded-md border px-3 py-3', reviewClass(cycle.submission.review_status)]">
                            <i :class="[reviewIcon(cycle.submission.review_status), 'mt-0.5 shrink-0']" aria-hidden="true"></i>
                            <div class="min-w-0">
                                <p class="text-sm font-bold">{{ cycle.submission.review_status_label }}</p>
                                <p v-if="cycle.submission.review_notes" class="mt-1 text-xs leading-5">{{ cycle.submission.review_notes }}</p>
                                <p v-else-if="cycle.submission.review_status === 'pending'" class="mt-1 text-xs leading-5">The provider will compare the result with the uploaded record.</p>
                                <p v-if="cycle.submission.reviewed_at" class="mt-1 text-[11px] opacity-70">Reviewed {{ cycle.submission.reviewed_at }}<span v-if="cycle.submission.reviewed_by"> by {{ cycle.submission.reviewed_by }}</span></p>
                            </div>
                        </div>
                        <div v-if="cycle.submission.grade_label" :class="['flex flex-col gap-2 rounded-md border px-3 py-3 sm:flex-row sm:items-center sm:justify-between', comparisonClass(cycle.submission.comparison?.status)]">
                            <div><p class="text-[10px] font-bold uppercase tracking-[0.1em] opacity-75">{{ cycle.submission.grade_source === 'ocr' ? 'OCR.space extracted result' : 'Applicant-entered result' }}</p><p class="mt-1 text-lg font-bold">{{ cycle.submission.grade_label }}</p></div>
                            <p class="text-xs font-bold uppercase">{{ cycle.submission.comparison?.status === 'pass' ? 'Meets listed requirement' : cycle.submission.comparison?.status === 'fail' ? 'Provider review needed' : 'Manual review' }}</p>
                        </div>
                        <div v-else class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm leading-6 text-amber-900"><p class="font-bold">The file was saved, but the final result was not extracted.</p><p class="mt-1 text-xs">{{ cycle.submission.ocr_message }}</p></div>

                        <form v-if="cycle.submission.manual_entry_allowed && cycle.can_submit" class="mt-3 rounded-md border border-slate-200 bg-white p-3" @submit.prevent="saveManualGrade(cycle)">
                            <p class="text-sm font-bold text-slate-950">Enter the result shown on the record</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">This does not replace provider verification. It only supplies the value OCR.space could not read.</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Grading scale</span><select v-model="manualForm(cycle).grading_scale" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="percentage">Percentage</option><option value="grade_point">GWA / GPA grade point</option></select></label>
                                <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Final result</span><input v-model="manualForm(cycle).grade" type="number" step="0.01" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                                <button type="submit" :disabled="savingManualCycleId === cycle.id" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ savingManualCycleId === cycle.id ? 'Saving...' : 'Save result' }}</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div v-else-if="cycle.can_submit" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <label class="flex cursor-pointer items-start gap-3 text-sm leading-5 text-slate-700"><input v-model="acceptedTerms[cycle.id]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-950"><span>I confirm this grade record belongs to me and covers the academic period listed above.</span></label>
                    <button type="button" :disabled="uploadingCycleId === cycle.id" class="mt-3 inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60" @click="openFilePicker(cycle)"><i class="fa-solid fa-arrow-up-from-bracket text-xs"></i>{{ uploadingCycleId === cycle.id ? 'Scanning...' : 'Upload and scan grade record' }}</button>
                </div>
                <p v-else-if="!cycle.submission" class="mt-3 rounded-md bg-slate-100 px-3 py-2.5 text-sm font-semibold text-slate-600">{{ cycle.locked_reason }}</p>
            </article>
        </div>
    </section>
</template>
