<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import ApplicantReviewIdentityCard from '../components/ApplicantReviewIdentityCard.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import ReviewOfficerSidebar from '../components/ReviewOfficerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import ApplicantProfileRecord from '../components/profile/ApplicantProfileRecord.vue';
import { formatFileSize } from '../support/display';
import { showPortalToast } from '../support/portalToast';

const appElement = document.getElementById('app');
const applicantId = appElement?.dataset.applicantId;
const usesReviewOfficerWorkspace = window.location.pathname.startsWith('/admin/workspaces/reviews');
const usesPortalManagerWorkspace = window.location.pathname.startsWith('/admin/workspaces/portal');
const reviewQueueUrl = usesPortalManagerWorkspace
    ? '/admin/workspaces/portal/reviews?type=applicants'
    : (usesReviewOfficerWorkspace ? '/admin/workspaces/reviews?type=applicants' : '/admin/reviews?type=applicants');
const isLoading = ref(true);
const isSaving = ref(false);
const loadError = ref('');
const decisionError = ref('');
const photoReviewError = ref('');
const isReviewingPhoto = ref(false);
const applicant = ref(null);
const reviewNote = ref('');
const reviewedAcademicScale = ref('');
const reviewedAcademicResult = ref('');
const previewDocument = ref(null);
const academicScaleOptions = [
    { value: 'percentage', label: 'General average / percentage' },
    { value: 'grade_point', label: 'GWA / GPA grade point' },
    { value: 'pass_fail', label: 'Pass/fail or competency based' },
    { value: 'other', label: 'Other grading scale' },
];
const requestedSection = new URLSearchParams(window.location.search).get('section');
const reviewSections = [
    { key: 'profile', label: 'Applicant record', icon: 'fa-solid fa-user-graduate' },
    { key: 'oversight', label: 'Review history', icon: 'fa-solid fa-shield-halved' },
    { key: 'decision', label: 'Decision', icon: 'fa-solid fa-gavel' },
];
const activeReviewSection = ref(reviewSections.some((section) => section.key === requestedSection) ? requestedSection : 'profile');
const activeReviewSectionIndex = computed(() => reviewSections.findIndex((section) => section.key === activeReviewSection.value));
const previousReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value - 1] ?? null);
const nextReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value + 1] ?? null);
const academicRecord = computed(() => academicVerificationDocument(applicant.value));
const schoolRecord = computed(() => applicant.value?.verification_documents?.find(
    (document) => document.document_type === 'school_record',
) ?? null);
const recentSchoolId = computed(() => applicant.value?.verification_documents?.find(
    (document) => document.document_type === 'recent_school_id',
) ?? null);
const profileEvidenceDocuments = computed(() => applicant.value?.verification_documents ?? []);
const profileEvidenceReady = computed(() => (applicant.value?.profile_evidence_requirements ?? [])
    .filter((requirement) => requirement.required)
    .every((requirement) => profileEvidenceDocuments.value.some((document) => document.document_type === requirement.value))
    && Boolean(academicRecord.value) && Boolean(recentSchoolId.value));
const savedAcademicResult = computed(() => academicResultLabel(applicant.value));
const academicScanRequired = computed(() => Boolean(applicant.value?.academic_scan_required));
const academicScanReady = computed(() => !academicScanRequired.value || academicRecord.value?.ocr_status === 'succeeded');
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
const applicantProfileRecord = computed(() => ({
    name: applicant.value?.name || applicant.value?.username || 'Applicant record',
    initials: String(applicant.value?.name || applicant.value?.username || 'AP')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join(''),
    photoUrl: applicant.value?.profile_photo_url,
    educationSummary: [
        applicant.value?.education_level ? statusLabel(applicant.value.education_level) : null,
        applicant.value?.course_or_strand,
        applicant.value?.year_level,
    ].filter(Boolean).join(' - '),
}));
const applicantProfileSections = computed(() => [
    {
        id: 'personal',
        title: 'Personal and contact',
        icon: 'fa-solid fa-address-card',
        description: 'Identity and contact information available during verification.',
        items: [
            { label: 'Birthdate', value: applicant.value?.birthdate },
            { label: 'Age', value: applicant.value?.age !== null && applicant.value?.age !== undefined ? `${applicant.value.age} years old` : null },
            { label: 'Gender', value: applicant.value?.gender ? statusLabel(applicant.value.gender) : null },
            { label: 'Contact number', value: applicant.value?.contact_number },
            { label: 'Email address', value: applicant.value?.email },
            { label: 'Account managed by', value: applicant.value?.account_managed_by ? statusLabel(applicant.value.account_managed_by) : null },
            { label: 'Citizenship', value: applicant.value?.citizenship_status ? statusLabel(applicant.value.citizenship_status) : null },
        ],
    },
    {
        id: 'academic',
        title: 'Learning record',
        icon: 'fa-solid fa-graduation-cap',
        description: 'Academic information to compare with the submitted records.',
        items: [
            { label: 'Education level', value: applicant.value?.education_level ? statusLabel(applicant.value.education_level) : null },
            { label: 'Grade / year / level', value: applicant.value?.year_level },
            { label: 'Enrollment', value: applicant.value?.enrollment_status ? statusLabel(applicant.value.enrollment_status) : null },
            { label: 'Institution type', value: applicant.value?.school_type ? statusLabel(applicant.value.school_type) : null },
            { label: 'School / institution', value: applicant.value?.school, wide: true },
            { label: 'Track / strand / course', value: applicant.value?.course_or_strand, wide: true },
            { label: 'Learner / student ID', value: applicant.value?.learner_reference_number },
            { label: 'Academic year', value: applicant.value?.academic_year },
            { label: 'Record period', value: applicant.value?.academic_term ? statusLabel(applicant.value.academic_term) : null },
            { label: 'Saved result', value: savedAcademicResult.value },
        ],
    },
    {
        id: 'household',
        title: 'Household and guardian',
        icon: 'fa-solid fa-house-chimney-user',
        description: 'Self-declared household and guardian context.',
        items: [
            { label: 'Income bracket', value: applicant.value?.income_bracket },
            { label: 'Household size', value: applicant.value?.household_size ? `${applicant.value.household_size} members` : null },
            { label: 'Current scholarship', value: applicant.value?.current_scholarship_status ? statusLabel(applicant.value.current_scholarship_status) : null },
            { label: 'Guardian name', value: applicant.value?.guardian_name },
            { label: 'Relationship', value: applicant.value?.guardian_relationship ? statusLabel(applicant.value.guardian_relationship) : null },
            { label: 'Guardian contact', value: applicant.value?.guardian_contact },
            { label: 'Guardian email', value: applicant.value?.guardian_email },
        ],
    },
    {
        id: 'location',
        title: 'Location',
        icon: 'fa-solid fa-location-dot',
        description: 'Residence information retained for eligibility checks.',
        items: [
            { label: 'Region', value: applicant.value?.region },
            { label: 'Province', value: applicant.value?.province },
            { label: 'City / municipality', value: applicant.value?.city },
            { label: 'Barangay', value: applicant.value?.barangay },
            { label: 'Street / home address', value: applicant.value?.address, wide: true },
        ],
    },
]);
const reviewFocus = computed(() => {
    const status = applicantReviewStatus(applicant.value);

    if (status === 'unsubmitted') {
        return {
            eyebrow: 'Waiting on applicant',
            title: 'No academic record to verify',
            description: 'The applicant must upload an academic record before an admin can verify the saved result.',
            icon: 'fa-regular fa-clock',
            section: null,
            action: null,
        };
    }

    if (status === 'approved') {
        return {
            eyebrow: 'Verification complete',
            title: 'The applicant profile is supported by submitted records',
            description: 'The school and academic information was checked against the available evidence.',
            icon: 'fa-solid fa-check',
            section: 'oversight',
            action: 'View audit trail',
        };
    }

    if (status === 'rejected') {
        return {
            eyebrow: 'Applicant action needed',
            title: 'Replacement supporting evidence was requested',
            description: 'Review the current records and decision note while waiting for the applicant to upload a replacement.',
            icon: 'fa-solid fa-rotate',
            section: 'profile',
            action: 'View record and evidence',
        };
    }

    return {
        eyebrow: 'Review needed',
        title: 'Compare the profile with the submitted records',
        description: 'Confirm that the school and academic information is supported before recording the profile decision.',
        icon: 'fa-solid fa-arrow-right',
        section: 'profile',
        action: 'Open record and evidence',
    };
});

function academicVerificationDocument(currentApplicant) {
    return currentApplicant?.verification_documents?.find(
        (document) => document.document_type === 'academic_record',
    ) ?? null;
}

function selectReviewSection(section) {
    activeReviewSection.value = section;

    const url = new URL(window.location.href);
    url.searchParams.set('section', section);
    window.history.replaceState(window.history.state, '', url);
}

function statusLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function applicantReviewStatus(currentApplicant) {
    const status = currentApplicant?.applicant_verification_status;

    if (['approved', 'rejected'].includes(status)) {
        return status;
    }

    return academicVerificationDocument(currentApplicant) ? 'pending' : 'unsubmitted';
}

function applicantReviewStatusLabel(currentApplicant) {
    return {
        pending: 'Needs review',
        approved: 'Verified',
        rejected: 'Not verified',
        unsubmitted: 'No academic record',
    }[applicantReviewStatus(currentApplicant)];
}

function academicResultLabel(currentApplicant) {
    if (currentApplicant?.gwa !== null && currentApplicant?.gwa !== undefined) {
        return `${currentApplicant.gwa}${currentApplicant.grading_scale === 'percentage' ? '%' : ''}`;
    }

    if (currentApplicant?.grading_scale === 'pass_fail') {
        return 'Pass / fail or competency result';
    }

    return 'Not provided';
}

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'unsubmitted') {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function documentStatusClass(status) {
    if (['accepted', 'approved'].includes(status)) {
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

function academicScanStatusLabel(status) {
    return {
        succeeded: 'Result extracted',
        needs_review: 'Result not found',
        failed: 'Scan failed',
        unavailable: 'Scanner unavailable',
        not_requested: 'Not scanned',
    }[status] ?? 'Not scanned';
}

function academicScanStatusClass(status) {
    if (status === 'succeeded') {
        return 'bg-sky-100 text-sky-800';
    }

    if (['failed', 'needs_review'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function extractedAcademicResult(document) {
    if (document?.ocr_grading_scale === 'pass_fail') {
        return 'Pass / competency result';
    }

    if (document?.ocr_grade !== null && document?.ocr_grade !== undefined) {
        return document.ocr_grading_scale === 'percentage'
            ? `${document.ocr_grade}%`
            : `${document.ocr_grade} GWA / GPA`;
    }

    return 'No result extracted';
}

function documentTypeLabel(type) {
    return {
        academic_record: 'Academic record',
        recent_school_id: 'Recent school ID',
        school_record: 'School enrollment proof',
        identity_record: 'Identity and birth record',
        income_record: 'Household income proof',
        residency_record: 'Residence proof',
        guardian_record: 'Parent or guardian ID',
        no_current_scholarship_proof: 'Proof of no current scholarship',
        achievement_evidence: 'Achievement evidence',
    }[type] ?? 'Older verification file';
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

function applicantActionOptions(currentApplicant) {
    if (!profileEvidenceReady.value) {
        return [];
    }

    const status = applicantReviewStatus(currentApplicant);
    const actions = [];

    if (status !== 'approved' && (academicScanReady.value || reviewedAcademicResultReady.value)) {
        actions.push({
            status: 'approved',
            label: 'Verify profile information',
            className: 'bg-slate-950 text-white hover:bg-slate-800',
        });
    }

    if (status === 'approved') {
        actions.push({
            status: 'pending',
            label: 'Reopen verification',
            className: 'border border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100',
        });
    }

    if (status !== 'rejected') {
        actions.push({
            status: 'rejected',
            label: 'Request replacement',
            className: 'border border-rose-200 bg-white text-rose-700 hover:bg-rose-50',
        });
    }

    return actions;
}

function applyApplicant(payload) {
    applicant.value = payload;
    reviewNote.value = payload?.applicant_verification_notes ?? '';
    reviewedAcademicScale.value = payload?.grading_scale ?? '';
    reviewedAcademicResult.value = payload?.gwa ?? '';
}

async function loadApplicant() {
    isLoading.value = true;
    loadError.value = '';
    decisionError.value = '';

    try {
        const response = await window.axios.get(`/admin/applicants/${applicantId}/review/data`);
        applyApplicant(response.data.applicant);
    } catch (error) {
        loadError.value = error.response?.data?.message ?? 'Unable to load applicant review details.';
    } finally {
        isLoading.value = false;
    }
}

async function updateApplicant(verificationStatus) {
    if (!applicant.value || applicantReviewStatus(applicant.value) === verificationStatus) {
        return;
    }

    if (!profileEvidenceReady.value) {
        decisionError.value = 'The applicant must upload all required profile evidence before verification.';
        return;
    }

    const verificationNote = reviewNote.value.trim();
    const isReopening = applicantReviewStatus(applicant.value) === 'approved' && verificationStatus === 'pending';

    if ((verificationStatus === 'rejected' || isReopening) && !verificationNote) {
        decisionError.value = isReopening
            ? 'Add an oversight reason before reopening this verification.'
            : 'Add a reason so the applicant knows what must be corrected or replaced.';
        return;
    }

    isSaving.value = true;
    decisionError.value = '';

    try {
        const response = await window.axios.patch(`/admin/users/${applicantId}/profile-verification`, {
            verification_status: verificationStatus,
            verification_notes: verificationNote,
            academic_grading_scale: verificationStatus === 'approved' ? reviewedAcademicScale.value : null,
            academic_result: verificationStatus === 'approved' && reviewedAcademicResultIsNumeric.value
                ? reviewedAcademicResult.value
                : null,
        });
        const updatedApplicant = response.data.applicant ?? {
            ...applicant.value,
            ...response.data.user,
            verification_documents: response.data.verification_documents ?? [],
        };

        applyApplicant(updatedApplicant);
    } catch (error) {
        decisionError.value = error.response?.data?.message ?? 'Unable to save the applicant decision.';
    } finally {
        isSaving.value = false;
    }
}

async function updatePhotoReview(action, reason = '') {
    if (!applicant.value || isReviewingPhoto.value) {
        return;
    }

    isReviewingPhoto.value = true;
    photoReviewError.value = '';

    try {
        const response = await window.axios.patch(`/admin/applicants/${applicantId}/profile-photo-review`, {
            action,
            reason: reason || null,
        }, { portalToast: false });

        applyApplicant(response.data.applicant);
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

onMounted(loadApplicant);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar v-if="usesPortalManagerWorkspace" />
        <ReviewOfficerSidebar v-else-if="usesReviewOfficerWorkspace" />
        <AdminSidebar v-else active="reviews" />

        <FilePreviewModal
            :file="previewDocument"
            :title="documentTypeLabel(previewDocument?.document_type)"
            :context="applicant?.name || applicant?.username || 'Applicant'"
            @close="closeDocumentPreview"
        />

        <section class="admin-page">
            <div class="admin-container">
                <nav class="mb-4 flex min-w-0 items-center gap-2 text-sm" aria-label="Breadcrumb">
                    <a :href="reviewQueueUrl" class="font-bold text-slate-600 transition hover:text-slate-950">Applicant reviews</a>
                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-400" aria-hidden="true"></i>
                    <span class="truncate font-semibold text-slate-950">Review record</span>
                </nav>

                <TaskPageHeader
                    theme="admin"
                    eyebrow="Applicant review"
                    title="Verify applicant information"
                    description="Compare the profile with its supporting records, then record the verification decision."
                    icon="fa-solid fa-user-check"
                >
                    <template v-if="applicant" #actions>
                            <span :class="['w-fit rounded-md px-3 py-2 text-xs font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                {{ applicantReviewStatusLabel(applicant) }}
                            </span>
                            <button
                                v-if="academicRecord && activeReviewSection !== 'decision'"
                                type="button"
                                class="inline-flex w-fit items-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="selectReviewSection('decision')"
                            >
                                Record decision
                                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                            </button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading applicant review details...
                </div>

                <div v-else-if="loadError || !applicant" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 shadow-sm">
                    <p class="text-sm font-bold text-rose-800">Applicant details could not be loaded</p>
                    <p class="mt-1 text-sm leading-6 text-rose-700">{{ loadError }}</p>
                </div>

                <div v-else class="mt-4 space-y-4">
                        <section class="admin-panel overflow-hidden">
                            <div class="border-b border-slate-200 px-4 py-3">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Review steps</p>
                            </div>
                            <nav class="grid gap-1 p-1 sm:grid-cols-3" aria-label="Academic verification sections">
                                <button
                                    v-for="(section, index) in reviewSections"
                                    :key="section.key"
                                    type="button"
                                    :aria-current="activeReviewSection === section.key ? 'step' : undefined"
                                    :class="[
                                        'flex items-center gap-3 rounded-md px-3 py-2.5 text-left transition',
                                        activeReviewSection === section.key
                                            ? 'bg-slate-950 text-white'
                                            : 'text-slate-700 hover:bg-slate-50 hover:text-slate-950',
                                    ]"
                                    @click="selectReviewSection(section.key)"
                                >
                                    <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs', activeReviewSection === section.key ? 'bg-white/10 text-amber-300' : 'bg-slate-100 text-slate-600']"><i :class="section.icon" aria-hidden="true"></i></span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-bold">{{ index + 1 }}. {{ section.label }}</span>
                                        <span :class="['mt-0.5 block truncate text-xs', activeReviewSection === section.key ? 'text-slate-300' : 'text-slate-500']">
                                            <template v-if="section.key === 'profile'">Identity and school record</template>
                                            <template v-else-if="section.key === 'oversight'">Source and decision activity</template>
                                            <template v-else>{{ applicantReviewStatusLabel(applicant) }}</template>
                                        </span>
                                    </span>
                                </button>
                            </nav>
                        </section>

                        <section class="admin-panel flex flex-col gap-3 border-l-4 border-l-slate-950 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-950 text-sm text-amber-300"><i :class="reviewFocus.icon" aria-hidden="true"></i></span>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">{{ reviewFocus.eyebrow }}</p>
                                    <p class="mt-1 text-sm font-bold text-slate-950">{{ reviewFocus.title }}</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ reviewFocus.description }}</p>
                                </div>
                            </div>
                            <button v-if="reviewFocus.action" type="button" class="w-fit shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="selectReviewSection(reviewFocus.section)">{{ reviewFocus.action }}</button>
                            <span v-else class="w-fit shrink-0 rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">Waiting for evidence</span>
                        </section>

                        <ApplicantProfileRecord
                            v-if="activeReviewSection === 'profile'"
                            :record="applicantProfileRecord"
                            :sections="applicantProfileSections"
                            :verification-status="applicantReviewStatus(applicant)"
                            :verification-label="applicantReviewStatusLabel(applicant)"
                            :show-trust-notice="false"
                        >
                            <template #actions>
                                <ApplicantReviewIdentityCard
                                    :applicant="applicant"
                                    trigger-only
                                    :busy="isReviewingPhoto"
                                    :error="photoReviewError"
                                    @approve-photo="updatePhotoReview('approve')"
                                    @request-photo-replacement="updatePhotoReview('request_replacement', $event)"
                                />
                            </template>
                        </ApplicantProfileRecord>

                        <article v-if="activeReviewSection === 'profile'" id="verification-files" class="admin-panel scroll-mt-6 p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-file-lines" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Submitted evidence</p>
                                        <h3 class="mt-1 text-xl font-bold text-slate-950">Profile supporting records</h3>
                                        <p class="mt-1 text-sm text-slate-600">Open each submitted record and compare it with the related information in the applicant profile.</p>
                                    </div>
                                </div>
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                    {{ applicant.verification_documents?.length ? `${applicant.verification_documents.length} record${applicant.verification_documents.length === 1 ? '' : 's'}` : 'No record' }}
                                </span>
                            </div>

                            <div v-if="applicant.verification_documents?.length" class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                <div
                                    v-for="document in applicant.verification_documents"
                                    :key="document.id"
                                    class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                                            <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-950">{{ documentTypeLabel(document.document_type) }}</p>
                                            <p class="mt-1 truncate text-xs text-slate-500">{{ document.original_name }} - {{ formatFileSize(document.size) }}</p>
                                            <p class="mt-1 text-xs text-slate-500">Uploaded {{ document.uploaded_at || 'recently' }}</p>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                        <div
                                            v-if="academicScanRequired && document.document_type === 'academic_record'"
                                            class="flex items-center gap-2"
                                            :title="document.ocr_message || undefined"
                                        >
                                            <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', academicScanStatusClass(document.ocr_status)]">
                                                {{ academicScanStatusLabel(document.ocr_status) }}
                                            </span>
                                            <strong v-if="document.ocr_status === 'succeeded'" class="whitespace-nowrap text-xs text-slate-900">
                                                {{ extractedAcademicResult(document) }}
                                            </strong>
                                        </div>
                                        <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', documentStatusClass(document.status)]">
                                            {{ statusLabel(document.status || 'submitted') }}
                                        </span>
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            @click="openDocumentPreview(document)"
                                        >
                                            View file
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-if="!academicRecord" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800">
                                {{ applicant.verification_documents?.length
                                    ? 'School enrollment proof is available, but the applicant must still upload an academic record before the saved result can be verified.'
                                    : 'No academic record has been uploaded. The saved academic result cannot be verified yet.' }}
                            </p>
                        </article>

                        <article v-if="activeReviewSection === 'oversight'" class="admin-panel overflow-hidden">
                            <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Verification oversight</p>
                                        <h3 class="mt-1 text-xl font-bold text-slate-950">Decision source and audit trail</h3>
                                        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                                            See who checked the academic record and the application context used. Profile verification does not approve a scholarship application.
                                        </p>
                                    </div>
                                </div>
                                <span :class="['w-fit shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                    {{ applicantReviewStatusLabel(applicant) }}
                                </span>
                            </div>

                            <dl class="grid border-b border-slate-200 bg-slate-50 sm:grid-cols-3">
                                <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Review source</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ applicant.verification_oversight?.source_label || 'Awaiting review' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Reviewed by</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">
                                        {{ applicant.verification_oversight?.provider_organization || applicant.verification_oversight?.reviewer_name || 'No reviewer assigned' }}
                                    </dd>
                                    <p v-if="applicant.verification_oversight?.provider_organization && applicant.verification_oversight?.reviewer_name" class="mt-1 text-xs text-slate-500">
                                        Account: {{ applicant.verification_oversight.reviewer_name }}
                                    </p>
                                </div>
                                <div class="p-4">
                                    <dt class="text-xs font-semibold text-slate-500">Verified at</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ applicant.verification_oversight?.verified_at || 'Not verified yet' }}</dd>
                                </div>
                            </dl>

                            <div class="space-y-5 p-5">
                                <section v-if="applicant.verification_oversight?.context" class="rounded-md border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">
                                                {{ applicant.verification_oversight.context.is_current ? 'Provider verification context' : 'Previous provider context' }}
                                            </p>
                                            <h4 class="mt-1 truncate text-sm font-bold text-slate-950">{{ applicant.verification_oversight.context.program_title }}</h4>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ applicant.verification_oversight.context.provider_name || 'Provider' }} &middot; Application #{{ applicant.verification_oversight.context.application_id }}
                                            </p>
                                        </div>
                                        <a
                                            :href="applicant.verification_oversight.context.program_review_url"
                                            class="inline-flex shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        >
                                            View program
                                        </a>
                                    </div>
                                </section>

                                <section>
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Decision history</p>
                                            <h4 class="mt-1 text-base font-bold text-slate-950">Verification activity</h4>
                                        </div>
                                        <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">
                                            {{ applicant.verification_oversight?.history?.length || 0 }} record{{ applicant.verification_oversight?.history?.length === 1 ? '' : 's' }}
                                        </span>
                                    </div>

                                    <div v-if="applicant.verification_oversight?.history?.length" class="mt-3 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                        <div v-for="entry in applicant.verification_oversight.history" :key="entry.id" class="flex gap-3 p-4">
                                            <span :class="['mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full', entry.status === 'approved' ? 'bg-emerald-500' : entry.status === 'rejected' ? 'bg-rose-500' : 'bg-amber-500']"></span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                    <p class="text-sm font-bold text-slate-950">{{ entry.title }}</p>
                                                    <p class="text-xs text-slate-500">{{ entry.created_at }}</p>
                                                </div>
                                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ entry.actor_name || 'Portal reviewer' }} &middot; {{ entry.source_label }}</p>
                                                <p v-if="entry.reason" class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">Reason: {{ entry.reason }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-else class="mt-3 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                                        No verification decisions have been recorded yet.
                                    </p>
                                </section>

                                <section v-if="applicant.verification_oversight?.can_reopen" class="flex flex-col gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm font-bold text-amber-950">Admin oversight control</p>
                                        <p class="mt-1 text-xs leading-5 text-amber-800">Reopen only when the proof or recorded result needs another check. A reason is required and will be visible in the audit trail.</p>
                                    </div>
                                    <button type="button" class="shrink-0 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="selectReviewSection('decision')">
                                        Review decision
                                    </button>
                                </section>
                            </div>
                        </article>

                    <section v-if="activeReviewSection === 'decision'" id="verification-decision" class="admin-panel scroll-mt-6 p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Admin decision</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Profile verification decision</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">Compare the applicant's identity, school, household, and residence details with the submitted records, or request a replacement.</p>
                                </div>
                            </div>
                            <span :class="['shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                {{ applicantReviewStatusLabel(applicant) }}
                            </span>
                        </div>

                        <div v-if="profileEvidenceReady" class="w-full">
                            <div class="mt-4 overflow-hidden rounded-md border border-slate-200">
                                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                                    <p class="text-sm font-bold text-slate-950">Supporting records to check</p>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-500">Open the available records before verifying the profile. Verification confirms supported school and academic details, not every personal claim.</p>
                                </div>
                                <div class="divide-y divide-slate-200">
                                    <div v-for="document in profileEvidenceDocuments" :key="document.id" class="flex items-center justify-between gap-3 px-4 py-3">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                                                <i class="fa-solid fa-file-circle-check" aria-hidden="true"></i>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold text-slate-950">{{ documentTypeLabel(document.document_type) }}</p>
                                                <p class="mt-0.5 truncate text-xs text-slate-500">{{ document.original_name }}</p>
                                            </div>
                                        </div>
                                        <button type="button" class="w-fit shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="openDocumentPreview(document)">Review file</button>
                                    </div>
                                </div>
                            </div>

                            <p v-if="academicScanRequired && !academicScanReady" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">
                                The automatic scan did not find a usable result. Read the uploaded record and enter the verified result below, or request a clearer replacement.
                            </p>

                            <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                                <p class="text-sm font-bold text-slate-950">Academic result check</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Confirm or correct this part of the profile after reviewing the academic record.</p>
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="mb-1.5 block text-xs font-bold text-slate-700">Grading scale</span>
                                        <select v-model="reviewedAcademicScale" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100" @change="decisionError = ''">
                                            <option value="">Select grading scale</option>
                                            <option v-for="option in academicScaleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                        </select>
                                    </label>
                                    <label v-if="reviewedAcademicResultIsNumeric" class="block">
                                        <span class="mb-1.5 block text-xs font-bold text-slate-700">Result shown on record</span>
                                        <input
                                            v-model="reviewedAcademicResult"
                                            type="number"
                                            min="0.01"
                                            :max="reviewedAcademicScale === 'grade_point' ? 5 : 100"
                                            step="0.01"
                                            :placeholder="reviewedAcademicScale === 'grade_point' ? 'Example: 1.75' : 'Example: 89.50'"
                                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                            @input="decisionError = ''"
                                        >
                                    </label>
                                    <div v-else-if="reviewedAcademicScale" class="rounded-md border border-slate-200 bg-white px-3 py-2.5 text-xs leading-5 text-slate-600">
                                        Confirm this non-numeric result directly from the uploaded record.
                                    </div>
                                </div>
                            </div>

                            <label class="mt-5 block text-xs font-bold text-slate-700">
                                Review note <span class="font-normal text-slate-500">(required for reopening or replacement)</span>
                            </label>
                            <textarea
                                v-model="reviewNote"
                                rows="4"
                                maxlength="1500"
                                placeholder="Add context or explain which profile information or supporting record must be corrected."
                                class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                @input="decisionError = ''"
                            ></textarea>

                            <p v-if="decisionError" class="mt-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs font-semibold leading-5 text-rose-700">
                                {{ decisionError }}
                            </p>

                            <div class="mt-4 grid gap-2 sm:auto-cols-fr sm:grid-flow-col">
                                <button
                                    v-for="action in applicantActionOptions(applicant)"
                                    :key="action.status"
                                    type="button"
                                    :disabled="isSaving"
                                    :class="[
                                        'w-full rounded-md px-4 py-2.5 text-sm font-bold transition disabled:cursor-not-allowed disabled:opacity-60',
                                        action.className,
                                    ]"
                                    @click="updateApplicant(action.status)"
                                >
                                    {{ isSaving ? 'Saving decision...' : action.label }}
                                </button>
                            </div>
                        </div>

                        <div v-else class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">
                            The applicant must upload all required profile evidence before verification.
                        </div>
                    </section>

                    <nav class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm" aria-label="Applicant review navigation">
                        <button
                            type="button"
                            :disabled="!previousReviewSection"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:invisible"
                            @click="previousReviewSection && selectReviewSection(previousReviewSection.key)"
                        >
                            Previous
                        </button>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Step {{ activeReviewSectionIndex + 1 }} of {{ reviewSections.length }}</p>
                        <button
                            v-if="nextReviewSection"
                            type="button"
                            class="rounded-md bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800"
                            @click="selectReviewSection(nextReviewSection.key)"
                        >
                            Next: {{ nextReviewSection.label }}
                        </button>
                        <a v-else :href="reviewQueueUrl" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">Back to queue</a>
                    </nav>
                </div>

            </div>
        </section>
    </main>
</template>
