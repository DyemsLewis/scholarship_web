<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderProgramNav from '../components/ProviderProgramNav.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { labelFromKey } from '../support/display';
import { showPortalToast } from '../support/portalToast';

const scholarshipId = document.getElementById('app')?.dataset.scholarshipId;
const scholarship = ref(null);
const cycles = ref([]);
const benefitReleases = ref([]);
const releaseCandidates = ref([]);
const supportRecipients = ref([]);
const programSummary = ref(null);
const monitoringViews = ['summary', 'monitoring', 'releases', 'outcomes'];
const requestedMonitoringView = new URLSearchParams(window.location.search).get('view');
const activeTab = ref(monitoringViews.includes(requestedMonitoringView) ? requestedMonitoringView : 'summary');
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const showCycleForm = ref(false);
const titleInput = ref(null);
const previewFile = ref(null);
const reviewTarget = ref(null);
const reviewNotes = ref('');
const isReviewing = ref(false);
const openCycles = ref(new Set());
const today = new Date().toISOString().slice(0, 10);
const form = ref(defaultForm());
const showReleaseForm = ref(false);
const isSavingRelease = ref(false);
const releaseForm = ref(defaultReleaseForm());
const openReleases = ref(new Set());
const releaseTarget = ref(null);
const isRecordingRelease = ref(false);
const releaseResultForm = ref(defaultReleaseResultForm());
const supportTarget = ref(null);
const supportForm = ref(defaultSupportForm());
const isSavingSupport = ref(false);
const supportError = ref('');
const recipientRecordTarget = ref(null);
const recipientRecord = ref(null);
const recipientRecordError = ref('');
const isLoadingRecipientRecord = ref(false);

const eligibleCandidates = computed(() => releaseCandidates.value.filter((candidate) => candidate.eligible));
const activeSupportCount = computed(() => supportRecipients.value.filter((recipient) => !recipient.is_closed).length);
const renewalReadyCount = computed(() => supportRecipients.value.filter((recipient) => recipient.renewal_eligible && !recipient.is_closed).length);
const viewTabs = computed(() => [
    {
        value: 'summary',
        label: 'Overview',
        icon: 'fa-solid fa-table-columns',
        count: Number(programSummary.value?.attention_count ?? 0),
    },
    {
        value: 'monitoring',
        label: 'Academic checks',
        icon: 'fa-solid fa-chart-line',
        count: cycles.value.reduce((total, cycle) => total + Number(cycle.action_needed_count ?? 0), 0),
    },
    {
        value: 'releases',
        label: 'Benefit releases',
        icon: 'fa-solid fa-hand-holding-heart',
        count: benefitReleases.value.reduce((total, release) => total + Number(release.pending_count ?? 0), 0),
    },
    {
        value: 'outcomes',
        label: 'Support outcomes',
        icon: 'fa-solid fa-flag-checkered',
        count: renewalReadyCount.value,
    },
]);

function defaultForm() {
    return {
        title: '',
        period_type: 'semester',
        academic_period: '',
        school_year: '',
        opens_at: today,
        due_at: '',
        minimum_grade: '85',
        grading_scale: 'percentage',
        instructions: '',
    };
}

function defaultReleaseForm() {
    return {
        title: '',
        release_at: '',
        benefit_description: '',
        amount: '',
        release_method: 'in_person',
        location: '',
        instructions: '',
        requires_original_verification: true,
        recipient_ids: [],
    };
}

function defaultReleaseResultForm() {
    return { status: 'prepared', originals_verified: false, notes: '', receipt_proof: null };
}

function defaultSupportForm() {
    return {
        decision: 'renewed',
        effective_on: today,
        support_ends_on: '',
        next_review_on: '',
        reason: '',
        next_period_terms: '',
        confirmed: false,
    };
}

function supportStatusClass(status) {
    if (status === 'renewed') return 'bg-sky-100 text-sky-800';
    if (status === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (status === 'terminated') return 'bg-rose-100 text-rose-700';
    return 'bg-amber-100 text-amber-800';
}

function recordStatusClass(status) {
    if (['accepted', 'met', 'excused', 'released', 'renewed', 'completed'].includes(status)) return 'bg-emerald-100 text-emerald-800';
    if (['not_met', 'missed', 'withheld', 'terminated', 'declined'].includes(status)) return 'bg-rose-100 text-rose-700';
    if (['needs_correction', 'prepared'].includes(status)) return 'bg-amber-100 text-amber-800';
    return 'bg-slate-100 text-slate-600';
}

function recordEventIcon(type) {
    if (type === 'agreement') return 'fa-file-signature';
    if (type === 'monitoring') return 'fa-graduation-cap';
    if (type === 'release') return 'fa-hand-holding-heart';
    return 'fa-flag-checkered';
}

function openSummaryItem(item) {
    if (item.application_url) {
        window.location.href = item.application_url;
        return;
    }

    setActiveTab(item.type === 'release' ? 'releases' : 'monitoring');
}

function setActiveTab(view) {
    if (!monitoringViews.includes(view)) return;

    activeTab.value = view;
    const url = new URL(window.location.href);

    if (view === 'summary') {
        url.searchParams.delete('view');
    } else {
        url.searchParams.set('view', view);
    }

    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
}

async function openRecipientRecord(recipient) {
    recipientRecordTarget.value = recipient;
    recipientRecord.value = null;
    recipientRecordError.value = '';
    isLoadingRecipientRecord.value = true;

    try {
        const response = await window.axios.get(`/provider/applications/${recipient.application_id}/recipient-record`);
        recipientRecord.value = response.data.record;
    } catch (error) {
        recipientRecordError.value = error.response?.data?.message ?? 'Unable to load this recipient record.';
    } finally {
        isLoadingRecipientRecord.value = false;
    }
}

function closeRecipientRecord() {
    recipientRecordTarget.value = null;
    recipientRecord.value = null;
    recipientRecordError.value = '';
}

function openSupportDecision(recipient = null) {
    supportTarget.value = recipient ?? supportRecipients.value.find((item) => !item.is_closed) ?? null;
    supportForm.value = defaultSupportForm();
    supportError.value = '';
}

function closeSupportDecision() {
    if (isSavingSupport.value) return;
    supportTarget.value = null;
    supportError.value = '';
}

function openComposer() {
    form.value = defaultForm();
    errorMessage.value = '';
    showCycleForm.value = true;
    nextTick(() => titleInput.value?.focus());
}

function closeComposer() {
    if (isSaving.value) return;
    showCycleForm.value = false;
}

function openReleaseComposer() {
    releaseForm.value = {
        ...defaultReleaseForm(),
        recipient_ids: eligibleCandidates.value.map((candidate) => candidate.application_id),
    };
    errorMessage.value = '';
    showReleaseForm.value = true;
}

function closeReleaseComposer() {
    if (isSavingRelease.value) return;
    showReleaseForm.value = false;
}

function toggleRelease(releaseId) {
    const next = new Set(openReleases.value);
    next.has(releaseId) ? next.delete(releaseId) : next.add(releaseId);
    openReleases.value = next;
}

function releaseIsOpen(releaseId) {
    return openReleases.value.has(releaseId);
}

function releaseStatusClass(status) {
    if (status === 'released' || status === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (status === 'prepared' || status === 'in_progress') return 'bg-sky-100 text-sky-800';
    if (status === 'missed' || status === 'withheld') return 'bg-rose-100 text-rose-700';
    return 'bg-amber-100 text-amber-800';
}

function openReleaseResult(release, record) {
    releaseTarget.value = { release, record };
    releaseResultForm.value = {
        status: record.status === 'scheduled' ? 'prepared' : record.status,
        originals_verified: Boolean(record.originals_verified),
        notes: record.notes ?? '',
        receipt_proof: null,
    };
}

function closeReleaseResult() {
    if (isRecordingRelease.value) return;
    releaseTarget.value = null;
    releaseResultForm.value = defaultReleaseResultForm();
}

function toggleCycle(cycleId) {
    const next = new Set(openCycles.value);
    next.has(cycleId) ? next.delete(cycleId) : next.add(cycleId);
    openCycles.value = next;
}

function cycleIsOpen(cycleId) {
    return openCycles.value.has(cycleId);
}

function comparisonClass(status) {
    if (status === 'pass') return 'bg-emerald-100 text-emerald-800';
    if (status === 'fail') return 'bg-rose-100 text-rose-700';
    return 'bg-amber-100 text-amber-800';
}

function comparisonLabel(submission) {
    if (!submission?.grade) return 'Result needs review';
    if (submission.comparison?.status === 'pass') return 'Meets requirement';
    if (submission.comparison?.status === 'fail') return 'Below requirement';
    return 'Manual review';
}

function reviewStatusClass(status) {
    if (status === 'met') return 'bg-emerald-100 text-emerald-800';
    if (status === 'not_met') return 'bg-rose-100 text-rose-700';
    if (status === 'needs_correction') return 'bg-amber-100 text-amber-800';
    if (status === 'excused') return 'bg-sky-100 text-sky-800';
    return 'bg-slate-100 text-slate-600';
}

function openReview(cycle, recipient) {
    reviewTarget.value = { cycle, recipient };
    reviewNotes.value = recipient.submission?.review_notes ?? '';
}

function closeReview() {
    if (isReviewing.value) return;
    reviewTarget.value = null;
    reviewNotes.value = '';
}

async function submitReview(decision) {
    if (!reviewTarget.value || isReviewing.value) return;

    if (['not_met', 'needs_correction', 'excused'].includes(decision) && reviewNotes.value.trim().length < 5) {
        showPortalToast({
            type: 'error',
            title: 'Review note required',
            message: 'Add a short explanation before recording this decision.',
        });
        return;
    }

    isReviewing.value = true;
    try {
        const submission = reviewTarget.value.recipient.submission;
        const response = await window.axios.patch(`/provider/monitoring-submissions/${submission.id}/review`, {
            decision,
            notes: reviewNotes.value.trim() || null,
        });
        const cycleIndex = cycles.value.findIndex((cycle) => cycle.id === response.data.cycle.id);
        if (cycleIndex >= 0) cycles.value.splice(cycleIndex, 1, response.data.cycle);
        reviewTarget.value = null;
        reviewNotes.value = '';
        await loadMonitoring();
        showPortalToast({ type: 'success', title: 'Review recorded', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Review not saved',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to save this review.'),
        });
    } finally {
        isReviewing.value = false;
    }
}

async function loadMonitoring() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/scholarships/${scholarshipId}/monitoring-cycles`);
        scholarship.value = response.data.scholarship;
        cycles.value = response.data.cycles ?? [];
        benefitReleases.value = response.data.benefit_releases ?? [];
        releaseCandidates.value = response.data.release_candidates ?? [];
        supportRecipients.value = response.data.support_recipients ?? [];
        programSummary.value = response.data.program_summary ?? null;
        if (cycles.value.length) openCycles.value = new Set([cycles.value[0].id]);
        if (benefitReleases.value.length) openReleases.value = new Set([benefitReleases.value[0].id]);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load recipient monitoring.';
    } finally {
        isLoading.value = false;
    }
}

async function submitSupportDecision() {
    if (!supportTarget.value || isSavingSupport.value) return;

    isSavingSupport.value = true;
    supportError.value = '';
    try {
        const response = await window.axios.post(
            `/provider/applications/${supportTarget.value.application_id}/support-decision`,
            supportForm.value,
        );
        const index = supportRecipients.value.findIndex((recipient) => recipient.application_id === response.data.recipient.application_id);
        if (index >= 0) supportRecipients.value.splice(index, 1, response.data.recipient);
        supportTarget.value = null;
        await loadMonitoring();
        showPortalToast({ type: 'success', title: 'Support outcome recorded', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        supportError.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to record this support outcome.');
    } finally {
        isSavingSupport.value = false;
    }
}

async function publishRelease() {
    if (isSavingRelease.value) return;
    if (!releaseForm.value.recipient_ids.length) {
        errorMessage.value = 'Select at least one eligible recipient.';
        return;
    }

    isSavingRelease.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.post(`/provider/scholarships/${scholarshipId}/benefit-releases`, releaseForm.value);
        benefitReleases.value = [response.data.release, ...benefitReleases.value];
        openReleases.value = new Set([response.data.release.id]);
        showReleaseForm.value = false;
        showPortalToast({ type: 'success', title: 'Release scheduled', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        errorMessage.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to schedule this benefit release.');
    } finally {
        isSavingRelease.value = false;
    }
}

async function submitReleaseResult() {
    if (!releaseTarget.value || isRecordingRelease.value) return;

    isRecordingRelease.value = true;
    const data = new FormData();
    data.append('status', releaseResultForm.value.status);
    data.append('originals_verified', releaseResultForm.value.originals_verified ? '1' : '0');
    if (releaseResultForm.value.notes) data.append('notes', releaseResultForm.value.notes);
    if (releaseResultForm.value.receipt_proof) data.append('receipt_proof', releaseResultForm.value.receipt_proof);

    try {
        const response = await window.axios.post(
            `/provider/benefit-release-records/${releaseTarget.value.record.id}/result`,
            data,
        );
        const index = benefitReleases.value.findIndex((release) => release.id === response.data.release.id);
        if (index >= 0) benefitReleases.value.splice(index, 1, response.data.release);
        releaseTarget.value = null;
        releaseResultForm.value = defaultReleaseResultForm();
        showPortalToast({ type: 'success', title: 'Release record updated', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Release record not saved',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to save this release record.'),
        });
    } finally {
        isRecordingRelease.value = false;
    }
}

async function publishCycle() {
    if (isSaving.value) return;
    isSaving.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.post(`/provider/scholarships/${scholarshipId}/monitoring-cycles`, form.value);
        cycles.value = [response.data.cycle, ...cycles.value];
        openCycles.value = new Set([response.data.cycle.id]);
        showCycleForm.value = false;
        showPortalToast({ type: 'success', title: 'Monitoring period published', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        errorMessage.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to publish this monitoring period.');
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadMonitoring);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />
        <FilePreviewModal
            :file="previewFile"
            :title="previewFile?.original_name || 'Academic progress record'"
            :context="scholarship?.title || 'Recipient monitoring'"
            @close="previewFile = null"
        />

        <section class="provider-page">
            <div class="provider-container">
                <nav class="flex min-w-0 items-center gap-2 text-sm" aria-label="Breadcrumb">
                    <a href="/provider/programs" class="font-bold text-slate-600 transition hover:text-slate-950">Programs</a>
                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-400" aria-hidden="true"></i>
                    <a :href="`/provider/programs/${scholarshipId}`" class="truncate font-semibold text-slate-600 transition hover:text-slate-950">{{ scholarship?.title || 'Program' }}</a>
                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-400" aria-hidden="true"></i>
                    <span class="font-semibold text-slate-950">Monitoring</span>
                </nav>

                <div v-if="isLoading" class="provider-panel mt-5 p-6 text-sm text-slate-500">Loading recipient monitoring...</div>

                <template v-else-if="scholarship">
                    <TaskPageHeader
                        theme="provider"
                        eyebrow="Recipient support"
                        :title="scholarship.title"
                        description="Review ongoing requirements, benefit releases, and recipient outcomes."
                        icon="fa-solid fa-chart-line"
                    >
                        <template #meta>
                            <span>{{ scholarship.selected_recipients_count }} selected recipient{{ Number(scholarship.selected_recipients_count) === 1 ? '' : 's' }}</span>
                            <span>{{ activeSupportCount }} active support record{{ activeSupportCount === 1 ? '' : 's' }}</span>
                        </template>
                        <template #actions>
                            <button v-if="activeTab === 'monitoring'" type="button" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800" @click="openComposer">
                                <i class="fa-solid fa-plus text-xs" aria-hidden="true"></i>
                                New period
                            </button>
                            <button v-else-if="activeTab === 'releases'" type="button" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800" @click="openReleaseComposer">
                                <i class="fa-solid fa-plus text-xs" aria-hidden="true"></i>
                                Schedule release
                            </button>
                            <button v-else-if="activeTab === 'outcomes'" type="button" :disabled="!activeSupportCount" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" @click="openSupportDecision()">
                                <i class="fa-solid fa-flag-checkered text-xs" aria-hidden="true"></i>
                                Record outcome
                            </button>
                        </template>
                    </TaskPageHeader>

                    <ProviderProgramNav :program-id="scholarship.id" active="monitoring" />

                    <p v-if="errorMessage" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <div class="provider-panel mt-4 grid gap-1 p-1.5 sm:grid-cols-2 lg:grid-cols-4" aria-label="Recipient monitoring views">
                        <button
                            v-for="view in viewTabs"
                            :key="view.value"
                            type="button"
                            :aria-current="activeTab === view.value ? 'page' : undefined"
                            :class="['flex items-center justify-center gap-2 rounded-md px-3 py-2.5 text-sm font-bold transition', activeTab === view.value ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100']"
                            @click="setActiveTab(view.value)"
                        >
                            <i :class="[view.icon, 'text-xs', activeTab === view.value ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span>{{ view.label }}</span>
                            <span v-if="view.count" :class="['rounded px-1.5 py-0.5 text-[10px]', activeTab === view.value ? 'bg-white/10 text-white' : 'bg-amber-100 text-amber-800']">{{ view.count }}</span>
                        </button>
                    </div>

                    <template v-if="activeTab === 'summary'">
                        <section class="provider-panel mt-4 overflow-hidden">
                            <header class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Action queue</p><h2 class="mt-1 text-lg font-bold text-slate-950">Needs attention</h2><p class="mt-1 text-sm text-slate-500">Overdue, unreviewed, or incomplete recipient records.</p></div>
                                <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold', programSummary?.attention_count ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800']">{{ programSummary?.attention_count || 0 }} open</span>
                            </header>
                            <div v-if="!programSummary?.attention?.length" class="px-5 py-8 text-center sm:px-6"><span class="mx-auto grid h-10 w-10 place-items-center rounded-md bg-emerald-100 text-emerald-700"><i class="fa-solid fa-check" aria-hidden="true"></i></span><p class="mt-3 font-bold text-slate-950">No urgent recipient records</p><p class="mt-1 text-sm text-slate-500">Current agreements, reviews, and release results are up to date.</p></div>
                            <div v-else class="divide-y divide-slate-200">
                                <article v-for="(item, index) in programSummary.attention" :key="`${item.type}-${item.title}-${index}`" class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div class="flex min-w-0 items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i :class="['fa-solid text-xs', recordEventIcon(item.type)]" aria-hidden="true"></i></span><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-950">{{ item.title }}</h3><span class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">{{ item.type_label }}</span></div><p class="mt-1 text-sm leading-5 text-slate-600">{{ item.detail }}</p></div></div>
                                    <button type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openSummaryItem(item)">Open record <i class="fa-solid fa-arrow-right ml-1.5" aria-hidden="true"></i></button>
                                </article>
                            </div>
                        </section>

                        <section class="provider-panel mt-4 overflow-hidden">
                            <header class="border-b border-slate-200 px-5 py-4 sm:px-6"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Calendar</p><h2 class="mt-1 text-lg font-bold text-slate-950">Upcoming work</h2></header>
                            <div v-if="!programSummary?.upcoming?.length" class="px-5 py-8 text-center text-sm text-slate-500">No upcoming monitoring deadline or benefit release is currently scheduled.</div>
                            <div v-else class="divide-y divide-slate-200">
                                <article v-for="item in programSummary.upcoming" :key="`${item.type}-${item.title}-${item.date}`" class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <div class="flex min-w-0 items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i :class="['fa-solid text-xs', recordEventIcon(item.type)]" aria-hidden="true"></i></span><div><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-950">{{ item.title }}</h3><span class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">{{ item.type_label }}</span></div><p class="mt-1 text-sm text-slate-600">{{ item.detail }}</p></div></div>
                                    <div class="flex shrink-0 items-center gap-3"><span class="text-xs font-bold text-slate-600">{{ item.date_label }}</span><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openSummaryItem(item)">Open</button></div>
                                </article>
                            </div>
                        </section>

                        <section class="provider-panel mt-4 overflow-hidden">
                            <header class="border-b border-slate-200 px-5 py-4 sm:px-6"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Lifecycle</p><h2 class="mt-1 text-lg font-bold text-slate-950">Recipient outcomes</h2></header>
                            <dl class="grid gap-px bg-slate-200 sm:grid-cols-2 lg:grid-cols-4">
                                <div class="bg-white px-5 py-4"><dt class="text-xs font-bold text-slate-500">Active</dt><dd class="mt-1 text-xl font-bold text-slate-950">{{ programSummary?.outcomes?.active || 0 }}</dd></div>
                                <div class="bg-white px-5 py-4"><dt class="text-xs font-bold text-slate-500">Renewed</dt><dd class="mt-1 text-xl font-bold text-sky-800">{{ programSummary?.outcomes?.renewed || 0 }}</dd></div>
                                <div class="bg-white px-5 py-4"><dt class="text-xs font-bold text-slate-500">Completed</dt><dd class="mt-1 text-xl font-bold text-emerald-800">{{ programSummary?.outcomes?.completed || 0 }}</dd></div>
                                <div class="bg-white px-5 py-4"><dt class="text-xs font-bold text-slate-500">Ended early</dt><dd class="mt-1 text-xl font-bold text-rose-700">{{ programSummary?.outcomes?.terminated || 0 }}</dd></div>
                            </dl>
                        </section>
                    </template>

                    <template v-else-if="activeTab === 'monitoring'">
                        <section v-if="!cycles.length" class="provider-panel mt-4 px-5 py-10 text-center sm:px-6">
                            <span class="mx-auto grid h-12 w-12 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
                            <h2 class="mt-4 text-lg font-bold text-slate-950">No monitoring periods yet</h2>
                            <p class="mx-auto mt-1 max-w-xl text-sm text-slate-600">Request the next academic record from selected recipients.</p>
                            <button type="button" class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="openComposer">Create first period</button>
                        </section>

                        <section v-else class="mt-4 space-y-3">
                            <article v-for="cycle in cycles" :key="cycle.id" class="provider-panel overflow-hidden">
                                <button type="button" class="flex w-full flex-col gap-3 px-5 py-4 text-left sm:flex-row sm:items-center sm:justify-between sm:px-6" @click="toggleCycle(cycle.id)">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></span>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h2 class="font-bold text-slate-950">{{ cycle.title }}</h2>
                                                <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', cycle.status === 'open' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">{{ cycle.status }}</span>
                                            </div>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ cycle.academic_period || labelFromKey(cycle.period_type) }}<span v-if="cycle.school_year"> · {{ cycle.school_year }}</span> · Due {{ cycle.due_label }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4 sm:text-right">
                                        <div>
                                            <p class="text-sm font-bold text-slate-950">{{ cycle.submitted_count }} of {{ cycle.recipients.length }} received</p>
                                            <p :class="['text-xs', cycle.action_needed_count ? 'font-bold text-amber-700' : 'text-slate-500']">{{ cycle.action_needed_count ? `${cycle.action_needed_count} need provider review` : `${cycle.reviewed_count} ${Number(cycle.reviewed_count) === 1 ? 'review' : 'reviews'} completed` }}</p>
                                        </div>
                                        <i :class="['fa-solid fa-chevron-down text-xs text-slate-400 transition', cycleIsOpen(cycle.id) ? 'rotate-180' : '']" aria-hidden="true"></i>
                                    </div>
                                </button>

                                <div v-if="cycleIsOpen(cycle.id)" class="border-t border-slate-200">
                                    <div class="grid gap-px bg-slate-200 sm:grid-cols-3">
                                        <div class="bg-slate-50 px-5 py-3">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Academic period</p>
                                            <p class="mt-1 text-sm font-bold text-slate-950">{{ cycle.academic_period || labelFromKey(cycle.period_type) }}</p>
                                            <p v-if="cycle.school_year" class="mt-0.5 text-xs text-slate-500">School year {{ cycle.school_year }}</p>
                                        </div>
                                        <div class="bg-slate-50 px-5 py-3">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Grade requirement</p>
                                            <p class="mt-1 text-sm font-bold text-slate-950">{{ cycle.requirement_label }}</p>
                                            <p class="mt-0.5 text-xs text-slate-500">Used as a review guide</p>
                                        </div>
                                        <div class="bg-slate-50 px-5 py-3">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Submission window</p>
                                            <p class="mt-1 text-sm font-bold text-slate-950">Due {{ cycle.due_label }}</p>
                                            <p class="mt-0.5 text-xs text-slate-500">{{ cycle.pending_count }} still awaiting upload</p>
                                        </div>
                                    </div>
                                    <p v-if="cycle.instructions" class="border-t border-slate-200 px-5 py-3 text-sm leading-6 text-slate-600 sm:px-6"><strong class="text-slate-900">Recipient instructions:</strong> {{ cycle.instructions }}</p>

                                    <div class="overflow-x-auto border-t border-slate-200">
                                        <table class="w-full min-w-[820px] text-left text-sm">
                                            <colgroup>
                                                <col class="w-[28%]">
                                                <col class="w-[29%]">
                                                <col class="w-[23%]">
                                                <col class="w-[20%]">
                                            </colgroup>
                                            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">
                                                <tr>
                                                    <th class="px-5 py-3">Recipient</th>
                                                    <th class="px-4 py-3">Submitted result</th>
                                                    <th class="px-4 py-3">Provider review</th>
                                                    <th class="px-5 py-3 text-right">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200 bg-white">
                                                <tr v-for="recipient in cycle.recipients" :key="recipient.application_id">
                                                    <td class="align-top px-5 py-3.5">
                                                        <div class="flex min-h-6 flex-wrap items-center gap-2">
                                                            <p class="font-bold leading-5 text-slate-950">{{ recipient.name }}</p>
                                                            <span v-if="recipient.agreement_status !== 'accepted'" class="inline-flex rounded-md bg-amber-50 px-2 py-1 text-[10px] font-bold uppercase text-amber-800">Agreement {{ recipient.agreement_status }}</span>
                                                        </div>
                                                        <p class="mt-0.5 text-xs text-slate-500">{{ recipient.email }}</p>
                                                    </td>
                                                    <td class="align-top px-4 py-3.5">
                                                        <div class="flex min-h-6 items-center">
                                                            <p class="font-bold leading-5 text-slate-950">{{ recipient.submission?.grade_label || (recipient.submission ? 'Result needs review' : 'Not submitted') }}</p>
                                                        </div>
                                                        <p v-if="recipient.submission" class="mt-0.5 text-xs text-slate-500">
                                                            {{ recipient.submission.grade_source === 'ocr' ? 'OCR extracted' : recipient.submission.grade_source === 'applicant_manual' ? 'Applicant entered' : 'Manual check' }} · {{ comparisonLabel(recipient.submission) }}
                                                        </p>
                                                        <p v-else class="mt-0.5 text-xs text-slate-400">Waiting for academic record</p>
                                                    </td>
                                                    <td class="align-top px-4 py-3.5">
                                                        <div class="flex min-h-6 items-center">
                                                            <span v-if="recipient.submission" :class="['inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', reviewStatusClass(recipient.submission.review_status)]">{{ recipient.submission.review_status_label }}</span>
                                                            <span v-else class="text-xs font-semibold text-slate-400">Not available</span>
                                                        </div>
                                                        <p v-if="recipient.submission?.reviewed_at" class="mt-0.5 text-xs text-slate-500">Updated {{ recipient.submission.reviewed_at }}</p>
                                                        <p v-else-if="recipient.submission" class="mt-0.5 text-xs text-slate-500">Waiting for provider review</p>
                                                        <p v-else class="mt-0.5 text-xs text-slate-400">Available after upload</p>
                                                    </td>
                                                    <td class="align-top px-5 py-3.5">
                                                        <div class="flex min-h-9 items-start justify-end gap-2">
                                                            <button v-if="recipient.submission" type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openReview(cycle, recipient)">{{ recipient.submission.review_status === 'pending' ? 'Review record' : 'View review' }}</button>
                                                            <a :href="recipient.application_url" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Applicant</a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </article>
                        </section>
                    </template>

                    <template v-else-if="activeTab === 'releases'">
                        <section v-if="!benefitReleases.length" class="provider-panel mt-4 px-5 py-10 text-center sm:px-6">
                            <span class="mx-auto grid h-12 w-12 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i></span>
                            <h2 class="mt-4 text-lg font-bold text-slate-950">No benefit releases scheduled</h2>
                            <p class="mx-auto mt-1 max-w-xl text-sm text-slate-600">Schedule support for recipients with confirmed requirements.</p>
                            <button type="button" class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="openReleaseComposer">Schedule first release</button>
                        </section>

                        <section v-else class="mt-4 space-y-3">
                            <article v-for="release in benefitReleases" :key="release.id" class="provider-panel overflow-hidden">
                                <button type="button" class="flex w-full flex-col gap-3 px-5 py-4 text-left sm:flex-row sm:items-center sm:justify-between sm:px-6" @click="toggleRelease(release.id)">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2"><h2 class="font-bold text-slate-950">{{ release.title }}</h2><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', releaseStatusClass(release.status)]">{{ release.status_label }}</span></div>
                                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ release.release_label }} · {{ release.release_method_label }}<span v-if="release.location"> · {{ release.location }}</span></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4 sm:text-right">
                                        <div><p class="text-sm font-bold text-slate-950">{{ release.released_count }} of {{ release.recipient_count }} released</p><p class="text-xs text-slate-500">{{ release.pending_count }} pending<span v-if="release.exception_count"> · {{ release.exception_count }} exceptions</span></p></div>
                                        <i :class="['fa-solid fa-chevron-down text-xs text-slate-400 transition', releaseIsOpen(release.id) ? 'rotate-180' : '']" aria-hidden="true"></i>
                                    </div>
                                </button>

                                <div v-if="releaseIsOpen(release.id)" class="border-t border-slate-200">
                                    <div class="grid gap-px bg-slate-200 sm:grid-cols-3">
                                        <div class="bg-slate-50 px-5 py-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Benefit</p><p class="mt-1 text-sm font-bold text-slate-950">{{ release.benefit_description }}</p><p v-if="release.amount_label" class="mt-0.5 text-xs text-slate-500">{{ release.amount_label }}</p></div>
                                        <div class="bg-slate-50 px-5 py-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Release arrangement</p><p class="mt-1 text-sm font-bold text-slate-950">{{ release.release_method_label }}</p><p class="mt-0.5 text-xs text-slate-500">{{ release.location || 'Provider will coordinate the destination' }}</p></div>
                                        <div class="bg-slate-50 px-5 py-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Document check</p><p class="mt-1 text-sm font-bold text-slate-950">{{ release.requires_original_verification ? 'Originals required' : 'No original check at release' }}</p><p class="mt-0.5 text-xs text-slate-500">Receipt evidence is recorded per recipient.</p></div>
                                    </div>
                                    <p v-if="release.instructions" class="border-t border-slate-200 px-5 py-3 text-sm leading-6 text-slate-600 sm:px-6"><strong class="text-slate-900">Instructions:</strong> {{ release.instructions }}</p>
                                    <div class="overflow-x-auto border-t border-slate-200">
                                        <table class="w-full min-w-[820px] text-left text-sm">
                                            <colgroup>
                                                <col class="w-[28%]">
                                                <col class="w-[23%]">
                                                <col class="w-[29%]">
                                                <col class="w-[20%]">
                                            </colgroup>
                                            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">
                                                <tr>
                                                    <th class="px-5 py-3">Recipient</th>
                                                    <th class="px-4 py-3">Release status</th>
                                                    <th class="px-4 py-3">Verification</th>
                                                    <th class="px-5 py-3 text-right">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200 bg-white">
                                                <tr v-for="record in release.records" :key="record.id">
                                                    <td class="align-top px-5 py-3.5">
                                                        <div class="flex min-h-6 items-center"><p class="font-bold leading-5 text-slate-950">{{ record.name }}</p></div>
                                                        <p class="mt-0.5 text-xs text-slate-500">{{ record.email }}</p>
                                                    </td>
                                                    <td class="align-top px-4 py-3.5">
                                                        <div class="flex min-h-6 items-center"><span :class="['inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', releaseStatusClass(record.status)]">{{ record.status_label }}</span></div>
                                                        <p class="mt-0.5 text-xs text-slate-500">{{ record.recorded_at ? `Updated ${record.recorded_at}` : 'Waiting for release result' }}</p>
                                                    </td>
                                                    <td class="align-top px-4 py-3.5">
                                                        <div class="flex min-h-6 items-center">
                                                            <p :class="['text-xs font-bold', record.originals_verified ? 'text-emerald-700' : 'text-slate-700']">
                                                                {{ release.requires_original_verification ? (record.originals_verified ? 'Original records verified' : 'Original verification pending') : 'Original records not required' }}
                                                            </p>
                                                        </div>
                                                        <button v-if="record.receipt" type="button" class="mt-0.5 text-xs font-bold text-slate-700 underline decoration-slate-300 underline-offset-4" @click="previewFile = record.receipt">View receipt evidence</button>
                                                        <p v-else-if="record.notes" class="mt-0.5 text-xs text-slate-500">Release documented by provider note</p>
                                                        <p v-else class="mt-0.5 text-xs text-slate-400">No receipt evidence recorded</p>
                                                    </td>
                                                    <td class="align-top px-5 py-3.5">
                                                        <div class="flex min-h-9 items-start justify-end gap-2">
                                                            <button type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openReleaseResult(release, record)">Record result</button>
                                                            <a :href="record.application_url" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Applicant</a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </article>
                        </section>
                    </template>

                    <template v-else>
                        <section class="provider-panel mt-4 overflow-hidden">
                            <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">End-of-cycle decision</p><h2 class="mt-1 text-lg font-bold text-slate-950">Renew or close recipient support</h2></div>
                                <span class="w-fit rounded-md bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">{{ renewalReadyCount }} ready for renewal</span>
                            </header>

                            <div v-if="!supportRecipients.length" class="px-5 py-10 text-center"><p class="font-bold text-slate-950">No selected recipients yet</p><p class="mt-1 text-sm text-slate-500">Recipients appear here after accepting their scholarship offer.</p></div>
                            <div v-else class="overflow-x-auto">
                                <table class="w-full min-w-[1080px] text-left text-sm">
                                    <colgroup>
                                        <col class="w-[24%]">
                                        <col class="w-[20%]">
                                        <col class="w-[29%]">
                                        <col class="w-[27%]">
                                    </colgroup>
                                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">
                                        <tr>
                                            <th class="px-5 py-3">Recipient</th>
                                            <th class="px-4 py-3">Program progress</th>
                                            <th class="px-4 py-3">Support status</th>
                                            <th class="px-5 py-3 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 bg-white">
                                        <tr v-for="recipient in supportRecipients" :key="recipient.application_id">
                                            <td class="align-top px-5 py-3.5">
                                                <div class="flex min-h-6 items-center"><p class="font-bold leading-5 text-slate-950">{{ recipient.name }}</p></div>
                                                <p class="mt-0.5 text-xs text-slate-500">{{ recipient.email }}</p>
                                            </td>
                                            <td class="align-top px-4 py-3.5">
                                                <div class="flex min-h-6 items-center"><p class="font-bold text-slate-950">{{ recipient.requirements_met }} of {{ recipient.requirements_total }} checks confirmed</p></div>
                                                <p class="mt-0.5 text-xs text-slate-500">{{ recipient.released_count }} of {{ recipient.release_count }} releases received</p>
                                            </td>
                                            <td class="align-top px-4 py-3.5">
                                                <div class="flex min-h-6 flex-wrap items-center gap-2">
                                                    <span :class="['inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', supportStatusClass(recipient.support_status)]">{{ recipient.support_status_label }}</span>
                                                    <span :class="['text-xs font-bold', recipient.renewal_eligible ? 'text-emerald-700' : 'text-slate-500']">{{ recipient.renewal_eligibility_label }}</span>
                                                </div>
                                                <p class="mt-0.5 text-xs leading-5 text-slate-500">
                                                    {{ recipient.latest_decision ? `${recipient.latest_decision.decision_label} effective ${recipient.latest_decision.effective_label}${recipient.latest_decision.decided_by ? ` by ${recipient.latest_decision.decided_by}` : ''}` : recipient.renewal_eligibility_reason }}
                                                </p>
                                                <p v-if="recipient.latest_decision?.next_period_terms" class="mt-0.5 text-xs leading-5 text-slate-500">{{ recipient.latest_decision.next_period_terms }}</p>
                                                <p v-if="recipient.latest_decision?.reason" class="mt-0.5 text-xs leading-5 text-slate-500">{{ recipient.latest_decision.reason }}</p>
                                            </td>
                                            <td class="align-top px-5 py-3.5">
                                                <div class="flex min-h-9 flex-wrap items-start justify-end gap-2">
                                                    <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openRecipientRecord(recipient)">Recipient record</button>
                                                    <button v-if="!recipient.is_closed" type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openSupportDecision(recipient)">Record outcome</button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </template>
                </template>

                <ProviderFooter />
            </div>
        </section>

        <Teleport to="body">
            <div v-if="recipientRecordTarget" class="fixed inset-0 z-[2050] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeRecipientRecord" @keydown.esc="closeRecipientRecord">
                <section class="monitoring-modal flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="recipient-record-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Consolidated history</p>
                                <h2 id="recipient-record-title" class="mt-1 truncate text-xl font-bold text-slate-950">{{ recipientRecord?.recipient?.name || recipientRecordTarget.name }}</h2>
                                <p class="mt-1 truncate text-sm text-slate-500">{{ recipientRecord?.program?.title || scholarship?.title }}</p>
                            </div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close recipient record" @click="closeRecipientRecord"><i class="fa-solid fa-xmark"></i></button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                        <div v-if="isLoadingRecipientRecord" class="rounded-md border border-slate-200 bg-white px-5 py-12 text-center text-sm font-semibold text-slate-500">Loading recipient history...</div>
                        <div v-else-if="recipientRecordError" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ recipientRecordError }}</div>
                        <template v-else-if="recipientRecord">
                            <section class="overflow-hidden rounded-md border border-slate-200 bg-white">
                                <div class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <img v-if="recipientRecord.recipient.profile_photo_url" :src="recipientRecord.recipient.profile_photo_url" :alt="recipientRecord.recipient.name" class="h-12 w-12 shrink-0 rounded-md border border-slate-200 object-cover">
                                        <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-bold text-white">{{ recipientRecord.recipient.name.split(' ').map((part) => part[0]).slice(0, 2).join('') }}</span>
                                        <div class="min-w-0"><h3 class="truncate font-bold text-slate-950">{{ recipientRecord.recipient.name }}</h3><p class="mt-0.5 truncate text-xs text-slate-500">{{ recipientRecord.recipient.email }}</p><p class="mt-1 text-xs text-slate-500">{{ recipientRecord.program.provider_name }}</p></div>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1.5 text-[10px] font-bold uppercase', supportStatusClass(recipientRecord.support.support_status)]">{{ recipientRecord.support.support_status_label }}</span>
                                </div>
                                <dl class="grid gap-px border-t border-slate-200 bg-slate-200 sm:grid-cols-3">
                                    <div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Academic checks</dt><dd class="mt-1 text-base font-bold text-slate-950">{{ recipientRecord.summary.monitoring_confirmed }} of {{ recipientRecord.summary.monitoring_total }}</dd><p class="mt-0.5 text-xs text-slate-500">confirmed by the provider</p></div>
                                    <div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Benefit releases</dt><dd class="mt-1 text-base font-bold text-slate-950">{{ recipientRecord.summary.released_total }} of {{ recipientRecord.summary.release_total }}</dd><p class="mt-0.5 text-xs text-slate-500">recorded as received</p></div>
                                    <div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Lifecycle decisions</dt><dd class="mt-1 text-base font-bold text-slate-950">{{ recipientRecord.summary.decision_total }}</dd><p class="mt-0.5 text-xs text-slate-500">renewal or closing records</p></div>
                                </dl>
                            </section>

                            <section v-if="recipientRecord.agreement" class="mt-4 rounded-md border border-slate-200 bg-white px-4 py-4 sm:px-5">
                                <div class="flex flex-wrap items-center justify-between gap-2"><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Recipient agreement</p><h3 class="mt-1 font-bold text-slate-950">Terms recorded at selection</h3></div><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', recordStatusClass(recipientRecord.agreement.status)]">{{ recipientRecord.agreement.status_label }}</span></div>
                                <div class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                                    <div><p class="text-[10px] font-bold uppercase tracking-[0.08em] text-slate-500">Commitment</p><p class="mt-1 font-semibold leading-5 text-slate-800">{{ labelFromKey(recipientRecord.agreement.snapshot?.recipient_expectation?.commitment_type || 'provider briefing') }}</p></div>
                                    <div><p class="text-[10px] font-bold uppercase tracking-[0.08em] text-slate-500">Duration or frequency</p><p class="mt-1 leading-5 text-slate-600">{{ recipientRecord.agreement.snapshot?.recipient_expectation?.duration || 'Explained by the provider' }}</p></div>
                                    <div><p class="text-[10px] font-bold uppercase tracking-[0.08em] text-slate-500">Response recorded</p><p class="mt-1 leading-5 text-slate-600">{{ recipientRecord.agreement.responded_at || 'Awaiting recipient response' }}</p></div>
                                </div>
                            </section>

                            <section v-if="recipientRecord.support.latest_decision" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-4 sm:px-5">
                                <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Latest outcome</p><h3 class="mt-1 font-bold text-slate-950">{{ recipientRecord.support.latest_decision.decision_label }}</h3></div><p class="text-xs font-semibold text-slate-600">Effective {{ recipientRecord.support.latest_decision.effective_label }}</p></div>
                                <p v-if="recipientRecord.support.latest_decision.next_period_terms" class="mt-2 text-sm leading-6 text-slate-700">{{ recipientRecord.support.latest_decision.next_period_terms }}</p>
                                <p v-if="recipientRecord.support.latest_decision.reason" class="mt-2 text-sm leading-6 text-slate-700">{{ recipientRecord.support.latest_decision.reason }}</p>
                            </section>

                            <section class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white">
                                <header class="border-b border-slate-200 px-4 py-3.5 sm:px-5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Full record</p><h3 class="mt-1 font-bold text-slate-950">Recipient history</h3><p class="mt-1 text-xs leading-5 text-slate-500">Newest activity appears first. Uploaded evidence remains available from its related entry.</p></header>
                                <div v-if="!recipientRecord.timeline.length" class="px-5 py-8 text-center text-sm text-slate-500">No recipient history has been recorded yet.</div>
                                <div v-else class="divide-y divide-slate-200">
                                    <article v-for="(event, index) in recipientRecord.timeline" :key="`${event.type}-${index}-${event.occurred_label}`" class="flex gap-3 px-4 py-4 sm:px-5">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i :class="['fa-solid text-xs', recordEventIcon(event.type)]" aria-hidden="true"></i></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-start justify-between gap-2"><div><h4 class="text-sm font-bold text-slate-950">{{ event.title }}</h4><p v-if="event.occurred_label" class="mt-0.5 text-xs text-slate-500">{{ event.occurred_label }}</p></div><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', recordStatusClass(event.status)]">{{ event.status_label }}</span></div>
                                            <p v-if="event.description" class="mt-2 text-sm leading-6 text-slate-600">{{ event.description }}</p>
                                            <button v-if="event.file" type="button" class="mt-2 inline-flex items-center gap-1.5 text-xs font-bold text-slate-800 underline decoration-slate-300 underline-offset-4" @click="previewFile = event.file"><i class="fa-regular fa-file-lines" aria-hidden="true"></i>View {{ event.type === 'release' ? 'receipt' : 'grade record' }}</button>
                                        </div>
                                    </article>
                                </div>
                            </section>
                        </template>
                    </div>

                    <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="closeRecipientRecord">Close</button>
                        <a v-if="recipientRecord?.recipient?.application_url" :href="recipientRecord.recipient.application_url" class="rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800">Open applicant review</a>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showCycleForm" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/60 p-4" @click.self="closeComposer" @keydown.esc="closeComposer">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="monitoring-cycle-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Academic progress</p><h2 id="monitoring-cycle-title" class="mt-1 text-xl font-bold text-slate-950">New monitoring period</h2><p class="mt-1 text-sm text-slate-600">One grade record request will be sent to every selected recipient.</p></div>
                        <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="closeComposer"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="publishCycle">
                        <div class="space-y-4 px-5 py-5 sm:px-6">
                            <p v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Period title</span><input ref="titleInput" v-model="form.title" required maxlength="120" placeholder="Example: First semester grade update" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100"></label>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Period type</span><select v-model="form.period_type" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="semester">Semester</option><option value="quarter">Quarter</option><option value="monthly">Monthly</option><option value="custom">Custom period</option></select></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">School year</span><input v-model="form.school_year" maxlength="30" placeholder="2026-2027" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            </div>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Academic period label</span><input v-model="form.academic_period" maxlength="80" placeholder="Example: First semester or Quarter 2" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <div class="grid gap-4 sm:grid-cols-2"><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Opens on</span><input v-model="form.opens_at" type="date" :min="today" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Due date</span><input v-model="form.due_at" type="date" :min="form.opens_at || today" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label></div>
                            <div class="grid gap-4 sm:grid-cols-2"><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Grading scale</span><select v-model="form.grading_scale" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm" @change="form.minimum_grade = form.grading_scale === 'grade_point' ? '2.00' : '85'"><option value="percentage">Percentage / general average</option><option value="grade_point">GWA / GPA grade point</option></select></label><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">{{ form.grading_scale === 'grade_point' ? 'Maximum grade point' : 'Minimum average' }}</span><input v-model="form.minimum_grade" type="number" step="0.01" required :min="form.grading_scale === 'grade_point' ? 1 : 0" :max="form.grading_scale === 'grade_point' ? 5 : 100" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label></div>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Recipient instructions</span><textarea v-model="form.instructions" rows="4" maxlength="2000" placeholder="State which report card or grade record to upload and any reminder about bringing the original." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" :disabled="isSaving" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeComposer">Cancel</button><button type="submit" :disabled="isSaving" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isSaving ? 'Publishing...' : 'Publish period' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showReleaseForm" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/60 p-4" @click.self="closeReleaseComposer" @keydown.esc="closeReleaseComposer">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="benefit-release-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Benefit release</p><h2 id="benefit-release-title" class="mt-1 text-xl font-bold text-slate-950">Schedule recipient release</h2><p class="mt-1 text-sm text-slate-600">Only recipients with confirmed requirements can be included.</p></div>
                        <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="closeReleaseComposer"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="publishRelease">
                        <div class="space-y-4 px-5 py-5 sm:px-6">
                            <p v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Release title</span><input v-model="releaseForm.title" required maxlength="120" placeholder="Example: First semester allowance release" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Date and time</span><input v-model="releaseForm.release_at" type="datetime-local" :min="`${today}T00:00`" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Release method</span><select v-model="releaseForm.release_method" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="in_person">In person</option><option value="bank_transfer">Bank transfer</option><option value="e_wallet">E-wallet</option><option value="other">Other arrangement</option></select></label>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-[1fr_180px]">
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Benefit being released</span><input v-model="releaseForm.benefit_description" required maxlength="255" placeholder="Example: Semester allowance and school supplies" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Amount, if cash</span><input v-model="releaseForm.amount" type="number" min="0" step="0.01" placeholder="Optional" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            </div>
                            <label v-if="releaseForm.release_method === 'in_person'" class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Release location</span><input v-model="releaseForm.location" required maxlength="255" placeholder="Office, school, or release venue" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Recipient instructions</span><textarea v-model="releaseForm.instructions" rows="3" maxlength="2000" placeholder="What to bring, where to report, and who to contact." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700"><input v-model="releaseForm.requires_original_verification" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span><strong class="text-slate-950">Confirm original documents before release.</strong><br>The provider must record that originals were checked before marking the benefit released.</span></label>

                            <fieldset>
                                <legend class="text-xs font-bold text-slate-700">Recipients</legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Eligibility is based on accepted recipient terms and monitoring requirements currently due.</p>
                                <div class="mt-2 max-h-64 divide-y divide-slate-200 overflow-y-auto rounded-md border border-slate-200">
                                    <label v-for="candidate in releaseCandidates" :key="candidate.application_id" :class="['flex items-start gap-3 px-3 py-3', candidate.eligible ? 'cursor-pointer bg-white' : 'bg-slate-50 opacity-70']">
                                        <input v-model="releaseForm.recipient_ids" type="checkbox" :value="candidate.application_id" :disabled="!candidate.eligible" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950">
                                        <span class="min-w-0 flex-1"><span class="flex flex-wrap items-center justify-between gap-2"><strong class="text-sm text-slate-950">{{ candidate.name }}</strong><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', candidate.eligible ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">{{ candidate.eligibility_label }}</span></span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ candidate.eligibility_reason }}</span></span>
                                    </label>
                                </div>
                            </fieldset>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" :disabled="isSavingRelease" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeReleaseComposer">Cancel</button><button type="submit" :disabled="isSavingRelease || !releaseForm.recipient_ids.length" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isSavingRelease ? 'Scheduling...' : 'Schedule release' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="releaseTarget" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeReleaseResult" @keydown.esc="closeReleaseResult">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="release-result-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Recipient release record</p><h2 id="release-result-title" class="mt-1 text-xl font-bold text-slate-950">{{ releaseTarget.record.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ releaseTarget.release.title }} · {{ releaseTarget.release.release_label }}</p></div>
                        <button type="button" :disabled="isRecordingRelease" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="closeReleaseResult"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="submitReleaseResult">
                        <div class="space-y-4 px-5 py-5">
                            <div class="grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-2"><div class="bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Benefit</p><p class="mt-1 text-sm font-bold text-slate-950">{{ releaseTarget.release.benefit_description }}</p></div><div class="bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Current status</p><p class="mt-1 text-sm font-bold text-slate-950">{{ releaseTarget.record.status_label }}</p></div></div>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Outcome</span><select v-model="releaseResultForm.status" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="prepared">Prepared for release</option><option value="released">Released to recipient</option><option value="missed">Recipient missed schedule</option><option value="withheld">Release withheld</option></select></label>
                            <label v-if="releaseTarget.release.requires_original_verification" class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700"><input v-model="releaseResultForm.originals_verified" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span><strong class="text-slate-950">Original documents checked</strong><br>Required before this record can be marked released.</span></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Provider note</span><textarea v-model="releaseResultForm.notes" rows="4" maxlength="1500" :placeholder="releaseResultForm.status === 'released' ? 'Optional if receipt proof is uploaded; otherwise describe how receipt was acknowledged.' : 'Required for a missed or withheld release.'" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Signed receipt or acknowledgement proof</span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-md border border-slate-300 bg-white p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white" @change="releaseResultForm.receipt_proof = $event.target.files?.[0] || null"><span class="mt-1.5 block text-xs text-slate-500">PDF, JPG, JPEG, or PNG up to 5 MB. A written acknowledgement note may be used instead.</span></label>
                            <button v-if="releaseTarget.record.receipt" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="previewFile = releaseTarget.record.receipt">View existing receipt</button>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end"><button type="button" :disabled="isRecordingRelease" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeReleaseResult">Cancel</button><button type="submit" :disabled="isRecordingRelease" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isRecordingRelease ? 'Saving...' : 'Save release result' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="supportTarget" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeSupportDecision" @keydown.esc="closeSupportDecision">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="support-decision-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Recipient lifecycle</p><h2 id="support-decision-title" class="mt-1 text-xl font-bold text-slate-950">Record support outcome</h2><p class="mt-1 text-sm text-slate-500">Renew support or close the recipient record with an auditable reason.</p></div>
                        <button type="button" :disabled="isSavingSupport" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="closeSupportDecision"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="submitSupportDecision">
                        <div class="space-y-4 px-5 py-5 sm:px-6">
                            <p v-if="supportError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm font-semibold text-rose-700">{{ supportError }}</p>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Recipient</span><select v-model="supportTarget" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option v-for="recipient in supportRecipients.filter((item) => !item.is_closed)" :key="recipient.application_id" :value="recipient">{{ recipient.name }} · {{ recipient.support_status_label }}</option></select></label>

                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-bold text-slate-950">{{ supportTarget.renewal_eligibility_label }}</p><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', supportTarget.renewal_eligible ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">{{ supportTarget.renewal_eligible ? 'Renewal ready' : 'Review needed' }}</span></div>
                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ supportTarget.renewal_eligibility_reason }}</p>
                            </div>

                            <fieldset><legend class="text-xs font-bold text-slate-700">Outcome</legend><div class="mt-2 grid gap-2 sm:grid-cols-3">
                                <label :class="['cursor-pointer rounded-md border p-3', supportForm.decision === 'renewed' ? 'border-slate-950 bg-slate-50' : 'border-slate-200']"><input v-model="supportForm.decision" type="radio" value="renewed" class="sr-only"><span class="block text-sm font-bold text-slate-950">Renew support</span><span class="mt-1 block text-xs leading-5 text-slate-500">Continue for another period.</span></label>
                                <label :class="['cursor-pointer rounded-md border p-3', supportForm.decision === 'completed' ? 'border-slate-950 bg-slate-50' : 'border-slate-200']"><input v-model="supportForm.decision" type="radio" value="completed" class="sr-only"><span class="block text-sm font-bold text-slate-950">Complete program</span><span class="mt-1 block text-xs leading-5 text-slate-500">Close after normal completion.</span></label>
                                <label :class="['cursor-pointer rounded-md border p-3', supportForm.decision === 'terminated' ? 'border-rose-500 bg-rose-50' : 'border-slate-200']"><input v-model="supportForm.decision" type="radio" value="terminated" class="sr-only"><span class="block text-sm font-bold text-slate-950">End early</span><span class="mt-1 block text-xs leading-5 text-slate-500">Stop future support with reason.</span></label>
                            </div></fieldset>

                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Effective date</span><input v-model="supportForm.effective_on" type="date" :max="today" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <template v-if="supportForm.decision === 'renewed'">
                                <div class="grid gap-4 sm:grid-cols-2"><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">New support end date</span><input v-model="supportForm.support_ends_on" type="date" :min="supportForm.effective_on" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Next review date</span><input v-model="supportForm.next_review_on" type="date" :min="supportForm.effective_on" :max="supportForm.support_ends_on || undefined" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label></div>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Next-period terms</span><textarea v-model="supportForm.next_period_terms" required minlength="10" maxlength="2000" rows="4" placeholder="State the continuing requirement, review period, and support covered by this renewal." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            </template>
                            <label v-else class="block"><span class="mb-2 block text-xs font-bold text-slate-700">{{ supportForm.decision === 'completed' ? 'Completion summary' : 'Reason for ending support' }}</span><textarea v-model="supportForm.reason" required minlength="10" maxlength="2000" rows="4" :placeholder="supportForm.decision === 'completed' ? 'Summarize how the recipient completed the support period.' : 'Explain the policy or requirement involved and any discussion with the recipient.'" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>

                            <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700"><input v-model="supportForm.confirmed" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span>I reviewed the recipient's monitoring, release records, and applicable program terms before recording this outcome.</span></label>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" :disabled="isSavingSupport" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeSupportDecision">Cancel</button><button type="submit" :disabled="isSavingSupport || !supportForm.confirmed || (supportForm.decision === 'renewed' && !supportTarget.renewal_eligible)" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isSavingSupport ? 'Saving...' : 'Confirm outcome' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="reviewTarget" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeReview" @keydown.esc="closeReview">
                <section class="monitoring-modal flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="monitoring-review-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
                            <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Compliance review</p><h2 id="monitoring-review-title" class="mt-1 text-xl font-bold text-slate-950">{{ reviewTarget.recipient.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ reviewTarget.cycle.title }}</p></div>
                        </div>
                        <button type="button" :disabled="isReviewing" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100 disabled:opacity-50" aria-label="Close review" @click="closeReview"><i class="fa-solid fa-xmark"></i></button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-5">
                        <div class="grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-3">
                            <div class="bg-white p-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Submitted result</p><p class="mt-1 text-base font-bold text-slate-950">{{ reviewTarget.recipient.submission.grade_label || 'No result extracted' }}</p><p class="mt-1 text-xs text-slate-500">{{ reviewTarget.recipient.submission.grade_source === 'ocr' ? 'Extracted by OCR.space' : reviewTarget.recipient.submission.grade_source === 'applicant_manual' ? 'Entered by applicant' : 'Needs record review' }}</p></div>
                            <div class="bg-white p-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Required result</p><p class="mt-1 text-base font-bold text-slate-950">{{ reviewTarget.cycle.requirement_label }}</p><p class="mt-1 text-xs text-slate-500">Published for this period</p></div>
                            <div class="bg-white p-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">System comparison</p><span :class="['mt-1 inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', comparisonClass(reviewTarget.recipient.submission.comparison?.status)]">{{ comparisonLabel(reviewTarget.recipient.submission) }}</span><p class="mt-1 text-xs text-slate-500">Guide only; verify the record.</p></div>
                        </div>

                        <div class="mt-3 flex flex-col gap-3 rounded-md border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ reviewTarget.recipient.submission.original_name }}</p><p class="mt-1 text-xs text-slate-500">Submitted {{ reviewTarget.recipient.submission.submitted_at }}</p></div>
                            <button type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = reviewTarget.recipient.submission">View grade record</button>
                        </div>

                        <label class="mt-4 block"><span class="text-xs font-bold uppercase tracking-[0.1em] text-slate-600">Review note</span><textarea v-model="reviewNotes" rows="4" maxlength="1500" placeholder="Optional when confirming the requirement; required for other decisions." class="mt-2 w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100"></textarea></label>

                        <div v-if="reviewTarget.recipient.submission.reviews?.length" class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <div class="border-b border-slate-200 px-3 py-2.5"><p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-600">Review history</p></div>
                            <div class="divide-y divide-slate-200">
                                <div v-for="review in reviewTarget.recipient.submission.reviews" :key="review.id" class="px-3 py-3 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2"><span class="font-bold text-slate-950">{{ review.decision_label }}</span><span class="text-xs text-slate-500">{{ review.decided_at }}<span v-if="review.reviewed_by"> · {{ review.reviewed_by }}</span></span></div>
                                    <p v-if="review.notes" class="mt-1 leading-5 text-slate-600">{{ review.notes }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <footer class="border-t border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <p class="mb-3 text-xs leading-5 text-slate-500">OCR and the system comparison are guides. Choose the decision supported by the uploaded record and your program policy.</p>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <button type="button" :disabled="isReviewing" class="rounded-md bg-emerald-700 px-3 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60" @click="submitReview('met')">Requirement met</button>
                            <button type="button" :disabled="isReviewing" class="rounded-md border border-rose-200 bg-white px-3 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-60" @click="submitReview('not_met')">Not met</button>
                            <button type="button" :disabled="isReviewing" class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm font-bold text-amber-900 hover:bg-amber-100 disabled:opacity-60" @click="submitReview('needs_correction')">Request replacement</button>
                            <button type="button" :disabled="isReviewing" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2.5 text-sm font-bold text-sky-800 hover:bg-sky-100 disabled:opacity-60" @click="submitReview('excused')">Approve exception</button>
                        </div>
                    </footer>
                </section>
            </div>
        </Teleport>
    </main>
</template>

<style scoped>
.monitoring-modal :is(input:not([type='checkbox']):not([type='radio']), select, textarea) {
    background-color: #fff;
    color: #0f172a;
}

.monitoring-modal :is(input, textarea)::placeholder {
    color: #94a3b8;
    opacity: 1;
}

.monitoring-modal select option {
    background-color: #fff;
    color: #0f172a;
}
</style>
