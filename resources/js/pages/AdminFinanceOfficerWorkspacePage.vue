<script setup>
import { computed, onMounted, ref } from 'vue';
import FinanceOfficerSidebar from '../components/FinanceOfficerSidebar.vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isReceiptsPage = window.location.pathname.endsWith('/receipts');
const usesPortalManagerWorkspace = window.location.pathname.startsWith('/admin/workspaces/portal');
const financeWorkspaceBase = usesPortalManagerWorkspace ? '/admin/workspaces/portal/finance' : '/admin/workspaces/finance';
const isLoading = ref(true);
const errorMessage = ref('');
const searchDraft = ref('');
const appliedSearch = ref('');
const period = ref(isReceiptsPage ? 'all' : 'month');
const currency = ref('PHP');
const summary = ref({ today: 0, month: 0, lifetime: 0, successful_payments: 0, pending_payments: 0, failed_payments: 0 });
const trend = ref([]);
const serviceBreakdown = ref([]);
const receipts = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const selectedReceipt = ref(null);

const periodOptions = [
    { value: 'today', label: 'Today' },
    { value: 'month', label: 'This month' },
    { value: 'year', label: 'This year' },
    { value: 'all', label: 'All time' },
];
const maxTrendAmount = computed(() => Math.max(...trend.value.map((item) => Number(item.amount)), 1));
const maxServiceAmount = computed(() => Math.max(...serviceBreakdown.value.map((item) => Number(item.amount)), 1));

function money(amount, selectedCurrency = currency.value) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: selectedCurrency || 'PHP',
        minimumFractionDigits: 2,
    }).format(Number(amount ?? 0) / 100);
}

function dateTime(value) {
    if (!value) return 'Not recorded';
    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function label(value, fallback = 'Not recorded') {
    if (!value) return fallback;
    return String(value).replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function barHeight(amount) {
    if (Number(amount) <= 0) return '4px';
    return `${Math.max(12, Math.round((Number(amount) / maxTrendAmount.value) * 100))}%`;
}

function serviceWidth(amount) {
    return `${Math.max(5, Math.round((Number(amount) / maxServiceAmount.value) * 100))}%`;
}

async function loadFinance(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/finance/data', {
            params: {
                page,
                period: period.value,
                search: isReceiptsPage && appliedSearch.value ? appliedSearch.value : undefined,
            },
        });
        summary.value = response.data.summary ?? summary.value;
        trend.value = response.data.trend ?? [];
        serviceBreakdown.value = response.data.service_breakdown ?? [];
        receipts.value = response.data.receipts ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        currency.value = response.data.currency ?? 'PHP';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load financial records.';
    } finally {
        isLoading.value = false;
    }
}

function applySearch() {
    appliedSearch.value = searchDraft.value.trim();
    loadFinance(1);
}

function clearSearch() {
    searchDraft.value = '';
    appliedSearch.value = '';
    loadFinance(1);
}

onMounted(loadFinance);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar v-if="usesPortalManagerWorkspace" />
        <FinanceOfficerSidebar v-else />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Financial oversight"
                    :title="isReceiptsPage ? 'Payment receipts' : 'Finance overview'"
                    :description="isReceiptsPage ? 'Find and inspect confirmed provider service payments.' : 'Track platform service revenue and payment health.'"
                    :icon="isReceiptsPage ? 'fa-solid fa-receipt' : 'fa-solid fa-chart-line'"
                >
                    <template #actions>
                        <a :href="isReceiptsPage ? financeWorkspaceBase : `${financeWorkspaceBase}/receipts`" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i :class="[isReceiptsPage ? 'fa-solid fa-chart-column' : 'fa-solid fa-receipt', 'mr-1.5 text-xs']" aria-hidden="true"></i>{{ isReceiptsPage ? 'View overview' : 'View receipts' }}
                        </a>
                    </template>
                </TaskPageHeader>

                <p v-if="errorMessage" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading financial records...</div>

                <div v-else-if="!isReceiptsPage" class="admin-content-stack">
                    <section class="admin-panel overflow-hidden">
                        <dl class="grid divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Received today</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ money(summary.today) }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Received this month</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ money(summary.month) }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Confirmed payments</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ summary.successful_payments }}</dd><p class="mt-1 text-xs text-slate-500">{{ summary.pending_payments }} pending &middot; {{ summary.failed_payments }} failed</p></div>
                        </dl>
                    </section>

                    <section class="admin-panel p-5 sm:p-6">
                        <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Last 7 days</p><h2 class="mt-1 text-xl font-black text-slate-950">Payment activity</h2></div><span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">Confirmed only</span></div>
                        <div class="mt-6 grid h-48 grid-cols-7 items-end gap-2 border-b border-slate-200 px-1">
                            <div v-for="item in trend" :key="item.date" class="flex h-full min-w-0 flex-col justify-end gap-2 text-center">
                                <span class="truncate text-[10px] font-bold text-slate-500">{{ money(item.amount) }}</span>
                                <span class="mx-auto w-full max-w-14 rounded-t bg-amber-400" :style="{ height: barHeight(item.amount) }"></span>
                                <span class="pb-2 text-[11px] font-bold text-slate-500">{{ item.label }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <header class="border-b border-slate-200 px-5 py-4 sm:px-6"><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Revenue sources</p><h2 class="mt-1 text-xl font-black text-slate-950">Services purchased</h2></header>
                        <div v-if="serviceBreakdown.length" class="divide-y divide-slate-200">
                            <article v-for="service in serviceBreakdown" :key="service.plan_code" class="px-5 py-4 sm:px-6">
                                <div class="flex items-center justify-between gap-4"><div class="min-w-0"><h3 class="truncate font-bold text-slate-950">{{ service.plan_name }}</h3><p class="mt-1 text-xs text-slate-500">{{ service.transactions }} confirmed payment{{ service.transactions === 1 ? '' : 's' }}</p></div><p class="shrink-0 text-lg font-black text-slate-950">{{ money(service.amount, service.currency) }}</p></div>
                                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-slate-950" :style="{ width: serviceWidth(service.amount) }"></div></div>
                            </article>
                        </div>
                        <p v-else class="p-6 text-sm text-slate-500">No confirmed service payments yet.</p>
                    </section>

                    <p class="text-xs leading-5 text-slate-500">These totals include platform provider-service payments only. Scholarship benefits and applicant awards are not platform revenue.</p>
                </div>

                <section v-else class="admin-panel mt-5 overflow-hidden">
                    <form class="grid gap-3 border-b border-slate-200 bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto]" @submit.prevent="applySearch">
                        <label class="relative"><span class="sr-only">Search receipts</span><i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchDraft" type="search" maxlength="120" placeholder="Provider, service, or receipt number" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-slate-500"></label>
                        <select v-model="period" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none" @change="loadFinance(1)"><option v-for="option in periodOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select>
                        <div class="flex gap-2"><button type="submit" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Search</button><button v-if="appliedSearch" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-600" @click="clearSearch">Clear</button></div>
                    </form>

                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3 text-xs font-semibold text-slate-500"><span>Confirmed provider-service payments</span><span>{{ pagination.total }} receipt{{ pagination.total === 1 ? '' : 's' }}</span></div>

                    <div v-if="receipts.length" class="divide-y divide-slate-200">
                        <article v-for="receipt in receipts" :key="receipt.id" class="flex flex-col gap-3 px-5 py-4 sm:px-6 lg:flex-row lg:items-center">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1"><h3 class="truncate font-mono text-xs font-bold text-slate-800">{{ receipt.receipt_number }}</h3><p class="mt-1 truncate text-sm font-bold text-slate-950">{{ receipt.service }}</p></div>
                            <div class="min-w-0 lg:w-64"><p class="truncate text-sm font-bold text-slate-950">{{ receipt.provider }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ receipt.provider_email }}</p></div>
                            <div class="lg:w-44"><p class="text-xs text-slate-500">Paid {{ dateTime(receipt.paid_at) }}</p><p class="mt-1 font-black text-slate-950">{{ money(receipt.amount, receipt.currency) }}</p></div>
                            <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="selectedReceipt = receipt">View receipt</button>
                        </article>
                    </div>
                    <div v-else class="px-6 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span><p class="mt-3 font-bold text-slate-950">No receipts found</p><p class="mt-1 text-sm text-slate-500">Try another period or search term.</p></div>

                    <nav v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3" aria-label="Receipt pagination"><button type="button" :disabled="pagination.current_page <= 1" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadFinance(pagination.current_page - 1)">Previous</button><span class="text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><button type="button" :disabled="pagination.current_page >= pagination.last_page" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadFinance(pagination.current_page + 1)">Next</button></nav>
                </section>
            </div>
        </section>

        <Teleport to="body">
            <div v-if="selectedReceipt" class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="finance-receipt-title" @click.self="selectedReceipt = null">
                <section class="flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-950 p-5 text-white"><div><p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-300">Payment receipt</p><h2 id="finance-receipt-title" class="mt-1 text-xl font-black">{{ selectedReceipt.receipt_number }}</h2></div><button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-white/20 text-slate-300 hover:bg-white/10" aria-label="Close receipt" @click="selectedReceipt = null"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                    <div class="overflow-y-auto p-5 sm:p-6">
                        <div class="border-b border-slate-200 pb-5"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Provider</p><p class="mt-1 font-black text-slate-950">{{ selectedReceipt.provider }}</p><p class="mt-1 text-sm text-slate-500">{{ selectedReceipt.provider_email }}</p></div><span :class="['rounded-md px-2.5 py-1 text-xs font-bold', selectedReceipt.livemode ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900']">{{ selectedReceipt.livemode ? 'Live payment' : 'Test payment' }}</span></div></div>
                        <dl class="grid gap-x-6 gap-y-4 py-5 sm:grid-cols-2"><div class="sm:col-span-2"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Service</dt><dd class="mt-1 font-bold text-slate-950">{{ selectedReceipt.service }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Paid on</dt><dd class="mt-1 text-sm font-semibold text-slate-800">{{ dateTime(selectedReceipt.paid_at) }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Method</dt><dd class="mt-1 text-sm font-semibold text-slate-800">{{ label(selectedReceipt.payment_method) }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Purchased by</dt><dd class="mt-1 text-sm font-semibold text-slate-800">{{ selectedReceipt.purchased_by || selectedReceipt.provider }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Service status</dt><dd class="mt-1 text-sm font-semibold text-slate-800">{{ label(selectedReceipt.fulfillment_status) }}</dd></div><div v-if="selectedReceipt.payment_id" class="sm:col-span-2"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Gateway payment ID</dt><dd class="mt-1 break-all font-mono text-xs text-slate-600">{{ selectedReceipt.payment_id }}</dd></div></dl>
                        <div class="flex items-center justify-between border-t-2 border-slate-950 pt-5"><span class="text-sm font-bold text-slate-600">Total paid</span><span class="text-2xl font-black text-slate-950">{{ money(selectedReceipt.amount, selectedReceipt.currency) }}</span></div>
                    </div>
                    <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4"><button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="selectedReceipt = null">Close receipt</button></footer>
                </section>
            </div>
        </Teleport>
    </main>
</template>
