<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const summary = ref({});
const funnel = ref([]);
const programInsights = ref([]);
const topMissingDocuments = ref([]);
const documentIssues = ref([]);
const documentReviewQueue = ref([]);
const documentReviewPagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 8,
    total: 0,
});
const documentQueueLoading = ref(false);
const documentQueueError = ref('');
const dssSummary = ref({});

const dssItems = computed(() => [
    { label: 'Highly recommended', value: dssSummary.value.highly_recommended ?? 0 },
    { label: 'Recommended', value: dssSummary.value.recommended ?? 0 },
    { label: 'Needs review', value: dssSummary.value.needs_review ?? 0 },
    { label: 'Not recommended', value: dssSummary.value.not_recommended ?? 0 },
]);
const maxFunnelValue = computed(() => Math.max(1, ...funnel.value.map((item) => Number(item.value ?? 0))));
const maxProgramApplications = computed(() => Math.max(1, ...programInsights.value.map((program) => Number(program.applications ?? 0))));

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

function acceptedDocumentPercent(packet) {
    const total = Number(packet.files_count ?? 0);

    if (total === 0) {
        return '0%';
    }

    return `${Math.round((Number(packet.accepted_count ?? 0) / total) * 100)}%`;
}

function documentPacketStatus(packet) {
    const pending = Math.max(
        0,
        Number(packet.needs_review_count ?? 0)
            - Number(packet.replacement_count ?? 0)
            - Number(packet.rejected_count ?? 0),
    );
    const parts = [];

    if (pending > 0) {
        parts.push(`${pending} awaiting review`);
    }

    if (Number(packet.replacement_count ?? 0) > 0) {
        parts.push(`${packet.replacement_count} need replacement`);
    }

    if (Number(packet.rejected_count ?? 0) > 0) {
        parts.push(`${packet.rejected_count} rejected`);
    }

    return parts.length ? parts.join(' - ') : 'All documents reviewed';
}

function applyInsightsPayload(data) {
    summary.value = data.summary;
    funnel.value = data.funnel ?? [];
    programInsights.value = data.program_insights ?? [];
    topMissingDocuments.value = data.top_missing_documents ?? [];
    documentIssues.value = data.document_issues ?? [];
    dssSummary.value = data.dss_summary ?? {};

    const queue = data.document_review_queue ?? {};
    documentReviewQueue.value = Array.isArray(queue) ? queue : (queue.data ?? []);
    documentReviewPagination.value = {
        current_page: Number(queue.current_page ?? 1),
        last_page: Number(queue.last_page ?? 1),
        per_page: Number(queue.per_page ?? 8),
        total: Number(queue.total ?? documentReviewQueue.value.length),
    };
}

async function loadInsights() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/insights/data');
        applyInsightsPayload(response.data);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider review.';
    } finally {
        isLoading.value = false;
    }
}

async function loadDocumentPage(page) {
    const nextPage = Number(page);

    if (
        documentQueueLoading.value
        || nextPage < 1
        || nextPage > documentReviewPagination.value.last_page
        || nextPage === documentReviewPagination.value.current_page
    ) {
        return;
    }

    documentQueueLoading.value = true;
    documentQueueError.value = '';

    try {
        const response = await window.axios.get('/provider/insights/data', {
            params: { document_page: nextPage },
        });
        applyInsightsPayload(response.data);
    } catch (error) {
        documentQueueError.value = error.response?.data?.message ?? 'Unable to load this document page.';
    } finally {
        documentQueueLoading.value = false;
    }
}

onMounted(loadInsights);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Provider Review
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Review center
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Start with applicant files that need attention, then use the summaries for additional context.
                            </p>
                        </div>
                        <a href="/provider/applications" class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800">
                            Open applications
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider review...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 flex flex-col gap-6">
                    <section class="order-3 grid gap-6 xl:grid-cols-[0.85fr_1.15fr]">
                        <article class="provider-panel p-5">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
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

                        <article class="provider-panel p-5">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
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

                    <section class="provider-panel order-1 p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Document Review
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Applicant document packets
                                </h3>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    Review all uploaded files for one application together instead of working through a long file-by-file list.
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">
                                    {{ documentReviewPagination.total }} applications
                                </span>
                                <a
                                    href="/provider/applications"
                                    class="rounded-md border border-slate-300 px-3 py-2 text-center text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    View queue
                                </a>
                            </div>
                        </div>

                        <p v-if="documentQueueError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">
                            {{ documentQueueError }}
                        </p>

                        <div v-if="documentQueueLoading" class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">
                            Loading document packets...
                        </div>

                        <div v-else-if="documentReviewQueue.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No uploaded student documents yet.
                        </div>

                        <div v-else class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <div class="hidden grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto] gap-4 border-b border-slate-200 bg-slate-50 px-4 py-2.5 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500 xl:grid">
                                <span>Applicant</span>
                                <span>Files</span>
                                <span>Action</span>
                            </div>
                            <article
                                v-for="packet in documentReviewQueue"
                                :key="packet.application_id"
                                class="grid gap-3 border-b border-slate-200 p-4 last:border-b-0 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto] xl:items-center"
                            >
                                <div class="flex min-w-0 gap-3">
                                    <img
                                        :src="packet.scholarship_image_url || '/uploads/scholarship-default.jpg'"
                                        :alt="packet.scholarship || 'Scholarship'"
                                        class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0">
                                        <h4 class="truncate text-sm font-bold text-slate-950">{{ packet.applicant || 'Applicant' }}</h4>
                                        <p class="mt-1 truncate text-xs font-semibold text-amber-700">{{ packet.scholarship || 'Scholarship' }}</p>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ packet.applicant_email }} - Submitted {{ packet.submitted_at || 'recently' }}</p>
                                    </div>
                                </div>

                                <div class="min-w-0 max-w-md">
                                    <div class="flex items-center justify-between gap-4">
                                        <p class="text-sm font-bold text-slate-950">{{ packet.files_count }} uploaded</p>
                                        <p class="shrink-0 text-xs font-semibold text-slate-500">
                                            {{ packet.accepted_count }} accepted
                                        </p>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            class="h-full rounded-full bg-emerald-500 transition-all"
                                            :style="{ width: acceptedDocumentPercent(packet) }"
                                        ></div>
                                    </div>
                                    <p
                                        :class="[
                                            'mt-2 text-xs font-semibold',
                                            packet.needs_review_count ? 'text-amber-700' : 'text-emerald-700',
                                        ]"
                                    >
                                        {{ documentPacketStatus(packet) }}
                                    </p>
                                </div>

                                <a
                                    :href="packet.review_url"
                                    class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-3 py-2.5 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    Review files
                                    <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                </a>
                            </article>
                        </div>

                        <div v-if="documentReviewPagination.last_page > 1" class="mt-4 flex items-center justify-between gap-3">
                            <button
                                type="button"
                                :disabled="documentReviewPagination.current_page === 1 || documentQueueLoading"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                @click="loadDocumentPage(documentReviewPagination.current_page - 1)"
                            >
                                Previous
                            </button>
                            <p class="text-xs font-semibold text-slate-500">
                                Page {{ documentReviewPagination.current_page }} of {{ documentReviewPagination.last_page }}
                            </p>
                            <button
                                type="button"
                                :disabled="documentReviewPagination.current_page === documentReviewPagination.last_page || documentQueueLoading"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                @click="loadDocumentPage(documentReviewPagination.current_page + 1)"
                            >
                                Next
                            </button>
                        </div>
                    </section>

                    <section class="order-2 grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
                        <article class="provider-panel p-5">
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

                        <article class="provider-panel p-5">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                DSS Summary
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Recommendation signals
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                DSS summarizes how applicant profiles compare with your published program criteria. Use it to prioritize review, not as an automatic approval decision.
                            </p>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Average suitability score: <span class="font-bold text-slate-950">{{ dssSummary.average_score || 0 }}%</span>
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
