<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ApplicantReviewIdentityCard from '../components/ApplicantReviewIdentityCard.vue';
import ApplicantProfileProofModal from '../components/ApplicantProfileProofModal.vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import EligibilityConditionList from '../components/EligibilityConditionList.vue';
import ProviderDocumentReviewModal from '../components/ProviderDocumentReviewModal.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderProfileEvidencePanel from '../components/ProviderProfileEvidencePanel.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';
import RecipientAgreementSummary from '../components/RecipientAgreementSummary.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { decisionReasonOptions } from '../support/applicationDecisionReasons';
import { formatFileSize, labelFromKey as formatKeyLabel } from '../support/display';
import { showPortalToast } from '../support/portalToast';
import {
    applicationStatusClass as statusClass,
    documentStatusClass,
    eligibilityStatusClass,
    evidenceStatusClass as evidenceClass,
    profileVerificationStatusClass as profileVerificationClass,
    recommendationStatusClass as recommendationClass,
} from '../support/providerStatusStyles';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const isLoading = ref(true);
const updatingId = ref(null);
const documentUpdatingId = ref(null);
const errorMessage = ref('');
const application = ref(null);
const pageSearchParams = new URLSearchParams(window.location.search);
const requestedSection = pageSearchParams.get('section');
const requestedReturnTo = pageSearchParams.get('return_to');
const providerWorkspaceUrl = safeProviderUrl(window.portalUser?.provider_workspace_url);
const requestedWorkspaceUrl = safeProviderUrl(requestedReturnTo);
const detailWorkspacePath = requestedWorkspaceUrl.split('?')[0] || providerWorkspaceUrl;
const detailContext = {
    '/provider/workspaces/reviews': {
        eyebrow: 'Application reviewer workspace',
        title: 'Application verification record',
        description: 'Check applicant eligibility and supporting evidence before recording the screening result.',
        icon: 'fa-solid fa-magnifying-glass-chart',
        defaultSection: 'eligibility',
    },
    '/provider/workspaces/selection': {
        eyebrow: 'Selection officer workspace',
        title: 'Selection activity record',
        description: 'Review the completed selection stage and record the appropriate result.',
        icon: 'fa-solid fa-calendar-check',
        defaultSection: 'decision',
    },
    '/provider/workspaces/decisions': {
        eyebrow: 'Decision officer workspace',
        title: 'Final decision record',
        description: 'Review the completed application evidence and record the final scholarship outcome.',
        icon: 'fa-solid fa-gavel',
        defaultSection: 'decision',
    },
    '/provider/workspaces/recipients': {
        eyebrow: 'Recipient officer workspace',
        title: 'Recipient onboarding record',
        description: 'Review the selected applicant, agreement response, and current support status.',
        icon: 'fa-solid fa-user-shield',
        defaultSection: 'decision',
    },
}[detailWorkspacePath] ?? {
    eyebrow: 'Applicant workflow',
    title: 'Applicant record',
    description: 'Review one part of the applicant record at a time and continue through the appropriate workflow.',
    icon: 'fa-solid fa-user-check',
    defaultSection: 'applicant',
};
const normalizedRequestedSection = requestedSection === 'review'
    ? 'eligibility'
    : requestedSection === 'schedule'
        ? 'decision'
        : requestedSection;
const validSections = ['applicant', 'eligibility', 'documents', 'decision', 'history'];
const activeSection = ref(validSections.includes(normalizedRequestedSection)
    ? normalizedRequestedSection
    : detailContext.defaultSection);
const activeApplicantView = ref('profile');
const eligibilityPage = ref(1);
const rubricPage = ref(1);
const documentPage = ref(1);
const historyPage = ref(1);
const detailPageSize = 5;
const showDssDetails = ref(false);
const reviewForm = ref(emptyReviewForm());
const selectedDocument = ref(null);
const selectedProfileProof = ref(null);
const selectedReviewActionKey = ref('');
const documentReviewError = ref('');
const rubricScores = ref({});
const postDecisionSummary = ref(null);
const applicationNavigation = ref({
    position: 0,
    total: 0,
    previous_application: null,
    next_application: null,
});
const showCorrectionForm = ref(false);
const correctionMessage = ref('');
const correctionTargets = ref([]);
const isHandlingCorrection = ref(false);
const showBenefitTerminationForm = ref(false);
const isStoppingBenefits = ref(false);
const benefitTerminationForm = ref({ reason: '', explanation: '' });
const isVerifyingAcademicRecord = ref(false);
const isReviewingPhoto = ref(false);
const photoReviewError = ref('');
const reviewedAcademicScale = ref('');
const reviewedAcademicResult = ref('');
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
const detailSections = [
    ...primaryDetailSections,
    { key: 'history', label: 'History', icon: 'fa-solid fa-clock-rotate-left' },
];
const applicantDetailViews = [
    { key: 'profile', label: 'Profile', icon: 'fa-solid fa-graduation-cap' },
    { key: 'background', label: 'Background', icon: 'fa-solid fa-house-user' },
    { key: 'responses', label: 'Responses', icon: 'fa-solid fa-message' },
];
const scheduleTypeCatalog = [
    { value: 'exam', label: 'Exam', icon: 'fa-solid fa-clipboard-question' },
    { value: 'interview', label: 'Interview', icon: 'fa-solid fa-comments' },
];
const scheduleModeOptions = [
    { value: 'onsite', label: 'On-site' },
    { value: 'online', label: 'Online' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'provider_managed', label: 'Provider managed' },
];
const correctionTargetOptions = [
    { value: 'profile', label: 'Profile information', icon: 'fa-solid fa-user-pen' },
    { value: 'academic_record', label: 'Academic record', icon: 'fa-solid fa-graduation-cap' },
    { value: 'application_files', label: 'Application files', icon: 'fa-solid fa-file-arrow-up' },
    { value: 'other', label: 'Other information', icon: 'fa-solid fa-ellipsis' },
];
const benefitTerminationReasonOptions = decisionReasonOptions.filter((option) => [
    'procedure_not_followed',
    'recipient_obligations_not_met',
    'program_conditions_not_met',
    'unable_to_contact_recipient',
    'recipient_requested_end',
    'other',
].includes(option.value));

function safeProviderUrl(value) {
    if (!value) {
        return '';
    }

    try {
        const url = new URL(value, window.location.origin);

        if (url.origin !== window.location.origin || !url.pathname.startsWith('/provider/')) {
            return '';
        }

        return `${url.pathname}${url.search}${url.hash}`;
    } catch {
        return '';
    }
}

const applicationListUrl = computed(() => safeProviderUrl(requestedReturnTo)
    || providerWorkspaceUrl
    || '/provider/workspaces/reviews');

function applicationNavigationUrl(item) {
    if (!item?.url) {
        return applicationListUrl.value;
    }

    const url = new URL(item.url, window.location.origin);

    url.searchParams.set('return_to', applicationListUrl.value);

    return `${url.pathname}${url.search}${url.hash}`;
}
const negativeDecisionReasonOptions = decisionReasonOptions.filter((option) => [
    '',
    'missing_documents',
    'academic_requirement_not_met',
    'outside_eligibility',
    'formal_application_not_completed',
    'failed_exam',
    'failed_interview',
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
    benefits_terminated: 'Benefits stopped',
    for_exam: 'Meets exam eligibility',
    exam_completed: 'Exam completed',
    passed_exam: 'Passed exam',
    failed_exam: 'Failed exam',
    failed_interview: 'Failed interview',
};

const eligibilityCriteria = computed(() => application.value?.eligibility_breakdown?.criteria ?? []);
const paginatedEligibilityCriteria = computed(() => eligibilityCriteria.value.slice(
    (eligibilityPage.value - 1) * detailPageSize,
    eligibilityPage.value * detailPageSize,
));
const eligibilityPagination = computed(() => clientPagination(eligibilityCriteria.value.length, eligibilityPage.value));
const eligibilityConditionResults = computed(() => application.value?.eligibility_breakdown?.condition_results ?? []);
const dssCriteria = computed(() => application.value?.dss_breakdown?.criteria ?? []);
const dssComparison = computed(() => application.value?.dss_explanation?.comparison ?? {
    state: 'complete',
    label: 'Comparison complete',
    completeness: 100,
    met: 0,
    not_met: 0,
    missing: 0,
    manual_review: 0,
    not_applicable: 0,
});
const rubricReview = computed(() => application.value?.rubric_review ?? { criteria: [], completed: 0, total_criteria: 0 });
const paginatedRubricCriteria = computed(() => (rubricReview.value.criteria ?? []).slice(
    (rubricPage.value - 1) * detailPageSize,
    rubricPage.value * detailPageSize,
));
const rubricPagination = computed(() => clientPagination((rubricReview.value.criteria ?? []).length, rubricPage.value));
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
const workflow = computed(() => application.value?.workflow ?? {});
const currentWorkflowStage = computed(() => workflow.value.current_stage ?? 'screening');
const currentActivityType = computed(() => (
    ['exam', 'interview'].includes(currentWorkflowStage.value)
        ? currentWorkflowStage.value
        : null
));
const currentStageSchedule = computed(() => (
    currentActivityType.value
        ? schedules.value.find((schedule) => schedule.type === currentActivityType.value) ?? null
        : null
));
const activityResultLocked = computed(() => Boolean(
    currentActivityType.value && currentStageSchedule.value?.status !== 'completed',
));
const activityResultLockMessage = computed(() => {
    if (!activityResultLocked.value) {
        return '';
    }

    const activityLabel = scheduleTypeLabel(currentActivityType.value).toLowerCase();

    return currentStageSchedule.value
        ? `Mark the ${activityLabel} activity as completed before recording results.`
        : `Publish and complete the ${activityLabel} activity before recording results.`;
});
const programWorkspaceAction = computed(() => {
    const waitingType = currentActivityType.value;

    if (waitingType && !currentStageSchedule.value) {
        return {
            section: 'schedule',
            title: `${scheduleTypeLabel(waitingType)} schedule needs to be published`,
            description: 'Publish the shared date and instructions once for applicants who reached this stage.',
        };
    }

    return null;
});
const programWorkspaceUrl = computed(() => {
    const scholarshipId = application.value?.scholarship?.id;

    return scholarshipId
        ? `/provider/programs/${scholarshipId}/applications/${programWorkspaceAction.value?.section === 'schedule' ? 'activities' : 'review'}`
        : '/provider/applications/review';
});
const programActivityUrl = computed(() => {
    const scholarshipId = application.value?.scholarship?.id;

    return scholarshipId
        ? `/provider/programs/${scholarshipId}/applications/activities`
        : '/provider/applications/activities';
});
const applicantProfileProofs = computed(() => application.value?.applicant?.profile_proofs ?? []);
const academicProfileProof = computed(() => applicantProfileProofs.value.find(
    (proof) => proof.document_type === 'academic_record',
) ?? null);
const recentSchoolIdProfileProof = computed(() => applicantProfileProofs.value.find(
    (proof) => proof.document_type === 'recent_school_id',
) ?? null);
const schoolRecordProfileProof = computed(() => applicantProfileProofs.value.find(
    (proof) => proof.document_type === 'school_record',
) ?? null);
const achievementProfileProof = computed(() => applicantProfileProofs.value.find(
    (proof) => proof.document_type === 'achievement_evidence',
) ?? null);
const academicScanRequired = computed(() => Boolean(application.value?.applicant?.academic_scan_required));
const academicScanReady = computed(() => !academicScanRequired.value || academicProfileProof.value?.ocr_status === 'succeeded');
const reviewedAcademicResultIsNumeric = computed(() => ['percentage', 'grade_point'].includes(reviewedAcademicScale.value));
const reviewedAcademicResultReady = computed(() => {
    if (!reviewedAcademicScale.value) {
        return false;
    }

    if (!reviewedAcademicResultIsNumeric.value) {
        return true;
    }

    const result = Number(reviewedAcademicResult.value);
    const maximum = reviewedAcademicScale.value === 'grade_point' ? 5 : 100;

    return reviewedAcademicResult.value !== '' && Number.isFinite(result) && result > 0 && result <= maximum;
});
const applicantApplicationAnswers = computed(() => (
    Array.isArray(application.value?.application_answers)
        ? application.value.application_answers
        : []
).filter((answer) => answer?.prompt));
const applicantReviewFacts = computed(() => {
    const applicant = application.value?.applicant ?? {};

    return [
        { label: 'Birthdate', value: applicant.birthdate || 'Not provided' },
        { label: 'Age', value: applicant.age ? `${applicant.age} years old` : 'Not provided' },
        { label: 'Gender', value: applicant.gender ? labelFromKey(applicant.gender) : 'Not provided' },
        { label: 'Citizenship', value: applicant.citizenship_status ? labelFromKey(applicant.citizenship_status) : 'Not provided' },
    ];
});
const applicantIdentityFacts = computed(() => {
    const applicant = application.value?.applicant ?? {};
    const education = [
        applicant.education_level ? labelFromKey(applicant.education_level) : '',
        applicant.course_or_strand,
        applicant.year_level,
    ].filter(Boolean).join(' - ');

    return [
        { label: 'Education', value: education || 'Not provided' },
        { label: 'School', value: applicant.school || 'Not provided' },
        { label: 'Account managed by', value: applicant.account_managed_by ? labelFromKey(applicant.account_managed_by) : 'Not provided' },
    ];
});
const academicEvidenceState = computed(() => {
    const status = application.value?.applicant?.profile_verification_status;

    if (status === 'approved') {
        return 'verified';
    }

    if (status === 'rejected') {
        return 'needs_replacement';
    }

    return academicProfileProof.value ? 'document_supported' : 'self_declared';
});
const recentSchoolIdEvidenceState = computed(() => profileProofEvidenceState(
    recentSchoolIdProfileProof.value,
    true,
));
const schoolRecordEvidenceState = computed(() => profileProofEvidenceState(schoolRecordProfileProof.value));
const achievementEvidenceState = computed(() => profileProofEvidenceState(achievementProfileProof.value));
const profileEvidenceRows = computed(() => {
    const applicant = application.value?.applicant ?? {};
    const rows = [
        {
            key: 'academic_record',
            label: 'Academic record',
            detail: 'Supports the saved grades and grading period.',
            icon: 'fa-solid fa-file-lines',
            proof: academicProfileProof.value,
            state: academicEvidenceState.value,
        },
        {
            key: 'recent_school_id',
            label: 'Recent school ID',
            detail: 'Supports the applicant\'s current student identity.',
            icon: 'fa-solid fa-id-card',
            proof: recentSchoolIdProfileProof.value,
            state: recentSchoolIdEvidenceState.value,
        },
    ];

    if (schoolRecordProfileProof.value) {
        rows.push({
            key: 'school_record',
            label: 'Enrollment proof',
            detail: 'Supports the current school and enrollment details.',
            icon: 'fa-solid fa-school',
            proof: schoolRecordProfileProof.value,
            state: schoolRecordEvidenceState.value,
        });
    }

    if (applicant.achievements || achievementProfileProof.value) {
        rows.push({
            key: 'achievement_evidence',
            label: 'Achievement evidence',
            detail: 'Supports the achievement entered in the profile.',
            icon: 'fa-solid fa-award',
            proof: achievementProfileProof.value,
            state: achievementEvidenceState.value,
        });
    }

    return rows;
});
const canVerifyAcademicRecord = computed(() => (
    application.value?.applicant?.profile_verification_status === 'pending'
        && Boolean(academicProfileProof.value)
        && Boolean(recentSchoolIdProfileProof.value)
        && (academicScanReady.value || reviewedAcademicResultReady.value)
));
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
const documentReviewComplete = computed(() => {
    const readiness = application.value?.document_readiness;
    const required = Number(readiness?.required ?? 0);
    const accepted = Number(readiness?.accepted ?? 0);

    return required === 0 || accepted >= required;
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
const suggestedReviewActions = computed(() => {
    if (workflow.value.is_closed || ['withdrawn', 'complete'].includes(currentWorkflowStage.value)) {
        return [];
    }

    if (currentWorkflowStage.value === 'decision') {
        return [
            {
                key: 'selected',
                kind: 'final',
                outcome: 'selected',
                status: 'selected',
                reason: 'approved_for_award',
                note: 'Selected after completing the provider process.',
                label: 'Selected',
                description: 'Confirm this applicant as a scholarship recipient.',
                confirmLabel: 'Confirm selection',
                icon: 'fa-solid fa-award',
                tone: 'success',
            },
            {
                key: 'waitlisted',
                kind: 'final',
                outcome: 'waitlisted',
                status: 'waitlisted',
                reason: 'funds_limited',
                note: 'Kept as an alternate recipient if a slot becomes available.',
                label: 'Waitlisted',
                description: 'Keep this qualified applicant as an alternate recipient.',
                confirmLabel: 'Confirm waitlist',
                icon: 'fa-solid fa-list-ol',
            },
            {
                key: 'not_selected',
                kind: 'final',
                outcome: 'not_selected',
                status: 'not_selected',
                reason: '',
                note: 'The applicant was not selected after the provider process.',
                label: 'Not selected',
                description: 'Close the application and provide a clear reason.',
                confirmLabel: 'Confirm not selected',
                icon: 'fa-solid fa-circle-xmark',
                tone: 'danger',
                requiresReason: true,
            },
        ];
    }

    const stageLabel = workflow.value.current_stage_label ?? 'Current stage';
    const isScreening = currentWorkflowStage.value === 'screening';
    const passCopy = {
        screening: ['Pass pre-screening', 'The applicant meets the portal criteria and can continue.', 'Confirm pre-screening result'],
        formal_application: ['Formal application passed', 'The provider confirms the applicant completed this stage.', 'Confirm formal application'],
        exam: ['Passed exam', 'Record the provider-managed exam result.', 'Confirm exam result'],
        interview: ['Passed interview', 'Record the provider-managed interview result.', 'Confirm interview result'],
    }[currentWorkflowStage.value] ?? [`Passed ${stageLabel}`, 'Move the applicant to the next configured stage.', 'Confirm result'];
    const failCopy = {
        screening: ['Not qualified', 'End the application and explain which criterion was not met.'],
        formal_application: ['Formal application not completed', 'Close the application with a clear provider note.'],
        exam: ['Did not pass exam', 'Record the provider-managed exam result.'],
        interview: ['Did not pass interview', 'Record the provider-managed interview result.'],
    }[currentWorkflowStage.value] ?? [`Did not pass ${stageLabel}`, 'Close this application stage with a clear reason.'];

    return [
        {
            key: 'passed',
            kind: 'stage',
            result: 'passed',
            status: 'passed',
            reason: '',
            note: `${stageLabel} passed.`,
            label: passCopy[0],
            description: passCopy[1],
            confirmLabel: passCopy[2],
            icon: 'fa-solid fa-circle-check',
            tone: 'success',
            blocked: (isScreening && !documentReviewComplete.value) || activityResultLocked.value,
            blockedSection: isScreening && !documentReviewComplete.value ? 'documents' : 'decision',
            blockedMessage: isScreening && !documentReviewComplete.value ? documentReviewBlockMessage.value : activityResultLockMessage.value,
        },
        {
            key: 'not_passed',
            kind: 'stage',
            result: 'not_passed',
            status: 'not_passed',
            reason: '',
            note: `${stageLabel} was not passed.`,
            label: failCopy[0],
            description: failCopy[1],
            confirmLabel: 'Confirm not passed',
            icon: 'fa-solid fa-circle-xmark',
            tone: 'danger',
            requiresReason: true,
            blocked: activityResultLocked.value,
            blockedSection: 'decision',
            blockedMessage: activityResultLockMessage.value,
        },
    ];
});
const selectedReviewAction = computed(() => (
    suggestedReviewActions.value.find((action) => action.key === selectedReviewActionKey.value) ?? null
));
const reviewSubmitLabel = computed(() => {
    if (updatingId.value === application.value?.id) {
        return 'Saving...';
    }

    if (selectedReviewAction.value) {
        return selectedReviewAction.value.confirmLabel;
    }

    return 'Save notes and scores';
});
const completedStageMessage = computed(() => workflow.value.final_outcome_label
    ? application.value?.status === 'benefits_terminated'
        ? 'The original selection remains in history, but future scholarship benefits have been stopped.'
        : `Final outcome: ${workflow.value.final_outcome_label}. No further selection decision is required.`
    : 'This application has no pending provider action.');
const decisionPanelTitle = computed(() => {
    if (workflow.value.application_state === 'withdrawn') {
        return 'Application withdrawn';
    }

    if (currentWorkflowStage.value === 'decision') {
        return 'Record the final outcome';
    }

    return `Record the ${String(workflow.value.current_stage_label ?? 'current stage').toLowerCase()} result`;
});
const decisionPanelDescription = computed(() => {
    if (workflow.value.application_state === 'withdrawn') {
        return 'Keep the withdrawal reason for your records. This application no longer needs review.';
    }

    if (currentWorkflowStage.value === 'decision') {
        return 'Choose Selected, Waitlisted, or Not selected after all configured stages are complete.';
    }

    return 'Use one result to move the applicant forward or close the application at this stage.';
});
const canRequestCorrection = computed(() => application.value
    && !workflow.value.is_closed
    && !['requested', 'submitted'].includes(application.value.correction_status));
const canStopBenefits = computed(() => application.value
    && workflow.value.final_outcome === 'selected'
    && ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'].includes(application.value.status));
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
const paginatedApplicationFileRows = computed(() => (
    applicationFileRows.value.slice(
        (documentPage.value - 1) * detailPageSize,
        documentPage.value * detailPageSize,
    )
));
const documentPagination = computed(() => clientPagination(applicationFileRows.value.length, documentPage.value));
const paginatedTimeline = computed(() => (
    timeline.value.slice(
        (historyPage.value - 1) * detailPageSize,
        historyPage.value * detailPageSize,
    )
));
const historyPagination = computed(() => clientPagination(timeline.value.length, historyPage.value));

function clientPagination(total, requestedPage) {
    const lastPage = Math.max(1, Math.ceil(total / detailPageSize));
    const currentPage = Math.min(Math.max(Number(requestedPage) || 1, 1), lastPage);
    const from = total ? ((currentPage - 1) * detailPageSize) + 1 : 0;

    return {
        current_page: currentPage,
        last_page: lastPage,
        per_page: detailPageSize,
        total,
        from,
        to: total ? Math.min(from + detailPageSize - 1, total) : 0,
    };
}

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

function eligibilityStatusLabel(criterion) {
    if (criterion.status === 'pass') {
        return 'Met';
    }

    if (criterion.status === 'fail') {
        return 'Not met';
    }

    if (criterion.status === 'missing') {
        return 'Missing information';
    }

    if (criterion.comparison_mode === 'manual_review') {
        return 'Manual review';
    }

    return 'No restriction';
}

function scheduleTypeLabel(type) {
    return scheduleTypeCatalog.find((option) => option.value === type)?.label ?? labelFromKey(type);
}

function scheduleModeLabel(mode) {
    return scheduleModeOptions.find((option) => option.value === mode)?.label ?? labelFromKey(mode);
}

function profileVerificationLabel(status) {
    return {
        approved: 'Academic record verified',
        rejected: 'Academic record needs replacement',
        pending: 'Academic review pending',
        unsubmitted: 'Academic record not verified',
    }[status] ?? labelFromKey(status || 'unsubmitted');
}

function evidenceLabel(status) {
    return {
        verified: 'Verified',
        document_supported: 'Document submitted',
        needs_replacement: 'Needs replacement',
        missing: 'Missing',
        not_required: 'Not required',
        self_declared: 'Self-declared',
    }[status] ?? 'Self-declared';
}

function profileProofEvidenceState(proof, verifiedWithAcademicReview = false) {
    const proofStatus = proof?.status;
    const profileStatus = application.value?.applicant?.profile_verification_status;

    if (['rejected', 'needs_replacement'].includes(proofStatus)
        || (verifiedWithAcademicReview && profileStatus === 'rejected')) {
        return 'needs_replacement';
    }

    if (proofStatus === 'approved'
        || (verifiedWithAcademicReview && profileStatus === 'approved')) {
        return 'verified';
    }

    return proof ? 'document_supported' : 'missing';
}

function labelFromKey(value) {
    if (customStatusLabels[value]) {
        return customStatusLabels[value];
    }

    return formatKeyLabel(value);
}

function eligibilityValueLabel(value, fallback) {
    const text = String(value ?? '').trim();

    return text ? labelFromKey(text) : fallback;
}

function correctionTargetLabel(target) {
    return correctionTargetOptions.find((option) => option.value === target)?.label ?? labelFromKey(target);
}

function resetCorrectionForm() {
    showCorrectionForm.value = false;
    correctionMessage.value = '';
    correctionTargets.value = [];
    errorMessage.value = '';
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
    eligibilityPage.value = 1;
    rubricPage.value = 1;
    documentPage.value = 1;
    historyPage.value = 1;
    reviewedAcademicScale.value = payload?.applicant?.grading_scale ?? '';
    reviewedAcademicResult.value = payload?.applicant?.gwa ?? '';
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

function resetBenefitTerminationForm() {
    showBenefitTerminationForm.value = false;
    benefitTerminationForm.value = { reason: '', explanation: '' };
    errorMessage.value = '';
}

function decisionReasonLabel(reason) {
    return decisionReasonOptions.find((option) => option.value === reason)?.label ?? statusLabel(reason);
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

async function verifyApplicantAcademicRecord() {
    if (!application.value || !canVerifyAcademicRecord.value || isVerifyingAcademicRecord.value) {
        return;
    }

    const confirmed = await requestConfirmation({
        title: 'Verify this academic record?',
        message: 'Confirm that the saved academic result matches the uploaded record. This verifies the applicant profile across the portal, but does not approve this scholarship application.',
        confirmLabel: 'Verify record',
    });

    if (!confirmed) {
        return;
    }

    isVerifyingAcademicRecord.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(
            `/provider/applications/${application.value.id}/profile-verification`,
            {
                academic_grading_scale: reviewedAcademicScale.value,
                academic_result: reviewedAcademicResultIsNumeric.value ? reviewedAcademicResult.value : null,
            },
            { portalToast: false },
        );

        applyApplication(response.data.application);
        showPortalToast({
            title: 'Academic record verified',
            message: response.data.message,
        });
    } catch (error) {
        const message = error.response?.data?.errors?.verification?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to verify the academic record.';

        errorMessage.value = message;
        showPortalToast({
            type: 'error',
            title: 'Verification failed',
            message,
        });
    } finally {
        isVerifyingAcademicRecord.value = false;
    }
}

async function updatePhotoReview(action, reason = '') {
    if (!application.value || isReviewingPhoto.value) {
        return;
    }

    isReviewingPhoto.value = true;
    photoReviewError.value = '';

    try {
        const response = await window.axios.patch(
            `/provider/applications/${application.value.id}/profile-photo-review`,
            { action, reason: reason || null },
            { portalToast: false },
        );

        applyApplication(response.data.application);
        showPortalToast({
            title: action === 'approve' ? 'Photo checked' : 'Replacement requested',
            message: response.data.message,
        });
    } catch (error) {
        photoReviewError.value = error.response?.data?.errors?.reason?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to update the 2x2 photo review.';
    } finally {
        isReviewingPhoto.value = false;
    }
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

function statusConfirmation(action) {
    const applicantName = application.value?.applicant?.name || 'This applicant';

    if (!action) {
        return null;
    }

    return {
        title: `${action.confirmLabel}?`,
        message: action.kind === 'final'
            ? `${applicantName} will receive this final outcome and your note.`
            : `${applicantName} will receive this stage result and the application will update automatically.`,
        confirmLabel: action.confirmLabel,
        tone: action.tone === 'danger' ? 'danger' : 'default',
    };
}

async function loadApplication() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/applications/${applicationId}/data`);

        applyApplication(response.data.application);
        applicationNavigation.value = response.data.application_navigation ?? applicationNavigation.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load application details.';
    } finally {
        isLoading.value = false;
    }
}

async function requestApplicationCorrection() {
    const message = correctionMessage.value.trim();

    if (!application.value || !correctionTargets.value.length || message.length < 5) {
        errorMessage.value = !correctionTargets.value.length
            ? 'Choose what the applicant needs to update.'
            : 'Tell the applicant what needs to be corrected.';
        return;
    }

    isHandlingCorrection.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/applications/${application.value.id}/correction`, {
            action: 'request',
            message,
            targets: correctionTargets.value,
        });

        applyApplication(response.data.application);
        resetCorrectionForm();
        showPortalToast({
            title: 'Correction requested',
            message: response.data.message,
        });
    } catch (error) {
        errorMessage.value = error.response?.data?.errors?.message?.[0]
            ?? error.response?.data?.errors?.targets?.[0]
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

    if (currentWorkflowStage.value === 'screening' && rubricReview.value.criteria?.length && !rubricDraftSummary.value.isComplete) {
        activeSection.value = 'decision';
        errorMessage.value = 'Score every provider review criterion before saving the review.';
        return;
    }

    if (selectedReviewAction.value?.blocked) {
        activeSection.value = selectedReviewAction.value.blockedSection || 'decision';
        errorMessage.value = selectedReviewAction.value.blockedMessage || 'Complete the required review steps before continuing.';
        return;
    }

    if (selectedReviewAction.value?.requiresReason && !reviewForm.value.decisionReason) {
        errorMessage.value = 'Select a decision reason before saving a negative decision.';
        return;
    }

    if (selectedReviewAction.value) {
        const confirmationOptions = statusConfirmation(selectedReviewAction.value);

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
        let response;

        if (selectedReviewAction.value?.kind === 'stage') {
            response = await window.axios.patch(`/provider/applications/${application.value.id}/stages/${currentWorkflowStage.value}/result`, {
                result: selectedReviewAction.value.result,
                decision_reason: reviewForm.value.decisionReason || null,
                notes: reviewForm.value.reviewNotes,
                rubric_scores: completedRubricScores,
            });
        } else if (selectedReviewAction.value?.kind === 'final') {
            response = await window.axios.patch(`/provider/applications/${application.value.id}/final-outcome`, {
                outcome: selectedReviewAction.value.outcome,
                decision_reason: reviewForm.value.decisionReason || null,
                notes: reviewForm.value.reviewNotes,
            });
        } else {
            response = await window.axios.patch(`/provider/applications/${application.value.id}/status`, {
                status: application.value.status,
                decision_reason: application.value.decision_reason,
                review_notes: reviewForm.value.reviewNotes,
                rubric_scores: completedRubricScores,
            });
        }

        applyApplication(response.data.application);

        if (completedReviewAction) {
            postDecisionSummary.value = {
                actionLabel: completedReviewAction.label,
                message: response.data.message || 'The applicant decision was saved.',
                remainingCount: Number(response.data.review_navigation?.remaining_count ?? 0),
                stageLabel: response.data.review_navigation?.stage_label || 'current stage',
                listUrl: applicationListUrl.value,
                nextApplication: response.data.review_navigation?.next_application ?? null,
            };
        }
    } catch (handledError) {
        void handledError;
    } finally {
        updatingId.value = null;
    }
}

async function stopBenefits() {
    if (!application.value || !canStopBenefits.value || isStoppingBenefits.value) {
        return;
    }

    const reason = benefitTerminationForm.value.reason;
    const explanation = benefitTerminationForm.value.explanation.trim();

    if (!reason) {
        errorMessage.value = 'Select why future benefits are being stopped.';
        return;
    }

    if (explanation.length < 10) {
        errorMessage.value = 'Add a short explanation of at least 10 characters for the applicant and audit record.';
        return;
    }

    const confirmed = await requestConfirmation({
        title: 'Stop future scholarship benefits?',
        message: `This will notify ${application.value.applicant?.name || 'the applicant'} and record the reason in application history. Benefits already released are not reversed.`,
        confirmLabel: 'Stop future benefits',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    isStoppingBenefits.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(
            `/provider/applications/${application.value.id}/status`,
            {
                status: 'benefits_terminated',
                decision_reason: reason,
                outcome_notes: explanation,
            },
            { portalToast: false },
        );

        applyApplication(response.data.application);
        resetBenefitTerminationForm();
        showPortalToast({
            title: 'Benefits stopped',
            message: 'The applicant was notified and the reason was added to application history.',
        });
    } catch (error) {
        const message = error.response?.data?.errors?.decision_reason?.[0]
            ?? error.response?.data?.errors?.outcome_notes?.[0]
            ?? error.response?.data?.errors?.status?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to stop future benefits.';

        errorMessage.value = message;
        showPortalToast({ type: 'error', title: 'Benefits not changed', message });
    } finally {
        isStoppingBenefits.value = false;
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
    <main class="provider-shell">
        <ProviderSidebar />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="provider-page">
            <div class="provider-container">
                <nav class="mb-4 flex min-w-0 items-center gap-2 text-sm" aria-label="Breadcrumb">
                    <a :href="applicationListUrl" class="font-bold text-slate-600 transition hover:text-slate-950"><i class="fa-solid fa-arrow-left mr-2 text-xs" aria-hidden="true"></i>Back to workspace</a>
                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-400" aria-hidden="true"></i>
                    <span class="max-w-72 truncate font-semibold text-slate-950">{{ application?.applicant?.name || 'Applicant record' }}</span>
                </nav>

                <ProviderPageHeader
                    :eyebrow="detailContext.eyebrow"
                    :title="detailContext.title"
                    :description="detailContext.description"
                    :icon="detailContext.icon"
                    :show-role-guide="false"
                >
                    <template v-if="application" #meta>
                        <span><i class="fa-solid fa-user mr-2 text-slate-400" aria-hidden="true"></i>{{ application.applicant?.name || 'Applicant record' }}</span>
                        <span><i class="fa-solid fa-graduation-cap mr-2 text-slate-400" aria-hidden="true"></i>{{ application.scholarship?.title || 'Scholarship program' }}</span>
                        <span><i class="fa-regular fa-clock mr-2 text-slate-400" aria-hidden="true"></i>{{ application.submitted_at || 'Submission date unavailable' }}</span>
                    </template>
                    <template v-if="application" #actions>
                        <div class="flex flex-wrap items-center gap-2">
                            <span :class="['inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold uppercase', statusClass(application.status)]">
                                <i class="fa-solid fa-circle-dot" aria-hidden="true"></i>{{ workflow.application_state_label || statusLabel(application.status) }}
                            </span>
                            <button
                                v-if="activeSection !== 'decision'"
                                type="button"
                                class="inline-flex items-center justify-center gap-2 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="activeSection = 'decision'"
                            >
                                Open current action
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </button>
                        </div>
                    </template>
                </ProviderPageHeader>

                <ProviderWorkspaceState v-if="isLoading" class="mt-4" title="Loading applicant record" message="Preparing the requested workflow record and supporting evidence." />

                <ProviderWorkspaceState v-else-if="errorMessage && !application" class="mt-4" tone="error" title="Applicant record is unavailable" :message="errorMessage" />

                <div v-else-if="application" class="mt-4 space-y-4">
                    <p v-if="errorMessage" class="border border-rose-300 border-l-4 bg-rose-50 p-4 text-sm font-semibold text-rose-800 shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        {{ errorMessage }}
                    </p>
                    <section class="overflow-hidden border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                            <p class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-folder-open text-slate-400" aria-hidden="true"></i>Applicant record menu</p>
                            <div v-if="applicationNavigation.total > 1" class="flex items-center gap-1 text-xs font-bold text-slate-600">
                                <a v-if="applicationNavigation.previous_application" :href="applicationNavigationUrl(applicationNavigation.previous_application)" class="grid h-8 w-8 place-items-center border border-slate-300 bg-white hover:bg-slate-100" aria-label="Previous applicant"><i class="fa-solid fa-chevron-left text-[10px]" aria-hidden="true"></i></a>
                                <span class="px-2">Record {{ applicationNavigation.position }} of {{ applicationNavigation.total }}</span>
                                <a v-if="applicationNavigation.next_application" :href="applicationNavigationUrl(applicationNavigation.next_application)" class="grid h-8 w-8 place-items-center border border-slate-300 bg-white hover:bg-slate-100" aria-label="Next applicant"><i class="fa-solid fa-chevron-right text-[10px]" aria-hidden="true"></i></a>
                            </div>
                        </div>
                        <nav class="flex overflow-x-auto" aria-label="Applicant record sections">
                            <button
                                v-for="section in detailSections"
                                :key="section.key"
                                type="button"
                                :aria-current="activeSection === section.key ? 'page' : undefined"
                                :class="[
                                    'inline-flex min-w-32 flex-1 items-center justify-center gap-2 border-r border-slate-200 px-3 py-3 text-sm font-bold transition last:border-r-0',
                                    activeSection === section.key
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-700 hover:bg-slate-50 hover:text-slate-950',
                                ]"
                                @click="activeSection = section.key"
                            >
                                <i :class="section.icon" aria-hidden="true"></i>{{ section.label }}
                            </button>
                        </nav>
                    </section>

                    <div class="block">
                        <div v-if="activeSection !== 'applicant'" class="flex flex-col gap-5">
                            <section v-if="activeSection === 'eligibility'" class="order-2 overflow-hidden border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                                <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Eligibility check</p>
                                        <h3 class="mt-2 text-xl font-bold text-slate-950">Published criteria comparison</h3>
                                    </div>
                                    <div class="shrink-0 sm:text-right">
                                        <p class="text-2xl font-bold text-slate-950">{{ application.eligibility_breakdown?.score ?? 0 }}%</p>
                                        <p class="text-xs font-bold text-slate-500">{{ application.eligibility_breakdown?.label || 'Needs review' }}</p>
                                    </div>
                                </div>

                                <div v-if="eligibilityCriteria.length" class="flex flex-wrap gap-x-5 gap-y-2 border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold text-slate-600">
                                    <span><strong class="text-emerald-700">{{ dssComparison.met }}</strong> met</span>
                                    <span><strong class="text-rose-700">{{ dssComparison.not_met }}</strong> not met</span>
                                    <span><strong class="text-amber-700">{{ dssComparison.missing }}</strong> missing</span>
                                    <span><strong class="text-slate-700">{{ dssComparison.manual_review }}</strong> manual review</span>
                                    <span><strong class="text-slate-700">{{ dssComparison.not_applicable }}</strong> unrestricted</span>
                                </div>

                                <div v-if="eligibilityConditionResults.length" class="border-b border-slate-200 p-5">
                                    <p class="text-sm font-bold text-slate-950">Required condition checks</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">Automatic results come from the submitted profile snapshot. Review the remaining conditions before deciding.</p>
                                    <EligibilityConditionList class="mt-3" :conditions="eligibilityConditionResults" audience="reviewer" />
                                </div>

                                <div v-if="eligibilityCriteria.length" class="portal-table-scroll">
                                    <table class="portal-data-table min-w-[68rem] table-fixed">
                                        <caption class="sr-only">Published eligibility criteria comparison</caption>
                                        <colgroup><col class="w-[29%]"><col class="w-[23%]"><col class="w-[28%]"><col class="w-[20%]"></colgroup>
                                        <thead><tr><th scope="col">Criterion</th><th scope="col">Applicant value</th><th scope="col">Program rule</th><th scope="col">Result</th></tr></thead>
                                        <tbody>
                                            <tr v-for="criterion in paginatedEligibilityCriteria" :key="criterion.key">
                                                <td><p class="font-semibold text-slate-950"><i :class="[eligibilityStatusIcon(criterion.status), eligibilityStatusTextClass(criterion.status), 'mr-2 w-4 text-center']" aria-hidden="true"></i>{{ criterion.label }}</p><p class="mt-0.5 text-xs text-slate-500">{{ criterion.note }}</p></td>
                                                <td><p class="font-semibold text-slate-800">{{ eligibilityValueLabel(criterion.student_value, 'Not provided') }}</p><p v-if="criterion.equivalence?.applicant" class="mt-0.5 text-xs text-slate-500">Equivalent: {{ criterion.equivalence.applicant }}</p></td>
                                                <td><p class="font-semibold text-slate-800">{{ eligibilityValueLabel(criterion.requirement, 'Open to all') }}</p><p v-if="criterion.equivalence?.requirement" class="mt-0.5 text-xs text-slate-500">Equivalent: {{ criterion.equivalence.requirement }}</p></td>
                                                <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', eligibilityStatusClass(criterion.status)]"><i :class="eligibilityStatusIcon(criterion.status)" aria-hidden="true"></i>{{ eligibilityStatusLabel(criterion) }}</span><p v-if="criterion.equivalence?.notice" class="mt-1 text-xs text-slate-500">{{ criterion.equivalence.notice }}</p></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <ProviderPagination v-if="eligibilityCriteria.length" :pagination="eligibilityPagination" item-label="criteria" @change="eligibilityPage = $event" />
                                <p v-else class="p-5 text-sm leading-6 text-slate-600">This program does not have structured eligibility rules to compare.</p>

                                <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="text-sm leading-6 text-slate-600">
                                        {{ application.dss_explanation?.next_action || 'Review differences and supporting documents before deciding.' }}
                                    </p>
                                    <button
                                        type="button"
                                        class="inline-flex w-fit shrink-0 items-center gap-2 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:border-slate-500 hover:bg-slate-100"
                                        @click="showDssDetails = true"
                                    >
                                        View calculation
                                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-amber-700" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </section>

                            <section v-if="activeSection === 'eligibility' && application.exam" class="provider-panel order-3 overflow-hidden">
                                <div class="grid sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-center">
                                    <div class="flex h-36 items-center justify-center border-b border-slate-200 bg-slate-50 p-4 sm:border-b-0 sm:border-r">
                                        <img :src="application.exam.image_url" :alt="application.exam.title" class="h-full w-full object-contain">
                                    </div>
                                    <div class="p-4">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Provider-managed exam</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ application.exam.title }}</h3>
                                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-600">
                                            <span>{{ labelFromKey(application.exam.delivery_mode) }}</span>
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">Your organization conducts and grades this exam outside the portal.</p>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'decision'" class="order-5 border border-slate-300 bg-white p-5 shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
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
                                            {{ application.status === 'benefits_terminated' ? statusLabel(application.status) : (workflow.current_stage_label || statusLabel(application.status)) }}
                                        </span>
                                    </div>
                                </div>

                                <div
                                    v-if="currentActivityType && !postDecisionSummary"
                                    class="mt-5 flex flex-col gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-900 text-amber-300">
                                            <i :class="currentActivityType === 'exam' ? 'fa-solid fa-clipboard-question' : 'fa-solid fa-comments'" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Program activity</p>
                                            <p class="mt-1 text-sm font-bold text-slate-950">
                                                {{ currentStageSchedule ? currentStageSchedule.title : `${scheduleTypeLabel(currentActivityType)} details are not published yet` }}
                                            </p>
                                            <p class="mt-1 text-xs leading-5 text-slate-600">
                                                <template v-if="currentStageSchedule">
                                                    {{ currentStageSchedule.scheduled_label }} &middot; {{ scheduleModeLabel(currentStageSchedule.mode) }}
                                                </template>
                                                <template v-else>
                                                    Publish this once for all applicants who reached the {{ scheduleTypeLabel(currentActivityType).toLowerCase() }} stage.
                                                </template>
                                            </p>
                                        </div>
                                    </div>
                                    <a
                                        :href="programActivityUrl"
                                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                    >
                                        {{ currentStageSchedule ? 'Manage activity' : 'Set activity' }}
                                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </a>
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
                                            <div v-if="application.correction_targets?.length" class="mt-2 flex flex-wrap gap-1.5">
                                                <span v-for="target in application.correction_targets" :key="target" class="rounded-md bg-white/80 px-2 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                                    {{ correctionTargetLabel(target) }}
                                                </span>
                                            </div>
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

                                <div v-if="!postDecisionSummary" class="mt-5">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Available actions</p>
                                        <button
                                            v-if="canRequestCorrection"
                                            type="button"
                                            class="inline-flex w-fit items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            @click="showCorrectionForm = true"
                                        >
                                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                            Request correction
                                        </button>
                                    </div>
                                    <button
                                        v-if="currentWorkflowStage === 'screening' && !documentReviewComplete"
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
                                    <div
                                        v-if="activityResultLocked"
                                        class="mt-3 flex flex-col gap-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <span class="flex items-start gap-3">
                                            <i class="fa-solid fa-lock mt-0.5 text-amber-700" aria-hidden="true"></i>
                                            <span>
                                                <strong class="block">Results are locked</strong>
                                                <span class="mt-0.5 block text-xs leading-5 text-amber-800">{{ activityResultLockMessage }}</span>
                                            </span>
                                        </span>
                                        <a :href="programActivityUrl" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-amber-950 transition hover:bg-amber-100">
                                            Manage activity
                                            <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                    <div v-if="suggestedReviewActions.length" class="mt-3 grid gap-2">
                                        <button
                                            v-for="action in suggestedReviewActions"
                                            :key="action.key"
                                            type="button"
                                            :disabled="action.blocked"
                                            :class="[
                                                'group flex items-center gap-3 rounded-md border p-3 text-left transition',
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
                                            <span class="min-w-0">
                                                <span :class="['block font-bold', isSelectedReviewAction(action) ? 'text-white' : 'text-slate-950']">
                                                    {{ action.label }}
                                                </span>
                                                <span :class="['mt-0.5 block text-xs leading-5', isSelectedReviewAction(action) ? 'text-slate-300' : 'text-slate-600']">
                                                    {{ action.description }}
                                                </span>
                                            </span>
                                        </button>
                                    </div>
                                    <div v-else class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                        {{ completedStageMessage }}
                                    </div>
                                </div>

                                <div v-if="application.recipient_agreement" class="mt-5">
                                    <RecipientAgreementSummary :agreement="application.recipient_agreement" compact />
                                    <div
                                        v-if="application.recipient_agreement.response_note"
                                        class="border-x border-b border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-700"
                                    >
                                        <strong>Applicant note:</strong> {{ application.recipient_agreement.response_note }}
                                    </div>
                                </div>

                                <div
                                    v-if="canStopBenefits || application.status === 'benefits_terminated'"
                                    class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white"
                                >
                                    <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span :class="[
                                                'grid h-10 w-10 shrink-0 place-items-center rounded-md',
                                                application.status === 'benefits_terminated'
                                                    ? 'bg-rose-100 text-rose-700'
                                                    : 'bg-slate-100 text-slate-700',
                                            ]">
                                                <i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="font-bold text-slate-950">Recipient support</p>
                                                    <span :class="[
                                                        'rounded px-2 py-1 text-[10px] font-bold uppercase',
                                                        application.status === 'benefits_terminated'
                                                            ? 'bg-rose-100 text-rose-700'
                                                            : 'bg-emerald-100 text-emerald-700',
                                                    ]">
                                                        {{ application.status === 'benefits_terminated' ? 'Stopped' : 'Active' }}
                                                    </span>
                                                </div>
                                                <p class="mt-1 text-sm leading-6 text-slate-600">
                                                    {{ application.status === 'benefits_terminated' ? 'Future benefits stopped' : 'Recipient support is active' }}
                                                </p>
                                                <p v-if="application.status === 'benefits_terminated'" class="mt-1 text-sm leading-6 text-slate-700">
                                                    {{ application.outcome_notes || 'The provider ended future support for this recipient.' }}
                                                </p>
                                                <p v-if="application.status === 'benefits_terminated' && application.decision_reason" class="mt-2 text-xs font-bold text-rose-800">
                                                    Reason: {{ decisionReasonLabel(application.decision_reason) }}
                                                </p>
                                            </div>
                                        </div>
                                        <button
                                            v-if="canStopBenefits"
                                            type="button"
                                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
                                            @click="showBenefitTerminationForm = true"
                                        >
                                            <i class="fa-solid fa-ban text-xs" aria-hidden="true"></i>
                                            Stop future benefits
                                        </button>
                                    </div>

                                </div>

                                <div class="mt-5 grid gap-4 border-t border-slate-200 pt-5 md:grid-cols-2">
                                    <div v-if="selectedReviewAction?.requiresReason">
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
                                                            {{ postDecisionSummary.remainingCount }} other applicant{{ postDecisionSummary.remainingCount === 1 ? '' : 's' }} still need{{ postDecisionSummary.remainingCount === 1 ? 's' : '' }} {{ postDecisionSummary.stageLabel.toLowerCase() }} review.
                                                        </template>
                                                        <template v-else>
                                                            No other applicants are waiting at this stage.
                                                        </template>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-4 flex flex-col gap-2 border-t border-emerald-200 pt-4 sm:flex-row sm:flex-wrap">
                                            <a
                                                v-if="postDecisionSummary.nextApplication"
                                                :href="applicationNavigationUrl(postDecisionSummary.nextApplication)"
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
                                                v-if="!postDecisionSummary.remainingCount && programWorkspaceAction"
                                                :href="programWorkspaceUrl"
                                                class="inline-flex items-center justify-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-950 transition hover:bg-amber-100 sm:ml-auto"
                                            >
                                                Continue to {{ scheduleTypeLabel(currentActivityType) }} setup
                                                <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'decision' && !postDecisionSummary && rubricReview.criteria?.length" class="order-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                                <div class="border-b border-slate-200 px-5 py-4">
                                    <h3 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fa-solid fa-chart-simple text-sm text-slate-500" aria-hidden="true"></i>Consistent applicant scoring</h3>
                                    <p class="mt-1 text-sm text-slate-500">Score every provider criterion from 0 to 100 before saving the review or decision.</p>
                                </div>

                                <div class="border-b border-slate-200 bg-slate-50 px-5 py-3">
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

                                <div class="portal-table-scroll">
                                    <table class="portal-data-table min-w-[48rem] table-fixed">
                                        <caption class="sr-only">Provider review rubric</caption>
                                        <colgroup><col class="w-[58%]"><col class="w-[17%]"><col class="w-[25%]"></colgroup>
                                        <thead><tr><th scope="col">Criterion</th><th scope="col">Weight</th><th scope="col">Score</th></tr></thead>
                                        <tbody>
                                            <tr v-for="criterion in paginatedRubricCriteria" :key="criterion.key">
                                                <td><p class="font-semibold text-slate-950"><i class="fa-solid fa-asterisk mr-2 text-[0.55rem] text-rose-600" aria-hidden="true"></i>{{ criterion.label }}</p><p v-if="criterion.guidance" class="mt-0.5 text-xs text-slate-500">{{ criterion.guidance }}</p></td>
                                                <td class="font-bold text-slate-800">{{ criterion.weight }}%</td>
                                                <td><label :for="`rubric-score-${criterion.key}`" class="sr-only">{{ criterion.label }} score</label><input :id="`rubric-score-${criterion.key}`" v-model.number="rubricScores[criterion.key]" type="number" min="0" max="100" step="1" placeholder="0–100" required class="w-full rounded-sm border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <ProviderPagination :pagination="rubricPagination" item-label="criteria" @change="rubricPage = $event" />

                                <p class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-500">
                                    {{ rubricReview.decision_notice }} Use the final decision section below to save these scores.
                                </p>
                            </section>

                            <section v-if="activeSection === 'documents'" class="border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                                <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fa-solid fa-file-circle-check text-sm text-slate-500" aria-hidden="true"></i>Document checklist</h3>
                                        <p class="mt-1 text-sm text-slate-500">Open an uploaded file to review it and record your decision.</p>
                                    </div>
                                    <span class="bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ application.document_readiness?.uploaded ?? 0 }} of {{ application.document_readiness?.required ?? applicationRequirements.length }} uploaded
                                    </span>
                                </div>

                                <div v-if="applicationFileRows.length" class="portal-table-scroll">
                                    <table class="portal-data-table min-w-[64rem] table-fixed">
                                        <caption class="sr-only">Application document checklist</caption>
                                        <colgroup><col class="w-[31%]"><col class="w-[29%]"><col class="w-[15%]"><col class="w-[15%]"><col class="w-[10%]"></colgroup>
                                        <thead><tr><th scope="col">Requirement</th><th scope="col">Submitted file</th><th scope="col">Uploaded</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                                        <tbody>
                                            <tr v-for="row in paginatedApplicationFileRows" :key="row.name">
                                                <td><p class="font-semibold text-slate-950"><i :class="[row.document ? 'fa-solid fa-file-circle-check' : 'fa-regular fa-file', 'mr-2 w-4 text-center text-slate-400']" aria-hidden="true"></i>{{ row.name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ row.required ? 'Required document' : 'Supporting file' }}</p></td>
                                                <td><p v-if="row.document" class="truncate font-semibold text-slate-800">{{ row.document.original_name }}</p><p v-if="row.document" class="mt-0.5 text-xs text-slate-500">{{ formatFileSize(row.document.size) }}</p><p v-else :class="['font-semibold', row.required ? 'text-amber-700' : 'text-slate-500']">{{ row.required ? 'Not uploaded' : 'Not provided' }}</p></td>
                                                <td class="text-slate-600">{{ row.document?.uploaded_at || '—' }}</td>
                                                <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', row.document ? documentStatusClass(row.document.status) : row.required ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500']"><i :class="row.document ? 'fa-solid fa-circle-dot' : 'fa-solid fa-minus'" aria-hidden="true"></i>{{ row.document ? labelFromKey(row.document.status || 'pending') : row.required ? 'Missing' : 'Optional' }}</span></td>
                                                <td><button v-if="row.document" type="button" class="inline-flex items-center border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" @click="openDocumentReview(row.document)"><i class="fa-regular fa-eye mr-2" aria-hidden="true"></i>Open</button><span v-else class="text-xs text-slate-400">No file</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <ProviderPagination v-if="applicationFileRows.length" :pagination="documentPagination" item-label="documents" @change="documentPage = $event" />
                                <div v-else class="px-6 py-10 text-center text-sm text-slate-600">
                                    <i class="fa-regular fa-folder-open text-2xl text-slate-300" aria-hidden="true"></i>
                                    <p class="mt-3 font-semibold text-slate-900">No document requirements</p>
                                    This application does not have any document requirements yet.
                                </div>
                            </section>

                            <ProviderProfileEvidencePanel
                                v-if="activeSection === 'documents'"
                                :proofs="applicantProfileProofs"
                                :academic-proof="academicProfileProof"
                                :school-id-proof="recentSchoolIdProfileProof"
                                :scan-required="academicScanRequired"
                                :scan-ready="academicScanReady"
                                :profile-status="application.applicant?.profile_verification_status"
                                :grading-scale="reviewedAcademicScale"
                                :academic-result="reviewedAcademicResult"
                                :result-is-numeric="reviewedAcademicResultIsNumeric"
                                :can-verify="canVerifyAcademicRecord"
                                :verifying="isVerifyingAcademicRecord"
                                @update:grading-scale="reviewedAcademicScale = $event; errorMessage = ''"
                                @update:academic-result="reviewedAcademicResult = $event; errorMessage = ''"
                                @verify="verifyApplicantAcademicRecord"
                                @open="openProfileProof"
                            />

                            <section v-if="activeSection === 'history' && timeline.length" class="border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                                <header class="border-b border-slate-200 px-5 py-4">
                                    <h3 class="flex items-center gap-2 text-lg font-bold text-slate-950"><i class="fa-solid fa-clock-rotate-left text-sm text-slate-500" aria-hidden="true"></i>Review history</h3>
                                    <p class="mt-1 text-sm text-slate-500">Showing five recorded changes per page.</p>
                                </header>
                                <div class="portal-table-scroll">
                                    <table class="portal-data-table min-w-[58rem] table-fixed">
                                        <caption class="sr-only">Applicant review history</caption>
                                        <colgroup><col class="w-[20%]"><col class="w-[18%]"><col class="w-[20%]"><col class="w-[42%]"></colgroup>
                                        <thead><tr><th scope="col">Status</th><th scope="col">Changed</th><th scope="col">Recorded by</th><th scope="col">Reason and note</th></tr></thead>
                                        <tbody>
                                            <tr v-for="event in paginatedTimeline" :key="event.id">
                                                <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', statusClass(event.to_status)]"><i class="fa-solid fa-circle-dot" aria-hidden="true"></i>{{ statusLabel(event.to_status) }}</span></td>
                                                <td class="text-slate-600"><i class="fa-regular fa-clock mr-1.5 text-xs text-slate-400" aria-hidden="true"></i>{{ event.changed_at || 'Recently' }}</td>
                                                <td class="font-semibold text-slate-800">{{ event.actor || 'System' }}</td>
                                                <td><p v-if="event.decision_reason" class="font-semibold text-slate-800">{{ labelFromKey(event.decision_reason) }}</p><p :class="['text-sm text-slate-600', event.decision_reason ? 'mt-0.5' : '']">{{ event.review_notes || 'No additional note recorded.' }}</p></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <ProviderPagination :pagination="historyPagination" item-label="history entries" @change="historyPage = $event" />
                            </section>

                            <section v-if="activeSection === 'history' && !timeline.length" class="border border-slate-300 bg-white px-6 py-10 text-center shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                                <i class="fa-solid fa-clock-rotate-left text-2xl text-slate-300" aria-hidden="true"></i><h3 class="mt-3 text-sm font-bold text-slate-900">No recorded history yet</h3><p class="mt-1 text-sm text-slate-500">Changes will appear here as the application moves through review.</p>
                            </section>

                            <section v-if="activeSection === 'history' && application.status_progress" class="border border-slate-300 bg-white p-5 shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
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
                        </div>

                        <div
                            v-if="activeSection === 'applicant'"
                            class="space-y-5"
                        >
                            <nav class="flex overflow-x-auto border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]" aria-label="Applicant record sections">
                                <button
                                    v-for="view in applicantDetailViews"
                                    :key="view.key"
                                    type="button"
                                    :aria-current="activeApplicantView === view.key ? 'page' : undefined"
                                    :class="[
                                        'inline-flex min-w-36 flex-1 items-center justify-center gap-2 border-r border-slate-200 px-3 py-3 text-sm font-bold transition last:border-r-0',
                                        activeApplicantView === view.key
                                            ? 'bg-slate-950 text-white'
                                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                                    ]"
                                    @click="activeApplicantView = view.key"
                                >
                                    <i :class="view.icon" aria-hidden="true"></i>
                                    {{ view.label }}
                                </button>
                            </nav>

                            <ApplicantReviewIdentityCard
                                :applicant="application.applicant"
                                eyebrow="Applicant record"
                                :status-label="profileVerificationLabel(application.applicant?.profile_verification_status)"
                                :status-class="profileVerificationClass(application.applicant?.profile_verification_status)"
                                :facts="applicantIdentityFacts"
                                :busy="isReviewingPhoto"
                                :error="photoReviewError"
                                @approve-photo="updatePhotoReview('approve')"
                                @request-photo-replacement="updatePhotoReview('request_replacement', $event)"
                            />

                            <section v-if="activeApplicantView === 'profile'" class="provider-panel overflow-hidden">
                                <header class="flex flex-col gap-2 border-b border-slate-200 bg-slate-50/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h3 class="font-bold text-slate-950">Supporting evidence</h3>
                                        <p class="mt-1 text-xs text-slate-500">Open a record when a saved claim needs checking.</p>
                                    </div>
                                    <span class="text-xs font-bold text-slate-500">{{ profileEvidenceRows.filter((row) => row.proof).length }} of {{ profileEvidenceRows.length }} submitted</span>
                                </header>
                                <div class="divide-y divide-slate-200">
                                    <article
                                        v-for="row in profileEvidenceRows"
                                        :key="row.key"
                                        class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center"
                                    >
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                                            <i :class="row.icon" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="font-bold text-slate-950">{{ row.label }}</p>
                                            <p class="mt-0.5 text-xs text-slate-500">{{ row.detail }}</p>
                                        </div>
                                        <span :class="['w-fit shrink-0 rounded-sm px-2 py-1 text-[10px] font-bold uppercase', evidenceClass(row.state)]">
                                            {{ evidenceLabel(row.state) }}
                                        </span>
                                        <button
                                            v-if="row.proof"
                                            type="button"
                                            class="inline-flex w-fit shrink-0 items-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 hover:text-slate-950"
                                            @click="openProfileProof(row.proof)"
                                        >
                                            Open file
                                            <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
                                        </button>
                                    </article>
                                </div>
                            </section>

                            <section v-if="activeApplicantView === 'profile'" class="provider-panel overflow-hidden">
                                <div class="border-b border-slate-200">
                                    <div class="flex items-center gap-3 bg-slate-50/70 px-5 py-4">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                                            <i class="fa-solid fa-address-card" aria-hidden="true"></i>
                                        </span>
                                        <h3 class="font-bold text-slate-950">Personal information</h3>
                                    </div>
                                    <dl class="grid gap-x-8 gap-y-4 px-5 py-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div v-for="fact in applicantReviewFacts" :key="fact.label">
                                            <dt class="text-xs font-semibold text-slate-500">{{ fact.label }}</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ fact.value }}</dd>
                                        </div>
                                    </dl>
                                </div>

                                <div class="border-b border-slate-200">
                                    <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-50/70 px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                                                <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                            </span>
                                            <h3 class="font-bold text-slate-950">Learning record</h3>
                                        </div>
                                        <span :class="['rounded-sm px-2 py-1 text-[10px] font-bold uppercase', evidenceClass(academicEvidenceState)]">
                                            {{ evidenceLabel(academicEvidenceState) }}
                                        </span>
                                    </div>
                                    <dl class="grid gap-x-8 gap-y-4 px-5 py-5 text-sm sm:grid-cols-2 xl:grid-cols-3">
                                        <div><dt class="text-xs font-semibold text-slate-500">Education level</dt><dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.education_level || 'not set') }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Grade / year</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.year_level || 'Not provided' }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Course / strand</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.course_or_strand || 'Not applicable or not provided' }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Enrollment</dt><dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.enrollment_status || 'not provided') }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Academic year</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.academic_year || 'Not provided' }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Record period</dt><dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.academic_term || 'not provided') }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">School</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.school || 'Not provided' }}</dd><dd class="mt-1 text-xs text-slate-500">{{ labelFromKey(application.applicant?.school_type || 'school type not provided') }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Academic result</dt><dd class="mt-1 font-bold text-slate-950">{{ applicantAcademicLabel(application.applicant) }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Learner reference number</dt><dd class="mt-1 break-words font-bold text-slate-950">{{ application.applicant?.learner_reference_number || 'Not provided' }}</dd></div>
                                    </dl>
                                </div>

                                <div>
                                    <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-50/70 px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                                                <i class="fa-solid fa-house-chimney-user" aria-hidden="true"></i>
                                            </span>
                                            <h3 class="font-bold text-slate-950">Household and support</h3>
                                        </div>
                                        <span class="rounded-sm bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">Applicant-declared</span>
                                    </div>
                                    <dl class="grid gap-x-8 gap-y-4 px-5 py-5 text-sm sm:grid-cols-2 xl:grid-cols-3">
                                        <div><dt class="text-xs font-semibold text-slate-500">Income bracket</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.income_bracket || 'Not provided' }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Household size</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.household_size ?? 'Not provided' }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Outside-platform scholarship</dt><dd class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.applicant?.current_scholarship_status || 'not provided') }}</dd><dd v-if="application.applicant?.current_scholarship_details" class="mt-1 text-xs leading-5 text-slate-500">{{ application.applicant.current_scholarship_details }}</dd></div>
                                        <div class="sm:col-span-2 xl:col-span-3">
                                            <dt class="text-xs font-semibold text-slate-500">Other active awards detected by the portal</dt>
                                            <dd v-if="application.applicant?.platform_active_scholarships?.length" class="mt-2 flex flex-wrap gap-2">
                                                <span v-for="record in application.applicant.platform_active_scholarships" :key="record.application_id" class="rounded-sm bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-900 ring-1 ring-amber-200">{{ record.title }} · {{ labelFromKey(record.status) }}</span>
                                            </dd>
                                            <dd v-else class="mt-1 font-bold text-slate-950">No other active portal award detected</dd>
                                        </div>
                                        <div class="sm:col-span-2 xl:col-span-3"><dt class="text-xs font-semibold text-slate-500">Study support needed</dt><dd class="mt-1 whitespace-pre-line font-bold leading-6 text-slate-950">{{ application.applicant?.support_needs || 'Not provided' }}</dd></div>
                                    </dl>
                                </div>
                            </section>

                            <section v-if="activeApplicantView === 'background'" class="provider-panel overflow-hidden">
                                <div class="border-b border-slate-200">
                                    <div class="flex items-center gap-3 bg-slate-50/70 px-5 py-4">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                                        <h3 class="font-bold text-slate-950">Address</h3>
                                    </div>
                                    <dl class="grid gap-x-8 gap-y-4 px-5 py-5 text-sm sm:grid-cols-2">
                                        <div><dt class="text-xs font-semibold text-slate-500">Residential address</dt><dd class="mt-1 font-bold leading-6 text-slate-950">{{ application.applicant?.address || 'Not provided' }}</dd></div>
                                        <div v-if="application.applicant?.location"><dt class="text-xs font-semibold text-slate-500">General location</dt><dd class="mt-1 font-bold leading-6 text-slate-950">{{ application.applicant.location }}</dd></div>
                                    </dl>
                                </div>

                                <div :class="hasGuardianDetails ? 'border-b border-slate-200' : ''">
                                    <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-50/70 px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-bullseye" aria-hidden="true"></i></span>
                                            <h3 class="font-bold text-slate-950">Goals and involvement</h3>
                                        </div>
                                        <span class="rounded-sm bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">Applicant-declared</span>
                                    </div>
                                    <dl class="divide-y divide-slate-200 text-sm">
                                        <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Applicant goal</dt><dd class="mt-1 whitespace-pre-line font-bold leading-6 text-slate-950">{{ application.applicant?.scholarship_goal || 'Not provided' }}</dd></div>
                                        <div class="px-5 py-4">
                                            <dt class="flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-slate-500">
                                                <span>Achievements or strengths</span>
                                                <span v-if="application.applicant?.achievements || achievementProfileProof" :class="['rounded-sm px-2 py-1 text-[10px] font-bold uppercase', evidenceClass(achievementEvidenceState)]">{{ evidenceLabel(achievementEvidenceState) }}</span>
                                            </dt>
                                            <dd class="mt-1 whitespace-pre-line font-bold leading-6 text-slate-950">{{ application.applicant?.achievements || 'Not provided' }}</dd>
                                            <button v-if="achievementProfileProof" type="button" class="mt-2 inline-flex items-center gap-2 text-xs font-bold text-amber-800 hover:text-amber-950" @click="openProfileProof(achievementProfileProof)">
                                                Open achievement evidence<i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Activities and responsibilities</dt><dd class="mt-1 whitespace-pre-line font-bold leading-6 text-slate-950">{{ application.applicant?.activities_and_responsibilities || 'Not provided' }}</dd></div>
                                    </dl>
                                </div>

                                <div v-if="hasGuardianDetails">
                                    <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-50/70 px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-people-roof" aria-hidden="true"></i></span>
                                            <h3 class="font-bold text-slate-950">Parent or guardian</h3>
                                        </div>
                                        <span v-if="application.applicant?.guardian_is_account_owner" class="rounded-sm bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">Manages applicant account</span>
                                    </div>
                                    <dl class="grid gap-x-8 gap-y-4 px-5 py-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div><dt class="text-xs font-semibold text-slate-500">Name</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.guardian_name || 'Not provided' }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Relationship</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.guardian_relationship || 'Not provided' }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Contact</dt><dd class="mt-1 font-bold text-slate-950">{{ application.applicant?.guardian_contact || 'Not provided' }}</dd></div>
                                        <div><dt class="text-xs font-semibold text-slate-500">Email</dt><dd class="mt-1 break-words font-bold text-slate-950">{{ application.applicant?.guardian_email || 'Not provided' }}</dd></div>
                                    </dl>
                                </div>
                            </section>

                            <section v-if="activeApplicantView === 'responses'" class="provider-panel overflow-hidden">
                                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50/70 px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-message" aria-hidden="true"></i></span>
                                        <h3 class="font-bold text-slate-950">Program responses</h3>
                                    </div>
                                    <span v-if="applicantApplicationAnswers.length" class="rounded-sm bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">Applicant-declared</span>
                                </div>

                                <dl v-if="applicantApplicationAnswers.length" class="divide-y divide-slate-200">
                                    <div v-for="(answer, index) in applicantApplicationAnswers" :key="answer.question_id || index" class="grid gap-3 px-5 py-4 sm:grid-cols-[2rem_minmax(0,1fr)]">
                                        <span class="grid h-7 w-7 place-items-center rounded-sm bg-slate-950 text-[11px] font-bold text-white">{{ index + 1 }}</span>
                                        <div><dt class="text-xs font-bold leading-5 text-slate-600">{{ answer.prompt }}</dt><dd class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-900">{{ answer.answer || 'No response provided' }}</dd></div>
                                    </div>
                                </dl>
                                <div v-else class="px-5 py-6">
                                    <p class="font-bold text-slate-950">No program responses</p>
                                    <p class="mt-1 text-sm text-slate-600">This scholarship did not collect additional written answers.</p>
                                </div>

                                <div v-if="application.notes || application.review_notes" class="border-t border-slate-200">
                                    <div class="flex items-center gap-3 bg-slate-50/70 px-5 py-4">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-note-sticky" aria-hidden="true"></i></span>
                                        <h3 class="font-bold text-slate-950">Notes</h3>
                                    </div>
                                    <dl class="divide-y divide-slate-200 text-sm">
                                        <div v-if="application.notes" class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Applicant note</dt><dd class="mt-1 whitespace-pre-line leading-6 text-slate-900">{{ application.notes }}</dd></div>
                                        <div v-if="application.review_notes" class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Provider review note</dt><dd class="mt-1 whitespace-pre-line leading-6 text-slate-900">{{ application.review_notes }}</dd></div>
                                    </dl>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

            </div>
        </section>

        <Teleport to="body">
            <div
                v-if="showDssDetails"
                class="fixed inset-0 z-[2600] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5"
                @click.self="showDssDetails = false"
                @keydown.esc="showDssDetails = false"
            >
                <section
                    class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="decision-support-modal-title"
                >
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Decision support</p>
                                <h2 id="decision-support-modal-title" class="mt-1 text-xl font-bold text-slate-950">How the guidance was calculated</h2>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                            aria-label="Close decision support calculation"
                            @click="showDssDetails = false"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-5">
                        <div class="grid grid-cols-2 overflow-hidden rounded-md border border-slate-200 bg-white sm:grid-cols-5">
                            <div class="border-b border-r border-slate-200 p-3 sm:border-b-0">
                                <p class="text-lg font-bold text-emerald-700">{{ dssComparison.met }}</p>
                                <p class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Met</p>
                            </div>
                            <div class="border-b border-slate-200 p-3 sm:border-r sm:border-b-0">
                                <p class="text-lg font-bold text-rose-700">{{ dssComparison.not_met }}</p>
                                <p class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Not met</p>
                            </div>
                            <div class="border-b border-r border-slate-200 p-3 sm:border-b-0">
                                <p class="text-lg font-bold text-amber-700">{{ dssComparison.missing }}</p>
                                <p class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Missing</p>
                            </div>
                            <div class="border-b border-slate-200 p-3 sm:border-r sm:border-b-0">
                                <p class="text-lg font-bold text-slate-800">{{ dssComparison.manual_review }}</p>
                                <p class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Manual review</p>
                            </div>
                            <div class="col-span-2 p-3 sm:col-span-1">
                                <p class="text-lg font-bold text-slate-800">{{ dssComparison.not_applicable }}</p>
                                <p class="mt-0.5 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Unrestricted</p>
                            </div>
                        </div>

                        <div v-if="application.dss_explanation?.strengths?.length || application.dss_explanation?.needs_attention?.length" class="mt-4 grid gap-3 md:grid-cols-2">
                            <section v-if="application.dss_explanation?.strengths?.length" class="rounded-md border border-slate-200 bg-white p-4">
                                <h3 class="text-sm font-bold text-slate-950">Matched information</h3>
                                <div class="mt-3 grid gap-2">
                                    <p v-for="item in application.dss_explanation.strengths" :key="item" class="flex items-start gap-2 text-sm leading-5 text-slate-600">
                                        <i class="fa-solid fa-check mt-1 text-[10px] text-emerald-600" aria-hidden="true"></i>
                                        <span>{{ item }}</span>
                                    </p>
                                </div>
                            </section>
                            <section v-if="application.dss_explanation?.needs_attention?.length" class="rounded-md border border-slate-200 bg-white p-4">
                                <h3 class="text-sm font-bold text-slate-950">Needs reviewer attention</h3>
                                <div class="mt-3 grid gap-2">
                                    <p v-for="item in application.dss_explanation.needs_attention" :key="item" class="flex items-start gap-2 text-sm leading-5 text-slate-600">
                                        <i class="fa-solid fa-circle-exclamation mt-1 text-[10px] text-amber-600" aria-hidden="true"></i>
                                        <span>{{ item }}</span>
                                    </p>
                                </div>
                            </section>
                        </div>

                        <section v-if="dssCriteria.length" class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <div class="border-b border-slate-200 px-4 py-3">
                                <h3 class="text-sm font-bold text-slate-950">Weighted calculation</h3>
                            </div>
                            <div class="divide-y divide-slate-200">
                                <div v-for="criterion in dssCriteria" :key="criterion.key" class="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">{{ criterion.label }}</p>
                                        <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ criterion.note }}</p>
                                    </div>
                                    <p class="text-xs font-bold text-slate-700">
                                        {{ criterion.score }}% x {{ criterion.weight }}% = {{ criterion.weighted_score }} points
                                    </p>
                                </div>
                            </div>
                        </section>
                    </div>

                    <footer class="flex flex-col gap-3 border-t border-slate-200 bg-white px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <p class="text-xs leading-5 text-slate-500">
                            Methodology {{ application.dss_breakdown?.methodology_version || 'current' }}. Guidance supports review and does not replace the provider's decision.
                        </p>
                        <button
                            type="button"
                            class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            @click="showDssDetails = false"
                        >
                            Close
                        </button>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showCorrectionForm"
                class="fixed inset-0 z-[2700] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5"
                @click.self="resetCorrectionForm"
                @keydown.esc="resetCorrectionForm"
            >
                <section class="w-full max-w-2xl overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="correction-modal-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Applicant update</p>
                            <h2 id="correction-modal-title" class="mt-1 text-xl font-bold text-slate-950">Request a correction</h2>
                            <p class="mt-1 text-sm text-slate-600">Choose the affected area and give one clear instruction.</p>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-900" aria-label="Close correction request" @click="resetCorrectionForm">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="grid gap-4 p-5">
                        <fieldset>
                            <legend :class="labelClass">What needs attention?</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label v-for="option in correctionTargetOptions" :key="option.value" class="flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2.5 text-sm font-semibold text-slate-700 hover:border-slate-400">
                                    <input v-model="correctionTargets" type="checkbox" :value="option.value" class="h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-amber-400">
                                    <i :class="option.icon" class="w-4 text-center text-slate-400" aria-hidden="true"></i>
                                    <span>{{ option.label }}</span>
                                </label>
                            </div>
                        </fieldset>
                        <div>
                            <label :class="labelClass">Instruction for the applicant</label>
                            <textarea v-model="correctionMessage" rows="4" maxlength="1500" placeholder="Example: Replace the unreadable report card and update your current grade level." :class="inputClass"></textarea>
                        </div>
                        <p v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ errorMessage }}</p>
                    </div>

                    <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end">
                        <button type="button" :disabled="isHandlingCorrection" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-60" @click="resetCorrectionForm">Cancel</button>
                        <button type="button" :disabled="isHandlingCorrection" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60" @click="requestApplicationCorrection">
                            {{ isHandlingCorrection ? 'Sending...' : 'Send request' }}
                        </button>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showBenefitTerminationForm"
                class="fixed inset-0 z-[2700] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5"
                @click.self="resetBenefitTerminationForm"
                @keydown.esc="resetBenefitTerminationForm"
            >
                <section class="w-full max-w-xl overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="benefit-termination-modal-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Recipient support</p>
                            <h2 id="benefit-termination-modal-title" class="mt-1 text-xl font-bold text-slate-950">Stop future benefits</h2>
                            <p class="mt-1 text-sm text-slate-600">Released benefits stay in the record. The applicant will be notified.</p>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-900" aria-label="Close benefit termination" @click="resetBenefitTerminationForm">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="grid gap-4 p-5">
                        <div>
                            <label :class="labelClass">Reason <span class="text-rose-600">*</span></label>
                            <select v-model="benefitTerminationForm.reason" :class="inputClass">
                                <option value="">Select a reason</option>
                                <option v-for="option in benefitTerminationReasonOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="labelClass">Explanation for the applicant <span class="text-rose-600">*</span></label>
                            <textarea v-model="benefitTerminationForm.explanation" rows="4" maxlength="2000" placeholder="Explain the procedure or condition that was not followed." :class="inputClass"></textarea>
                        </div>
                        <p v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ errorMessage }}</p>
                    </div>

                    <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end">
                        <button type="button" :disabled="isStoppingBenefits" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-60" @click="resetBenefitTerminationForm">Cancel</button>
                        <button type="button" :disabled="isStoppingBenefits" class="rounded-md bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-800 disabled:opacity-60" @click="stopBenefits">
                            {{ isStoppingBenefits ? 'Saving...' : 'Stop and notify' }}
                        </button>
                    </footer>
                </section>
            </div>
        </Teleport>

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
