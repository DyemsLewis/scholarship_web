<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import EligibilityConditionList from '../components/EligibilityConditionList.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import RecipientAgreementSummary from '../components/RecipientAgreementSummary.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { labelFromKey as formatKeyLabel } from '../support/display';
import { showPortalToast } from '../support/portalToast';
import { progressStateLabel } from '../support/selectionPlan';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const isLoading = ref(true);
const isUploading = ref(false);
const errorMessage = ref('');
const user = ref(null);
const application = ref(null);
const uploadForm = ref({ documentName: '' });
const uploadFile = ref(null);
const fileInput = ref(null);
const activeUploadRequirement = ref('');
const previewDocument = ref(null);
const activeMapPreview = ref(null);
const documentTermsAccepted = ref(false);
const showWithdrawalModal = ref(false);
const withdrawalReason = ref('');
const isWithdrawing = ref(false);
const showCorrectionModal = ref(false);
const correctionResponse = ref('');
const isSendingCorrection = ref(false);
const agreementTermsAccepted = ref(false);
const agreementResponseNote = ref('');
const isSubmittingAgreement = ref(false);
const showRecipientAgreementModal = ref(false);
const showProfileMatchModal = ref(false);
const formalHandoffOpen = ref(false);
const activeSection = ref('overview');
const requiresOriginalVerification = computed(() => ['onsite', 'hybrid'].includes(
    application.value?.scholarship?.application_mode,
));

const requiredDocuments = computed(() => documentRequirements(application.value?.scholarship?.requirements));
const confirmedDocuments = computed(() => application.value?.document_checklist ?? []);
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
const dssCriteria = computed(() => application.value?.dss_breakdown?.criteria ?? []);
const eligibilityConditionResults = computed(() => application.value?.eligibility_breakdown?.condition_results ?? []);
const dssDecisionNotice = computed(() => application.value?.dss_breakdown?.decision_notice ?? 'This score supports screening only. The scholarship provider makes the final decision.');
const workflow = computed(() => application.value?.workflow ?? {});
const recipientAgreement = computed(() => application.value?.recipient_agreement ?? null);
const recipientMonitoring = computed(() => application.value?.recipient_monitoring ?? { eligible: false, cycles: [], pending_count: 0 });
const formalApplicationHandoff = computed(() => application.value?.formal_application_handoff ?? null);
const applicantNextActionDetails = computed(() => workflow.value.next_action
    ?? application.value?.status_progress?.next_action_details
    ?? {});
const applicantNextStep = computed(() => {
    if (application.value?.correction_status === 'requested') {
        return 'Update the information requested by the provider';
    }

    if (recipientAgreement.value?.can_respond) {
        return 'Review your recipient agreement';
    }

    if (recipientMonitoring.value.pending_count > 0) {
        return recipientMonitoring.value.pending_count === 1
            ? 'Submit your current scholarship requirement'
            : `Complete ${recipientMonitoring.value.pending_count} scholarship requirements`;
    }

    if (filesNeedingAction.value.length) {
        return filesNeedingAction.value.length === 1
            ? 'Upload or replace one required file'
            : `Upload or replace ${filesNeedingAction.value.length} required files`;
    }

    if (currentSchedule.value) {
        return currentSchedule.value.title || `Attend your scheduled ${scheduleTypeLabel(currentSchedule.value.type).toLowerCase()}`;
    }

    if (applicationIsClosed.value) {
        return recipientMonitoring.value.eligible
            ? (recipientMonitoring.value.support_status_label || 'Scholarship support is active')
            : 'Review the application result';
    }

    return workflow.value.next_action?.label ?? applicantNextAction(application.value);
});
const applicantNextActor = computed(() => {
    if (application.value?.correction_status === 'requested'
        || recipientAgreement.value?.can_respond
        || recipientMonitoring.value.pending_count > 0
        || filesNeedingAction.value.length > 0
        || Boolean(currentSchedule.value)) {
        return 'You';
    }

    if (applicationIsClosed.value) {
        return 'Status';
    }

    return applicantNextActionDetails.value.actor_label ?? 'Check application';
});
const applicantOwnsNextAction = computed(() => {
    const actor = String(applicantNextActor.value ?? '').toLowerCase();

    return actor === 'you' || actor.includes('applicant') || actor.includes('recipient');
});
const applicantNextEyebrow = computed(() => {
    if (applicantOwnsNextAction.value) {
        return 'Your next action';
    }

    if (applicationIsClosed.value && recipientMonitoring.value.eligible) {
        return 'Scholarship support';
    }

    if (applicationIsClosed.value) {
        return 'Final result';
    }

    const actor = String(applicantNextActor.value ?? '');

    return /provider|admin|review team|platform/i.test(actor)
        ? `Waiting for ${actor}`
        : 'Next step';
});
const applicantNextDescription = computed(() => {
    if (application.value?.correction_status === 'requested') {
        return application.value.correction_message || 'Review the provider request, update the affected details or files, then send your response.';
    }

    if (recipientAgreement.value?.can_respond) {
        return 'Confirm the support and responsibilities recorded when the provider selected you.';
    }

    if (recipientMonitoring.value.pending_count > 0) {
        return 'Open Monitoring and submit the checklist records requested by the provider.';
    }

    if (filesNeedingAction.value.length) {
        return 'Open Files to review the provider note and upload the required replacement.';
    }

    if (currentSchedule.value) {
        const accessDetail = currentSchedule.value.mode === 'online' ? 'meeting link' : 'location';
        const scheduledLabel = currentSchedule.value.scheduled_label;

        return `Review the date, ${accessDetail}, and instructions${scheduledLabel ? ` for ${scheduledLabel}` : ''}.`;
    }

    if (applicationIsClosed.value) {
        return recipientMonitoring.value.eligible
            ? 'Use Monitoring for ongoing requirements and benefit releases.'
            : (application.value?.outcome_notes || 'The provider has recorded the final result for this application.');
    }

    return applicantNextActionDetails.value.description ?? 'No action is required from you right now. We will show the next instruction here when the application changes.';
});
const timeline = computed(() => application.value?.timeline ?? []);
const schedules = computed(() => application.value?.schedules ?? []);
const applicationIsClosed = computed(() => Boolean(workflow.value.is_closed));
const currentSchedule = computed(() => applicationIsClosed.value
    ? null
    : (schedules.value.find((schedule) => (
        schedule.status === 'scheduled'
        && ['exam', 'interview'].includes(schedule.type)
    )) ?? null));
const scheduleHistory = computed(() => schedules.value.filter(
    (schedule) => applicationIsClosed.value || schedule.status !== 'scheduled',
));
const currentScheduleDate = computed(() => formatScheduleDate(currentSchedule.value));
const filesNeedingAction = computed(() => applicationFileRows.value.filter((row) => row.required
    && (!row.document || ['needs_replacement', 'rejected'].includes(row.document.status))));
const requiredFileRows = computed(() => applicationFileRows.value.filter((row) => row.required));
const fileStatusLabel = computed(() => {
    if (!requiredFileRows.value.length) {
        return 'No files required';
    }

    if (filesNeedingAction.value.length) {
        return `${filesNeedingAction.value.length} need attention`;
    }

    return `${requiredFileRows.value.length} uploaded`;
});
const hasProviderUpdate = computed(() => Boolean(
    application.value?.review_notes
    || application.value?.decision_reason
    || application.value?.outcome_notes,
));
const applicationSections = computed(() => [
    { key: 'overview', label: 'Next step', icon: 'fa-solid fa-arrow-right' },
    ...(applicationFileRows.value.length ? [{ key: 'files', label: 'Files', icon: 'fa-solid fa-folder-open', count: filesNeedingAction.value.length }] : []),
    ...(schedules.value.length ? [{ key: 'schedule', label: 'Schedule', icon: 'fa-regular fa-calendar', count: currentSchedule.value ? 1 : 0 }] : []),
    { key: 'program', label: 'Scholarship', icon: 'fa-solid fa-graduation-cap' },
    { key: 'history', label: 'History', icon: 'fa-solid fa-clock-rotate-left' },
]);
const nextActionButton = computed(() => {
    if (application.value?.correction_status === 'requested') {
        return { label: 'Review requested update', section: 'overview', target: 'application-correction' };
    }

    if (recipientAgreement.value?.can_respond) {
        return { label: 'Review agreement', action: 'agreement' };
    }

    if (recipientMonitoring.value.pending_count > 0) {
        return { label: 'Open monitoring checklist', href: `/dashboard/monitoring/${application.value.id}` };
    }

    if (filesNeedingAction.value.length) {
        return { label: 'Review required files', section: 'files' };
    }

    if (currentSchedule.value) {
        return { label: 'View schedule', section: 'schedule', target: 'application-schedules' };
    }

    if (applicationIsClosed.value && recipientMonitoring.value.eligible) {
        return { label: 'View monitoring', href: `/dashboard/monitoring/${application.value.id}` };
    }

    if (applicationIsClosed.value) {
        return null;
    }

    if (formalApplicationHandoff.value) {
        return { label: 'View formal application steps', section: 'overview', target: 'formal-application-handoff' };
    }

    return null;
});
const applicationScholarship = computed(() => application.value?.scholarship ?? null);
const supportPeriodLabel = computed(() => {
    const startsAt = applicationScholarship.value?.support_starts_at;
    const endsAt = applicationScholarship.value?.support_ends_at;

    if (startsAt && endsAt) return `${startsAt} - ${endsAt}`;
    if (startsAt) return `Starts ${startsAt}`;
    if (endsAt) return `Through ${endsAt}`;

    return 'Not specified';
});
const correctionTargetOptions = [
    { value: 'profile', label: 'Profile information' },
    { value: 'academic_record', label: 'Academic record' },
    { value: 'application_files', label: 'Application files' },
    { value: 'application_answers', label: 'Application answers' },
    { value: 'other', label: 'Other information' },
];
const correctionTargets = computed(() => application.value?.correction_targets ?? []);
const scholarshipMapAddress = computed(() => {
    const parts = [
        applicationScholarship.value?.location_address,
        applicationScholarship.value?.location_name,
    ].filter(Boolean);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});
const hasMapPreview = computed(() => Boolean(
    (applicationScholarship.value?.latitude && applicationScholarship.value?.longitude)
    || applicationScholarship.value?.location_address
    || applicationScholarship.value?.location_name,
));
const hasUserMapLocation = computed(() => hasCoordinates(user.value?.latitude, user.value?.longitude));

function closeMapModal() {
    activeMapPreview.value = null;
}

function openProgramMap() {
    const program = applicationScholarship.value;
    if (!program) return;

    activeMapPreview.value = {
        eyebrow: 'Program location',
        title: program.location_name || program.title,
        address: scholarshipMapAddress.value,
        latitude: program.latitude,
        longitude: program.longitude,
        markerText: program.location_name || program.title,
        secondaryLatitude: user.value?.latitude,
        secondaryLongitude: user.value?.longitude,
        secondaryMarkerText: user.value?.name || 'Your saved location',
        distanceLabel: program.distance_label ? `About ${program.distance_label}` : '',
        note: hasUserMapLocation.value && program.distance_label
            ? `Your saved location is shown too: ${program.distance_label} from this program.`
            : 'This is the program location currently listed by the provider.',
    };
}

function openFormalHandoffMap() {
    const handoff = formalApplicationHandoff.value;
    if (!handoff) return;

    activeMapPreview.value = {
        eyebrow: 'Formal application location',
        title: handoff.location_name || 'Where to continue',
        address: handoff.location_address || handoff.location_name || '',
        markerText: handoff.location_name || 'Formal application location',
        note: 'Use this location when the provider asks you to continue the formal application in person.',
    };
}

function openScheduleMap(schedule) {
    if (!schedule) return;

    activeMapPreview.value = {
        eyebrow: 'Activity location',
        title: schedule.venue || schedule.title,
        address: schedule.location_address || schedule.venue || '',
        latitude: schedule.latitude,
        longitude: schedule.longitude,
        markerText: schedule.venue || schedule.title,
        note: 'Review this location together with the activity date and provider instructions.',
    };
}

function statusLabel(status) {
    const labels = {
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
    };

    if (labels[status]) {
        return labels[status];
    }

    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function correctionTargetLabel(target) {
    return correctionTargetOptions.find((option) => option.value === target)?.label ?? labelFromKey(target);
}

function correctionTargetsInclude(target) {
    return correctionTargets.value.includes(target);
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'exam_passed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['withdrawn', 'rejected', 'not_awarded', 'exam_failed', 'interview_failed', 'benefits_terminated'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled', 'waitlisted'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function matchClass(score) {
    if (Number(score) >= 80) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (Number(score) >= 50) {
        return 'bg-amber-100 text-amber-800';
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

function scheduleTypeLabel(type) {
    return {
        exam: 'Scholarship exam',
        interview: 'Interview',
    }[type] ?? labelFromKey(type);
}

function scheduleTypeIcon(type) {
    return {
        exam: 'fa-solid fa-clipboard-check',
        interview: 'fa-solid fa-comments',
    }[type] ?? 'fa-solid fa-calendar-day';
}

function scheduleModeLabel(mode) {
    return {
        onsite: 'On-site',
        online: 'Online',
        hybrid: 'On-site and online',
        provider_managed: 'Provider-managed',
    }[mode] ?? labelFromKey(mode);
}

function applicationModeLabel(mode) {
    return {
        online: 'Portal review',
        onsite: 'Portal review with in-person verification',
        hybrid: 'Portal review with in-person verification',
        provider_review: 'Profile review only',
    }[mode] ?? labelFromKey(mode || 'not listed');
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

function formatScheduleDate(schedule) {
    if (!schedule?.scheduled_at) {
        return { month: '', day: '', time: schedule?.scheduled_label || 'Date to be announced' };
    }

    const date = new Date(schedule.scheduled_at);

    if (Number.isNaN(date.getTime())) {
        return { month: '', day: '', time: schedule.scheduled_label || 'Date to be announced' };
    }

    return {
        month: new Intl.DateTimeFormat('en-PH', { month: 'short' }).format(date),
        day: new Intl.DateTimeFormat('en-PH', { day: '2-digit' }).format(date),
        time: new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(date),
    };
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

function criterionClass(status) {
    if (status === 'pass') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    }

    if (status === 'fail') {
        return 'border-rose-200 bg-rose-50 text-rose-800';
    }

    if (status === 'missing') {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    return 'border-slate-200 bg-slate-50 text-slate-600';
}

function eligibilityCriterionLabel(criterion) {
    if (criterion.status === 'pass') {
        return 'Matched';
    }

    if (criterion.status === 'fail') {
        return 'Not matched';
    }

    if (criterion.status === 'missing') {
        return 'Needs information';
    }

    return criterion.key === 'academic' && criterion.requirement
        ? 'Provider review'
        : 'No restriction';
}

function eligibilityCriterionText(value, fallback = '') {
    if (Array.isArray(value)) {
        const items = value
            .map((item) => eligibilityCriterionText(item))
            .filter(Boolean);

        return items.length ? items.join(', ') : fallback;
    }

    const text = String(value ?? '').trim();

    return text ? labelFromKey(text) : fallback;
}

function labelFromKey(value) {
    const labels = {
        qualified_for_formal_application: 'Qualified for formal application',
        approved_for_award: 'Approved for award',
        for_exam: 'Meets exam eligibility',
        exam_scheduled: 'Exam scheduled',
        exam_completed: 'Exam completed',
        passed_exam: 'Passed exam',
        failed_exam: 'Failed exam',
        failed_interview: 'Failed interview',
    };

    if (labels[value]) {
        return labels[value];
    }

    return formatKeyLabel(value);
}

function hasCoordinates(latitude, longitude) {
    return latitude !== null
        && latitude !== undefined
        && latitude !== ''
        && longitude !== null
        && longitude !== undefined
        && longitude !== '';
}

function applicantNextAction(current) {
    if (!current) {
        return 'Wait for provider review and document feedback.';
    }

    if (current.workflow?.is_closed) {
        return 'Review the final provider result and notes for this application.';
    }

    const activeSchedule = current.schedules?.find((schedule) => schedule.status === 'scheduled');

    if (activeSchedule) {
        return `Follow the posted ${scheduleTypeLabel(activeSchedule.type)} instructions and attend at the scheduled time.`;
    }

    if (current.formal_application_handoff) {
        return 'You passed portal pre-screening. Review what to bring and continue directly with the provider.';
    }

    const missing = current.document_readiness?.missing ?? [];

    if (missing.length) {
        return `Confirm or upload: ${missing.slice(0, 3).join(', ')}${missing.length > 3 ? ', and more' : ''}.`;
    }

    if (Number(current.document_readiness?.accepted_percent ?? 100) < 100) {
        return 'Wait for the provider to review your uploaded documents.';
    }

    if (['highly_recommended', 'recommended'].includes(current.dss_recommendation)) {
        return 'Your profile looks suitable. Monitor updates and respond quickly if the provider asks for anything.';
    }

    return 'Wait for provider review and keep your profile and documents updated.';
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

function criterionImpact(criterion) {
    const weightedScore = Number(criterion.weighted_score ?? 0);

    if (Number.isFinite(weightedScore) && weightedScore > 0) {
        return `${weightedScore.toFixed(weightedScore % 1 === 0 ? 0 : 1)} pts`;
    }

    const score = Number(criterion.score ?? 0);
    const weight = Number(criterion.weight ?? 0);

    if (!Number.isFinite(score) || !Number.isFinite(weight)) {
        return '0 pts';
    }

    const impact = (score * weight) / 100;

    return `${impact.toFixed(impact % 1 === 0 ? 0 : 1)} pts`;
}

async function handleFileChange(event) {
    uploadFile.value = event.target.files?.[0] ?? null;

    if (!uploadFile.value) {
        activeUploadRequirement.value = '';
        return;
    }

    await uploadDocument();
}

function openUploadPicker(requirement) {
    errorMessage.value = '';

    if (!documentTermsAccepted.value) {
        showPortalToast({
            type: 'error',
            title: 'Terms required',
            message: 'Accept the document upload terms before choosing a file.',
        });
        return;
    }

    uploadForm.value.documentName = requirement;
    uploadFile.value = null;
    activeUploadRequirement.value = requirement;

    if (fileInput.value) {
        fileInput.value.value = '';
        fileInput.value.click();
    }
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

function closeRecipientAgreementModal() {
    if (!isSubmittingAgreement.value) {
        showRecipientAgreementModal.value = false;
    }
}

async function openSection(section, target = null) {
    activeSection.value = section;

    if (target === 'formal-application-handoff') {
        formalHandoffOpen.value = true;
    }

    await nextTick();

    if (target) {
        document.getElementById(target)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

async function followNextAction() {
    if (nextActionButton.value?.href) {
        window.location.href = nextActionButton.value.href;
        return;
    }

    if (nextActionButton.value?.action === 'agreement') {
        showRecipientAgreementModal.value = true;
        return;
    }

    if (nextActionButton.value?.action === 'correction') {
        showCorrectionModal.value = true;
        return;
    }

    if (nextActionButton.value?.section) {
        await openSection(nextActionButton.value.section, nextActionButton.value.target);
    }
}

async function loadApplication() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/applications/${applicationId}/data`);

        user.value = response.data.user;
        application.value = response.data.application;
        const urlParams = new URLSearchParams(window.location.search);
        const requestedSection = urlParams.get('section');
        const requestedAction = urlParams.get('action');
        if (requestedSection === 'monitoring' && recipientMonitoring.value.eligible) {
            window.location.replace(`/dashboard/monitoring/${application.value.id}`);
            return;
        }
        if (requestedSection && applicationSections.value.some((section) => section.key === requestedSection)) {
            activeSection.value = requestedSection;
        }
        if (requestedAction === 'agreement' && recipientAgreement.value?.can_respond) {
            showRecipientAgreementModal.value = true;
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load application details.';
    } finally {
        isLoading.value = false;
    }
}

async function uploadDocument() {
    if (!application.value || !uploadForm.value.documentName || !uploadFile.value) {
        errorMessage.value = 'Choose a file before uploading.';
        return;
    }

    if (!documentTermsAccepted.value) {
        showPortalToast({
            type: 'error',
            title: 'Terms required',
            message: 'Accept the document upload terms before uploading.',
        });
        return;
    }

    isUploading.value = true;
    errorMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', uploadForm.value.documentName);
    payload.append('document_file', uploadFile.value);
    payload.append('terms_accepted', '1');

    try {
        const response = await window.axios.post(`/dashboard/applications/${application.value.id}/documents`, payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        application.value = response.data.application;
        uploadFile.value = null;
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    } catch (handledError) {
        void handledError;
    } finally {
        isUploading.value = false;
        activeUploadRequirement.value = '';
    }
}

async function deleteDocument(document) {
    if (!application.value) {
        return;
    }

    isUploading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.delete(`/dashboard/documents/${document.id}`);

        application.value = response.data.application;
        if (previewDocument.value?.id === document.id) {
            closeDocumentPreview();
        }
    } catch (handledError) {
        void handledError;
    } finally {
        isUploading.value = false;
    }
}

async function withdrawApplication() {
    if (!application.value || withdrawalReason.value.trim().length < 5) {
        showPortalToast({ type: 'error', title: 'Reason required', message: 'Briefly explain why you are withdrawing this application.' });
        return;
    }

    isWithdrawing.value = true;

    try {
        const response = await window.axios.patch(`/dashboard/applications/${application.value.id}/withdraw`, {
            reason: withdrawalReason.value.trim(),
        });
        application.value = response.data.application;
        showWithdrawalModal.value = false;
        withdrawalReason.value = '';
        showPortalToast({ type: 'success', title: 'Application withdrawn', message: response.data.message });
    } catch (handledError) {
        void handledError;
    } finally {
        isWithdrawing.value = false;
    }
}

async function submitCorrectionResponse() {
    if (!application.value || correctionResponse.value.trim().length < 3) {
        showPortalToast({ type: 'error', title: 'Response required', message: 'Describe what you updated before sending the correction.' });
        return;
    }

    isSendingCorrection.value = true;

    try {
        const response = await window.axios.patch(`/dashboard/applications/${application.value.id}/correction-response`, {
            response: correctionResponse.value.trim(),
        });
        application.value = response.data.application;
        showCorrectionModal.value = false;
        correctionResponse.value = '';
        showPortalToast({ type: 'success', title: 'Correction sent', message: response.data.message });
    } catch (handledError) {
        void handledError;
    } finally {
        isSendingCorrection.value = false;
    }
}

async function submitRecipientAgreement(response) {
    if (!application.value || isSubmittingAgreement.value) {
        return;
    }

    if (response === 'accepted' && !agreementTermsAccepted.value) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that you reviewed the recipient agreement before accepting it.',
        });
        return;
    }

    if (response === 'declined' && agreementResponseNote.value.trim().length < 5) {
        showPortalToast({
            type: 'error',
            title: 'Reason required',
            message: 'Briefly tell the provider why you cannot accept the agreement.',
        });
        return;
    }

    isSubmittingAgreement.value = true;

    try {
        const result = await window.axios.patch(`/dashboard/applications/${application.value.id}/response`, {
            response,
            terms_accepted: response === 'accepted' ? agreementTermsAccepted.value : false,
            note: agreementResponseNote.value.trim() || null,
        });
        application.value = result.data.application;
        agreementTermsAccepted.value = false;
        agreementResponseNote.value = '';
        showRecipientAgreementModal.value = false;
        showPortalToast({
            type: response === 'accepted' ? 'success' : 'info',
            title: response === 'accepted' ? 'Agreement accepted' : 'Response sent',
            message: result.data.message,
        });
    } catch (handledError) {
        void handledError;
    } finally {
        isSubmittingAgreement.value = false;
    }
}

onMounted(loadApplication);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <FilePreviewModal
            :file="previewDocument"
            :title="previewDocument?.document_name || previewDocument?.original_name || 'Application document'"
            :context="application?.scholarship?.title || 'Submitted application'"
            @close="closeDocumentPreview"
        />

        <section class="student-page">
            <div class="student-container max-w-6xl">
                <a href="/dashboard/applications" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to applications
                </a>

                <div v-if="isLoading" class="student-card mt-5 rounded-md border-slate-300 p-6 text-sm text-slate-500">
                    Loading application...
                </div>

                <div v-else-if="errorMessage && !application" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                    {{ errorMessage }}
                </div>

                <div v-else-if="application" class="mt-5 space-y-4">
                    <div v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                        {{ errorMessage }}
                    </div>

                    <header class="student-card overflow-hidden rounded-md border-slate-300 border-t-4 border-t-amber-400 shadow-[0_8px_22px_rgba(15,23,42,0.07)]">
                        <div class="flex flex-col gap-4 bg-[linear-gradient(120deg,#ffffff_0%,#ffffff_72%,#f8fafc_100%)] p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div class="flex min-w-0 items-center gap-4">
                                <img
                                    :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="application.scholarship?.title || 'Scholarship'"
                                    class="h-14 w-14 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Application record</p>
                                    <h1 class="mt-1 text-xl font-bold leading-tight text-slate-950 sm:text-2xl">{{ application.scholarship?.title || 'Scholarship application' }}</h1>
                                    <p class="mt-1 truncate text-sm font-semibold text-slate-500">{{ application.scholarship?.provider?.name || 'Scholarship provider' }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-col items-start sm:items-end">
                                <span :class="['rounded-md px-3 py-1.5 text-xs font-bold uppercase', statusClass(application.status)]">
                                    {{ application.status === 'benefits_terminated' ? statusLabel(application.status) : (workflow.final_outcome_label || workflow.application_state_label || statusLabel(application.status)) }}
                                </span>
                                <p class="mt-2 text-xs text-slate-500">Submitted {{ application.submitted_at || 'recently' }}</p>
                            </div>
                        </div>

                        <dl class="grid border-t border-slate-200 bg-slate-50 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div class="px-4 py-3 sm:border-r sm:border-slate-200 sm:px-5">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Current stage</dt>
                                <dd class="mt-1 font-bold text-slate-900">{{ application.status_progress?.current_stage_label || statusLabel(application.status) }}</dd>
                            </div>
                            <div class="border-t border-slate-200 px-4 py-3 sm:border-r sm:border-t-0 sm:px-5">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Application progress</dt>
                                <dd class="mt-1 flex items-center gap-2 font-bold text-slate-900"><span>{{ application.status_progress?.percent ?? 0 }}%</span><span class="h-1.5 min-w-12 flex-1 overflow-hidden bg-slate-200"><span class="block h-full bg-amber-400" :style="{ width: `${application.status_progress?.percent ?? 0}%` }"></span></span></dd>
                            </div>
                            <div class="border-t border-slate-200 px-4 py-3 sm:border-r sm:border-slate-200 lg:border-t-0 sm:px-5">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Files</dt>
                                <dd :class="['mt-1 font-bold', filesNeedingAction.length ? 'text-amber-800' : 'text-slate-900']">{{ fileStatusLabel }}</dd>
                            </div>
                            <div class="border-t border-slate-200 px-4 py-3 sm:px-5 lg:border-t-0">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Program deadline</dt>
                                <dd class="mt-1 font-bold text-slate-900">{{ application.scholarship?.deadline || 'Not listed' }}</dd>
                            </div>
                        </dl>
                    </header>

                    <section :class="['rounded-md border border-l-4 p-4 shadow-sm sm:p-5', applicantOwnsNextAction ? 'border-amber-300 border-l-amber-400 bg-amber-50' : applicationIsClosed ? 'border-emerald-200 border-l-emerald-500 bg-emerald-50/60' : 'border-slate-300 border-l-slate-800 bg-white']">
                        <div class="grid gap-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center">
                            <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-md', applicantOwnsNextAction ? 'bg-amber-300 text-slate-950' : applicationIsClosed ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-950 text-amber-300']">
                                <i :class="applicantOwnsNextAction ? 'fa-solid fa-arrow-right' : applicationIsClosed ? 'fa-solid fa-check' : 'fa-regular fa-clock'" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">{{ applicantNextEyebrow }}</p>
                                    <span v-if="!applicantOwnsNextAction && !applicationIsClosed" class="rounded bg-white px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-600 ring-1 ring-slate-200">No action needed</span>
                                </div>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">{{ applicantNextStep }}</h2>
                                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ applicantNextDescription }}</p>
                            </div>
                            <button
                                v-if="nextActionButton"
                                type="button"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto"
                                @click="followNextAction"
                            >
                                {{ nextActionButton.label }}
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </button>
                        </div>
                    </section>

                    <nav class="overflow-x-auto rounded-md border border-slate-300 bg-white p-1 shadow-sm" aria-label="Application sections">
                        <div class="flex min-w-max gap-1 sm:min-w-0" role="tablist">
                            <button
                                v-for="section in applicationSections"
                                :key="section.key"
                                type="button"
                                role="tab"
                                :aria-selected="activeSection === section.key"
                                :class="['flex items-center justify-center gap-2 rounded-sm px-3 py-2.5 text-sm font-bold transition sm:flex-1', activeSection === section.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950']"
                                @click="openSection(section.key)"
                            >
                                <i :class="section.icon" class="text-xs" aria-hidden="true"></i>
                                {{ section.label }}
                                <span v-if="section.count" :class="['rounded px-1.5 py-0.5 text-[10px] font-bold', activeSection === section.key ? 'bg-white/15 text-white' : 'bg-amber-100 text-amber-800']">{{ section.count }}</span>
                            </button>
                        </div>
                    </nav>

                    <div v-if="activeSection === 'overview'" class="space-y-4">
                        <section
                            v-if="application.correction_status"
                            id="application-correction"
                            :class="['scroll-mt-4 rounded-md border border-l-4 bg-white p-4 shadow-sm sm:p-5', application.correction_status === 'requested' ? 'border-slate-300 border-l-amber-400' : application.correction_status === 'submitted' ? 'border-slate-300 border-l-sky-500' : 'border-slate-300 border-l-emerald-500']"
                        >
                            <div class="flex items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1">
                                    <p class="student-kicker">Requested update</p>
                                    <h3 class="mt-1 text-base font-bold text-slate-950">{{ application.correction_status === 'requested' ? 'The provider needs more information' : application.correction_status === 'submitted' ? 'Your update is being reviewed' : 'Update completed' }}</h3>
                                    <p v-if="application.correction_message" class="mt-2 text-sm leading-6 text-slate-600">{{ application.correction_message }}</p>
                                    <div v-if="correctionTargets.length" class="mt-3 flex flex-wrap gap-1.5">
                                        <span v-for="target in correctionTargets" :key="target" class="rounded bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">{{ correctionTargetLabel(target) }}</span>
                                    </div>
                                    <div v-if="application.correction_status === 'requested'" class="mt-4 flex flex-wrap gap-2">
                                        <a v-if="correctionTargetsInclude('profile')" href="/dashboard/profile?section=personal" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Update profile</a>
                                        <a v-if="correctionTargetsInclude('academic_record')" href="/dashboard/profile?section=verification" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Update academic record</a>
                                        <button v-if="correctionTargetsInclude('application_files') || !correctionTargets.length" type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openSection('files')">Update files</button>
                                        <button type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="showCorrectionModal = true">Send response</button>
                                    </div>
                                    <p v-if="application.correction_response" class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600"><strong>Your response:</strong> {{ application.correction_response }}</p>
                                </div>
                            </div>
                        </section>

                        <section v-if="recipientAgreement" class="rounded-md border border-slate-300 bg-white p-4 shadow-sm sm:p-5">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-file-signature" aria-hidden="true"></i></span>
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-bold text-slate-950">Recipient agreement</h3>
                                            <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', recipientAgreement.status === 'accepted' ? 'bg-emerald-100 text-emerald-800' : recipientAgreement.status === 'declined' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-800']">{{ recipientAgreement.status_label }}</span>
                                        </div>
                                        <p class="mt-1 text-sm leading-5 text-slate-500">{{ recipientAgreement.can_respond ? 'Review what you will receive and what the provider expects before accepting.' : 'The support and responsibilities recorded when you were selected.' }}</p>
                                    </div>
                                </div>
                                <button type="button" class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="showRecipientAgreementModal = true">{{ recipientAgreement.can_respond ? 'Review and respond' : 'View agreement' }}</button>
                            </div>
                        </section>

                        <details
                            v-if="formalApplicationHandoff && !applicationIsClosed"
                            id="formal-application-handoff"
                            :open="formalHandoffOpen"
                            class="scroll-mt-4 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm"
                            @toggle="formalHandoffOpen = $event.currentTarget.open"
                        >
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4 hover:bg-slate-50 sm:p-5 [&::-webkit-details-marker]:hidden">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-800"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                                    <div class="min-w-0">
                                        <p class="student-kicker">Continue with provider</p>
                                        <h3 class="mt-1 font-bold text-slate-950">Formal application instructions</h3>
                                        <p class="mt-1 line-clamp-1 text-sm text-slate-500">{{ formalApplicationHandoff.notice }}</p>
                                    </div>
                                </div>
                                <i class="fa-solid fa-chevron-down shrink-0 text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                            </summary>
                            <div class="grid gap-4 border-t border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 sm:p-5">
                                <div class="rounded-md border border-slate-200 bg-white p-4">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Instructions</p>
                                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ formalApplicationHandoff.instructions || 'Follow the instructions provided by the scholarship provider.' }}</p>
                                    <p v-if="formalApplicationHandoff.deadline" class="mt-3 text-xs font-bold text-amber-800">Due {{ formalApplicationHandoff.deadline }}</p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-white p-4">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">What to prepare</p>
                                    <ul v-if="formalApplicationHandoff.requirements?.length" class="mt-2 space-y-2">
                                        <li v-for="item in formalApplicationHandoff.requirements" :key="item" class="flex items-start gap-2 text-sm text-slate-700"><i class="fa-solid fa-check mt-1 text-xs text-amber-700" aria-hidden="true"></i><span>{{ item }}</span></li>
                                    </ul>
                                    <p v-else class="mt-2 text-sm text-slate-500">The provider will confirm any additional documents.</p>
                                </div>
                                <div v-if="formalApplicationHandoff.location_name || formalApplicationHandoff.location_address" class="rounded-md border border-slate-200 bg-white p-4 sm:col-span-2">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Where to continue</p>
                                    <p class="mt-1 text-sm font-bold text-slate-950">{{ formalApplicationHandoff.location_name }}</p>
                                    <p class="mt-1 text-sm text-slate-600">{{ formalApplicationHandoff.location_address }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="openFormalHandoffMap">View map</button>
                                        <a v-if="formalApplicationHandoff.url" :href="formalApplicationHandoff.url" target="_blank" rel="noopener" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white">Continue online</a>
                                    </div>
                                </div>
                            </div>
                        </details>

                        <details v-if="hasProviderUpdate" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4 hover:bg-slate-50 sm:p-5 [&::-webkit-details-marker]:hidden">
                                <div class="flex items-center gap-3">
                                    <span class="grid h-9 w-9 place-items-center rounded-md bg-slate-100 text-slate-700"><i class="fa-solid fa-message" aria-hidden="true"></i></span>
                                    <div><p class="student-kicker">Provider update</p><h3 class="mt-1 font-bold text-slate-950">View feedback or result notes</h3></div>
                                </div>
                                <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                            </summary>
                            <dl class="divide-y divide-slate-200 border-t border-slate-200 bg-slate-50">
                                <div v-if="application.review_notes" class="p-4 sm:px-5"><dt class="text-xs font-bold text-slate-500">Provider message</dt><dd class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ application.review_notes }}</dd></div>
                                <div v-if="application.outcome_notes" class="p-4 sm:px-5"><dt class="text-xs font-bold text-slate-500">Outcome details</dt><dd class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ application.outcome_notes }}</dd></div>
                                <div v-if="application.decision_reason" class="p-4 sm:px-5"><dt class="text-xs font-bold text-slate-500">Decision reason</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ labelFromKey(application.decision_reason) }}</dd></div>
                            </dl>
                        </details>

                        <details v-if="application.status_progress" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4 hover:bg-slate-50 sm:p-5 [&::-webkit-details-marker]:hidden">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i class="fa-solid fa-route" aria-hidden="true"></i></span>
                                    <div class="min-w-0"><p class="student-kicker">Application process</p><h3 class="mt-1 truncate font-bold text-slate-950">{{ application.status_progress.current_stage_label }}</h3></div>
                                </div>
                                <div class="flex items-center gap-3"><span class="text-xs font-bold text-slate-600">{{ application.status_progress.percent }}%</span><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></div>
                            </summary>
                            <ol class="grid gap-2 border-t border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-4">
                                <li v-for="(step, index) in application.status_progress.steps" :key="step.key" :class="['flex items-center gap-3 rounded-md border p-3 text-xs', step.state === 'current' ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white']">
                                    <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-full text-[10px] font-bold', step.state === 'complete' ? 'bg-slate-950 text-white' : step.state === 'current' ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-500']"><i v-if="step.state === 'complete'" class="fa-solid fa-check" aria-hidden="true"></i><span v-else>{{ index + 1 }}</span></span>
                                    <div><p class="font-bold text-slate-800">{{ step.label }}</p><p class="mt-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-500">{{ progressStateLabel(step.state) }}</p></div>
                                </li>
                            </ol>
                        </details>

                        <details v-if="application.can_withdraw || application.status === 'withdrawn'" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-slate-600 hover:bg-slate-50 [&::-webkit-details-marker]:hidden"><span>Application options</span><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary>
                            <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-slate-600">Withdraw only if you no longer want the provider to continue reviewing this application.</p><button v-if="application.can_withdraw" type="button" class="rounded-md border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700" @click="showWithdrawalModal = true">Withdraw application</button><span v-else class="text-xs font-bold text-slate-500">Withdrawn {{ application.withdrawn_at }}</span></div>
                        </details>
                    </div>

                    <section v-if="activeSection === 'files'" class="student-card overflow-hidden rounded-md border-slate-300">
                        <header class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div><p class="student-kicker">Documents</p><h2 class="mt-1 text-lg font-bold text-slate-950">Application files</h2><p class="mt-1 text-sm text-slate-500">Upload only the file requested for each requirement.</p></div>
                            <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold', filesNeedingAction.length ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800']">{{ filesNeedingAction.length ? `${filesNeedingAction.length} need attention` : fileStatusLabel }}</span>
                        </header>
                        <div class="p-4 sm:p-5">
                            <div v-if="requiresOriginalVerification" class="mb-4 flex items-start gap-2 rounded-md bg-amber-50 p-3 text-xs leading-5 text-amber-950 ring-1 ring-amber-200"><i class="fa-solid fa-circle-info mt-1 text-amber-700" aria-hidden="true"></i><p>Keep the originals ready. Bring them only when the provider sends in-person instructions.</p></div>
                            <TermsAgreement v-model="documentTermsAccepted" context="document" />
                            <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" @change="handleFileChange">
                            <div class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-lg border border-slate-200">
                                <div v-for="row in applicationFileRows" :key="row.name" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2"><p class="text-sm font-bold text-slate-950">{{ row.name }}</p><span v-if="!row.required" class="rounded bg-slate-100 px-2 py-0.5 text-[9px] font-bold uppercase text-slate-500">Optional</span></div>
                                        <p v-if="row.document" class="mt-1 truncate text-xs text-slate-500">{{ row.document.original_name }} - {{ row.document.uploaded_at }}</p>
                                        <p v-else class="mt-1 text-xs text-slate-500">{{ row.required ? 'No file uploaded' : 'Upload only if this supports your application' }}</p>
                                        <p v-if="row.document?.review_notes" class="mt-1 text-xs font-semibold text-amber-800">Provider note: {{ row.document.review_notes }}</p>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                                        <span :class="['rounded-md px-2.5 py-2 text-[10px] font-bold uppercase', row.document ? documentStatusClass(row.document.status) : row.required ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500']">{{ row.document ? labelFromKey(row.document.status || 'pending') : row.required ? 'Not uploaded' : 'Optional' }}</span>
                                        <button v-if="row.document?.view_url" type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700" @click="openDocumentPreview(row.document)">View</button>
                                        <button type="button" :disabled="isUploading" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white disabled:opacity-60" @click="openUploadPicker(row.name)">{{ isUploading && activeUploadRequirement === row.name ? 'Uploading...' : row.document ? 'Replace' : 'Upload' }}</button>
                                        <button v-if="row.document" type="button" :disabled="isUploading" class="grid h-8 w-8 place-items-center rounded-md border border-slate-300 text-slate-500" :aria-label="`Remove ${row.name}`" @click="deleteDocument(row.document)"><i class="fa-solid fa-trash-can text-xs" aria-hidden="true"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section v-if="activeSection === 'schedule'" id="application-schedules" class="student-card scroll-mt-4 overflow-hidden rounded-md border-slate-300">
                        <header class="border-b border-slate-200 bg-slate-50/70 p-4 sm:p-5"><p class="student-kicker">Schedule</p><h2 class="mt-1 text-lg font-bold text-slate-950">{{ currentSchedule ? 'Your next activity' : 'Activity history' }}</h2><p class="mt-1 text-sm text-slate-500">{{ currentSchedule ? (currentSchedule.mode === 'online' ? 'Review the date, meeting link, and instructions before joining.' : 'Review the date, location, and instructions before attending.') : 'There is no upcoming activity.' }}</p></header>
                        <div v-if="currentSchedule" class="p-4 sm:p-5">
                            <div class="grid gap-4 rounded-md border border-slate-300 bg-slate-50 p-4 sm:grid-cols-[auto_minmax(0,1fr)]">
                                <div class="flex h-16 w-16 flex-col items-center justify-center rounded-md bg-slate-950 text-white"><span class="text-[10px] font-bold uppercase text-amber-300">{{ currentScheduleDate.month }}</span><span class="text-2xl font-bold">{{ currentScheduleDate.day }}</span></div>
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-amber-700">{{ scheduleTypeLabel(currentSchedule.type) }}</p><h3 class="mt-1 text-lg font-bold text-slate-950">{{ currentSchedule.title }}</h3><p class="mt-1 text-sm font-semibold text-slate-600">{{ currentSchedule.scheduled_label }}</p></div>
                                <dl class="grid gap-3 sm:col-span-2 sm:grid-cols-3"><div class="rounded-md bg-white p-3 ring-1 ring-slate-200"><dt class="text-[10px] font-bold uppercase text-slate-500">Time</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ currentScheduleDate.time }}</dd></div><div class="rounded-md bg-white p-3 ring-1 ring-slate-200"><dt class="text-[10px] font-bold uppercase text-slate-500">Mode</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ scheduleModeLabel(currentSchedule.mode) }}</dd></div><div class="rounded-md bg-white p-3 ring-1 ring-slate-200"><dt class="text-[10px] font-bold uppercase text-slate-500">{{ currentSchedule.mode === 'online' ? 'Meeting' : 'Venue' }}</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ currentSchedule.mode === 'online' ? (currentSchedule.online_url ? 'Meeting link ready' : 'See provider instructions') : (currentSchedule.venue || 'See provider instructions') }}</dd></div></dl>
                                <div class="sm:col-span-2"><p class="text-sm leading-6 text-slate-700">{{ currentSchedule.instructions }}</p><div class="mt-3 flex flex-wrap gap-2"><a v-if="currentSchedule.online_url" :href="currentSchedule.online_url" target="_blank" rel="noopener" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white">{{ currentSchedule.type === 'interview' ? 'Join interview' : 'Open online access' }}</a><button v-if="['onsite', 'hybrid'].includes(currentSchedule.mode) && (hasCoordinates(currentSchedule.latitude, currentSchedule.longitude) || currentSchedule.location_address || currentSchedule.venue)" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="openScheduleMap(currentSchedule)">View map</button></div></div>
                            </div>
                        </div>
                        <details v-if="scheduleHistory.length" class="group border-t border-slate-200"><summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-bold text-slate-700 sm:px-5 [&::-webkit-details-marker]:hidden"><span>Previous activities ({{ scheduleHistory.length }})</span><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary><div class="divide-y divide-slate-200 border-t border-slate-200 bg-slate-50"><div v-for="schedule in scheduleHistory" :key="schedule.id" class="flex items-center justify-between gap-3 px-4 py-3 sm:px-5"><div><p class="text-sm font-bold text-slate-900">{{ schedule.title || scheduleTypeLabel(schedule.type) }}</p><p class="mt-1 text-xs text-slate-500">{{ schedule.scheduled_label }}</p></div><span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', scheduleStatusClass(schedule.status)]">{{ labelFromKey(schedule.status) }}</span></div></div></details>
                    </section>

                    <section v-if="activeSection === 'program'" class="student-card overflow-hidden rounded-md border-slate-300">
                        <header class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div><p class="student-kicker">Scholarship</p><h2 class="mt-1 text-lg font-bold text-slate-950">Program information</h2><p class="mt-1 text-sm text-slate-500">Benefits, dates, and provider contact for this application.</p></div>
                            <a :href="`/dashboard/scholarships/${application.scholarship?.id}`" class="w-fit rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">View full scholarship</a>
                        </header>

                        <div class="p-4 sm:p-5">
                            <div class="flex items-center gap-3">
                                <img :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'" :alt="application.scholarship?.title" class="h-12 w-12 rounded-sm object-contain p-1 ring-1 ring-slate-200">
                                <div class="min-w-0"><h3 class="truncate font-bold text-slate-950">{{ application.scholarship?.title }}</h3><p class="mt-1 text-xs text-slate-500">{{ application.scholarship?.provider?.name }}</p></div>
                            </div>
                            <p v-if="application.scholarship?.description" class="mt-4 max-w-4xl text-sm leading-6 text-slate-600">{{ application.scholarship.description }}</p>

                            <div v-if="application.scholarship?.benefits?.length || application.scholarship?.benefit_summary || application.scholarship?.award_amount != null" class="mt-4 border-t border-slate-200 pt-4">
                                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Support provided</p>
                                <ul v-if="application.scholarship?.benefits?.length" class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <li v-for="benefit in application.scholarship.benefits" :key="`${benefit.type}-${benefit.title}`" class="flex items-start gap-2 border-l-2 border-amber-400 bg-amber-50/60 px-3 py-2 text-sm text-slate-700"><i class="fa-solid fa-check mt-1 text-xs text-amber-700" aria-hidden="true"></i><span><strong>{{ benefit.title }}</strong><span v-if="benefit.amount !== null && benefit.amount !== undefined && benefit.amount !== ''"> - {{ formatAwardAmount(benefit.amount) }}</span></span></li>
                                </ul>
                                <p v-else class="mt-2 text-sm font-bold text-slate-900">{{ application.scholarship?.benefit_summary || formatAwardAmount(application.scholarship?.award_amount) }}</p>
                            </div>

                            <dl class="mt-5 grid gap-px overflow-hidden border border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-5">
                                <div class="bg-slate-50 px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Program cycle</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ application.scholarship?.program_cycle || 'Not listed' }}</dd></div>
                                <div class="bg-slate-50 px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Deadline</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ application.scholarship?.deadline || 'Not listed' }}</dd></div>
                                <div class="bg-slate-50 px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Application mode</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ applicationModeLabel(application.scholarship?.application_mode) }}</dd></div>
                                <div class="bg-slate-50 px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Support period</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ supportPeriodLabel }}</dd></div>
                                <div class="bg-slate-50 px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Location</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ application.scholarship?.location_name || 'Not listed' }}</dd><button v-if="hasMapPreview" type="button" class="mt-1 text-xs font-bold text-amber-800" @click="openProgramMap">View map</button></div>
                            </dl>
                        </div>

                        <div class="divide-y divide-slate-200 border-t border-slate-200 bg-slate-50">
                            <div class="px-4 py-4 sm:px-5">
                                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Provider contact</p>
                                <p class="mt-1 text-sm font-bold text-slate-900">{{ application.scholarship?.contact_person || application.scholarship?.provider?.name || 'Scholarship provider' }}</p>
                                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600"><a v-if="application.scholarship?.contact_email" :href="`mailto:${application.scholarship.contact_email}`" class="font-semibold hover:text-slate-950">{{ application.scholarship.contact_email }}</a><a v-if="application.scholarship?.contact_number" :href="`tel:${application.scholarship.contact_number}`" class="font-semibold hover:text-slate-950">{{ application.scholarship.contact_number }}</a><span v-if="!application.scholarship?.contact_email && !application.scholarship?.contact_number">Contact details are available on the full listing.</span></div>
                            </div>
                            <div class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"><div><p class="text-sm font-bold text-slate-900">Profile comparison</p><p class="mt-1 text-xs text-slate-500">Review the profile values checked against the rules.</p></div><button type="button" class="w-fit shrink-0 rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="showProfileMatchModal = true">View match details</button></div>
                        </div>
                    </section>

                    <div v-if="activeSection === 'history'" class="space-y-4">
                        <section class="student-card overflow-hidden rounded-md border-slate-300"><header class="border-b border-slate-200 bg-slate-50/70 p-4 sm:p-5"><p class="student-kicker">History</p><h2 class="mt-1 text-lg font-bold text-slate-950">Application timeline</h2><p class="mt-1 text-sm text-slate-500">Status changes and provider updates are recorded here.</p></header><div v-if="timeline.length" class="divide-y divide-slate-200"><div v-for="event in timeline" :key="event.id" class="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-5"><div><p class="text-sm font-bold text-slate-900">{{ statusLabel(event.to_status) }}</p><p class="mt-1 text-xs text-slate-500">By {{ event.actor || 'System' }}</p><p v-if="event.review_notes" class="mt-2 text-sm leading-6 text-slate-600">{{ event.review_notes }}</p></div><p class="text-xs text-slate-500">{{ event.changed_at || 'Recently' }}</p></div></div><div v-else class="p-5 text-sm text-slate-500">No status changes have been recorded yet.</div></section>
                        <details v-if="application.application_answers?.length" class="group student-card overflow-hidden rounded-md border-slate-300"><summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-bold text-slate-700 sm:px-5 [&::-webkit-details-marker]:hidden"><span>Your submitted answers</span><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary><dl class="divide-y divide-slate-200 border-t border-slate-200"><div v-for="(answer, index) in application.application_answers" :key="answer.question_id || index" class="px-4 py-3 sm:px-5"><dt class="text-xs font-bold text-slate-500">{{ answer.prompt }}</dt><dd class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ answer.answer || 'No response provided' }}</dd></div></dl></details>
                        <details v-if="application.notes" class="group student-card overflow-hidden rounded-md border-slate-300"><summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-bold text-slate-700 sm:px-5 [&::-webkit-details-marker]:hidden"><span>Your submitted note</span><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary><p class="border-t border-slate-200 px-4 py-3 text-sm leading-6 text-slate-600 sm:px-5">{{ application.notes }}</p></details>
                    </div>
                </div>
            </div>
        </section>
    </main>

        <Teleport to="body">
            <div
                v-if="showProfileMatchModal && application"
                class="fixed inset-0 z-[2500] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5"
                @click.self="showProfileMatchModal = false"
                @keydown.esc="showProfileMatchModal = false"
            >
                <section
                    class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="application-profile-match-title"
                >
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-chart-simple" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Profile match</p>
                                <h2 id="application-profile-match-title" class="mt-1 text-xl font-bold text-slate-950">Your profile and this program</h2>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                            aria-label="Close profile match details"
                            @click="showProfileMatchModal = false"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
                                <div class="min-w-0">
                                    <p class="text-sm font-bold leading-6 text-slate-950">
                                        {{ application.dss_explanation?.headline || application.dss_breakdown?.summary || 'Your saved profile was compared with this program.' }}
                                    </p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        {{ application.dss_explanation?.next_action || 'Review the comparison and keep your profile information current.' }}
                                    </p>
                                </div>
                                <div class="flex w-fit shrink-0 items-baseline gap-2 rounded-md bg-slate-950 px-3 py-2 text-white">
                                    <span class="text-xl font-bold">{{ application.dss_score ?? 0 }}%</span>
                                    <span class="text-xs font-semibold text-slate-300">{{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}</span>
                                </div>
                            </div>
                            <div class="h-1.5 bg-slate-200">
                                <div class="h-full bg-amber-500" :style="{ width: `${Math.min(Math.max(Number(application.dss_score) || 0, 0), 100)}%` }"></div>
                            </div>
                        </section>

                        <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-950">Eligibility comparison</h3>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">Your submitted value is shown beside the rule used for this application.</p>
                                </div>
                                <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(application.eligibility_score)]">
                                    {{ application.eligibility_score ?? 0 }}% profile match
                                </span>
                            </div>

                            <div v-if="application.eligibility_breakdown?.criteria?.length" class="divide-y divide-slate-200">
                                <article v-for="criterion in application.eligibility_breakdown.criteria" :key="criterion.key" class="p-4 sm:p-5">
                                    <div class="flex items-start justify-between gap-3">
                                        <h4 class="text-sm font-bold text-slate-950">{{ eligibilityCriterionText(criterion.label, 'Eligibility requirement') }}</h4>
                                        <span :class="['shrink-0 rounded-md border px-2.5 py-1 text-xs font-bold', criterionClass(criterion.status)]">
                                            {{ eligibilityCriterionLabel(criterion) }}
                                        </span>
                                    </div>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        <div class="rounded-md bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Your profile</p>
                                            <p class="mt-1 text-sm font-semibold leading-5 text-slate-800">
                                                {{ eligibilityCriterionText(criterion.student_value || criterion.studentValue, 'Not provided') }}
                                            </p>
                                        </div>
                                        <div class="rounded-md bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Program rule</p>
                                            <p class="mt-1 text-sm font-semibold leading-5 text-slate-800">
                                                {{ criterion.status === 'info' && !criterion.requirement ? 'Open to all' : eligibilityCriterionText(criterion.requirement, 'No restriction') }}
                                            </p>
                                        </div>
                                    </div>
                                    <p v-if="criterion.comparison || criterion.note" class="mt-2 text-xs leading-5 text-slate-500">
                                        {{ criterion.comparison || criterion.note }}
                                    </p>
                                </article>
                            </div>
                            <p v-else class="p-5 text-sm leading-6 text-slate-500">
                                {{ application.eligibility_breakdown?.summary || 'No individual eligibility checks are available.' }}
                            </p>
                        </section>

                        <section v-if="eligibilityConditionResults.length" class="mt-4 rounded-lg border border-slate-200 bg-white p-4 sm:p-5">
                            <h3 class="text-sm font-bold text-slate-950">Provider-required conditions</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">These written conditions were saved when you submitted the application.</p>
                            <EligibilityConditionList class="mt-3" :conditions="eligibilityConditionResults" />
                        </section>

                        <div v-if="application.dss_explanation?.strengths?.length || application.dss_explanation?.needs_attention?.length" class="mt-4 grid gap-4 lg:grid-cols-2">
                            <section v-if="application.dss_explanation?.strengths?.length" class="rounded-lg border border-slate-200 bg-white p-4 sm:p-5">
                                <h3 class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-circle-check text-emerald-600" aria-hidden="true"></i> Where your profile aligns</h3>
                                <ul class="mt-3 space-y-2">
                                    <li v-for="item in application.dss_explanation.strengths" :key="item" class="flex items-start gap-2 text-xs leading-5 text-slate-600"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span><span>{{ item }}</span></li>
                                </ul>
                            </section>
                            <section v-if="application.dss_explanation?.needs_attention?.length" class="rounded-lg border border-slate-200 bg-white p-4 sm:p-5">
                                <h3 class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-circle-info text-amber-700" aria-hidden="true"></i> What to review</h3>
                                <ul class="mt-3 space-y-2">
                                    <li v-for="item in application.dss_explanation.needs_attention" :key="item" class="flex items-start gap-2 text-xs leading-5 text-slate-600"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span><span>{{ item }}</span></li>
                                </ul>
                            </section>
                        </div>

                        <details v-if="dssCriteria.length" class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                            <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-slate-800 sm:px-5">
                                <span>How the suitability score was calculated</span>
                                <i class="fa-solid fa-chevron-down text-xs text-slate-400" aria-hidden="true"></i>
                            </summary>
                            <div class="divide-y divide-slate-200 border-t border-slate-200 px-4 sm:px-5">
                                <div v-for="criterion in dssCriteria" :key="criterion.key" class="py-3 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                        <span class="text-xs font-bold text-slate-600">{{ criterionImpact(criterion) }}</span>
                                    </div>
                                    <p class="mt-1 text-xs font-bold uppercase tracking-[0.1em] text-slate-400">{{ criterion.score }}% score x {{ criterion.weight }}% weight</p>
                                    <p class="mt-1 leading-5 text-slate-600">{{ criterion.note }}</p>
                                </div>
                            </div>
                        </details>
                    </div>

                    <footer class="flex items-center justify-between gap-4 border-t border-slate-200 bg-white px-5 py-4 sm:px-6">
                        <p class="hidden max-w-2xl text-xs leading-5 text-slate-500 sm:block">{{ dssDecisionNotice }}</p>
                        <button
                            type="button"
                            class="ml-auto rounded-md bg-slate-950 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            @click="showProfileMatchModal = false"
                        >
                            Close
                        </button>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showRecipientAgreementModal && recipientAgreement"
                class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/75 p-3 backdrop-blur-[2px] sm:p-5"
                role="presentation"
                @click.self="closeRecipientAgreementModal"
                @keydown.esc="closeRecipientAgreementModal"
            >
                <section
                    class="flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-md border border-slate-700 bg-white shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="recipient-agreement-modal-title"
                >
                    <header class="border-t-4 border-amber-400 bg-slate-950 px-4 py-4 text-white sm:px-6 sm:py-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex min-w-0 items-start gap-3.5">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded bg-amber-400 text-slate-950">
                                    <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-300">Recipient agreement</p>
                                        <span
                                            :class="[
                                                'rounded px-2 py-1 text-[10px] font-bold uppercase tracking-wide ring-1',
                                                recipientAgreement.status === 'accepted'
                                                    ? 'bg-emerald-400/15 text-emerald-200 ring-emerald-300/30'
                                                    : recipientAgreement.status === 'declined'
                                                        ? 'bg-rose-400/15 text-rose-200 ring-rose-300/30'
                                                        : 'bg-amber-400/15 text-amber-200 ring-amber-300/30',
                                            ]"
                                        >
                                            {{ recipientAgreement.status_label }}
                                        </span>
                                    </div>
                                    <h2 id="recipient-agreement-modal-title" class="mt-1 text-xl font-bold text-white sm:text-2xl">Review your recipient agreement</h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-5 text-slate-300">Review one section at a time before accepting or declining the recorded terms.</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                :disabled="isSubmittingAgreement"
                                class="grid h-10 w-10 shrink-0 place-items-center rounded border border-white/20 text-slate-300 transition hover:bg-white/10 hover:text-white disabled:opacity-50"
                                aria-label="Close recipient agreement"
                                @click="closeRecipientAgreementModal"
                            >
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-100 p-3 sm:p-4">
                        <RecipientAgreementSummary :agreement="recipientAgreement" compact embedded />

                        <div v-if="recipientAgreement.can_respond" class="mt-4 overflow-hidden rounded-md border border-slate-200 border-t-4 border-t-amber-400 bg-white">
                            <div class="flex items-start gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded bg-slate-950 text-sm text-amber-300"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></span>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Your response</p>
                                    <h3 class="mt-1 font-bold text-slate-950">Confirm that you understand the agreement</h3>
                                    <p class="mt-1 text-sm leading-5 text-slate-600">Accept to continue as a recipient, or decline and explain your reason to the provider.</p>
                                </div>
                            </div>
                            <div class="space-y-4 p-4 sm:p-5">
                                <label class="flex cursor-pointer items-start gap-3 rounded border border-amber-200 bg-amber-50/60 p-3.5 text-sm leading-5 text-slate-700">
                                    <input v-model="agreementTermsAccepted" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-slate-950 focus:ring-slate-500">
                                    <span><strong class="text-slate-950">I reviewed the complete terms.</strong> I understand the listed benefits, responsibilities, proof, release conditions, timeframe, consequences, and exception process.</span>
                                </label>
                                <div>
                                    <div class="flex items-end justify-between gap-3">
                                        <label for="agreement-response-note" class="text-xs font-bold text-slate-800">Note to provider</label>
                                        <span class="text-[11px] text-slate-500">Optional to accept, required to decline</span>
                                    </div>
                                    <textarea
                                        id="agreement-response-note"
                                        v-model="agreementResponseNote"
                                        rows="3"
                                        maxlength="1000"
                                        placeholder="Add a question or explain why you cannot accept the agreement."
                                        class="mt-2 w-full rounded border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                    ></textarea>
                                    <p class="mt-1 text-right text-[11px] text-slate-400">{{ agreementResponseNote.length }}/1000</p>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="recipientAgreement.response_note" class="mt-4 rounded-md border border-slate-200 bg-white px-4 py-4 sm:px-5">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Your recorded note</p>
                            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ recipientAgreement.response_note }}</p>
                        </div>
                    </div>

                    <footer class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <p v-if="recipientAgreement.can_respond" class="text-xs leading-5 text-slate-500">
                            Your response is recorded and shared with the provider.
                        </p>
                        <p v-else class="text-xs leading-5 text-slate-500">
                            This agreement is retained with your application record.
                        </p>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
                            <button
                                type="button"
                                :disabled="isSubmittingAgreement"
                                class="rounded border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60"
                                @click="closeRecipientAgreementModal"
                            >
                                Close
                            </button>
                            <template v-if="recipientAgreement.can_respond">
                                <button
                                    type="button"
                                    :disabled="isSubmittingAgreement"
                                    class="inline-flex min-h-11 items-center justify-center rounded border border-rose-200 bg-white px-5 py-2.5 text-sm font-bold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                                    @click="submitRecipientAgreement('declined')"
                                >
                                    Decline agreement
                                </button>
                                <button
                                    type="button"
                                    :disabled="isSubmittingAgreement"
                                    class="inline-flex min-h-11 items-center justify-center rounded bg-slate-950 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60"
                                    @click="submitRecipientAgreement('accepted')"
                                >
                                    {{ isSubmittingAgreement ? 'Saving...' : 'Accept agreement' }}
                                </button>
                            </template>
                        </div>
                    </footer>
                </section>
            </div>
        </Teleport>

        <div v-if="showCorrectionModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6" @click.self="showCorrectionModal = false">
            <form class="w-full max-w-lg overflow-hidden rounded-lg bg-white shadow-2xl" @submit.prevent="submitCorrectionResponse">
                <div class="border-b border-slate-200 p-5">
                    <p class="student-kicker">Application correction</p>
                    <h2 class="mt-1 text-xl font-bold text-slate-950">Tell the provider what you updated</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Update the requested profile details or files first, then send a short response.</p>
                </div>
                <div class="p-5">
                    <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">{{ application?.correction_message }}</div>
                    <div v-if="correctionTargets.length" class="mt-3">
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Requested areas</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="target in correctionTargets" :key="target" class="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold text-slate-700">{{ correctionTargetLabel(target) }}</span>
                        </div>
                    </div>
                    <label class="mt-4 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500" for="correction-response">What did you update?</label>
                    <textarea id="correction-response" v-model="correctionResponse" rows="4" maxlength="1500" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-600" placeholder="Example: I replaced my report card with the latest copy."></textarea>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100" @click="showCorrectionModal = false">Cancel</button>
                    <button type="submit" :disabled="isSendingCorrection" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60">{{ isSendingCorrection ? 'Sending...' : 'Send correction' }}</button>
                </div>
            </form>
        </div>

        <div v-if="showWithdrawalModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6" @click.self="showWithdrawalModal = false">
            <form class="w-full max-w-lg overflow-hidden rounded-lg bg-white shadow-2xl" @submit.prevent="withdrawApplication">
                <div class="border-b border-slate-200 p-5">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-700">Withdraw application</p>
                    <h2 class="mt-1 text-xl font-bold text-slate-950">Stop this application?</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">The provider will stop reviewing it. This action cannot be reversed from the applicant portal.</p>
                </div>
                <div class="p-5">
                    <label class="block text-xs font-bold uppercase tracking-[0.12em] text-slate-500" for="withdrawal-reason">Reason for withdrawing</label>
                    <textarea id="withdrawal-reason" v-model="withdrawalReason" rows="4" maxlength="1000" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-400" placeholder="Briefly explain why you no longer want to continue."></textarea>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100" @click="showWithdrawalModal = false">Keep application</button>
                    <button type="submit" :disabled="isWithdrawing" class="rounded-md bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-800 disabled:opacity-60">{{ isWithdrawing ? 'Withdrawing...' : 'Withdraw application' }}</button>
                </div>
            </form>
        </div>

        <LocationMapModal
            v-if="activeMapPreview"
            open
            :eyebrow="activeMapPreview.eyebrow"
            :title="activeMapPreview.title"
            :address="activeMapPreview.address"
            :latitude="activeMapPreview.latitude"
            :longitude="activeMapPreview.longitude"
            :marker-text="activeMapPreview.markerText"
            :secondary-latitude="activeMapPreview.secondaryLatitude"
            :secondary-longitude="activeMapPreview.secondaryLongitude"
            :secondary-marker-text="activeMapPreview.secondaryMarkerText"
            :distance-label="activeMapPreview.distanceLabel"
            :note="activeMapPreview.note"
            @close="closeMapModal"
        />
</template>
