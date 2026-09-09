<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSectionNav from '../components/AdminSectionNav.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const search = ref('');
const period = ref('all');
const summary = ref({
    today: 0,
    month: 0,
    lifetime: 0,
    successful_payments: 0,
    pending_payments: 0,
    failed_payments: 0,
});
const trend = ref([]);
const serviceBreakdown = ref([]);
const receipts = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const currency = ref('PHP');
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
    if (!value) {
        return 'Not recorded';
    }

    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function label(value, fallback = 'Not recorded') {
    if (!value) {
        return fallback;
    }

    return String(value).replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function barHeight(amount) {
    if (Number(amount) <= 0) {
        return '4px';
    }

    return `${Math.max(12, Math.round((Number(amount) / maxTrendAmount.value) * 100))}%`;
}

function serviceWidth(amount) {
    return `${Math.max(5, Math.round((Number(amount) / maxServiceAmount.value) * 100))}%`;
}

async function loadFinance(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/finance/data', {
            params: {
                page,
                period: period.value,
                search: search.value || undefined,
            },
        });
        summary.value = response.data.summary ?? summary.value;
        trend.value = response.data.trend ?? [];
        serviceBreakdown.value = response.data.service_breakdown ?? [];
        receipts.value = response.data.receipts ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        currency.value = response.data.currency ?? 'PHP';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load platform finance records.';
    } finally {
        isLoading.value = false;
    }
}

function changePeriod() {
    loadFinance(1);
}

onMounted(() => loadFinance());
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="finance" />

        <section class="admin-page">
            <div class="admin-container">
                <header class="admin-hero">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Financial oversight</p>
                    <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">Platform finance</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        Monitor confirmed provider service payments and inspect their transaction receipts. Scholarship awards are not included.
                    </p>
                </header>

                <AdminSectionNav section="operations" />

                <div v-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-if="!isLoading || receipts.length">
                    <section class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article class="admin-panel border-l-4 border-l-amber-400 p-5">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Today</p>
                            <p class="mt-3 text-2xl font-black text-slate-950">{{ money(summary.today) }}</p>
                            <p class="mt-1 text-xs text-slate-500">Confirmed since midnight</p>
                        </article>
                        <article class="admin-panel border-l-4 border-l-slate-900 p-5">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">This month</p>
                            <p class="mt-3 text-2xl font-black text-slate-950">{{ money(summary.month) }}</p>
                            <p class="mt-1 text-xs text-slate-500">Confirmed this calendar month</p>
                        </article>
                        <article class="admin-panel border-l-4 border-l-sky-600 p-5">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Lifetime total</p>
                            <p class="mt-3 text-2xl font-black text-slate-950">{{ money(summary.lifetime) }}</p>
                            <p class="mt-1 text-xs text-slate-500">All confirmed service payments</p>
                        </article>
                        <article class="admin-panel border-l-4 border-l-emerald-600 p-5">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Successful payments</p>
                            <p class="mt-3 text-2xl font-black text-slate-950">{{ summary.successful_payments }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ summary.pending_payments }} pending, {{ summary.failed_payments }} failed</p>
                        </article>
                    </section>

                    <section class="mt-4 grid gap-4 xl:grid-cols-[1.15fr_0.85fr]">
                        <article class="admin-panel p-5 sm:p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Last 7 days</p>
                                    <h2 class="mt-1 text-lg font-black text-slate-950">Payment activity</h2>
                                </div>
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">Paid only</span>
                            </div>
                            <div class="mt-6 grid h-40 grid-cols-7 items-end gap-2 border-b border-slate-200 px-1">
                                <div v-for="item in trend" :key="item.date" class="flex h-full min-w-0 flex-col justify-end gap-2 text-center">
                                    <span class="truncate text-[10px] font-bold text-slate-500">{{ money(item.amount) }}</span>
                                    <span class="mx-auto w-full max-w-10 rounded-t bg-amber-400" :style="{ height: barHeight(item.amount) }"></span>
                                    <span class="pb-2 text-[11px] font-bold text-slate-500">{{ item.label }}</span>
                                </div>
                            </div>
                        </article>

                        <article class="admin-panel p-5 sm:p-6">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Revenue sources</p>
                            <h2 class="mt-1 text-lg font-black text-slate-950">Services purchased</h2>
                            <div v-if="serviceBreakdown.length" class="mt-5 space-y-4">
                                <div v-for="service in serviceBreakdown" :key="service.plan_code">
                                    <div class="flex items-start justify-between gap-3 text-sm">
                                        <div class="min-w-0">
                                            <p class="truncate font-bold text-slate-900">{{ service.plan_name }}</p>
                                            <p class="mt-0.5 text-xs text-slate-500">{{ service.transactions }} payment{{ service.transactions === 1 ? '' : 's' }}</p>
                                        </div>
                                        <span class="shrink-0 font-black text-slate-900">{{ money(service.amount, service.currency) }}</span>
                                    </div>
                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-slate-900" :style="{ width: serviceWidth(service.amount) }"></div>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="mt-5 rounded-md bg-slate-50 p-4 text-sm text-slate-500">No confirmed service payments yet.</p>
                        </article>
                    </section>
                </template>

                <section class="admin-panel mt-4 overflow-hidden">
                    <div class="border-b border-slate-200 p-5 sm:flex sm:items-end sm:justify-between sm:gap-5">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Receipts</p>
                            <h2 class="mt-1 text-xl font-black text-slate-950">Payment records</h2>
                            <p class="mt-1 text-sm text-slate-500">A read-only record of confirmed provider service payments.</p>
                        </div>
                        <p class="mt-3 text-xs font-bold text-slate-500 sm:mt-0">{{ pagination.total }} receipt{{ pagination.total === 1 ? '' : 's' }}</p>
                    </div>

                    <form class="grid gap-3 border-b border-slate-200 bg-slate-50/70 p-4 sm:grid-cols-[minmax(0,1fr)_180px_auto]" @submit.prevent="loadFinance(1)">
                        <label class="relative">
                            <span class="sr-only">Search receipts</span>
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input v-model="search" type="search" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100" placeholder="Provider, service, or receipt number">
                        </label>
                        <select v-model="period" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500" @change="changePeriod">
                            <option v-for="option in periodOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                        <button type="submit" class="rounded-md bg-slate-900 px-5 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Search</button>
                    </form>

                    <div v-if="isLoading" class="p-10 text-center text-sm font-semibold text-slate-500">Loading financial records...</div>
                    <div v-else-if="receipts.length === 0" class="p-10 text-center">
                        <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
                        <p class="mt-3 text-sm font-bold text-slate-900">No receipts found</p>
                        <p class="mt-1 text-sm text-slate-500">Try another period or search term.</p>
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-[0.12em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-bold">Receipt</th>
                                    <th class="px-5 py-3 font-bold">Provider</th>
                                    <th class="px-5 py-3 font-bold">Service</th>
                                    <th class="px-5 py-3 font-bold">Paid</th>
                                    <th class="px-5 py-3 font-bold">Amount</th>
                                    <th class="px-5 py-3 text-right font-bold">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="receipt in receipts" :key="receipt.id" class="hover:bg-slate-50/70">
                                    <td class="whitespace-nowrap px-5 py-4 font-mono text-xs font-bold text-slate-700">{{ receipt.receipt_number }}</td>
                                    <td class="px-5 py-4">
                                        <p class="font-bold text-slate-950">{{ receipt.provider }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500">{{ receipt.provider_email }}</p>
                                    </td>
                                    <td class="px-5 py-4 font-semibold text-slate-700">{{ receipt.service }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-xs text-slate-600">{{ dateTime(receipt.paid_at) }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 font-black text-slate-950">{{ money(receipt.amount, receipt.currency) }}</td>
                                    <td class="px-5 py-4 text-right">
                                        <button type="button" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="selectedReceipt = receipt">
                                            View receipt <i class="fa-solid fa-eye text-[10px]" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm">
                        <p class="text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="loadFinance(pagination.current_page - 1)">Previous</button>
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="loadFinance(pagination.current_page + 1)">Next</button>
                        </div>
                    </div>
                </section>

                <AdminFooter />
            </div>
        </section>

        <div v-if="selectedReceipt" class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/65 p-4" role="dialog" aria-modal="true" aria-labelledby="receipt-title" @click.self="selectedReceipt = null">
            <section class="w-full max-w-xl overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-950 p-5 text-white">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">Payment receipt</p>
                        <h2 id="receipt-title" class="mt-1 text-xl font-black">{{ selectedReceipt.receipt_number }}</h2>
                    </div>
                    <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-white/20 text-slate-300 hover:bg-white/10 hover:text-white" aria-label="Close receipt" @click="selectedReceipt = null">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="p-5 sm:p-6">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-200 pb-5">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Provider</p>
                            <p class="mt-1 font-black text-slate-950">{{ selectedReceipt.provider }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ selectedReceipt.provider_email }}</p>
                        </div>
                        <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', selectedReceipt.livemode ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">
                            {{ selectedReceipt.livemode ? 'Live payment' : 'Test payment' }}
                        </span>
                    </div>
                    <dl class="grid gap-x-6 gap-y-4 py-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Service</dt>
                            <dd class="mt-1 font-bold text-slate-950">{{ selectedReceipt.service }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Paid on</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ dateTime(selectedReceipt.paid_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Payment method</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ label(selectedReceipt.payment_method) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Purchased by</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ selectedReceipt.purchased_by || selectedReceipt.provider }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Service status</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ label(selectedReceipt.fulfillment_status) }}</dd>
                        </div>
                        <div v-if="selectedReceipt.payment_id" class="sm:col-span-2">
                            <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Gateway payment ID</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-slate-600">{{ selectedReceipt.payment_id }}</dd>
                        </div>
                    </dl>
                    <div class="flex items-center justify-between border-t-2 border-slate-950 pt-5">
                        <span class="text-sm font-bold text-slate-600">Total paid</span>
                        <span class="text-2xl font-black text-slate-950">{{ money(selectedReceipt.amount, selectedReceipt.currency) }}</span>
                    </div>
                    <p class="mt-5 rounded-md bg-slate-50 p-3 text-xs leading-5 text-slate-500">
                        This record confirms a provider service payment received by the platform. It is separate from scholarship funds or applicant awards.
                    </p>
                </div>
                <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button type="button" class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="selectedReceipt = null">Close receipt</button>
                </footer>
            </section>
        </div>
    </main>
</template>
