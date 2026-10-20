<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ConfirmationDialog from '../../../components/ConfirmationDialog.vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const releaseId = document.getElementById('app')?.dataset.releaseId;
const returnTo = new URLSearchParams(window.location.search).get('return_to');
const safeReturnUrl = returnTo?.startsWith('/provider/workspaces/releases')
    ? returnTo
    : '/provider/workspaces/releases/issues';
const program = ref({});
const release = ref(null);
const isLoading = ref(true);
const actionBusy = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const recordFilter = ref('action');
const selectedRecordId = ref(null);
const confirmation = ref(null);
const resultForm = ref({ status: 'prepared', originals_verified: false, notes: '', receipt_proof: null });
const resolutionForm = ref({ resolution_outcome: 'corrected_release', resolution_notes: '', resolution_proof: null });

const records = computed(() => release.value?.records ?? []);
const selectedRecord = computed(() => records.value.find((record) => record.id === selectedRecordId.value) ?? null);
const canMarkReleased = computed(() => release.value?.release_at && new Date(release.value.release_at) <= new Date());

function recordState(record) {
    if (record.receipt_response?.response_type === 'issue' && record.receipt_response.status === 'open') return 'issue';
    if (['scheduled', 'prepared'].includes(record.status)) return 'pending';
    return 'complete';
}

const recordCounts = computed(() => records.value.reduce((counts, record) => {
    counts[recordState(record)] += 1;
    return counts;
}, { issue: 0, pending: 0, complete: 0 }));
const filteredRecords = computed(() => {
    if (recordFilter.value === 'all') return records.value;
    if (recordFilter.value === 'action') return records.value.filter((record) => ['issue', 'pending'].includes(recordState(record)));
    return records.value.filter((record) => recordState(record) === recordFilter.value);
});

function stateLabel(record) {
    if (recordState(record) === 'issue') return 'Issue reported';
    return record.status_label;
}

function stateClass(record) {
    if (recordState(record) === 'issue') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (recordState(record) === 'pending') return 'border-amber-200 bg-amber-50 text-amber-800';
    if (record.status === 'released') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    return errors ? Object.values(errors).flat()[0] : (error.response?.data?.message || fallback);
}

function selectRecord(record) {
    selectedRecordId.value = record.id;
    resultForm.value = {
        status: canMarkReleased.value ? 'released' : 'prepared',
        originals_verified: Boolean(record.originals_verified),
        notes: record.notes ?? '',
        receipt_proof: null,
    };
    resolutionForm.value = { resolution_outcome: 'corrected_release', resolution_notes: '', resolution_proof: null };
}

watch(filteredRecords, (items) => {
    if (!items.length) {
        selectedRecordId.value = null;
        return;
    }
    if (!items.some((record) => record.id === selectedRecordId.value)) selectRecord(items[0]);
});

async function loadRecord() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/workspaces/releases/distributions/${releaseId}/data`);
        program.value = response.data.program;
        release.value = response.data.release;
        const first = records.value.find((record) => ['issue', 'pending'].includes(recordState(record))) ?? records.value[0];
        if (first) selectRecord(first);
    } catch (error) {
        errorMessage.value = firstError(error, 'The benefit release record could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

function askToSaveResult() {
    confirmation.value = {
        type: 'result',
        title: 'Record distribution result',
        message: `Save ${resultForm.value.status.replaceAll('_', ' ')} for ${selectedRecord.value.name}?`,
        confirmLabel: 'Save result',
        tone: ['missed', 'withheld'].includes(resultForm.value.status) ? 'danger' : 'warning',
    };
}

function askToResolveIssue() {
    confirmation.value = {
        type: 'resolution',
        title: 'Resolve reported issue',
        message: 'This response and any attached proof will be shared with the recipient.',
        confirmLabel: 'Resolve issue',
        tone: 'warning',
    };
}

function appendFormValue(data, key, value) {
    if (value !== null && value !== undefined && value !== '') data.append(key, value);
}

async function runAction(action, fallback) {
    actionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const response = await action();
        release.value = response.data.release;
        successMessage.value = response.data.message;
        const refreshed = records.value.find((record) => record.id === selectedRecordId.value);
        if (refreshed) selectRecord(refreshed);
    } catch (error) {
        errorMessage.value = firstError(error, fallback);
    } finally {
        actionBusy.value = false;
    }
}

async function confirmAction() {
    const type = confirmation.value?.type;
    confirmation.value = null;
    if (!selectedRecord.value) return;

    if (type === 'result') {
        const data = new FormData();
        Object.entries(resultForm.value).forEach(([key, value]) => appendFormValue(data, key, key === 'originals_verified' ? (value ? '1' : '0') : value));
        await runAction(() => window.axios.post(`/provider/benefit-release-records/${selectedRecord.value.id}/result`, data), 'The release result could not be saved.');
    } else if (type === 'resolution') {
        const data = new FormData();
        Object.entries(resolutionForm.value).forEach(([key, value]) => appendFormValue(data, key, value));
        await runAction(() => window.axios.post(`/provider/benefit-receipt-responses/${selectedRecord.value.receipt_response.id}/resolve`, data), 'The recipient issue could not be resolved.');
    }
}

onMounted(loadRecord);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="release-record-title">
            <div class="mx-auto max-w-[94rem]">
                <a :href="safeReturnUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to releases</a>
                <div v-if="errorMessage" class="mb-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mb-4 border-l-4 border-emerald-500 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</div>
                <div v-if="isLoading" class="border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading benefit release...</p></div>

                <template v-else-if="release">
                    <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                        <div class="flex items-center justify-between gap-8"><div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Benefit distribution</p><h1 id="release-record-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Manage benefit release</h1><p class="mt-1 text-sm leading-6 text-slate-600">Record one recipient result or resolve one reported issue at a time.</p></div></div><span class="border border-slate-300 bg-slate-100 px-3 py-2 text-[10px] font-bold uppercase tracking-wide text-slate-700">{{ release.status_label }}</span></div>
                    </header>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-8 border-b border-slate-200 px-6 py-5"><div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">{{ program.title }}</p><h2 class="mt-1 truncate text-xl font-bold text-slate-950">{{ release.title }}</h2><p class="mt-1 text-sm text-slate-500">{{ release.benefit_description }}</p></div><div class="text-right"><p class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Scheduled release</p><p class="mt-1 text-sm font-bold text-slate-950">{{ release.release_label }}</p><p class="mt-1 text-xs text-slate-500">{{ release.release_method_label }}</p></div></div>
                        <dl class="grid grid-cols-4 bg-slate-50"><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Recipients</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.recipient_count }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Released</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.released_count }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Pending</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.pending_count }}</dd></div><div class="px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Open issues</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.open_issue_count }}</dd></div></dl>
                    </section>

                    <nav class="mt-4 flex border border-slate-200 bg-white shadow-sm" aria-label="Recipient result filters">
                        <button v-for="filter in [{ key: 'action', label: 'Needs action', count: recordCounts.issue + recordCounts.pending }, { key: 'issue', label: 'Reported issues', count: recordCounts.issue }, { key: 'pending', label: 'To record', count: recordCounts.pending }, { key: 'complete', label: 'Completed', count: recordCounts.complete }, { key: 'all', label: 'All recipients', count: records.length }]" :key="filter.key" type="button" :class="['flex h-14 flex-1 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', recordFilter === filter.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50']" @click="recordFilter = filter.key"><span>{{ filter.label }}</span><span :class="['px-2 py-0.5 text-xs', recordFilter === filter.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-600']">{{ filter.count }}</span></button>
                    </nav>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Recipient results</h2><p class="mt-1 text-sm text-slate-500">Select a recipient to record delivery or answer their report.</p></div></header>
                        <div v-if="!filteredRecords.length" class="px-6 py-14 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No recipients match this work state.</p></div>
                        <template v-else>
                            <div class="flex flex-wrap gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
                                <button v-for="record in filteredRecords" :key="record.id" type="button" :class="['flex min-w-[14rem] items-center gap-3 border px-3 py-2 text-left', selectedRecordId === record.id ? 'border-slate-950 bg-white' : 'border-slate-200 bg-slate-50 hover:border-slate-400']" @click="selectRecord(record)"><img v-if="record.profile_photo_url" :src="record.profile_photo_url" :alt="`${record.name} profile`" class="h-9 w-9 shrink-0 border border-slate-200 object-cover"><span v-else class="grid h-9 w-9 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user text-xs" aria-hidden="true"></i></span><span class="min-w-0 flex-1"><span class="block truncate text-xs font-bold text-slate-950">{{ record.name }}</span><span class="mt-0.5 block text-[10px] font-semibold text-slate-500">{{ stateLabel(record) }}</span></span></button>
                            </div>

                            <div v-if="selectedRecord" class="px-6 py-6">
                                <div class="flex items-center justify-between gap-6"><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Selected recipient</p><h3 class="mt-1 text-lg font-bold text-slate-950">{{ selectedRecord.name }}</h3><p class="mt-1 text-sm text-slate-500">{{ selectedRecord.email }}</p></div><span :class="['border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', stateClass(selectedRecord)]">{{ stateLabel(selectedRecord) }}</span></div>

                                <div v-if="selectedRecord.receipt_response?.response_type === 'issue' && selectedRecord.receipt_response.status === 'open'" class="mt-5">
                                    <div class="border-l-4 border-rose-400 bg-rose-50 px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-rose-800">{{ selectedRecord.receipt_response.issue_type_label }}</p><p class="mt-2 text-sm font-semibold leading-6 text-rose-950">{{ selectedRecord.receipt_response.issue_details }}</p><p v-if="selectedRecord.receipt_response.recipient_note" class="mt-2 text-sm text-rose-900">{{ selectedRecord.receipt_response.recipient_note }}</p><a v-if="selectedRecord.receipt_response.evidence" :href="selectedRecord.receipt_response.evidence.view_url" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-2 text-xs font-bold text-rose-950 underline">Open recipient evidence<i class="fa-solid fa-arrow-up-right-from-square text-[9px]" aria-hidden="true"></i></a></div>
                                    <form class="mt-5" @submit.prevent="askToResolveIssue"><div class="grid grid-cols-3 gap-4"><label class="text-sm font-semibold text-slate-700">Resolution<select v-model="resolutionForm.resolution_outcome" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="corrected_release">Corrected release</option><option value="replacement_scheduled">Replacement scheduled</option><option value="delivery_confirmed">Delivery confirmed</option><option value="no_change">No change</option></select></label><label class="col-span-2 text-sm font-semibold text-slate-700">Resolution note<textarea v-model="resolutionForm.resolution_notes" required minlength="5" maxlength="1500" rows="2" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal"></textarea></label><label class="col-span-3 text-sm font-semibold text-slate-700">Resolution proof <span class="font-normal text-slate-400">(optional)</span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full border border-slate-300 bg-white px-3 py-2 text-sm" @change="resolutionForm.resolution_proof = $event.target.files?.[0] || null"></label></div><button type="submit" class="mt-4 inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-40" :disabled="actionBusy"><i :class="['fa-solid', actionBusy ? 'fa-spinner fa-spin' : 'fa-circle-check']" aria-hidden="true"></i>Resolve issue</button></form>
                                </div>

                                <form v-else-if="['scheduled', 'prepared'].includes(selectedRecord.status)" class="mt-5" @submit.prevent="askToSaveResult"><div class="grid grid-cols-3 gap-4"><label class="text-sm font-semibold text-slate-700">Distribution result<select v-model="resultForm.status" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="prepared">Prepared</option><option value="released" :disabled="!canMarkReleased">Released / received</option><option value="missed">Recipient missed release</option><option value="withheld">Release withheld</option></select><span v-if="!canMarkReleased" class="mt-1 block text-xs font-normal text-slate-500">Receipt can be confirmed on or after the scheduled time.</span></label><label class="col-span-2 text-sm font-semibold text-slate-700">Record note <span class="font-normal text-slate-400">({{ ['missed', 'withheld'].includes(resultForm.status) ? 'required' : 'optional' }})</span><textarea v-model="resultForm.notes" :required="['missed', 'withheld'].includes(resultForm.status) || (resultForm.status === 'released' && !resultForm.receipt_proof)" rows="3" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal"></textarea></label><label class="col-span-3 text-sm font-semibold text-slate-700">Receipt or acknowledgement <span class="font-normal text-slate-400">(PDF or image)</span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full border border-slate-300 bg-white px-3 py-2 text-sm" @change="resultForm.receipt_proof = $event.target.files?.[0] || null"></label></div><label v-if="release.requires_original_verification" class="mt-4 flex items-start gap-3 border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700"><input v-model="resultForm.originals_verified" type="checkbox" :required="resultForm.status === 'released'" class="mt-0.5 h-4 w-4">Original documents were checked before confirming release.</label><button type="submit" class="mt-4 inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-40" :disabled="actionBusy"><i :class="['fa-solid', actionBusy ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>Save distribution result</button></form>

                                <div v-else class="mt-5 border-y border-slate-200 py-5"><dl class="grid grid-cols-4"><div class="border-r border-slate-200 px-4"><dt class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Result</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ selectedRecord.status_label }}</dd></div><div class="border-r border-slate-200 px-4"><dt class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Recorded by</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ selectedRecord.recorded_by || 'Not recorded' }}</dd></div><div class="border-r border-slate-200 px-4"><dt class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Recorded</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ selectedRecord.recorded_at || 'Not available' }}</dd></div><div class="px-4"><dt class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Receipt response</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ selectedRecord.receipt_response?.status_label || 'Awaiting recipient' }}</dd></div></dl><p v-if="selectedRecord.notes" class="mt-5 text-sm leading-6 text-slate-600">{{ selectedRecord.notes }}</p><a v-if="selectedRecord.receipt" :href="selectedRecord.receipt.view_url" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-2 border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700"><i class="fa-solid fa-eye text-[10px]" aria-hidden="true"></i>Open receipt proof</a></div>
                            </div>
                        </template>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm"><details><summary class="flex cursor-pointer list-none items-center justify-between px-6 py-5"><span class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></span><span><span class="block text-base font-bold text-slate-950">Distribution details</span><span class="mt-1 block text-sm text-slate-500">Saved benefit, method, location, and recipient instructions.</span></span></span><i class="fa-solid fa-chevron-down text-xs text-slate-400" aria-hidden="true"></i></summary><dl class="grid grid-cols-4 border-t border-slate-200 bg-slate-50"><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Benefit value</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ release.amount_label || 'Not specified' }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Method</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ release.release_method_label }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Location</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ release.location || 'Not applicable' }}</dd></div><div class="px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Instructions</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ release.instructions || 'No additional instructions' }}</dd></div></dl></details></section>
                </template>
            </div>
        </section>

        <ConfirmationDialog :open="Boolean(confirmation)" :title="confirmation?.title" :message="confirmation?.message" :confirm-label="confirmation?.confirmLabel" :tone="confirmation?.tone" @confirm="confirmAction" @cancel="confirmation = null" />
    </main>
</template>
