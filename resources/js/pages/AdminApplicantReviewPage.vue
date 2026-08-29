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
    { key: 'profile', label: 'Academic profile' },
    { key: 'proof', label: 'Profile proof' },
    { key: 'oversight', label: 'Oversight' },
    { key: 'decision', label: 'Decision' },
];
const activeReviewSection = ref(reviewSections.some((section) => section.key === requestedSection) ? requestedSection : 'profile');
const activeReviewSectionIndex = computed(() => reviewSections.findIndex((section) => section.key === activeReviewSection.value));
const previousReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value - 1] ?? null);
const nextReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value + 1] ?? null);

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

    if (status !== 'approved') {
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
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="reviews" />

        <FilePreviewModal
            :file="previewDocument"
            :title="documentTypeLabel(previewDocument?.document_type)"
            :context="applicant?.name || applicant?.username || 'Applicant'"
            @close="closeDocumentPreview"
        />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Academic Review</p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">Academic verification details</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Compare the saved learning details with the submitted academic record. This review does not require identity, household, or income documents.
                            </p>
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
                                v-if="activeReviewSection !== 'decision'"
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

                <div v-else class="mt-6 space-y-5">
                        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <nav class="grid gap-1 p-2 sm:grid-cols-2 xl:grid-cols-4" aria-label="Academic verification steps">
                                <button
                                    v-for="(section, index) in reviewSections"
                                    :key="section.key"
                                    type="button"
                                    :aria-current="activeReviewSection === section.key ? 'step' : undefined"
                                    :class="[
                                        'flex items-center gap-3 rounded-md px-3 py-3 text-left transition',
                                        activeReviewSection === section.key
                                            ? 'bg-slate-950 text-white'
                                            : 'text-slate-700 hover:bg-slate-50 hover:text-slate-950',
                                    ]"
                                    @click="selectReviewSection(section.key)"
                                >
                                    <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs font-bold', activeReviewSection === section.key ? 'bg-white/10' : 'bg-slate-100 text-slate-600']">{{ index + 1 }}</span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-bold">{{ section.label }}</span>
                                        <span :class="['mt-0.5 block truncate text-xs', activeReviewSection === section.key ? 'text-slate-300' : 'text-slate-500']">
                                            <template v-if="section.key === 'profile'">Saved learning details</template>
                                            <template v-else-if="section.key === 'proof'">{{ academicVerificationDocument(applicant) ? 'Academic record submitted' : 'No academic record' }}</template>
                                            <template v-else-if="section.key === 'oversight'">{{ applicant.verification_oversight?.source_label || 'Awaiting review' }}</template>
                                            <template v-else>{{ applicantReviewStatusLabel(applicant) }}</template>
                                        </span>
                                    </span>
                                </button>
                            </nav>
                        </section>

                        <article v-if="activeReviewSection === 'profile'" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-bold tracking-[0.08em] text-white">
                                    {{ applicantInitials(applicant) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Applicant account</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">{{ applicant.name || applicant.username }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ applicant.email }}</p>
                                </div>
                                <span :class="['w-fit shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                    {{ applicantReviewStatusLabel(applicant) }}
                                </span>
                            </div>

                            <dl class="grid border-t border-slate-200 bg-slate-50 sm:grid-cols-2 lg:grid-cols-4">
                                <div class="border-b border-slate-200 p-4 sm:border-r lg:border-b-0">
                                    <dt class="text-xs font-semibold text-slate-500">Username</dt>
                                    <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ applicant.username || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 lg:border-b-0 lg:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Contact number</dt>
                                    <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ applicant.contact_number || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Account managed by</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ statusLabel(applicant.account_managed_by || 'applicant') }}</dd>
                                </div>
                                <div class="p-4">
                                    <dt class="text-xs font-semibold text-slate-500">Registered</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ applicant.created_at || 'Not provided' }}</dd>
                                </div>
                            </dl>
                        </article>

                        <article v-if="activeReviewSection === 'profile'" id="applicant-details" class="scroll-mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-start gap-3 border-b border-slate-200 p-5">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-white">
                                    <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Step 1 - Saved learning details</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Academic profile</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">These are the academic details the applicant is asking the admin to verify.</p>
                                </div>
                            </div>

                            <dl class="grid gap-3 bg-slate-50/70 p-4 text-sm sm:grid-cols-2 sm:p-5 lg:grid-cols-3">
                                <div class="rounded-md border border-slate-200 bg-white p-3">
                                    <dt class="text-xs font-semibold text-slate-500">Education level</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.education_level || 'not provided') }}</dd>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-white p-3 sm:col-span-2">
                                    <dt class="text-xs font-semibold text-slate-500">School / learning institution</dt>
                                    <dd class="mt-1 break-words font-bold text-slate-950">{{ applicant.school || 'Not provided' }}</dd>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-white p-3">
                                    <dt class="text-xs font-semibold text-slate-500">Grade / year / level</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ applicant.year_level || 'Not provided' }}</dd>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-white p-3">
                                    <dt class="text-xs font-semibold text-slate-500">Track / strand / course</dt>
                                    <dd class="mt-1 break-words font-bold text-slate-950">{{ applicant.course_or_strand || 'Not applicable' }}</dd>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-white p-3">
                                    <dt class="text-xs font-semibold text-slate-500">Enrollment status</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.enrollment_status || 'not provided') }}</dd>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-white p-3">
                                    <dt class="text-xs font-semibold text-slate-500">Grading system</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.grading_scale || 'not provided') }}</dd>
                                </div>
                                <div class="rounded-md border border-amber-200 bg-amber-50 p-3 sm:col-span-2">
                                    <dt class="text-xs font-semibold text-amber-800">Saved academic result</dt>
                                    <dd class="mt-1 text-lg font-black text-slate-950">
                                        <template v-if="applicant.gwa !== null && applicant.gwa !== undefined">{{ applicant.gwa }}{{ applicant.grading_scale === 'percentage' ? '%' : '' }}</template>
                                        <template v-else-if="applicant.grading_scale === 'pass_fail'">Pass / fail or competency result</template>
                                        <template v-else>Not provided</template>
                                    </dd>
                                </div>
                            </dl>
                        </article>

                        <article v-if="activeReviewSection === 'proof'" id="verification-files" class="scroll-mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Step 2 - Profile evidence</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Submitted academic and school records</h3>
                                    <p class="mt-1 text-sm text-slate-600">Check the academic record against the saved result. School enrollment proof provides additional school context when submitted.</p>
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
                            <p v-if="!academicVerificationDocument(applicant)" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800">
                                {{ applicant.verification_documents?.length
                                    ? 'School enrollment proof is available, but the applicant must still upload an academic record before the saved result can be verified.'
                                    : 'No academic record has been uploaded. The saved academic result cannot be verified yet.' }}
                            </p>
                        </article>

                        <article v-if="activeReviewSection === 'oversight'" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-white">
                                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Step 3 - Verification oversight</p>
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

                    <section v-if="activeReviewSection === 'decision'" id="verification-decision" class="scroll-mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Step 4 - Admin decision</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">Academic verification decision</h3>
                            </div>
                            <span :class="['shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                {{ applicantReviewStatusLabel(applicant) }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Confirm the submitted record, request a replacement, or reopen an existing verification when oversight finds an issue.
                        </p>

                        <div v-if="academicVerificationDocument(applicant)" class="w-full">
                            <div class="mt-4 flex items-center gap-3 rounded-md bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-200">
                                <i class="fa-solid fa-file-circle-check text-slate-500" aria-hidden="true"></i>
                                <span>Academic record is available for review</span>
                            </div>

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
