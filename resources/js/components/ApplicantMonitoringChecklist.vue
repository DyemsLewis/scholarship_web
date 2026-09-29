<script setup>
import { ref } from 'vue';
import FilePreviewModal from './FilePreviewModal.vue';
import { showPortalToast } from '../support/portalToast';

const props = defineProps({
    checkIns: { type: Array, default: () => [] },
    applicationId: { type: [Number, String], required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['application-updated']);
const fileInput = ref(null);
const activeRequirement = ref(null);
const acceptedTerms = ref({});
const uploadingRequirementId = ref(null);
const savingGradeId = ref(null);
const manualForms = ref({});
const previewFile = ref(null);
const adjustmentTarget = ref(null);
const adjustmentForm = ref(defaultAdjustmentForm());
const isRequestingAdjustment = ref(false);

function defaultAdjustmentForm() {
    return {
        request_type: 'extension',
        reason_category: 'illness',
        explanation: '',
        requested_due_at: '',
        supporting_record: null,
        confirmation: false,
    };
}

function statusClass(status) {
    if (status === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (status === 'submitted') return 'bg-sky-100 text-sky-800';
    if (status === 'action_needed') return 'bg-amber-100 text-amber-900';
    if (status === 'not_met') return 'bg-rose-100 text-rose-800';
    if (status === 'request_pending') return 'bg-amber-100 text-amber-900';
    if (status === 'extension_approved') return 'bg-emerald-100 text-emerald-800';
    if (status === 'provider_recorded') return 'bg-slate-100 text-slate-600';
    if (status === 'upcoming') return 'bg-sky-50 text-sky-700';
    if (status === 'closed') return 'bg-slate-100 text-slate-500';
    return 'bg-amber-100 text-amber-900';
}

function adjustmentStatusClass(status) {
    if (status === 'approved') return 'bg-emerald-100 text-emerald-800';
    if (status === 'declined') return 'bg-rose-100 text-rose-800';
    return 'bg-amber-100 text-amber-900';
}

function dateOffset(date, days) {
    if (!date) return '';
    const value = new Date(`${date}T00:00:00`);
    value.setDate(value.getDate() + days);
    return value.toISOString().slice(0, 10);
}

function openAdjustment(checkIn, requirement) {
    adjustmentTarget.value = { checkIn, requirement };
    adjustmentForm.value = defaultAdjustmentForm();
    if (!requirement.requires_file) adjustmentForm.value.request_type = 'exception';
}

function closeAdjustment() {
    if (isRequestingAdjustment.value) return;
    adjustmentTarget.value = null;
    adjustmentForm.value = defaultAdjustmentForm();
}

function selectAdjustmentFile(event) {
    adjustmentForm.value.supporting_record = event.target.files?.[0] ?? null;
}

async function submitAdjustment() {
    if (!adjustmentTarget.value || isRequestingAdjustment.value) return;
    if (!adjustmentForm.value.confirmation) {
        showPortalToast({ type: 'error', title: 'Confirmation required', message: 'Confirm that the request details are accurate.' });
        return;
    }

    isRequestingAdjustment.value = true;
    const data = new FormData();
    data.append('request_type', adjustmentForm.value.request_type);
    data.append('reason_category', adjustmentForm.value.reason_category);
    data.append('explanation', adjustmentForm.value.explanation);
    if (adjustmentForm.value.request_type === 'extension') {
        data.append('requested_due_at', adjustmentForm.value.requested_due_at);
    }
    if (adjustmentForm.value.supporting_record) {
        data.append('supporting_record', adjustmentForm.value.supporting_record);
    }
    data.append('confirmation', '1');

    try {
        const requirement = adjustmentTarget.value.requirement;
        const response = await window.axios.post(
            `/dashboard/applications/${props.applicationId}/monitoring-requirements/${requirement.id}/adjustment-request`,
            data,
        );
        emit('application-updated', response.data.application);
        adjustmentTarget.value = null;
        adjustmentForm.value = defaultAdjustmentForm();
        showPortalToast({ type: 'success', title: 'Request sent', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Request not sent',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to send this request.'),
        });
    } finally {
        isRequestingAdjustment.value = false;
    }
}

function progressWidth(checkIn) {
    if (!checkIn.required_count) return 100;
    return Math.min(100, Math.round((checkIn.submitted_count / checkIn.required_count) * 100));
}

function openFilePicker(requirement) {
    if (!requirement.submission && !acceptedTerms.value[requirement.id]) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that the record belongs to you and matches this requirement.',
        });
        return;
    }

    activeRequirement.value = requirement;
    fileInput.value.value = '';
    fileInput.value.click();
}

async function uploadRequirement(event) {
    const file = event.target.files?.[0];
    const requirement = activeRequirement.value;
    if (!file || !requirement) return;

    uploadingRequirementId.value = requirement.id;
    const data = new FormData();
    data.append('supporting_record', file);
    data.append('terms_accepted', '1');

    try {
        const response = await window.axios.post(
            `/dashboard/applications/${props.applicationId}/monitoring-requirements/${requirement.id}/submission`,
            data,
        );
        emit('application-updated', response.data.application);
        showPortalToast({ type: 'success', title: 'Record uploaded', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Upload not completed',
            message: errors
                ? Object.values(errors).flat()[0]
                : (error.response?.data?.message ?? 'Unable to upload this record.'),
        });
    } finally {
        uploadingRequirementId.value = null;
        activeRequirement.value = null;
    }
}

function manualForm(requirement) {
    if (!manualForms.value[requirement.id]) {
        manualForms.value[requirement.id] = {
            grade: requirement.submission?.grade ?? '',
            grading_scale: requirement.submission?.grading_scale ?? requirement.grading_scale ?? 'percentage',
        };
    }

    return manualForms.value[requirement.id];
}

async function saveManualGrade(requirement) {
    const form = manualForm(requirement);
    if (form.grade === '' || form.grade === null) {
        showPortalToast({ type: 'error', title: 'Result required', message: 'Enter the result shown on the uploaded record.' });
        return;
    }

    savingGradeId.value = requirement.id;
    try {
        const response = await window.axios.patch(
            `/dashboard/applications/${props.applicationId}/monitoring-requirements/${requirement.id}/manual-grade`,
            form,
        );
        emit('application-updated', response.data.application);
        showPortalToast({ type: 'success', title: 'Result saved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Result not saved',
            message: errors
                ? Object.values(errors).flat()[0]
                : (error.response?.data?.message ?? 'Unable to save this result.'),
        });
    } finally {
        savingGradeId.value = null;
    }
}
</script>

<template>
    <FilePreviewModal
        :file="previewFile"
        :title="previewFile?.original_name || 'Monitoring record'"
        :context="programTitle"
        @close="previewFile = null"
    />

    <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadRequirement">

    <div class="space-y-4">
        <article v-for="checkIn in checkIns" :key="checkIn.id" class="overflow-hidden rounded-md border border-slate-200 bg-white">
            <header class="border-b border-slate-200 px-4 py-4 sm:px-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Current check-in</p>
                        <h4 class="mt-1 font-bold text-slate-950">{{ checkIn.title }}</h4>
                        <p class="mt-1 text-xs text-slate-500">{{ checkIn.period_label }} | Due {{ checkIn.due_label }}</p>
                    </div>
                    <p class="text-xs font-bold text-slate-700">{{ checkIn.submitted_count }} of {{ checkIn.required_count }} submitted</p>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-amber-400" :style="{ width: `${progressWidth(checkIn)}%` }"></div>
                </div>
                <p v-if="checkIn.instructions" class="mt-3 text-sm leading-6 text-slate-600">{{ checkIn.instructions }}</p>
            </header>

            <div class="divide-y divide-slate-200">
                <section v-for="requirement in checkIn.requirements" :key="requirement.id" class="px-4 py-4 sm:px-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                <i :class="requirement.icon" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h5 class="font-bold text-slate-950">{{ requirement.title }}</h5>
                                    <span v-if="!requirement.required" class="rounded bg-slate-100 px-2 py-0.5 text-[9px] font-bold uppercase text-slate-500">Optional</span>
                                </div>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ requirement.evidence_description || requirement.description }}</p>
                                <p v-if="requirement.requirement_label" class="mt-1 text-xs font-bold text-slate-700">{{ requirement.requirement_label }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <span :class="['w-fit rounded px-2 py-1 text-[10px] font-bold uppercase', statusClass(requirement.status)]">{{ requirement.status_label }}</span>
                            <button v-if="requirement.can_request_adjustment" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openAdjustment(checkIn, requirement)">Request help</button>
                        </div>
                    </div>

                    <div v-if="requirement.adjustment_request" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-950">{{ requirement.adjustment_request.request_type_label }}</p>
                            <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', adjustmentStatusClass(requirement.adjustment_request.status)]">{{ requirement.adjustment_request.status_label }}</span>
                        </div>
                        <p v-if="requirement.adjustment_request.approved_due_label" class="mt-1 text-xs font-bold text-emerald-800">New deadline: {{ requirement.adjustment_request.approved_due_label }}</p>
                        <p v-if="requirement.adjustment_request.decision_notes" class="mt-1 text-xs leading-5 text-slate-600">Provider note: {{ requirement.adjustment_request.decision_notes }}</p>
                        <button v-if="requirement.adjustment_request.attachment" type="button" class="mt-2 text-xs font-bold text-slate-700 underline" @click="previewFile = requirement.adjustment_request.attachment">View supporting record</button>
                    </div>

                    <div v-if="requirement.interventions?.length" class="mt-3 rounded-md border border-sky-200 bg-sky-50 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-950">Provider follow-up: {{ requirement.interventions[0].type_label }}</p>
                            <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', requirement.interventions[0].status === 'completed' ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800']">{{ requirement.interventions[0].status_label }}</span>
                        </div>
                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ requirement.interventions[0].summary }}</p>
                        <p v-if="requirement.interventions[0].action_required" class="mt-1 text-xs font-bold leading-5 text-slate-700">Next action: {{ requirement.interventions[0].action_required }}</p>
                        <p v-if="requirement.interventions[0].follow_up_label" class="mt-1 text-xs text-slate-500">Follow up by {{ requirement.interventions[0].follow_up_label }}</p>
                    </div>

                    <div v-if="requirement.submission" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-950">{{ requirement.submission.has_file ? requirement.submission.original_name : requirement.submission.review_status_label }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ requirement.submission.has_file ? 'Submitted' : 'Recorded by provider' }} {{ requirement.submission.submitted_at }}</p>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2">
                                <button v-if="requirement.submission.has_file" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="previewFile = requirement.submission">View</button>
                                <button v-if="requirement.can_submit" type="button" :disabled="uploadingRequirementId === requirement.id" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white disabled:opacity-60" @click="openFilePicker(requirement)">{{ uploadingRequirementId === requirement.id ? 'Uploading...' : 'Replace' }}</button>
                            </div>
                        </div>
                        <p v-if="requirement.submission.grade_label" class="mt-2 text-xs font-bold text-slate-700">Detected result: {{ requirement.submission.grade_label }}</p>
                        <p v-if="requirement.submission.review_notes" class="mt-2 text-xs leading-5 text-slate-600">Provider note: {{ requirement.submission.review_notes }}</p>
                    </div>

                    <div v-else-if="requirement.can_submit" class="mt-3">
                        <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                            <input v-model="acceptedTerms[requirement.id]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-950">
                            <span>I confirm this record belongs to me and matches this requirement.</span>
                        </label>
                        <button type="button" :disabled="uploadingRequirementId === requirement.id" class="mt-3 inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60" @click="openFilePicker(requirement)">
                            <i class="fa-solid fa-arrow-up-from-bracket text-xs" aria-hidden="true"></i>
                            {{ uploadingRequirementId === requirement.id ? 'Uploading...' : 'Upload record' }}
                        </button>
                    </div>

                    <p v-else-if="!requirement.submission && requirement.locked_reason" class="mt-3 text-xs font-semibold text-slate-500">{{ requirement.locked_reason }}</p>

                    <form v-if="requirement.submission?.manual_entry_allowed && requirement.can_submit" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3" @submit.prevent="saveManualGrade(requirement)">
                        <p class="text-sm font-bold text-slate-950">Enter the result shown on the record</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                            <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Scale</span><select v-model="manualForm(requirement).grading_scale" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="percentage">Percentage</option><option value="grade_point">GWA / GPA</option></select></label>
                            <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Result</span><input v-model="manualForm(requirement).grade" type="number" step="0.01" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"></label>
                            <button type="submit" :disabled="savingGradeId === requirement.id" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ savingGradeId === requirement.id ? 'Saving...' : 'Save' }}</button>
                        </div>
                    </form>

                    <p v-if="requirement.requires_original_verification" class="mt-3 text-xs font-semibold text-amber-800"><i class="fa-solid fa-circle-info mr-1" aria-hidden="true"></i>Keep the original for in-person verification.</p>
                </section>
            </div>
        </article>
    </div>

    <Teleport to="body">
        <div v-if="adjustmentTarget" class="fixed inset-0 z-[2100] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeAdjustment" @keydown.esc="closeAdjustment">
            <form class="flex max-h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" @submit.prevent="submitAdjustment">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-5">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i></span>
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Monitoring request</p><h2 class="mt-1 text-xl font-bold">Request an adjustment</h2><p class="mt-1 text-sm text-slate-500">{{ adjustmentTarget.requirement.title }}</p></div>
                    </div>
                    <button type="button" :disabled="isRequestingAdjustment" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500" aria-label="Close request" @click="closeAdjustment"><i class="fa-solid fa-xmark"></i></button>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-4 sm:p-5">
                    <fieldset><legend class="text-xs font-bold text-slate-700">What do you need?</legend><div :class="['mt-2 grid gap-2', adjustmentTarget.requirement.requires_file ? 'sm:grid-cols-2' : 'grid-cols-1']">
                        <label v-if="adjustmentTarget.requirement.requires_file" :class="['cursor-pointer rounded-md border bg-white p-3', adjustmentForm.request_type === 'extension' ? 'border-slate-950 ring-1 ring-slate-950' : 'border-slate-200']"><input v-model="adjustmentForm.request_type" type="radio" value="extension" class="sr-only"><span class="block text-sm font-bold">More time</span><span class="mt-1 block text-xs text-slate-500">Ask for a personal deadline.</span></label>
                        <label :class="['cursor-pointer rounded-md border bg-white p-3', adjustmentForm.request_type === 'exception' ? 'border-slate-950 ring-1 ring-slate-950' : 'border-slate-200']"><input v-model="adjustmentForm.request_type" type="radio" value="exception" class="sr-only"><span class="block text-sm font-bold">Exception</span><span class="mt-1 block text-xs text-slate-500">Ask the provider to excuse this item.</span></label>
                    </div></fieldset>

                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Reason</span><select v-model="adjustmentForm.reason_category" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950"><option value="illness">Illness or health</option><option value="family_emergency">Family emergency</option><option value="school_schedule">School schedule or record delay</option><option value="transfer">School transfer</option><option value="technical_issue">Technical issue</option><option value="other">Other circumstance</option></select></label>
                    <label v-if="adjustmentForm.request_type === 'extension'" class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Requested deadline</span><input v-model="adjustmentForm.requested_due_at" type="date" required :min="dateOffset(adjustmentTarget.checkIn.due_at, 1)" :max="dateOffset(adjustmentTarget.checkIn.due_at, 60)" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950"></label>
                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Explain the circumstance</span><textarea v-model="adjustmentForm.explanation" required minlength="10" maxlength="2000" rows="4" placeholder="Explain what happened and how it affects this requirement." class="w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 text-slate-950 placeholder:text-slate-400"></textarea></label>
                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Supporting record <span class="font-normal text-slate-400">(optional)</span></span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-bold" @change="selectAdjustmentFile"></label>
                    <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-white p-3 text-sm leading-6 text-slate-700"><input v-model="adjustmentForm.confirmation" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span>I confirm that this request is accurate and understand that the provider will decide it.</span></label>
                </div>

                <footer class="flex justify-end gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:px-5"><button type="button" :disabled="isRequestingAdjustment" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeAdjustment">Cancel</button><button type="submit" :disabled="isRequestingAdjustment" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isRequestingAdjustment ? 'Sending...' : 'Send request' }}</button></footer>
            </form>
        </div>
    </Teleport>
</template>
