<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderQueueTabs from '../components/ProviderQueueTabs.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

const allowedQueues = ['needs_action', 'active', 'waiting', 'completed'];
const pageUrl = new URL(window.location.href);
const requestedQueue = pageUrl.searchParams.get('queue');
const activeQueue = ref(allowedQueues.includes(requestedQueue) ? requestedQueue : 'needs_action');
const searchQuery = ref('');
const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ needs_action: 0, active: 0, waiting: 0, completed: 0, total: 0 });
const nextTask = ref(null);
const purchases = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const syncingReference = ref(null);
let searchTimer = null;

const queueTabs = computed(() => [
    { key: 'needs_action', label: 'Needs action', count: Number(summary.value.needs_action ?? 0) },
    { key: 'active', label: 'In progress', count: Number(summary.value.active ?? 0) },
    { key: 'waiting', label: 'Waiting', count: Number(summary.value.waiting ?? 0) },
    { key: 'completed', label: 'Completed', count: Number(summary.value.completed ?? 0) },
]);
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);
const queueHeading = computed(() => ({
    needs_action: 'Requests needing your attention',
    active: 'Support work in progress',
    waiting: 'Requests waiting to start',
    completed: 'Completed service history',
}[activeQueue.value]));

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format(Number(amount ?? 0) / 100);
}

function dateTime(value) {
    if (!value) return 'Not recorded';
    return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function stateClass(state) {
    return {
        needs_action: 'bg-amber-100 text-amber-900',
        active: 'bg-slate-100 text-slate-700',
        waiting: 'bg-slate-100 text-slate-700',
        completed: 'bg-slate-200 text-slate-700',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);
    if (activeQueue.value === 'needs_action') nextUrl.searchParams.delete('queue');
    else nextUrl.searchParams.set('queue', activeQueue.value);
    window.history.replaceState({}, '', nextUrl);
}

async function loadWorkspace(page = 1, initial = false) {
    if (initial) isLoading.value = true;
    else isRefreshing.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/billing/data', {
            params: {
                queue: activeQueue.value,
                search: searchQuery.value.trim() || undefined,
                page,
            },
        });
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        nextTask.value = response.data.next_task;
        purchases.value = response.data.purchases ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the service request desk.';
    } finally {
        isLoading.value = false;
        isRefreshing.value = false;
    }
}

async function selectQueue(queue) {
    activeQueue.value = queue;
    await loadWorkspace(1);
}

async function syncPayment(purchase) {
    syncingReference.value = purchase.reference_number;
    errorMessage.value = '';

    try {
        await window.axios.post('/provider/billing/sync', { reference: purchase.reference_number });
        await loadWorkspace(pagination.value.current_page);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to refresh the payment status.';
    } finally {
        syncingReference.value = null;
    }
}

watch(searchQuery, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadWorkspace(1), 300);
});

onMounted(() => loadWorkspace(1, true));
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <ProviderWorkspaceState v-if="isLoading" title="Loading service request desk" message="Preparing payments and support work that needs attention." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Service requests are unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="billing" title="Service request desk" description="Track provider payments and follow each service request through fulfillment." icon="fa-solid fa-receipt">
                        <template #actions>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <a :href="workspace.services_url" class="bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-plus mr-2 text-amber-300"></i>Browse services</a>
                            </div>
                        </template>
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace.organization_name }}</span>
                            <span><i class="fa-solid fa-layer-group mr-2 text-slate-400"></i>{{ workspace.service_count }} optional services</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextTask" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950"><i class="fa-solid fa-receipt text-sm"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Next task</p>
                                <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextTask.plan_name }}</h2>
                                <p class="mt-0.5 truncate text-xs text-slate-500">{{ nextTask.work_detail }}</p>
                            </div>
                            <div class="shrink-0 border-l border-slate-200 pl-4">
                                <span :class="['inline-flex px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', stateClass(nextTask.work_state)]">{{ nextTask.work_label }}</span>
                                <p class="mt-1 text-xs font-semibold text-slate-600">{{ money(nextTask.amount, nextTask.currency) }}</p>
                            </div>
                            <a :href="nextTask.action_url" class="shrink-0 bg-slate-950 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-800">{{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i></a>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div><h2 class="text-sm font-bold text-slate-950">No service request needs action</h2><p class="mt-0.5 text-xs text-slate-500">New payment or review tasks will appear here.</p></div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
                            <div><p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-amber-700">Request queue</p><h2 class="mt-0.5 text-base font-bold text-slate-950">{{ queueHeading }}</h2></div>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">{{ activeQueueTab.count }} {{ activeQueueTab.count === 1 ? 'request' : 'requests' }}</p>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Service request states" @select="selectQueue" />

                        <div class="border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <label class="relative block"><span class="sr-only">Search requests</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i><input v-model="searchQuery" type="search" placeholder="Search service or reference" class="w-full rounded border border-slate-300 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                        <div v-if="purchases.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(19rem,1.15fr)_minmax(20rem,1.1fr)_14rem_7rem] gap-4 bg-slate-50 px-5 py-2.5 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid"><span>Service request</span><span>Current status</span><span>Payment</span><span class="text-right">Action</span></div>
                            <article v-for="purchase in purchases" :key="purchase.id" class="grid gap-4 px-5 py-3.5 hover:bg-slate-50 xl:grid-cols-[minmax(19rem,1.15fr)_minmax(20rem,1.1fr)_14rem_7rem] xl:items-center">
                                <div class="min-w-0"><h3 class="truncate text-sm font-bold text-slate-950">{{ purchase.plan_name }}</h3><p class="mt-0.5 font-mono text-[11px] text-slate-500">{{ purchase.reference_number }}</p><p class="mt-0.5 text-xs text-slate-500">Requested {{ dateTime(purchase.created_at) }}</p></div>
                                <div><span :class="['inline-flex px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(purchase.work_state)]">{{ purchase.work_label }}</span><p class="mt-1.5 line-clamp-2 text-xs leading-5 text-slate-500">{{ purchase.work_detail }}</p></div>
                                <div><p class="text-sm font-black text-slate-950">{{ money(purchase.amount, purchase.currency) }}</p><p class="mt-1 text-xs capitalize text-slate-500">{{ purchase.status }}</p></div>
                                <div class="flex items-center justify-end gap-2"><button v-if="purchase.can_refresh_payment" type="button" :disabled="syncingReference === purchase.reference_number" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600 hover:border-slate-900 disabled:opacity-40" title="Refresh payment status" aria-label="Refresh payment status" @click="syncPayment(purchase)"><i :class="['fa-solid fa-rotate-right', syncingReference === purchase.reference_number ? 'animate-spin' : '']"></i></button><a :href="purchase.action_url" class="grid h-9 w-9 place-items-center bg-slate-950 text-white hover:bg-slate-800" :title="purchase.action_label" :aria-label="purchase.action_label"><i class="fa-solid fa-arrow-right"></i></a></div>
                            </article>
                        </div>
                        <div v-else class="px-6 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-inbox"></i></span><h3 class="mt-3 text-sm font-bold text-slate-900">No requests in this queue</h3><p class="mt-1 text-sm text-slate-500">Try another state or search.</p></div>
                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="requests" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
