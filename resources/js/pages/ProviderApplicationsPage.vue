<script setup>
import { computed, onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { decisionReasonOptions } from '../support/applicationDecisionReasons';

const appElement = document.getElementById('app');
const initialScholarshipId = appElement?.dataset.scholarshipId ?? new URLSearchParams(window.location.search).get('scholarship_id') ?? '';
const initialScholarshipTitle = appElement?.dataset.scholarshipTitle ?? '';
const isLoading = ref(true);
const updatingId = ref(null);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const applications = ref([]);
const selectedScholarshipContext = ref(initialScholarshipId ? {
    id: Number(initialScholarshipId),
    title: initialScholarshipTitle,
} : null);
const reviewNotes = ref({});
const decisionReasons = ref({});
const awardedAmounts = ref({});
const outcomeNotes = ref({});
const outcomeDates = ref({});
const documentStatuses = ref({});
const documentNotes = ref({});
const documentUpdatingId = ref(null);
const selectedQueueFilter = ref('all');
const selectedQueueSort = ref('priority');
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const selectedScholarshipId = computed(() => selectedScholarshipContext.value?.id || initialScholarshipId);
const hasProgramContext = computed(() => Boolean(selectedScholarshipId.value));
const exportApplicationsUrl = computed(() => {
    if (!hasProgramContext.value) {
        return '/provider/export/applications';
    }

    return `/provider/export/applications?scholarship_id=${encodeURIComponent(selectedScholarshipId.value)}`;
});
const pageKicker = computed(() => (hasProgramContext.value ? 'Program Applicants' : 'Application Review'));
const pageTitle = computed(() => (hasProgramContext.value
    ? `Applicants for ${selectedScholarshipContext.value?.title || 'this program'}`
    : 'Applicant activity queue'));
const pageDescription = computed(() => (hasProgramContext.value
    ? 'Review only the applicants who submitted for this scholarship program.'
    : 'Review submitted applications, document status, and DSS guidance for your programs.'));
const reviewFilterOptions = computed(() => [
    { value: 'all', label: 'All', count: applications.value.length },
    {
        value: 'pending_review',
        label: 'Pending review',
        count: applications.value.filter((application) => ['submitted', 'under_review'].includes(application.status ?? 'submitted')).length,
    },
    {
        value: 'document_issues',
        label: 'Document issues',
        count: applications.value.filter((application) => documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100).length,
    },
    {
        value: 'strong_candidates',
        label: 'Strong candidates',
        count: applications.value.filter((application) => Number(application.dss_score ?? 0) >= 80 || Number(application.eligibility_score ?? 0) >= 80).length,
    },
]);
const rankedApplications = computed(() => {
    const filteredApplications = applications.value.filter((application) => {
        if (selectedQueueFilter.value === 'pending_review') {
            return ['submitted', 'under_review'].includes(application.status ?? 'submitted');
        }

        if (selectedQueueFilter.value === 'document_issues') {
            return documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100;
        }

        if (selectedQueueFilter.value === 'strong_candidates') {
            return Number(application.dss_score ?? 0) >= 80 || Number(application.eligibility_score ?? 0) >= 80;
        }

        return true;
    });

    return [...filteredApplications].sort((first, second) => {
        if (selectedQueueSort.value === 'dss') {
            return Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
        }

        if (selectedQueueSort.value === 'documents') {
            return documentIssueCount(second) - documentIssueCount(first);
        }

        return reviewPriorityScore(second) - reviewPriorityScore(first) || Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
    });
});
const statusOptions = [
    { value: 'submitted', label: 'Submitted' },
    { value: 'under_review', label: 'Under review' },
    { value: 'qualified', label: 'Qualified' },
    { value: 'exam_qualified', label: 'Qualified for exam' },
    { value: 'exam_scheduled', label: 'Exam scheduled' },
    { value: 'exam_taken', label: 'Exam taken' },
    { value: 'exam_passed', label: 'Passed exam' },
    { value: 'exam_failed', label: 'Failed exam' },
    { value: 'shortlisted', label: 'Shortlisted' },
    { value: 'interview', label: 'For interview' },
    { value: 'approved', label: 'Approved' },
    { value: 'awarded', label: 'Awarded' },
    { value: 'distribution_scheduled', label: 'Distribution scheduled' },
    { value: 'not_awarded', label: 'Not awarded' },
    { value: 'disbursed', label: 'Distributed' },
    { value: 'renewed', label: 'Renewed' },
    { value: 'rejected', label: 'Rejected' },
];
const documentStatusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'needs_replacement', label: 'Needs replacement' },
    { value: 'rejected', label: 'Rejected' },
];
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

function applicantAcademicLabel(applicant) {
    if (!applicant?.gwa) {
        return 'No academic value';
    }

    return applicant.grading_scale === 'grade_point'
        ? `${applicant.gwa} GWA/GPA`
        : `${applicant.gwa}%`;
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

function documentIssueCount(application) {
    return (application.documents ?? []).filter((document) => ['pending', 'needs_replacement', 'rejected'].includes(document.status ?? 'pending')).length;
}

function reviewPriorityScore(application) {
    const status = application.status ?? 'submitted';
    const readiness = Number(application.document_readiness?.percent ?? 0);
    const dssScore = Number(application.dss_score ?? 0);
    const eligibilityScore = Number(application.eligibility_score ?? 0);
    const issues = documentIssueCount(application);
    let score = 0;

    if (status === 'submitted') {
        score += 24;
    }

    if (status === 'under_review') {
        score += 16;
    }

    if (['exam_qualified', 'exam_scheduled', 'exam_taken'].includes(status)) {
        score += 14;
    }

    if (status === 'exam_passed') {
        score += 10;
    }

    if (issues > 0) {
        score += Math.min(35, issues * 12);
    }

    if (readiness < 100) {
        score += readiness === 0 ? 22 : 14;
    }

    if (dssScore >= 80 || eligibilityScore >= 80) {
        score += 12;
    }

    if (application.dss_recommendation === 'needs_review') {
        score += 20;
    }

    if (application.dss_recommendation === 'not_recommended') {
        score += 10;
    }

    if (['approved', 'awarded'].includes(status) && !application.distribution_scheduled_for) {
        score += 18;
    }

    if (!application.review_notes && ['submitted', 'under_review'].includes(status)) {
        score += 5;
    }

    if (['not_awarded', 'disbursed', 'renewed', 'rejected', 'exam_failed'].includes(status)) {
        score -= 25;
    }

    return Math.max(0, score);
}

function reviewPriorityLabel(application) {
    const score = reviewPriorityScore(application);

    if (score >= 60) {
        return 'High priority';
    }

    if (score >= 35) {
        return 'Needs review';
    }

    return 'Routine';
}

function reviewPriorityClass(application) {
    const score = reviewPriorityScore(application);

    if (score >= 60) {
        return 'bg-rose-100 text-rose-800';
    }

    if (score >= 35) {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-200 text-slate-700';
}

function reviewReasons(application) {
    const reasons = [];
    const readiness = Number(application.document_readiness?.percent ?? 0);
    const issues = documentIssueCount(application);
    const dssScore = Number(application.dss_score ?? 0);
    const eligibilityScore = Number(application.eligibility_score ?? 0);

    if (['submitted', 'under_review'].includes(application.status ?? 'submitted')) {
        reasons.push('Awaiting provider decision');
    }

    const examReason = {
        exam_qualified: 'Ready for exam schedule',
        exam_scheduled: 'Exam scheduled',
        exam_taken: 'Awaiting exam result',
        exam_passed: 'Ready for final award review',
        exam_failed: 'Closed after exam result',
    }[application.status ?? 'submitted'];

    if (examReason) {
        reasons.push(examReason);
    }

    if (issues > 0) {
        reasons.push(`${issues} document ${issues === 1 ? 'issue' : 'issues'}`);
    } else if (readiness < 100) {
        reasons.push(`${readiness}% document readiness`);
    }

    if (dssScore >= 80 || eligibilityScore >= 80) {
        reasons.push('Strong DSS or match signal');
    }

    if (application.dss_recommendation === 'needs_review') {
        reasons.push('DSS asks for manual review');
    }

    if (['approved', 'awarded'].includes(application.status) && !application.distribution_scheduled_for) {
        reasons.push('Reward schedule needed');
    } else if (application.distribution_scheduled_label) {
        reasons.push(`Distribution ${application.distribution_scheduled_label}`);
    }

    if (reasons.length === 0) {
        reasons.push('No urgent review flags');
    }

    return reasons.slice(0, 3);
}

function quickActionNote(application, action) {
    if (action === 'under_review') {
        return 'Application moved to provider review.';
    }

    if (action === 'missing_documents') {
        const missingDocuments = application.document_readiness?.missing ?? [];
        const missingList = missingDocuments.length ? missingDocuments.slice(0, 3).join(', ') : 'remaining listed documents';

        return `Please upload or replace ${missingList}.`;
    }

    if (action === 'shortlisted') {
        return 'Applicant shortlisted for the next review step.';
    }

    if (action === 'interview') {
        return 'Applicant selected for interview or follow-up screening.';
    }

    if (action === 'exam_qualified') {
        return 'Applicant passed eligibility screening and is qualified to take the scholarship exam.';
    }

    if (action === 'exam_scheduled') {
        return 'Scholarship exam is scheduled. Check provider instructions for date, venue, or online exam details.';
    }

    if (action === 'exam_taken') {
        return 'Scholarship exam was marked as taken.';
    }

    if (action === 'exam_passed') {
        return 'Applicant passed the scholarship exam and may proceed to final award review.';
    }

    if (action === 'exam_failed') {
        return 'Applicant did not pass the scholarship exam.';
    }

    if (action === 'approved') {
        return 'Application approved after provider review.';
    }

    if (action === 'rejected') {
        return 'Application was not selected after provider review.';
    }

    return '';
}

function statusConfirmation(application, status) {
    const applicantName = application.applicant?.name || 'This applicant';
    const negative = ['exam_failed', 'not_awarded', 'rejected'].includes(status);
    const labels = {
        exam_qualified: 'Qualify for exam',
        exam_scheduled: 'Schedule exam',
        exam_passed: 'Record exam pass',
        exam_failed: 'Record exam failure',
        approved: 'Approve application',
        awarded: 'Record award',
        distribution_scheduled: 'Schedule distribution',
        disbursed: 'Mark distributed',
        renewed: 'Confirm renewal',
        not_awarded: 'Record not awarded',
        rejected: 'Reject application',
    };

    if (!labels[status]) {
        return null;
    }

    return {
        title: `${labels[status]}?`,
        message: `${applicantName} will receive the updated status${negative ? ', decision reason,' : ''} and provider note.`,
        confirmLabel: labels[status],
        tone: negative ? 'danger' : 'warning',
    };
}

async function applyQuickAction(application, action) {
    const map = {
        under_review: { status: 'under_review', reason: '' },
        missing_documents: { status: 'under_review', reason: 'missing_documents' },
        shortlisted: { status: 'shortlisted', reason: 'complete_requirements' },
        interview: { status: 'interview', reason: 'for_interview' },
        exam_qualified: { status: 'exam_qualified', reason: 'for_exam' },
        exam_scheduled: { status: 'exam_scheduled', reason: 'exam_scheduled' },
        exam_taken: { status: 'exam_taken', reason: 'exam_completed' },
        exam_passed: { status: 'exam_passed', reason: 'passed_exam' },
        exam_failed: { status: 'exam_failed', reason: 'failed_exam' },
        approved: { status: 'approved', reason: 'approved_for_award' },
        rejected: { status: 'rejected', reason: 'not_selected' },
    };
    const selected = map[action];

    if (!selected) {
        return;
    }

    decisionReasons.value[application.id] = selected.reason;
    reviewNotes.value[application.id] = quickActionNote(application, action);
    await updateStatus(application, selected.status);
}

function labelFromKey(value) {
    if (customStatusLabels[value]) {
        return customStatusLabels[value];
    }

    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatFileSize(size) {
    if (!size) {
        return '0 KB';
    }

    return `${Math.max(1, Math.round(Number(size) / 1024))} KB`;
}

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/applications/data', {
            params: hasProgramContext.value ? { scholarship_id: selectedScholarshipId.value } : {},
        });

        user.value = response.data.user;
        scholarships.value = response.data.scholarships;
        applications.value = response.data.applications;
        selectedScholarshipContext.value = response.data.selected_scholarship ?? selectedScholarshipContext.value;
        reviewNotes.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.review_notes ?? '']),
        );
        decisionReasons.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.decision_reason ?? '']),
        );
        awardedAmounts.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.awarded_amount ?? '']),
        );
        outcomeNotes.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.outcome_notes ?? '']),
        );
        outcomeDates.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.outcome_at ?? '']),
        );
        documentStatuses.value = Object.fromEntries(
            applications.value.flatMap((application) => (application.documents ?? []).map((document) => [document.id, document.status ?? 'pending'])),
        );
        documentNotes.value = Object.fromEntries(
            applications.value.flatMap((application) => (application.documents ?? []).map((document) => [document.id, document.review_notes ?? ''])),
        );

    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider applications.';
    } finally {
        isLoading.value = false;
    }
}

async function updateStatus(application, status) {
    if (status !== application.status) {
        const confirmationOptions = statusConfirmation(application, status);

        if (confirmationOptions && !await requestConfirmation(confirmationOptions)) {
            return;
        }
    }

    updatingId.value = application.id;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/applications/${application.id}/status`, {
            status,
            decision_reason: decisionReasons.value[application.id] ?? '',
            review_notes: reviewNotes.value[application.id] ?? '',
            awarded_amount: awardedAmounts.value[application.id] ?? '',
            outcome_notes: outcomeNotes.value[application.id] ?? '',
            outcome_at: outcomeDates.value[application.id] ?? '',
        });

        statusMessage.value = response.data.message ?? 'Application status updated.';
        await loadProviderData();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update application status.';
    } finally {
        updatingId.value = null;
    }
}

async function updateDocumentStatus(application, document) {
    const nextStatus = documentStatuses.value[document.id] ?? 'pending';

    if (nextStatus !== document.status && ['rejected', 'needs_replacement'].includes(nextStatus)) {
        const confirmed = await requestConfirmation({
            title: nextStatus === 'rejected' ? 'Reject this document?' : 'Request a replacement?',
            message: `${application.applicant?.name || 'The applicant'} will see this document decision and provider note.`,
            confirmLabel: nextStatus === 'rejected' ? 'Reject document' : 'Request replacement',
            tone: nextStatus === 'rejected' ? 'danger' : 'warning',
        });

        if (!confirmed) {
            return;
        }
    }

    documentUpdatingId.value = document.id;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/documents/${document.id}/status`, {
            status: documentStatuses.value[document.id] ?? 'pending',
            review_notes: documentNotes.value[document.id] ?? '',
        });

        applications.value = applications.value.map((item) => (item.id === application.id ? response.data.application : item));
        statusMessage.value = response.data.message ?? 'Document status updated.';
        await loadProviderData();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update document status.';
    } finally {
        documentUpdatingId.value = null;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                {{ pageKicker }}
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ pageTitle }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                {{ pageDescription }}
                            </p>
                        </div>
                        <div v-if="hasProgramContext" class="flex flex-wrap gap-2">
                            <a
                                href="/provider/applications"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                All applications
                            </a>
                            <a
                                href="/provider/programs"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Programs
                            </a>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading application review page...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </div>

                    <details class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm shadow-sm">
                        <summary class="cursor-pointer font-bold text-slate-950">
                            DSS queue guide
                        </summary>
                        <p class="mt-2 leading-5 text-slate-600">
                            DSS helps rank applications, but the provider still makes the final decision.
                        </p>
                    </details>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Review Queue
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            {{ hasProgramContext ? 'Submitted applicants' : 'Submitted applications' }}
                        </h3>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <a
                                :href="exportApplicationsUrl"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Export CSV
                            </a>
                        </div>

                        <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="filter in reviewFilterOptions"
                                        :key="filter.value"
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueFilter === filter.value
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueFilter = filter.value"
                                    >
                                        {{ filter.label }} ({{ filter.count }})
                                    </button>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'priority'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'priority'"
                                    >
                                        Priority
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'dss'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'dss'"
                                    >
                                        DSS
                                    </button>
                                    <button
                                        type="button"
                                        :class="[
                                            'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                            selectedQueueSort === 'documents'
                                                ? 'border-slate-900 bg-slate-900 text-white'
                                                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-100'
                                        ]"
                                        @click="selectedQueueSort = 'documents'"
                                    >
                                        Documents
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No applications to review yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                {{ hasProgramContext
                                    ? 'Applicants for this program will appear here after eligible students submit the application wizard.'
                                    : 'Applications will appear after an approved scholarship is published and an eligible applicant submits the application wizard.' }}
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="/provider/programs" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800">Check programs</a>
                                <a href="/provider/programs/create" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100">Create scholarship</a>
                            </div>
                        </div>

                        <div v-else-if="rankedApplications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applications match this review filter.
                        </div>

                        <div v-else class="mt-5 grid gap-4 xl:grid-cols-2">
                            <article
                                v-for="application in rankedApplications"
                                :key="application.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                                            {{ application.scholarship?.title || 'Scholarship' }}
                                        </p>
                                        <h4 class="mt-2 text-lg font-bold text-slate-950">
                                            {{ application.applicant?.name || 'Applicant' }}
                                        </h4>
                                        <p class="mt-1 text-sm text-slate-500">
                                            Submitted {{ application.submitted_at || 'recently' }}
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', reviewPriorityClass(application)]">
                                            {{ reviewPriorityLabel(application) }}
                                        </span>
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                            {{ statusLabel(application.status) }}
                                        </span>
                                        <span
                                            v-if="application.distribution_scheduled_label"
                                            class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase text-slate-700"
                                        >
                                            {{ application.distribution_scheduled_label }}
                                        </span>
                                        <a
                                            :href="application.detail_url || `/provider/applications/${application.id}`"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        >
                                            View details
                                        </a>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span
                                        v-for="reason in reviewReasons(application)"
                                        :key="reason"
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200"
                                    >
                                        {{ reason }}
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <p class="font-semibold text-slate-500">
                                            Suitability
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.dss_score ?? 0 }}%
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <p class="font-semibold text-slate-500">
                                            Eligibility match
                                        </p>
                                        <p :class="['mt-1 inline-flex rounded-md px-2 py-1 text-xs font-bold', matchClass(application.eligibility_score)]">
                                            {{ application.eligibility_score ?? 0 }}%
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <p class="font-semibold text-slate-500">
                                            Documents
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.document_readiness?.percent ?? 0 }}% ready
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                                        <p class="font-semibold text-slate-500">
                                            Review stage
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.status_progress?.label || statusLabel(application.status) }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-md border border-slate-200 bg-white p-3">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                        Quick review actions
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'under_review')"
                                        >
                                            Start review
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'missing_documents')"
                                        >
                                            Request documents
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'exam_qualified')"
                                        >
                                            Qualify for exam
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'exam_scheduled')"
                                        >
                                            Schedule exam
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'exam_taken')"
                                        >
                                            Exam taken
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'exam_passed')"
                                        >
                                            Passed exam
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'exam_failed')"
                                        >
                                            Failed exam
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'shortlisted')"
                                        >
                                            Shortlist
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'interview')"
                                        >
                                            Interview
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'approved')"
                                        >
                                            Approve
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                                            @click="applyQuickAction(application, 'rejected')"
                                        >
                                            Reject
                                        </button>
                                    </div>
                                </div>

                            </article>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
