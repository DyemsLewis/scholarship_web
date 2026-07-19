<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import TermsAgreement from '../components/TermsAgreement.vue';
import { formatFileSize, labelFromKey as formatKeyLabel } from '../support/display';
import { programEventForStage, progressStateLabel, progressStepIcon } from '../support/selectionPlan';

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
const acknowledgingScheduleId = ref(null);
const activeSection = ref('overview');

const requiredDocuments = computed(() => documentRequirements(application.value?.scholarship?.requirements));
const confirmedDocuments = computed(() => application.value?.document_checklist ?? []);
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
const dssCriteria = computed(() => application.value?.dss_breakdown?.criteria ?? []);
const dssDecisionNotice = computed(() => application.value?.dss_breakdown?.decision_notice ?? 'This score supports screening only. The scholarship provider makes the final decision.');
const applicantNextStep = computed(() => applicantNextAction(application.value));
const timeline = computed(() => application.value?.timeline ?? []);
const schedules = computed(() => application.value?.schedules ?? []);
const currentSchedule = computed(() => schedules.value.find((schedule) => schedule.status === 'scheduled') ?? null);
const filesNeedingAction = computed(() => applicationFileRows.value.filter((row) => !row.document
    || ['needs_replacement', 'rejected'].includes(row.document.status)));
const applicationIsClosed = computed(() => ['rejected', 'not_awarded', 'exam_failed', 'disbursed'].includes(application.value?.status));
const applicationSections = computed(() => [
    { key: 'overview', label: 'Overview' },
    { key: 'files', label: 'Required files', count: filesNeedingAction.value.length },
    { key: 'program', label: 'Program & match' },
    { key: 'history', label: 'History', count: timeline.value.length },
]);
const nextActionButton = computed(() => {
    if (currentSchedule.value && !currentSchedule.value.applicant_acknowledged) {
        return { label: 'Review schedule', section: 'overview', target: 'application-schedules' };
    }

    if (!applicationIsClosed.value && filesNeedingAction.value.length) {
        return { label: 'Review required files', section: 'files' };
    }

    if (currentSchedule.value) {
        return { label: 'View schedule', section: 'overview', target: 'application-schedules' };
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

function progressStepEvent(step) {
    return programEventForStage(applicationScholarship.value, step?.key);
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

function scheduleStatusClass(status) {
    if (status === 'completed') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'cancelled') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
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

function stepClass(state) {
    if (state === 'complete') {
        return 'bg-slate-900 text-white';
    }

    if (state === 'current') {
        return 'bg-slate-100 text-slate-950 ring-1 ring-slate-300';
    }

    if (['stopped', 'skipped'].includes(state)) {
        return 'bg-rose-50 text-rose-700 ring-1 ring-rose-100';
    }

    return 'bg-white text-slate-500 ring-1 ring-slate-200';
}

function labelFromKey(value) {
    const labels = {
        for_exam: 'Meets exam eligibility',
        exam_scheduled: 'Exam scheduled',
        exam_completed: 'Exam completed',
        passed_exam: 'Passed exam',
        failed_exam: 'Failed exam',
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
    const unacknowledgedSchedule = activeSchedule && !activeSchedule.applicant_acknowledged
        ? activeSchedule
        : null;

    if (unacknowledgedSchedule) {
        return `Review and confirm the ${scheduleTypeLabel(unacknowledgedSchedule.type)} details below.`;
    }

    if (activeSchedule) {
        return `Follow the posted ${scheduleTypeLabel(activeSchedule.type)} instructions and attend at the scheduled time.`;
    }

    if (['awarded', 'disbursed', 'renewed'].includes(current.status)) {
        return 'Your award is recorded. Watch for release or renewal updates.';
    }

    if (current.status === 'distribution_scheduled') {
        return `Your reward distribution is scheduled for ${current.distribution_scheduled_for || 'the provider date'}. Review the instructions below.`;
    }

    if (['rejected', 'not_awarded', 'exam_failed'].includes(current.status)) {
        return 'This application is closed. Check review notes for the provider decision.';
    }

    if (current.status === 'exam_qualified') {
        return 'You passed initial screening. Wait for the provider to send the exam schedule or instructions.';
    }

    if (current.status === 'exam_scheduled') {
        return 'Take the scholarship exam as instructed by the provider.';
    }

    if (current.status === 'exam_taken') {
        return 'Your exam is recorded as taken. Wait for the provider to post the result.';
    }

    if (current.status === 'exam_passed') {
        return 'You passed the scholarship exam. Wait for final provider award review.';
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
        errorMessage.value = 'Please agree to the document upload terms first.';
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
    await nextTick();

    if (target) {
        document.getElementById(target)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
        errorMessage.value = 'Please accept the document upload terms before uploading.';
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

async function acknowledgeSchedule(schedule) {
    if (!application.value || schedule.status !== 'scheduled' || schedule.applicant_acknowledged) {
        return;
    }

    acknowledgingScheduleId.value = schedule.id;
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(
            `/dashboard/applications/${application.value.id}/schedules/${schedule.id}/acknowledge`,
        );

        application.value = response.data.application;
    } catch (handledError) {
        void handledError;
    } finally {
        acknowledgingScheduleId.value = null;
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
                    eyebrow="Application Details"
                    title="Track your application"
                    :description="application ? `${application.scholarship?.title || 'Scholarship'} - ${application.scholarship?.provider?.name || 'Scholarship provider'}` : 'See your status, next step, and required files.'"
                    icon="fa-solid fa-file-circle-check"
                    action-href="/dashboard/applications"
                    action-label="Back to applications"
                    secondary-href="/dashboard/documents"
                    secondary-label="Documents"
                />

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

                    <section class="relative overflow-hidden rounded-lg bg-slate-950 text-white shadow-sm">
                        <div class="pointer-events-none absolute inset-y-0 right-0 w-1/2 bg-[radial-gradient(circle_at_top_right,_rgba(251,191,36,0.18),_transparent_62%)]"></div>
                        <div class="relative flex flex-col gap-5 p-5 sm:p-6 lg:flex-row lg:items-start lg:justify-between">
                            <div class="flex min-w-0 gap-4">
                                <img
                                    :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="application.scholarship?.title || 'Scholarship'"
                                    class="h-16 w-16 shrink-0 rounded-md bg-white object-contain p-2 ring-1 ring-white/20"
                                >
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-300">
                                        Application #{{ application.id }}
                                    </p>
                                    <h3 class="mt-1 font-display text-xl font-bold text-white sm:text-2xl">
                                        {{ application.scholarship?.title || 'Scholarship' }}
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-300">
                                        {{ application.scholarship?.provider?.name || 'Scholarship provider' }}
                                    </p>
                                </div>
                            </div>

                            <div class="shrink-0 lg:text-right">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Current status</p>
                                <span :class="['mt-2 inline-flex w-fit rounded-md px-3 py-1.5 text-xs font-bold uppercase', statusClass(application.status)]">
                                    {{ statusLabel(application.status) }}
                                </span>
                            </div>
                        </div>

                        <div class="relative grid border-t border-white/10 bg-white/5 text-sm sm:grid-cols-3">
                            <div class="border-b border-white/10 px-5 py-3 sm:border-b-0 sm:border-r sm:border-white/10">
                                <p class="text-xs font-semibold text-slate-400">Submitted</p>
                                <p class="mt-1 font-bold text-white">{{ application.submitted_at || 'Recently' }}</p>
                            </div>
                            <div class="border-b border-white/10 px-5 py-3 sm:border-b-0 sm:border-r sm:border-white/10">
                                <p class="text-xs font-semibold text-slate-400">Current stage</p>
                                <p class="mt-1 font-bold text-white">{{ application.status_progress?.current_stage_label || statusLabel(application.status) }}</p>
                            </div>
                            <div class="px-5 py-3">
                                <p class="text-xs font-semibold text-slate-400">Program deadline</p>
                                <p class="mt-1 font-bold text-white">{{ application.scholarship?.deadline || 'Not listed' }}</p>
                            </div>
                        </div>
                    </section>

                    <section class="flex flex-col gap-4 rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-200 text-amber-900">
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-800">What to do now</p>
                                <p class="mt-1 text-sm font-semibold leading-6 text-slate-800">{{ applicantNextStep }}</p>
                            </div>
                        </div>
                        <button
                            v-if="nextActionButton"
                            type="button"
                            class="shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            @click="openSection(nextActionButton.section, nextActionButton.target)"
                        >
                            {{ nextActionButton.label }}
                        </button>
                    </section>

                    <nav class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-1.5 shadow-sm" aria-label="Application details sections">
                        <div class="flex min-w-max gap-1" role="tablist">
                            <button
                                v-for="section in applicationSections"
                                :key="section.key"
                                type="button"
                                role="tab"
                                :aria-selected="activeSection === section.key"
                                :class="[
                                    'flex items-center gap-2 rounded-md px-4 py-2.5 text-sm font-bold transition',
                                    activeSection === section.key
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950',
                                ]"
                                @click="openSection(section.key)"
                            >
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

                    <div :class="['grid gap-4', activeSection === 'files' ? '' : 'lg:grid-cols-[minmax(0,1fr)_21rem]']">
                        <div class="space-y-4">
                            <section v-if="activeSection === 'overview' && application.status_progress" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Selection journey</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            {{ application.status_progress.current_stage_label }}
                                        </h3>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">This follows the process configured by the scholarship provider.</p>
                                    </div>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ application.status_progress.completed_steps }} of {{ application.status_progress.total_steps }} complete
                                    </span>
                                </div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${application.status_progress.percent}%` }"></div>
                                </div>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                                    <div
                                        v-for="step in application.status_progress.steps"
                                        :key="step.key"
                                        :class="['rounded-md p-3 text-xs', stepClass(step.state)]"
                                    >
                                        <div class="flex items-start gap-2.5">
                                            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-current/10">
                                                <i :class="progressStepIcon(step.key)" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="font-bold">{{ step.label }}</p>
                                                <p class="mt-0.5 text-[10px] font-semibold uppercase tracking-[0.08em] opacity-70">{{ progressStateLabel(step.state) }}</p>
                                            </div>
                                        </div>
                                        <p v-if="progressStepEvent(step)" class="mt-2 border-t border-current/10 pt-2 text-[11px] font-semibold leading-4">
                                            {{ progressStepEvent(step).scheduled_label }}
                                        </p>
                                    </div>
                                </div>
                                <p class="mt-3 rounded-md bg-slate-50 px-3 py-2.5 text-sm leading-6 text-slate-600 ring-1 ring-slate-200">
                                    {{ application.status_progress.next_action }}
                                </p>
                            </section>

                            <section v-if="activeSection === 'overview' && schedules.length" id="application-schedules" class="scroll-mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="flex flex-col gap-2 border-b border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Schedule</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">Dates and instructions</h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">Review each posted activity and confirm active schedules after reading the details.</p>
                                    </div>
                                    <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ schedules.length }} {{ schedules.length === 1 ? 'activity' : 'activities' }}
                                    </span>
                                </div>

                                <div class="divide-y divide-slate-200">
                                    <article v-for="schedule in schedules" :key="schedule.id" class="p-4">
                                        <div class="flex items-start gap-3">
                                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-white">
                                                <i :class="scheduleTypeIcon(schedule.type)" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                    <div>
                                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-amber-700">{{ scheduleTypeLabel(schedule.type) }}</p>
                                                        <h4 class="mt-1 font-bold text-slate-950">{{ schedule.title }}</h4>
                                                    </div>
                                                    <span :class="['w-fit rounded-md px-2 py-1 text-[11px] font-bold uppercase', scheduleStatusClass(schedule.status)]">
                                                        {{ labelFromKey(schedule.status) }}
                                                    </span>
                                                </div>

                                                <div class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                                    <div class="rounded-md bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                                                        <p class="text-xs font-semibold text-slate-500">Date and time</p>
                                                        <p class="mt-1 font-bold text-slate-800">{{ schedule.scheduled_label }}</p>
                                                    </div>
                                                    <div class="rounded-md bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                                                        <p class="text-xs font-semibold text-slate-500">Mode</p>
                                                        <p class="mt-1 font-bold text-slate-800">{{ scheduleModeLabel(schedule.mode) }}</p>
                                                    </div>
                                                    <div v-if="schedule.type === 'distribution'" class="rounded-md bg-emerald-50 px-3 py-2.5 ring-1 ring-emerald-200 sm:col-span-2">
                                                        <p class="text-xs font-semibold text-emerald-700">Award amount</p>
                                                        <p class="mt-1 font-bold text-emerald-900">{{ formatAwardAmount(application.awarded_amount) }}</p>
                                                    </div>
                                                </div>

                                                <div v-if="schedule.venue || schedule.location_address" class="mt-3 text-sm leading-6 text-slate-600">
                                                    <p v-if="schedule.venue"><span class="font-bold text-slate-800">Site:</span> {{ schedule.venue }}</p>
                                                    <p v-if="schedule.location_address">{{ schedule.location_address }}</p>
                                                </div>

                                                <a
                                                    v-if="schedule.online_url"
                                                    :href="schedule.online_url"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="mt-3 inline-flex items-center gap-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-bold text-sky-800 hover:bg-sky-100"
                                                >
                                                    Open access link
                                                    <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                                                </a>

                                                <p class="mt-3 whitespace-pre-line rounded-md bg-slate-50 p-3 text-sm leading-6 text-slate-600 ring-1 ring-slate-200">{{ schedule.instructions }}</p>

                                                <details
                                                    v-if="schedule.status !== 'cancelled' && (hasCoordinates(schedule.latitude, schedule.longitude) || schedule.location_address || schedule.venue)"
                                                    class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3"
                                                >
                                                    <summary class="cursor-pointer text-sm font-bold text-slate-700">View schedule map</summary>
                                                    <div class="mt-3 overflow-hidden rounded-md">
                                                        <LeafletMapPreview
                                                            :address="schedule.location_address || schedule.venue"
                                                            :latitude="schedule.latitude"
                                                            :longitude="schedule.longitude"
                                                            :title="schedule.venue || schedule.title"
                                                            :marker-text="schedule.venue || schedule.title"
                                                            height="11rem"
                                                            auto-geocode
                                                        />
                                                    </div>
                                                </details>

                                                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <div class="flex flex-wrap gap-2 text-xs font-bold">
                                                        <span v-if="schedule.applicant_acknowledged" class="rounded-md bg-emerald-50 px-2.5 py-1.5 text-emerald-800 ring-1 ring-emerald-200">
                                                            Confirmed {{ schedule.applicant_acknowledged_at }}
                                                        </span>
                                                        <span v-if="schedule.attendance_status !== 'pending'" class="rounded-md bg-slate-100 px-2.5 py-1.5 text-slate-700">
                                                            {{ schedule.type === 'distribution' ? 'Release' : 'Participation' }}: {{ labelFromKey(schedule.attendance_status) }}
                                                        </span>
                                                    </div>
                                                    <button
                                                        v-if="schedule.status === 'scheduled' && !schedule.applicant_acknowledged"
                                                        type="button"
                                                        :disabled="acknowledgingScheduleId === schedule.id"
                                                        class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                                        @click="acknowledgeSchedule(schedule)"
                                                    >
                                                        {{ acknowledgingScheduleId === schedule.id ? 'Confirming...' : 'I have seen this schedule' }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                </div>
                            </section>

                            <section v-if="activeSection === 'overview' && !schedules.length" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="font-bold text-slate-950">{{ applicationIsClosed ? 'No schedule required' : 'No schedule posted yet' }}</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            {{ applicationIsClosed
                                                ? 'This application has no upcoming activity to attend. Review the provider update for the recorded outcome.'
                                                : 'There is no exam, interview, or distribution date for you to attend right now. You will receive an update when the provider publishes one.' }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'overview' && application.exam" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="grid sm:grid-cols-[9rem_minmax(0,1fr)]">
                                    <div class="flex h-36 items-center justify-center border-b border-slate-200 bg-slate-50 p-4 sm:border-b-0 sm:border-r">
                                        <img :src="application.exam.image_url" :alt="application.exam.title" class="h-full w-full object-contain">
                                    </div>
                                    <div class="p-4">
                                        <p class="student-kicker">Assessment</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ application.exam.title }}</h3>
                                        <p v-if="application.exam.description" class="mt-2 text-sm leading-6 text-slate-600">{{ application.exam.description }}</p>
                                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-700">
                                            <span class="rounded-md bg-slate-100 px-2.5 py-1">{{ labelFromKey(application.exam.assessment_type) }}</span>
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

                            <section v-if="activeSection === 'program'" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Program summary</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">What you applied for</h3>
                                    </div>
                                    <a
                                        :href="`/dashboard/scholarships/${application.scholarship?.id}`"
                                        class="w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                    >
                                        View scholarship
                                    </a>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ application.scholarship?.description || 'No program description was provided.' }}
                                </p>
                                <div class="mt-4 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <p class="text-xs font-semibold text-slate-500">Award</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ formatAwardAmount(application.scholarship?.award_amount) }}</p>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <p class="text-xs font-semibold text-slate-500">Deadline</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ application.scholarship?.deadline || 'Not listed' }}</p>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <p class="text-xs font-semibold text-slate-500">Application mode</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.scholarship?.application_mode || 'not listed') }}</p>
                                    </div>
                                    <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                                        <p class="text-xs font-semibold text-slate-500">Available slots</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ application.scholarship?.slots_available || 'Not listed' }}</p>
                                    </div>
                                </div>
                                <div
                                    v-if="application.scholarship?.contact_email || application.scholarship?.contact_number"
                                    class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-4 text-sm"
                                >
                                    <span class="font-semibold text-slate-500">Provider contact:</span>
                                    <a
                                        v-if="application.scholarship.contact_email"
                                        :href="`mailto:${application.scholarship.contact_email}`"
                                        class="font-bold text-slate-800 hover:text-amber-700"
                                    >
                                        {{ application.scholarship.contact_email }}
                                    </a>
                                    <a
                                        v-if="application.scholarship.contact_number"
                                        :href="`tel:${application.scholarship.contact_number}`"
                                        class="font-bold text-slate-800 hover:text-amber-700"
                                    >
                                        {{ application.scholarship.contact_number }}
                                    </a>
                                </div>
                            </section>

                            <section v-if="activeSection === 'program'" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Match guidance</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            Why this program may fit you
                                        </h3>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', recommendationClass(application.dss_recommendation)]">
                                        {{ application.dss_score ?? 0 }}% - {{ application.dss_breakdown?.label || labelFromKey(application.dss_recommendation || 'needs_review') }}
                                    </span>
                                </div>
                                <p class="mt-3 line-clamp-2 text-sm font-semibold leading-6 text-slate-800">
                                    {{ application.dss_explanation?.headline || application.dss_breakdown?.summary || 'DSS reviewed the current application data.' }}
                                </p>
                                <p class="mt-1 line-clamp-2 text-sm leading-6 text-slate-600">
                                    {{ application.dss_explanation?.next_action || 'Use this guidance together with the program requirements when reviewing your application.' }}
                                </p>

                                <div
                                    v-if="application.dss_explanation?.strengths?.length || application.dss_explanation?.needs_attention?.length"
                                    class="mt-4 grid gap-3 md:grid-cols-2"
                                >
                                    <div v-if="application.dss_explanation?.strengths?.length" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                            Strengths
                                        </p>
                                        <ul class="mt-2 space-y-1">
                                            <li
                                                v-for="item in application.dss_explanation.strengths"
                                                :key="item"
                                                class="line-clamp-2 text-sm leading-6 text-slate-600"
                                            >
                                                {{ item }}
                                            </li>
                                        </ul>
                                    </div>
                                    <div v-if="application.dss_explanation?.needs_attention?.length" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                            Needs attention
                                        </p>
                                        <ul class="mt-2 space-y-1">
                                            <li
                                                v-for="item in application.dss_explanation.needs_attention"
                                                :key="item"
                                                class="line-clamp-2 text-sm leading-6 text-slate-600"
                                            >
                                                {{ item }}
                                            </li>
                                        </ul>
                                    </div>
                                </div>

                                <details v-if="dssCriteria.length" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <summary class="cursor-pointer text-sm font-bold text-slate-800">
                                        Suitability breakdown
                                    </summary>
                                    <p class="mt-2 text-xs leading-5 text-slate-500">
                                        Only these criteria are weighted in the suitability score.
                                    </p>
                                    <div class="mt-3 grid gap-2">
                                        <div
                                            v-for="criterion in dssCriteria"
                                            :key="criterion.key"
                                            class="rounded-md border border-slate-200 bg-white p-3 text-sm"
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="font-bold text-slate-950">{{ criterion.label }}</p>
                                                <span class="text-xs font-bold text-slate-600">{{ criterionImpact(criterion) }}</span>
                                            </div>
                                            <p class="mt-1 text-xs font-bold uppercase tracking-[0.1em] text-slate-400">
                                                {{ criterion.score }}% score x {{ criterion.weight }}% weight
                                            </p>
                                            <p class="mt-1 line-clamp-2 leading-6 text-slate-600">
                                                {{ criterion.note }}
                                            </p>
                                        </div>
                                    </div>
                                </details>

                                <p class="mt-3 text-xs font-semibold leading-5 text-slate-500">
                                    {{ dssDecisionNotice }}
                                </p>
                            </section>

                            <section v-if="activeSection === 'files'" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Documents</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">
                                            Application files
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Upload each file beside the requirement it belongs to.
                                        </p>
                                    </div>
                                    <span
                                        :class="[
                                            'rounded-md px-2.5 py-1 text-xs font-bold',
                                            filesNeedingAction.length ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800',
                                        ]"
                                    >
                                        {{ filesNeedingAction.length ? `${filesNeedingAction.length} need attention` : 'Files ready' }}
                                    </span>
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
                                                    row.document ? 'bg-slate-100 text-slate-700' : 'bg-amber-50 text-amber-700',
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
                                                        Additional file
                                                    </span>
                                                </div>
                                                <p v-if="row.document" class="mt-1 truncate text-xs text-slate-500">
                                                    {{ row.document.original_name }} - {{ formatFileSize(row.document.size) }} - {{ row.document.uploaded_at }}
                                                </p>
                                                <p v-else class="mt-1 text-xs font-semibold text-amber-700">
                                                    No file uploaded yet
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
                                                    row.document ? documentStatusClass(row.document.status) : 'bg-amber-50 text-amber-700',
                                                ]"
                                            >
                                                {{ row.document ? labelFromKey(row.document.status || 'pending') : 'Not uploaded' }}
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
                            </section>

                            <section v-if="activeSection === 'history' && timeline.length" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Timeline</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    Application history
                                </h3>
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
                        </div>

                        <aside v-if="activeSection !== 'files'" class="space-y-4">
                            <section v-if="activeSection === 'history'" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Application</p>
                                <div class="mt-3 grid gap-3 text-sm">
                                    <div>
                                        <p class="font-semibold text-slate-500">Applicant</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ user?.name || 'Applicant' }}</p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">Submitted</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ application.submitted_at || 'Recently' }}</p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">Application mode</p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ labelFromKey(application.scholarship?.application_mode || 'not listed') }}
                                        </p>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-slate-500">Record number</p>
                                        <p class="mt-1 font-bold text-slate-950">#{{ application.id }}</p>
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'program'" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Location</p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">
                                    {{ application.scholarship?.location_name || 'Location not named' }}
                                </h3>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    {{ application.scholarship?.location_address || application.scholarship?.eligible_locations || 'No map address added yet.' }}
                                </p>
                                <p v-if="application.scholarship?.distance_label" class="mt-2 text-xs font-bold text-slate-700">
                                    About {{ application.scholarship.distance_label }} from your saved location.
                                </p>

                                <button
                                    v-if="hasMapPreview"
                                    type="button"
                                    class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                    @click="showMapModal = true"
                                >
                                    <i class="fa-solid fa-map-location-dot"></i>
                                    Preview map
                                </button>
                            </section>

                            <section v-if="activeSection === 'program'" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Eligibility</p>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', matchClass(application.eligibility_score)]">
                                        {{ application.eligibility_score ?? 0 }}% match
                                    </span>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                        {{ application.eligibility_breakdown?.label || 'Needs review' }}
                                    </span>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-slate-600">
                                    {{ application.eligibility_breakdown?.summary || 'Eligibility was checked against the scholarship profile rules.' }}
                                </p>
                                <div v-if="application.eligibility_breakdown?.criteria?.length" class="mt-3 grid gap-2">
                                    <div
                                        v-for="criterion in application.eligibility_breakdown.criteria"
                                        :key="criterion.key"
                                        :class="['rounded-md border px-3 py-2 text-xs font-bold', criterionClass(criterion.status)]"
                                    >
                                        {{ criterion.label }}: {{ labelFromKey(criterion.status) }}
                                    </div>
                                </div>
                            </section>

                            <section v-if="activeSection === 'overview'" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <p class="student-kicker">Provider update</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    {{ application.review_notes || application.decision_reason || application.outcome_notes ? 'Review feedback' : 'No new message' }}
                                </h3>

                                <div v-if="application.review_notes" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm">
                                    <p class="font-semibold text-amber-900">Message from the provider</p>
                                    <p class="mt-1 leading-6 text-slate-700">{{ application.review_notes }}</p>
                                </div>
                                <p v-else class="mt-2 text-sm leading-6 text-slate-600">
                                    The provider has not added a review message. Important changes will also appear in your notifications.
                                </p>

                                <div v-if="application.outcome_notes" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm">
                                    <p class="font-semibold text-slate-800">Outcome details</p>
                                    <p class="mt-1 leading-6 text-slate-600">{{ application.outcome_notes }}</p>
                                </div>

                                <div v-if="application.decision_reason" class="mt-3 border-t border-slate-200 pt-3 text-sm">
                                    <p class="font-semibold text-slate-500">Decision reason</p>
                                    <p class="mt-1 font-bold text-slate-950">{{ labelFromKey(application.decision_reason) }}</p>
                                </div>

                                <details v-if="application.notes" class="mt-3 border-t border-slate-200 pt-3">
                                    <summary class="cursor-pointer text-sm font-bold text-slate-700">Your submitted note</summary>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ application.notes }}</p>
                                </details>
                            </section>

                            <section
                                v-if="activeSection === 'overview' && !schedules.some((schedule) => schedule.type === 'distribution') && (application.awarded_amount || application.distribution_scheduled_for || application.distribution_instructions || ['approved', 'awarded', 'distribution_scheduled', 'disbursed', 'renewed'].includes(application.status))"
                                class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
                            >
                                <p class="student-kicker">Reward Distribution</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    {{ application.status === 'disbursed' ? 'Reward distributed' : 'Provider-managed schedule' }}
                                </h3>
                                <div class="mt-3 grid gap-2 text-sm">
                                    <p class="rounded-md bg-slate-50 px-3 py-2 font-bold text-slate-700 ring-1 ring-slate-200">
                                        Amount: {{ application.awarded_amount || 'Not listed' }}
                                    </p>
                                    <p class="rounded-md bg-slate-50 px-3 py-2 font-bold text-slate-700 ring-1 ring-slate-200">
                                        Scheduled date: {{ application.distribution_scheduled_for || 'Provider will set this later' }}
                                    </p>
                                    <p class="rounded-md bg-slate-50 px-3 py-2 font-bold text-slate-700 ring-1 ring-slate-200">
                                        Status: {{ statusLabel(application.status) }}
                                    </p>
                                </div>
                                <p v-if="application.distribution_instructions" class="mt-3 whitespace-pre-line rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                                    {{ application.distribution_instructions }}
                                </p>
                                <p v-else class="mt-3 text-sm leading-6 text-slate-500">
                                    The provider will add release instructions when the schedule is ready. No in-platform acceptance is required.
                                </p>
                            </section>
                        </aside>
                    </div>
                </div>

                <ApplicantFooter />
            </div>
        </section>

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
