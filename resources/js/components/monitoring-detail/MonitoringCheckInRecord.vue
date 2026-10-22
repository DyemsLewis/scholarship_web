<script setup>
import { computed, ref, watch } from 'vue';
import FilePreviewModal from '../FilePreviewModal.vue';
import MonitoringCaseHistoryModal from '../MonitoringCaseHistoryModal.vue';
import { showPortalToast } from '../../support/portalToast';
import MonitoringAdjustmentModal from './MonitoringAdjustmentModal.vue';

const props = defineProps({
    record: { type: Object, required: true },
    applicationId: { type: [Number, String], required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['refresh']);
const fileInput = ref(null);
const activeUploadRequirement = ref(null);
const expandedRequirement = ref(null);
const acceptedTerms = ref({});
const manualForms = ref({});
const uploadingKey = ref(null);
const savingGradeKey = ref(null);
const previewFile = ref(null);
const historyOpen = ref(false);
const adjustmentRequirement = ref(null);

const completedCount = computed(() => props.record.requirements.filter(
    (requirement) => ['completed', 'provider_recorded'].includes(requirement.status),
).length);

watch(() => props.record.record_key, () => {
    const firstAction = props.record.requirements.find(
        (requirement) => requirement.can_submit || requirement.can_request_adjustment || requirement.correction_requested,
    );
    expandedRequirement.value = requirementKey(firstAction ?? props.record.requirements[0], 0);
}, { immediate: true });

function requirementKey(requirement, index = 0) {
    return requirement?.id ? `requirement-${requirement.id}` : `${props.record.record_key}-${index}`;
}

function stateClass(state) {
    if (state === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (state === 'action_required') return 'bg-amber-100 text-amber-900';
    if (state === 'under_review') return 'bg-sky-100 text-sky-800';
    if (state === 'cancelled') return 'bg-slate-100 text-slate-500';
    return 'bg-slate-100 text-slate-700';
}

function requirementStatusClass(status) {
    if (['completed', 'provider_recorded'].includes(status)) return 'bg-emerald-100 text-emerald-800';
    if (['submitted'].includes(status)) return 'bg-sky-100 text-sky-800';
    if (['not_met', 'action_needed'].includes(status)) return 'bg-rose-100 text-rose-800';
    if (['open', 'request_pending', 'extension_approved', 'grace_period'].includes(status)) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-600';
}

function adjustmentStatusClass(status) {
    if (status === 'approved') return 'bg-emerald-100 text-emerald-800';
    if (status === 'declined') return 'bg-rose-100 text-rose-800';
    return 'bg-amber-100 text-amber-900';
}

function toggleRequirement(requirement, index) {
    const key = requirementKey(requirement, index);
    expandedRequirement.value = expandedRequirement.value === key ? null : key;
}

function openFilePicker(requirement, index) {
    const key = requirementKey(requirement, index);
    if (!requirement.submission && !acceptedTerms.value[key]) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that this record belongs to you and matches the requirement.',
        });
        return;
    }

    activeUploadRequirement.value = { requirement, index, key };
    fileInput.value.value = '';
    fileInput.value.click();
}

async function uploadRecord(event) {
    const file = event.target.files?.[0];
    const target = activeUploadRequirement.value;
    if (!file || !target) return;

    uploadingKey.value = target.key;
    const data = new FormData();
    const isLegacy = props.record.record_type === 'academic_record';
    data.append(isLegacy ? 'grade_record' : 'supporting_record', file);
    data.append('terms_accepted', '1');
    const endpoint = isLegacy
        ? `/dashboard/applications/${props.applicationId}/monitoring-cycles/${props.record.id}/grade-record`
        : `/dashboard/applications/${props.applicationId}/monitoring-requirements/${target.requirement.id}/submission`;

    try {
        const response = await window.axios.post(endpoint, data);
        showPortalToast({ type: 'success', title: 'Record uploaded', message: response.data.message });
        emit('refresh');
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
        uploadingKey.value = null;
        activeUploadRequirement.value = null;
    }
}

function manualForm(requirement, index) {
    const key = requirementKey(requirement, index);
    if (!manualForms.value[key]) {
        manualForms.value[key] = {
            grade: requirement.submission?.grade ?? '',
            grading_scale: requirement.submission?.grading_scale ?? requirement.grading_scale ?? 'percentage',
        };
    }
    return manualForms.value[key];
}

async function saveManualGrade(requirement, index) {
    const key = requirementKey(requirement, index);
    const form = manualForm(requirement, index);
    if (form.grade === '' || form.grade === null) {
        showPortalToast({ type: 'error', title: 'Result required', message: 'Enter the result shown on the uploaded record.' });
        return;
    }

    savingGradeKey.value = key;
    const isLegacy = props.record.record_type === 'academic_record';
    const endpoint = isLegacy
        ? `/dashboard/applications/${props.applicationId}/monitoring-cycles/${props.record.id}/manual-grade`
        : `/dashboard/applications/${props.applicationId}/monitoring-requirements/${requirement.id}/manual-grade`;

    try {
        const response = await window.axios.patch(endpoint, form);
        showPortalToast({ type: 'success', title: 'Result saved', message: response.data.message });
        emit('refresh');
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
        savingGradeKey.value = null;
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
    <MonitoringCaseHistoryModal
        :open="historyOpen"
        :monitoring-case="record.monitoring_case"
        :subject="record.title"
        @close="historyOpen = false"
    />
    <MonitoringAdjustmentModal
        :open="Boolean(adjustmentRequirement)"
        :application-id="applicationId"
        :record="record"
        :requirement="adjustmentRequirement"
        @close="adjustmentRequirement = null"
        @saved="emit('refresh')"
    />
    <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadRecord">

    <div data-monitoring-check-in-record class="mt-4 space-y-4">
        <a :href="record.return_href" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
            All check-ins
        </a>

        <article class="overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm">
            <header class="px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">{{ record.record_type_label }}</p>
                            <span :class="['px-2 py-1 text-[10px] font-bold uppercase', stateClass(record.state)]">{{ record.state_label }}</span>
                        </div>
                        <h3 class="mt-2 font-display text-xl font-bold text-slate-950 sm:text-2xl">{{ record.title }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ record.period_label || 'Monitoring period' }}</p>
                    </div>
                    <button
                        v-if="record.monitoring_case?.history?.length || record.monitoring_case?.exception_context"
                        type="button"
                        class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50"
                        @click="historyOpen = true"
                    >
                        <i class="fa-solid fa-clock-rotate-left text-xs" aria-hidden="true"></i>
                        Case history
                    </button>
                </div>
                <p v-if="record.instructions" class="mt-4 max-w-4xl text-sm leading-6 text-slate-600">{{ record.instructions }}</p>
            </header>

            <dl class="grid border-t border-slate-200 bg-slate-50 sm:grid-cols-3">
                <div class="px-5 py-3">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Deadline</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ record.effective_due_label || record.due_label || 'Not scheduled' }}</dd>
                </div>
                <div class="border-t border-slate-200 px-5 py-3 sm:border-l sm:border-t-0">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Requirements</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ record.requirements.length }} listed</dd>
                </div>
                <div class="border-t border-slate-200 px-5 py-3 sm:border-l sm:border-t-0">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Progress</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ completedCount }} of {{ record.requirements.length }} complete</dd>
                </div>
            </dl>
        </article>

        <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Submission checklist</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Complete one item at a time</h3>
                <p class="mt-1 text-sm text-slate-500">Open an item to view its record, feedback, or available action.</p>
            </header>

            <div class="divide-y divide-slate-200">
                <article v-for="(requirement, index) in record.requirements" :key="requirementKey(requirement, index)">
                    <button type="button" class="flex w-full items-start justify-between gap-4 px-5 py-4 text-left hover:bg-slate-50 sm:px-6" @click="toggleRequirement(requirement, index)">
                        <span class="flex min-w-0 items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-700">
                                <i :class="requirement.icon || 'fa-solid fa-file-lines'" class="text-sm" aria-hidden="true"></i>
                            </span>
                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-bold text-slate-950">{{ requirement.title }}</span>
                                    <span v-if="!requirement.required" class="bg-slate-100 px-2 py-0.5 text-[9px] font-bold uppercase text-slate-500">Optional</span>
                                </span>
                                <span class="mt-1 block text-xs text-slate-500">{{ requirement.requirement_label || requirement.type_label || 'Supporting record' }}</span>
                            </span>
                        </span>
                        <span class="flex shrink-0 items-center gap-3">
                            <span :class="['px-2 py-1 text-[10px] font-bold uppercase', requirementStatusClass(requirement.status)]">{{ requirement.status_label }}</span>
                            <i :class="['fa-solid fa-chevron-down text-xs text-slate-400 transition', expandedRequirement === requirementKey(requirement, index) ? 'rotate-180' : '']" aria-hidden="true"></i>
                        </span>
                    </button>

                    <div v-if="expandedRequirement === requirementKey(requirement, index)" class="border-t border-slate-200 bg-slate-50 px-5 py-5 sm:px-6">
                        <p v-if="requirement.evidence_description || requirement.description" class="max-w-3xl text-sm leading-6 text-slate-600">{{ requirement.evidence_description || requirement.description }}</p>

                        <div v-if="requirement.adjustment_request" class="mt-4 border-l-4 border-amber-400 bg-amber-50 px-4 py-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-bold text-slate-950">{{ requirement.adjustment_request.request_type_label }}</p>
                                <span :class="['px-2 py-1 text-[10px] font-bold uppercase', adjustmentStatusClass(requirement.adjustment_request.status)]">{{ requirement.adjustment_request.status_label }}</span>
                            </div>
                            <p v-if="requirement.adjustment_request.approved_due_label" class="mt-1 text-xs font-bold text-emerald-800">New deadline: {{ requirement.adjustment_request.approved_due_label }}</p>
                            <p v-if="requirement.adjustment_request.decision_notes" class="mt-1 text-xs leading-5 text-slate-600">Provider note: {{ requirement.adjustment_request.decision_notes }}</p>
                            <button v-if="requirement.adjustment_request.attachment" type="button" class="mt-2 text-xs font-bold text-slate-700 underline underline-offset-4" @click="previewFile = requirement.adjustment_request.attachment">View supporting record</button>
                        </div>

                        <div v-if="requirement.interventions?.length" class="mt-4 border-l-4 border-sky-400 bg-sky-50 px-4 py-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-bold text-slate-950">Provider follow-up: {{ requirement.interventions[0].type_label }}</p>
                                <span class="bg-sky-100 px-2 py-1 text-[10px] font-bold uppercase text-sky-800">{{ requirement.interventions[0].status_label }}</span>
                            </div>
                            <p class="mt-1 text-xs leading-5 text-slate-600">{{ requirement.interventions[0].summary }}</p>
                            <p v-if="requirement.interventions[0].action_required" class="mt-1 text-xs font-bold text-slate-700">Next: {{ requirement.interventions[0].action_required }}</p>
                        </div>

                        <div v-if="requirement.submission" class="mt-4 border border-slate-200 bg-white p-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-950">{{ requirement.submission.has_file ? requirement.submission.original_name : requirement.submission.review_status_label }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ requirement.submission.has_file ? 'Submitted' : 'Recorded by provider' }} {{ requirement.submission.submitted_at }}</p>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    <button v-if="requirement.submission.has_file" type="button" class="border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="previewFile = requirement.submission">View file</button>
                                    <button v-if="requirement.can_submit" type="button" :disabled="uploadingKey === requirementKey(requirement, index)" class="bg-slate-950 px-3 py-2 text-xs font-bold text-white disabled:opacity-60" @click="openFilePicker(requirement, index)">{{ uploadingKey === requirementKey(requirement, index) ? 'Uploading...' : 'Replace file' }}</button>
                                </div>
                            </div>
                            <p v-if="requirement.submission.grade_label" class="mt-3 text-xs font-bold text-slate-700">Recorded result: {{ requirement.submission.grade_label }}</p>
                            <p v-if="requirement.submission.review_notes" class="mt-2 border-t border-slate-200 pt-2 text-xs leading-5 text-slate-600"><strong>Provider note:</strong> {{ requirement.submission.review_notes }}</p>
                        </div>

                        <div v-else-if="requirement.can_submit" class="mt-4 border border-slate-200 bg-white p-4">
                            <label class="flex items-start gap-3 text-sm leading-6 text-slate-700">
                                <input v-model="acceptedTerms[requirementKey(requirement, index)]" type="checkbox" class="mt-1 h-4 w-4 border-slate-300 text-slate-950">
                                <span>I confirm this record belongs to me and matches this requirement.</span>
                            </label>
                            <button type="button" :disabled="uploadingKey === requirementKey(requirement, index)" class="mt-3 inline-flex min-h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-60" @click="openFilePicker(requirement, index)">
                                <i class="fa-solid fa-arrow-up-from-bracket text-xs" aria-hidden="true"></i>
                                {{ uploadingKey === requirementKey(requirement, index) ? 'Uploading...' : 'Upload record' }}
                            </button>
                        </div>

                        <p v-else-if="!requirement.submission && requirement.locked_reason" class="mt-4 border-l-4 border-slate-300 bg-white px-4 py-3 text-sm text-slate-600">{{ requirement.locked_reason }}</p>

                        <form v-if="requirement.submission?.manual_entry_allowed && requirement.can_submit" class="mt-4 border border-amber-200 bg-amber-50 p-4" @submit.prevent="saveManualGrade(requirement, index)">
                            <p class="text-sm font-bold text-slate-950">Enter the result shown on the record</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Scale</span><select v-model="manualForm(requirement, index).grading_scale" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="percentage">Percentage</option><option value="grade_point">GWA / GPA</option></select></label>
                                <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Result</span><input v-model="manualForm(requirement, index).grade" type="number" step="0.01" required class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm"></label>
                                <button type="submit" :disabled="savingGradeKey === requirementKey(requirement, index)" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ savingGradeKey === requirementKey(requirement, index) ? 'Saving...' : 'Save result' }}</button>
                            </div>
                        </form>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <button v-if="requirement.can_request_adjustment" type="button" class="border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="adjustmentRequirement = requirement">Request more time or an exception</button>
                            <p v-if="requirement.requires_original_verification" class="text-xs font-semibold text-amber-800"><i class="fa-solid fa-circle-info mr-1" aria-hidden="true"></i>Keep the original for provider verification.</p>
                        </div>
                    </div>
                </article>
            </div>
        </section>
    </div>
</template>
