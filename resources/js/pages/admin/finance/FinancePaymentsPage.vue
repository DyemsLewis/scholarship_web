<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { createStaffQueueState } from '../../../support/staffQueueState.js';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const queueState = createStaffQueueState(window, ['search','period']);
const search = ref(queueState.get('search') ?? '');
const period = ref(['today', 'month', 'year', 'all'].includes(queueState.get('period')) ? queueState.get('period') : 'month');
const resource = useAdminResource(async ({ signal }, params) => {
    const response = await queueState.load((query) => window.axios.get('/admin/workspaces/finance/payments/data', { params: query, signal }), params, () => !signal.aborted);
    return response.data;
}, { failureMessage: 'Confirmed payments could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const items = computed(() => resource.data.value?.items ?? []);
const pagination = computed(() => resource.data.value?.pagination ?? { current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => loading.value ? 'Updating results...' : errorMessage.value ? 'Results unavailable' : pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 payments');

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format(Number(amount ?? 0) / 100);
}

function paymentMethod(value) {
    return { gcash: 'GCash', paymaya: 'Maya', grab_pay: 'GrabPay' }[value] || (value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not recorded');
}

function loadPayments(page = 1) {
    clearTimeout(searchTimer);
    return resource.load({ page, per_page: 15, period: period.value, search: search.value.trim() || undefined });
}

function changePage(page) {
    if (!loading.value && !errorMessage.value && page >= 1 && page <= pagination.value.last_page && page !== pagination.value.current_page) loadPayments(page);
}

watch(search, () => {
    resource.invalidate();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadPayments(1), 250);
}, { flush: 'sync' });
watch(period, () => {
    resource.invalidate();
    loadPayments(1);
}, { flush: 'sync' });
onBeforeUnmount(() => clearTimeout(searchTimer));
onMounted(() => loadPayments(queueState.page));
</script>

<template>
    <AdminWorkspacePage workspace="finance_officer" class="admin-finance-workspace">
        <AdminWorkspaceHeader
            eyebrow="Payment records"
            title="Confirmed payments"
            description="Find a confirmed provider service payment and open its receipt."
            icon="fa-solid fa-receipt"
        />

        <AdminTableFrame title="Payment records" :description="resultLabel" icon="fa-solid fa-receipt" class="mt-4" :loading="loading" :error="errorMessage" @retry="resource.retry">
            <template #filters>
                <div class="grid grid-cols-[minmax(24rem,1fr)_14rem] gap-3">
                    <label class="relative block">
                        <span class="sr-only">Search confirmed payments</span>
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                        <input v-model="search" type="search" maxlength="120" placeholder="Search provider, service, receipt, or payment ID" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950">
                    </label>
                    <label>
                        <span class="sr-only">Payment period</span>
                        <select v-model="period" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950">
                            <option value="today">Today</option>
                            <option value="month">This month</option>
                            <option value="year">This year</option>
                            <option value="all">All time</option>
                        </select>
                    </label>
                </div>
            </template>

            <table v-if="items.length" class="portal-data-table admin-ledger-table">
                <colgroup><col style="width: 15%"><col style="width: 31%"><col style="width: 16%"><col style="width: 17%"><col style="width: 11%"><col style="width: 10%"></colgroup>
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">
                    <tr><th class="border-b border-slate-200 px-5 py-3">Receipt</th><th class="border-b border-slate-200 px-4 py-3">Provider</th><th class="border-b border-slate-200 px-4 py-3">Service</th><th class="border-b border-slate-200 px-4 py-3">Payment</th><th class="border-b border-slate-200 px-4 py-3">Amount</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3.5"><p class="font-mono text-xs font-bold text-slate-900">{{ item.receipt_number }}</p><p class="mt-1 text-xs text-slate-500">{{ item.livemode ? 'Live payment' : 'Test payment' }}</p></td>
                        <td class="px-4 py-3.5"><div class="flex min-w-0 items-center gap-3"><StaffTableIdentity kind="provider" :src="item.provider_logo_url" class="h-10 w-10" /><span class="min-w-0"><span class="block break-words text-sm font-bold text-slate-900">{{ item.provider }}</span><span class="mt-0.5 block break-words text-xs text-slate-500">{{ item.provider_email }}</span></span></div></td>
                        <td class="px-4 py-3.5 text-sm font-semibold text-slate-700">{{ item.service }}</td>
                        <td class="px-4 py-3.5"><p class="text-sm font-semibold text-slate-700">{{ paymentMethod(item.payment_method) }}</p><p class="mt-0.5 text-xs text-slate-500">{{ item.paid_at }}</p></td>
                        <td class="px-4 py-3.5 text-sm font-black text-slate-950">{{ money(item.amount, item.currency) }}</td>
                        <td class="px-5 py-3.5 text-right"><a :href="item.receipt_url" class="staff-row-action h-10 w-10" :aria-label="`Open receipt ${item.receipt_number}`" title="Open receipt"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else title="No confirmed payments found" description="Try another period or search term." icon="fa-solid fa-receipt" />

            <template v-if="pagination.last_page > 1" #footer>
                <div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || errorMessage || pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || errorMessage || pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div>
            </template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
