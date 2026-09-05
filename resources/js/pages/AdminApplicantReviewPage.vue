<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import { formatFileSize } from '../support/display';

const appElement = document.getElementById('app');
const applicantId = appElement?.dataset.applicantId;
const isLoading = ref(true);
const isSaving = ref(false);
const loadError = ref('');
const decisionError = ref('');
const applicant = ref(null);
const reviewNote = ref('');
const previewDocument = ref(null);
const requestedSection = new URLSearchParams(window.location.search).get('section');
const reviewSections = [
    { key: 'profile', label: 'Applicant record', icon: 'fa-solid fa-user-graduate' },
    { key: 'proof', label: 'Evidence', icon: 'fa-solid fa-file-lines' },
    { key: 'oversight', label: 'Review history', icon: 'fa-solid fa-shield-halved' },
    { key: 'decision', label: 'Decision', icon: 'fa-solid fa-gavel' },
];
const activeReviewSection = ref(reviewSections.some((section) => section.key === requestedSection) ? requestedSection : 'profile');
const activeReviewSectionIndex = computed(() => reviewSections.findIndex((section) => section.key === activeReviewSection.value));
const previousReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value - 1] ?? null);
const nextReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value + 1] ?? null);
const academicRecord = computed(() => academicVerificationDocument(applicant.value));
const savedAcademicResult = computed(() => academicResultLabel(applicant.value));
const academicScanRequired = computed(() => Boolean(applicant.value?.academic_scan_required));
const academicScanReady = computed(() => !academicScanRequired.value || academicRecord.value?.ocr_status === 'succeeded');
const hasGuardianDetails = computed(() => Boolean(
    applicant.value?.guardian_name
    || applicant.value?.guardian_relationship
    || applicant.value?.guardian_contact
    || applicant.value?.guardian_email,
));
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
            title: 'The saved academic result is verified',
            description: 'Open the audit trail to review who verified it and the recorded decision history.',
            icon: 'fa-solid fa-check',
            section: 'oversight',
            action: 'View audit trail',
        };
    }

    if (status === 'rejected') {
        return {
            eyebrow: 'Applicant action needed',
            title: 'A replacement academic record was requested',
            description: 'Review the current evidence and decision note while waiting for the applicant to upload a replacement.',
            icon: 'fa-solid fa-rotate',
            section: 'proof',
            action: 'View evidence',
        };
    }

    return {
        eyebrow: 'Review needed',
        title: 'Compare the saved result with the academic record',
        description: 'Open the submitted evidence, confirm that it supports the saved academic result, then record the decision.',
        icon: 'fa-solid fa-arrow-right',
        section: 'proof',
        action: 'Open evidence',
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
        school_record: 'School enrollment proof',
    }[type] ?? 'Older verification file';
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

function applicantInitials(currentApplicant) {
    return String(currentApplicant?.name || currentApplicant?.username || 'Applicant')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase();
}

function applicantActionOptions(currentApplicant) {
    if (!academicVerificationDocument(currentApplicant)) {
        return [];
    }

    const status = applicantReviewStatus(currentApplicant);
    const actions = [];

    if (status !== 'approved' && academicScanReady.value) {
        actions.push({
            status: 'approved',
            label: 'Verify academic result',
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

    if (!academicVerificationDocument(applicant.value)) {
        decisionError.value = 'The applicant must upload an academic record before verification.';
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

onMounted(loadApplicant);
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="reviews" />

        <FilePreviewModal
            :file="previewDocument"
            :title="documentTypeLabel(previewDocument?.document_type)"
            :context="applicant?.name || applicant?.username || 'Applicant'"
            @close="closeDocumentPreview"
        />

        <section class="admin-page">
            <div class="admin-container">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Applicant review</p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">Verify academic information</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Compare the applicant's saved result with the academic record, then record a clear decision.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a
                                href="/admin/reviews?type=applicants"
                                class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Back to reviews
                            </a>
                            <button
                                type="button"
                                class="w-fit rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                @click="loadApplicant"
                            >
                                Refresh
                            </button>
                            <button
                                v-if="academicRecord && activeReviewSection !== 'decision'"
                                type="button"
                                class="w-fit rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="selectReviewSection('decision')"
                            >
                                Record decision
                            </button>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading applicant review details...
                </div>

                <div v-else-if="loadError || !applicant" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 shadow-sm">
                    <p class="text-sm font-bold text-rose-800">Applicant details could not be loaded</p>
                    <p class="mt-1 text-sm leading-6 text-rose-700">{{ loadError }}</p>
                </div>

                <div v-else class="mt-6 space-y-4">
                        <section class="admin-panel overflow-hidden">
                            <div class="flex flex-col gap-4 border-l-4 border-l-amber-400 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-bold tracking-[0.08em] text-white">
                                        {{ applicantInitials(applicant) }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Applicant record</p>
                                        <h3 class="mt-1 truncate text-lg font-bold text-slate-950">{{ applicant.name || applicant.username }}</h3>
                                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
                                            <span>{{ applicant.email }}</span>
                                            <span>{{ applicant.contact_number || 'No contact number' }}</span>
                                        </div>
                                    </div>
                                </div>
                                <span :class="['w-fit shrink-0 rounded-md px-3 py-1.5 text-xs font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                    {{ applicantReviewStatusLabel(applicant) }}
                                </span>
                            </div>

                            <dl class="grid border-t border-slate-200 bg-slate-50/80 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div class="border-b border-slate-200 p-3 sm:border-r lg:border-b-0">
                                    <dt class="text-xs font-semibold text-slate-500">Education</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.education_level || 'not provided') }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-3 lg:border-b-0 lg:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">School</dt>
                                    <dd class="mt-1 truncate font-bold text-slate-950">{{ applicant.school || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-3 sm:border-b-0 sm:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Saved result</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ savedAcademicResult }}</dd>
                                </div>
                                <div class="p-3">
                                    <dt class="text-xs font-semibold text-slate-500">Academic evidence</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ academicRecord ? 'Submitted' : 'Not submitted' }}</dd>
                                </div>
                            </dl>

                            <div class="flex flex-col gap-4 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-950 text-sm text-amber-300">
                                        <i :class="reviewFocus.icon" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ reviewFocus.eyebrow }}</p>
                                        <p class="mt-1 text-sm font-bold text-slate-950">{{ reviewFocus.title }}</p>
                                        <p class="mt-1 max-w-3xl text-xs leading-5 text-slate-500">{{ reviewFocus.description }}</p>
                                    </div>
                                </div>
                                <button
                                    v-if="reviewFocus.action"
                                    type="button"
                                    class="w-fit shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800"
                                    @click="selectReviewSection(reviewFocus.section)"
                                >
                                    {{ reviewFocus.action }}
                                </button>
                                <span v-else class="w-fit shrink-0 rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">Waiting for evidence</span>
                            </div>
                        </section>

                        <section class="admin-panel overflow-hidden">
                            <nav class="grid gap-1 p-1 sm:grid-cols-2 xl:grid-cols-4" aria-label="Academic verification sections">
                                <button
                                    v-for="section in reviewSections"
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
                                        <span class="block text-sm font-bold">{{ section.label }}</span>
                                        <span :class="['mt-0.5 block truncate text-xs', activeReviewSection === section.key ? 'text-slate-300' : 'text-slate-500']">
                                            <template v-if="section.key === 'profile'">Identity and school record</template>
                                            <template v-else-if="section.key === 'proof'">{{ academicRecord ? 'Record submitted' : 'No academic record' }}</template>
                                            <template v-else-if="section.key === 'oversight'">Source and decision activity</template>
                                            <template v-else>{{ applicantReviewStatusLabel(applicant) }}</template>
                                        </span>
                                    </span>
                                </button>
                            </nav>
                        </section>

                        <article v-if="activeReviewSection === 'profile'" id="applicant-details" class="admin-panel scroll-mt-6 overflow-hidden">
                            <div class="flex items-start gap-3 border-b border-slate-200 p-5">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                    <i class="fa-solid fa-user-graduate" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Applicant record</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Profile used for verification</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">Confirm the saved school result against the submitted academic evidence. Other profile details provide identification context only.</p>
                                </div>
                            </div>

                            <div class="space-y-5 p-5">
                                <section>
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Applicant context</p>
                                            <h4 class="mt-1 text-base font-bold text-slate-950">Identity and account responsibility</h4>
                                        </div>
                                        <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">Applicant-declared</span>
                                    </div>
                                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div class="rounded-md bg-slate-50 p-3">
                                            <dt class="font-semibold text-slate-500">Birthdate</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.birthdate || 'Not provided' }}</dd>
                                            <dd v-if="applicant.age !== null && applicant.age !== undefined" class="mt-1 text-xs text-slate-500">Age {{ applicant.age }}</dd>
                                        </div>
                                        <div class="rounded-md bg-slate-50 p-3">
                                            <dt class="font-semibold text-slate-500">Gender</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.gender || 'not provided') }}</dd>
                                        </div>
                                        <div class="rounded-md bg-slate-50 p-3">
                                            <dt class="font-semibold text-slate-500">Account managed by</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.account_managed_by || 'learner') }}</dd>
                                        </div>
                                        <div class="rounded-md bg-slate-50 p-3">
                                            <dt class="font-semibold text-slate-500">Citizenship</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.citizenship_status || 'not provided') }}</dd>
                                        </div>
                                    </dl>
                                </section>

                                <section class="border-t border-slate-200 pt-5">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Academic details</p>
                                            <h4 class="mt-1 text-base font-bold text-slate-950">Record to compare with evidence</h4>
                                        </div>
                                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', academicRecord ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600']">
                                            {{ academicRecord ? 'Evidence submitted' : 'No evidence submitted' }}
                                        </span>
                                    </div>

                                    <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <dt class="font-semibold text-slate-500">Education level</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.education_level || 'not provided') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Grade / year / level</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.year_level || 'Not provided' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Enrollment status</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.enrollment_status || 'not provided') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Institution type</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.school_type || 'not provided') }}</dd>
                                        </div>
                                        <div class="sm:col-span-2">
                                            <dt class="font-semibold text-slate-500">School / learning institution</dt>
                                            <dd class="mt-1 break-words font-bold text-slate-950">{{ applicant.school || 'Not provided' }}</dd>
                                        </div>
                                        <div class="sm:col-span-2">
                                            <dt class="font-semibold text-slate-500">Track / strand / course</dt>
                                            <dd class="mt-1 break-words font-bold text-slate-950">{{ applicant.course_or_strand || 'Not applicable' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Learner / student ID</dt>
                                            <dd class="mt-1 break-words font-bold text-slate-950">{{ applicant.learner_reference_number || 'Not provided' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Academic year</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.academic_year || 'Not provided' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Record period</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.academic_term || 'not provided') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Grading system</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.grading_scale || 'not provided') }}</dd>
                                        </div>
                                    </dl>

                                    <div class="mt-4 flex flex-col gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-amber-800">Saved academic result</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-600">This is the applicant-entered value that must match the evidence.</p>
                                        </div>
                                        <p class="text-xl font-black text-slate-950">{{ savedAcademicResult }}</p>
                                    </div>
                                </section>

                                <section v-if="hasGuardianDetails" class="border-t border-slate-200 pt-5">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Parent or guardian</p>
                                            <h4 class="mt-1 text-base font-bold text-slate-950">Contact for a supported applicant</h4>
                                        </div>
                                        <span v-if="applicant.guardian_is_account_owner" class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">Manages applicant account</span>
                                    </div>
                                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <dt class="font-semibold text-slate-500">Name</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.guardian_name || 'Not provided' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Relationship</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.guardian_relationship || 'Not provided' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Contact</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.guardian_contact || 'Not provided' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="font-semibold text-slate-500">Email</dt>
                                            <dd class="mt-1 break-words font-bold text-slate-950">{{ applicant.guardian_email || 'Not provided' }}</dd>
                                        </div>
                                    </dl>
                                </section>
                            </div>
                        </article>

                        <article v-if="activeReviewSection === 'proof'" id="verification-files" class="admin-panel scroll-mt-6 p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-file-lines" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Submitted evidence</p>
                                        <h3 class="mt-1 text-xl font-bold text-slate-950">Academic and school records</h3>
                                        <p class="mt-1 text-sm text-slate-600">Open the academic record and compare it with the saved result shown above.</p>
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
                                            <div v-if="academicScanRequired && document.document_type === 'academic_record'" class="mt-2 flex flex-wrap items-center gap-2">
                                                <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', academicScanStatusClass(document.ocr_status)]">
                                                    {{ academicScanStatusLabel(document.ocr_status) }}
                                                </span>
                                                <strong v-if="document.ocr_status === 'succeeded'" class="text-xs text-slate-900">
                                                    {{ extractedAcademicResult(document) }}
                                                </strong>
                                                <p v-if="document.ocr_message" class="basis-full text-xs leading-5 text-slate-500">{{ document.ocr_message }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
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
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Academic verification decision</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">Approve the saved result, request a clearer record, or reopen it when another review is needed.</p>
                                </div>
                            </div>
                            <span :class="['shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                {{ applicantReviewStatusLabel(applicant) }}
                            </span>
                        </div>

                        <div v-if="academicRecord" class="w-full">
                            <div class="mt-4 flex flex-col gap-3 rounded-md bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-200 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3">
                                    <i class="fa-solid fa-file-circle-check text-slate-500" aria-hidden="true"></i>
                                    <span><strong class="text-slate-900">Academic record ready.</strong> Compare it with {{ savedAcademicResult }} before deciding.</span>
                                </div>
                                <button type="button" class="w-fit shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="openDocumentPreview(academicRecord)">View academic record</button>
                            </div>

                            <p v-if="academicScanRequired && !academicScanReady" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">
                                The portal could not extract a usable academic result. Ask the applicant to upload a clearer record or retry the scan before verification.
                            </p>

                            <label class="mt-5 block text-xs font-bold text-slate-700">
                                Review note <span class="font-normal text-slate-500">(required for reopening or replacement)</span>
                            </label>
                            <textarea
                                v-model="reviewNote"
                                rows="4"
                                maxlength="1500"
                                placeholder="Add context or explain what academic information or file must be corrected."
                                class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                @input="decisionError = ''"
                            ></textarea>

                            <p v-if="decisionError" class="mt-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs font-semibold leading-5 text-rose-700">
                                {{ decisionError }}
                            </p>

                            <div class="mt-4 grid gap-2">
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
                            Wait for the applicant to upload an academic record before making a verification decision.
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
                        <a v-else href="/admin/reviews?type=applicants" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">Back to queue</a>
                    </nav>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
