<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import WithdrawalRequestDialog from './WithdrawalRequestDialog.vue';
import { useWithdrawalList } from '../../composables/useWithdrawalList.js';
import { withdrawalClass, withdrawalDate, withdrawalLabel } from '../../support/recipientWithdrawal.js';

const props = defineProps({ historyUrl: { type: String, required: true }, context: String, agreement: Object, reviewer: Boolean });
const emit = defineEmits(['saved', 'pending-change']);
const root = ref(null);
const open = ref(false);
const mode = ref('view');
const selected = ref(null);
const latest = ref(null);
const historyOpen = ref(false);
const success = ref('');
const { rows, canRequest, loading, error, pagination, load, invalidate } = useWithdrawalList({
    endpoint: () => props.historyUrl, fetcher: (...args) => window.axios.get(...args),
});
const pending = computed(() => latest.value?.status === 'pending');
async function reload(page = 1) {
    const okay = await load(page);
    if (okay && page === 1) { latest.value = rows.value[0] ?? null; emit('pending-change', pending.value); }
    return okay;
}
watch(() => props.historyUrl, async () => {
    latest.value = null;
    await reload();
    if (window.location.hash === '#scholarship-withdrawal') {
        await nextTick();
        root.value?.scrollIntoView({ block: 'start' });
        root.value?.focus({ preventScroll: true });
    }
}, { immediate: true });
function show(item, intent = 'view') { selected.value = item; mode.value = intent; open.value = true; }
async function saved(data) {
    open.value = false;
    success.value = data.message;
    await reload();
    emit('saved', data);
}
function refreshed(item) {
    selected.value = item;
    if (latest.value?.id === item.id) latest.value = item;
    rows.value = rows.value.map((row) => row.id === item.id ? item : row);
    emit('pending-change', pending.value);
}
onBeforeUnmount(invalidate);
</script>

<template>
    <section id="scholarship-withdrawal" ref="root" tabindex="-1" data-withdrawal-panel class="scroll-mt-5 overflow-hidden rounded-sm border border-slate-300 bg-white shadow-sm" aria-label="Scholarship withdrawal">
        <header class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="flex min-w-0 items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i></span><div><h2 class="text-base font-bold text-slate-950">Scholarship withdrawal</h2><p class="mt-1 text-sm leading-5 text-slate-600">{{ reviewer ? 'Applicant-initiated requests to end accepted support.' : 'Need to leave your accepted scholarship? Ask the provider to review your request.' }}</p></div></div>
            <button v-if="!reviewer && canRequest && !error" type="button" :disabled="loading" class="min-h-10 shrink-0 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-40" @click="show(null, 'create')">Request withdrawal</button>
        </header>
        <p v-if="success" role="status" class="border-t border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-900">{{ success }}</p>
        <div v-if="error" role="alert" class="border-t border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-950">{{ error }} <button type="button" :disabled="loading" class="ml-2 font-bold underline" @click="reload()">Try again</button></div>
        <p v-else-if="loading" role="status" class="border-t border-slate-200 px-5 py-4 text-sm text-slate-500">Loading withdrawal requests...</p>
        <template v-else>
            <div v-if="latest" class="flex flex-col gap-3 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div class="min-w-0"><span :class="['inline-flex border px-2.5 py-1 text-xs font-bold', withdrawalClass(latest.status)]">{{ withdrawalLabel(latest.status) }}</span><p class="mt-2 text-sm text-slate-600">{{ pending ? 'Support remains active while the provider reviews this request.' : latest.status === 'approved' ? 'Future support has ended. Earlier award and release records are retained.' : 'This request is closed. It did not end scholarship support.' }}</p><p class="mt-1 text-xs text-slate-500">{{ withdrawalDate(latest.resolved_at || latest.requested_at) }}</p></div>
                <button type="button" class="min-h-10 shrink-0 bg-slate-950 px-4 text-sm font-bold text-white" @click="show(latest, reviewer && latest.can_review ? 'review' : 'view')">{{ reviewer && latest.can_review ? 'Review request' : 'View request' }}</button>
            </div>
            <p v-else class="border-t border-slate-200 px-5 py-3 text-sm text-slate-500">{{ reviewer ? 'No withdrawal request is recorded for this recipient.' : canRequest ? 'Sending a request does not immediately cancel your scholarship.' : 'There is no withdrawal request for this award. Requests are available only while accepted support is active.' }}</p>
        </template>
        <details v-if="pagination.total > 1 || historyOpen" class="border-t border-slate-200 px-4 py-3 sm:px-5" @toggle="historyOpen = $event.currentTarget.open">
            <summary class="cursor-pointer text-sm font-bold text-slate-600">Earlier requests</summary>
            <div class="mt-3 divide-y divide-slate-200"><div v-for="row in rows" :key="row.id" class="flex items-center justify-between gap-3 py-3"><div><p class="text-sm font-bold text-slate-800">{{ withdrawalLabel(row.status) }}</p><p class="mt-1 text-xs text-slate-500">{{ withdrawalDate(row.requested_at) }}</p></div><button type="button" class="min-h-9 border border-slate-300 px-3 text-xs font-bold" @click="show(row)">View</button></div></div>
            <div v-if="pagination.last_page > 1" class="mt-3 flex items-center justify-between gap-2 text-xs"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" :disabled="loading || pagination.current_page <= 1" class="border px-3 py-2 disabled:opacity-40" @click="reload(pagination.current_page - 1)">Previous</button><button type="button" :disabled="loading || pagination.current_page >= pagination.last_page" class="border px-3 py-2 disabled:opacity-40" @click="reload(pagination.current_page + 1)">Next</button></div></div>
        </details>
        <WithdrawalRequestDialog :open="open" :mode="mode" :request="selected" :endpoint="!reviewer && canRequest ? historyUrl : ''" :context="context" :agreement="agreement" @close="open = false" @saved="saved" @refreshed="refreshed" @reload="open = false; reload()" />
    </section>
</template>
