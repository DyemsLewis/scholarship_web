<script setup>
import { computed, onMounted, ref } from 'vue';
import BillingOfficerSidebar from '../components/BillingOfficerSidebar.vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isLoading = ref(true);
const usesPortalManagerWorkspace = window.location.pathname.startsWith('/admin/workspaces/portal');
const billingWorkspaceBase = usesPortalManagerWorkspace ? '/admin/workspaces/portal/billing' : '/admin/workspaces/billing';
const errorMessage = ref('');
const searchDraft = ref('');
const appliedSearch = ref('');
const paymentStatus = ref('paid');
const fulfillmentStatus = ref('all');
const counts = ref({ all: 0, queued: 0, needs_information: 0, ready: 0, in_progress: 0, provider_review: 0, completed: 0 });
const purchases = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });

const fulfillmentFilters = computed(() => [
    { value: 'all', label: 'All paid', count: counts.value.all },
    { value: 'needs_information', label: 'Needs information', count: counts.value.needs_information },
    { value: 'ready', label: 'Ready', count: counts.value.ready },
    { value: 'in_progress', label: 'In progress', count: counts.value.in_progress },
    { value: 'provider_review', label: 'Provider review', count: counts.value.provider_review },
    { value: 'completed', label: 'Completed', count: counts.value.completed },
]);
const waitingCount = computed(() => counts.value.needs_information + counts.value.provider_review);

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
    }).format(Number(amount ?? 0) / 100);
}

function dateTime(value) {
    if (!value) return 'Not set';
    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function statusLabel(value) {
    return String(value ?? 'pending').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['paid', 'completed'].includes(status)) return 'bg-emerald-100 text-emerald-800';
    if (status === 'failed') return 'bg-rose-100 text-rose-800';
    if (status === 'in_progress') return 'bg-sky-100 text-sky-800';
    return 'bg-amber-100 text-amber-900';
}

function actionLabel(status) {
    return {
        queued: 'Review and assign',
        needs_information: 'Provider response needed',
        ready: 'Ready to begin',
        in_progress: 'Continue delivery',
        provider_review: 'Provider confirmation pending',
        completed: 'Delivery complete',
    }[status] ?? 'Review request';
}

async function loadPurchases(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/billing/data', {
            params: {
                page,
                search: appliedSearch.value || undefined,
                payment_status: paymentStatus.value,
                fulfillment_status: fulfillmentStatus.value,
            },
        });
        counts.value = response.data.counts ?? counts.value;
        purchases.value = response.data.purchases ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load paid service requests.';
    } finally {
        isLoading.value = false;
    }
}

function applySearch() {
    appliedSearch.value = searchDraft.value.trim();
    loadPurchases(1);
}

function clearSearch() {
    searchDraft.value = '';
    appliedSearch.value = '';
    loadPurchases(1);
}

onMounted(loadPurchases);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar v-if="usesPortalManagerWorkspace" />
        <BillingOfficerSidebar v-else />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Service desk"
                    title="Billing workspace"
                    description="Track confirmed purchases and complete provider service delivery."
                    icon="fa-solid fa-file-invoice-dollar"
                >
                    <template #actions>
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="loadPurchases(pagination.current_page)">
                            <i class="fa-solid fa-rotate mr-1.5 text-xs" aria-hidden="true"></i>Refresh
                        </button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading && !purchases.length" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading service requests...</div>

                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <section class="admin-panel overflow-hidden">
                        <dl class="grid divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Ready to start</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ counts.ready }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">In progress</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ counts.in_progress }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Waiting on provider</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ waitingCount }}</dd></div>
                        </dl>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Delivery queue</p>
                            <h2 class="mt-1 text-xl font-black text-slate-950">Provider service requests</h2>
                        </header>

                        <form class="grid gap-3 border-b border-slate-200 bg-slate-50 p-3 lg:grid-cols-[minmax(0,1fr)_14rem_14rem_auto]" @submit.prevent="applySearch">
                            <label class="relative">
                                <span class="sr-only">Search service requests</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input v-model="searchDraft" type="search" maxlength="120" placeholder="Search provider, service, or reference" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-slate-500">
                            </label>
                            <select v-model="fulfillmentStatus" aria-label="Delivery status" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none" @change="loadPurchases(1)">
                                <option v-for="filter in fulfillmentFilters" :key="filter.value" :value="filter.value">{{ filter.label }} ({{ filter.count }})</option>
                            </select>
                            <select v-model="paymentStatus" aria-label="Payment status" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none" @change="loadPurchases(1)">
                                <option value="paid">Confirmed payments</option>
                                <option value="pending">Pending checkout</option>
                                <option value="failed">Failed checkout</option>
                                <option value="all">All payments</option>
                            </select>
                            <div class="flex gap-2"><button type="submit" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Search</button><button v-if="appliedSearch" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50" @click="clearSearch">Clear</button></div>
                        </form>

                        <div v-if="isLoading" class="p-6 text-sm text-slate-500">Updating queue...</div>

                        <div v-else-if="purchases.length" class="divide-y divide-slate-200">
                            <article v-for="purchase in purchases" :key="purchase.id" class="flex flex-col gap-4 px-5 py-4 sm:px-6 xl:flex-row xl:items-center">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-950">{{ purchase.plan_name }}</h3><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(purchase.fulfillment_status)]">{{ statusLabel(purchase.fulfillment_status) }}</span></div>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ purchase.provider?.name || 'Provider' }} &middot; {{ purchase.reference_number }}</p>
                                </div>
                                <div class="grid shrink-0 grid-cols-2 gap-x-5 gap-y-2 text-xs sm:grid-cols-3 xl:w-[470px]">
                                    <div><p class="font-semibold text-slate-500">Payment</p><p class="mt-1 font-bold text-slate-900">{{ money(purchase.amount, purchase.currency) }}</p></div>
                                    <div><p class="font-semibold text-slate-500">Assignment</p><p class="mt-1 truncate font-bold text-slate-900">{{ purchase.assigned_to_name || 'Unassigned' }}</p></div>
                                    <div class="col-span-2 sm:col-span-1"><p class="font-semibold text-slate-500">Next action</p><p class="mt-1 truncate font-bold text-slate-900">{{ actionLabel(purchase.fulfillment_status) }}</p></div>
                                </div>
                                <div class="shrink-0 xl:w-40"><p class="text-xs font-semibold text-slate-500">{{ purchase.target_due_at ? `Due ${dateTime(purchase.target_due_at)}` : `Paid ${dateTime(purchase.paid_at ?? purchase.created_at)}` }}</p></div>
                                <a v-if="purchase.status === 'paid'" :href="`${billingWorkspaceBase}/requests/${purchase.id}`" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800">Open request</a>
                                <span v-else class="shrink-0 text-xs font-semibold text-slate-400">No delivery action</span>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
                            <p class="mt-3 font-bold text-slate-950">No matching service requests</p>
                            <p class="mt-1 text-sm text-slate-500">Try another delivery status, payment state, or search.</p>
                        </div>

                        <nav v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3" aria-label="Billing queue pagination">
                            <button type="button" :disabled="pagination.current_page <= 1" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadPurchases(pagination.current_page - 1)">Previous</button>
                            <span class="text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                            <button type="button" :disabled="pagination.current_page >= pagination.last_page" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadPurchases(pagination.current_page + 1)">Next</button>
                        </nav>
                    </section>
                </div>
            </div>
        </section>
    </main>
</template>
