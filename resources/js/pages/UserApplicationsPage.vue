<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import PrivacyNoticeCard from '../components/PrivacyNoticeCard.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { labelFromKey } from '../support/display';
import { showPortalToast } from '../support/portalToast';
import { progressStateLabel, selectionPlanFor } from '../support/selectionPlan';

const isLoading = ref(true);
const isSubmitting = ref(false);
const isUploadingDocument = ref(false);
const errorMessage = ref('');
const user = ref(null);
const stats = ref({
    available_scholarships: 0,
    applications: 0,
    saved: 0,
});
const scholarships = ref([]);
const applications = ref([]);
const preparedDocuments = ref([]);
const profileReadiness = ref({
    complete: false,
    completed: 0,
    total: 0,
    percent: 0,
    missing: [],
});
const currentStep = ref(0);
const activeWorkspace = ref('applications');
const selectedScholarshipId = ref('');
const documentChecklist = ref([]);
const notes = ref('');
const applicationTermsAccepted = ref(false);
const documentTermsAccepted = ref(false);
const documentFileInput = ref(null);
const activeUploadRequirement = ref('');

const steps = [
    { label: 'Program', detail: 'Review your match', icon: 'fa-solid fa-graduation-cap' },
    { label: 'Documents', detail: 'Prepare your files', icon: 'fa-solid fa-folder-open' },
    { label: 'Confirm', detail: 'Check and submit', icon: 'fa-solid fa-paper-plane' },
];
const applicationModeOptions = [
    { value: 'online', label: 'Portal review' },
    { value: 'onsite', label: 'Portal review with in-person verification' },
    { value: 'provider_review', label: 'Profile review only' },
];
const selectedScholarship = computed(() => scholarships.value.find((scholarship) => scholarship.id === Number(selectedScholarshipId.value)));
const selectedRequirements = computed(() => selectedScholarship.value?.application_mode === 'provider_review'
    ? []
    : documentRequirements(selectedScholarship.value?.requirements));
const selectedOptionalRequirements = computed(() => selectedScholarship.value?.application_mode === 'provider_review'
    ? []
    : documentRequirements(selectedScholarship.value?.optional_requirements)
        .filter((requirement) => !selectedRequirements.value.includes(requirement)));
const selectedContractSections = computed(() => {
    const scholarship = selectedScholarship.value;

    if (!scholarship) {
        return [];
    }

    return [
        { label: 'Possible service commitment', value: scholarship.return_service_contract },
        { label: 'Commitment preview', value: scholarship.other_contract_terms },
        { label: 'Possible renewal requirement', value: scholarship.renewal_policy },
    ].filter((section) => section.value && String(section.value).trim());
});
const appliedScholarshipIds = computed(() => new Set(applications.value.map((application) => application.scholarship?.id).filter(Boolean)));
const selectedAlreadyApplied = computed(() => selectedScholarship.value && appliedScholarshipIds.value.has(selectedScholarship.value.id));
const allDocumentsChecked = computed(() => selectedRequirements.value.every((requirement) => documentChecklist.value.includes(requirement)));
const canApply = computed(() => profileReadiness.value.complete);
const selectedEligibilityBlockers = computed(() => selectedScholarship.value?.eligibility_match?.blocking_criteria ?? []);
const selectedIsEligible = computed(() => selectedScholarship.value?.eligibility_match?.is_eligible !== false);
const selectedCanStartApplication = computed(() => {
    if (!selectedScholarship.value) {
        return false;
    }

    if (selectedScholarship.value.can_start_application !== undefined) {
        return Boolean(selectedScholarship.value.can_start_application);
    }

    return canApply.value && selectedIsEligible.value && !selectedAlreadyApplied.value;
});
const selectedEligibilityMessage = computed(() => {
    if (selectedIsEligible.value) {
        return '';
    }

    const labels = selectedEligibilityBlockers.value
        .map((criterion) => criterion.label)
        .filter(Boolean)
        .slice(0, 3);

    return labels.length
        ? `Your profile does not meet: ${labels.join(', ')}.`
        : 'Your profile does not meet this scholarship eligibility.';
});
const preparedDocumentsByName = computed(() => new Map(
    preparedDocuments.value.map((document) => [document.document_name, document]),
));
const selectedPreparedDocuments = computed(() => selectedRequirements.value
    .filter((requirement) => preparedDocumentsByName.value.has(requirement)));
const selectedPreparedOptionalDocuments = computed(() => selectedOptionalRequirements.value
    .filter((requirement) => preparedDocumentsByName.value.has(requirement)));
const selectedMissingPreparedDocuments = computed(() => selectedRequirements.value
    .filter((requirement) => !preparedDocumentsByName.value.has(requirement)));
const selectedDocumentReadiness = computed(() => selectedRequirements.value.length === 0
    ? 100
    : Math.round((selectedPreparedDocuments.value.length / selectedRequirements.value.length) * 100));
const selectedApplicationMode = computed(() => applicationModeLabel(selectedScholarship.value?.application_mode));
const selectedApplicationModeDescription = computed(() => applicationModeDescription(selectedScholarship.value?.application_mode));
const selectedNeedsOriginalVerification = computed(() => ['onsite', 'hybrid'].includes(selectedScholarship.value?.application_mode));
const selectedSelectionPlan = computed(() => selectionPlanFor(selectedScholarship.value));
const readyApplicationCount = computed(() => applications.value.filter((application) => Number(application.document_readiness?.accepted_percent ?? application.document_readiness?.uploaded_percent ?? 0) >= 100).length);
const activeApplicationCount = computed(() => applications.value.filter((application) => ![
    'rejected',
    'not_awarded',
    'disbursed',
    'renewed',
    'exam_failed',
    'interview_failed',
].includes(application.status ?? 'submitted')).length);
const upcomingScheduleCount = computed(() => applications.value.reduce(
    (total, application) => total + applicationSchedules(application)
        .filter((schedule) => schedule.status === 'scheduled')
        .length,
    0,
));
const applicationQueue = computed(() => [...applications.value].sort((first, second) => {
    const firstNeedsConfirmation = Number(scheduleNeedsAcknowledgment(first));
    const secondNeedsConfirmation = Number(scheduleNeedsAcknowledgment(second));

    if (firstNeedsConfirmation !== secondNeedsConfirmation) {
        return secondNeedsConfirmation - firstNeedsConfirmation;
    }

    const firstActiveSchedule = primarySchedule(first)?.status === 'scheduled';
    const secondActiveSchedule = primarySchedule(second)?.status === 'scheduled';

    if (firstActiveSchedule !== secondActiveSchedule) {
        return Number(secondActiveSchedule) - Number(firstActiveSchedule);
    }

    const statusRank = {
        exam_passed: 9,
        exam_taken: 8,
        exam_scheduled: 7,
        interview: 7,
        shortlisted: 6,
        exam_qualified: 6,
        under_review: 5,
        qualified: 4,
        submitted: 3,
        approved: 2,
        awarded: 1,
        distribution_scheduled: 2,
        disbursed: 1,
        renewed: 1,
        exam_failed: 0,
        interview_failed: 0,
        rejected: 0,
        not_awarded: 0,
    };

    const firstRank = statusRank[first.status ?? 'submitted'] ?? 0;
    const secondRank = statusRank[second.status ?? 'submitted'] ?? 0;

    return secondRank - firstRank;
}));
const nextStepLabel = computed(() => steps[currentStep.value + 1]?.label
    ? `Continue to ${steps[currentStep.value + 1].label}`
    : 'Continue');
const canGoNext = computed(() => {
    if (!selectedCanStartApplication.value) {
        return false;
    }

    if (currentStep.value === 1) {
        return allDocumentsChecked.value;
    }

    return true;
});
const canSubmitApplication = computed(() => allDocumentsChecked.value && selectedCanStartApplication.value && applicationTermsAccepted.value);

function canOpenWizardStep(index) {
    if (index === 0) {
        return true;
    }

    if (!selectedCanStartApplication.value) {
        return false;
    }

    if (index === 1) {
        return true;
    }

    if (index === 2) {
        return allDocumentsChecked.value;
    }

    return false;
}

function inferGradeScale(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return Number(value) <= 5 ? 'grade_point' : 'percentage';
}

function academicRequirementLabel(scholarship) {
    if (scholarship?.minimum_grade_label) {
        return scholarship.minimum_grade_label;
    }

    if (!scholarship?.minimum_gwa) {
        return 'Not listed yet';
    }

    return inferGradeScale(scholarship.minimum_gwa) === 'grade_point'
        ? `Maximum GWA/GPA ${scholarship.minimum_gwa}`
        : `Minimum average ${scholarship.minimum_gwa}%`;
}

function goToWizardStep(index) {
    if (canOpenWizardStep(index)) {
        currentStep.value = index;
        errorMessage.value = '';
        return;
    }

    if (!canApply.value) {
        errorMessage.value = 'Complete your student profile before starting an application.';
        return;
    }

    if (!selectedIsEligible.value) {
        errorMessage.value = selectedEligibilityMessage.value;
        return;
    }

    if (selectedAlreadyApplied.value) {
        errorMessage.value = 'You already submitted an application for this scholarship.';
        return;
    }

    errorMessage.value = 'Complete the current application step before moving forward.';
}

function openWorkspace(workspace) {
    activeWorkspace.value = workspace;
    errorMessage.value = '';
}

function formatAmount(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return 'Amount not set';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(amount));
}

function statusLabel(status) {
    const labels = {
        approved: 'Qualified for formal application',
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

    if (['rejected', 'not_awarded', 'exam_failed', 'interview_failed'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function applicationSchedules(application) {
    return Array.isArray(application?.schedules) ? application.schedules : [];
}

function scheduleRequiresAcknowledgment(schedule) {
    return schedule?.requires_applicant_acknowledgment !== false;
}

function scheduleNeedsAcknowledgmentValue(schedule) {
    return Boolean(
        schedule?.status === 'scheduled'
        && scheduleRequiresAcknowledgment(schedule)
        && !schedule.applicant_acknowledged,
    );
}

function primarySchedule(application) {
    const schedules = applicationSchedules(application);
    const unacknowledged = schedules.find(
        (schedule) => scheduleNeedsAcknowledgmentValue(schedule),
    );
    const active = schedules.find((schedule) => schedule.status === 'scheduled');

    return unacknowledged ?? active ?? schedules[schedules.length - 1] ?? null;
}

function scheduleNeedsAcknowledgment(application) {
    const schedule = primarySchedule(application);

    return scheduleNeedsAcknowledgmentValue(schedule);
}

function hasDistributionSchedule(application) {
    return applicationSchedules(application).some((schedule) => schedule.type === 'distribution');
}

function scheduleTypeLabel(type) {
    return {
        screening: 'Application screening',
        exam: 'Scholarship exam',
        interview: 'Interview',
        distribution: 'Award distribution',
    }[type] ?? labelFromKey(type);
}

function scheduleTypeIcon(type) {
    return {
        screening: 'fa-solid fa-list-check',
        exam: 'fa-solid fa-clipboard-check',
        interview: 'fa-solid fa-comments',
        distribution: 'fa-solid fa-hand-holding-heart',
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

function schedulePreviewLabel(schedule) {
    if (schedule?.status === 'scheduled') {
        return 'Upcoming activity';
    }

    if (schedule?.status === 'completed') {
        return 'Latest completed activity';
    }

    return 'Latest schedule update';
}

function timelineStepClass(state) {
    if (state === 'complete') {
        return 'border-slate-900 bg-slate-900 text-white';
    }

    if (state === 'current') {
        return 'border-amber-300 bg-amber-50 text-slate-950';
    }

    if (['stopped', 'skipped'].includes(state)) {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }

    return 'border-slate-200 bg-white text-slate-500';
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

function recommendationClass(recommendation) {
    if (recommendation === 'highly_recommended') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (recommendation === 'recommended') {
        return 'bg-slate-100 text-slate-700';
    }

    if (recommendation === 'needs_review') {
        return 'bg-amber-100 text-amber-800';
    }

    if (recommendation === 'not_recommended') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-rose-100 text-rose-800';
}

function applicationModeLabel(value) {
    const normalizedValue = value === 'hybrid' ? 'onsite' : value;

    return applicationModeOptions.find((option) => option.value === normalizedValue)?.label ?? labelFromKey(value || 'not_listed');
}

function applicationModeDescription(value) {
    return {
        online: 'Your profile and required files are reviewed through the portal.',
        onsite: 'Upload copies first and keep the originals ready. Do not bring them yet; the provider will send the date and location if an in-person check is needed.',
        hybrid: 'Upload copies first and keep the originals ready. Do not bring them yet; the provider will send the date and location if an in-person check is needed.',
        provider_review: 'The provider checks your profile first. No program files are required for this initial review.',
    }[value] ?? 'The provider will review your portal submission before its formal application process.';
}

function selectionStageDescription(stage) {
    return {
        screening: 'The provider checks your profile, eligibility, and submitted files.',
        exam: 'Applicants who pass the review receive the exam schedule and instructions.',
        interview: 'Applicants who reach this stage receive the interview schedule and instructions.',
        distribution: 'Selected recipients receive the benefit release details from the provider.',
    }[stage?.value] ?? stage?.detail ?? 'The provider will share instructions if you reach this stage.';
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

function preparedDocumentFor(requirement) {
    return preparedDocumentsByName.value.get(requirement) ?? null;
}

function formatFileSize(size) {
    const bytes = Number(size ?? 0);

    if (!bytes) {
        return 'Size unavailable';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function syncDocumentChecklist() {
    documentChecklist.value = [...selectedPreparedDocuments.value];
}

function nextStep() {
    if (currentStep.value < steps.length - 1 && canGoNext.value) {
        currentStep.value += 1;
    }
}

function previousStep() {
    if (currentStep.value > 0) {
        currentStep.value -= 1;
    }
}

function resetWizard() {
    selectedScholarshipId.value = '';
    documentChecklist.value = [];
    notes.value = '';
    applicationTermsAccepted.value = false;
    documentTermsAccepted.value = false;
    activeUploadRequirement.value = '';
    errorMessage.value = '';
    currentStep.value = 0;

    if (window.location.search) {
        window.history.replaceState({}, '', window.location.pathname);
    }
}

function openDocumentUpload(requirement) {
    errorMessage.value = '';

    if (!documentTermsAccepted.value) {
        showPortalToast({
            type: 'error',
            title: 'Terms required',
            message: 'Accept the document upload terms before choosing a file.',
        });
        return;
    }

    activeUploadRequirement.value = requirement;

    if (documentFileInput.value) {
        documentFileInput.value.value = '';
        documentFileInput.value.click();
    }
}

async function handleDocumentFileChange(event) {
    const file = event.target.files?.[0] ?? null;
    const requirement = activeUploadRequirement.value;

    if (!file || !requirement) {
        activeUploadRequirement.value = '';
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        errorMessage.value = 'Choose a file that is 5 MB or smaller.';
        event.target.value = '';
        activeUploadRequirement.value = '';
        return;
    }

    isUploadingDocument.value = true;
    errorMessage.value = '';

    const payload = new FormData();
    payload.append('document_name', requirement);
    payload.append('document_file', file);
    payload.append('terms_accepted', '1');

    try {
        const response = await window.axios.post('/dashboard/student-documents', payload, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
        const savedDocument = response.data.document;

        preparedDocuments.value = [
            savedDocument,
            ...preparedDocuments.value.filter((document) => document.document_name !== savedDocument.document_name),
        ];
        syncDocumentChecklist();
    } catch (handledError) {
        void handledError;
    } finally {
        isUploadingDocument.value = false;
        activeUploadRequirement.value = '';
        event.target.value = '';
    }
}

async function loadApplications() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/applications/data');

        user.value = response.data.user;
        profileReadiness.value = response.data.profile_readiness ?? profileReadiness.value;
        stats.value = response.data.stats;
        scholarships.value = response.data.scholarships;
        applications.value = response.data.applications;
        preparedDocuments.value = response.data.prepared_documents ?? [];

        const requestedScholarshipId = new URLSearchParams(window.location.search).get('scholarship');

        activeWorkspace.value = requestedScholarshipId ? 'new' : 'applications';

        if (requestedScholarshipId) {
            const requestedScholarship = scholarships.value.find((scholarship) => scholarship.id === Number(requestedScholarshipId));

            if (requestedScholarship) {
                selectedScholarshipId.value = String(requestedScholarship.id);
                documentChecklist.value = [];
                notes.value = '';
                currentStep.value = 0;

                if (appliedScholarshipIds.value.has(requestedScholarship.id)) {
                    errorMessage.value = 'You already submitted an application for this scholarship.';
                    currentStep.value = 0;
                } else if (!canApply.value) {
                    errorMessage.value = 'Complete your student profile before starting an application.';
                    currentStep.value = 0;
                } else if (requestedScholarship.eligibility_match?.is_eligible === false) {
                    const labels = (requestedScholarship.eligibility_match.blocking_criteria ?? [])
                        .map((criterion) => criterion.label)
                        .filter(Boolean)
                        .slice(0, 3);
                    errorMessage.value = labels.length
                        ? `Your profile does not meet: ${labels.join(', ')}.`
                        : 'Your profile does not meet this scholarship eligibility.';
                    currentStep.value = 0;
                }
            } else {
                errorMessage.value = 'The selected scholarship was not found. Please choose a published scholarship from the scholarship page.';
            }
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applications.';
    } finally {
        isLoading.value = false;
    }
}

async function submitApplication() {
    if (!canApply.value) {
        errorMessage.value = 'Complete your student profile before submitting an application.';
        return;
    }

    if (!selectedIsEligible.value) {
        errorMessage.value = selectedEligibilityMessage.value;
        return;
    }

    if (!selectedScholarship.value || !allDocumentsChecked.value) {
        return;
    }

    if (!applicationTermsAccepted.value) {
        errorMessage.value = 'Please accept the application terms before submitting.';
        return;
    }

    isSubmitting.value = true;
    errorMessage.value = '';

    try {
        await window.axios.post('/dashboard/applications', {
            scholarship_id: selectedScholarship.value.id,
            document_checklist: documentChecklist.value,
            notes: notes.value,
            terms_accepted: applicationTermsAccepted.value ? '1' : '',
        });

        await loadApplications();
        resetWizard();
        activeWorkspace.value = 'applications';
    } catch (error) {
        if (error.response?.data?.profile_readiness) {
            profileReadiness.value = error.response.data.profile_readiness;
        }
    } finally {
        isSubmitting.value = false;
    }
}

async function trackApplicationStart(scholarship) {
    if (!scholarship || appliedScholarshipIds.value.has(scholarship.id)) {
        return;
    }

    try {
        await window.axios.post(`/dashboard/scholarships/${scholarship.id}/application-start`);
    } catch {
        // Tracking must never interrupt the application wizard.
    }
}

onMounted(loadApplications);

watch(selectedScholarship, (scholarship) => {
    if (!scholarship) {
        documentChecklist.value = [];
        return;
    }

    syncDocumentChecklist();
    applicationTermsAccepted.value = false;
    trackApplicationStart(scholarship);
});
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <ApplicantPageHeader
                    eyebrow="Applications"
                    title="Manage your submissions"
                    description="Start pre-screening or check what happens next."
                    icon="fa-solid fa-file-signature"
                    action-href="/dashboard/scholarships"
                    action-label="Browse scholarships"
                    secondary-href="/dashboard/documents"
                    secondary-label="Prepare documents"
                />

                <PrivacyNoticeCard context="applications" />

                <nav class="student-card mt-5 grid gap-1.5 p-1.5 sm:grid-cols-2" aria-label="Application workspace">
                    <button
                        type="button"
                        :class="[
                            'flex min-h-14 items-center justify-between gap-3 rounded-md px-4 py-3 text-left transition',
                            activeWorkspace === 'applications'
                                ? 'bg-slate-900 text-white shadow-sm'
                                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                        ]"
                        :aria-pressed="activeWorkspace === 'applications'"
                        @click="openWorkspace('applications')"
                    >
                        <span class="flex items-center gap-3">
                            <i class="fa-solid fa-layer-group text-sm" aria-hidden="true"></i>
                            <span>
                                <span class="block text-sm font-bold">My applications</span>
                                <span class="mt-0.5 block text-xs opacity-70">Track status and schedules</span>
                            </span>
                        </span>
                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', activeWorkspace === 'applications' ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-600']">
                            {{ applications.length }}
                        </span>
                    </button>
                    <button
                        type="button"
                        :class="[
                            'flex min-h-14 items-center justify-between gap-3 rounded-md px-4 py-3 text-left transition',
                            activeWorkspace === 'new'
                                ? 'bg-slate-900 text-white shadow-sm'
                                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                        ]"
                        :aria-pressed="activeWorkspace === 'new'"
                        @click="openWorkspace('new')"
                    >
                        <span class="flex items-center gap-3">
                            <i class="fa-solid fa-plus text-sm" aria-hidden="true"></i>
                            <span>
                                <span class="block text-sm font-bold">Start pre-screening</span>
                                <span class="mt-0.5 block text-xs opacity-70">Review, prepare, and submit</span>
                            </span>
                        </span>
                        <span v-if="selectedScholarship" :class="['h-2.5 w-2.5 rounded-full', activeWorkspace === 'new' ? 'bg-amber-300' : 'bg-amber-500']" title="Scholarship selected"></span>
                    </button>
                </nav>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading pre-screening form...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </div>

                    <template v-if="activeWorkspace === 'new'">
                    <div v-if="!canApply" class="student-card border-amber-200 bg-amber-50/90 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-xs font-bold uppercase text-amber-700">
                                    Profile required
                                </p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">
                                    Complete your student profile before applying
                                </h3>
                                <p class="mt-2 text-sm leading-5 text-slate-700">
                                    Your profile is {{ profileReadiness.percent }}% complete. Missing:
                                    {{ profileReadiness.missing.slice(0, 4).map((field) => field.label).join(', ') }}{{ profileReadiness.missing.length > 4 ? ', and more' : '' }}.
                                </p>
                            </div>
                            <a
                                href="/dashboard/profile"
                                class="inline-flex justify-center rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Complete profile
                            </a>
                        </div>
                    </div>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <header class="bg-slate-950 px-5 py-4 text-white sm:px-6">
                            <div class="flex items-center gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-amber-400 text-slate-950">
                                    <i :class="steps[currentStep].icon" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-amber-300">Step {{ currentStep + 1 }} of {{ steps.length }}</p>
                                    <h3 class="mt-1 text-lg font-bold">{{ steps[currentStep].label }}</h3>
                                    <p class="mt-0.5 truncate text-xs text-slate-300">{{ selectedScholarship?.title || 'Select a scholarship to begin' }}</p>
                                </div>
                                <span class="hidden text-xs font-semibold text-slate-400 sm:block">{{ steps[currentStep].detail }}</span>
                            </div>
                        </header>

                        <nav class="grid gap-2 border-b border-slate-200 bg-slate-50 p-3 sm:grid-cols-3" aria-label="Pre-screening process">
                            <button
                                v-for="(step, index) in steps"
                                :key="step.label"
                                type="button"
                                :disabled="!canOpenWizardStep(index)"
                                :aria-current="currentStep === index ? 'step' : undefined"
                                :class="[
                                    'flex min-w-0 items-center gap-3 rounded-md border px-3 py-2.5 text-left transition disabled:cursor-not-allowed disabled:opacity-45',
                                    currentStep === index
                                        ? 'border-slate-900 bg-white text-slate-950 shadow-sm'
                                        : index < currentStep
                                            ? 'border-slate-200 bg-white text-slate-700'
                                            : 'border-transparent text-slate-500 hover:border-slate-200 hover:bg-white hover:text-slate-950',
                                ]"
                                @click="goToWizardStep(index)"
                            >
                                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs font-bold', currentStep === index ? 'bg-amber-400 text-slate-950' : index < currentStep ? 'bg-slate-900 text-white' : 'bg-slate-200 text-slate-500']">
                                    <i v-if="index < currentStep" class="fa-solid fa-check" aria-hidden="true"></i>
                                    <i v-else :class="step.icon" aria-hidden="true"></i>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-bold">{{ step.label }}</span>
                                    <span class="mt-0.5 block truncate text-[11px] opacity-70">{{ step.detail }}</span>
                                </span>
                            </button>
                        </nav>

                        <div class="bg-white p-5 sm:p-6">
                            <div v-if="currentStep === 0 && !selectedScholarship">
                                <div class="grid min-h-64 place-items-center rounded-md border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center">
                                    <div class="max-w-md">
                                        <span class="mx-auto grid h-12 w-12 place-items-center rounded-md bg-white text-amber-700 shadow-sm ring-1 ring-slate-200">
                                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                        </span>
                                        <h3 class="mt-4 text-lg font-bold text-slate-950">Choose a scholarship first</h3>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">Open Scholarships, review a program, and select Start pre-screening. Your chosen program will appear here automatically.</p>
                                        <a href="/dashboard/scholarships" class="mt-5 inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                                            Browse scholarships
                                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div v-else-if="currentStep === 0 && selectedScholarship" class="grid gap-5">
                                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                    <header class="relative flex flex-col gap-5 overflow-hidden bg-slate-950 p-5 text-white sm:flex-row sm:items-start sm:justify-between sm:p-6">
                                        <span class="pointer-events-none absolute -right-12 -top-16 h-40 w-40 rounded-full border-[24px] border-amber-300/10" aria-hidden="true"></span>
                                        <div class="flex min-w-0 items-start gap-4">
                                            <img :src="selectedScholarship.image_url" :alt="selectedScholarship.title" class="h-16 w-16 shrink-0 rounded-md bg-white object-contain p-2 ring-1 ring-white/20">
                                            <div class="min-w-0">
                                                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-amber-300">{{ selectedScholarship.provider?.name || 'Scholarship Provider' }}</p>
                                                <h3 class="mt-1 text-2xl font-bold leading-tight text-white">{{ selectedScholarship.title }}</h3>
                                                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">{{ selectedScholarship.description || 'No program description has been posted yet.' }}</p>
                                            </div>
                                        </div>
                                        <span :class="['relative w-fit shrink-0 rounded-md px-3 py-2 text-xs font-bold ring-1', selectedIsEligible ? 'bg-emerald-400/15 text-emerald-200 ring-emerald-300/25' : 'bg-rose-400/15 text-rose-200 ring-rose-300/25']">{{ selectedScholarship.eligibility_match?.score ?? 0 }}% match</span>
                                    </header>

                                    <dl class="grid gap-px border-b border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-3">
                                        <div class="flex items-start gap-3 bg-amber-50 p-4 sm:col-span-2 lg:col-span-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-200 text-amber-900"><i class="fa-solid fa-gift" aria-hidden="true"></i></span><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-amber-700">Benefits</dt><dd class="mt-1 text-sm font-bold leading-6 text-slate-950">{{ selectedScholarship.benefit_summary || formatAmount(selectedScholarship.award_amount) }}</dd></div></div>
                                        <div class="flex items-start gap-3 bg-white p-4"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span><div><dt class="text-xs font-semibold text-slate-500">Deadline</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ selectedScholarship.deadline || 'No deadline' }}</dd></div></div>
                                        <div class="flex items-start gap-3 bg-white p-4"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span><div><dt class="text-xs font-semibold text-slate-500">Academic requirement</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ academicRequirementLabel(selectedScholarship) }}</dd></div></div>
                                        <div class="flex items-start gap-3 bg-white p-4"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i class="fa-solid fa-folder-open" aria-hidden="true"></i></span><div><dt class="text-xs font-semibold text-slate-500">Files to prepare</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ selectedRequirements.length ? `${selectedRequirements.length} required` : 'None initially' }}</dd></div></div>
                                    </dl>

                                    <div :class="['flex items-start gap-3 border-b px-5 py-4', selectedIsEligible ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50']">
                                        <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md', selectedIsEligible ? 'bg-emerald-600 text-white' : 'bg-rose-600 text-white']"><i :class="selectedIsEligible ? 'fa-solid fa-check' : 'fa-solid fa-exclamation'" aria-hidden="true"></i></span>
                                        <div>
                                            <p :class="['text-sm font-bold', selectedIsEligible ? 'text-emerald-950' : 'text-rose-950']">{{ selectedIsEligible ? 'Your profile meets the listed criteria' : 'Your profile needs attention' }}</p>
                                            <p :class="['mt-1 text-sm leading-6', selectedIsEligible ? 'text-emerald-800' : 'text-rose-800']">{{ selectedIsEligible ? (selectedScholarship.eligibility || 'You meet the published eligibility rules.') : selectedEligibilityMessage }}</p>
                                        </div>
                                    </div>

                                    <div class="grid gap-px bg-slate-200 lg:grid-cols-2">
                                        <section class="bg-white p-5">
                                            <div class="flex items-start justify-between gap-3">
                                                <div><p class="student-kicker">Application details</p><h4 class="mt-1 font-bold text-slate-950">What the provider receives</h4></div>
                                                <a href="/dashboard/profile" class="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">Edit profile</a>
                                            </div>
                                            <div class="mt-4 flex items-center gap-3 rounded-md bg-slate-50 p-3">
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white text-slate-700 ring-1 ring-slate-200"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                                <div><p class="text-sm font-bold text-slate-900">{{ user?.name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ user?.course_or_strand || 'Track not set' }} · {{ user?.year_level || 'Grade/year not set' }}</p></div>
                                            </div>
                                            <div class="mt-3 border-l-2 border-amber-400 pl-3">
                                                <p class="text-xs font-bold text-slate-700">{{ selectedApplicationMode }}</p>
                                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ selectedApplicationModeDescription }}</p>
                                            </div>
                                        </section>

                                        <section class="bg-slate-50 p-5">
                                            <div class="flex items-center justify-between gap-3"><div><p class="student-kicker">After submission</p><h4 class="mt-1 font-bold text-slate-950">Selection process</h4></div><span class="text-xs font-bold text-slate-500">{{ selectedSelectionPlan.length }} stages</span></div>
                                            <ol class="mt-4 grid gap-2 sm:grid-cols-2">
                                                <li v-for="(stage, index) in selectedSelectionPlan" :key="stage.value" class="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-700"><span class="grid h-6 w-6 shrink-0 place-items-center rounded bg-slate-950 text-[10px] text-amber-300">{{ index + 1 }}</span>{{ stage.label }}</li>
                                            </ol>
                                        </section>
                                    </div>
                                </section>
                            </div>

                            <div v-else-if="currentStep === 1 && selectedScholarship" class="grid gap-5">
                                <input ref="documentFileInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" @change="handleDocumentFileChange">

                                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                                    <header class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="student-kicker">Required for this program</p>
                                            <h3 class="mt-1 text-xl font-bold text-slate-950">Prepare your application files</h3>
                                            <p class="mt-1 text-sm leading-6 text-slate-600">Upload one clear file for each required item.</p>
                                        </div>
                                        <span class="w-fit rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">{{ selectedPreparedDocuments.length }} / {{ selectedRequirements.length }} ready</span>
                                    </header>

                                    <div v-if="selectedRequirements.length" class="divide-y divide-slate-200">
                                        <article v-for="requirement in selectedRequirements" :key="requirement" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="flex min-w-0 items-center gap-3">
                                                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md', preparedDocumentFor(requirement) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800']">
                                                    <i :class="preparedDocumentFor(requirement) ? 'fa-solid fa-check' : 'fa-regular fa-file'" aria-hidden="true"></i>
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="font-bold text-slate-950">{{ requirement }}</p>
                                                    <p v-if="preparedDocumentFor(requirement)" class="mt-1 truncate text-xs text-slate-500">{{ preparedDocumentFor(requirement).original_name }} · {{ formatFileSize(preparedDocumentFor(requirement).size) }}</p>
                                                    <p v-else class="mt-1 text-xs font-semibold text-amber-700">No file uploaded</p>
                                                </div>
                                            </div>
                                            <div class="flex shrink-0 gap-2">
                                                <a v-if="preparedDocumentFor(requirement)?.view_url" :href="preparedDocumentFor(requirement).view_url" target="_blank" rel="noopener" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">View</a>
                                                <button type="button" :disabled="isUploadingDocument" class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:opacity-60" @click="openDocumentUpload(requirement)">
                                                    <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i>
                                                    {{ isUploadingDocument && activeUploadRequirement === requirement ? 'Uploading...' : preparedDocumentFor(requirement) ? 'Replace' : 'Upload' }}
                                                </button>
                                            </div>
                                        </article>
                                    </div>

                                    <div v-else class="flex items-start gap-3 p-5 text-sm leading-6 text-slate-600">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-700"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                                        <div><p class="font-bold text-slate-950">No initial files required</p><p class="mt-1">This provider will begin with your completed applicant profile.</p></div>
                                    </div>

                                    <details v-if="selectedOptionalRequirements.length" class="border-t border-slate-200 bg-slate-50">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-sm font-bold text-slate-700">
                                            <span>Optional supporting files · {{ selectedPreparedOptionalDocuments.length }} / {{ selectedOptionalRequirements.length }} prepared</span>
                                            <i class="fa-solid fa-chevron-down text-xs text-slate-400" aria-hidden="true"></i>
                                        </summary>
                                        <div class="divide-y divide-slate-200 border-t border-slate-200 bg-white">
                                            <article v-for="requirement in selectedOptionalRequirements" :key="`optional-${requirement}`" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                                                <div class="flex min-w-0 items-center gap-3">
                                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600"><i :class="preparedDocumentFor(requirement) ? 'fa-solid fa-file-circle-check' : 'fa-regular fa-file'" aria-hidden="true"></i></span>
                                                    <div class="min-w-0"><p class="font-bold text-slate-900">{{ requirement }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ preparedDocumentFor(requirement)?.original_name || 'Optional · upload only if useful' }}</p></div>
                                                </div>
                                                <div class="flex shrink-0 gap-2">
                                                    <a v-if="preparedDocumentFor(requirement)?.view_url" :href="preparedDocumentFor(requirement).view_url" target="_blank" rel="noopener" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700">View</a>
                                                    <button type="button" :disabled="isUploadingDocument" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60" @click="openDocumentUpload(requirement)">{{ preparedDocumentFor(requirement) ? 'Replace' : 'Upload' }}</button>
                                                </div>
                                            </article>
                                        </div>
                                    </details>

                                    <details class="border-t border-slate-200">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-sm font-bold text-slate-700"><span>Add a note to the provider <span class="font-normal text-slate-400">(optional)</span></span><i class="fa-solid fa-chevron-down text-xs text-slate-400" aria-hidden="true"></i></summary>
                                        <div class="border-t border-slate-200 p-4"><label for="application-notes" class="sr-only">Optional note to provider</label><textarea id="application-notes" v-model="notes" rows="3" maxlength="1000" placeholder="Share short context the provider should know" class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-2 focus:ring-amber-100"></textarea></div>
                                    </details>
                                </section>

                                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                                    <div v-if="selectedNeedsOriginalVerification" class="border-b border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-950">
                                        <p class="font-bold"><i class="fa-solid fa-file-shield mr-2 text-amber-700" aria-hidden="true"></i>Keep originals ready</p>
                                        <p class="mt-1 text-amber-900">Upload copies now. Bring originals only after the provider sends the date and location.</p>
                                    </div>

                                    <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center justify-between gap-3">
                                                <p class="text-sm font-bold text-slate-900">File readiness</p>
                                                <span class="text-sm font-bold text-slate-700">{{ selectedDocumentReadiness }}%</span>
                                            </div>
                                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-amber-500 transition-all" :style="{ width: `${selectedDocumentReadiness}%` }"></div></div>
                                            <p class="mt-2 text-xs text-slate-500">{{ selectedMissingPreparedDocuments.length ? `${selectedMissingPreparedDocuments.length} required file${selectedMissingPreparedDocuments.length === 1 ? '' : 's'} still missing.` : 'All required files are ready.' }}</p>
                                        </div>
                                        <a href="/dashboard/documents" class="inline-flex w-full shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 sm:w-auto"><i class="fa-solid fa-folder-open" aria-hidden="true"></i>Open Documents</a>
                                    </div>

                                    <div class="p-5">
                                        <TermsAgreement v-model="documentTermsAccepted" context="document" />
                                    </div>
                                </section>
                            </div>

                            <div v-else-if="currentStep === 2 && selectedScholarship" class="grid gap-5">
                                <div class="grid gap-4">
                                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                                        <header class="flex items-start gap-4 border-b border-slate-200 p-5">
                                            <img :src="selectedScholarship.image_url" :alt="selectedScholarship.title" class="h-12 w-12 shrink-0 rounded-md bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200">
                                            <div class="min-w-0"><p class="text-[11px] font-bold uppercase tracking-[0.14em] text-amber-700">{{ selectedScholarship.provider?.name || 'Scholarship Provider' }}</p><h3 class="mt-1 text-xl font-bold text-slate-950">{{ selectedScholarship.title }}</h3><p class="mt-1 text-sm text-slate-500">{{ selectedApplicationMode }}</p></div>
                                        </header>

                                        <div class="divide-y divide-slate-200">
                                            <div class="flex items-start gap-3 p-4">
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-700"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                                                <div><p class="text-xs font-semibold text-slate-500">Applicant profile</p><p class="mt-1 font-bold text-slate-950">{{ user?.name }}</p><p class="mt-1 text-xs text-slate-500">{{ user?.course_or_strand || 'Track not set' }} · {{ user?.year_level || 'Grade/year not set' }}</p></div>
                                            </div>
                                            <div class="flex items-start gap-3 p-4">
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-700"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                                                <div><p class="text-xs font-semibold text-slate-500">Eligibility check</p><p class="mt-1 font-bold text-slate-950">Profile meets the published criteria</p><p class="mt-1 text-xs text-slate-500">{{ selectedScholarship.eligibility_match?.score ?? 0 }}% profile match</p></div>
                                            </div>
                                            <div class="flex items-start gap-3 p-4">
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-700"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                                                <div><p class="text-xs font-semibold text-slate-500">Application files</p><p class="mt-1 font-bold text-slate-950">{{ selectedRequirements.length ? `${documentChecklist.length} of ${selectedRequirements.length} required files attached` : 'No initial files required' }}</p><p v-if="selectedPreparedOptionalDocuments.length" class="mt-1 text-xs text-slate-500">{{ selectedPreparedOptionalDocuments.length }} optional supporting file{{ selectedPreparedOptionalDocuments.length === 1 ? '' : 's' }} included</p></div>
                                            </div>
                                            <div class="flex items-start gap-3 p-4">
                                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i class="fa-regular fa-message" aria-hidden="true"></i></span>
                                                <div><p class="text-xs font-semibold text-slate-500">Note to provider</p><p class="mt-1 text-sm leading-6 text-slate-700">{{ notes || 'No note added.' }}</p></div>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="rounded-lg border border-slate-200 bg-slate-50 p-5">
                                        <div class="flex items-center justify-between gap-3"><div><p class="student-kicker">After you submit</p><h3 class="mt-1 text-lg font-bold text-slate-950">Provider selection flow</h3></div><span class="text-xs font-bold text-slate-500">{{ selectedSelectionPlan.length }} stages</span></div>
                                        <ol class="mt-4 grid gap-2 sm:grid-cols-2">
                                            <li v-for="(stage, index) in selectedSelectionPlan" :key="stage.value" class="flex items-start gap-3 rounded-md border border-slate-200 bg-white px-4 py-3">
                                                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-slate-900 text-xs font-bold text-white">{{ index + 1 }}</span>
                                                <div>
                                                    <p class="text-sm font-bold text-slate-800">{{ stage.label }}</p>
                                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ selectionStageDescription(stage) }}</p>
                                                </div>
                                            </li>
                                        </ol>
                                    </section>

                                    <details v-if="selectedContractSections.length" class="rounded-lg border border-slate-200 bg-white">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-sm font-bold text-slate-700"><span>Possible commitments after acceptance</span><span class="flex items-center gap-2 text-xs text-slate-500">Review <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></span></summary>
                                        <div class="grid gap-4 border-t border-slate-200 p-5 sm:grid-cols-2"><div v-for="section in selectedContractSections" :key="section.label"><p class="text-sm font-bold text-slate-800">{{ section.label }}</p><p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{{ section.value }}</p></div><p class="text-xs leading-5 text-slate-500 sm:col-span-2">This is not the final agreement. The provider explains any commitment only if you are accepted.</p></div>
                                    </details>

                                    <section class="rounded-lg border border-amber-200 bg-amber-50/60 p-4 sm:p-5">
                                        <TermsAgreement v-model="applicationTermsAccepted" context="application" />
                                    </section>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-slate-200 bg-slate-50 px-5 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <button
                                    v-if="currentStep > 0"
                                    type="button"
                                    class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                                    @click="previousStep"
                                >
                                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                                    Back
                                </button>
                                <a
                                    v-else
                                    href="/dashboard/scholarships"
                                    class="inline-flex w-full items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 sm:w-auto"
                                >
                                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                                    Scholarships
                                </a>

                                <div class="grid gap-2 sm:flex sm:justify-end">
                                    <button
                                        v-if="currentStep < steps.length - 1"
                                        type="button"
                                        :disabled="!canGoNext"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400 sm:w-auto"
                                        @click="nextStep"
                                    >
                                        {{ nextStepLabel }}
                                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        :disabled="isSubmitting || !canSubmitApplication"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400 sm:w-auto"
                                        @click="submitApplication"
                                    >
                                        {{ isSubmitting ? 'Submitting...' : 'Submit application' }}
                                        <i class="fa-solid fa-paper-plane text-xs" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    </template>

                    <section v-if="activeWorkspace === 'applications'" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 border-b border-slate-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="student-kicker">
                                    Application tracker
                                </p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">
                                    Your pre-screening submissions
                                </h3>
                                <p class="mt-1 text-sm text-slate-500">Open an application when a file, schedule, or decision needs your attention.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">
                                    {{ activeApplicationCount }} active
                                </span>
                                <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">
                                    {{ readyApplicationCount }} files ready
                                </span>
                                <span v-if="upcomingScheduleCount" class="rounded-md bg-amber-100 px-3 py-2 text-xs font-bold text-amber-900 ring-1 ring-amber-200">
                                    {{ upcomingScheduleCount }} upcoming {{ upcomingScheduleCount === 1 ? 'activity' : 'activities' }}
                                </span>
                            </div>
                        </div>

                        <div v-if="applications.length === 0" class="m-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">
                                No pre-screening submissions yet
                            </p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Choose a scholarship, confirm your documents, then submit for provider pre-screening.
                            </p>
                            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                                <a
                                    href="/dashboard/scholarships"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                >
                                    Browse scholarships
                                </a>
                                <a
                                    href="/dashboard/documents"
                                    class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Prepare documents
                                </a>
                            </div>
                        </div>

                        <div v-else class="grid gap-4 p-5">
                            <article
                                v-for="application in applicationQueue"
                                :key="application.id"
                                :class="[
                                    'overflow-hidden rounded-lg border border-l-4 bg-white shadow-sm',
                                    scheduleNeedsAcknowledgment(application)
                                        ? 'border-amber-200 border-l-amber-500'
                                        : 'border-slate-200 border-l-slate-900',
                                ]"
                            >
                                <div class="p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="flex min-w-0 gap-3">
                                            <img
                                                :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                                :alt="application.scholarship?.title || 'Scholarship'"
                                                class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                            >
                                            <div class="min-w-0">
                                                <h4 class="truncate font-bold text-slate-950">
                                                    {{ application.scholarship?.title || 'Scholarship' }}
                                                </h4>
                                                <p class="mt-1 text-sm text-slate-500">
                                                    Submitted {{ application.submitted_at || 'recently' }}
                                                </p>
                                                <p class="mt-1 truncate text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                    {{ application.scholarship?.provider?.name || 'Scholarship provider' }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap gap-2 sm:justify-end">
                                            <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                                {{ statusLabel(application.status) }}
                                            </span>
                                            <span
                                                v-if="application.distribution_scheduled_for && !hasDistributionSchedule(application)"
                                                class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase text-slate-700"
                                            >
                                                Distribution {{ application.distribution_scheduled_for }}
                                            </span>
                                        </div>
                                    </div>

                                    <div
                                        v-if="primarySchedule(application)"
                                        class="mt-4 border-t border-slate-200 pt-3"
                                    >
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                            <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md', primarySchedule(application).status === 'scheduled' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600']">
                                                <i :class="scheduleTypeIcon(primarySchedule(application).type)" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p :class="['text-[10px] font-bold uppercase tracking-[0.12em]', primarySchedule(application).status === 'scheduled' ? 'text-amber-700' : 'text-slate-500']">
                                                        {{ schedulePreviewLabel(primarySchedule(application)) }}
                                                    </p>
                                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600">
                                                        {{ labelFromKey(primarySchedule(application).status) }}
                                                    </span>
                                                </div>
                                                <p class="mt-1 truncate text-sm font-bold text-slate-950">
                                                    {{ scheduleTypeLabel(primarySchedule(application).type) }}
                                                </p>
                                                <p class="mt-0.5 text-xs leading-5 text-slate-500">
                                                    {{ primarySchedule(application).scheduled_label }}
                                                    <span class="px-1 text-slate-300">|</span>
                                                    {{ scheduleModeLabel(primarySchedule(application).mode) }}
                                                </p>
                                                <p v-if="primarySchedule(application).venue" class="mt-0.5 truncate text-xs text-slate-500">
                                                    {{ primarySchedule(application).venue }}
                                                </p>
                                            </div>
                                            <a
                                                :href="application.detail_url || `/dashboard/applications/${application.id}`"
                                                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-xs font-bold text-slate-700 transition hover:border-slate-500 hover:bg-slate-50"
                                            >
                                                Open schedule
                                                <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                            </a>
                                        </div>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600">
                                        <span class="rounded-md bg-slate-100 px-2.5 py-1">
                                            Stage: {{ application.status_progress?.label || statusLabel(application.status) }}
                                        </span>
                                        <span :class="['rounded-md px-2.5 py-1', recommendationClass(application.dss_recommendation)]">
                                            Suitability {{ application.dss_score ?? 0 }}%
                                        </span>
                                        <span class="rounded-md bg-slate-100 px-2.5 py-1 text-slate-700">
                                            Documents {{ application.document_readiness?.percent ?? 0 }}%
                                        </span>
                                        <span
                                            v-if="application.student_responded_at"
                                            class="rounded-md bg-slate-100 px-2.5 py-1 text-slate-700"
                                        >
                                            Responded {{ application.student_responded_at }}
                                        </span>
                                        <a
                                            :href="application.detail_url || `/dashboard/applications/${application.id}`"
                                            class="ml-auto rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-700 transition hover:bg-slate-50"
                                        >
                                            View details
                                        </a>
                                    </div>

                                    <details v-if="application.status_progress?.steps?.length" class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-slate-50">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-xs font-bold text-slate-700">
                                            <span class="flex items-center gap-2">
                                                <i class="fa-solid fa-route text-slate-400" aria-hidden="true"></i>
                                                View application flow
                                            </span>
                                            <span class="flex min-w-0 items-center gap-2 text-slate-500">
                                                <span class="hidden truncate font-semibold sm:block">{{ application.status_progress.next_action }}</span>
                                                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                                            </span>
                                        </summary>
                                        <div class="overflow-x-auto border-t border-slate-200 bg-white">
                                            <ol class="flex min-w-max divide-x divide-slate-200">
                                                <li
                                                    v-for="(step, index) in application.status_progress.steps"
                                                    :key="step.key"
                                                    :class="['flex min-w-[10rem] flex-1 items-center gap-2.5 px-3 py-3 text-xs', timelineStepClass(step.state)]"
                                                >
                                                    <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-full text-[10px] font-bold', step.state === 'complete' ? 'bg-white/15 text-white' : step.state === 'current' ? 'bg-amber-400 text-slate-950' : 'bg-slate-100 text-slate-500']">
                                                        <i v-if="step.state === 'complete'" class="fa-solid fa-check" aria-hidden="true"></i>
                                                        <span v-else>{{ index + 1 }}</span>
                                                    </span>
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-bold">{{ step.label }}</span>
                                                        <span class="mt-0.5 block text-[9px] font-semibold uppercase tracking-[0.08em] opacity-70">{{ progressStateLabel(step.state) }}</span>
                                                    </span>
                                                </li>
                                            </ol>
                                        </div>
                                    </details>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
