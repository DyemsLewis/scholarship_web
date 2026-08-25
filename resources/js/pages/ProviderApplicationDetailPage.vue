<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ApplicantProfileProofModal from '../components/ApplicantProfileProofModal.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderDocumentReviewModal from '../components/ProviderDocumentReviewModal.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ScholarshipBenefitsPanel from '../components/ScholarshipBenefitsPanel.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { decisionReasonOptions, negativeDecisionStatuses } from '../support/applicationDecisionReasons';
import { formatFileSize, labelFromKey as formatKeyLabel } from '../support/display';
import { showPortalToast } from '../support/portalToast';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const isLoading = ref(true);
const updatingId = ref(null);
const documentUpdatingId = ref(null);
const errorMessage = ref('');
const application = ref(null);
const requestedSection = new URLSearchParams(window.location.search).get('section');
const normalizedRequestedSection = requestedSection === 'review' ? 'eligibility' : requestedSection;
const validSections = ['applicant', 'eligibility', 'documents', 'decision', 'schedule', 'history'];
const activeSection = ref(validSections.includes(normalizedRequestedSection)
    ? normalizedRequestedSection
    : 'applicant');
const showDssDetails = ref(false);
const reviewForm = ref(emptyReviewForm());
const selectedDocument = ref(null);
const selectedProfileProof = ref(null);
const selectedReviewActionKey = ref('');
const documentReviewError = ref('');
const rubricScores = ref({});
const postDecisionSummary = ref(null);
const showCorrectionForm = ref(false);
const correctionMessage = ref('');
const isHandlingCorrection = ref(false);
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const primaryDetailSections = [
    { key: 'applicant', label: 'Applicant', icon: 'fa-solid fa-user' },
    { key: 'eligibility', label: 'Eligibility', icon: 'fa-solid fa-scale-balanced' },
    { key: 'documents', label: 'Documents', icon: 'fa-solid fa-file-circle-check' },
    { key: 'decision', label: 'Decision', icon: 'fa-solid fa-gavel' },
];
const secondaryDetailSections = [
    { key: 'schedule', label: 'Schedule', icon: 'fa-regular fa-calendar' },
    { key: 'history', label: 'History', icon: 'fa-solid fa-clock-rotate-left' },
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
        label: 'Qualify applicant',
        description: 'Allow the applicant to continue with your formal application process.',
        confirmLabel: 'Mark as qualified',
        reason: 'qualified_for_formal_application',
        note: 'Applicant qualified after portal pre-screening.',
        icon: 'fa-solid fa-circle-check',
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
    approved: 'Qualified for formal application',
    waitlisted: 'Waitlisted alternate',
    withdrawn: 'Withdrawn',
    awarded: 'Awarded',
    not_awarded: 'Not selected',
    rejected: 'Not qualified',
    exam_qualified: 'Qualified for exam',
    exam_scheduled: 'Exam scheduled',
    exam_taken: 'Exam taken',
    exam_passed: 'Passed exam',
    exam_failed: 'Failed exam',
    interview_failed: 'Failed interview',
    distribution_scheduled: 'Distribution scheduled',
    disbursed: 'Distributed',
    for_exam: 'Meets exam eligibility',
    exam_completed: 'Exam completed',
    passed_exam: 'Passed exam',
    failed_exam: 'Failed exam',
    failed_interview: 'Failed interview',
};

const eligibilityCriteria = computed(() => application.value?.eligibility_breakdown?.criteria ?? []);
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
const pendingManagedStageResult = computed(() => {
    const stageType = {
        exam_scheduled: 'exam',
        interview: 'interview',
    }[application.value?.status];

    if (!stageType) {
        return null;
    }

    return schedules.value.find((schedule) => (
        schedule.type === stageType
        && schedule.status === 'scheduled'
        && (schedule.attendance_status ?? 'pending') === 'pending'
    )) ?? null;
});
const programWorkspaceAction = computed(() => {
    const pendingResult = schedules.value.find((schedule) => (
        schedule.status === 'completed' && (schedule.attendance_status ?? 'pending') === 'pending'
    ));

    if (pendingResult) {
        return {
            section: 'results',
            title: `${scheduleTypeLabel(pendingResult.type)} result still pending`,
            description: 'Record this applicant together with the other participants in the Program Workspace.',
        };
    }

    const dueActivity = schedules.value.find((schedule) => (
        schedule.status === 'scheduled'
        && schedule.scheduled_at
        && new Date(schedule.scheduled_at).getTime() <= Date.now()
    ));

    if (dueActivity) {
        return {
            section: 'results',
            title: `${scheduleTypeLabel(dueActivity.type)} activity is ready to close`,
            description: 'Complete the shared activity and update participant results in the Program Workspace.',
        };
    }

    const waitingType = {
        exam_qualified: 'exam',
        interview: 'interview',
        awarded: 'distribution',
    }[application.value?.status];

    if (waitingType && !schedules.value.some((schedule) => schedule.type === waitingType)) {
        return {
            section: 'schedule',
            title: `${scheduleTypeLabel(waitingType)} schedule needs to be published`,
            description: 'This applicant has reached the stage. Publish the shared details once for all eligible applicants.',
        };
    }

    return null;
});
const programWorkspaceUrl = computed(() => {
    const scholarshipId = application.value?.scholarship?.id;
    const workspaceSection = programWorkspaceAction.value?.section ?? 'applications';

    return scholarshipId
        ? `/provider/programs/${scholarshipId}/applications?workspace=${workspaceSection}`
        : '/provider/applications';
});
const applicantProfileProofs = computed(() => application.value?.applicant?.profile_proofs ?? []);
const applicantInitials = computed(() => String(application.value?.applicant?.name ?? 'Applicant')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || 'AP');
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
const activePrimarySectionIndex = computed(() => primaryDetailSections.findIndex((section) => section.key === activeSection.value));
const previousPrimarySection = computed(() => (
    activePrimarySectionIndex.value > 0 ? primaryDetailSections[activePrimarySectionIndex.value - 1] : null
));
const nextPrimarySection = computed(() => (
    activePrimarySectionIndex.value >= 0 && activePrimarySectionIndex.value < primaryDetailSections.length - 1
        ? primaryDetailSections[activePrimarySectionIndex.value + 1]
        : null
));
const selectionStages = computed(() => application.value?.scholarship?.selection_stages ?? ['screening']);
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
const documentReviewComplete = computed(() => {
    const readiness = application.value?.document_readiness;
    const required = Number(readiness?.required ?? 0);
    const accepted = Number(readiness?.accepted ?? 0);

    return required === 0 || accepted >= required;
});
const approvalParticipationBlock = computed(() => {
    const stageType = {
        exam_taken: 'exam',
        exam_passed: 'exam',
        interview: 'interview',
    }[application.value?.status];

    if (!stageType) {
        return null;
    }

    const schedule = schedules.value.find((item) => item.type === stageType);

    if (schedule?.status === 'completed' && schedule.attendance_status === 'attended') {
        return null;
    }

    if (!schedule) {
        return {
            section: 'schedule',
            message: `Publish the shared ${scheduleTypeLabel(stageType).toLowerCase()} schedule before approving the next stage.`,
        };
    }

    if (schedule.status !== 'completed') {
        return {
            section: 'schedule',
            message: `Close the ${scheduleTypeLabel(stageType).toLowerCase()} activity and record attendance before approving the next stage.`,
        };
    }

    return {
        section: 'schedule',
        message: `This applicant is marked ${labelFromKey(schedule.attendance_status || 'pending').toLowerCase()}. Only an attended applicant can be approved for the next stage.`,
    };
});
const documentReviewBlockMessage = computed(() => {
    const readiness = application.value?.document_readiness;
    const required = Number(readiness?.required ?? 0);
    const accepted = Number(readiness?.accepted ?? 0);

    if (readiness?.missing?.length) {
        return `${readiness.missing.length} required file${readiness.missing.length === 1 ? ' is' : 's are'} still missing.`;
    }

    if (readiness?.needs_attention?.length) {
        return 'Resolve the rejected or replacement files before approving.';
    }

    return `${accepted} of ${required} required files accepted. Review the remaining files before approving.`;
});
const nextRejectionStatus = computed(() => (
    ['exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed'].includes(application.value?.status)
        ? 'exam_failed'
        : (application.value?.status === 'interview'
            ? 'interview_failed'
            : (['submitted', 'under_review', 'qualified', 'shortlisted'].includes(application.value?.status) ? 'rejected' : null))
));
const suggestedReviewActions = computed(() => {
    if (pendingManagedStageResult.value) {
        return [];
    }

    if (application.value?.status === 'approved') {
        return [
            {
                key: 'record_award',
                decision: 'outcome',
                directStatus: true,
                status: 'awarded',
                reason: 'approved_for_award',
                note: 'Selected by the provider after completing the formal application process.',
                label: 'Award scholarship',
                description: 'Confirm that this applicant was selected after your organization completed its formal process.',
                confirmLabel: 'Confirm award',
                icon: 'fa-solid fa-award',
                tone: 'success',
            },
            {
                key: 'add_to_waitlist',
                decision: 'outcome',
                directStatus: true,
                status: 'waitlisted',
                reason: 'funds_limited',
                note: 'Applicant remains eligible and was placed on the alternate recipient waitlist.',
                label: 'Add to waitlist',
                description: 'Keep this eligible applicant as an alternate if an award slot becomes available.',
                confirmLabel: 'Add to waitlist',
                icon: 'fa-solid fa-list-ol',
                tone: 'default',
            },
            {
                key: 'record_not_selected',
                decision: 'outcome',
                directStatus: true,
                status: 'not_awarded',
                reason: 'not_selected',
                note: 'The applicant was not selected after the provider formal application process.',
                label: 'Not selected',
                description: 'Close the application without an award and provide the applicant with a clear reason.',
                confirmLabel: 'Confirm not selected',
                icon: 'fa-solid fa-circle-xmark',
                tone: 'danger',
            },
        ];
    }

    if (application.value?.status === 'waitlisted') {
        return [
            {
                key: 'promote_alternate',
                decision: 'outcome',
                directStatus: true,
                status: 'awarded',
                reason: 'approved_for_award',
                note: 'Promoted from the alternate recipient waitlist after a slot became available.',
                label: 'Promote alternate',
                description: 'Use the available award slot for this waitlisted applicant.',
                confirmLabel: 'Promote recipient',
                icon: 'fa-solid fa-arrow-up-right-dots',
                tone: 'success',
            },
            {
                key: 'remove_from_waitlist',
                decision: 'outcome',
                directStatus: true,
                status: 'approved',
                reason: 'qualified_for_formal_application',
                note: 'Removed from the alternate list and returned to qualified status.',
                label: 'Remove from waitlist',
                description: 'Return this applicant to the qualified formal-application stage.',
                confirmLabel: 'Remove from waitlist',
                icon: 'fa-solid fa-rotate-left',
                tone: 'default',
            },
        ];
    }

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
                approved: 'qualified_for_formal_application',
            }[nextApprovalStatus.value] ?? '',
            note: nextApprovalStatus.value === 'approved'
                ? 'Applicant qualified after eligibility, document, and provider pre-screening.'
                : `Applicant approved to proceed to ${nextLabel.toLowerCase()}.`,
            label: nextApprovalStatus.value === 'approved' ? 'Qualify applicant' : `Approve for ${nextLabel.replace(/^Qualified for /, '')}`,
            description: nextApprovalStatus.value === 'approved'
                ? (approvalParticipationBlock.value?.message || 'Confirm that the applicant passed portal pre-screening and may formally apply with your organization.')
                : `Move this applicant to the configured ${nextLabel.toLowerCase()} stage.`,
            confirmLabel: nextApprovalStatus.value === 'approved' ? 'Mark as qualified' : `Approve for ${nextLabel.toLowerCase()}`,
            blocked: !documentReviewComplete.value || Boolean(approvalParticipationBlock.value),
            blockedSection: !documentReviewComplete.value ? 'documents' : approvalParticipationBlock.value?.section,
            blockedMessage: !documentReviewComplete.value ? documentReviewBlockMessage.value : approvalParticipationBlock.value?.message,
        });
    }

    if (nextRejectionStatus.value) {
        const failedStageAction = {
            exam_failed: {
                label: 'Failed exam',
                description: 'Record that the applicant did not pass the scholarship exam.',
                confirmLabel: 'Record failed exam',
                reason: 'failed_exam',
                note: 'Applicant did not pass the scholarship exam.',
            },
            interview_failed: {
                label: 'Failed interview',
                description: 'Record that the applicant did not pass the scholarship interview.',
                confirmLabel: 'Record failed interview',
                reason: 'failed_interview',
                note: 'Applicant did not pass the scholarship interview.',
            },
        }[nextRejectionStatus.value] ?? {};

        actions.push({
            ...reviewActionCatalog.reject,
            ...failedStageAction,
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
    exam_scheduled: 'Complete the shared exam, then record Passed or Failed once in Program Results.',
    interview: 'Complete the shared interview, then record Passed or Failed once in Program Results.',
    approved: 'The applicant passed portal pre-screening and can now follow your formal application instructions.',
    waitlisted: 'This qualified applicant is on the alternate recipient list. Promote them when an award slot becomes available.',
    withdrawn: 'The applicant withdrew this application. The record remains available for reference and no further decision is needed.',
    awarded: 'The award is confirmed. Publish distribution details once from the program applicant page.',
    not_awarded: 'The provider formal application process is complete and the applicant was not selected.',
    distribution_scheduled: 'Distribution is scheduled. Use Schedule only to record this applicant\'s release result.',
    disbursed: 'The scholarship release is complete for this applicant.',
}[application.value?.status] ?? 'No applicant decision is needed at this stage. You can still save review notes and rubric scores.'));
const decisionPanelTitle = computed(() => {
    if (application.value?.status === 'withdrawn') {
        return 'Application withdrawn';
    }

    if (['approved', 'waitlisted'].includes(application.value?.status)) {
        return 'Record the formal application outcome';
    }

    return 'Record the pre-screening decision';
});
const decisionPanelDescription = computed(() => {
    if (application.value?.status === 'withdrawn') {
        return 'Keep the withdrawal reason for your records. This application no longer needs review.';
    }

    if (['approved', 'waitlisted'].includes(application.value?.status)) {
        return 'After your organization finishes its formal process, record the final recipient outcome.';
    }

    return 'Decide whether the applicant may continue to your organization’s formal application process.';
});
const canRequestCorrection = computed(() => application.value
    && ![
        'withdrawn',
        'rejected',
        'not_awarded',
        'exam_failed',
        'interview_failed',
        'disbursed',
        'renewed',
    ].includes(application.value.status)
    && !['requested', 'submitted'].includes(application.value.correction_status));
const confirmedDocuments = computed(() => application.value?.document_checklist ?? []);
const requiredDocuments = computed(() => documentRequirements(application.value?.scholarship?.requirements));
const applicationRequirements = computed(() => {
    const checklist = confirmedDocuments.value
        .map((requirement) => String(requirement).trim())
        .filter(Boolean);

    return checklist.length ? checklist : requiredDocuments.value;
});
const optionalApplicationRequirements = computed(() => {
    const checklist = (application.value?.optional_document_checklist ?? [])
        .map((requirement) => String(requirement).trim())
        .filter(Boolean);

    return checklist.length
        ? checklist
        : documentRequirements(application.value?.scholarship?.optional_requirements);
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

    optionalApplicationRequirements.value.forEach((requirement) => {
        const normalizedName = normalizeDocumentName(requirement);

        if (!normalizedName || seenNames.has(normalizedName)) {
            return;
        }

        seenNames.add(normalizedName);
        rows.push({
            name: requirement,
            document: documentsByName.get(normalizedName) ?? null,
            required: false,
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
        { label: 'Possible service commitment', value: scholarship.return_service_contract },
        { label: 'Commitment preview', value: scholarship.other_contract_terms },
        { label: 'Possible renewal requirement', value: scholarship.renewal_policy },
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

    if (['withdrawn', 'rejected', 'not_awarded', 'exam_failed', 'interview_failed'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled', 'waitlisted'].includes(status)) {
        return 'bg-slate-100 text-slate-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function eligibilityStatusClass(status) {
    if (status === 'pass') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'fail') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'missing') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

function eligibilityStatusIcon(status) {
    return {
        pass: 'fa-solid fa-circle-check',
        fail: 'fa-solid fa-circle-xmark',
        missing: 'fa-solid fa-circle-exclamation',
        info: 'fa-solid fa-circle-info',
    }[status] ?? 'fa-solid fa-circle-info';
}

function eligibilityStatusTextClass(status) {
    return {
        pass: 'text-emerald-700',
        fail: 'text-rose-700',
        missing: 'text-amber-700',
        info: 'text-slate-500',
    }[status] ?? 'text-slate-500';
}

function sectionSummary(sectionKey) {
    if (sectionKey === 'applicant') {
        return profileVerificationLabel(application.value?.applicant?.profile_verification_status);
    }

    if (sectionKey === 'eligibility') {
        return `${application.value?.eligibility_breakdown?.score ?? application.value?.dss_score ?? 0}% match`;
    }

    if (sectionKey === 'documents') {
        const readiness = application.value?.document_readiness;

        return `${readiness?.accepted ?? 0}/${readiness?.required ?? applicationRequirements.value.length} accepted`;
    }

    if (sectionKey === 'decision') {
        return statusLabel(application.value?.status);
    }

    if (sectionKey === 'schedule') {
        return `${schedules.value.length} ${schedules.value.length === 1 ? 'item' : 'items'}`;
    }

    return `${timeline.value.length} ${timeline.value.length === 1 ? 'event' : 'events'}`;
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
        approved: 'Academic record verified',
        rejected: 'Academic record needs replacement',
        pending: 'Academic review pending',
        unsubmitted: 'Academic record not verified',
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
    if (action.blocked) {
        activeSection.value = action.blockedSection || 'decision';
        errorMessage.value = action.blockedMessage || 'Complete the required review steps before continuing.';

        return;
    }

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
        interview_failed: {
            title: 'Record a failed interview result?',
            message: `${applicantName} will receive this negative decision and its reason.`,
            confirmLabel: 'Record failure',
            tone: 'danger',
        },
        approved: {
            title: 'Mark this applicant as qualified?',
            message: `${applicantName} will receive the documents and instructions needed to continue the formal application with your organization. This is not a final scholarship award.`,
            confirmLabel: 'Mark as qualified',
        },
        waitlisted: {
            title: 'Add this applicant to the waitlist?',
            message: `${applicantName} will be notified that they remain eligible as an alternate recipient.` ,
            confirmLabel: 'Add to waitlist',
        },
        awarded: {
            title: 'Confirm this scholarship award?',
            message: `${applicantName} will be notified that your organization selected them after the formal application process.`,
            confirmLabel: 'Confirm award',
        },
        not_awarded: {
            title: 'Mark this applicant as not selected?',
            message: `${applicantName} will receive the final outcome, selected reason, and provider note.`,
            confirmLabel: 'Confirm not selected',
            tone: 'danger',
        },
        rejected: {
            title: 'Mark this applicant as not qualified?',
            message: `${applicantName} will receive the pre-screening result, reason, and provider note.`,
            confirmLabel: 'Mark as not qualified',
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

async function requestApplicationCorrection() {
    const message = correctionMessage.value.trim();

    if (!application.value || message.length < 5) {
        errorMessage.value = 'Tell the applicant what needs to be corrected.';
        return;
    }

    isHandlingCorrection.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/applications/${application.value.id}/correction`, {
            action: 'request',
            message,
        });

        applyApplication(response.data.application);
        correctionMessage.value = '';
        showCorrectionForm.value = false;
        showPortalToast({
            title: 'Correction requested',
            message: response.data.message,
        });
    } catch (error) {
        errorMessage.value = error.response?.data?.errors?.message?.[0]
            ?? error.response?.data?.errors?.action?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to send the correction request.';
    } finally {
        isHandlingCorrection.value = false;
    }
}

async function resolveApplicationCorrection() {
    if (!application.value || isHandlingCorrection.value) {
        return;
    }

    const confirmed = await requestConfirmation({
        title: 'Complete this correction review?',
        message: 'Confirm that the applicant has provided the requested changes and that you have reviewed them.',
        confirmLabel: 'Mark as resolved',
    });

    if (!confirmed) {
        return;
    }

    isHandlingCorrection.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/applications/${application.value.id}/correction`, {
            action: 'resolve',
        });

        applyApplication(response.data.application);
        showPortalToast({
            title: 'Correction resolved',
            message: response.data.message,
        });
    } catch (error) {
        errorMessage.value = error.response?.data?.errors?.action?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to resolve the correction request.';
    } finally {
        isHandlingCorrection.value = false;
    }
}

async function updateStatus() {
    if (!application.value) {
        return;
    }

    if (rubricReview.value.criteria?.length && !rubricDraftSummary.value.isComplete) {
        activeSection.value = 'decision';
        errorMessage.value = 'Score every provider review criterion before saving the review.';
        return;
    }

    if (selectedReviewAction.value?.blocked) {
        activeSection.value = selectedReviewAction.value.blockedSection || 'decision';
        errorMessage.value = selectedReviewAction.value.blockedMessage || 'Complete the required review steps before continuing.';
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

    const completedReviewAction = selectedReviewAction.value
        ? { ...selectedReviewAction.value }
        : null;

    updatingId.value = application.value.id;
    errorMessage.value = '';

    try {
        const completedRubricScores = Object.fromEntries(
            Object.entries(rubricScores.value).filter(([, score]) => score !== '' && score !== null),
        );
        const response = selectedReviewAction.value?.decision && !selectedReviewAction.value?.directStatus
            ? await window.axios.patch(`/provider/applications/${application.value.id}/decision`, {
                decision: selectedReviewAction.value.decision,
                decision_reason: reviewForm.value.decisionReason || null,
                review_notes: reviewForm.value.reviewNotes,
                rubric_scores: completedRubricScores,
            })
            : await window.axios.patch(`/provider/applications/${application.value.id}/status`, {
                status: selectedReviewAction.value?.directStatus ? reviewForm.value.status : application.value.status,
                decision_reason: selectedReviewAction.value?.directStatus
                    ? (reviewForm.value.decisionReason || null)
                    : application.value.decision_reason,
                review_notes: reviewForm.value.reviewNotes,
                rubric_scores: completedRubricScores,
            });

        applyApplication(response.data.application);

        if (completedReviewAction?.decision) {
            postDecisionSummary.value = {
                actionLabel: completedReviewAction.label,
                message: response.data.message || 'The applicant decision was saved.',
                remainingCount: Number(response.data.review_navigation?.remaining_count ?? 0),
                listUrl: response.data.review_navigation?.list_url
                    || `/provider/applications?scholarship_id=${application.value?.scholarship?.id ?? ''}&filter=${completedReviewAction.directStatus ? 'formal_application' : 'pending_review'}`,
                nextApplication: response.data.review_navigation?.next_application ?? null,
            };
        }
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

watch(activeSection, (section) => {
    const url = new URL(window.location.href);
    url.searchParams.set('section', section);
    window.history.replaceState(window.history.state, '', url);
});

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
                                Back to applicants
                            </a>
                            <p class="mt-4 text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Applicant Review
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
                        <div v-if="application" class="flex flex-wrap items-center gap-2">
                            <span :class="['w-fit rounded-md px-3 py-2 text-xs font-bold uppercase', statusClass(application.status)]">
                                {{ statusLabel(application.status) }}
                            </span>
                            <button
                                v-if="activeSection !== 'decision'"
                                type="button"
                                class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="activeSection = 'decision'"
                            >
                                Record decision
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading applicant review...
                </div>

                <div v-else-if="errorMessage && !application" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="application" class="mt-6 space-y-5">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-4 py-3">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Review steps</p>
                        </div>
                        <nav class="grid gap-1 p-1.5 sm:grid-cols-2 xl:grid-cols-4" aria-label="Applicant review steps">
                            <button
                                v-for="(section, index) in primaryDetailSections"
                                :key="section.key"
                                type="button"
                                :aria-current="activeSection === section.key ? 'step' : undefined"
                                :class="[
                                    'flex min-w-0 items-center gap-3 rounded-md px-3 py-3 text-left transition',
                                    activeSection === section.key
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-700 hover:bg-slate-50 hover:text-slate-950',
                                ]"
                                @click="activeSection = section.key"
                            >
                                <span
                                    :class="[
                                        'grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs font-bold',
                                        activeSection === section.key ? 'bg-white/10 text-white' : 'bg-slate-100 text-slate-600',
                                    ]"
                                >
                                    <i :class="section.icon" aria-hidden="true"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="flex items-center gap-2">
                                        <span class="block truncate text-sm font-bold">{{ section.label }}</span>
                                        <span :class="['shrink-0 text-[9px] font-bold uppercase tracking-[0.12em]', activeSection === section.key ? 'text-slate-300' : 'text-slate-400']">
                                            Step {{ index + 1 }}
                                        </span>
                                    </span>
                                    <span :class="['mt-0.5 block truncate text-xs', activeSection === section.key ? 'text-slate-300' : 'text-slate-500']">
                                        {{ sectionSummary(section.key) }}
                                    </span>
                                </span>
                            </button>
                        </nav>

                        <nav class="flex flex-wrap items-center gap-1 border-t border-slate-200 bg-slate-50 px-3 py-2" aria-label="Applicant follow-up sections">
                            <span class="mr-2 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Follow-up</span>
                            <button
                                v-for="section in secondaryDetailSections"
                                :key="section.key"
                                type="button"
                                :aria-current="activeSection === section.key ? 'page' : undefined"
                                :class="[
                                    'inline-flex items-center gap-2 rounded-md px-3 py-2 text-xs font-bold transition',
                                    activeSection === section.key
                                        ? 'bg-white text-slate-950 shadow-sm ring-1 ring-slate-200'
                                        : 'text-slate-600 hover:bg-white hover:text-slate-950',
                                ]"
                                @click="activeSection = section.key"
                            >
                                <i :class="section.icon" aria-hidden="true"></i>
                                {{ section.label }}
                                <span class="font-semibold text-slate-400">{{ sectionSummary(section.key) }}</span>
                                <span
                                    v-if="section.key === 'schedule' && programWorkspaceAction"
                                    class="h-2 w-2 rounded-full bg-amber-400"
                                    aria-label="Program action needed"
                                ></span>
                            </button>
                        </nav>
                    </section>

                    <section
                        v-if="programWorkspaceAction && !(postDecisionSummary && activeSection === 'decision')"
                        class="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-200 text-amber-900">
                                <i class="fa-solid fa-arrow-up-right-dots text-sm" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Program action needed</p>
                                <p class="mt-1 text-sm font-bold text-slate-950">{{ programWorkspaceAction.title }}</p>
                                <p class="mt-0.5 text-xs leading-5 text-slate-600">{{ programWorkspaceAction.description }}</p>
                            </div>
                        </div>
                        <a
                            :href="programWorkspaceUrl"
                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            Open Program Workspace
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </a>
                    </section>

                    <div :class="usesDetailSidebar ? 'grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]' : 'block'">
                        <div v-if="activeSection !== 'applicant'" class="flex flex-col gap-5">
                            <section v-if="activeSection === 'eligibility'" class="order-1 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Eligibility check</p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">Published criteria comparison</h3>
                                        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                                            Compare the applicant profile with the program rules. Confirm important details against the submitted proof before deciding.
                                        </p>
                                    </div>
                                    <div class="shrink-0 sm:text-right">
                                        <p class="text-2xl font-bold text-slate-950">{{ application.eligibility_breakdown?.score ?? 0 }}%</p>
                                        <p class="text-xs font-bold text-slate-500">{{ application.eligibility_breakdown?.label || 'Needs review' }}</p>
                                    </div>
                                </div>

                                <div v-if="eligibilityCriteria.length" class="grid gap-px bg-slate-200 md:grid-cols-2">
                                    <article v-for="criterion in eligibilityCriteria" :key="criterion.key" class="bg-white p-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <i :class="[eligibilityStatusIcon(criterion.status), eligibilityStatusTextClass(criterion.status)]" aria-hidden="true"></i>
                                                <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                            </div>
                                            <span :class="['shrink-0 rounded px-2 py-1 text-[10px] font-bold uppercase', eligibilityStatusClass(criterion.status)]">
                                                {{ labelFromKey(criterion.status) }}
                                            </span>
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-slate-600">{{ criterion.note }}</p>
                                        <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                            <div class="rounded-md bg-slate-50 p-2.5">
                                                <dt class="font-semibold text-slate-500">Applicant</dt>
                                                <dd class="mt-1 break-words font-bold text-slate-800">{{ criterion.student_value || 'Not provided' }}</dd>
                                            </div>
                                            <div class="rounded-md bg-slate-50 p-2.5">
                                                <dt class="font-semibold text-slate-500">Program rule</dt>
                                                <dd class="mt-1 break-words font-bold text-slate-800">{{ criterion.requirement || 'Open to all' }}</dd>
                                            </div>
                                        </dl>
                                    </article>
                                </div>
                                <p v-else class="p-5 text-sm leading-6 text-slate-600">This program does not have structured eligibility rules to compare.</p>
                            </section>

                            <section v-if="activeSection === 'eligibility' && application.exam" class="order-3 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
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

                            <section v-if="activeSection === 'decision'" class="order-5 rounded-lg border border-slate-300 bg-white p-5 shadow-sm">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Decision
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            {{ decisionPanelTitle }}
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            {{ decisionPanelDescription }}
                                        </p>
                                    </div>
                                    <div class="shrink-0 sm:text-right">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Current stage</p>
                                        <span :class="['mt-1 inline-flex rounded-md px-2.5 py-1.5 text-xs font-bold uppercase', statusClass(application.status)]">
                                            {{ statusLabel(application.status) }}
                                        </span>
                                    </div>
                                </div>

                                <div
                                    v-if="application.status === 'withdrawn'"
                                    class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-rose-100 text-rose-700">
                                            <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="font-bold text-rose-950">Withdrawn by the applicant</p>
                                            <p class="mt-1 text-sm leading-6 text-rose-900">
                                                {{ application.withdrawal_reason || 'No withdrawal reason was provided.' }}
                                            </p>
                                            <p v-if="application.withdrawn_at" class="mt-1 text-xs font-semibold text-rose-700">
                                                Withdrawn {{ application.withdrawn_at }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="application.status === 'waitlisted'"
                                    class="mt-5 flex flex-col gap-3 rounded-md border border-sky-200 bg-sky-50 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-sky-100 text-sky-700">
                                            <i class="fa-solid fa-list-ol" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="font-bold text-sky-950">Alternate recipient</p>
                                            <p class="mt-1 text-sm leading-6 text-sky-900">
                                                Keep this qualified applicant available if an award slot opens.
                                            </p>
                                        </div>
                                    </div>
                                    <span v-if="application.waitlist_position" class="w-fit rounded-md bg-white px-3 py-2 text-sm font-bold text-sky-900 ring-1 ring-sky-200">
                                        Position {{ application.waitlist_position }}
                                    </span>
                                </div>

                                <div
                                    v-if="application.correction_status"
                                    :class="[
                                        'mt-5 rounded-md border p-4',
                                        application.correction_status === 'submitted'
                                            ? 'border-sky-200 bg-sky-50'
                                            : application.correction_status === 'resolved'
                                                ? 'border-emerald-200 bg-emerald-50'
                                                : 'border-amber-200 bg-amber-50',
                                    ]"
                                >
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Applicant correction</p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ application.correction_status === 'submitted' ? 'Changes sent for review' : application.correction_status === 'resolved' ? 'Correction resolved' : 'Waiting for applicant changes' }}
                                            </p>
                                            <p v-if="application.correction_message" class="mt-2 text-sm leading-6 text-slate-700">
                                                <strong>Requested:</strong> {{ application.correction_message }}
                                            </p>
                                            <p v-if="application.correction_response" class="mt-1 text-sm leading-6 text-slate-700">
                                                <strong>Applicant response:</strong> {{ application.correction_response }}
                                            </p>
                                        </div>
                                        <button
                                            v-if="application.correction_status === 'submitted'"
                                            type="button"
                                            :disabled="isHandlingCorrection"
                                            class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60"
                                            @click="resolveApplicationCorrection"
                                        >
                                            Mark resolved
                                        </button>
                                    </div>
                                </div>

                                <div v-if="showCorrectionForm" class="mt-5 rounded-md border border-slate-300 bg-slate-50 p-4">
                                    <label :class="labelClass">What should the applicant correct?</label>
                                    <textarea
                                        v-model="correctionMessage"
                                        rows="3"
                                        maxlength="1500"
                                        placeholder="Example: Replace the unreadable report card and update your current grade level."
                                        :class="inputClass"
                                    ></textarea>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            :disabled="isHandlingCorrection"
                                            class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60"
                                            @click="requestApplicationCorrection"
                                        >
                                            Send correction request
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="isHandlingCorrection"
                                            class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60"
                                            @click="showCorrectionForm = false; correctionMessage = ''"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-5">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Available actions</p>
                                        <button
                                            v-if="canRequestCorrection && !showCorrectionForm"
                                            type="button"
                                            class="inline-flex w-fit items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            @click="showCorrectionForm = true"
                                        >
                                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                            Request correction
                                        </button>
                                    </div>
                                    <button
                                        v-if="nextApprovalStatus && !documentReviewComplete"
                                        type="button"
                                        class="mt-3 flex w-full items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-left text-sm text-amber-950 transition hover:bg-amber-100"
                                        @click="activeSection = 'documents'"
                                    >
                                        <i class="fa-solid fa-file-circle-exclamation mt-0.5 text-amber-700" aria-hidden="true"></i>
                                        <span>
                                            <strong class="block">Document review must be completed first</strong>
                                            <span class="mt-0.5 block text-xs leading-5 text-amber-800">{{ documentReviewBlockMessage }} Open the Documents tab to continue.</span>
                                        </span>
                                    </button>
                                    <button
                                        v-if="nextApprovalStatus && documentReviewComplete && approvalParticipationBlock"
                                        type="button"
                                        class="mt-3 flex w-full items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-left text-sm text-amber-950 transition hover:bg-amber-100"
                                        @click="activeSection = approvalParticipationBlock.section"
                                    >
                                        <i class="fa-solid fa-calendar-check mt-0.5 text-amber-700" aria-hidden="true"></i>
                                        <span>
                                            <strong class="block">Participation must be recorded first</strong>
                                            <span class="mt-0.5 block text-xs leading-5 text-amber-800">{{ approvalParticipationBlock.message }} Open the Schedule tab to continue.</span>
                                        </span>
                                    </button>
                                    <div v-if="suggestedReviewActions.length" class="mt-3 grid gap-3 md:grid-cols-2">
                                        <button
                                            v-for="action in suggestedReviewActions"
                                            :key="action.key"
                                            type="button"
                                            :disabled="action.blocked"
                                            :class="[
                                                'group flex min-h-24 flex-col rounded-md border p-4 text-left transition',
                                                action.blocked
                                                    ? 'cursor-not-allowed border-slate-200 bg-slate-100 opacity-60'
                                                    : isSelectedReviewAction(action)
                                                    ? 'border-slate-900 bg-slate-900 text-white shadow-sm'
                                                    : action.tone === 'danger'
                                                        ? 'border-rose-200 bg-white hover:border-rose-300 hover:bg-rose-50'
                                                        : action.tone === 'success'
                                                            ? 'border-emerald-200 bg-white hover:border-emerald-300 hover:bg-emerald-50'
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
                                                            : action.tone === 'success'
                                                                ? 'bg-emerald-100 text-emerald-700'
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
                                                :disabled="updatingId === application.id || selectedReviewAction?.blocked"
                                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                                @click="updateStatus"
                                            >
                                                {{ reviewSubmitLabel }}
                                            </button>
                                        </div>
                                    </div>

                                    <div
                                        v-if="postDecisionSummary"
                                        class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 md:col-span-2"
                                    >
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="flex min-w-0 items-start gap-3">
                                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-600 text-white">
                                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="text-sm font-bold text-emerald-950">Decision saved</p>
                                                    <p class="mt-1 text-xs leading-5 text-emerald-900">{{ postDecisionSummary.message }}</p>
                                                    <p class="mt-1 text-xs font-semibold text-emerald-800">
                                                        <template v-if="postDecisionSummary.remainingCount">
                                                            {{ postDecisionSummary.remainingCount }} other applicant{{ postDecisionSummary.remainingCount === 1 ? '' : 's' }} still need{{ postDecisionSummary.remainingCount === 1 ? 's' : '' }} review in this program.
                                                        </template>
                                                        <template v-else>
                                                            No other applicants are waiting for a decision in this program.
                                                        </template>
                                                    </p>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                class="grid h-8 w-8 shrink-0 place-items-center rounded-md text-emerald-800 transition hover:bg-emerald-100"
                                                aria-label="Dismiss decision follow-up"
                                                @click="postDecisionSummary = null"
                                            >
                                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                            </button>
                                        </div>

                                        <div class="mt-4 flex flex-col gap-2 border-t border-emerald-200 pt-4 sm:flex-row sm:flex-wrap">
                                            <a
                                                v-if="postDecisionSummary.nextApplication"
                                                :href="postDecisionSummary.nextApplication.url"
                                                class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                            >
                                                Review next applicant
                                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                            </a>
                                            <a
                                                :href="postDecisionSummary.listUrl"
                                                class="inline-flex items-center justify-center gap-2 rounded-md border border-emerald-300 bg-white px-4 py-2.5 text-sm font-bold text-emerald-950 transition hover:bg-emerald-100"
                                            >
                                                Back to applications
                                            </a>
                                            <a
                                                v-if="programWorkspaceAction"
                                                :href="programWorkspaceUrl"
                                                class="inline-flex items-center justify-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-950 transition hover:bg-amber-100 sm:ml-auto"
                                            >
                                                {{ programWorkspaceAction.section === 'schedule' ? 'Set program schedule' : 'Open Program Workspace' }}
                                                <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'decision' && rubricReview.criteria?.length" class="order-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div>
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                            Review Rubric
                                        </p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                                            Consistent applicant scoring
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Score every provider criterion from 0 to 100 before saving the review or decision.
                                        </p>
                                    </div>
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

                                <div class="mt-4 grid gap-3">
                                    <div
                                        v-for="criterion in rubricReview.criteria"
                                        :key="criterion.key"
                                        class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_7rem] sm:items-center"
                                    >
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                                <span class="text-xs font-bold text-rose-600">Required</span>
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
                                                required
                                                :class="inputClass"
                                            >
                                        </div>
                                    </div>
                                </div>

                                <p class="mt-3 text-xs leading-5 text-slate-500">
                                    {{ rubricReview.decision_notice }} Use the final decision section below to save these scores.
                                </p>
                            </section>

                            <section v-if="activeSection === 'eligibility'" class="order-2 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Decision Support
                                </p>
                                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                                    DSS compares the applicant's profile with the program's published criteria to highlight fit and items to check. It supports your review; it never approves or rejects an applicant.
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
                                                    row.document
                                                        ? 'bg-slate-100 text-slate-700'
                                                        : row.required ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500',
                                                ]"
                                            >
                                                <i :class="row.document ? 'fa-solid fa-file-circle-check' : 'fa-regular fa-file'"></i>
                                            </span>

                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="font-bold text-slate-950">{{ row.name }}</p>
                                                    <span v-if="!row.required" class="rounded bg-slate-100 px-2 py-0.5 text-[0.65rem] font-bold uppercase text-slate-500">
                                                        Supporting file
                                                    </span>
                                                </div>
                                                <p v-if="row.document" class="mt-1 truncate text-xs text-slate-500">
                                                    {{ row.document.original_name }} - {{ formatFileSize(row.document.size) }} - {{ row.document.uploaded_at }}
                                                </p>
                                                <p v-else :class="['mt-1 text-xs font-semibold', row.required ? 'text-amber-700' : 'text-slate-500']">
                                                    {{ row.required ? 'Applicant has not uploaded this file' : 'Optional file not provided' }}
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
                                                    row.document
                                                        ? documentStatusClass(row.document.status)
                                                        : row.required ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500',
                                                ]"
                                            >
                                                {{ row.document ? labelFromKey(row.document.status || 'pending') : row.required ? 'Not uploaded' : 'Optional' }}
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
                                            Shared dates and participant results are managed from the Program Workspace. Record Passed or Failed once after the activity is complete.
                                            </p>
                                        </div>
                                        <a :href="`/provider/programs/${application.scholarship.id}/applications?workspace=schedule`" class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                                            Open program schedule
                                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>

                                <div v-if="schedules.length" class="grid gap-3">
                                    <details
                                        v-for="schedule in schedules"
                                        :key="schedule.id"
                                        class="group overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
                                    >
                                        <summary class="flex cursor-pointer list-none items-center gap-3 p-3.5 [&::-webkit-details-marker]:hidden">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-900 text-sm text-white">
                                                <i :class="scheduleTypeIcon(schedule.type)" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-amber-700">{{ scheduleTypeLabel(schedule.type) }}</p>
                                                    <h3 class="truncate text-sm font-bold text-slate-950">{{ schedule.title }}</h3>
                                                </div>
                                                <p class="mt-1 text-xs text-slate-500">{{ schedule.scheduled_label }} - {{ scheduleModeLabel(schedule.mode) }}</p>
                                            </div>
                                            <span :class="['hidden rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', scheduleStatusClass(schedule.status)]">{{ labelFromKey(schedule.status) }}</span>
                                            <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                        </summary>

                                        <div class="grid gap-3 border-t border-slate-200 p-3 text-sm lg:grid-cols-2">
                                            <div v-if="schedule.type === 'distribution'" class="rounded-md bg-emerald-50 px-3 py-2.5 ring-1 ring-emerald-200 lg:col-span-2">
                                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-emerald-800">Benefit package</p>
                                                <ScholarshipBenefitsPanel
                                                    v-if="application.scholarship?.benefits?.length || application.awarded_amount != null"
                                                    class="mt-2"
                                                    :benefits="application.scholarship?.benefits"
                                                    :cash-amount="application.awarded_amount"
                                                    compact
                                                />
                                                <p v-else class="mt-1 text-sm text-emerald-800">
                                                    {{ application.scholarship?.benefit_summary || 'Benefit details have not been listed yet.' }}
                                                </p>
                                            </div>
                                            <div v-if="schedule.venue || schedule.location_address" class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                                <p class="font-bold text-slate-800">{{ schedule.venue || 'Activity site' }}</p>
                                                <p v-if="schedule.location_address" class="mt-1 leading-5 text-slate-600">{{ schedule.location_address }}</p>
                                            </div>
                                            <a v-if="schedule.online_url" :href="schedule.online_url" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between rounded-md border border-sky-200 bg-sky-50 px-3 py-2.5 font-bold text-sky-800 hover:bg-sky-100">
                                                Open online access link
                                                <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                            </a>
                                            <p class="whitespace-pre-line rounded-md bg-slate-50 p-3 leading-6 text-slate-600 ring-1 ring-slate-200 lg:col-span-2">{{ schedule.instructions }}</p>

                                            <LeafletMapPreview
                                                v-if="schedule.latitude && schedule.longitude"
                                                class="lg:col-span-2"
                                                :latitude="schedule.latitude"
                                                :longitude="schedule.longitude"
                                                :title="schedule.venue || schedule.title"
                                                :marker-text="schedule.venue || schedule.title"
                                                height="10rem"
                                            />

                                            <div class="rounded-md bg-sky-50 px-3 py-2.5 text-xs font-bold text-sky-800 ring-1 ring-sky-200 lg:col-span-2">
                                                Applicant notified through the portal and email.
                                            </div>
                                        </div>

                                        <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    {{ schedule.type === 'distribution' ? 'Release status' : 'Result status' }}
                                                </p>
                                                <p class="mt-1 text-sm text-slate-600">
                                                    {{ labelFromKey(schedule.attendance_status || 'pending') }}. Results are updated from the Program Workspace.
                                                </p>
                                            </div>
                                            <a
                                                :href="`/provider/programs/${application.scholarship?.id}/applications?workspace=results`"
                                                class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                            >
                                                Open results list
                                            </a>
                                        </div>
                                    </details>
                                </div>

                                <div v-else class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center">
                                    <p class="text-sm font-bold text-slate-800">No schedule announced yet</p>
                                    <p class="mt-1 text-sm text-slate-500">Publish the shared stage from this program's applicant page.</p>
                                    <a
                                        :href="`/provider/programs/${application.scholarship?.id}/applications?workspace=schedule`"
                                        class="mt-4 inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                    >
                                        Set program schedule
                                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </a>
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
                                    <div class="flex min-w-0 items-center gap-4">
                                        <div class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-md bg-slate-950 text-lg font-bold text-white">
                                            <img
                                                v-if="application.applicant?.profile_photo_url"
                                                :src="application.applicant.profile_photo_url"
                                                :alt="`${application.applicant?.name || 'Applicant'} photo`"
                                                class="h-full w-full object-cover"
                                            >
                                            <span v-else>{{ applicantInitials }}</span>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Applicant profile</p>
                                            <h3 class="mt-2 text-xl font-bold text-slate-950">{{ application.applicant?.name || 'Applicant' }}</h3>
                                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                                                <span>{{ application.applicant?.email || 'Email not provided' }}</span>
                                                <span>{{ application.applicant?.contact_number || 'Contact not provided' }}</span>
                                            </div>
                                            <p v-if="application.applicant?.profile_photo_url" class="mt-2 text-xs leading-5 text-slate-500">
                                                The applicant photo is for reviewer reference and is not academic evidence.
                                            </p>
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
                                    Learning record
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
                                    Household, location, and support
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
                                    <div class="sm:col-span-2 rounded-md bg-slate-50 p-3">
                                        <dt class="font-semibold text-slate-500">Study support needed</dt>
                                        <dd class="mt-1 whitespace-pre-line font-bold leading-6 text-slate-950">{{ application.applicant?.support_needs || 'Not provided' }}</dd>
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
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Academic record</p>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">
                                            Grade evidence saved in the applicant profile, shown separately from this program's requirements.
                                        </p>
                                    </div>
                                    <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ applicantProfileProofs.length ? 'Record available' : 'No record' }}
                                    </span>
                                </div>

                                <div v-if="applicantProfileProofs.length" class="divide-y divide-slate-200">
                                    <article v-for="proof in applicantProfileProofs" :key="proof.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-700">
                                                <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
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
                                            View record
                                        </button>
                                    </article>
                                </div>
                                <p v-else class="p-5 text-sm leading-6 text-slate-600">
                                    No academic record is available from profile verification. Review only the documents required by this program.
                                </p>
                            </section>

                            <section v-if="activeSection === 'applicant'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Applicant preferences</p>
                                <div class="mt-3 grid gap-2 text-sm">
                                    <p class="whitespace-pre-line rounded-md bg-slate-50 p-3 leading-6 text-slate-600"><span class="font-bold text-slate-800">Goal:</span> {{ application.applicant?.scholarship_goal || 'Not provided' }}</p>
                                    <p class="whitespace-pre-line rounded-md bg-slate-50 p-3 leading-6 text-slate-600"><span class="font-bold text-slate-800">Scholarship types:</span> {{ application.applicant?.preferred_categories || 'Not provided' }}</p>
                                    <p class="whitespace-pre-line rounded-md bg-slate-50 p-3 leading-6 text-slate-600"><span class="font-bold text-slate-800">Preferred locations:</span> {{ application.applicant?.preferred_locations || 'Not provided' }}</p>
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
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Possible recipient commitments</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">A preview only. Explain and confirm the final agreement with the applicant after acceptance.</p>
                                <div class="mt-3 grid gap-2">
                                    <div v-for="section in providerContractSections" :key="section.label" class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                        <p class="font-bold text-slate-800">{{ section.label }}</p>
                                        <p class="mt-1 whitespace-pre-line leading-6 text-slate-600">{{ section.value }}</p>
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>

                    <nav
                        v-if="activePrimarySectionIndex >= 0"
                        class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm sm:flex-row sm:items-center sm:justify-between"
                        aria-label="Review step navigation"
                    >
                        <button
                            type="button"
                            :disabled="!previousPrimarySection"
                            class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:invisible"
                            @click="previousPrimarySection && (activeSection = previousPrimarySection.key)"
                        >
                            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                            {{ previousPrimarySection ? previousPrimarySection.label : 'Previous' }}
                        </button>

                        <p class="text-center text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                            Step {{ activePrimarySectionIndex + 1 }} of {{ primaryDetailSections.length }}
                        </p>

                        <button
                            v-if="nextPrimarySection"
                            type="button"
                            class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            @click="activeSection = nextPrimarySection.key"
                        >
                            Next: {{ nextPrimarySection.label }}
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </button>
                        <a
                            v-else
                            href="/provider/applications"
                            class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                        >
                            Back to applicants
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </a>
                    </nav>
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
