<script setup>
import { confirmOutcome } from '../../support/outcomeConfirmation.js';
import { computed, ref, watch } from 'vue';
import FilePreviewModal from '../FilePreviewModal.vue';
import MonitoringCaseHistoryModal from '../MonitoringCaseHistoryModal.vue';
import LocationMapLink from '../LocationMapLink.vue';
import { showPortalToast } from '../../support/portalToast';
import MonitoringAdjustmentModal from './MonitoringAdjustmentModal.vue';
import { checkInStatusClass } from '../../support/checkInStatus';

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

const completedCount = computed(() => props.record.requirements.filter(requirement => requirement.status === 'completed').length);
const isPast = computed(() => props.record.monitoring_case?.is_final || props.record.state === 'closed');
const canUpload = computed(() => !props.record.handoff && !isPast.value);
const dateLabel = computed(() => {
    const handoff = props.record.handoff;
    if (!handoff) return 'Deadline';
    if (handoff.started || isPast.value) return 'Appointment date';
    return handoff.rescheduled ? 'New date and time' : 'Date and time';
});
const groups = computed(() => {
    const items = props.record.requirements.map((requirement, index) => ({ requirement, index }));
    if (!props.record.preparation) {
        return [{
            key: 'checklist',
            title: isPast.value ? 'Checklist record' : 'Check-in requirements',
            description: isPast.value ? 'See which items were verified and which were left unresolved.'
                : props.record.handoff ? 'Open an item to see what the provider recorded.' : 'Open an item to view its record, feedback, or available action.',
            items,
        }];
    }

    const documents = new Set(props.record.preparation.document_ids);
    return [
        {
            key: 'documents',
            title: 'What to bring',
            description: documents.size ? 'Bring the original documents. No online upload is needed.' : 'No documents are listed for this visit.',
            items: items.filter(({ requirement }) => documents.has(requirement.id)),
        },
        {
            key: 'provider',
            title: 'Recorded by the provider',
            description: 'The provider updates these items during or after the visit.',
            items: items.filter(({ requirement }) => !documents.has(requirement.id)),
        },
    ].filter(group => group.key === 'documents' || group.items.length);
});

watch(() => props.record.record_key, () => {
    const index = props.record.requirements.findIndex(
        (requirement) => requirement.can_submit || requirement.can_request_adjustment || requirement.correction_requested,
    );
    expandedRequirement.value = canUpload.value && index >= 0 ? requirementKey(props.record.requirements[index], index) : null;
}, { immediate: true });

function requirementKey(requirement, index = 0) {
    return requirement?.id ? `requirement-${requirement.id}` : `${props.record.record_key}-${index}`;
}

function requirementStatusClass(status) {
    if (status === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (['submitted'].includes(status)) return 'bg-sky-100 text-sky-800';
    if (['not_met', 'action_needed'].includes(status)) return 'bg-rose-100 text-rose-800';
    if (['open', 'request_pending', 'extension_approved', 'grace_period'].includes(status)) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-600';
}

function requirementHint(requirement, group) {
    const hint = group === 'documents'
        ? (requirement.evidence_description || requirement.description || requirement.requirement_label)
        : (requirement.requirement_label || requirement.type_label);
    return hint?.trim().toLowerCase() !== requirement.title?.trim().toLowerCase() ? hint : null;
}

function showRequirementStatus(requirement, group) {
    return !(group === 'documents' && requirement.status === 'bring_document')
        && !(group === 'provider' && requirement.status === 'awaiting_provider');
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
    if (!canUpload.value || !requirement.can_submit || !fileInput.value) return;
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
    if (!file || !target || !canUpload.value || !target.requirement.can_submit) return;

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
    if (!canUpload.value || !requirement.can_submit || !requirement.submission?.manual_entry_allowed) return;
    const key = requirementKey(requirement, index);
    const form = manualForm(requirement, index);
    if (form.grade === '' || form.grade === null) {
        showPortalToast({ type: 'error', title: 'Result required', message: 'Enter the result shown on the uploaded record.' });
        return;
    }

    if (!await confirmOutcome({ record: requirement.title || props.record.title || 'Academic check-in', outcome: `Reported result: ${form.grade}`, consequence: 'This saves the result you entered from your academic record. The provider still needs to review it.' }, () => !savingGradeKey.value && form.grade !== '' && form.grade !== null)) return;
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
        :title="previewFile?.original_name || 'Check-in document'"
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
    <input v-if="canUpload && record.requirements.some(item => item.can_submit)" ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadRecord">

    <div data-monitoring-check-in-record class="mt-4 space-y-4">
        <a :href="record.return_href" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
            All check-ins
        </a>

        <article :class="['overflow-hidden rounded-md border bg-white shadow-sm', record.preparation ? 'border-amber-300' : 'border-slate-300']" aria-labelledby="check-in-record-title">
            <header :class="['flex flex-wrap items-start justify-between gap-3 border-l-4 px-5 py-5 sm:px-6', record.preparation ? 'border-amber-400' : 'border-slate-700']">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-amber-800">{{ record.handoff ? 'In-person check-in' : record.record_type_label }}</p>
                        <span :class="['border px-2 py-1 text-[10px] font-bold uppercase', checkInStatusClass(record.state_tone)]">{{ record.state_label }}</span>
                    </div>
                    <h2 id="check-in-record-title" class="mt-1.5 break-words font-display text-2xl font-bold leading-tight text-slate-950">{{ record.title }}</h2>
                    <p v-if="record.period_label" class="mt-1 text-sm text-slate-600">{{ record.period_label }}</p>
                </div>
                <button v-if="record.monitoring_case?.history?.length || record.monitoring_case?.exception_context" type="button" class="student-button student-button-secondary" @click="historyOpen = true">
                    <i class="fa-solid fa-clock-rotate-left text-xs" aria-hidden="true"></i> Case history
                </button>
            </header>
            <dl v-if="record.handoff || record.effective_due_label || record.due_label" data-check-in-appointment class="grid gap-px border-t border-slate-200 bg-slate-200 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="flex min-w-0 items-start gap-3 bg-slate-50 px-5 py-4 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-white text-slate-700 ring-1 ring-slate-200"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <dt class="student-detail-label">{{ dateLabel }}</dt>
                        <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ record.handoff?.scheduled_label || record.effective_due_label || record.due_label }}</dd>
                    </div>
                </div>
                <div v-if="record.handoff" class="flex min-w-0 items-start gap-3 bg-slate-50 px-5 py-4 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-white text-slate-700 ring-1 ring-slate-200"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <dt class="student-detail-label">Venue</dt>
                        <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ record.handoff.location }}</dd>
                        <LocationMapLink :address="record.handoff.location" :latitude="record.handoff.latitude" :longitude="record.handoff.longitude" :title="record.title || 'Check-in venue'" />
                    </div>
                </div>
                <div v-else class="flex min-w-0 items-start gap-3 bg-slate-50 px-5 py-4 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-white text-slate-700 ring-1 ring-slate-200"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
                    <div>
                        <dt class="student-detail-label">Checklist progress</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ completedCount }} of {{ record.requirements.length }} complete</dd>
                    </div>
                </div>
            </dl>
            <div v-if="!isPast && !record.handoff?.started && record.instructions" class="border-t border-slate-200 px-5 py-3.5 sm:px-6">
                <p class="text-xs font-bold text-slate-800">{{ record.handoff ? 'Arrival instructions' : 'Instructions' }}</p>
                <p class="mt-1 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ record.instructions }}</p>
            </div>
        </article>

        <div :class="['grid items-start gap-4', isPast || record.guidance ? 'xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]' : '']">
            <section v-if="isPast || record.guidance" data-check-in-next-step class="flex min-w-0 items-start gap-3 rounded-md border border-slate-300 bg-white px-5 py-4 shadow-sm xl:col-start-2 xl:row-start-1">
                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-sm border', checkInStatusClass(record.state_tone)]"><i :class="isPast ? 'fa-solid fa-list-check' : record.guidance.actor === 'applicant' ? 'fa-solid fa-arrow-right' : 'fa-regular fa-clock'" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-600">{{ isPast ? 'Recorded outcome' : 'Next step: ' + record.guidance.actor_label }}</p>
                    <h3 class="mt-1 text-base font-bold text-slate-950">{{ isPast ? record.state_label : record.guidance.title }}</h3>
                    <template v-if="record.preparation && record.guidance?.key === 'attend_handoff'">
                        <p v-if="record.handoff?.rescheduled" class="mt-1 text-sm font-semibold leading-6 text-amber-800">Use the new time and venue shown above.</p>
                        <p class="mt-1 text-sm leading-6 text-slate-600">If you cannot attend, contact the provider before your appointment.</p>
                    </template>
                    <template v-else>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ isPast ? (record.monitoring_case?.decision_reason || 'No further action is needed for this check-in.') : record.guidance.description }}</p>
                        <p v-if="!isPast && record.monitoring_case?.attendance_status === 'attended'" class="mt-2 text-xs font-semibold text-slate-600">Attendance recorded: {{ record.monitoring_case.attendance_label }}</p>
                    </template>
                </div>
            </section>

            <section class="min-w-0 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm xl:col-start-1 xl:row-start-1" aria-label="Check-in checklist">
                <div v-for="group in groups" :key="group.key" :data-check-in-preparation="group.key === 'documents' ? '' : null" class="border-b border-slate-200 last:border-b-0">
                    <header class="border-b border-slate-200 bg-slate-50/70 px-5 py-4 sm:px-6">
                        <h3 class="font-display text-lg font-bold text-slate-950">{{ group.title }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ group.description }}</p>
                    </header>
                    <div v-if="group.items.length" class="divide-y divide-slate-200">
                        <article v-for="{ requirement, index } in group.items" :key="requirementKey(requirement, index)">
                            <button type="button" class="grid w-full min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-5 py-4 text-left hover:bg-slate-50 sm:px-6" :aria-expanded="expandedRequirement === requirementKey(requirement, index)" @click="toggleRequirement(requirement, index)">
                                <span class="flex min-w-0 items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-700">
                                        <i :class="requirement.icon || 'fa-solid fa-file-lines'" class="text-sm" aria-hidden="true"></i>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="font-bold text-slate-950">{{ requirement.title }}</span>
                                            <span v-if="!requirement.required" class="bg-slate-100 px-2 py-0.5 text-[9px] font-bold uppercase text-slate-500">Optional</span>
                                        </span>
                                        <span v-if="requirementHint(requirement, group.key)" class="mt-1 block break-words text-xs leading-5 text-slate-600">{{ requirementHint(requirement, group.key) }}</span>
                                        <span v-if="showRequirementStatus(requirement, group.key)" :class="['mt-1 inline-block px-2 py-1 text-[10px] font-bold uppercase sm:hidden', requirementStatusClass(requirement.status)]">{{ requirement.status_label }}</span>
                                    </span>
                                </span>
                                <span class="flex min-w-0 flex-wrap items-center gap-2">
                                    <span v-if="showRequirementStatus(requirement, group.key)" :class="['hidden px-2 py-1 text-[10px] font-bold uppercase sm:inline-block', requirementStatusClass(requirement.status)]">{{ requirement.status_label }}</span>
                                    <i :class="['fa-solid fa-chevron-down text-xs text-slate-400 transition', expandedRequirement === requirementKey(requirement, index) ? 'rotate-180' : '']" aria-hidden="true"></i>
                                </span>
                            </button>

                            <div v-if="expandedRequirement === requirementKey(requirement, index)" class="border-t border-slate-200 bg-slate-50 px-5 py-5 sm:px-6">
                                <p v-if="group.key !== 'documents' && (requirement.evidence_description || requirement.description)" class="max-w-3xl text-sm leading-6 text-slate-600">{{ requirement.evidence_description || requirement.description }}</p>

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
                                            <button v-if="canUpload && requirement.can_submit" type="button" :disabled="uploadingKey === requirementKey(requirement, index)" class="bg-slate-950 px-3 py-2 text-xs font-bold text-white disabled:opacity-60" @click="openFilePicker(requirement, index)">{{ uploadingKey === requirementKey(requirement, index) ? 'Uploading...' : 'Replace file' }}</button>
                                        </div>
                                    </div>
                                    <p v-if="requirement.submission.grade_label" class="mt-3 text-xs font-bold text-slate-700">Recorded result: {{ requirement.submission.grade_label }}</p>
                                    <p v-if="requirement.submission.review_notes" class="mt-2 border-t border-slate-200 pt-2 text-xs leading-5 text-slate-600"><strong>Provider note:</strong> {{ requirement.submission.review_notes }}</p>
                                </div>

                                <div v-else-if="canUpload && requirement.can_submit" class="mt-4 border border-slate-200 bg-white p-4">
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

                                <form v-if="canUpload && requirement.submission?.manual_entry_allowed && requirement.can_submit" class="mt-4 border border-amber-200 bg-amber-50 p-4" @submit.prevent="saveManualGrade(requirement, index)">
                                    <p class="text-sm font-bold text-slate-950">Enter the result shown on the record</p>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                        <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Scale</span><select v-model="manualForm(requirement, index).grading_scale" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="percentage">Percentage</option><option value="grade_point">GWA / GPA</option></select></label>
                                        <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Result</span><input v-model="manualForm(requirement, index).grade" type="number" step="0.01" required class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm"></label>
                                        <button type="submit" :disabled="savingGradeKey === requirementKey(requirement, index)" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ savingGradeKey === requirementKey(requirement, index) ? 'Saving...' : 'Save result' }}</button>
                                    </div>
                                </form>

                                <div class="mt-4 flex flex-wrap items-center gap-3">
                                    <button v-if="!isPast && requirement.can_request_adjustment" type="button" class="border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="adjustmentRequirement = requirement">{{ record.handoff ? 'Request an exception' : 'Request more time or an exception' }}</button>
                                    <p v-if="!isPast && !record.handoff && requirement.requires_original_verification" class="text-xs font-semibold text-amber-800"><i class="fa-solid fa-circle-info mr-1" aria-hidden="true"></i>Keep the original for provider verification.</p>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
