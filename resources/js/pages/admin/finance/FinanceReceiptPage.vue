<script setup>
import { onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const purchaseId = document.getElementById('app')?.dataset.purchaseId;
const loading = ref(true);
const errorMessage = ref('');
const receipt = ref(null);

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format(Number(amount ?? 0) / 100);
}

function label(value, fallback = 'Not recorded') {
    if (!value) return fallback;
    return String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadReceipt() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/admin/workspaces/finance/receipts/${purchaseId}/data`);
        receipt.value = response.data.receipt ?? null;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The payment receipt could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function printReceipt() {
    window.print();
}

onMounted(loadReceipt);
</script>

<template>
    <AdminWorkspacePage workspace="finance_officer">
        <a href="/admin/workspaces/finance/payments" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to confirmed payments</a>

        <AdminWorkspaceHeader
            eyebrow="Payment record"
            title="Payment receipt"
            :description="receipt?.receipt_number || 'Confirmed provider service payment.'"
            icon="fa-solid fa-receipt"
        >
            <template v-if="receipt" #actions>
                <button type="button" class="inline-flex h-11 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950" @click="printReceipt"><i class="fa-solid fa-print text-xs" aria-hidden="true"></i>Print receipt</button>
            </template>
        </AdminWorkspaceHeader>

        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading payment receipt...</div>
        <p v-else-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <section v-else-if="receipt" class="admin-panel mt-4 overflow-hidden">
            <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-5">
                <div class="flex min-w-0 items-center gap-4"><span class="relative grid h-12 w-12 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-building-columns" aria-hidden="true"></i><img v-if="receipt.provider_logo_url" :src="receipt.provider_logo_url" :alt="`${receipt.provider} logo`" class="absolute inset-0 h-full w-full bg-white object-contain p-1" @error="$event.currentTarget.remove()"></span><div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Provider</p><h2 class="mt-1 truncate text-lg font-black text-slate-950">{{ receipt.provider }}</h2><p class="mt-1 truncate text-sm text-slate-500">{{ receipt.provider_email }}</p></div></div>
                <span :class="['shrink-0 px-3 py-2 text-xs font-bold uppercase tracking-wide', receipt.livemode ? 'bg-slate-900 text-white' : 'bg-amber-100 text-amber-900']">{{ receipt.livemode ? 'Live payment' : 'Test payment' }}</span>
            </header>

            <dl class="grid grid-cols-4 divide-x divide-slate-200 border-b border-slate-200 bg-slate-50">
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Amount paid</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ money(receipt.amount, receipt.currency) }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Paid on</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ receipt.paid_at }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Payment method</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ label(receipt.payment_method) }}</dd></div>
                <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Service status</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ label(receipt.fulfillment_status) }}</dd></div>
            </dl>

            <div class="divide-y divide-slate-200">
                <div class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 px-6 py-4"><dt class="text-sm font-semibold text-slate-500">Service purchased</dt><dd class="text-sm font-bold text-slate-950">{{ receipt.service }}</dd></div>
                <div class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 px-6 py-4"><dt class="text-sm font-semibold text-slate-500">Purchased by</dt><dd><p class="text-sm font-bold text-slate-950">{{ receipt.purchased_by || receipt.provider }}</p><p class="mt-0.5 text-xs text-slate-500">{{ receipt.purchased_by_email || receipt.provider_email }}</p></dd></div>
                <div class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 px-6 py-4"><dt class="text-sm font-semibold text-slate-500">Terms accepted</dt><dd class="text-sm font-bold text-slate-900">{{ receipt.service_terms_accepted_at || 'Not recorded' }}</dd></div>
                <div class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 px-6 py-4"><dt class="text-sm font-semibold text-slate-500">Record created</dt><dd class="text-sm font-bold text-slate-900">{{ receipt.created_at }}</dd></div>
            </div>

            <details class="group border-t border-slate-200">
                <summary class="flex cursor-pointer list-none items-center justify-between px-6 py-4 text-sm font-bold text-slate-800 hover:bg-slate-50">
                    <span class="flex items-center gap-3"><span class="grid h-8 w-8 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-fingerprint text-xs" aria-hidden="true"></i></span>Gateway references</span>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                </summary>
                <dl class="divide-y divide-slate-200 border-t border-slate-200 bg-slate-50">
                    <div class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 px-6 py-4"><dt class="text-sm font-semibold text-slate-500">Gateway payment ID</dt><dd class="break-all font-mono text-xs font-bold text-slate-800">{{ receipt.payment_id || 'Not recorded' }}</dd></div>
                    <div class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 px-6 py-4"><dt class="text-sm font-semibold text-slate-500">Payment intent</dt><dd class="break-all font-mono text-xs font-bold text-slate-800">{{ receipt.payment_intent_id || 'Not recorded' }}</dd></div>
                    <div class="grid grid-cols-[15rem_minmax(0,1fr)] gap-6 px-6 py-4"><dt class="text-sm font-semibold text-slate-500">Checkout session</dt><dd class="break-all font-mono text-xs font-bold text-slate-800">{{ receipt.checkout_session_id || 'Not recorded' }}</dd></div>
                </dl>
            </details>
        </section>
    </AdminWorkspacePage>
</template>
