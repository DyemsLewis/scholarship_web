<script setup>
import { onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSectionNav from '../components/AdminSectionNav.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const search = ref('');
const paymentStatus = ref('paid');
const fulfillmentStatus = ref('all');
const counts = ref({ all: 0, needs_information: 0, ready: 0, in_progress: 0, provider_review: 0, completed: 0 });
const purchases = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });

const fulfillmentFilters = [
    { value: 'all', label: 'All paid' },
    { value: 'needs_information', label: 'Needs information' },
    { value: 'ready', label: 'Ready' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'provider_review', label: 'Provider review' },
    { value: 'completed', label: 'Completed' },
];

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
    }).format(Number(amount ?? 0) / 100);
}

function dateTime(value) {
    if (!value) {
        return 'Not yet';
    }

    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function statusLabel(value) {
    return String(value ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['paid', 'completed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'failed') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'in_progress') {
        return 'bg-sky-100 text-sky-800';
    }

    return 'bg-amber-100 text-amber-800';
}

async function loadPurchases(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/billing/data', {
            params: {
                page,
                search: search.value || undefined,
                payment_status: paymentStatus.value,
                fulfillment_status: fulfillmentStatus.value,
            },
        });
        counts.value = response.data.counts ?? counts.value;
        purchases.value = response.data.purchases ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider service payments.';
    } finally {
        isLoading.value = false;
    }
}

function chooseFulfillmentFilter(value) {
    fulfillmentStatus.value = value;
    loadPurchases(1);
}

onMounted(() => {
    loadPurchases();
});
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="billing" />

        <section class="admin-page">
            <div class="admin-container">
                <header class="admin-hero">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Provider Services</p>
                    <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">Service request queue</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        Assign paid support requests, follow milestones, exchange updates, and deliver completed work.
                    </p>

                    <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                        <button v-for="filter in fulfillmentFilters" :key="filter.value" type="button" :class="['rounded-md px-3 py-2 text-xs font-bold transition', fulfillmentStatus === filter.value ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50']" @click="chooseFulfillmentFilter(filter.value)">
                            {{ filter.label }} {{ counts[filter.value] ?? counts.all }}
                        </button>
                    </div>
                </header>

                <AdminSectionNav section="operations" />

                <div v-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <section class="admin-panel mt-5 overflow-hidden">
                    <form class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 p-4 sm:flex-row" @submit.prevent="loadPurchases(1)">
                        <label class="relative min-w-0 flex-1">
                            <span class="sr-only">Search payments</span>
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input v-model="search" type="search" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100" placeholder="Search provider, service, or reference">
                        </label>
                        <select v-model="paymentStatus" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500" @change="loadPurchases(1)">
                            <option value="paid">Confirmed payments</option>
                            <option value="pending">Pending checkout</option>
                            <option value="failed">Failed checkout</option>
                            <option value="all">All payment states</option>
                        </select>
                        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Search</button>
                    </form>

                    <div v-if="isLoading" class="p-8 text-center text-sm text-slate-500">Loading service payments...</div>
                    <div v-else-if="purchases.length === 0" class="p-8 text-center">
                        <i class="fa-solid fa-receipt text-2xl text-slate-300" aria-hidden="true"></i>
                        <p class="mt-3 text-sm font-bold text-slate-900">No matching service payments</p>
                        <p class="mt-1 text-sm text-slate-500">Confirmed provider purchases will appear here for fulfillment.</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-[0.12em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-bold">Provider</th>
                                    <th class="px-5 py-3 font-bold">Service</th>
                                    <th class="px-5 py-3 font-bold">Payment</th>
                                    <th class="px-5 py-3 font-bold">Fulfillment</th>
                                    <th class="px-5 py-3 font-bold">Amount</th>
                                    <th class="px-5 py-3 text-right font-bold">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="purchase in purchases" :key="purchase.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <p class="font-bold text-slate-950">{{ purchase.provider?.name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ purchase.provider?.email }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="font-bold text-slate-900">{{ purchase.plan_name }}</p>
                                        <p class="mt-1 font-mono text-xs text-slate-500">{{ purchase.reference_number }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span :class="['rounded px-2 py-1 text-xs font-bold', statusClass(purchase.status)]">{{ statusLabel(purchase.status) }}</span>
                                        <p class="mt-2 whitespace-nowrap text-xs text-slate-500">{{ dateTime(purchase.paid_at ?? purchase.created_at) }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span :class="['rounded px-2 py-1 text-xs font-bold', statusClass(purchase.fulfillment_status)]">{{ statusLabel(purchase.fulfillment_status) }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 font-bold text-slate-900">{{ money(purchase.amount, purchase.currency) }}</td>
                                    <td class="px-5 py-4 text-right">
                                        <a v-if="purchase.status === 'paid'" :href="purchase.workspace_url" class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100">Open workspace <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
                                        <span v-else class="text-xs font-semibold text-slate-400">No action</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-4 text-sm">
                        <p class="text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="loadPurchases(pagination.current_page - 1)">Previous</button>
                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="loadPurchases(pagination.current_page + 1)">Next</button>
                        </div>
                    </div>
                </section>

                <AdminFooter />
            </div>
        </section>
    </main>

</template>
