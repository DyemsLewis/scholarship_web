<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import WithdrawalRequestDialog from '../../../components/withdrawals/WithdrawalRequestDialog.vue';
import { useWithdrawalList } from '../../../composables/useWithdrawalList.js';
import { withdrawalClass, withdrawalDate, withdrawalError, withdrawalLabel, withdrawalStatuses } from '../../../support/recipientWithdrawal.js';

const initialId = document.getElementById('app')?.dataset.withdrawalId;
const query = new URLSearchParams(window.location.search);
const status = ref(withdrawalStatuses.some((item) => item.value === query.get('status')) ? query.get('status') : 'pending');
const program = ref(query.get('program_id') || '');
const search = ref(query.get('search') || '');
const selected = ref(null);
const opening = ref(false);
const selectionError = ref('');
const success = ref('');
const { rows, programs, loading, error, pagination, load, invalidate } = useWithdrawalList({
    endpoint: () => '/provider/workspaces/recipients/withdrawals/data',
    params: () => ({ status: status.value, search: search.value.trim() || undefined, program_id: program.value || undefined }),
    fetcher: (...args) => window.axios.get(...args),
});
const range = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 requests');
function photoFallback(event) {
    if (event.currentTarget.dataset.fallback) return;
    event.currentTarget.dataset.fallback = 'true';
    event.currentTarget.src = '/images/demo/default-applicant-avatar.jpg';
}
let timer;
let openVersion = 0;
async function openRequest(row) {
    if (opening.value || selected.value) return;
    const version = ++openVersion;
    opening.value = true; selectionError.value = '';
    try {
        const response = await window.axios.get(row.read_url, { portalToast: false });
        if (version === openVersion) selected.value = response.data.request;
    } catch (failure) { if (version === openVersion) selectionError.value = withdrawalError(failure, 'This request could not be opened. Reload the queue and try again.'); }
    finally { if (version === openVersion) opening.value = false; }
}
function close() {
    selected.value = null;
    if (initialId) window.history.replaceState({}, '', '/provider/workspaces/recipients/withdrawals' + window.location.search);
}
async function saved(data) { close(); success.value = data.message; await load(pagination.value.current_page); if (!rows.value.length && pagination.value.current_page > 1) await load(pagination.value.current_page - 1); }
function refreshed(row) { rows.value = rows.value.map((item) => item.id === row.id ? row : item); }
watch([status, program], () => { window.clearTimeout(timer); load(); });
watch(search, () => { invalidate(); loading.value = true; window.clearTimeout(timer); timer = window.setTimeout(() => load(), 300); });
onMounted(async () => { await load(); if (initialId) await openRequest({ read_url: `/provider/recipient-withdrawals/${initialId}` }); });
onBeforeUnmount(() => { invalidate(); openVersion++; window.clearTimeout(timer); });
</script>

<template>
    <main class="student-shell"><ProviderSidebar /><section class="student-page" aria-labelledby="withdrawal-queue-title"><div class="provider-application-workspace mx-auto max-w-[94rem]">
        <header class="flex items-center justify-between gap-6 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm"><div class="flex items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i></span><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Recipient management</p><h1 id="withdrawal-queue-title" class="mt-1 font-display text-2xl font-bold text-slate-950">Withdrawal requests</h1><p class="mt-2 text-sm text-slate-600">Review requests to leave an accepted scholarship. Pending requests do not pause support.</p></div></div><a href="/provider/workspaces/recipients/active" class="inline-flex min-h-10 shrink-0 items-center gap-2 border border-slate-300 px-4 text-sm font-bold text-slate-700">Active recipients<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></header>
        <p v-if="success" role="status" class="mt-4 border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm text-emerald-900">{{ success }}</p>
        <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Withdrawal request queue">
            <div class="grid grid-cols-[minmax(0,1fr)_16rem_14rem] gap-3 border-b border-slate-200 p-4"><label><span class="mb-1.5 block text-xs font-bold text-slate-600">Find a request</span><input v-model="search" type="search" maxlength="160" placeholder="Applicant or program name" class="h-11 w-full border border-slate-300 px-3 text-sm"></label><label><span class="mb-1.5 block text-xs font-bold text-slate-600">Program</span><select v-model="program" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="">All assigned programs</option><option v-for="item in programs" :key="item.id" :value="item.id">{{ item.title }}</option></select></label><label><span class="mb-1.5 block text-xs font-bold text-slate-600">Request status</span><select v-model="status" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option v-for="item in withdrawalStatuses" :key="item.value" :value="item.value">{{ item.label }}</option></select></label></div>
            <div v-if="error || selectionError" role="alert" class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-950">{{ error || selectionError }} <button type="button" :disabled="loading" class="ml-2 font-bold underline" @click="selectionError = ''; load()">Reload queue</button></div>
            <div class="staff-table-viewport" :aria-busy="loading || opening">
                <p v-if="loading" role="status" class="py-20 text-center text-sm text-slate-500">Loading withdrawal requests...</p>
                <div v-else-if="!rows.length && !error" class="px-6 py-20 text-center"><span class="mx-auto grid h-10 w-10 place-items-center bg-slate-100 text-slate-500"><i class="fa-regular fa-folder-open" aria-hidden="true"></i></span><h2 class="mt-3 text-base font-bold">No {{ status === 'pending' ? 'pending ' : '' }}requests found</h2><p class="mt-2 text-sm text-slate-500">{{ search || program ? 'Try a different name or program filter.' : 'Requests appear here when an accepted recipient asks to leave.' }}</p></div>
                <table v-else-if="!error" class="w-full min-w-[60rem] table-fixed text-left"><colgroup><col><col class="w-44"><col class="w-48"><col class="w-32"></colgroup><thead class="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-[0.14em] text-slate-600"><tr><th class="px-5 py-3">Applicant and program</th><th class="px-5 py-3">Requested</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="row in rows" :key="row.id" class="hover:bg-slate-50"><td class="px-5 py-4"><div class="flex items-center gap-3"><img :src="row.applicant?.profile_photo_url || '/images/demo/default-applicant-avatar.jpg'" :alt="row.applicant?.name || 'Applicant'" class="h-11 w-11 shrink-0 border border-slate-200 object-cover" @error="photoFallback"><div class="min-w-0"><p class="break-words text-sm font-bold text-slate-950">{{ row.applicant?.name }}</p><p class="mt-1 break-words text-xs text-slate-500">{{ row.program?.title }}</p></div></div></td><td class="px-5 py-4 text-xs leading-5 text-slate-600">{{ withdrawalDate(row.requested_at) }}</td><td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-xs font-bold', withdrawalClass(row.status)]">{{ withdrawalLabel(row.status) }}</span></td><td class="px-5 py-4 text-right"><button type="button" :disabled="opening" class="inline-flex min-h-9 items-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white disabled:opacity-40" @click="openRequest(row)">{{ row.can_review ? 'Review' : 'View' }}<i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></button></td></tr></tbody></table>
            </div>
            <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs"><span class="font-semibold text-slate-500">{{ range }}<span v-if="opening" role="status"> &middot; Opening request...</span></span><div class="flex gap-2"><button type="button" :disabled="loading || pagination.current_page <= 1" class="min-h-9 border border-slate-300 bg-white px-3 font-bold disabled:opacity-40" @click="load(pagination.current_page - 1)">Previous</button><button type="button" :disabled="loading || pagination.current_page >= pagination.last_page" class="min-h-9 border border-slate-300 bg-white px-3 font-bold disabled:opacity-40" @click="load(pagination.current_page + 1)">Next</button></div></footer>
        </section>
        <WithdrawalRequestDialog :open="!!selected" :request="selected" :mode="selected?.can_review ? 'review' : 'view'" @close="close" @saved="saved" @refreshed="refreshed" />
    </div></section></main>
</template>
