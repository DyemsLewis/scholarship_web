<script setup>
import { onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import { formatFileSize } from '../support/display';

const appElement = document.getElementById('app');
const applicantId = appElement?.dataset.applicantId;
const isLoading = ref(true);
const isSaving = ref(false);
const loadError = ref('');
const decisionError = ref('');
const applicant = ref(null);
const reviewNote = ref('');

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

    return currentApplicant?.verification_documents?.length ? 'pending' : 'unsubmitted';
}

function applicantReviewStatusLabel(currentApplicant) {
    return {
        pending: 'Needs review',
        approved: 'Verified',
        rejected: 'Not verified',
        unsubmitted: 'No proof',
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
    return statusLabel(type || 'document');
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
    if (!currentApplicant?.verification_documents?.length) {
        return [];
    }

    const status = applicantReviewStatus(currentApplicant);
    const actions = [];

    if (status !== 'approved') {
        actions.push({
            status: 'approved',
            label: 'Verify applicant',
            className: 'bg-slate-950 text-white hover:bg-slate-800',
        });
    }

    if (status !== 'rejected') {
        actions.push({
            status: 'rejected',
            label: 'Do not verify',
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

    if (!applicant.value.verification_documents?.length) {
        decisionError.value = 'The applicant must upload at least one proof before verification.';
        return;
    }

    const verificationNote = reviewNote.value.trim();

    if (verificationStatus === 'rejected' && !verificationNote) {
        decisionError.value = 'Add a reason so the applicant knows what proof must be replaced.';
        return;
    }

    isSaving.value = true;
    decisionError.value = '';

    try {
        const response = await window.axios.patch(`/admin/users/${applicantId}/profile-verification`, {
            verification_status: verificationStatus,
            verification_notes: verificationNote,
        });
        const updatedApplicant = {
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

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Applicant Review</p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">Applicant review details</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Compare the applicant profile with uploaded proof before making a verification decision.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a
                                href="/admin/reviews"
                                class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Back to reviews
                            </a>
                            <button
                                type="button"
                                class="w-fit rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                                @click="loadApplicant"
                            >
                                Refresh details
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
                        <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
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

                        <article id="applicant-details" class="scroll-mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-start gap-3 border-b border-slate-200 p-5">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-white">
                                    <i class="fa-solid fa-user-check" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Step 1 - Applicant details</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Profile information</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">Compare these saved details with the proof files below.</p>
                                </div>
                            </div>

                            <div class="grid gap-4 bg-slate-50/70 p-4 sm:p-5 lg:grid-cols-2">
                                <section class="rounded-md border border-slate-200 bg-white p-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700">
                                            <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                        </span>
                                        <h4 class="font-bold text-slate-950">Education</h4>
                                    </div>
                                    <dl class="mt-3 divide-y divide-slate-200 text-sm">
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">Education level</dt>
                                            <dd class="break-words font-bold text-slate-950 sm:text-right">{{ statusLabel(applicant.education_level || 'not provided') }}</dd>
                                        </div>
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">School</dt>
                                            <dd class="break-words font-bold text-slate-950 sm:text-right">{{ applicant.school || 'Not provided' }}</dd>
                                        </div>
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">Grade / year</dt>
                                            <dd class="break-words font-bold text-slate-950 sm:text-right">{{ applicant.year_level || 'Not provided' }}</dd>
                                        </div>
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">Track / course</dt>
                                            <dd class="break-words font-bold text-slate-950 sm:text-right">{{ applicant.course_or_strand || 'Not applicable' }}</dd>
                                        </div>
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">Learner number</dt>
                                            <dd class="break-words font-bold text-slate-950 sm:text-right">{{ applicant.learner_reference_number || 'Not provided' }}</dd>
                                        </div>
                                    </dl>
                                </section>

                                <section class="rounded-md border border-slate-200 bg-white p-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700">
                                            <i class="fa-solid fa-address-card" aria-hidden="true"></i>
                                        </span>
                                        <h4 class="font-bold text-slate-950">Personal details</h4>
                                    </div>
                                    <dl class="mt-3 divide-y divide-slate-200 text-sm">
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">Birthdate</dt>
                                            <dd class="font-bold text-slate-950 sm:text-right">
                                                {{ applicant.birthdate || 'Not provided' }}
                                                <span v-if="applicant.age !== null && applicant.age !== undefined" class="block text-xs font-normal text-slate-500">{{ applicant.age }} years old</span>
                                            </dd>
                                        </div>
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">Full location</dt>
                                            <dd class="break-words font-bold text-slate-950 sm:text-right">{{ [applicant.city, applicant.province, applicant.region].filter(Boolean).join(', ') || 'Not provided' }}</dd>
                                        </div>
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">Income bracket</dt>
                                            <dd class="break-words font-bold text-slate-950 sm:text-right">{{ applicant.income_bracket || 'Not provided' }}</dd>
                                        </div>
                                        <div class="grid gap-1 py-2.5 sm:grid-cols-[8.5rem_minmax(0,1fr)]">
                                            <dt class="text-slate-500">Household size</dt>
                                            <dd class="font-bold text-slate-950 sm:text-right">{{ applicant.household_size || 'Not provided' }}</dd>
                                        </div>
                                    </dl>
                                </section>
                            </div>

                            <section v-if="applicant.guardian_name" class="mx-4 mb-4 overflow-hidden rounded-md border border-slate-200 sm:mx-5 sm:mb-5">
                                <div class="flex items-center gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                                    <i class="fa-solid fa-people-roof text-slate-600" aria-hidden="true"></i>
                                    <h4 class="text-sm font-bold text-slate-950">Parent or guardian</h4>
                                </div>
                                <dl class="grid text-sm sm:grid-cols-3 sm:divide-x sm:divide-slate-200">
                                    <div class="border-b border-slate-200 p-3 sm:border-b-0">
                                        <dt class="text-xs font-semibold text-slate-500">Name</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ applicant.guardian_name }}</dd>
                                    </div>
                                    <div class="border-b border-slate-200 p-3 sm:border-b-0">
                                        <dt class="text-xs font-semibold text-slate-500">Relationship</dt>
                                        <dd class="mt-1 font-bold text-slate-950">{{ applicant.guardian_relationship || 'Not provided' }}</dd>
                                    </div>
                                    <div class="p-3">
                                        <dt class="text-xs font-semibold text-slate-500">Contact</dt>
                                        <dd class="mt-1 break-words font-bold text-slate-950">{{ applicant.guardian_contact || applicant.guardian_email || 'Not provided' }}</dd>
                                    </div>
                                </dl>
                            </section>
                            <div class="flex justify-end border-t border-slate-200 bg-white px-4 py-3 sm:px-5">
                                <a
                                    href="#verification-files"
                                    class="inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                >
                                    Next: Review proof
                                    <i class="fa-solid fa-arrow-down text-xs" aria-hidden="true"></i>
                                </a>
                            </div>
                        </article>

                        <article id="verification-files" class="scroll-mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Step 2 - Submitted proof</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Verification files</h3>
                                    <p class="mt-1 text-sm text-slate-600">Open each file and compare it with the applicant record.</p>
                                </div>
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                    {{ applicant.verification_documents?.length || 0 }} file{{ applicant.verification_documents?.length === 1 ? '' : 's' }}
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
                                        <a
                                            :href="document.view_url"
                                            target="_blank"
                                            rel="noopener"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                        >
                                            View file
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                No proof has been uploaded. This applicant cannot be verified yet.
                            </p>
                            <div class="mt-4 flex justify-end border-t border-slate-200 pt-4">
                                <a
                                    href="#verification-decision"
                                    class="inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                >
                                    Next: Make decision
                                    <i class="fa-solid fa-arrow-down text-xs" aria-hidden="true"></i>
                                </a>
                            </div>
                        </article>

                    <section id="verification-decision" class="scroll-mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Step 3 - Final decision</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">Verification decision</h3>
                            </div>
                            <span :class="['shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                {{ applicantReviewStatusLabel(applicant) }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Confirm that the profile and proof belong to the same applicant.
                        </p>

                        <div v-if="applicant.verification_documents?.length" class="max-w-3xl">
                            <div class="mt-4 flex items-center gap-3 rounded-md bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-200">
                                <i class="fa-solid fa-file-circle-check text-slate-500" aria-hidden="true"></i>
                                <span><strong>{{ applicant.verification_documents.length }}</strong> proof file{{ applicant.verification_documents.length === 1 ? '' : 's' }} available</span>
                            </div>

                            <label class="mt-5 block text-xs font-bold text-slate-700">
                                Review note <span class="font-normal text-slate-500">(required when not verifying)</span>
                            </label>
                            <textarea
                                v-model="reviewNote"
                                rows="4"
                                maxlength="1500"
                                placeholder="Add context or explain what proof must be replaced."
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
                            Wait for the applicant to upload proof before making a verification decision.
                        </div>
                    </section>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
