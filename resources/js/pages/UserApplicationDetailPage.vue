<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import PrivacyNoticeCard from '../components/PrivacyNoticeCard.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { formatFileSize, labelFromKey as formatKeyLabel } from '../support/display';
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
const showMapModal = ref(false);
const documentTermsAccepted = ref(false);
const showWithdrawalModal = ref(false);
const withdrawalReason = ref('');
const isWithdrawing = ref(false);
const showCorrectionModal = ref(false);
const correctionResponse = ref('');
const isSendingCorrection = ref(false);
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
const dssDecisionNotice = computed(() => application.value?.dss_breakdown?.decision_notice ?? 'This score supports screening only. The scholarship provider makes the final decision.');
const rubricReview = computed(() => application.value?.rubric_review ?? null);
const rubricCriteria = computed(() => rubricReview.value?.criteria ?? []);
const workflow = computed(() => application.value?.workflow ?? {});
const formalApplicationHandoff = computed(() => application.value?.formal_application_handoff ?? null);
const handoffRequiresOriginalDocuments = computed(() => formalApplicationHandoff.value?.mode === 'onsite'
    && (formalApplicationHandoff.value?.requirements?.length ?? 0) > 0);
const applicantNextActionDetails = computed(() => workflow.value.next_action
    ?? application.value?.status_progress?.next_action_details
    ?? {});
const applicantNextStep = computed(() => application.value?.correction_status === 'requested'
    ? 'Update the information requested by the provider'
    : (workflow.value.next_action?.label ?? applicantNextAction(application.value)));
const applicantNextActor = computed(() => application.value?.correction_status === 'requested'
    ? 'You'
    : (applicantNextActionDetails.value.actor_label ?? 'Check application'));
const applicantNextDescription = computed(() => application.value?.correction_status === 'requested'
    ? (application.value.correction_message || 'Review the provider request, update the affected profile details or files, then send your response.')
    : (applicantNextActionDetails.value.description ?? 'Open the application for the latest instructions.'));
const timeline = computed(() => application.value?.timeline ?? []);
const schedules = computed(() => application.value?.schedules ?? []);
const currentSchedule = computed(() => schedules.value.find((schedule) => (
    schedule.status === 'scheduled'
    && ['exam', 'interview'].includes(schedule.type)
)) ?? null);
const scheduleHistory = computed(() => schedules.value.filter((schedule) => schedule.status !== 'scheduled'));
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
const applicationIsClosed = computed(() => Boolean(workflow.value.is_closed));
const applicationSections = computed(() => [
    { key: 'overview', label: 'Overview', icon: 'fa-solid fa-route' },
    { key: 'files', label: 'Files', icon: 'fa-solid fa-folder-open', count: filesNeedingAction.value.length },
    { key: 'schedule', label: 'Schedule', icon: 'fa-regular fa-calendar', count: currentSchedule.value ? 1 : 0 },
    { key: 'program', label: 'Program', icon: 'fa-solid fa-graduation-cap' },
    { key: 'history', label: 'History', icon: 'fa-solid fa-clock-rotate-left' },
]);
const nextActionButton = computed(() => {
    if (application.value?.correction_status === 'requested') {
        return { label: 'Review requested update', action: 'correction' };
    }

    if (formalApplicationHandoff.value) {
        return { label: 'View formal application steps', section: 'overview', target: 'formal-application-handoff' };
    }

    if (!applicationIsClosed.value && filesNeedingAction.value.length) {
        return { label: 'Review required files', section: 'files' };
    }

    if (currentSchedule.value) {
        return { label: 'View schedule', section: 'schedule', target: 'application-schedules' };
    }

    return null;
});
const applicationScholarship = computed(() => application.value?.scholarship ?? null);
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
    };

    if (labels[status]) {
        return labels[status];
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

    const activeSchedule = current.schedules?.find((schedule) => schedule.status === 'scheduled');

    if (activeSchedule) {
        return `Follow the posted ${scheduleTypeLabel(activeSchedule.type)} instructions and attend at the scheduled time.`;
    }

    if (current.formal_application_handoff) {
        return 'You passed portal pre-screening. Review what to bring and continue directly with the provider.';
    }

    if (current.workflow?.is_closed) {
        return 'Review the final provider result and notes for this application.';
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

onMounted(loadApplication);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <ApplicantPageHeader
                    eyebrow="My application"
                    title="Application details"
                    description="Check your status, next required action, files, and provider updates."
                    icon="fa-solid fa-file-circle-check"
                    action-href="/dashboard/applications"
                    action-label="Back to submissions"
                    secondary-href="/dashboard/documents"
                    secondary-label="Documents"
                />

                <PrivacyNoticeCard context="application" />

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading application details...
                </div>

                <div v-else-if="errorMessage && !application" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else-if="application" class="mt-6 space-y-4">
                    <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </div>

                    <section class="student-card overflow-hidden border-l-4 border-l-amber-400">
                        <div class="flex flex-col gap-5 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div class="flex min-w-0 gap-4">
                                <img
                                    :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="application.scholarship?.title || 'Scholarship'"
                                    class="h-14 w-14 shrink-0 rounded-md bg-slate-50 object-contain p-2 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0">
                                    <p class="student-kicker">
                                        Application #{{ application.id }}
                                    </p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950 sm:text-xl">
                                        {{ application.scholarship?.title || 'Scholarship' }}
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ application.scholarship?.provider?.name || 'Scholarship provider' }}
                                    </p>
                                </div>
                            </div>

                            <div class="shrink-0 sm:text-right">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Current status</p>
                                <span :class="['mt-2 inline-flex w-fit rounded-md px-3 py-1.5 text-xs font-bold uppercase', statusClass(application.status)]">
                                    {{ workflow.final_outcome_label || workflow.application_state_label || statusLabel(application.status) }}
                                </span>
                            </div>
                        </div>

                        <dl class="grid border-t border-slate-200 bg-slate-50/80 text-sm sm:grid-cols-2 lg:grid-cols-4">
                            <div class="border-b border-slate-200 px-4 py-3 sm:border-r lg:border-b-0">
                                <dt class="text-xs font-semibold text-slate-500">Submitted</dt>
                                <dd class="mt-1 font-bold text-slate-900">{{ application.submitted_at || 'Recently' }}</dd>
                            </div>
                            <div class="border-b border-slate-200 px-4 py-3 lg:border-b-0 lg:border-r">
                                <dt class="text-xs font-semibold text-slate-500">Current stage</dt>
                                <dd class="mt-1 font-bold text-slate-900">{{ application.status_progress?.current_stage_label || statusLabel(application.status) }}</dd>
                            </div>
                            <div class="border-b border-slate-200 px-4 py-3 sm:border-b-0 sm:border-r">
                                <dt class="text-xs font-semibold text-slate-500">Required files</dt>
                                <dd :class="['mt-1 font-bold', filesNeedingAction.length ? 'text-amber-800' : 'text-slate-900']">{{ fileStatusLabel }}</dd>
                            </div>
                            <div class="px-4 py-3">
                                <dt class="text-xs font-semibold text-slate-500">Program deadline</dt>
                                <dd class="mt-1 font-bold text-slate-900">{{ application.scholarship?.deadline || 'Not listed' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="student-card flex flex-col gap-4 border-l-4 border-l-slate-950 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                        <div class="flex items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                                <i :class="applicationIsClosed ? 'fa-solid fa-flag-checkered' : 'fa-solid fa-arrow-right'" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="student-kicker">{{ applicationIsClosed ? 'Final update' : 'What happens next' }}</p>
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600">{{ applicantNextActor }}</span>
                                </div>
                                <h3 class="mt-1 text-base font-bold leading-6 text-slate-950">{{ applicantNextStep }}</h3>
                                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ applicantNextDescription }}</p>
                            </div>
                        </div>
                        <button
                            v-if="nextActionButton"
                            type="button"
                            class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            @click="followNextAction"
                        >
                            {{ nextActionButton.label }}
                        </button>
                        <span v-else class="w-fit shrink-0 rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">
                            {{ applicationIsClosed ? 'Process complete' : 'No action needed now' }}
                        </span>
                    </section>

                    <nav class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-1 shadow-sm" aria-label="Application details sections">
                        <div class="flex min-w-max gap-1 sm:min-w-0" role="tablist">
                            <button
                                v-for="section in applicationSections"
                                :key="section.key"
                                type="button"
                                role="tab"
                                :aria-selected="activeSection === section.key"
                                :class="[
                                    'flex items-center justify-center gap-2 rounded-md px-3 py-2.5 text-sm font-bold transition sm:flex-1',
                                    activeSection === section.key
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950',
                                ]"
                                @click="openSection(section.key)"
                            >
                                <i :class="section.icon" class="text-xs" aria-hidden="true"></i>
                                {{ section.label }}
                                <span
                                    v-if="section.count"
                                    :class="[
                                        'rounded px-1.5 py-0.5 text-[10px] font-bold',
                                        activeSection === section.key ? 'bg-white/15 text-white' : 'bg-amber-100 text-amber-800',
                                    ]"
                                >
                                    {{ section.count }}
                                </span>
                            </button>
                        </div>
                    </nav>

                    <div class="space-y-4">
                        <div class="flex flex-col gap-4">
                            <section v-if="activeSection === 'overview' && application.status_progress" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="student-section-head p-4 sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <span class="student-section-mark">
                                            <i class="fa-solid fa-route" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="student-kicker">Application flow</p>
                                            <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            {{ application.status_progress.current_stage_label }}
                                            </h3>
                                            <p class="mt-1 text-sm leading-5 text-slate-500">See what is complete, where you are now, and what remains.</p>
                                        </div>
                                    </div>
                                    <div class="w-full sm:w-44">
                                        <div class="flex items-center justify-between text-xs font-bold text-slate-600">
                                            <span>Progress</span>
                                            <span>{{ application.status_progress.percent }}%</span>
                                        </div>
                                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                            <div class="h-full rounded-full bg-slate-950 transition-all" :style="{ width: `${application.status_progress.percent}%` }"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="border-t border-slate-200 bg-slate-50/70 p-3 sm:p-4">
                                    <ol class="grid gap-2 sm:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))]">
                                        <li
                                            v-for="(step, index) in application.status_progress.steps"
                                            :key="step.key"
                                            :class="[
                                                'flex min-w-0 items-center gap-3 rounded-md border px-3 py-3 text-xs',
                                                step.state === 'current' ? 'border-amber-300 bg-amber-50 text-slate-950' : step.state === 'complete' ? 'border-slate-200 bg-white text-slate-800' : ['stopped', 'skipped'].includes(step.state) ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-slate-200 bg-white text-slate-500',
                                            ]"
                                        >
                                            <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold', step.state === 'complete' ? 'bg-slate-900 text-white' : step.state === 'current' ? 'bg-amber-400 text-slate-950 ring-4 ring-amber-100' : ['stopped', 'skipped'].includes(step.state) ? 'bg-rose-100 text-rose-700' : 'border border-slate-300 bg-white text-slate-500']">
                                                <i v-if="step.state === 'complete'" class="fa-solid fa-check" aria-hidden="true"></i>
                                                <span v-else>{{ index + 1 }}</span>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="truncate font-bold">{{ step.label }}</p>
                                                <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-[0.08em] opacity-70">{{ progressStateLabel(step.state) }}</p>
                                            </div>
                                        </li>
                                    </ol>
                                </div>
                            </section>

                            <details
                                v-if="activeSection === 'overview' && formalApplicationHandoff"
                                id="formal-application-handoff"
                                :open="formalHandoffOpen"
                                class="scroll-mt-4 overflow-hidden rounded-lg border border-amber-200 bg-white shadow-sm"
                                @toggle="formalHandoffOpen = $event.currentTarget.open"
                            >
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 hover:bg-amber-50/60 [&::-webkit-details-marker]:hidden">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-sm text-amber-300">
                                            <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="student-kicker">Pre-screening passed</p>
                                            <h3 class="mt-0.5 text-base font-bold text-slate-950">Continue with the provider</h3>
                                            <p class="mt-0.5 line-clamp-1 text-xs text-slate-500">{{ formalApplicationHandoff.notice }}</p>
                                        </div>
                                    </div>
                                    <span class="flex shrink-0 items-center gap-2 text-xs font-bold text-slate-600">
                                        <span class="hidden sm:inline">{{ formalHandoffOpen ? 'Hide details' : 'View details' }}</span>
                                        <i :class="['fa-solid fa-chevron-down transition-transform', formalHandoffOpen ? 'rotate-180' : '']" aria-hidden="true"></i>
                                    </span>
                                </summary>

                                <div class="p-4">
                                    <div
                                        v-if="formalApplicationHandoff.location_name || formalApplicationHandoff.location_address || formalApplicationHandoff.url"
                                        class="flex flex-wrap items-stretch gap-2 border-b border-slate-200 pb-3"
                                    >
                                        <div v-if="formalApplicationHandoff.location_name || formalApplicationHandoff.location_address" class="flex w-full items-start gap-2 rounded-md bg-slate-50 px-2.5 py-2 ring-1 ring-slate-200 sm:w-auto sm:max-w-sm">
                                            <span class="grid h-6 w-6 shrink-0 place-items-center rounded bg-white text-[10px] text-slate-700 ring-1 ring-slate-200">
                                                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[9px] font-bold uppercase tracking-[0.12em] text-slate-500">Where to continue</p>
                                                <p v-if="formalApplicationHandoff.location_name" class="text-xs font-bold text-slate-950">{{ formalApplicationHandoff.location_name }}</p>
                                                <p v-if="formalApplicationHandoff.location_address" class="mt-0.5 line-clamp-1 text-[11px] leading-4 text-slate-600">{{ formalApplicationHandoff.location_address }}</p>
                                                <a v-if="formalApplicationHandoff.map_url" :href="formalApplicationHandoff.map_url" target="_blank" rel="noopener" class="mt-0.5 inline-flex items-center gap-1 text-[11px] font-bold text-sky-700">
                                                    View map
                                                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]" aria-hidden="true"></i>
                                                </a>
                                            </div>
                                        </div>

                                        <a v-if="formalApplicationHandoff.url" :href="formalApplicationHandoff.url" target="_blank" rel="noopener" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white sm:w-auto">
                                            Continue on provider site
                                            <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                        </a>
                                    </div>

                                    <div :class="formalApplicationHandoff.location_name || formalApplicationHandoff.location_address || formalApplicationHandoff.url ? 'pt-4' : ''">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Provider documents</p>
                                                <p class="mt-0.5 text-sm font-bold text-slate-950">What to prepare for the next step</p>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span v-if="formalApplicationHandoff.deadline" class="inline-flex items-center gap-1.5 rounded-md bg-amber-50 px-2 py-1 text-xs font-bold text-amber-900 ring-1 ring-amber-200">
                                                    <i class="fa-solid fa-calendar-day text-[10px] text-amber-700" aria-hidden="true"></i>
                                                    Due {{ formalApplicationHandoff.deadline }}
                                                </span>
                                                <span v-if="formalApplicationHandoff.requirements?.length" class="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">
                                                    {{ formalApplicationHandoff.requirements.length }} {{ formalApplicationHandoff.requirements.length === 1 ? 'item' : 'items' }}
                                                </span>
                                            </div>
                                        </div>
                                        <ul v-if="formalApplicationHandoff.requirements?.length" class="mt-2 flex flex-wrap gap-2">
                                            <li v-for="item in formalApplicationHandoff.requirements" :key="item" class="flex min-w-[15rem] flex-1 items-start gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm leading-5 text-slate-700 ring-1 ring-slate-200">
                                                <i class="fa-solid fa-circle-check mt-1 text-xs text-emerald-700" aria-hidden="true"></i>
                                                <span>{{ item }}</span>
                                            </li>
                                        </ul>
                                        <p v-else class="mt-2 text-sm text-slate-500">The provider will confirm the document list directly.</p>
                                        <p v-if="!handoffRequiresOriginalDocuments" class="mt-3 flex items-start gap-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs leading-5 text-sky-900">
                                            <i class="fa-solid fa-circle-info mt-1 shrink-0 text-sky-700" aria-hidden="true"></i>
                                            <span>Bring or send these directly to the provider as instructed. Upload them here only if they also appear in your portal checklist.</span>
                                        </p>
                                    </div>

                                    <div v-if="handoffRequiresOriginalDocuments" class="mt-3 flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-5 text-amber-950">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-amber-100 text-xs text-amber-800">
                                            <i class="fa-solid fa-file-shield" aria-hidden="true"></i>
                                        </span>
                                        <div>
                                            <p class="font-bold">Bring the original documents for this step</p>
                                            <p class="mt-0.5 text-amber-900">Bring the listed originals to the provider. Keep your portal copies unchanged unless a replacement is requested.</p>
                                        </div>
                                    </div>

                                    <div
                                        v-if="formalApplicationHandoff.instructions || formalApplicationHandoff.contact_person || formalApplicationHandoff.contact_department || formalApplicationHandoff.contact_email || formalApplicationHandoff.contact_number"
                                        class="mt-4 border-t border-slate-200 pt-4"
                                    >
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Provider instructions and contact</p>
                                        <p v-if="formalApplicationHandoff.instructions" class="mt-1.5 whitespace-pre-line text-sm leading-5 text-slate-700">{{ formalApplicationHandoff.instructions }}</p>
                                        <div v-if="formalApplicationHandoff.contact_person || formalApplicationHandoff.contact_department || formalApplicationHandoff.contact_email || formalApplicationHandoff.contact_number" class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-600">
                                            <span v-if="formalApplicationHandoff.contact_person">Contact: {{ formalApplicationHandoff.contact_person }}</span>
                                            <span v-if="formalApplicationHandoff.contact_department">{{ formalApplicationHandoff.contact_department }}</span>
                                            <a v-if="formalApplicationHandoff.contact_email" :href="`mailto:${formalApplicationHandoff.contact_email}`" class="font-bold text-sky-700">{{ formalApplicationHandoff.contact_email }}</a>
                                            <a v-if="formalApplicationHandoff.contact_number" :href="`tel:${formalApplicationHandoff.contact_number}`" class="font-bold text-sky-700">{{ formalApplicationHandoff.contact_number }}</a>
                                        </div>
                                    </div>
                                </div>
                            </details>

                            <section v-if="activeSection === 'program' && rubricReview" class="order-3 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="student-section-head p-4 sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <span class="student-section-mark"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
                                        <div>
                                            <p class="student-kicker">Provider assessment</p>
                                            <h3 class="mt-1 text-lg font-bold text-slate-950">Review rubric score</h3>
                                            <p class="mt-1 text-sm leading-5 text-slate-500">
                                            This is how the provider scored your submitted application against its review criteria.
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex w-fit items-baseline gap-1 rounded-md bg-slate-950 px-4 py-2 text-white">
                                        <span class="text-2xl font-bold">{{ rubricReview.total_score }}</span>
                                        <span class="text-xs font-semibold text-slate-300">/ 100</span>
                                    </div>
                                </div>

                                <div class="grid gap-3 border-t border-slate-200 bg-slate-50/70 p-4 sm:grid-cols-2">
                                    <div
                                        v-for="criterion in rubricCriteria"
                                        :key="criterion.key"
                                        class="rounded-md border border-slate-200 bg-white p-3"
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                            <p class="shrink-0 text-sm font-bold text-slate-950">{{ criterion.score }} / 100</p>
                                        </div>
                                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div
                                                class="h-full rounded-full bg-amber-400"
                                                :style="{ width: `${Math.min(100, Math.max(0, Number(criterion.score) || 0))}%` }"
                                            ></div>
                                        </div>
                                        <p class="mt-2 text-xs font-semibold text-slate-500">
                                            {{ criterion.weight }}% of the total score
                                        </p>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-1 border-t border-slate-200 px-4 py-3 text-xs leading-5 text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                                    <p>{{ rubricReview.decision_notice }}</p>
                                    <p v-if="application.rubric_scored_at" class="shrink-0 font-semibold">
                                        Scored {{ application.rubric_scored_at }}
                                    </p>
                                </div>
                            </section>

                            <section v-if="activeSection === 'schedule' && schedules.length" id="application-schedules" class="scroll-mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="student-section-head p-4 sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <span class="student-section-mark"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span>
                                        <div>
                                            <p class="student-kicker">Schedule</p>
                                            <h3 class="mt-1 text-lg font-bold text-slate-950">{{ currentSchedule ? 'Your next activity' : 'No upcoming activity' }}</h3>
                                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                            {{ currentSchedule ? 'Check when, where, and what you need to prepare.' : 'Previous activities are kept below for reference.' }}
                                            </p>
                                        </div>
                                    </div>
                                    <span v-if="currentSchedule" class="w-fit rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-900">
                                        Upcoming
                                    </span>
                                </div>

                                <div v-if="currentSchedule" class="border-t border-slate-200 p-4">
                                    <article class="overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                        <div class="flex flex-col gap-4 bg-white p-4 sm:flex-row sm:items-center">
                                            <div class="flex h-20 w-20 shrink-0 flex-col items-center justify-center rounded-md bg-slate-950 text-white shadow-sm">
                                                <span class="text-xs font-bold uppercase tracking-[0.14em] text-amber-300">{{ currentScheduleDate.month }}</span>
                                                <span class="mt-0.5 text-3xl font-bold leading-none">{{ currentScheduleDate.day }}</span>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="text-xs font-bold uppercase tracking-[0.12em] text-amber-700">{{ scheduleTypeLabel(currentSchedule.type) }}</p>
                                                    <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', scheduleStatusClass(currentSchedule.status)]">
                                                        {{ labelFromKey(currentSchedule.status) }}
                                                    </span>
                                                </div>
                                                <h4 class="mt-1 text-lg font-bold text-slate-950">{{ currentSchedule.title }}</h4>
                                                <p class="mt-1 text-sm font-semibold text-slate-600">{{ currentSchedule.scheduled_label }}</p>
                                            </div>
                                            <span class="inline-flex w-fit items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">
                                                <i class="fa-solid fa-location-arrow text-amber-700" aria-hidden="true"></i>
                                                {{ scheduleModeLabel(currentSchedule.mode) }}
                                            </span>
                                        </div>

                                        <dl class="grid border-t border-slate-200 bg-slate-50 sm:grid-cols-3">
                                            <div class="p-3 sm:border-r sm:border-slate-200">
                                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Time</dt>
                                                <dd class="mt-1 text-sm font-bold text-slate-900">{{ currentScheduleDate.time }}</dd>
                                            </div>
                                            <div class="border-t border-slate-200 p-3 sm:border-r sm:border-t-0">
                                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Venue</dt>
                                                <dd class="mt-1 line-clamp-2 text-sm font-bold text-slate-900">{{ currentSchedule.venue || (currentSchedule.mode === 'online' ? 'Online access' : 'See address below') }}</dd>
                                            </div>
                                            <div class="border-t border-slate-200 p-3 sm:border-t-0">
                                                <dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Application stage</dt>
                                                <dd class="mt-1 text-sm font-bold text-slate-900">{{ workflow.current_stage_label || scheduleTypeLabel(currentSchedule.type) }}</dd>
                                            </div>
                                        </dl>

                                        <div class="border-t border-slate-200 bg-white p-4">
                                            <div class="grid gap-3 lg:grid-cols-2">
                                                <div v-if="currentSchedule.venue || currentSchedule.location_address" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Address</p>
                                                    <p class="mt-1 text-sm font-bold leading-5 text-slate-900">{{ currentSchedule.location_address || currentSchedule.venue }}</p>
                                                    <p v-if="currentSchedule.location_address && currentSchedule.venue" class="mt-1 text-xs text-slate-500">{{ currentSchedule.venue }}</p>
                                                </div>
                                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">What to prepare</p>
                                                    <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ currentSchedule.instructions }}</p>
                                                </div>
                                            </div>

                                            <div class="mt-3 flex flex-wrap gap-2">
                                                <a
                                                    v-if="currentSchedule.online_url"
                                                    :href="currentSchedule.online_url"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex items-center gap-2 rounded-md bg-slate-950 px-3 py-2.5 text-sm font-bold text-white hover:bg-slate-800"
                                                >
                                                    Open online access
                                                    <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                                </a>
                                                <details
                                                    v-if="hasCoordinates(currentSchedule.latitude, currentSchedule.longitude) || currentSchedule.location_address || currentSchedule.venue"
                                                    class="group/map w-full"
                                                >
                                                    <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                                        <i class="fa-solid fa-map-location-dot text-amber-700" aria-hidden="true"></i>
                                                        View map
                                                    </summary>
                                                    <div class="mt-3 w-full overflow-hidden rounded-md border border-slate-200">
                                                        <LeafletMapPreview
                                                            :address="currentSchedule.location_address || currentSchedule.venue"
                                                            :latitude="currentSchedule.latitude"
                                                            :longitude="currentSchedule.longitude"
                                                            :title="currentSchedule.venue || currentSchedule.title"
                                                            :marker-text="currentSchedule.venue || currentSchedule.title"
                                                            height="11rem"
                                                            auto-geocode
                                                        />
                                                    </div>
                                                </details>
                                            </div>
                                        </div>
                                    </article>
                                </div>

                                <div v-if="scheduleHistory.length" :class="currentSchedule ? 'border-t border-slate-200' : ''" class="p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">Previous activities</p>
                                            <p class="mt-0.5 text-xs text-slate-500">Completed and cancelled schedule records.</p>
                                        </div>
                                        <span class="text-xs font-bold text-slate-500">{{ scheduleHistory.length }}</span>
                                    </div>

                                    <div class="mt-3 overflow-hidden rounded-md border border-slate-200">
                                        <details v-for="schedule in scheduleHistory" :key="schedule.id" class="group border-b border-slate-200 last:border-b-0">
                                            <summary class="flex cursor-pointer list-none items-center gap-3 bg-white px-3 py-3 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                                                    <i :class="scheduleTypeIcon(schedule.type)" aria-hidden="true"></i>
                                                </span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="truncate text-sm font-bold text-slate-950">{{ scheduleTypeLabel(schedule.type) }}</p>
                                                    <p class="mt-0.5 text-xs text-slate-500">{{ schedule.scheduled_label }}</p>
                                                </div>
                                                <span :class="['hidden rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', scheduleStatusClass(schedule.status)]">{{ labelFromKey(schedule.status) }}</span>
                                                <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                            </summary>
                                            <div class="border-t border-slate-200 bg-slate-50 p-3 text-sm">
                                                <p v-if="schedule.venue || schedule.location_address" class="font-semibold text-slate-700">{{ schedule.location_address || schedule.venue }}</p>
                                                <p class="mt-2 whitespace-pre-line leading-6 text-slate-600">{{ schedule.instructions }}</p>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'schedule' && !schedules.length" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="font-bold text-slate-950">{{ applicationIsClosed ? 'No schedule required' : 'No schedule posted yet' }}</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            {{ applicationIsClosed
                                                ? 'This application has no upcoming activity to attend. Review the provider update for the recorded outcome.'
                                                : 'There is no exam or interview date for you to attend right now. You will receive an update when the provider publishes one.' }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'schedule' && application.exam" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="grid sm:grid-cols-[9rem_minmax(0,1fr)]">
                                    <div class="flex h-36 items-center justify-center border-b border-slate-200 bg-slate-50 p-4 sm:border-b-0 sm:border-r">
                                        <img :src="application.exam.image_url" :alt="application.exam.title" class="h-full w-full object-contain">
                                    </div>
                                    <div class="p-4">
                                        <p class="student-kicker">Provider-managed exam</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ application.exam.title }}</h3>
                                        <p v-if="application.exam.description" class="mt-2 text-sm leading-6 text-slate-600">{{ application.exam.description }}</p>
                                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-700">
                                            <span v-if="application.exam.duration_minutes" class="rounded-md bg-slate-100 px-2.5 py-1">{{ application.exam.duration_minutes }} minutes</span>
                                            <span v-if="application.exam.passing_score !== null" class="rounded-md bg-slate-100 px-2.5 py-1">{{ Number(application.exam.passing_score) }}% passing score</span>
                                            <span class="rounded-md bg-slate-100 px-2.5 py-1">{{ labelFromKey(application.exam.delivery_mode) }}</span>
                                        </div>
                                        <div v-if="application.exam.venue || application.exam.instructions" class="mt-3 border-t border-slate-200 pt-3 text-sm leading-6 text-slate-600">
                                            <p v-if="application.exam.venue"><span class="font-bold text-slate-800">Venue:</span> {{ application.exam.venue }}</p>
                                            <p v-if="application.exam.instructions" class="mt-1">{{ application.exam.instructions }}</p>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'program'" class="order-1 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <img
                                            :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                            :alt="application.scholarship?.title || 'Scholarship program'"
                                            class="h-11 w-11 shrink-0 rounded-md bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0">
                                            <p class="student-kicker">Program</p>
                                            <h3 class="mt-0.5 text-base font-bold leading-tight text-slate-950">{{ application.scholarship?.title || 'Scholarship program' }}</h3>
                                            <p class="mt-0.5 truncate text-xs font-semibold text-slate-500">{{ application.scholarship?.provider?.name || 'Scholarship provider' }}</p>
                                        </div>
                                    </div>
                                    <a
                                        :href="`/dashboard/scholarships/${application.scholarship?.id}`"
                                        class="inline-flex w-fit shrink-0 items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                    >
                                        Full details
                                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </div>

                                <p class="line-clamp-2 border-t border-slate-200 px-4 py-3 text-sm leading-5 text-slate-600 sm:px-5">{{ application.scholarship?.description || 'No program description was provided.' }}</p>

                                <dl class="mx-4 grid border-y border-slate-200 sm:mx-5 sm:grid-cols-3">
                                    <div class="border-b border-slate-200 py-3 sm:border-b-0 sm:border-r sm:pr-3">
                                        <dt class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">Deadline</dt>
                                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ application.scholarship?.deadline || 'Not listed' }}</dd>
                                    </div>
                                    <div class="border-b border-slate-200 py-3 sm:border-b-0 sm:border-r sm:px-3">
                                        <dt class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">Application mode</dt>
                                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ applicationModeLabel(application.scholarship?.application_mode) }}</dd>
                                    </div>
                                    <div class="py-3 sm:pl-3">
                                        <dt class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">Available slots</dt>
                                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ application.scholarship?.slots_available || 'Not listed' }}</dd>
                                    </div>
                                </dl>

                                <div
                                    v-if="application.scholarship?.benefits?.length || application.scholarship?.benefit_summary || application.scholarship?.award_amount != null"
                                    class="px-4 py-4 sm:px-5"
                                >
                                    <p class="student-kicker">Support package</p>
                                    <h4 class="mt-0.5 text-sm font-bold text-slate-950">What the program provides</h4>
                                    <div v-if="application.scholarship?.benefits?.length" class="mt-2 divide-y divide-slate-200 border-y border-slate-200">
                                        <div v-for="benefit in application.scholarship.benefits" :key="`${benefit.type}-${benefit.title}`" class="flex items-start gap-2 py-2.5">
                                            <i class="fa-solid fa-check mt-1 text-xs text-amber-700" aria-hidden="true"></i>
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold text-slate-950">
                                                    {{ benefit.title }}
                                                    <span v-if="benefit.amount !== null && benefit.amount !== undefined && benefit.amount !== ''" class="text-amber-800"> - {{ formatAwardAmount(benefit.amount) }}</span>
                                                </p>
                                                <p v-if="benefit.coverage_label || benefit.frequency_label" class="mt-1 text-xs font-semibold text-slate-500">
                                                    {{ [benefit.coverage_label, benefit.frequency_label].filter(Boolean).join(' - ') }}
                                                </p>
                                                <p v-if="benefit.description" class="mt-1 line-clamp-2 text-xs leading-5 text-slate-600">{{ benefit.description }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="mt-2 border-y border-slate-200 py-2.5 text-sm font-bold leading-5 text-slate-950">
                                        {{ application.scholarship?.benefit_summary || formatAwardAmount(application.scholarship?.award_amount) }}
                                    </p>
                                </div>

                                <div class="mx-4 border-t border-slate-200 sm:mx-5">
                                    <div class="flex items-start gap-2.5 py-3">
                                        <i class="fa-solid fa-location-dot mt-1 text-amber-700" aria-hidden="true"></i>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Program location</p>
                                            <p class="mt-0.5 text-sm font-bold text-slate-950">{{ application.scholarship?.location_name || 'Location not named' }}</p>
                                            <p class="mt-0.5 text-xs leading-5 text-slate-600">{{ application.scholarship?.location_address || application.scholarship?.eligible_locations || 'No address listed.' }}</p>
                                            <p v-if="application.scholarship?.distance_label" class="mt-1 text-xs font-bold text-slate-600">About {{ application.scholarship.distance_label }} away</p>
                                            <button v-if="hasMapPreview" type="button" class="mt-1.5 inline-flex items-center gap-2 text-xs font-bold text-amber-800" @click="showMapModal = true">
                                                View on map
                                                <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="flex items-start gap-2.5 border-t border-slate-200 py-3">
                                        <i class="fa-solid fa-address-book mt-1 text-amber-700" aria-hidden="true"></i>
                                        <div class="min-w-0 flex-1 text-sm">
                                            <p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Provider contact</p>
                                            <div v-if="application.scholarship?.contact_email || application.scholarship?.contact_number" class="mt-1 flex flex-wrap gap-x-5 gap-y-1">
                                                <a v-if="application.scholarship.contact_email" :href="`mailto:${application.scholarship.contact_email}`" class="truncate font-bold text-slate-800 hover:text-amber-700">{{ application.scholarship.contact_email }}</a>
                                                <a v-if="application.scholarship.contact_number" :href="`tel:${application.scholarship.contact_number}`" class="font-bold text-slate-800 hover:text-amber-700">{{ application.scholarship.contact_number }}</a>
                                            </div>
                                            <p v-else class="mt-1 text-slate-500">No public contact listed.</p>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'program'" class="order-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <span class="student-section-mark"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i></span>
                                        <div>
                                            <p class="student-kicker">Profile match</p>
                                            <h3 class="mt-0.5 text-base font-bold text-slate-950">How your profile fits this program</h3>
                                            <p class="mt-0.5 text-xs text-slate-500">A guide based on your submitted profile, not the provider's final decision.</p>
                                        </div>
                                    </div>
                                    <div class="flex w-fit items-baseline gap-2 rounded-md bg-slate-950 px-3 py-2 text-white">
                                        <span class="text-xl font-bold">{{ application.dss_score ?? 0 }}%</span>
                                        <span class="text-xs font-semibold text-slate-300">{{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}</span>
                                    </div>
                                </div>

                                <div class="border-t border-slate-200 px-4 py-3 sm:px-5">
                                    <p class="text-sm font-bold leading-5 text-slate-950">{{ application.dss_explanation?.headline || application.dss_breakdown?.summary || 'Your saved profile was compared with this program.' }}</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ application.dss_explanation?.next_action || 'Review the eligibility checks and keep your profile information current.' }}</p>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-full rounded-full bg-amber-500" :style="{ width: `${Math.min(Math.max(Number(application.dss_score) || 0, 0), 100)}%` }"></div>
                                    </div>
                                </div>

                                <div class="border-t border-slate-200 px-4 py-3 sm:px-5">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">Eligibility checks</p>
                                            <p class="mt-1 text-xs text-slate-500">Your saved answers compared with the program rules.</p>
                                        </div>
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(application.eligibility_score)]">{{ application.eligibility_score ?? 0 }}% matched</span>
                                    </div>
                                    <div v-if="application.eligibility_breakdown?.criteria?.length" class="mt-2 divide-y divide-slate-200 border-y border-slate-200">
                                        <div v-for="criterion in application.eligibility_breakdown.criteria" :key="criterion.key" class="flex items-center justify-between gap-4 py-2.5 text-sm">
                                            <span class="font-semibold text-slate-700">{{ criterion.label }}</span>
                                            <span :class="['shrink-0 rounded-md px-2 py-1 text-xs font-bold', criterionClass(criterion.status)]">{{ eligibilityCriterionLabel(criterion) }}</span>
                                        </div>
                                    </div>
                                    <p v-else class="mt-3 text-sm leading-5 text-slate-500">{{ application.eligibility_breakdown?.summary || 'No individual eligibility checks are available.' }}</p>
                                </div>

                                <div v-if="application.dss_explanation?.strengths?.length || application.dss_explanation?.needs_attention?.length" class="border-t border-slate-200 px-4 sm:px-5">
                                    <div v-if="application.dss_explanation?.strengths?.length" class="py-3">
                                        <p class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-circle-check text-emerald-600" aria-hidden="true"></i> Where your profile aligns</p>
                                        <ul class="mt-2 space-y-1.5">
                                            <li v-for="item in application.dss_explanation.strengths" :key="item" class="flex items-start gap-2 text-xs leading-5 text-slate-600"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span><span>{{ item }}</span></li>
                                        </ul>
                                    </div>
                                    <div v-if="application.dss_explanation?.needs_attention?.length" :class="['py-3', application.dss_explanation?.strengths?.length ? 'border-t border-slate-200' : '']">
                                        <p class="flex items-center gap-2 text-sm font-bold text-slate-950"><i class="fa-solid fa-circle-info text-amber-700" aria-hidden="true"></i> What to review</p>
                                        <ul class="mt-2 space-y-1.5">
                                            <li v-for="item in application.dss_explanation.needs_attention" :key="item" class="flex items-start gap-2 text-xs leading-5 text-slate-600"><span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400"></span><span>{{ item }}</span></li>
                                        </ul>
                                    </div>
                                </div>

                                <details v-if="dssCriteria.length" class="border-t border-slate-200 px-4 sm:px-5">
                                    <summary class="flex cursor-pointer items-center justify-between gap-3 py-3 text-sm font-bold text-slate-800">
                                        <span>View suitability score breakdown</span>
                                        <i class="fa-solid fa-chevron-down text-xs text-slate-400" aria-hidden="true"></i>
                                    </summary>
                                    <div class="divide-y divide-slate-200 border-t border-slate-200">
                                        <div v-for="criterion in dssCriteria" :key="criterion.key" class="py-2.5 text-sm">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                                <span class="text-xs font-bold text-slate-600">{{ criterionImpact(criterion) }}</span>
                                            </div>
                                            <p class="mt-1 text-xs font-bold uppercase tracking-[0.1em] text-slate-400">{{ criterion.score }}% score x {{ criterion.weight }}% weight</p>
                                            <p class="mt-1 leading-5 text-slate-600">{{ criterion.note }}</p>
                                        </div>
                                    </div>
                                </details>

                                <p class="border-t border-slate-200 px-4 py-3 text-xs font-semibold leading-5 text-slate-500 sm:px-5">{{ dssDecisionNotice }}</p>
                            </section>

                            <section v-if="activeSection === 'files'" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="student-section-head p-4 sm:p-5">
                                    <div class="flex items-start gap-3">
                                        <span class="student-section-mark"><i class="fa-solid fa-folder-open" aria-hidden="true"></i></span>
                                        <div>
                                            <p class="student-kicker">Documents</p>
                                            <h3 class="mt-1 text-lg font-bold text-slate-950">Application files</h3>
                                            <p class="mt-1 text-sm leading-6 text-slate-500">Upload or replace a file beside the requirement it belongs to.</p>
                                        </div>
                                    </div>
                                    <span
                                        :class="[
                                            'rounded-md px-2.5 py-1 text-xs font-bold',
                                            filesNeedingAction.length ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800',
                                        ]"
                                    >
                                        {{ filesNeedingAction.length ? `${filesNeedingAction.length} need attention` : fileStatusLabel }}
                                    </span>
                                </div>

                                <div class="border-t border-slate-200 p-4 sm:p-5">
                                <div v-if="requiresOriginalVerification" class="flex items-start gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                                    <i class="fa-solid fa-circle-info mt-1 text-amber-700" aria-hidden="true"></i>
                                    <p><span class="font-bold">Originals are not needed yet.</span> Keep them ready and bring them only when the provider sends an in-person verification schedule or formal application instructions.</p>
                                </div>

                                <TermsAgreement
                                    v-model="documentTermsAccepted"
                                    class="mt-4"
                                    context="document"
                                />

                                <input
                                    ref="fileInput"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                    class="hidden"
                                    @change="handleFileChange"
                                >

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
                                                    <p class="font-bold text-slate-950">
                                                        {{ row.name }}
                                                    </p>
                                                    <span v-if="!row.required" class="rounded bg-slate-100 px-2 py-0.5 text-[0.65rem] font-bold uppercase text-slate-500">
                                                        Supporting file
                                                    </span>
                                                </div>
                                                <p v-if="row.document" class="mt-1 truncate text-xs text-slate-500">
                                                    {{ row.document.original_name }} - {{ formatFileSize(row.document.size) }} - {{ row.document.uploaded_at }}
                                                </p>
                                                <p v-else :class="['mt-1 text-xs font-semibold', row.required ? 'text-amber-700' : 'text-slate-500']">
                                                    {{ row.required ? 'No file uploaded yet' : 'Optional - upload if it supports your application' }}
                                                </p>
                                                <p v-if="row.document?.review_notes" class="mt-1 text-xs font-semibold text-slate-600">
                                                    Provider note: {{ row.document.review_notes }}
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
                                                v-if="row.document?.view_url"
                                                type="button"
                                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                                @click="openDocumentPreview(row.document)"
                                            >
                                                View
                                            </button>
                                            <button
                                                type="button"
                                                :disabled="isUploading"
                                                class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                                @click="openUploadPicker(row.name)"
                                            >
                                                <i class="fa-solid fa-arrow-up-from-bracket"></i>
                                                {{ isUploading && activeUploadRequirement === row.name ? 'Uploading...' : (row.document ? 'Replace file' : 'Upload document') }}
                                            </button>
                                            <button
                                                v-if="row.document"
                                                type="button"
                                                :disabled="isUploading"
                                                class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-600 transition hover:bg-slate-50 disabled:opacity-60"
                                                :aria-label="`Remove ${row.name}`"
                                                @click="deleteDocument(row.document)"
                                            >
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                                    This program does not have any document requirements yet.
                                </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'history' && timeline.length" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-start gap-3">
                                    <span class="student-section-mark"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="student-kicker">Timeline</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">Application history</h3>
                                        <p class="mt-1 text-sm text-slate-500">A record of changes made to this application.</p>
                                    </div>
                                </div>
                                <div class="mt-4 grid gap-2">
                                    <div
                                        v-for="event in timeline"
                                        :key="event.id"
                                        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                                    >
                                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="font-bold text-slate-950">
                                                {{ statusLabel(event.to_status) }}
                                            </p>
                                            <p class="text-xs text-slate-500">
                                                {{ event.changed_at || 'Recently' }}
                                            </p>
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">
                                            By {{ event.actor || 'System' }}
                                        </p>
                                        <p v-if="event.review_notes" class="mt-2 leading-6 text-slate-600">
                                            {{ event.review_notes }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'history' && !timeline.length" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="font-bold text-slate-950">No status changes yet</p>
                                <p class="mt-1 text-sm leading-6 text-slate-600">
                                    The submitted application will appear here when the provider records a review update.
                                </p>
                            </section>

                            <details v-if="activeSection === 'history' && application.application_answers?.length" class="group rounded-lg border border-slate-200 bg-white shadow-sm">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                    <span>Your submitted answers</span>
                                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                </summary>
                                <dl class="divide-y divide-slate-200 border-t border-slate-200">
                                    <div v-for="(answer, index) in application.application_answers" :key="answer.question_id || index" class="px-4 py-3">
                                        <dt class="text-xs font-bold leading-5 text-slate-500">{{ answer.prompt }}</dt>
                                        <dd class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ answer.answer || 'No response provided' }}</dd>
                                    </div>
                                </dl>
                            </details>

                            <details v-if="activeSection === 'history' && application.notes" class="group rounded-lg border border-slate-200 bg-white shadow-sm">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                    <span>Your submitted note</span>
                                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                </summary>
                                <p class="border-t border-slate-200 px-4 py-3 text-sm leading-6 text-slate-600">{{ application.notes }}</p>
                            </details>
                        </div>

                        <aside v-if="activeSection === 'overview'" class="flex flex-col gap-4">
                            <section v-if="hasProviderUpdate" class="order-2 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Provider update</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    {{ application.review_notes || application.decision_reason || application.outcome_notes ? 'Review feedback' : 'Your submitted note' }}
                                </h3>

                                <div v-if="application.review_notes" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm">
                                    <p class="font-semibold text-amber-900">Message from the provider</p>
                                    <p class="mt-1 leading-6 text-slate-700">{{ application.review_notes }}</p>
                                </div>
                                <div v-if="application.outcome_notes" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                    <p class="font-semibold text-slate-800">Outcome details</p>
                                    <p class="mt-1 leading-6 text-slate-600">{{ application.outcome_notes }}</p>
                                </div>

                                <div v-if="application.decision_reason" class="mt-3 border-t border-slate-200 pt-3 text-sm">
                                    <p class="font-semibold text-slate-500">Decision reason</p>
                                    <p class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.decision_reason) }}</p>
                                </div>

                            </section>

                            <section
                                v-if="activeSection === 'overview' && application.correction_status"
                                :class="[
                                    'order-1 rounded-lg border p-4 shadow-sm',
                                    application.correction_status === 'requested'
                                        ? 'border-amber-200 bg-amber-50'
                                        : application.correction_status === 'submitted'
                                            ? 'border-sky-200 bg-sky-50'
                                            : 'border-emerald-200 bg-emerald-50',
                                ]"
                            >
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-white text-slate-800 shadow-sm">
                                        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="student-kicker">Application correction</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            {{ application.correction_status === 'requested' ? 'Provider needs an update' : application.correction_status === 'submitted' ? 'Correction sent for review' : 'Correction completed' }}
                                        </h3>
                                        <p v-if="application.correction_message" class="mt-2 text-sm leading-6 text-slate-700">{{ application.correction_message }}</p>
                                        <p v-if="application.correction_response" class="mt-2 rounded-md bg-white/80 px-3 py-2 text-xs leading-5 text-slate-600"><strong>Your response:</strong> {{ application.correction_response }}</p>
                                        <div v-if="application.correction_status === 'requested'" class="mt-3 flex flex-wrap gap-2">
                                            <button type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="showCorrectionModal = true">Send correction</button>
                                            <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openSection('files')">Update files</button>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <details v-if="application.can_withdraw || application.status === 'withdrawn'" class="group order-3 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                    <span class="flex items-center gap-2"><i class="fa-solid fa-gear text-slate-400" aria-hidden="true"></i> Application options</span>
                                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                </summary>
                                <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50/70 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h3 class="text-sm font-bold text-slate-950">Withdraw this application</h3>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">This stops provider review and keeps the record in your history.</p>
                                    </div>
                                    <button
                                        v-if="application.can_withdraw"
                                        type="button"
                                        class="shrink-0 rounded-md border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50"
                                        @click="showWithdrawalModal = true"
                                    >
                                        Withdraw application
                                    </button>
                                    <span v-else-if="application.status === 'withdrawn'" class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">Withdrawn {{ application.withdrawn_at }}</span>
                                </div>
                            </details>

                        </aside>
                    </div>
                </div>

                <ApplicantFooter />
            </div>
        </section>

        <div v-if="showCorrectionModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6" @click.self="showCorrectionModal = false">
            <form class="w-full max-w-lg overflow-hidden rounded-lg bg-white shadow-2xl" @submit.prevent="submitCorrectionResponse">
                <div class="border-b border-slate-200 p-5">
                    <p class="student-kicker">Application correction</p>
                    <h2 class="mt-1 text-xl font-bold text-slate-950">Tell the provider what you updated</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Update the requested profile details or files first, then send a short response.</p>
                </div>
                <div class="p-5">
                    <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">{{ application?.correction_message }}</div>
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

        <div
            v-if="showMapModal && applicationScholarship"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6"
            @click.self="showMapModal = false"
        >
            <section class="max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-lg bg-white shadow-2xl">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">
                            Program Location
                        </p>
                        <h3 class="mt-1 text-xl font-bold text-slate-950">
                            {{ applicationScholarship.location_name || applicationScholarship.title }}
                        </h3>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ applicationScholarship.location_address || applicationScholarship.eligible_locations || 'No map address added yet.' }}
                        </p>
                        <p v-if="hasUserMapLocation && applicationScholarship.distance_label" class="mt-2 rounded-md bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">
                            Your saved location is shown too: {{ applicationScholarship.distance_label }} from this program.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        @click="showMapModal = false"
                    >
                        Close
                    </button>
                </div>

                <div class="bg-slate-100 p-4">
                    <LeafletMapPreview
                        :address="scholarshipMapAddress"
                        :latitude="applicationScholarship.latitude"
                        :longitude="applicationScholarship.longitude"
                        :secondary-latitude="user?.latitude"
                        :secondary-longitude="user?.longitude"
                        :secondary-marker-text="user?.name || 'Your location'"
                        :distance-label="applicationScholarship.distance_label ? `About ${applicationScholarship.distance_label}` : ''"
                        :title="applicationScholarship.location_name || applicationScholarship.title"
                        :marker-text="applicationScholarship.location_name || applicationScholarship.title"
                        height="55vh"
                        auto-geocode
                    />
                </div>

                <div class="flex flex-col gap-2 border-t border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-5 text-slate-500">
                        This is the location currently listed by the scholarship provider.
                    </p>
                    <a
                        v-if="applicationScholarship.map_url"
                        :href="applicationScholarship.map_url"
                        target="_blank"
                        rel="noreferrer"
                        class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                    >
                        Open Full Map
                    </a>
                </div>
            </section>
        </div>

        <div
            v-if="previewDocument"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4"
            @click.self="closeDocumentPreview"
        >
            <section class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-950">
                            {{ previewDocument.document_name }}
                        </p>
                        <p class="truncate text-xs text-slate-500">
                            {{ previewDocument.original_name }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <a
                            :href="previewDocument.download_url"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                        >
                            Download
                        </a>
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                            aria-label="Close preview"
                            @click="closeDocumentPreview"
                        >
                            <i class="fa-solid fa-xmark text-sm"></i>
                        </button>
                    </div>
                </header>

                <div class="h-[72vh] bg-slate-100">
                    <iframe
                        :src="previewDocument.view_url || previewDocument.download_url"
                        :title="previewDocument.document_name"
                        class="h-full w-full border-0 bg-white"
                    ></iframe>
                </div>
            </section>
        </div>
    </main>
</template>
