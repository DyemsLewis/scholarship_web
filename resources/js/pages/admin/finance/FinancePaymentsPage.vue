<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const query = new URLSearchParams(window.location.search);
const loading = ref(true);
const errorMessage = ref('');
const search = ref(query.get('search') ?? '');
const period = ref(['today', 'month', 'year', 'all'].includes(query.get('period')) ? query.get('period') : 'month');
const items = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 payments');

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format(Number(amount ?? 0) / 100);
}

function paymentMethod(value) {
    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not recorded';
}

async function loadPayments(page = 1) {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/admin/workspaces/finance/payments/data', {
            params: { page, per_page: 15, period: period.value, search: search.value.trim() || undefined },
        });
        items.value = response.data.items ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Confirmed payments could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    if (page >= 1 && page <= pagination.value.last_page && page !== pagination.value.current_page) loadPayments(page);
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadPayments(1), 250);
});
watch(period, () => loadPayments(1));
onMounted(loadPayments);
</script>

<template>
    <AdminWorkspacePage workspace="finance_officer">
        <AdminWorkspaceHeader
            eyebrow="Payment records"
            title="Confirmed payments"
            description="Find a completed provider service payment and open its receipt."
            icon="fa-solid fa-receipt"
        />

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <AdminTableFrame title="Payment records" :description="resultLabel" icon="fa-solid fa-receipt" class="mt-4">
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

            <div v-if="loading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading confirmed payments...</div>
            <table v-else-if="items.length" class="portal-data-table min-w-[68rem]">
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">
                    <tr><th class="border-b border-slate-200 px-5 py-3">Receipt</th><th class="border-b border-slate-200 px-4 py-3">Provider</th><th class="border-b border-slate-200 px-4 py-3">Service</th><th class="border-b border-slate-200 px-4 py-3">Payment</th><th class="border-b border-slate-200 px-4 py-3">Amount</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3.5"><p class="font-mono text-xs font-bold text-slate-900">{{ item.receipt_number }}</p><p class="mt-1 text-xs text-slate-500">{{ item.livemode ? 'Live payment' : 'Test payment' }}</p></td>
                        <td class="px-4 py-3.5"><div class="flex min-w-0 items-center gap-3"><span class="relative grid h-10 w-10 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-building-columns" aria-hidden="true"></i><img v-if="item.provider_logo_url" :src="item.provider_logo_url" :alt="`${item.provider} logo`" class="absolute inset-0 h-full w-full bg-white object-contain p-1" @error="$event.currentTarget.remove()"></span><span class="min-w-0"><span class="block max-w-xs truncate text-sm font-bold text-slate-900">{{ item.provider }}</span><span class="mt-0.5 block max-w-xs truncate text-xs text-slate-500">{{ item.provider_email }}</span></span></div></td>
                        <td class="px-4 py-3.5 text-sm font-semibold text-slate-700">{{ item.service }}</td>
                        <td class="px-4 py-3.5"><p class="text-sm font-semibold text-slate-700">{{ paymentMethod(item.payment_method) }}</p><p class="mt-0.5 text-xs text-slate-500">{{ item.paid_at }}</p></td>
                        <td class="px-4 py-3.5 text-sm font-black text-slate-950">{{ money(item.amount, item.currency) }}</td>
                        <td class="px-5 py-3.5 text-right"><a :href="item.receipt_url" class="inline-grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open receipt ${item.receipt_number}`" title="Open receipt"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else title="No confirmed payments found" description="Try another period or search term." icon="fa-solid fa-receipt" />

            <template v-if="pagination.last_page > 1" #footer>
                <div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div>
            </template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
