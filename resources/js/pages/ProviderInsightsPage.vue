<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const statusMessage = ref('');
const summary = ref({});
const funnel = ref([]);
const programInsights = ref([]);
const topMissingDocuments = ref([]);
const documentIssues = ref([]);
const documentReviewQueue = ref([]);
const documentStatuses = ref({});
const documentNotes = ref({});
const documentUpdatingId = ref(null);
const dssSummary = ref({});

const dssItems = computed(() => [
    { label: 'Highly recommended', value: dssSummary.value.highly_recommended ?? 0 },
    { label: 'Recommended', value: dssSummary.value.recommended ?? 0 },
    { label: 'Needs review', value: dssSummary.value.needs_review ?? 0 },
    { label: 'Not recommended', value: dssSummary.value.not_recommended ?? 0 },
]);
const maxFunnelValue = computed(() => Math.max(1, ...funnel.value.map((item) => Number(item.value ?? 0))));
const maxProgramApplications = computed(() => Math.max(1, ...programInsights.value.map((program) => Number(program.applications ?? 0))));
const documentStatusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'needs_replacement', label: 'Needs replacement' },
    { value: 'rejected', label: 'Rejected' },
];

function barWidth(value, max) {
    const numericValue = Number(value ?? 0);

    if (numericValue === 0) {
        return '0%';
    }

    return `${Math.max(8, Math.round((numericValue / max) * 100))}%`;
}

function statusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
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

    return 'bg-sky-100 text-sky-800';
}

function labelFromKey(value) {
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

async function loadInsights() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/insights/data');

        summary.value = response.data.summary;
        funnel.value = response.data.funnel ?? [];
        programInsights.value = response.data.program_insights ?? [];
        topMissingDocuments.value = response.data.top_missing_documents ?? [];
        documentIssues.value = response.data.document_issues ?? [];
        documentReviewQueue.value = response.data.document_review_queue ?? [];
        documentStatuses.value = Object.fromEntries(
            documentReviewQueue.value.map((document) => [document.id, document.status ?? 'pending']),
        );
        documentNotes.value = Object.fromEntries(
            documentReviewQueue.value.map((document) => [document.id, document.review_notes ?? '']),
        );
        dssSummary.value = response.data.dss_summary ?? {};
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider review.';
    } finally {
        isLoading.value = false;
    }
}

async function updateDocumentStatus(document) {
    documentUpdatingId.value = document.id;
    statusMessage.value = '';
    errorMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/documents/${document.id}/status`, {
            status: documentStatuses.value[document.id] ?? 'pending',
            review_notes: documentNotes.value[document.id] ?? '',
        });

        statusMessage.value = response.data.message ?? 'Document status updated.';
        await loadInsights();
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

onMounted(loadInsights);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Provider Review
                    </p>
                    <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Program and applicant review
                    </h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Review program activity, applicant readiness, document issues, and DSS recommendation signals.
                    </p>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider review...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </div>

                    <section class="grid gap-6 xl:grid-cols-[0.85fr_1.15fr]">
                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                Application Funnel
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                From discovery to approval
                            </h3>
                            <div class="mt-5 grid gap-4">
                                <div
                                    v-for="item in funnel"
                                    :key="item.label"
                                >
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-semibold text-slate-600">{{ item.label }}</span>
                                        <span class="font-bold text-slate-950">{{ item.value }}</span>
                                    </div>
                                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-slate-900" :style="{ width: barWidth(item.value, maxFunnelValue) }"></div>
                                    </div>
                                </div>
                            </div>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                Program Review
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Program activity and completion
                            </h3>
                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="program in programInsights"
                                    :key="program.id"
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4"
                                >
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="truncate font-bold text-slate-950">
                                                {{ program.title }}
                                            </p>
                                            <p class="mt-1 text-sm text-slate-500">
                                                {{ program.views }} views - {{ program.saves }} saves - DSS {{ program.average_dss_score || 0 }}%
                                            </p>
                                        </div>
                                        <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(program.status)]">
                                            {{ program.status }}
                                        </span>
                                    </div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white">
                                        <div class="h-full rounded-full bg-amber-400" :style="{ width: barWidth(program.applications, maxProgramApplications) }"></div>
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500">
                                        {{ program.applications }} submitted, {{ program.complete_applications }} complete checklist
                                    </p>
                                </div>
                            </div>
                        </article>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                    Document Review
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Uploaded files to check
                                </h3>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    Review student-uploaded documents, add a short note, and mark each file as accepted or needing replacement.
                                </p>
                            </div>
                            <a
                                href="/provider/applications"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                View applications
                            </a>
                        </div>

                        <div v-if="documentReviewQueue.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No uploaded student documents yet.
                        </div>

                        <div v-else class="mt-5 grid gap-3 lg:grid-cols-2">
                            <article
                                v-for="document in documentReviewQueue"
                                :key="document.id"
                                class="rounded-md border border-slate-200 bg-slate-50 p-3"
                            >
                                <div class="flex gap-3">
                                    <img
                                        :src="document.scholarship_image_url || '/uploads/scholarship-default.jpg'"
                                        :alt="document.scholarship || 'Scholarship'"
                                        class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <p class="truncate text-xs font-bold uppercase tracking-[0.14em] text-sky-700">
                                                    {{ document.scholarship || 'Scholarship' }}
                                                </p>
                                                <h4 class="mt-1 truncate text-base font-bold text-slate-950">
                                                    {{ document.document_name }}
                                                </h4>
                                                <p class="mt-1 truncate text-xs text-slate-500">
                                                    {{ document.applicant || 'Applicant' }} - {{ document.original_name }}
                                                </p>
                                            </div>
                                            <span :class="['h-fit w-fit rounded-md px-2.5 py-1 text-[11px] font-bold uppercase', documentStatusClass(document.status)]">
                                                {{ labelFromKey(document.status || 'pending') }}
                                            </span>
                                        </div>

                                        <div class="mt-3 flex flex-wrap gap-2 text-[11px] font-bold text-slate-600">
                                            <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">
                                                {{ formatFileSize(document.size) }}
                                            </span>
                                            <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">
                                                {{ document.uploaded_at || document.submitted_at || 'Recently uploaded' }}
                                            </span>
                                            <span class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">
                                                App #{{ document.application_id }}
                                            </span>
                                        </div>

                                        <div class="mt-3 grid gap-2 md:grid-cols-[11rem_1fr_auto] md:items-end">
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Status
                                                </label>
                                                <select
                                                    v-model="documentStatuses[document.id]"
                                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                >
                                                    <option
                                                        v-for="option in documentStatusOptions"
                                                        :key="option.value"
                                                        :value="option.value"
                                                    >
                                                        {{ option.label }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">
                                                    Note
                                                </label>
                                                <input
                                                    v-model="documentNotes[document.id]"
                                                    type="text"
                                                    maxlength="1000"
                                                    placeholder="Optional note for student"
                                                    class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 outline-none transition focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                                >
                                            </div>
                                            <div class="flex gap-2">
                                                <a
                                                    :href="document.download_url"
                                                    class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-white"
                                                >
                                                    Download
                                                </a>
                                                <button
                                                    type="button"
                                                    :disabled="documentUpdatingId === document.id"
                                                    class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                                    @click="updateDocumentStatus(document)"
                                                >
                                                    {{ documentUpdatingId === document.id ? 'Saving...' : 'Save' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Document Issues
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Missing and problem documents
                            </h3>
                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <div>
                                    <p class="text-sm font-bold text-slate-700">Most missing</p>
                                    <div class="mt-3 grid gap-2">
                                        <div
                                            v-for="document in topMissingDocuments"
                                            :key="document.document"
                                            class="flex items-center justify-between rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                                        >
                                            <span class="font-semibold text-slate-600">{{ document.document }}</span>
                                            <span class="font-bold text-slate-950">{{ document.total }}</span>
                                        </div>
                                        <p v-if="topMissingDocuments.length === 0" class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-3 text-sm text-slate-500">
                                            No missing documents yet.
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-slate-700">Review issues</p>
                                    <div class="mt-3 grid gap-2">
                                        <div
                                            v-for="document in documentIssues"
                                            :key="document.document"
                                            class="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                                        >
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="font-semibold text-slate-600">{{ document.document }}</span>
                                                <span class="font-bold text-slate-950">{{ document.total }}</span>
                                            </div>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ document.pending }} pending, {{ document.needs_replacement }} replacement, {{ document.rejected }} rejected
                                            </p>
                                        </div>
                                        <p v-if="documentIssues.length === 0" class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-3 text-sm text-slate-500">
                                            No document review issues yet.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-700">
                                DSS Summary
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Recommendation signals
                            </h3>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Average DSS score: <span class="font-bold text-slate-950">{{ dssSummary.average_score || 0 }}%</span>
                            </p>
                            <div class="mt-5 flex flex-wrap gap-2">
                                <div
                                    v-for="item in dssItems"
                                    :key="item.label"
                                    class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm"
                                >
                                    <span class="font-semibold text-slate-600">{{ item.label }}:</span>
                                    <span class="font-bold text-slate-950">{{ item.value }}</span>
                                </div>
                            </div>
                        </article>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
