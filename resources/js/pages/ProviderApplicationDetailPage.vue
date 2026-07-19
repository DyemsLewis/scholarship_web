<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantProfileProofModal from '../components/ApplicantProfileProofModal.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderDocumentReviewModal from '../components/ProviderDocumentReviewModal.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { decisionReasonOptions, negativeDecisionStatuses } from '../support/applicationDecisionReasons';
import { formatFileSize, labelFromKey as formatKeyLabel } from '../support/display';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const isLoading = ref(true);
const updatingId = ref(null);
const documentUpdatingId = ref(null);
const errorMessage = ref('');
const application = ref(null);
const activeSection = ref('review');
const showRubricDetails = ref(false);
const showDssDetails = ref(false);
const reviewForm = ref(emptyReviewForm());
const selectedDocument = ref(null);
const selectedProfileProof = ref(null);
const selectedReviewActionKey = ref('');
const documentReviewError = ref('');
const rubricScores = ref({});
const scheduleTrackingId = ref(null);
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const detailSections = [
    { key: 'review', label: 'Review' },
    { key: 'schedule', label: 'Schedule' },
    { key: 'documents', label: 'Documents' },
    { key: 'applicant', label: 'Applicant' },
    { key: 'history', label: 'History' },
];
const scheduleTypeCatalog = [
    { value: 'screening', label: 'Screening', icon: 'fa-solid fa-list-check' },
    { value: 'exam', label: 'Exam', icon: 'fa-solid fa-clipboard-question' },
    { value: 'interview', label: 'Interview', icon: 'fa-solid fa-comments' },
    { value: 'distribution', label: 'Distribution', icon: 'fa-solid fa-hand-holding-dollar' },
];
const scheduleModeOptions = [
    { value: 'onsite', label: 'On-site' },
    { value: 'online', label: 'Online' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'provider_managed', label: 'Provider managed' },
];
const reviewActionCatalog = {
    approve: {
        key: 'approve',
        status: 'approved',
        label: 'Approve application',
        description: 'Select the applicant for scholarship support.',
        confirmLabel: 'Approve application',
        reason: 'approved_for_award',
        note: 'Application approved after provider review.',
        icon: 'fa-solid fa-award',
    },
    reject: {
        key: 'reject',
        status: 'rejected',
        label: 'Reject application',
        description: 'End the application and provide a clear reason.',
        confirmLabel: 'Reject application',
        reason: '',
        note: 'Application was not selected after provider review.',
        icon: 'fa-solid fa-ban',
        tone: 'danger',
    },
};
const negativeDecisionReasonOptions = decisionReasonOptions.filter((option) => [
    '',
    'missing_documents',
    'academic_requirement_not_met',
    'outside_eligibility',
    'failed_exam',
    'funds_limited',
    'not_selected',
    'other',
].includes(option.value));
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100';
const labelClass = 'mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500';
const customStatusLabels = {
    exam_qualified: 'Qualified for exam',
    exam_scheduled: 'Exam scheduled',
    exam_taken: 'Exam taken',
    exam_passed: 'Passed exam',
    exam_failed: 'Failed exam',
    distribution_scheduled: 'Distribution scheduled',
    disbursed: 'Distributed',
    for_exam: 'Meets exam eligibility',
    exam_completed: 'Exam completed',
    passed_exam: 'Passed exam',
    failed_exam: 'Failed exam',
};

const dssCriteria = computed(() => application.value?.dss_breakdown?.criteria ?? []);
const rubricReview = computed(() => application.value?.rubric_review ?? { criteria: [], completed: 0, total_criteria: 0 });
const rubricDraftSummary = computed(() => {
    const criteria = rubricReview.value.criteria ?? [];
    let completed = 0;
    let weightedScore = 0;

    criteria.forEach((criterion) => {
        const rawScore = rubricScores.value[criterion.key];
        const score = Number(rawScore);

        if (rawScore === '' || rawScore === null || rawScore === undefined || !Number.isFinite(score) || score < 0 || score > 100) {
            return;
        }

        completed += 1;
        weightedScore += (score * Number(criterion.weight ?? 0)) / 100;
    });

    const total = criteria.length;
    const isComplete = total > 0 && completed === total;

    return {
        completed,
        total,
        isComplete,
        completionPercent: total > 0 ? Math.round((completed / total) * 100) : 0,
        totalScore: isComplete ? Math.round(weightedScore * 100) / 100 : null,
    };
});
const timeline = computed(() => application.value?.timeline ?? []);
const schedules = computed(() => application.value?.schedules ?? []);
const applicantProfileProofs = computed(() => application.value?.applicant?.profile_proofs ?? []);
const hasGuardianDetails = computed(() => {
    const applicant = application.value?.applicant;

    return Boolean(
        applicant?.guardian_name
        || applicant?.guardian_relationship
        || applicant?.guardian_contact
        || applicant?.guardian_email
        || applicant?.guardian_is_account_owner,
    );
});
const usesDetailSidebar = computed(() => activeSection.value === 'history');
const selectionStages = computed(() => application.value?.scholarship?.selection_stages ?? ['screening', 'distribution']);
const nextApprovalStatus = computed(() => {
    const currentStatus = application.value?.status;

    if (['submitted', 'under_review', 'qualified', 'shortlisted'].includes(currentStatus)) {
        if (selectionStages.value.includes('exam')) {
            return 'exam_qualified';
        }

        return selectionStages.value.includes('interview') ? 'interview' : 'approved';
    }

    if (['exam_taken', 'exam_passed'].includes(currentStatus)) {
        return selectionStages.value.includes('interview') ? 'interview' : 'approved';
    }

    return currentStatus === 'interview' ? 'approved' : null;
});
const nextRejectionStatus = computed(() => (
    ['exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed'].includes(application.value?.status)
        ? 'exam_failed'
        : (['submitted', 'under_review', 'qualified', 'shortlisted', 'interview'].includes(application.value?.status) ? 'rejected' : null)
));
const suggestedReviewActions = computed(() => {
    const actions = [];

    if (nextApprovalStatus.value) {
        const nextLabel = statusLabel(nextApprovalStatus.value);
        actions.push({
            ...reviewActionCatalog.approve,
            key: 'approve_next_stage',
            decision: 'approve',
            status: nextApprovalStatus.value,
            reason: {
                exam_qualified: 'for_exam',
                interview: 'for_interview',
                approved: 'approved_for_award',
            }[nextApprovalStatus.value] ?? '',
            note: nextApprovalStatus.value === 'approved'
                ? 'Application approved after eligibility, requirement, and provider review.'
                : `Applicant approved to proceed to ${nextLabel.toLowerCase()}.`,
            label: nextApprovalStatus.value === 'approved' ? 'Approve applicant' : `Approve for ${nextLabel.replace(/^Qualified for /, '')}`,
            description: nextApprovalStatus.value === 'approved'
                ? 'Complete the provider review and approve this application.'
                : `Move this applicant to the configured ${nextLabel.toLowerCase()} stage.`,
            confirmLabel: nextApprovalStatus.value === 'approved' ? 'Approve applicant' : `Approve for ${nextLabel.toLowerCase()}`,
        });
    }

    if (nextRejectionStatus.value) {
        actions.push({
            ...reviewActionCatalog.reject,
            key: 'reject_applicant',
            decision: 'reject',
            status: nextRejectionStatus.value,
        });
    }

    return actions;
});
const selectedReviewAction = computed(() => (
    suggestedReviewActions.value.find((action) => action.key === selectedReviewActionKey.value) ?? null
));
const reviewStatusChanged = computed(() => reviewForm.value.status !== application.value?.status);
const reviewSubmitLabel = computed(() => {
    if (updatingId.value === application.value?.id) {
        return 'Saving...';
    }

    if (selectedReviewAction.value) {
        return selectedReviewAction.value.confirmLabel;
    }

    if (reviewStatusChanged.value) {
        return `Save as ${statusLabel(reviewForm.value.status)}`;
    }

    return 'Save notes and scores';
});
const completedStageMessage = computed(() => ({
    exam_qualified: 'The applicant is waiting for the shared exam schedule. You may still reject the application if screening needs to be closed.',
    exam_scheduled: 'Track exam attendance in Schedule. Approve or reject after the exam is completed.',
    approved: 'The applicant is approved. Publish distribution details once from the program applicant page.',
    awarded: 'The award is confirmed. Publish distribution details once from the program applicant page.',
    distribution_scheduled: 'Distribution is scheduled. Use Schedule only to record this applicant\'s release result.',
    disbursed: 'The scholarship release is complete for this applicant.',
}[application.value?.status] ?? 'No applicant decision is needed at this stage. You can still save review notes and rubric scores.'));
const confirmedDocuments = computed(() => application.value?.document_checklist ?? []);
const requiredDocuments = computed(() => documentRequirements(application.value?.scholarship?.requirements));
const applicationRequirements = computed(() => {
    const checklist = confirmedDocuments.value
        .map((requirement) => String(requirement).trim())
        .filter(Boolean);

    return checklist.length ? checklist : requiredDocuments.value;
});
const applicationFileRows = computed(() => {
    const documents = application.value?.documents ?? [];
    const documentsByName = new Map(
        documents.map((document) => [normalizeDocumentName(document.document_name), document]),
    );
    const seenNames = new Set();
    const rows = [];

    applicationRequirements.value.forEach((requirement) => {
        const normalizedName = normalizeDocumentName(requirement);

        if (!normalizedName || seenNames.has(normalizedName)) {
            return;
        }

        seenNames.add(normalizedName);
        rows.push({
            name: requirement,
            document: documentsByName.get(normalizedName) ?? null,
            required: true,
        });
    });

    documents.forEach((document) => {
        const normalizedName = normalizeDocumentName(document.document_name);

        if (seenNames.has(normalizedName)) {
            return;
        }

        seenNames.add(normalizedName);
        rows.push({
            name: document.document_name,
            document,
            required: false,
        });
    });

    return rows;
});
const providerContractSections = computed(() => {
    const scholarship = application.value?.scholarship ?? {};

    return [
        { label: 'Return service contract', value: scholarship.return_service_contract },
        { label: 'Other contract terms', value: scholarship.other_contract_terms },
        { label: 'Renewal / continuation', value: scholarship.renewal_policy },
    ].filter((section) => section.value && String(section.value).trim());
});

function emptyReviewForm() {
    return {
        status: 'submitted',
        decisionReason: '',
        reviewNotes: '',
    };
}

function statusLabel(status) {
    if (customStatusLabels[status]) {
        return customStatusLabels[status];
    }

    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'exam_passed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded', 'exam_failed'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled'].includes(status)) {
        return 'bg-slate-100 text-slate-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function scheduleStatusClass(status) {
    if (status === 'completed') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'cancelled') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function scheduleTypeLabel(type) {
    return scheduleTypeCatalog.find((option) => option.value === type)?.label ?? labelFromKey(type);
}

function scheduleTypeIcon(type) {
    return scheduleTypeCatalog.find((option) => option.value === type)?.icon ?? 'fa-solid fa-calendar';
}

function scheduleModeLabel(mode) {
    return scheduleModeOptions.find((option) => option.value === mode)?.label ?? labelFromKey(mode);
}

function attendanceOptions(type) {
    if (type === 'distribution') {
        return [
            { value: 'pending', label: 'Pending release' },
            { value: 'received', label: 'Received' },
            { value: 'not_required', label: 'Not required' },
        ];
    }

    return [
        { value: 'pending', label: 'Pending attendance' },
        { value: 'attended', label: 'Attended' },
        { value: 'absent', label: 'Absent' },
        { value: 'excused', label: 'Excused' },
        { value: 'not_required', label: 'Not required' },
    ];
}

function handleScheduleStatusChange(schedule) {
    if (schedule.status === 'scheduled') {
        schedule.attendance_status = 'pending';
        return;
    }

    if (schedule.status === 'completed' && schedule.attendance_status === 'pending') {
        schedule.attendance_status = schedule.type === 'distribution' ? 'received' : 'attended';
        return;
    }

    if (schedule.status === 'cancelled' && ['attended', 'absent', 'received'].includes(schedule.attendance_status)) {
        schedule.attendance_status = 'pending';
    }
}

function formatAwardAmount(value) {
    if (value === null || value === undefined || value === '') {
        return 'Not listed';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(value));
}

async function saveScheduleTracking(schedule) {
    if (['completed', 'cancelled'].includes(schedule.status)) {
        const confirmed = await requestConfirmation({
            title: schedule.status === 'completed' ? 'Complete this activity?' : 'Cancel this schedule?',
            message: schedule.status === 'completed'
                ? 'The applicant will see the attendance or release result and the application may advance automatically.'
                : 'The applicant will be notified that this schedule was cancelled.',
            confirmLabel: schedule.status === 'completed' ? 'Mark complete' : 'Cancel schedule',
            tone: schedule.status === 'cancelled' ? 'danger' : 'warning',
        });

        if (!confirmed) {
            await loadApplication();
            return;
        }
    }

    scheduleTrackingId.value = schedule.id;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/applications/${application.value.id}/schedules/${schedule.id}`, {
            status: schedule.status,
            attendance_status: schedule.attendance_status,
            attendance_notes: schedule.attendance_notes || null,
        });

        applyApplication(response.data.application);
    } catch {
        await loadApplication();
    } finally {
        scheduleTrackingId.value = null;
    }
}

function recommendationClass(recommendation) {
    if (recommendation === 'highly_recommended') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (recommendation === 'recommended') {
        return 'bg-slate-100 text-slate-800';
    }

    if (recommendation === 'needs_review') {
        return 'bg-amber-100 text-amber-800';
    }

    if (recommendation === 'not_recommended') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-rose-100 text-rose-800';
}

function documentStatusClass(status) {
    if (status === 'accepted') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'needs_replacement') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

function profileVerificationClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'pending') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

function profileVerificationLabel(status) {
    return {
        approved: 'Admin verified',
        rejected: 'Verification rejected',
        pending: 'Verification pending',
        unsubmitted: 'Not verified',
    }[status] ?? labelFromKey(status || 'unsubmitted');
}

function labelFromKey(value) {
    if (customStatusLabels[value]) {
        return customStatusLabels[value];
    }

    return formatKeyLabel(value);
}

function documentRequirements(requirements) {
    if (!requirements) {
        return [];
    }

    return String(requirements)
        .split(/\r?\n|,/)
        .map((requirement) => requirement.trim())
        .filter(Boolean);
}

function normalizeDocumentName(documentName) {
    return String(documentName ?? '').trim().toLocaleLowerCase();
}

function applicantAcademicLabel(applicant) {
    if (!applicant?.gwa) {
        return 'No academic value';
    }

    return applicant.grading_scale === 'grade_point'
        ? `${applicant.gwa} GWA/GPA`
        : `${applicant.gwa}%`;
}

function applyApplication(payload) {
    application.value = payload;
    selectedReviewActionKey.value = '';
    reviewForm.value = {
        status: payload?.status ?? 'submitted',
        decisionReason: payload?.decision_reason ?? '',
        reviewNotes: payload?.review_notes ?? '',
    };
    rubricScores.value = Object.fromEntries(
        (payload?.rubric_review?.criteria ?? []).map((criterion) => [criterion.key, criterion.score ?? '']),
    );
}

function openDocumentReview(document) {
    selectedDocument.value = document;
    documentReviewError.value = '';
}

function closeDocumentReview() {
    selectedDocument.value = null;
    documentReviewError.value = '';
}

function openProfileProof(proof) {
    selectedProfileProof.value = proof;
}

function closeProfileProof() {
    selectedProfileProof.value = null;
}

function selectReviewAction(action) {
    selectedReviewActionKey.value = action.key;
    reviewForm.value.status = action.status;
    reviewForm.value.decisionReason = action.reason;
    reviewForm.value.reviewNotes = action.note;
    errorMessage.value = '';
}

function clearReviewAction() {
    selectedReviewActionKey.value = '';
    reviewForm.value.status = application.value?.status ?? 'submitted';
    reviewForm.value.decisionReason = application.value?.decision_reason ?? '';
    reviewForm.value.reviewNotes = application.value?.review_notes ?? '';
    errorMessage.value = '';
}

function isSelectedReviewAction(action) {
    return selectedReviewActionKey.value === action.key;
}

function statusConfirmation(status) {
    const applicantName = application.value?.applicant?.name || 'This applicant';
    const confirmations = {
        exam_qualified: {
            title: 'Qualify applicant for the exam?',
            message: `${applicantName} will see the provider-managed exam details and receive a status notification.`,
            confirmLabel: 'Qualify for exam',
        },
        interview: {
            title: 'Approve applicant for interview?',
            message: `${applicantName} will move to the interview stage and receive the shared schedule when it is available.`,
            confirmLabel: 'Approve for interview',
        },
        exam_failed: {
            title: 'Record a failed exam result?',
            message: `${applicantName} will receive this negative decision and its reason.`,
            confirmLabel: 'Record failure',
            tone: 'danger',
        },
        approved: {
            title: 'Approve this application?',
            message: `${applicantName} will be notified that the provider approved the application.`,
            confirmLabel: 'Approve application',
        },
        rejected: {
            title: 'Reject this application?',
            message: `${applicantName} will receive the rejection status, reason, and provider note.`,
            confirmLabel: 'Reject application',
            tone: 'danger',
        },
    };

    return confirmations[status] ?? null;
}

async function loadApplication() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/applications/${applicationId}/data`);

        applyApplication(response.data.application);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load application details.';
    } finally {
        isLoading.value = false;
    }
}

async function updateStatus() {
    if (!application.value) {
        return;
    }

    if (negativeDecisionStatuses.includes(reviewForm.value.status) && !reviewForm.value.decisionReason) {
        errorMessage.value = 'Select a decision reason before saving a negative decision.';
        return;
    }

    if (reviewForm.value.status !== application.value.status) {
        const confirmationOptions = statusConfirmation(reviewForm.value.status);

        if (confirmationOptions && !await requestConfirmation(confirmationOptions)) {
            return;
        }
    }

    updatingId.value = application.value.id;
    errorMessage.value = '';

    try {
        const completedRubricScores = Object.fromEntries(
            Object.entries(rubricScores.value).filter(([, score]) => score !== '' && score !== null),
        );
        const response = selectedReviewAction.value?.decision
            ? await window.axios.patch(`/provider/applications/${application.value.id}/decision`, {
                decision: selectedReviewAction.value.decision,
                decision_reason: reviewForm.value.decisionReason || null,
                review_notes: reviewForm.value.reviewNotes,
                rubric_scores: completedRubricScores,
            })
            : await window.axios.patch(`/provider/applications/${application.value.id}/status`, {
                status: application.value.status,
                decision_reason: application.value.decision_reason,
                review_notes: reviewForm.value.reviewNotes,
                rubric_scores: completedRubricScores,
            });

        applyApplication(response.data.application);
    } catch (handledError) {
        void handledError;
    } finally {
        updatingId.value = null;
    }
}

async function updateDocumentStatus(review) {
    const document = review?.document ?? selectedDocument.value;

    if (!application.value || !document) {
        return;
    }

    const documentStatus = review?.status ?? 'pending';
    const documentNote = review?.review_notes ?? '';

    if (documentStatus !== document.status && ['rejected', 'needs_replacement'].includes(documentStatus)) {
        const confirmed = await requestConfirmation({
            title: documentStatus === 'rejected' ? 'Reject this document?' : 'Request a replacement?',
            message: `${application.value.applicant?.name || 'The applicant'} will see the document status and review note.`,
            confirmLabel: documentStatus === 'rejected' ? 'Reject document' : 'Request replacement',
            tone: documentStatus === 'rejected' ? 'danger' : 'warning',
        });

        if (!confirmed) {
            return;
        }
    }

    documentUpdatingId.value = document.id;
    errorMessage.value = '';
    documentReviewError.value = '';

    try {
        const response = await window.axios.patch(`/provider/documents/${document.id}/status`, {
            status: documentStatus,
            review_notes: documentNote,
        });

        applyApplication(response.data.application);
        closeDocumentReview();
    } catch (handledError) {
        void handledError;
    } finally {
        documentUpdatingId.value = null;
    }
}

onMounted(loadApplication);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-3xl">
                            <a href="/provider/applications" class="inline-flex w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                Back to applications
                            </a>
                            <p class="mt-4 text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Application Details
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ application?.applicant?.name || 'Applicant record' }}
                            </h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                {{ application?.scholarship?.title || 'Scholarship program' }}
                            </p>
                            <div v-if="application" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                <span>{{ application.applicant?.email || 'Email not provided' }}</span>
                                <span>{{ application.applicant?.contact_number || 'Contact not provided' }}</span>
                                <span>Submitted {{ application.submitted_at || 'recently' }}</span>
                            </div>
                        </div>
                        <span v-if="application" :class="['w-fit rounded-md px-3 py-2 text-xs font-bold uppercase', statusClass(application.status)]">
                            {{ statusLabel(application.status) }}
                        </span>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading application details...
                </div>

                <div v-else-if="errorMessage && !application" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="application" class="mt-6 space-y-5">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <nav class="flex gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1.5 shadow-sm" aria-label="Application detail sections">
                        <button
                            v-for="section in detailSections"
                            :key="section.key"
                            type="button"
                            :aria-current="activeSection === section.key ? 'page' : undefined"
                            :class="[
                                'inline-flex shrink-0 items-center justify-center gap-2 rounded-md px-4 py-2.5 text-sm font-bold transition',
                                activeSection === section.key
                                    ? 'bg-slate-900 text-white'
                                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950',
                            ]"
                            @click="activeSection = section.key"
                        >
                            {{ section.label }}
                            <span
                                v-if="section.key === 'documents'"
                                :class="activeSection === section.key ? 'text-slate-300' : 'text-slate-400'"
                                class="text-xs"
                            >
                                {{ application.document_readiness?.uploaded ?? 0 }}/{{ application.document_readiness?.required ?? applicationRequirements.length }}
                            </span>
                            <span
                                v-else-if="section.key === 'schedule'"
                                :class="activeSection === section.key ? 'text-slate-300' : 'text-slate-400'"
                                class="text-xs"
                            >
                                {{ schedules.length }}
                            </span>
                            <span
                                v-else-if="section.key === 'history'"
                                :class="activeSection === section.key ? 'text-slate-300' : 'text-slate-400'"
                                class="text-xs"
                            >
                                {{ timeline.length }}
                            </span>
                        </button>
                    </nav>

                    <div :class="usesDetailSidebar ? 'grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]' : 'block'">
                        <div v-if="activeSection !== 'applicant'" class="space-y-5">
                            <section v-if="activeSection === 'review' && application.exam" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="grid sm:grid-cols-[9rem_minmax(0,1fr)_auto] sm:items-center">
                                    <div class="flex h-36 items-center justify-center border-b border-slate-200 bg-slate-50 p-4 sm:border-b-0 sm:border-r">
                                        <img :src="application.exam.image_url" :alt="application.exam.title" class="h-full w-full object-contain">
                                    </div>
                                    <div class="p-4">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Provider-managed exam</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ application.exam.title }}</h3>
                                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-600">
                                            <span v-if="application.exam.duration_minutes">{{ application.exam.duration_minutes }} minutes</span>
                                            <span v-if="application.exam.passing_score !== null">{{ Number(application.exam.passing_score) }}% passing score</span>
                                            <span>{{ labelFromKey(application.exam.delivery_mode) }}</span>
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">Your organization conducts and grades this exam outside the portal.</p>
                                    </div>
                                    <a :href="`/provider/programs/${application.scholarship.id}/edit`" class="m-4 inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                        <i class="fa-solid fa-pen"></i>
                                        Edit program
                                    </a>
                                </div>
                            </section>

                            <section v-if="activeSection === 'review'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Provider Review
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Review applicant
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Check eligibility, requirements, profile proof, and the review rubric. Then approve or reject the applicant.
                                        </p>
                                    </div>
                                    <div class="shrink-0 sm:text-right">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Current stage</p>
                                        <span :class="['mt-1 inline-flex rounded-md px-2.5 py-1.5 text-xs font-bold uppercase', statusClass(application.status)]">
                                            {{ statusLabel(application.status) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-5">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Decision</p>
                                    <div v-if="suggestedReviewActions.length" class="mt-3 grid gap-3 md:grid-cols-2">
                                        <button
                                            v-for="action in suggestedReviewActions"
                                            :key="action.key"
                                            type="button"
                                            :class="[
                                                'group flex min-h-32 flex-col rounded-md border p-4 text-left transition',
                                                isSelectedReviewAction(action)
                                                    ? 'border-slate-900 bg-slate-900 text-white shadow-sm'
                                                    : action.tone === 'danger'
                                                        ? 'border-rose-200 bg-white hover:border-rose-300 hover:bg-rose-50'
                                                        : 'border-slate-200 bg-slate-50 hover:border-slate-300 hover:bg-white',
                                            ]"
                                            @click="selectReviewAction(action)"
                                        >
                                            <span
                                                :class="[
                                                    'inline-flex h-9 w-9 items-center justify-center rounded-md',
                                                    isSelectedReviewAction(action)
                                                        ? 'bg-white/10 text-white'
                                                        : action.tone === 'danger'
                                                            ? 'bg-rose-100 text-rose-700'
                                                            : 'bg-white text-slate-700 ring-1 ring-slate-200',
                                                ]"
                                            >
                                                <i :class="action.icon" aria-hidden="true"></i>
                                            </span>
                                            <span :class="['mt-3 font-bold', isSelectedReviewAction(action) ? 'text-white' : 'text-slate-950']">
                                                {{ action.label }}
                                            </span>
                                            <span :class="['mt-1 text-xs leading-5', isSelectedReviewAction(action) ? 'text-slate-300' : 'text-slate-600']">
                                                {{ action.description }}
                                            </span>
                                        </button>
                                    </div>
                                    <div v-else class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                        {{ completedStageMessage }}
                                    </div>
                                </div>

                                <div class="mt-5 grid gap-4 border-t border-slate-200 pt-5 md:grid-cols-2">
                                    <div v-if="negativeDecisionStatuses.includes(reviewForm.status)">
                                        <label :class="labelClass">
                                            Why was this decision made? <span class="text-rose-600">*</span>
                                        </label>
                                        <select v-model="reviewForm.decisionReason" :class="inputClass">
                                            <option v-for="option in negativeDecisionReasonOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label :class="labelClass">Note for the applicant</label>
                                        <textarea v-model="reviewForm.reviewNotes" rows="3" maxlength="1500" placeholder="Add useful instructions or explain the next step." :class="inputClass"></textarea>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">Keep this short and specific. It appears in the applicant's application record.</p>
                                    </div>

                                    <div class="flex flex-col gap-3 md:col-span-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="text-sm text-slate-600">
                                            <p v-if="selectedReviewAction" class="font-semibold text-slate-800">
                                                Selected: {{ selectedReviewAction.label }}
                                            </p>
                                            <p v-else>Save without changing the current stage.</p>
                                        </div>
                                        <div class="flex flex-col-reverse gap-2 sm:flex-row">
                                            <button
                                                v-if="selectedReviewAction"
                                                type="button"
                                                :disabled="updatingId === application.id"
                                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                                @click="clearReviewAction"
                                            >
                                                Cancel action
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="updatingId === application.id"
                                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                                @click="updateStatus"
                                            >
                                                {{ reviewSubmitLabel }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'review' && rubricReview.criteria?.length" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Review Rubric
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Consistent applicant scoring
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Score each criterion from 0 to 100. The weighted total appears when all criteria are complete.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                        @click="showRubricDetails = !showRubricDetails"
                                    >
                                        {{ showRubricDetails ? 'Hide criteria' : 'Score rubric' }}
                                    </button>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Rubric progress</p>
                                            <p class="mt-1 text-sm font-bold text-slate-950">
                                                {{ rubricDraftSummary.completed }} of {{ rubricDraftSummary.total }} criteria scored
                                            </p>
                                        </div>
                                        <div class="sm:text-right">
                                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Weighted score</p>
                                            <p :class="['mt-1 text-lg font-bold', rubricDraftSummary.isComplete ? 'text-slate-950' : 'text-slate-500']">
                                                {{ rubricDraftSummary.isComplete ? `${rubricDraftSummary.totalScore}%` : 'Complete all criteria' }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white ring-1 ring-slate-200">
                                        <div
                                            class="h-full rounded-full bg-slate-900 transition-all"
                                            :style="{ width: `${rubricDraftSummary.completionPercent}%` }"
                                        ></div>
                                    </div>
                                </div>

                                <div v-if="showRubricDetails" class="mt-4 grid gap-3">
                                    <div
                                        v-for="criterion in rubricReview.criteria"
                                        :key="criterion.key"
                                        class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_7rem] sm:items-center"
                                    >
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                                <span class="rounded bg-white px-2 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">
                                                    {{ criterion.weight }}%
                                                </span>
                                            </div>
                                            <p v-if="criterion.guidance" class="mt-1 text-xs leading-5 text-slate-500">
                                                {{ criterion.guidance }}
                                            </p>
                                        </div>
                                        <div>
                                            <label :for="`rubric-score-${criterion.key}`" class="sr-only">
                                                {{ criterion.label }} score
                                            </label>
                                            <input
                                                :id="`rubric-score-${criterion.key}`"
                                                v-model.number="rubricScores[criterion.key]"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="1"
                                                placeholder="0-100"
                                                :class="inputClass"
                                            >
                                        </div>
                                    </div>
                                </div>

                                <p v-if="showRubricDetails" class="mt-3 text-xs leading-5 text-slate-500">
                                    {{ rubricReview.decision_notice }} Use the review button above to save these scores.
                                </p>
                            </section>

                            <section v-if="activeSection === 'review'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Decision Support
                                </p>
                                <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="text-xl font-bold text-slate-950">
                                            {{ application.dss_score ?? 0 }}% suitability
                                        </h3>
                                        <p class="mt-2 text-sm font-semibold leading-6 text-slate-800">
                                            {{ application.dss_explanation?.headline || application.dss_breakdown?.summary || 'DSS reviewed the current application data.' }}
                                        </p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            {{ application.dss_explanation?.next_action || 'Review eligibility, documents, and notes before deciding.' }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                                        <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', recommendationClass(application.dss_recommendation)]">
                                            {{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}
                                        </span>
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            @click="showDssDetails = !showDssDetails"
                                        >
                                            {{ showDssDetails ? 'Hide details' : 'View details' }}
                                        </button>
                                    </div>
                                </div>

                                <div v-if="showDssDetails && (application.dss_explanation?.strengths?.length || application.dss_explanation?.needs_attention?.length)" class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Strengths</p>
                                        <div class="mt-2 grid gap-2">
                                            <p v-for="item in application.dss_explanation?.strengths ?? []" :key="item" class="text-sm leading-6 text-slate-600">
                                                {{ item }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Needs attention</p>
                                        <div class="mt-2 grid gap-2">
                                            <p v-for="item in application.dss_explanation?.needs_attention ?? []" :key="item" class="text-sm leading-6 text-slate-600">
                                                {{ item }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="showDssDetails && dssCriteria.length" class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div v-for="criterion in dssCriteria" :key="criterion.key" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                            <p class="text-xs font-bold text-slate-500">{{ criterion.weight }}%</p>
                                        </div>
                                        <p class="mt-2 text-xs font-bold uppercase tracking-[0.12em] text-slate-600">
                                            Score {{ criterion.score }}%
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            {{ criterion.note }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'documents'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Documents
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Document checklist
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Open an uploaded file to review it and record your decision.
                                        </p>
                                    </div>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ application.document_readiness?.uploaded ?? 0 }} of {{ application.document_readiness?.required ?? applicationRequirements.length }} uploaded
                                    </span>
                                </div>

                                <div v-if="applicationFileRows.length" class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white">
                                    <div
                                        v-for="row in applicationFileRows"
                                        :key="row.name"
                                        class="flex flex-col gap-3 border-b border-slate-200 p-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span
                                                :class="[
                                                    'mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md',
                                                    row.document ? 'bg-slate-100 text-slate-700' : 'bg-amber-50 text-amber-700',
                                                ]"
                                            >
                                                <i :class="row.document ? 'fa-solid fa-file-circle-check' : 'fa-regular fa-file'"></i>
                                            </span>

                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="font-bold text-slate-950">{{ row.name }}</p>
                                                    <span v-if="!row.required" class="rounded bg-slate-100 px-2 py-0.5 text-[0.65rem] font-bold uppercase text-slate-500">
                                                        Additional file
                                                    </span>
                                                </div>
                                                <p v-if="row.document" class="mt-1 truncate text-xs text-slate-500">
                                                    {{ row.document.original_name }} - {{ formatFileSize(row.document.size) }} - {{ row.document.uploaded_at }}
                                                </p>
                                                <p v-else class="mt-1 text-xs font-semibold text-amber-700">
                                                    Applicant has not uploaded this file
                                                </p>
                                                <p v-if="row.document?.review_notes" class="mt-1 line-clamp-1 text-xs text-slate-600">
                                                    Review note: {{ row.document.review_notes }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                                            <span
                                                :class="[
                                                    'h-fit rounded-md px-2.5 py-2 text-xs font-bold uppercase',
                                                    row.document ? documentStatusClass(row.document.status) : 'bg-amber-50 text-amber-700',
                                                ]"
                                            >
                                                {{ row.document ? labelFromKey(row.document.status || 'pending') : 'Not uploaded' }}
                                            </span>
                                            <button
                                                v-if="row.document"
                                                type="button"
                                                class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                                @click="openDocumentReview(row.document)"
                                            >
                                                <i class="fa-regular fa-eye"></i>
                                                View
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                                    This application does not have any document requirements yet.
                                </div>
                            </section>

                            <section v-if="activeSection === 'schedule'" class="space-y-5">
                                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                        <div>
                                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Applicant schedule</p>
                                            <h3 class="mt-2 text-xl font-bold text-slate-950">Attendance and results</h3>
                                            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                                Shared dates and instructions are managed once from the program applicant page. Use this tab only for this applicant's acknowledgement, attendance, and result.
                                            </p>
                                        </div>
                                        <a :href="`/provider/programs/${application.scholarship.id}/applications`" class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                                            Program schedule
                                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>

                                <div v-if="schedules.length" class="grid gap-4 xl:grid-cols-2">
                                    <article v-for="schedule in schedules" :key="schedule.id" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                        <div class="flex items-start gap-3 border-b border-slate-200 p-4">
                                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-white">
                                                <i :class="scheduleTypeIcon(schedule.type)" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-start justify-between gap-2">
                                                    <div>
                                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ scheduleTypeLabel(schedule.type) }}</p>
                                                        <h3 class="mt-1 text-base font-bold text-slate-950">{{ schedule.title }}</h3>
                                                    </div>
                                                    <span :class="['rounded-md px-2 py-1 text-[11px] font-bold uppercase', scheduleStatusClass(schedule.status)]">{{ labelFromKey(schedule.status) }}</span>
                                                </div>
                                                <p class="mt-2 text-sm font-bold text-slate-700">{{ schedule.scheduled_label }}</p>
                                                <p class="mt-1 text-xs text-slate-500">{{ scheduleModeLabel(schedule.mode) }}</p>
                                                <p v-if="schedule.type === 'distribution'" class="mt-1 text-xs font-bold text-emerald-700">
                                                    {{ formatAwardAmount(application.awarded_amount) }} award
                                                </p>
                                            </div>
                                        </div>

                                        <div class="space-y-3 p-4 text-sm">
                                            <div v-if="schedule.venue || schedule.location_address" class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                                <p class="font-bold text-slate-800">{{ schedule.venue || 'Activity site' }}</p>
                                                <p v-if="schedule.location_address" class="mt-1 leading-5 text-slate-600">{{ schedule.location_address }}</p>
                                            </div>
                                            <a v-if="schedule.online_url" :href="schedule.online_url" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between rounded-md border border-sky-200 bg-sky-50 px-3 py-2.5 font-bold text-sky-800 hover:bg-sky-100">
                                                Open online access link
                                                <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                            </a>
                                            <p class="whitespace-pre-line rounded-md bg-slate-50 p-3 leading-6 text-slate-600 ring-1 ring-slate-200">{{ schedule.instructions }}</p>

                                            <LeafletMapPreview
                                                v-if="schedule.latitude && schedule.longitude"
                                                :latitude="schedule.latitude"
                                                :longitude="schedule.longitude"
                                                :title="schedule.venue || schedule.title"
                                                :marker-text="schedule.venue || schedule.title"
                                                height="12rem"
                                            />

                                            <div :class="['rounded-md px-3 py-2.5 text-xs font-bold', schedule.applicant_acknowledged ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' : 'bg-amber-50 text-amber-800 ring-1 ring-amber-200']">
                                                {{ schedule.applicant_acknowledged ? `Applicant acknowledged ${schedule.applicant_acknowledged_at}` : 'Waiting for applicant acknowledgment' }}
                                            </div>
                                        </div>

                                        <div class="border-t border-slate-200 bg-slate-50 p-4">
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Provider tracking</p>
                                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                                <select v-model="schedule.status" :class="inputClass" @change="handleScheduleStatusChange(schedule)">
                                                    <option value="scheduled">Scheduled</option>
                                                    <option value="completed">Completed</option>
                                                    <option value="cancelled">Cancelled</option>
                                                </select>
                                                <select v-model="schedule.attendance_status" :class="inputClass">
                                                    <option v-for="option in attendanceOptions(schedule.type)" :key="option.value" :value="option.value">{{ option.label }}</option>
                                                </select>
                                            </div>
                                            <textarea v-model="schedule.attendance_notes" rows="2" maxlength="1500" placeholder="Optional attendance or release note" :class="['mt-3', inputClass]"></textarea>
                                            <div class="mt-3 flex flex-wrap justify-end gap-2">
                                                <button type="button" :disabled="scheduleTrackingId === schedule.id" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60" @click="saveScheduleTracking(schedule)">
                                                    {{ scheduleTrackingId === schedule.id ? 'Saving...' : 'Save tracking' }}
                                                </button>
                                            </div>
                                        </div>
                                    </article>
                                </div>

                                <div v-else class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center">
                                    <p class="text-sm font-bold text-slate-800">No schedule announced yet</p>
                                    <p class="mt-1 text-sm text-slate-500">Publish the shared stage from this program's applicant page.</p>
                                </div>
                            </section>

                            <section v-if="activeSection === 'history' && timeline.length" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Timeline
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Review history
                                </h3>
                                <div class="mt-4 grid gap-2">
                                    <div v-for="event in timeline" :key="event.id" class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="font-bold text-slate-950">{{ statusLabel(event.to_status) }}</p>
                                            <p class="text-xs text-slate-500">{{ event.changed_at || 'Recently' }}</p>
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">
                                            By {{ event.actor || 'System' }}
                                            <span v-if="event.decision_reason"> - {{ labelFromKey(event.decision_reason) }}</span>
                                        </p>
                                        <p v-if="event.review_notes" class="mt-2 leading-6 text-slate-600">
                                            {{ event.review_notes }}
                                        </p>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <aside
                            v-if="activeSection === 'applicant' || activeSection === 'history'"
                            :class="activeSection === 'applicant' ? 'grid gap-5 lg:grid-cols-2' : 'space-y-5'"
                        >
                            <section v-if="activeSection === 'history' && application.status_progress" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Progress
                                </p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">
                                    {{ application.status_progress.label }}
                                </h3>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${application.status_progress.percent}%` }"></div>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ application.status_progress.next_action }}
                                </p>
                            </section>

                            <section v-if="activeSection === 'applicant'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Applicant profile</p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">{{ application.applicant?.name || 'Applicant' }}</h3>
                                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                                            <span>{{ application.applicant?.email || 'Email not provided' }}</span>
                                            <span>{{ application.applicant?.contact_number || 'Contact not provided' }}</span>
                                        </div>
                                    </div>
                                    <span
                                        :class="['w-fit rounded-md px-2.5 py-1.5 text-xs font-bold', profileVerificationClass(application.applicant?.profile_verification_status)]"
                                        :title="application.applicant?.profile_verified_at ? `Verified ${application.applicant.profile_verified_at}` : ''"
                                    >
                                        {{ profileVerificationLabel(application.applicant?.profile_verification_status) }}
                                    </span>
                                </div>

                                <dl class="mt-4 grid gap-3 border-t border-slate-200 pt-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="rounded-md bg-slate-50 p-3">
                                        <dt class="font-semibold text-slate-500">Birthdate</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.birthdate || 'Not provided' }}</dd>
                                        <dd v-if="application.applicant?.age !== null && application.applicant?.age !== undefined" class="mt-1 text-xs text-slate-500">Age {{ application.applicant.age }}</dd>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3">
                                        <dt class="font-semibold text-slate-500">Gender</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.gender || 'not provided') }}</dd>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3">
                                        <dt class="font-semibold text-slate-500">Account managed by</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.account_managed_by || 'applicant') }}</dd>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3">
                                        <dt class="font-semibold text-slate-500">Profile updated</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.profile_updated_at || 'Not available' }}</dd>
                                    </div>
                                </dl>

                                <p v-if="application.applicant?.profile_verification_notes" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700">
                                    <span class="font-bold">Admin verification note:</span>
                                    {{ application.applicant.profile_verification_notes }}
                                </p>
                            </section>

                            <section v-if="activeSection === 'applicant'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Education
                                </p>
                                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="font-semibold text-slate-500">Education level</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.education_level || 'not set') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Grade / year</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.year_level || 'Not provided' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Course / strand</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.course_or_strand || 'Not applicable or not provided' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Enrollment</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.enrollment_status || 'not provided') }}</dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt class="font-semibold text-slate-500">School</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.school || 'Not provided' }}</dd>
                                        <dd class="mt-1 text-xs text-slate-500">{{ labelFromKey(application.applicant?.school_type || 'school type not provided') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Academic result</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ applicantAcademicLabel(application.applicant) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Learner reference number</dt>
                                        <dd class="mt-1 break-words font-bold text-slate-950">{{ application.applicant?.learner_reference_number || 'Not provided' }}</dd>
                                    </div>
                                </dl>
                            </section>

                            <section v-if="activeSection === 'applicant'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Household and location
                                </p>
                                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="font-semibold text-slate-500">Income bracket</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.income_bracket || 'Not provided' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Household size</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.household_size ?? 'Not provided' }}</dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt class="font-semibold text-slate-500">Address</dt>
                                        <dd class="mt-1 leading-6 font-bold text-slate-950">{{ application.applicant?.address || application.applicant?.location || 'Not provided' }}</dd>
                                        <dd v-if="application.applicant?.address && application.applicant?.location" class="mt-1 text-xs text-slate-500">{{ application.applicant.location }}</dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt class="font-semibold text-slate-500">Willing to relocate</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.willing_to_relocate || 'not provided') }}</dd>
                                    </div>
                                </dl>
                            </section>

                            <section v-if="activeSection === 'applicant' && hasGuardianDetails" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Parent or guardian</p>
                                    <span v-if="application.applicant?.guardian_is_account_owner" class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        Manages applicant account
                                    </span>
                                </div>
                                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <dt class="font-semibold text-slate-500">Name</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.guardian_name || 'Not provided' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Relationship</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.guardian_relationship || 'Not provided' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Contact</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.guardian_contact || 'Not provided' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold text-slate-500">Email</dt>
                                        <dd class="mt-1 break-words font-bold text-slate-950">{{ application.applicant?.guardian_email || 'Not provided' }}</dd>
                                    </div>
                                </dl>
                            </section>

                            <section v-if="activeSection === 'applicant'" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm lg:col-span-2">
                                <div class="flex flex-col gap-2 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Profile proofs</p>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">
                                            Supporting files from the applicant profile, shown separately from this program's requirements.
                                        </p>
                                    </div>
                                    <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ applicantProfileProofs.length }} on file
                                    </span>
                                </div>

                                <div v-if="applicantProfileProofs.length" class="divide-y divide-slate-200">
                                    <article v-for="proof in applicantProfileProofs" :key="proof.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-700">
                                                <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="font-bold text-slate-950">{{ labelFromKey(proof.document_type) }}</p>
                                                    <span :class="['rounded px-2 py-1 text-[11px] font-bold', profileVerificationClass(proof.status)]">
                                                        {{ labelFromKey(proof.status || 'submitted') }}
                                                    </span>
                                                </div>
                                                <p class="mt-1 truncate text-xs text-slate-500">{{ proof.original_name }}</p>
                                                <p class="mt-1 text-xs text-slate-500">{{ formatFileSize(proof.size) }} - {{ proof.uploaded_at || 'Date unavailable' }}</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                            @click="openProfileProof(proof)"
                                        >
                                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                            View proof
                                        </button>
                                    </article>
                                </div>
                                <p v-else class="p-5 text-sm leading-6 text-slate-600">
                                    No profile proofs have been uploaded. Review the entered profile and application documents instead.
                                </p>
                            </section>

                            <section v-if="activeSection === 'applicant'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Scholarship context</p>
                                <div class="mt-3 grid gap-2 text-sm">
                                    <p class="rounded-md bg-slate-50 p-3 leading-6 text-slate-600"><span class="font-bold text-slate-800">Goal:</span> {{ application.applicant?.scholarship_goal || 'Not provided' }}</p>
                                    <p class="rounded-md bg-slate-50 p-3 leading-6 text-slate-600"><span class="font-bold text-slate-800">Support needs:</span> {{ application.applicant?.support_needs || 'Not provided' }}</p>
                                    <p class="rounded-md bg-slate-50 p-3 leading-6 text-slate-600"><span class="font-bold text-slate-800">Preferred categories:</span> {{ application.applicant?.preferred_categories || 'Not provided' }}</p>
                                    <p class="rounded-md bg-slate-50 p-3 leading-6 text-slate-600"><span class="font-bold text-slate-800">Preferred locations:</span> {{ application.applicant?.preferred_locations || 'Not provided' }}</p>
                                </div>
                            </section>

                            <section v-if="activeSection === 'applicant'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Notes</p>
                                <p class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">{{ application.notes || 'No applicant note added.' }}</p>
                                <div v-if="application.review_notes" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                    <p class="font-semibold text-slate-700">Provider review note</p>
                                    <p class="mt-1 leading-6 text-slate-600">{{ application.review_notes }}</p>
                                </div>
                            </section>

                            <section
                                v-if="activeSection === 'applicant' && providerContractSections.length"
                                class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2"
                            >
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Post-Acceptance Obligations</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">Terms the applicant may need to fulfill if selected for this scholarship.</p>
                                <div class="mt-3 grid gap-2">
                                    <div v-for="section in providerContractSections" :key="section.label" class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                        <p class="font-bold text-slate-800">{{ section.label }}</p>
                                        <p class="mt-1 whitespace-pre-line leading-6 text-slate-600">{{ section.value }}</p>
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>

                <ProviderFooter />
            </div>
        </section>

        <ProviderDocumentReviewModal
            :document="selectedDocument"
            :context="[application?.applicant?.name, application?.scholarship?.title].filter(Boolean).join(' - ')"
            :saving="documentUpdatingId === selectedDocument?.id"
            :error="documentReviewError"
            @close="closeDocumentReview"
            @save="updateDocumentStatus"
            @clear-error="documentReviewError = ''"
        />

        <ApplicantProfileProofModal
            :proof="selectedProfileProof"
            :applicant-name="application?.applicant?.name"
            @close="closeProfileProof"
        />
    </main>
</template>
