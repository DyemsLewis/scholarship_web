<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

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
const queueCopy = computed(() => ({
    needs_action: { title: 'Requests needing your attention', description: 'Complete payments, answer questions, or review delivered work.' },
    active: { title: 'Support work in progress', description: 'Track requests currently being handled by platform support.' },
    waiting: { title: 'Requests waiting to start', description: 'Payment is confirmed and these requests are queued for support.' },
    completed: { title: 'Completed service history', description: 'Open a finished request to review its updates and deliverables.' },
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
        active: 'bg-sky-100 text-sky-800',
        waiting: 'bg-slate-200 text-slate-700',
        completed: 'bg-emerald-100 text-emerald-800',
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
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">Loading service request desk...</div>
                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                <template v-else>
                    <header class="rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-cyan-700 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-cyan-300"><i class="fa-solid fa-receipt"></i></span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-cyan-700">Billing staff</p>
                                    <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-950">Service request desk</h1>
                                    <p class="mt-1 text-sm text-slate-600">Manage payments and follow each support request to completion.</p>
                                </div>
                            </div>
                            <div class="flex flex-col gap-3 border-t border-slate-200 pt-3 sm:flex-row sm:items-center lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0">
                                <div class="lg:text-right"><p class="text-xs font-bold text-slate-900">{{ workspace.organization_name }}</p><p class="mt-1 text-xs text-slate-500">{{ workspace.service_count }} optional services</p></div>
                                <a :href="workspace.services_url" class="rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-plus mr-2 text-cyan-300"></i>Browse services</a>
                            </div>
                        </div>
                    </header>

                    <section v-if="nextTask" class="mt-4 overflow-hidden rounded-lg border border-amber-300 bg-white shadow-sm">
                        <div class="grid lg:grid-cols-[8rem_minmax(0,1fr)_minmax(14rem,.65fr)_auto] lg:items-stretch">
                            <div class="flex items-center justify-center bg-amber-300 px-4 py-4 text-slate-950"><div class="text-center"><p class="text-[0.62rem] font-black uppercase tracking-[0.18em]">Next task</p><i class="fa-solid fa-arrow-right mt-2"></i></div></div>
                            <div class="min-w-0 border-b border-amber-200 px-5 py-4 lg:border-b-0 lg:border-r"><h2 class="truncate text-base font-bold text-slate-950">{{ nextTask.plan_name }}</h2><p class="mt-1 text-sm text-slate-500">{{ nextTask.work_detail }}</p></div>
                            <div class="flex items-center border-b border-amber-200 px-5 py-4 lg:border-b-0 lg:border-r"><div><span :class="['inline-flex rounded px-2.5 py-1 text-xs font-black uppercase', stateClass(nextTask.work_state)]">{{ nextTask.work_label }}</span><p class="mt-1.5 text-xs text-slate-500">{{ money(nextTask.amount, nextTask.currency) }}</p></div></div>
                            <div class="flex items-center px-5 py-4"><a :href="nextTask.action_url" class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800 lg:w-auto">{{ nextTask.action_label }}</a></div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div><h2 class="text-sm font-bold text-emerald-950">No service request needs action</h2><p class="mt-0.5 text-sm text-emerald-800">New payment or review tasks will appear here.</p></div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                <div><p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-cyan-700">Request queue</p><h2 class="mt-1 text-lg font-bold text-slate-950">{{ queueCopy.title }}</h2><p class="mt-1 text-sm text-slate-500">{{ queueCopy.description }}</p></div>
                                <label class="relative block lg:w-80"><span class="sr-only">Search requests</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i><input v-model="searchQuery" type="search" placeholder="Search service or reference" class="w-full rounded-md border border-slate-300 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-cyan-600 focus:ring-3 focus:ring-cyan-100"></label>
                            </div>
                            <div class="mt-4 flex items-center gap-1 overflow-x-auto border-t border-slate-200 pt-2">
                                <button v-for="tab in queueTabs" :key="tab.key" type="button" :class="['shrink-0 border-b-2 px-4 py-2 text-xs font-bold', activeQueue === tab.key ? 'border-cyan-700 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800']" @click="selectQueue(tab.key)">{{ tab.label }} <span :class="['ml-1 rounded px-1.5 py-0.5', activeQueue === tab.key ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-200 text-slate-600']">{{ tab.count }}</span></button>
                                <span v-if="isRefreshing" class="ml-auto shrink-0 text-xs font-semibold text-slate-400"><i class="fa-solid fa-circle-notch mr-1 animate-spin"></i>Updating</span>
                            </div>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                        <div v-if="purchases.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(16rem,1.1fr)_minmax(17rem,1fr)_12rem_8rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid"><span>Service request</span><span>Current state</span><span>Payment</span><span class="text-right">Action</span></div>
                            <article v-for="purchase in purchases" :key="purchase.id" class="grid gap-4 px-5 py-4 hover:bg-slate-50 sm:px-6 xl:grid-cols-[minmax(16rem,1.1fr)_minmax(17rem,1fr)_12rem_8rem] xl:items-center">
                                <div class="min-w-0"><h3 class="truncate text-sm font-bold text-slate-950">{{ purchase.plan_name }}</h3><p class="mt-1 font-mono text-[11px] text-slate-500">{{ purchase.reference_number }}</p><p class="mt-1 text-xs text-slate-500">Requested {{ dateTime(purchase.created_at) }}</p></div>
                                <div><span :class="['inline-flex rounded px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(purchase.work_state)]">{{ purchase.work_label }}</span><p class="mt-1.5 line-clamp-2 text-xs leading-5 text-slate-500">{{ purchase.work_detail }}</p></div>
                                <div><p class="text-sm font-black text-slate-950">{{ money(purchase.amount, purchase.currency) }}</p><p class="mt-1 text-xs capitalize text-slate-500">{{ purchase.status }}</p></div>
                                <div class="flex items-center justify-end gap-2"><button v-if="purchase.can_refresh_payment" type="button" :disabled="syncingReference === purchase.reference_number" class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 text-slate-600 hover:border-slate-900 disabled:opacity-40" title="Refresh payment status" aria-label="Refresh payment status" @click="syncPayment(purchase)"><i :class="['fa-solid fa-rotate-right', syncingReference === purchase.reference_number ? 'animate-spin' : '']"></i></button><a :href="purchase.action_url" class="grid h-9 w-9 place-items-center rounded-md bg-slate-950 text-white hover:bg-slate-800" :title="purchase.action_label" :aria-label="purchase.action_label"><i class="fa-solid fa-arrow-right"></i></a></div>
                            </article>
                        </div>
                        <div v-else class="px-6 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-inbox"></i></span><h3 class="mt-3 text-sm font-bold text-slate-900">No requests in this queue</h3><p class="mt-1 text-sm text-slate-500">Try another state or search.</p></div>
                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6"><p class="text-xs font-semibold text-slate-500">{{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</p><div class="flex gap-2"><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1 || isRefreshing" @click="loadWorkspace(pagination.current_page - 1)">Previous</button><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isRefreshing" @click="loadWorkspace(pagination.current_page + 1)">Next</button></div></div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
