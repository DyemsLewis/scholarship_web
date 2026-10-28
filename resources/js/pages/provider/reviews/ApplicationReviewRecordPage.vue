<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import DecisionReopenDialog from '../../../components/DecisionReopenDialog.vue';
import ApplicantProfileRecord from '../../../components/profile/ApplicantProfileRecord.vue';
import { decisionReasonOptions } from '../../../support/applicationDecisionReasons';
import EligibilityReviewPanel from './components/EligibilityReviewPanel.vue';

const applicationId = document.getElementById('app')?.dataset.applicationId;
const returnTo = new URLSearchParams(window.location.search).get('return_to');
const safeReturnUrl = returnTo?.startsWith('/provider/workspaces/reviews')
    ? returnTo
    : '/provider/workspaces/reviews/assigned';
const sections = [
    { key: 'eligibility', label: 'Eligibility', icon: 'fa-solid fa-scale-balanced' },
    { key: 'documents', label: 'Evidence', icon: 'fa-solid fa-file-circle-check' },
    { key: 'applicant', label: 'Profile', icon: 'fa-solid fa-user' },
    { key: 'decision', label: 'Result', icon: 'fa-solid fa-gavel' },
    { key: 'history', label: 'History', icon: 'fa-solid fa-clock-rotate-left' },
];
const requestedSection = new URLSearchParams(window.location.search).get('section');
const activeSection = ref(sections.some((section) => section.key === requestedSection) ? requestedSection : 'eligibility');
const application = ref(null);
const workspace = ref(null);
const isLoading = ref(true);
const actionBusy = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const showReopenDialog = ref(false);
const selectedDocument = ref(null);
const documentForm = ref({ status: 'accepted', review_notes: '' });
const showCorrectionForm = ref(false);
const correctionForm = ref({ message: '', targets: [] });
const showPhotoReplacementForm = ref(false);
const photoReason = ref('');
const academicForm = ref({ academic_grading_scale: '', academic_result: '' });
const decisionForm = ref({ decision: 'approve', decision_reason: '', review_notes: '' });
const rubricScores = ref({});

const applicant = computed(() => application.value?.applicant ?? {});
const scholarship = computed(() => application.value?.scholarship ?? {});
const workflow = computed(() => application.value?.workflow ?? {});
const decisionReopen = computed(() => application.value?.decision_reopen ?? {});
const readiness = computed(() => application.value?.document_readiness ?? {});
const rubricCriteria = computed(() => application.value?.rubric_review?.criteria ?? []);
const currentUserId = computed(() => Number(window.portalUser?.id));
const assignedReviewerId = computed(() => Number(application.value?.assigned_reviewer?.id || 0));
const isAssignedToMe = computed(() => assignedReviewerId.value === currentUserId.value);
const isUnassigned = computed(() => !assignedReviewerId.value);
const isScreening = computed(() => application.value?.workflow_stage === 'screening');
const canEditReview = computed(() => isScreening.value && isAssignedToMe.value);
const assignmentLabel = computed(() => {
    if (isAssignedToMe.value) return 'Assigned to you';
    if (isUnassigned.value) return 'Unassigned';
    return `Assigned to ${application.value?.assigned_reviewer?.name || 'another reviewer'}`;
});
const acceptedDocuments = computed(() => application.value?.documents?.filter((document) => document.status === 'accepted').length ?? 0);
const requiredDocuments = computed(() => readiness.value.required ?? application.value?.document_checklist?.length ?? 0);
const applicantProfileRecord = computed(() => ({
    name: applicant.value.name || 'Applicant record',
    initials: String(applicant.value.name || 'AP')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join(''),
    photoUrl: applicant.value.profile_photo_url,
    educationSummary: [applicant.value.education_level, applicant.value.course_or_strand, applicant.value.year_level]
        .filter(Boolean)
        .join(' - '),
}));
const applicantProfileSections = computed(() => [
    {
        id: 'personal',
        title: 'Personal and contact',
        icon: 'fa-solid fa-address-card',
        description: 'Identity and contact details submitted for this applicant record.',
        items: [
            { label: 'Age', value: applicant.value.age ? `${applicant.value.age} years old` : null },
            { label: 'Gender', value: applicant.value.gender ? labelize(applicant.value.gender) : null },
            { label: 'Contact number', value: applicant.value.contact_number },
            { label: 'Email address', value: applicant.value.email },
            { label: 'Account managed by', value: applicant.value.account_managed_by ? labelize(applicant.value.account_managed_by) : null },
            { label: 'Citizenship', value: applicant.value.citizenship_status ? labelize(applicant.value.citizenship_status) : null },
        ],
    },
    {
        id: 'academic',
        title: 'Learning record',
        icon: 'fa-solid fa-graduation-cap',
        description: 'Academic information used for eligibility and evidence review.',
        items: [
            { label: 'School', value: applicant.value.school },
            { label: 'Education', value: [applicant.value.education_level, applicant.value.course_or_strand, applicant.value.year_level].filter(Boolean).join(' - ') },
            { label: 'Academic result', value: applicant.value.gwa ? `${applicant.value.gwa} - ${labelize(applicant.value.grading_scale)}` : null },
            { label: 'Enrollment', value: applicant.value.enrollment_status },
            { label: 'Academic year', value: applicant.value.academic_year },
            { label: 'Record period', value: applicant.value.academic_term ? labelize(applicant.value.academic_term) : null },
        ],
    },
    {
        id: 'household',
        title: 'Household and guardian',
        icon: 'fa-solid fa-house-chimney-user',
        description: 'Household information relevant to financial and guardian requirements.',
        items: [
            { label: 'Income bracket', value: applicant.value.income_bracket },
            { label: 'Household size', value: applicant.value.household_size ? `${applicant.value.household_size} members` : null },
            { label: 'Current scholarship', value: applicant.value.current_scholarship_status ? labelize(applicant.value.current_scholarship_status) : null },
            { label: 'Parent or guardian', value: applicant.value.guardian_name },
        ],
    },
    {
        id: 'location',
        title: 'Location',
        icon: 'fa-solid fa-location-dot',
        description: 'General residence used for location-based program rules.',
        items: [
            { label: 'Home location', value: applicant.value.location, wide: true },
            { label: 'Street / home address', value: applicant.value.address, wide: true },
        ],
    },
]);
const rejectionReasons = decisionReasonOptions.filter((option) => [
    'missing_documents',
    'academic_requirement_not_met',
    'outside_eligibility',
    'procedure_not_followed',
    'other',
].includes(option.value));

function labelize(value) {
    const evidenceLabels = { identity_record: 'Identity and birth record', income_record: 'Household income proof', residency_record: 'Residence proof', guardian_record: 'Parent or guardian ID' };
    if (evidenceLabels[value]) return evidenceLabels[value];

    if (value === 'no_current_scholarship_proof') {
        return 'Proof of no current scholarship';
    }

    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not provided';
}

function statusClass(status) {
    return {
        pass: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        accepted: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        approved: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        fail: 'border-rose-200 bg-rose-50 text-rose-800',
        rejected: 'border-rose-200 bg-rose-50 text-rose-800',
        needs_replacement: 'border-amber-200 bg-amber-50 text-amber-800',
        missing: 'border-amber-200 bg-amber-50 text-amber-800',
        info: 'border-slate-200 bg-slate-100 text-slate-700',
        pending: 'border-slate-200 bg-slate-100 text-slate-700',
    }[status] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) return first;
    }
    return error.response?.data?.message || fallback;
}

function syncApplication(nextApplication) {
    application.value = nextApplication;
    const academicScale = nextApplication?.applicant?.grading_scale ?? '';
    academicForm.value = {
        academic_grading_scale: academicScale,
        academic_result: nextApplication?.applicant?.gwa ?? '',
    };
    rubricScores.value = Object.fromEntries(
        (nextApplication?.rubric_review?.criteria ?? []).map((criterion) => [criterion.key, criterion.score ?? '']),
    );
}

async function loadApplication() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/applications/${applicationId}/data`, { params: { workspace: 'review' } });
        workspace.value = response.data.workspace;
        syncApplication(response.data.application);
    } catch (error) {
        errorMessage.value = firstError(error, 'The application record could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

async function claimReview() {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/review-claim`);
        syncApplication(response.data.application);
    } catch (error) {
        errorMessage.value = firstError(error, 'The application could not be claimed.');
    } finally {
        actionBusy.value = false;
    }
}

function openDocumentReview(document) {
    selectedDocument.value = document;
    documentForm.value = { status: document.status === 'accepted' ? 'accepted' : 'accepted', review_notes: document.review_notes ?? '' };
}

async function saveDocumentReview() {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/documents/${selectedDocument.value.id}/status`, documentForm.value);
        syncApplication(response.data.application);
        selectedDocument.value = null;
    } catch (error) {
        errorMessage.value = firstError(error, 'The evidence decision could not be saved.');
    } finally {
        actionBusy.value = false;
    }
}

async function verifyAcademicRecord() {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/profile-verification`, academicForm.value);
        syncApplication(response.data.application);
    } catch (error) {
        errorMessage.value = firstError(error, 'The academic record could not be verified.');
    } finally {
        actionBusy.value = false;
    }
}

async function reviewPhoto(action) {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/profile-photo-review`, {
            action,
            reason: action === 'request_replacement' ? photoReason.value : null,
        });
        syncApplication(response.data.application);
        showPhotoReplacementForm.value = false;
        photoReason.value = '';
    } catch (error) {
        errorMessage.value = firstError(error, 'The photo review could not be saved.');
    } finally {
        actionBusy.value = false;
    }
}

async function requestCorrection() {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/correction`, {
            action: 'request',
            message: correctionForm.value.message,
            targets: correctionForm.value.targets.length ? correctionForm.value.targets : ['other'],
        });
        syncApplication(response.data.application);
        showCorrectionForm.value = false;
        correctionForm.value = { message: '', targets: [] };
    } catch (error) {
        errorMessage.value = firstError(error, 'The correction request could not be sent.');
    } finally {
        actionBusy.value = false;
    }
}

async function resolveCorrection() {
    actionBusy.value = true;
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/correction`, { action: 'resolve' });
        syncApplication(response.data.application);
    } catch (error) {
        errorMessage.value = firstError(error, 'The correction could not be resolved.');
    } finally {
        actionBusy.value = false;
    }
}

async function recordResult() {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/decision`, {
            ...decisionForm.value,
            decision_reason: decisionForm.value.decision === 'reject' ? decisionForm.value.decision_reason : null,
            rubric_scores: rubricScores.value,
        });
        syncApplication(response.data.application);
        activeSection.value = 'history';
    } catch (error) {
        errorMessage.value = firstError(error, 'The screening result could not be recorded.');
    } finally {
        actionBusy.value = false;
    }
}

async function reopenDecision(reason) {
    actionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/decision/reopen`, { reason });
        syncApplication(response.data.application);
        showReopenDialog.value = false;
        activeSection.value = 'decision';
        successMessage.value = response.data.message;
    } catch (error) {
        errorMessage.value = firstError(error, 'The decision could not be reopened.');
    } finally {
        actionBusy.value = false;
    }
}

function updateSection(section) {
    activeSection.value = section;
    const url = new URL(window.location.href);
    url.searchParams.set('section', section);
    window.history.replaceState({}, '', url);
}

onMounted(loadApplication);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="application-review-title">
            <div class="mx-auto max-w-[94rem]">
                <a :href="safeReturnUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to review queue</a>

                <div v-if="errorMessage" class="mb-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mb-4 border-l-4 border-emerald-500 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</div>

                <div v-if="isLoading" class="border border-slate-200 bg-white px-6 py-16 text-center">
                    <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span>
                    <p class="mt-3 text-sm font-semibold text-slate-500">Loading application record...</p>
                </div>

                <template v-else-if="application">
                    <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                        <div class="flex items-center gap-5 px-7 py-5">
                            <img v-if="applicant.profile_photo_url" :src="applicant.profile_photo_url" :alt="`${applicant.name} profile`" class="h-16 w-16 shrink-0 border border-slate-200 object-cover">
                            <span v-else class="grid h-16 w-16 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-xl text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Application review</p>
                                <h1 id="application-review-title" class="mt-1 truncate font-display text-3xl font-bold tracking-tight text-slate-950">{{ applicant.name }}</h1>
                                <p class="mt-1 truncate text-sm text-slate-600">{{ scholarship.title }} · Submitted {{ application.submitted_at || 'date unavailable' }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span :class="['border px-3 py-2 text-[10px] font-bold uppercase tracking-wide', isAssignedToMe ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-100 text-slate-700']">{{ assignmentLabel }}</span>
                                <button v-if="isUnassigned && isScreening" type="button" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="actionBusy" @click="claimReview"><i :class="['fa-solid text-[10px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-user-plus']" aria-hidden="true"></i>Claim review</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-4 border-t border-slate-200 bg-slate-50">
                            <div class="border-r border-slate-200 px-5 py-3"><p class="text-[9px] font-bold uppercase tracking-[0.16em] text-slate-500">Stage</p><p class="mt-1 text-sm font-bold text-slate-900">{{ workflow.current_stage_label || labelize(application.workflow_stage) }}</p></div>
                            <div class="border-r border-slate-200 px-5 py-3"><p class="text-[9px] font-bold uppercase tracking-[0.16em] text-slate-500">Eligibility comparison</p><p class="mt-1 text-sm font-bold text-slate-900">{{ application.dss_explanation?.comparison?.label || 'Comparison unavailable' }}</p></div>
                            <div class="border-r border-slate-200 px-5 py-3"><p class="text-[9px] font-bold uppercase tracking-[0.16em] text-slate-500">Evidence</p><p class="mt-1 text-sm font-bold text-slate-900">{{ acceptedDocuments }} of {{ requiredDocuments }} accepted</p></div>
                            <div class="px-5 py-3"><p class="text-[9px] font-bold uppercase tracking-[0.16em] text-slate-500">Waiting</p><p class="mt-1 text-sm font-bold text-slate-900">{{ application.waiting_days }} day{{ application.waiting_days === 1 ? '' : 's' }}</p></div>
                        </div>
                    </header>

                    <div v-if="applicant.profile_verification_status !== 'approved'" class="mt-4 flex items-center justify-between gap-5 border border-amber-200 border-l-4 border-l-amber-500 bg-amber-50 px-5 py-4">
                        <div class="flex min-w-0 items-start gap-3">
                            <i class="fa-solid fa-shield-halved mt-0.5 text-amber-700" aria-hidden="true"></i>
                            <p class="text-sm leading-5 text-amber-950">
                                <strong>Unverified applicant profile.</strong> Treat profile details as self-declared and give them less weight until the supporting evidence has been reviewed.
                            </p>
                        </div>
                        <button type="button" class="inline-flex h-9 shrink-0 items-center gap-2 border border-amber-300 bg-white px-3 text-xs font-bold text-amber-950 hover:border-amber-500" @click="updateSection('applicant')">
                            <i class="fa-solid fa-shield-halved text-[10px]" aria-hidden="true"></i>Review profile evidence
                        </button>
                    </div>

                    <div v-if="!canEditReview && isScreening" class="mt-4 border-l-4 border-amber-400 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                        <strong>{{ isUnassigned ? 'Claim this application before reviewing it.' : 'This record belongs to another reviewer.' }}</strong>
                        <span class="ml-1">You can read the record, but review actions are unavailable.</span>
                    </div>

                    <nav class="mt-4 grid grid-cols-5 border border-slate-200 bg-white shadow-sm" aria-label="Review record sections">
                        <button v-for="section in sections" :key="section.key" type="button" :class="['flex h-14 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', activeSection === section.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']" @click="updateSection(section.key)">
                            <i :class="[section.icon, activeSection === section.key ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>{{ section.label }}
                        </button>
                    </nav>

                    <EligibilityReviewPanel
                        v-if="activeSection === 'eligibility'"
                        :breakdown="application.eligibility_breakdown ?? {}"
                        :explanation="application.dss_explanation ?? {}"
                        :score="application.dss_breakdown ?? {}"
                    />

                    <section v-else-if="activeSection === 'documents'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center justify-between border-b border-slate-200 px-6 py-5">
                            <div class="flex items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-circle-check text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Supporting evidence</h2><p class="mt-1 text-sm text-slate-500">Open each file, then save a clear review status.</p></div></div>
                            <p class="text-sm font-bold text-slate-700">{{ acceptedDocuments }} of {{ requiredDocuments }} accepted</p>
                        </header>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[62rem] table-fixed border-collapse text-left">
                                <thead class="bg-slate-50"><tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500"><th class="w-[35%] px-6 py-3">Evidence</th><th class="w-[18%] px-6 py-3">Uploaded</th><th class="w-[27%] px-6 py-3">Review</th><th class="w-[20%] px-6 py-3 text-right">Action</th></tr></thead>
                                <tbody class="divide-y divide-slate-200">
                                    <tr v-for="document in application.documents" :key="document.id">
                                        <td class="px-6 py-4"><p class="font-bold text-slate-900">{{ document.document_name }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ document.original_name }}</p></td>
                                        <td class="px-6 py-4 text-sm text-slate-600">{{ document.uploaded_at || 'Not available' }}</td>
                                        <td class="px-6 py-4"><span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(document.status)]">{{ labelize(document.status) }}</span><p v-if="document.review_notes" class="mt-1.5 truncate text-xs text-slate-500">{{ document.review_notes }}</p></td>
                                        <td class="px-6 py-4 text-right"><a :href="document.view_url" target="_blank" rel="noopener" class="mr-2 inline-flex h-9 items-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:border-slate-950">View<i class="fa-solid fa-arrow-up-right-from-square text-[9px]" aria-hidden="true"></i></a><button type="button" class="inline-flex h-9 items-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white disabled:opacity-40" :disabled="!canEditReview" @click="openDocumentReview(document)"><i class="fa-solid fa-file-pen text-[9px]" aria-hidden="true"></i>Review</button></td>
                                    </tr>
                                    <tr v-if="!application.documents?.length"><td colspan="4" class="px-6 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-circle-xmark text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No application files were submitted.</p></td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div v-else-if="activeSection === 'applicant'" class="mt-4 space-y-4">
                        <ApplicantProfileRecord
                            :record="applicantProfileRecord"
                            :sections="applicantProfileSections"
                            :verification-status="applicant.profile_verification_status"
                            :verification-label="labelize(applicant.profile_verification_status)"
                            :show-trust-notice="false"
                            show-data-source-notice
                        >
                            <template v-if="canEditReview && applicant.profile_photo_url" #actions>
                                <button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700" :disabled="actionBusy" @click="reviewPhoto('approve')"><i class="fa-solid fa-check text-[9px]" aria-hidden="true"></i>Accept photo</button>
                                <button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700" @click="showPhotoReplacementForm = true"><i class="fa-solid fa-rotate text-[9px]" aria-hidden="true"></i>Replace photo</button>
                            </template>
                        </ApplicantProfileRecord>

                        <section class="border border-slate-200 bg-white px-6 py-5 shadow-sm">
                            <div class="flex items-center justify-between"><div><h3 class="font-bold text-slate-950">Profile evidence</h3><p class="mt-1 text-sm text-slate-500">Academic record and identity evidence saved in the applicant profile.</p></div><span :class="['border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(applicant.profile_verification_status)]">{{ labelize(applicant.profile_verification_status) }}</span></div>
                            <div class="mt-4 divide-y divide-slate-200 border border-slate-200">
                                <div v-for="proof in applicant.profile_proofs" :key="proof.id" class="flex items-center gap-4 px-4 py-3"><i class="fa-regular fa-file-lines text-slate-400" aria-hidden="true"></i><div class="min-w-0 flex-1"><p class="truncate text-sm font-bold text-slate-900">{{ labelize(proof.document_type) }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ proof.original_name }} · {{ proof.uploaded_at }}</p></div><a :href="proof.view_url" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-2 border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700">Open<i class="fa-solid fa-arrow-up-right-from-square text-[9px]" aria-hidden="true"></i></a></div>
                                <div v-if="!applicant.profile_proofs?.length" class="px-4 py-8 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-circle-xmark text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No profile evidence was uploaded.</p></div>
                            </div>
                            <form v-if="canEditReview && applicant.profile_verification_status !== 'approved'" class="mt-4 flex items-end gap-3 bg-slate-50 p-4" @submit.prevent="verifyAcademicRecord">
                                <label class="w-56"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Grading scale</span><select v-model="academicForm.academic_grading_scale" class="h-10 w-full border border-slate-300 bg-white px-3 text-sm"><option value="">Use scanned result</option><option value="percentage">Percentage</option><option value="grade_point">GWA / grade point</option><option value="pass_fail">Pass / fail</option><option value="other">Other scale</option></select></label>
                                <label v-if="['percentage', 'grade_point'].includes(academicForm.academic_grading_scale)" class="w-44"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Verified result</span><input v-model="academicForm.academic_result" type="number" step="0.01" class="h-10 w-full border border-slate-300 px-3 text-sm"></label>
                                <button type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-60" :disabled="actionBusy"><i :class="['fa-solid text-[9px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-shield-circle-check']" aria-hidden="true"></i>Verify profile evidence</button>
                            </form>
                        </section>
                    </div>

                    <section v-else-if="activeSection === 'decision'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center justify-between border-b border-slate-200 px-6 py-5"><div class="flex items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Screening result</h2><p class="mt-1 text-sm text-slate-500">Score the published rubric, then record one result.</p></div></div><button v-if="canEditReview" type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 px-4 text-xs font-bold text-slate-700" @click="showCorrectionForm = true"><i class="fa-solid fa-pen-to-square text-[10px]" aria-hidden="true"></i>Request correction</button></header>
                        <div v-if="['required_mismatch', 'preferred_difference', 'manual_review', 'provisional'].includes(application.dss_explanation?.comparison?.state)" class="flex items-start justify-between gap-6 border-b border-amber-200 bg-amber-50 px-6 py-4">
                            <div><p class="text-sm font-bold text-amber-950">{{ application.dss_explanation.comparison.label }}</p><p class="mt-1 text-xs leading-5 text-amber-900">{{ application.dss_explanation.comparison.interpretation }}</p></div>
                            <button type="button" class="shrink-0 border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="updateSection('eligibility')">Review comparison</button>
                        </div>
                        <div v-if="application.correction_status" class="flex items-center justify-between border-b border-amber-200 bg-amber-50 px-6 py-4"><div><p class="text-sm font-bold text-amber-900">Correction {{ labelize(application.correction_status) }}</p><p class="mt-1 text-xs text-amber-800">{{ application.correction_message }}</p></div><button v-if="application.correction_status === 'submitted' && canEditReview" type="button" class="inline-flex h-9 items-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white" :disabled="actionBusy" @click="resolveCorrection"><i class="fa-solid fa-check text-[9px]" aria-hidden="true"></i>Accept update</button></div>
                        <form v-if="canEditReview" class="divide-y divide-slate-200" @submit.prevent="recordResult">
                            <div class="px-6 py-5"><h3 class="font-bold text-slate-950">Review rubric</h3><div class="mt-4 divide-y divide-slate-200 border border-slate-200"><label v-for="criterion in rubricCriteria" :key="criterion.key" class="grid grid-cols-[1fr_8rem] items-center gap-6 px-4 py-3"><span><span class="block text-sm font-bold text-slate-900">{{ criterion.label }} <span class="font-normal text-slate-500">({{ criterion.weight }}%)</span></span><span class="mt-1 block text-xs text-slate-500">{{ criterion.guidance }}</span></span><input v-model.number="rubricScores[criterion.key]" type="number" min="0" max="100" required class="h-10 border border-slate-300 px-3 text-sm" placeholder="0-100"></label><p v-if="!rubricCriteria.length" class="px-4 py-6 text-sm text-slate-500">This program does not use a scored rubric.</p></div></div>
                            <div class="px-6 py-5"><h3 class="font-bold text-slate-950">Decision</h3><div class="mt-4 flex gap-3"><label :class="['flex flex-1 cursor-pointer items-center gap-3 border p-4', decisionForm.decision === 'approve' ? 'border-emerald-500 bg-emerald-50' : 'border-slate-300']"><input v-model="decisionForm.decision" type="radio" value="approve"><span><span class="block text-sm font-bold text-slate-950">Pass screening</span><span class="mt-1 block text-xs text-slate-500">Move the application to the next configured stage.</span></span></label><label :class="['flex flex-1 cursor-pointer items-center gap-3 border p-4', decisionForm.decision === 'reject' ? 'border-rose-500 bg-rose-50' : 'border-slate-300']"><input v-model="decisionForm.decision" type="radio" value="reject"><span><span class="block text-sm font-bold text-slate-950">Do not pass</span><span class="mt-1 block text-xs text-slate-500">End the application at screening.</span></span></label></div><label v-if="decisionForm.decision === 'reject'" class="mt-4 block"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Reason</span><select v-model="decisionForm.decision_reason" required class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="" disabled>Select a reason</option><option v-for="reason in rejectionReasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option></select></label><label class="mt-4 block"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Review note <span class="font-normal text-slate-400">(optional)</span></span><textarea v-model="decisionForm.review_notes" rows="3" class="w-full border border-slate-300 px-3 py-2 text-sm" placeholder="Add only information needed for this decision."></textarea></label></div>
                            <footer class="flex justify-end bg-slate-50 px-6 py-4"><button type="submit" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-60" :disabled="actionBusy"><i :class="['fa-solid text-[10px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>{{ actionBusy ? 'Saving...' : 'Record screening result' }}</button></footer>
                        </form>
                        <div v-else class="px-6 py-10 text-center"><p class="font-bold text-slate-900">{{ isScreening ? 'Review action unavailable' : 'Screening review completed' }}</p><p class="mt-1 text-sm text-slate-500">{{ isScreening ? assignmentLabel : `This application is now at ${workflow.current_stage_label || labelize(application.workflow_stage)}.` }}</p><button v-if="decisionReopen.can_reopen" type="button" class="mt-5 h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950" @click="showReopenDialog = true"><i class="fa-solid fa-rotate-left mr-2" aria-hidden="true"></i>Reopen {{ decisionReopen.stage_label }}</button></div>
                    </section>

                    <section v-else class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Application history</h2><p class="mt-1 text-sm text-slate-500">Recorded changes for this application.</p></div></header>
                        <div v-if="!application.timeline?.length" class="px-6 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No application history has been recorded.</p></div>
                        <ol v-else class="divide-y divide-slate-200"><li v-for="entry in [...application.timeline].reverse()" :key="entry.id" class="grid grid-cols-[10rem_1fr_14rem] gap-5 px-6 py-4"><p class="text-xs font-semibold text-slate-500">{{ entry.changed_at }}</p><div><p class="text-sm font-bold text-slate-900">{{ labelize(entry.to_status) }}</p><p v-if="entry.review_notes" class="mt-1 text-sm text-slate-600">{{ entry.review_notes }}</p></div><p class="text-right text-xs text-slate-500">{{ entry.actor }}</p></li></ol>
                    </section>
                </template>
            </div>
        </section>

        <div v-if="selectedDocument" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6" @click.self="selectedDocument = null">
            <form class="w-full max-w-xl border border-slate-200 bg-white shadow-2xl" @submit.prevent="saveDocumentReview">
                <header class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Evidence review</p><h2 class="mt-1 text-xl font-bold text-slate-950">{{ selectedDocument.document_name }}</h2><p class="mt-1 text-sm text-slate-500">{{ selectedDocument.original_name }}</p></header>
                <div class="space-y-4 px-6 py-5"><label class="block"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Review status</span><select v-model="documentForm.status" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="accepted">Accepted</option><option value="needs_replacement">Needs replacement</option><option value="rejected">Rejected</option><option value="pending">Pending review</option></select></label><label class="block"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Note <span v-if="['rejected', 'needs_replacement'].includes(documentForm.status)">(required)</span></span><textarea v-model="documentForm.review_notes" rows="4" class="w-full border border-slate-300 px-3 py-2 text-sm" placeholder="Explain only what the applicant needs to know."></textarea></label></div>
                <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" @click="selectedDocument = null">Cancel</button><button type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-60" :disabled="actionBusy"><i :class="['fa-solid text-[9px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>Save review</button></footer>
            </form>
        </div>

        <div v-if="showCorrectionForm" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6" @click.self="showCorrectionForm = false">
            <form class="w-full max-w-xl border border-slate-200 bg-white shadow-2xl" @submit.prevent="requestCorrection"><header class="border-b border-slate-200 px-6 py-5"><h2 class="text-xl font-bold text-slate-950">Request an applicant update</h2><p class="mt-1 text-sm text-slate-500">Choose the affected record and give one clear instruction.</p></header><div class="space-y-4 px-6 py-5"><fieldset><legend class="text-xs font-semibold text-slate-700">Update needed</legend><div class="mt-2 flex flex-wrap gap-2"><label v-for="target in [['profile','Profile'],['academic_record','Academic record'],['application_files','Application files'],['other','Other']]" :key="target[0]" class="flex items-center gap-2 border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700"><input v-model="correctionForm.targets" type="checkbox" :value="target[0]">{{ target[1] }}</label></div></fieldset><label class="block"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Instruction</span><textarea v-model="correctionForm.message" rows="4" required minlength="5" class="w-full border border-slate-300 px-3 py-2 text-sm" placeholder="State what needs to be corrected or replaced."></textarea></label></div><footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" @click="showCorrectionForm = false">Cancel</button><button type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white" :disabled="actionBusy"><i :class="['fa-solid text-[9px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-paper-plane']" aria-hidden="true"></i>Send request</button></footer></form>
        </div>

        <div v-if="showPhotoReplacementForm" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6" @click.self="showPhotoReplacementForm = false"><form class="w-full max-w-lg border border-slate-200 bg-white shadow-2xl" @submit.prevent="reviewPhoto('request_replacement')"><header class="border-b border-slate-200 px-6 py-5"><h2 class="text-xl font-bold text-slate-950">Request a new applicant photo</h2><p class="mt-1 text-sm text-slate-500">Explain what makes the current photo unsuitable.</p></header><div class="px-6 py-5"><textarea v-model="photoReason" rows="4" required minlength="5" class="w-full border border-slate-300 px-3 py-2 text-sm" placeholder="Example: The photo is too dark to identify the applicant."></textarea></div><footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 border border-slate-300 px-4 text-xs font-bold text-slate-700" @click="showPhotoReplacementForm = false">Cancel</button><button type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white" :disabled="actionBusy"><i :class="['fa-solid text-[9px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-camera-rotate']" aria-hidden="true"></i>Request replacement</button></footer></form></div>

        <DecisionReopenDialog :open="showReopenDialog" :decision="decisionReopen" :busy="actionBusy" @confirm="reopenDecision" @cancel="showReopenDialog = false" />
    </main>
</template>
