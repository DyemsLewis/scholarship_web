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
            className: 'bg-emerald-700 text-white hover:bg-emerald-800',
        });
    }

    if (status !== 'rejected') {
        actions.push({
            status: 'rejected',
            label: 'Do not verify',
            className: 'bg-rose-700 text-white hover:bg-rose-800',
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
                            <a href="/admin/reviews" class="inline-flex text-sm font-bold text-amber-700 underline underline-offset-4">
                                Back to review queue
                            </a>
                            <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">Applicant review details</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Compare the applicant profile with uploaded proof before making a verification decision.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="w-fit rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                            @click="loadApplicant"
                        >
                            Refresh details
                        </button>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading applicant review details...
                </div>

                <div v-else-if="loadError || !applicant" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 shadow-sm">
                    <p class="text-sm font-bold text-rose-800">Applicant details could not be loaded</p>
                    <p class="mt-1 text-sm leading-6 text-rose-700">{{ loadError }}</p>
                </div>

                <div v-else class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div class="space-y-5">
                        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start gap-4">
                                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-bold tracking-[0.08em] text-white">
                                    {{ applicantInitials(applicant) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-xl font-bold text-slate-950">{{ applicant.name || applicant.username }}</h3>
                                        <span :class="['rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(applicantReviewStatus(applicant))]">
                                            {{ applicantReviewStatusLabel(applicant) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-500">{{ applicant.email }}</p>
                                    <p class="mt-2 text-xs text-slate-500">Registered {{ applicant.created_at || 'date not available' }}</p>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-5 border-t border-slate-200 pt-5 lg:grid-cols-2">
                                <section>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Education</p>
                                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-1">
                                        <div>
                                            <dt class="text-xs font-semibold text-slate-500">Education level</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.education_level || 'not provided') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold text-slate-500">Grade / year</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.year_level || 'Not provided' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold text-slate-500">School / institution</dt>
                                            <dd class="mt-1 font-bold leading-6 text-slate-950">{{ applicant.school || 'Not provided' }}</dd>
                                        </div>
                                        <div v-if="applicant.course_or_strand">
                                            <dt class="text-xs font-semibold text-slate-500">Track / course / program</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.course_or_strand }}</dd>
                                        </div>
                                        <div v-if="applicant.learner_reference_number">
                                            <dt class="text-xs font-semibold text-slate-500">Learner reference number</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.learner_reference_number }}</dd>
                                        </div>
                                    </dl>
                                </section>

                                <section>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Applicant details</p>
                                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-1">
                                        <div>
                                            <dt class="text-xs font-semibold text-slate-500">Birthdate</dt>
                                            <dd class="mt-1 font-bold text-slate-950">
                                                {{ applicant.birthdate || 'Not provided' }}
                                                <span v-if="applicant.age !== null && applicant.age !== undefined" class="font-normal text-slate-500">({{ applicant.age }} years old)</span>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold text-slate-500">Contact number</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ applicant.contact_number || 'Not provided' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold text-slate-500">Location</dt>
                                            <dd class="mt-1 font-bold leading-6 text-slate-950">
                                                {{ [applicant.city, applicant.province, applicant.region].filter(Boolean).join(', ') || 'Not provided' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-semibold text-slate-500">Account managed by</dt>
                                            <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(applicant.account_managed_by || 'applicant') }}</dd>
                                        </div>
                                    </dl>
                                </section>
                            </div>

                            <section v-if="applicant.guardian_name" class="mt-5 border-t border-slate-200 pt-5">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Parent / guardian</p>
                                <div class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <p class="text-xs font-semibold text-slate-500">Name</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ applicant.guardian_name }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-slate-500">Relationship</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ applicant.guardian_relationship || 'Not provided' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-slate-500">Contact</p>
                                        <p class="mt-1 font-bold text-slate-950">{{ applicant.guardian_contact || applicant.guardian_email || 'Not provided' }}</p>
                                    </div>
                                </div>
                            </section>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Submitted proof</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">Files to verify</h3>
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
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-950">{{ documentTypeLabel(document.document_type) }}</p>
                                        <p class="mt-1 truncate text-xs text-slate-500">
                                            {{ document.original_name }} - {{ formatFileSize(document.size) }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">Uploaded {{ document.uploaded_at || 'recently' }}</p>
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
                        </section>
                    </div>

                    <aside class="h-fit rounded-lg border border-slate-200 bg-white p-5 shadow-sm xl:sticky xl:top-8">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Admin decision</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950">Verify this applicant?</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Compare the profile with the submitted proof before choosing a decision.
                        </p>

                        <div v-if="applicant.verification_documents?.length">
                            <label class="mt-5 block text-xs font-bold text-slate-700">
                                Reason if not verified
                            </label>
                            <textarea
                                v-model="reviewNote"
                                rows="5"
                                maxlength="1500"
                                placeholder="Explain what is incorrect or what proof must be replaced."
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
                            <p class="mt-3 text-xs leading-5 text-slate-500">
                                A rejection reason tells the applicant what proof must be replaced.
                            </p>
                        </div>

                        <div v-else class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-900">
                            Wait for the applicant to upload proof before making a verification decision.
                        </div>
                    </aside>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
