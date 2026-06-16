<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const updatingId = ref(null);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const stats = ref({
    scholarships: 0,
    applications: 0,
    drafts: 0,
});
const scholarships = ref([]);
const applications = ref([]);
const reviewNotes = ref({});

const publishedPrograms = computed(() => scholarships.value.filter((scholarship) => scholarship.status === 'published'));
const statusOptions = [
    { value: 'submitted', label: 'Submitted' },
    { value: 'under_review', label: 'Under review' },
    { value: 'qualified', label: 'Qualified' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
];

function statusLabel(status) {
    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'under_review') {
        return 'bg-sky-100 text-sky-800';
    }

    return 'bg-amber-100 text-amber-800';
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
        const response = await window.axios.get('/provider/applications/data');

        user.value = response.data.user;
        stats.value = response.data.stats;
        scholarships.value = response.data.scholarships;
        applications.value = response.data.applications;
        reviewNotes.value = Object.fromEntries(
            applications.value.map((application) => [application.id, application.review_notes ?? '']),
        );
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider applications.';
    } finally {
        isLoading.value = false;
    }
}

async function updateStatus(application, status) {
    updatingId.value = application.id;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/applications/${application.id}/status`, {
            status,
            review_notes: reviewNotes.value[application.id] ?? '',
        });

        statusMessage.value = response.data.message ?? 'Application status updated.';
        await loadProviderData();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update application status.';
    } finally {
        updatingId.value = null;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Application Review
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Applicant activity queue
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Review applications submitted through the applicant wizard.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Provider
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.provider_name || user?.name || 'Provider' }}
                            </p>
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

                    <div class="grid gap-4 md:grid-cols-3">
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Total Applications
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-emerald-700">
                                {{ stats.applications }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Published Programs
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-sky-700">
                                {{ publishedPrograms.length }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Draft Programs
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-amber-600">
                                {{ stats.drafts }}
                            </p>
                        </article>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Review Queue
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Submitted applications
                        </h3>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm leading-6 text-slate-600">
                                Move applications through the review pipeline and export records when needed.
                            </p>
                            <a
                                href="/provider/export/applications"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Export CSV
                            </a>
                        </div>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applicant records yet. Applications submitted by users will appear here.
                        </div>

                        <div v-else class="mt-5 grid gap-4 xl:grid-cols-2">
                            <article
                                v-for="application in applications"
                                :key="application.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-700">
                                            {{ application.scholarship?.title || 'Scholarship' }}
                                        </p>
                                        <h4 class="mt-2 text-lg font-bold text-slate-950">
                                            {{ application.applicant?.name || 'Applicant' }}
                                        </h4>
                                        <p class="mt-1 text-sm text-slate-500">
                                            Submitted {{ application.submitted_at || 'recently' }}
                                        </p>
                                    </div>
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                        {{ statusLabel(application.status) }}
                                    </span>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <p class="font-semibold text-slate-500">
                                                Pipeline status
                                            </p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                Update where this application sits in review.
                                            </p>
                                        </div>
                                        <select
                                            :value="application.status"
                                            :disabled="updatingId === application.id"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100 disabled:opacity-60"
                                            @change="updateStatus(application, $event.target.value)"
                                        >
                                            <option
                                                v-for="option in statusOptions"
                                                :key="option.value"
                                                :value="option.value"
                                            >
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="mt-4 border-t border-slate-200 pt-3">
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                            Review note
                                        </label>
                                        <textarea
                                            v-model="reviewNotes[application.id]"
                                            rows="3"
                                            maxlength="1500"
                                            placeholder="Example: Missing proof of income, qualified for interview, or approved for final review."
                                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                        ></textarea>
                                        <button
                                            type="button"
                                            :disabled="updatingId === application.id"
                                            class="mt-2 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-70"
                                            @click="updateStatus(application, application.status)"
                                        >
                                            {{ updatingId === application.id ? 'Saving...' : 'Save note' }}
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Email
                                        </p>
                                        <p class="mt-1 break-words font-bold text-slate-950">
                                            {{ application.applicant?.email || 'Not provided' }}
                                        </p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Contact number
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ application.applicant?.contact_number || 'Not provided' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-semibold text-slate-500">
                                            Confirmed documents
                                        </p>
                                        <span class="rounded-md bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">
                                            {{ application.document_readiness?.percent ?? 0 }}% ready
                                        </span>
                                    </div>
                                    <div v-if="application.document_checklist?.length" class="mt-2 flex flex-wrap gap-2">
                                        <span
                                            v-for="document in application.document_checklist"
                                            :key="document"
                                            class="rounded-md bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800"
                                        >
                                            {{ document }}
                                        </span>
                                    </div>
                                    <p v-else class="mt-2 text-slate-500">
                                        No checklist items saved.
                                    </p>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="font-semibold text-slate-500">
                                            Uploaded files
                                        </p>
                                        <span class="rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                            {{ application.document_readiness?.uploaded ?? 0 }} uploaded
                                        </span>
                                    </div>

                                    <div v-if="application.documents?.length" class="mt-3 grid gap-2">
                                        <a
                                            v-for="document in application.documents"
                                            :key="document.id"
                                            :href="document.download_url"
                                            class="rounded-md border border-slate-200 bg-slate-50 p-3 transition hover:border-sky-200 hover:bg-sky-50"
                                        >
                                            <p class="font-bold text-slate-950">
                                                {{ document.document_name }}
                                            </p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ document.original_name }} · {{ formatFileSize(document.size) }} · {{ document.uploaded_at }}
                                            </p>
                                        </a>
                                    </div>
                                    <p v-else class="mt-2 text-slate-500">
                                        No uploaded files yet.
                                    </p>
                                </div>

                                <div class="mt-4 rounded-md bg-white p-3 text-sm">
                                    <p class="font-semibold text-slate-500">
                                        Applicant note
                                    </p>
                                    <p class="mt-1 leading-6 text-slate-700">
                                        {{ application.notes || 'No note added.' }}
                                    </p>
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
