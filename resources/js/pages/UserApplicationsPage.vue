<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantGuideStrip from '../components/ApplicantGuideStrip.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { labelFromKey } from '../support/display';

const isLoading = ref(true);
const isSubmitting = ref(false);
const isUploadingDocument = ref(false);
const errorMessage = ref('');
const submitMessage = ref('');
const documentUploadMessage = ref('');
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
const selectedScholarshipId = ref('');
const documentChecklist = ref([]);
const notes = ref('');
const applicationTermsAccepted = ref(false);
const documentTermsAccepted = ref(false);
const documentFileInput = ref(null);
const activeUploadRequirement = ref('');

const steps = [
    { label: 'Program', detail: 'Selected scholarship' },
    { label: 'Details', detail: 'Quick review' },
    { label: 'Documents', detail: 'Upload files' },
    { label: 'Submit', detail: 'Final check' },
];
const applicationGuideItems = [
    {
        title: 'Start from a program',
        text: 'Pick one scholarship.',
        icon: 'fa-solid fa-graduation-cap',
    },
    {
        title: 'Upload requirements',
        text: 'Add or replace files.',
        icon: 'fa-solid fa-folder-tree',
    },
    {
        title: 'Track progress',
        text: 'Follow schedules and review status.',
        icon: 'fa-solid fa-timeline',
    },
];
const applicationModeOptions = [
    { value: 'online', label: 'Online submission' },
    { value: 'onsite', label: 'On-site submission' },
    { value: 'hybrid', label: 'Online and on-site' },
    { value: 'provider_review', label: 'Provider review only' },
];
const dssApplicationGuideItems = [
    { label: 'Eligibility', weight: '65%', detail: 'Profile fit against provider rules.' },
    { label: 'Academic', weight: '20%', detail: 'Grade or GWA compared with the requirement.' },
    { label: 'Financial need', weight: '15%', detail: 'Income context for assistance-focused programs.' },
];
const dssApplicationSupportItems = [
    { label: 'Documents', detail: 'Shown as readiness so you know what to upload.' },
    { label: 'Review stage', detail: 'Shown as progress after the provider starts checking.' },
];
const selectedScholarship = computed(() => scholarships.value.find((scholarship) => scholarship.id === Number(selectedScholarshipId.value)));
const selectedRequirements = computed(() => documentRequirements(selectedScholarship.value?.requirements));
const selectedContractSections = computed(() => {
    const scholarship = selectedScholarship.value;

    if (!scholarship) {
        return [];
    }

    return [
        { label: 'Return service contract', value: scholarship.return_service_contract },
        { label: 'Other contract terms', value: scholarship.other_contract_terms },
        { label: 'Renewal / continuation', value: scholarship.renewal_policy },
    ].filter((section) => section.value && String(section.value).trim());
});
const appliedScholarshipIds = computed(() => new Set(applications.value.map((application) => application.scholarship?.id).filter(Boolean)));
const selectedAlreadyApplied = computed(() => selectedScholarship.value && appliedScholarshipIds.value.has(selectedScholarship.value.id));
const allDocumentsChecked = computed(() => selectedRequirements.value.every((requirement) => documentChecklist.value.includes(requirement)));
const checkedDocumentCount = computed(() => selectedRequirements.value.filter((requirement) => documentChecklist.value.includes(requirement)).length);
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
const selectedMissingPreparedDocuments = computed(() => selectedRequirements.value
    .filter((requirement) => !preparedDocumentsByName.value.has(requirement)));
const selectedDocumentReadiness = computed(() => selectedRequirements.value.length === 0
    ? 100
    : Math.round((selectedPreparedDocuments.value.length / selectedRequirements.value.length) * 100));
const selectedApplicationMode = computed(() => applicationModeLabel(selectedScholarship.value?.application_mode));
const selectedSlotsLabel = computed(() => selectedScholarship.value?.slots_available ?? 'Not listed');
const readyApplicationCount = computed(() => applications.value.filter((application) => Number(application.document_readiness?.accepted_percent ?? application.document_readiness?.uploaded_percent ?? 0) >= 100).length);
const activeApplicationCount = computed(() => applications.value.filter((application) => ![
    'rejected',
    'not_awarded',
    'disbursed',
    'renewed',
    'exam_failed',
].includes(application.status ?? 'submitted')).length);
const pendingScheduleCount = computed(() => applications.value.reduce(
    (total, application) => total + applicationSchedules(application)
        .filter((schedule) => schedule.status === 'scheduled' && !schedule.applicant_acknowledged)
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
        rejected: 0,
        not_awarded: 0,
    };

    const firstRank = statusRank[first.status ?? 'submitted'] ?? 0;
    const secondRank = statusRank[second.status ?? 'submitted'] ?? 0;

    return secondRank - firstRank;
}));
const wizardReadinessItems = computed(() => [
    {
        label: 'Profile ready',
        complete: canApply.value,
        detail: canApply.value ? 'Student profile is complete.' : `${profileReadiness.value.percent}% profile readiness.`,
    },
    {
        label: 'Program selected',
        complete: Boolean(selectedScholarship.value) && selectedIsEligible.value,
        detail: selectedScholarship.value
            ? (selectedIsEligible.value ? selectedScholarship.value.title : selectedEligibilityMessage.value)
            : 'Choose from Scholarships first.',
    },
    {
        label: 'Required files uploaded',
        complete: selectedRequirements.value.length === 0 || selectedMissingPreparedDocuments.value.length === 0,
        detail: selectedRequirements.value.length === 0
            ? 'No document requirements listed.'
            : `${selectedPreparedDocuments.value.length} of ${selectedRequirements.value.length} files ready.`,
    },
]);
const wizardReadinessPercent = computed(() => Math.round((wizardReadinessItems.value.filter((item) => item.complete).length / wizardReadinessItems.value.length) * 100));
const canGoNext = computed(() => {
    if (!selectedCanStartApplication.value) {
        return false;
    }

    if (currentStep.value === 2) {
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

    if (index === 1 || index === 2) {
        return true;
    }

    if (index === 3) {
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
        exam_qualified: 'Qualified for exam',
        exam_scheduled: 'Exam scheduled',
        exam_taken: 'Exam taken',
        exam_passed: 'Passed exam',
        exam_failed: 'Failed exam',
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

    if (['rejected', 'not_awarded', 'exam_failed'].includes(status)) {
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

function primarySchedule(application) {
    const schedules = applicationSchedules(application);
    const unacknowledged = schedules.find(
        (schedule) => schedule.status === 'scheduled' && !schedule.applicant_acknowledged,
    );
    const active = schedules.find((schedule) => schedule.status === 'scheduled');

    return unacknowledged ?? active ?? schedules[schedules.length - 1] ?? null;
}

function scheduleNeedsAcknowledgment(application) {
    const schedule = primarySchedule(application);

    return Boolean(schedule?.status === 'scheduled' && !schedule.applicant_acknowledged);
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

function timelineStepClass(state) {
    if (state === 'complete') {
        return 'border-slate-900 bg-slate-900 text-white';
    }

    if (state === 'current') {
        return 'border-amber-300 bg-amber-50 text-slate-950';
    }

    if (state === 'skipped') {
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

function applicationModeLabel(value) {
    return applicationModeOptions.find((option) => option.value === value)?.label ?? labelFromKey(value || 'not_listed');
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
    documentUploadMessage.value = '';
    activeUploadRequirement.value = '';
    errorMessage.value = '';
    submitMessage.value = '';
    currentStep.value = 0;

    if (window.location.search) {
        window.history.replaceState({}, '', window.location.pathname);
    }
}

function openDocumentUpload(requirement) {
    errorMessage.value = '';
    documentUploadMessage.value = '';

    if (!documentTermsAccepted.value) {
        errorMessage.value = 'Accept the document upload terms before choosing a file.';
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
    documentUploadMessage.value = '';

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
        documentUploadMessage.value = `${requirement} is ready for this application.`;
    } catch (error) {
        errorMessage.value = error.response?.data?.errors?.document_file?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to save the document.';
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

        if (requestedScholarshipId) {
            const requestedScholarship = scholarships.value.find((scholarship) => scholarship.id === Number(requestedScholarshipId));

            if (requestedScholarship) {
                selectedScholarshipId.value = String(requestedScholarship.id);
                documentChecklist.value = [];
                notes.value = '';
                currentStep.value = requestedScholarship.can_start_application ? 1 : 0;

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
    submitMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.post('/dashboard/applications', {
            scholarship_id: selectedScholarship.value.id,
            document_checklist: documentChecklist.value,
            notes: notes.value,
            terms_accepted: applicationTermsAccepted.value ? '1' : '',
        });

        const message = response.data.message ?? 'Application submitted successfully.';
        await loadApplications();
        resetWizard();
        submitMessage.value = message;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to submit application.';
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
        documentUploadMessage.value = '';
        return;
    }

    syncDocumentChecklist();
    documentUploadMessage.value = '';
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
                    eyebrow="Application Desk"
                    title="Apply step by step"
                    description="Choose a program, submit files, and track provider updates."
                    icon="fa-solid fa-route"
                    action-href="/dashboard/scholarships"
                    action-label="Choose scholarship"
                    secondary-href="/dashboard/documents"
                    secondary-label="Prepare documents"
                />

                <ApplicantGuideStrip class="mt-5" :items="applicationGuideItems" />

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading application wizard...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </div>

                    <div v-if="submitMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ submitMessage }}
                    </div>

                    <div v-if="!canApply" class="student-card border-amber-200 bg-amber-50/90 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">
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

                    <section class="student-card p-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="student-kicker">
                                    Application Readiness
                                </p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    {{ selectedScholarship ? selectedScholarship.title : 'Choose a scholarship to start' }}
                                </h3>
                            </div>
                            <div class="w-full lg:max-w-xs">
                                <div class="flex items-center justify-between text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                    <span>Ready to submit</span>
                                    <span>{{ wizardReadinessPercent }}%</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${wizardReadinessPercent}%` }"></div>
                                </div>
                            </div>
                        </div>

                        <details class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm">
                            <summary class="cursor-pointer font-bold text-slate-950">
                                Suitability score guide
                            </summary>
                            <p class="mt-2 leading-6 text-slate-600">
                                DSS suitability uses eligibility, academic merit, and financial need. Documents and review stage are shown separately so the score is easier to understand.
                            </p>
                            <div class="mt-3 grid gap-2 md:grid-cols-3">
                                <div
                                    v-for="item in dssApplicationGuideItems"
                                    :key="item.label"
                                    class="rounded-md border border-slate-200 bg-white p-3"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-bold text-slate-950">{{ item.label }}</p>
                                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600">
                                            {{ item.weight }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">
                                        {{ item.detail }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-3 grid gap-2 md:grid-cols-2">
                                <div
                                    v-for="item in dssApplicationSupportItems"
                                    :key="item.label"
                                    class="rounded-md border border-slate-200 bg-white p-3"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-bold text-slate-950">{{ item.label }}</p>
                                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600">
                                            Separate
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-slate-600">
                                        {{ item.detail }}
                                    </p>
                                </div>
                            </div>
                            <p class="mt-3 text-xs font-semibold leading-5 text-slate-500">
                                Providers still make the final scholarship decision.
                            </p>
                        </details>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 bg-white p-4">
                            <div class="flex flex-col gap-3">
                                <div>
                                    <p class="student-kicker">
                                        Process
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Application flow
                                    </h3>
                                </div>

                                <nav class="flex gap-2 overflow-x-auto pb-1" aria-label="Application process">
                                    <button
                                        v-for="(step, index) in steps"
                                        :key="step.label"
                                        type="button"
                                        :disabled="!canOpenWizardStep(index)"
                                        :class="[
                                            'min-w-[7.75rem] flex-1 rounded-md border px-3 py-2 text-left transition disabled:cursor-not-allowed disabled:opacity-50',
                                            currentStep === index
                                                ? 'border-slate-900 bg-slate-900 text-white shadow-sm'
                                                : index < currentStep
                                                    ? 'border-slate-300 bg-slate-100 text-slate-800'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-950',
                                        ]"
                                        @click="goToWizardStep(index)"
                                    >
                                        <span class="text-xs font-bold uppercase tracking-[0.14em] opacity-70">
                                            Step {{ index + 1 }}
                                        </span>
                                        <span class="mt-1 block font-bold">
                                            {{ step.label }}
                                        </span>
                                        <span class="mt-1 block text-xs leading-4 opacity-70">
                                            {{ step.detail }}
                                        </span>
                                    </button>
                                </nav>
                            </div>
                        </div>

                        <div class="bg-slate-50 p-4">
                            <div v-if="currentStep === 0">
                                <div class="rounded-lg border border-slate-200 bg-white p-4">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                Selected Program
                                            </p>
                                            <h3 class="mt-1 text-lg font-bold text-slate-950">
                                                Choose from Scholarships first
                                            </h3>
                                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
                                                Applications use the program selected from Scholarships.
                                            </p>
                                        </div>
                                        <a
                                            href="/dashboard/scholarships"
                                            class="inline-flex justify-center rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                        >
                                            Choose scholarship
                                        </a>
                                    </div>

                                    <div v-if="selectedScholarship" class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
                                        <div class="flex gap-3">
                                            <img
                                                :src="selectedScholarship.image_url"
                                                :alt="selectedScholarship.title"
                                                class="h-12 w-12 shrink-0 rounded-md bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200"
                                            >
                                            <div class="min-w-0">
                                                <p class="truncate text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    {{ selectedScholarship.provider?.name || 'Scholarship Provider' }}
                                                </p>
                                                <h4 class="mt-1 text-lg font-bold text-slate-950">
                                                    {{ selectedScholarship.title }}
                                                </h4>
                                                <p class="mt-1 line-clamp-2 text-sm leading-6 text-slate-600">
                                                    {{ selectedScholarship.description }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="mt-4 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-5">
                                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                <p class="font-semibold text-slate-500">Award</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ formatAmount(selectedScholarship.award_amount) }}</p>
                                            </div>
                                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                <p class="font-semibold text-slate-500">Deadline</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ selectedScholarship.deadline || 'No deadline' }}</p>
                                            </div>
                                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                <p class="font-semibold text-slate-500">Documents</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ selectedRequirements.length }}</p>
                                            </div>
                                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                <p class="font-semibold text-slate-500">Mode</p>
                                                <p class="mt-1 font-bold text-slate-950">{{ selectedApplicationMode }}</p>
                                            </div>
                                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                                <p class="font-semibold text-slate-500">Match</p>
                                                <p :class="['mt-1 inline-flex rounded-md px-2 py-1 text-xs font-bold', matchClass(selectedScholarship.eligibility_match?.score)]">
                                                    {{ selectedScholarship.eligibility_match?.score ?? 0 }}%
                                                </p>
                                            </div>
                                        </div>
                                        <div v-if="!selectedIsEligible" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm leading-6 text-rose-800">
                                            <p class="font-bold">
                                                Not eligible to apply
                                            </p>
                                            <p class="mt-1">
                                                {{ selectedEligibilityMessage }}
                                            </p>
                                        </div>
                                    </div>

                                    <div v-else class="mt-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                        No scholarship is selected yet. Open Scholarships, pick a program, then start the application from there.
                                    </div>
                                </div>
                            </div>

                            <div v-else-if="currentStep === 1 && selectedScholarship" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_19rem]">
                                <section class="rounded-lg border border-slate-200 bg-white p-4">
                                    <div class="flex gap-3">
                                        <img
                                            :src="selectedScholarship.image_url"
                                            :alt="selectedScholarship.title"
                                            class="h-12 w-12 shrink-0 rounded-md bg-slate-50 object-contain p-1.5 ring-1 ring-slate-200"
                                        >
                                        <div class="min-w-0">
                                            <p class="student-kicker">
                                                Scholarship Details
                                            </p>
                                            <h3 class="mt-1 text-lg font-bold text-slate-950">
                                                {{ selectedScholarship.title }}
                                            </h3>
                                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                                {{ selectedScholarship.description }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Provider
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.provider?.name || 'Scholarship Provider' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Award
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ formatAmount(selectedScholarship.award_amount) }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Academic requirement
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ academicRequirementLabel(selectedScholarship) }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Deadline
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.deadline || 'No deadline' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Match score
                                            </p>
                                            <p :class="['mt-1 inline-flex rounded-md px-2 py-1 text-xs font-bold', matchClass(selectedScholarship.eligibility_match?.score)]">
                                                {{ selectedScholarship.eligibility_match?.score ?? 0 }}% - {{ selectedScholarship.eligibility_match?.label || 'Needs review' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Application mode
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedApplicationMode }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Slots
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedSlotsLabel }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Documents ready
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedDocumentReadiness }}%
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                        <p class="font-semibold text-slate-500">
                                            Eligibility
                                        </p>
                                        <p class="mt-1 leading-6 text-slate-700">
                                            {{ selectedScholarship.eligibility || 'Not listed yet' }}
                                        </p>
                                    </div>

                                    <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                        <p class="font-semibold text-slate-500">
                                            Match guide
                                        </p>
                                        <p class="mt-1 leading-6 text-slate-700">
                                                {{ selectedScholarship.eligibility_match?.summary || selectedScholarship.eligibility_guide?.note || 'Review the scholarship requirements before submitting.' }}
                                            </p>
                                        <p v-if="!selectedIsEligible" class="mt-2 rounded-md border border-rose-200 bg-rose-50 p-2 text-xs font-bold leading-5 text-rose-800">
                                            {{ selectedEligibilityMessage }}
                                        </p>
                                        <div v-if="selectedScholarship.eligibility_match?.criteria?.length" class="mt-3 flex flex-wrap gap-2">
                                            <span
                                                v-for="criterion in selectedScholarship.eligibility_match.criteria"
                                                :key="criterion.key"
                                                :class="['rounded-md border px-2.5 py-1.5 text-xs font-bold', criterionClass(criterion.status)]"
                                            >
                                                {{ criterion.label }}: {{ criterion.status }}
                                            </span>
                                        </div>
                                    </div>
                                </section>

                                <section class="rounded-lg border border-slate-200 bg-white p-4">
                                    <p class="student-kicker">
                                        Applicant Record
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Confirm your details
                                    </h3>
                                    <div class="mt-3 grid gap-2 text-sm">
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Name
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.name }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Email
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.email }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Contact number
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.contact_number || 'Not provided' }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Academic details
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.course_or_strand || 'Track not set' }} - {{ user?.year_level || 'Grade/year not set' }}
                                            </p>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <div v-else-if="currentStep === 2 && selectedScholarship" class="space-y-4">
                                <div class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            Document Checklist
                                        </p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            Upload required files
                                        </h3>
                                        <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
                                            Add each requirement here. Uploaded files are also saved in Documents for reuse.
                                        </p>
                                    </div>
                                    <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                                        <p class="font-bold text-slate-950">
                                            {{ checkedDocumentCount }} / {{ selectedRequirements.length }}
                                        </p>
                                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                            Prepared
                                        </p>
                                    </div>
                                </div>

                                <input
                                    ref="documentFileInput"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                    class="hidden"
                                    @change="handleDocumentFileChange"
                                >

                                <div v-if="selectedRequirements.length" class="space-y-3">
                                    <div class="rounded-lg border border-slate-200 bg-white p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    File readiness
                                                </p>
                                                <p class="mt-1 text-sm font-bold text-slate-950">
                                                    {{ selectedPreparedDocuments.length }} of {{ selectedRequirements.length }} required files ready
                                                </p>
                                            </div>
                                            <span class="font-display text-2xl font-bold text-slate-950">
                                                {{ selectedDocumentReadiness }}%
                                            </span>
                                        </div>
                                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${selectedDocumentReadiness}%` }"></div>
                                        </div>
                                        <div class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <TermsAgreement
                                                    v-model="documentTermsAccepted"
                                                    context="document"
                                                />
                                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                                    Accept once to upload or replace files during this step.
                                                </p>
                                            </div>
                                            <a
                                                href="/dashboard/documents"
                                                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                            >
                                                <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
                                                Open Documents
                                            </a>
                                        </div>
                                    </div>

                                    <div v-if="documentUploadMessage" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
                                        {{ documentUploadMessage }}
                                    </div>

                                    <div class="grid gap-2">
                                        <article
                                            v-for="requirement in selectedRequirements"
                                            :key="requirement"
                                            :class="[
                                                'flex flex-col gap-3 rounded-lg border bg-white p-3 sm:flex-row sm:items-center sm:justify-between',
                                                preparedDocumentFor(requirement)
                                                    ? 'border-slate-300'
                                                    : 'border-amber-200',
                                            ]"
                                        >
                                            <div class="flex min-w-0 items-start gap-3">
                                                <span
                                                    :class="[
                                                        'grid h-9 w-9 shrink-0 place-items-center rounded-md text-sm',
                                                        preparedDocumentFor(requirement)
                                                            ? 'bg-slate-900 text-white'
                                                            : 'bg-amber-50 text-amber-700',
                                                    ]"
                                                >
                                                    <i :class="preparedDocumentFor(requirement) ? 'fa-solid fa-file-circle-check' : 'fa-regular fa-file'" aria-hidden="true"></i>
                                                </span>
                                                <div class="min-w-0">
                                                    <p class="font-bold text-slate-900">
                                                        {{ requirement }}
                                                    </p>
                                                    <p v-if="preparedDocumentFor(requirement)" class="mt-1 truncate text-xs text-slate-500">
                                                        {{ preparedDocumentFor(requirement).original_name }} - {{ formatFileSize(preparedDocumentFor(requirement).size) }}
                                                    </p>
                                                    <p v-else class="mt-1 text-xs font-semibold text-amber-700">
                                                        Upload this file before continuing.
                                                    </p>
                                                </div>
                                            </div>

                                            <div class="flex shrink-0 items-center gap-2">
                                                <span :class="['rounded-md px-2 py-1 text-[0.68rem] font-bold uppercase', preparedDocumentFor(requirement) ? 'bg-slate-100 text-slate-700' : 'bg-amber-50 text-amber-700']">
                                                    {{ preparedDocumentFor(requirement) ? 'Ready' : 'Missing' }}
                                                </span>
                                                <a
                                                    v-if="preparedDocumentFor(requirement)?.view_url"
                                                    :href="preparedDocumentFor(requirement).view_url"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                                >
                                                    View
                                                </a>
                                                <button
                                                    type="button"
                                                    :disabled="isUploadingDocument"
                                                    class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                                    @click="openDocumentUpload(requirement)"
                                                >
                                                    <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i>
                                                    {{ isUploadingDocument && activeUploadRequirement === requirement
                                                        ? 'Uploading...'
                                                        : preparedDocumentFor(requirement) ? 'Replace' : 'Upload' }}
                                                </button>
                                            </div>
                                        </article>
                                    </div>

                                    <div
                                        v-if="selectedMissingPreparedDocuments.length === 0"
                                        class="flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"
                                    >
                                        <i class="fa-solid fa-circle-check mt-0.5" aria-hidden="true"></i>
                                        <div>
                                            <p class="font-bold">
                                                All required files are ready
                                            </p>
                                            <p class="mt-1 leading-5">
                                                Continue when ready. You can replace a file from the application record after submitting.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div v-else class="rounded-lg border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">
                                    This scholarship has no listed document requirements, so you can continue to review.
                                </div>

                                <div class="rounded-lg border border-slate-200 bg-white p-4">
                                    <label for="application-notes" class="mb-2 block text-sm font-semibold text-slate-700">
                                        Optional note to provider
                                    </label>
                                    <textarea
                                        id="application-notes"
                                        v-model="notes"
                                        rows="4"
                                        maxlength="1000"
                                        placeholder="Add a short note about your application if needed"
                                        class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-slate-700 focus:ring-3 focus:ring-slate-100"
                                    ></textarea>
                                </div>
                            </div>

                            <div v-else-if="currentStep === 3 && selectedScholarship" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                                <section class="rounded-lg border border-slate-200 bg-white p-4">
                                    <p class="student-kicker">
                                        Final Review
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Ready to submit
                                    </h3>
                                    <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Scholarship
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.title }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Applicant
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ user?.name }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Required files
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ documentChecklist.length }} of {{ selectedRequirements.length }}
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                File updates
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                Available after submission
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Match score
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedScholarship.eligibility_match?.score ?? 0 }}%
                                            </p>
                                        </div>
                                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                            <p class="font-semibold text-slate-500">
                                                Application mode
                                            </p>
                                            <p class="mt-1 font-bold text-slate-950">
                                                {{ selectedApplicationMode }}
                                            </p>
                                        </div>
                                    </div>
                                </section>

                                <section class="rounded-lg border border-slate-200 bg-white p-4">
                                    <p class="student-kicker">
                                        Notes
                                    </p>
                                    <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700">
                                        These prepared files will be attached automatically. You can still view or replace them from the application record after submitting.
                                    </div>
                                    <p class="mt-3 rounded-md border border-slate-200 bg-white p-3 text-sm leading-6 text-slate-600">
                                        {{ notes || 'No note added.' }}
                                    </p>

                                    <div v-if="selectedContractSections.length" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            Possible obligations after acceptance
                                        </p>
                                        <p class="mt-2 text-xs leading-5 text-slate-600">
                                            If selected, the provider will confirm which agreements or service obligations apply to you.
                                        </p>
                                        <div class="mt-3 grid gap-3">
                                            <div
                                                v-for="section in selectedContractSections"
                                                :key="section.label"
                                                class="rounded-md border border-slate-200 bg-white p-3 text-sm"
                                            >
                                                <p class="font-bold text-slate-800">
                                                    {{ section.label }}
                                                </p>
                                                <p class="mt-1 whitespace-pre-line leading-6 text-slate-600">
                                                    {{ section.value }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <TermsAgreement
                                        v-model="applicationTermsAccepted"
                                        class="mt-4"
                                        context="application"
                                    />

                                    <button
                                        type="button"
                                        :disabled="isSubmitting || !canSubmitApplication"
                                        class="mt-5 w-full rounded-md bg-slate-900 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400"
                                        @click="submitApplication"
                                    >
                                        {{ isSubmitting ? 'Submitting...' : 'Submit application' }}
                                    </button>
                                </section>
                            </div>
                        </div>

                        <div class="border-t border-slate-200 bg-white px-5 py-4">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <button
                                    type="button"
                                    class="w-full rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                                    :disabled="currentStep === 0"
                                    @click="previousStep"
                                >
                                    Back
                                </button>

                                <div class="grid gap-2 sm:flex sm:justify-end">
                                    <button
                                        type="button"
                                        class="w-full rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 sm:w-auto"
                                        @click="resetWizard"
                                    >
                                        Reset
                                    </button>
                                    <button
                                        v-if="currentStep < steps.length - 1"
                                        type="button"
                                        :disabled="!canGoNext"
                                        class="w-full rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-400 sm:w-auto"
                                        @click="nextStep"
                                    >
                                        Continue
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="student-kicker">
                                    Submitted Applications
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Review timeline
                                </h3>
                            </div>
                            <span v-if="pendingScheduleCount" class="w-fit rounded-md bg-amber-100 px-3 py-2 text-xs font-bold text-amber-900 ring-1 ring-amber-200">
                                {{ pendingScheduleCount }} schedule {{ pendingScheduleCount === 1 ? 'confirmation' : 'confirmations' }} needed
                            </span>
                        </div>

                        <div v-if="applications.length === 0" class="m-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">
                                No submitted applications yet
                            </p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                Choose a scholarship, confirm your documents, then submit through the wizard above.
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
                                        :class="[
                                            'mt-4 rounded-lg border p-3',
                                            scheduleNeedsAcknowledgment(application)
                                                ? 'border-amber-200 bg-amber-50'
                                                : 'border-slate-200 bg-slate-50',
                                        ]"
                                    >
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                            <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md text-white', scheduleNeedsAcknowledgment(application) ? 'bg-amber-600' : 'bg-slate-900']">
                                                <i :class="scheduleTypeIcon(primarySchedule(application).type)" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                                        {{ scheduleTypeLabel(primarySchedule(application).type) }}
                                                    </p>
                                                    <span v-if="scheduleNeedsAcknowledgment(application)" class="rounded bg-amber-200 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-900">
                                                        Confirmation needed
                                                    </span>
                                                    <span v-else class="rounded bg-white px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600 ring-1 ring-slate-200">
                                                        {{ labelFromKey(primarySchedule(application).status) }}
                                                    </span>
                                                </div>
                                                <p class="mt-1 truncate text-sm font-bold text-slate-950">
                                                    {{ primarySchedule(application).title }}
                                                </p>
                                                <p class="mt-1 text-xs leading-5 text-slate-600">
                                                    {{ primarySchedule(application).scheduled_label }}
                                                    <span class="px-1 text-slate-300">|</span>
                                                    {{ scheduleModeLabel(primarySchedule(application).mode) }}
                                                    <template v-if="primarySchedule(application).venue">
                                                        <span class="px-1 text-slate-300">|</span>
                                                        {{ primarySchedule(application).venue }}
                                                    </template>
                                                </p>
                                                <p v-if="primarySchedule(application).type === 'distribution'" class="mt-1 text-xs font-bold text-emerald-700">
                                                    Award: {{ formatAmount(application.awarded_amount) }}
                                                </p>
                                            </div>
                                            <a
                                                :href="application.detail_url || `/dashboard/applications/${application.id}`"
                                                :class="[
                                                    'shrink-0 rounded-md px-3 py-2 text-center text-xs font-bold transition',
                                                    scheduleNeedsAcknowledgment(application)
                                                        ? 'bg-slate-900 text-white hover:bg-slate-800'
                                                        : 'border border-slate-300 bg-white text-slate-700 hover:border-slate-500',
                                                ]"
                                            >
                                                {{ scheduleNeedsAcknowledgment(application) ? 'Review and confirm' : 'View schedule' }}
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

                                    <div v-if="application.status_progress?.steps?.length" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                                Timeline preview
                                            </p>
                                            <p class="text-xs font-semibold text-slate-500">
                                                {{ application.status_progress.next_action }}
                                            </p>
                                        </div>
                                        <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                            <div
                                                v-for="step in application.status_progress.steps"
                                                :key="step.key"
                                                :class="['rounded-md border px-2.5 py-2 text-center text-[11px] font-bold', timelineStepClass(step.state)]"
                                            >
                                                {{ step.label }}
                                            </div>
                                        </div>
                                    </div>
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
