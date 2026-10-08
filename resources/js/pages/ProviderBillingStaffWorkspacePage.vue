<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

const allowedQueues = ['needs_action', 'active', 'waiting', 'completed'];
const pageUrl = new URL(window.location.href);
const pathSection = pageUrl.pathname.split('/').filter(Boolean).at(-1);
const pathQueueMap = { action: 'needs_action', active: 'active', waiting: 'waiting', completed: 'completed' };
const requestedQueue = pathQueueMap[pathSection] ?? pageUrl.searchParams.get('queue');
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

const queueSections = computed(() => [
    { key: 'needs_action', label: 'Requests needing action', shortLabel: 'Needs action', description: 'Resolve payment, information, or approval tasks from service requests.', count: Number(summary.value.needs_action ?? 0), href: '/provider/workspaces/billing/action', icon: 'fa-triangle-exclamation' },
    { key: 'active', label: 'Services in progress', shortLabel: 'In progress', description: 'Track paid services currently being delivered to your organization.', count: Number(summary.value.active ?? 0), href: '/provider/workspaces/billing/active', icon: 'fa-gears' },
    { key: 'waiting', label: 'Waiting to start', shortLabel: 'Waiting', description: 'Review paid requests queued for platform service work.', count: Number(summary.value.waiting ?? 0), href: '/provider/workspaces/billing/waiting', icon: 'fa-clock' },
    { key: 'completed', label: 'Completed requests', shortLabel: 'Completed', description: 'Review finished provider services and their payment records.', count: Number(summary.value.completed ?? 0), href: '/provider/workspaces/billing/completed', icon: 'fa-circle-check' },
]);
const activeSection = computed(() => queueSections.value.find((section) => section.key === activeQueue.value) ?? queueSections.value[0]);
const leadTask = computed(() => activeQueue.value === 'needs_action' ? nextTask.value : null);

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

function stateIcon(state) {
    return {
        needs_action: 'fa-solid fa-triangle-exclamation',
        active: 'fa-solid fa-gears',
        waiting: 'fa-solid fa-clock',
        completed: 'fa-solid fa-circle-check',
    }[state] ?? 'fa-solid fa-receipt';
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);
    const usesQueuePath = Object.prototype.hasOwnProperty.call(pathQueueMap, pathSection);
    if (usesQueuePath || activeQueue.value === 'needs_action') nextUrl.searchParams.delete('queue');
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
                    <ProviderPageHeader role-key="billing" :title="activeSection.label" :description="activeSection.description" icon="fa-solid fa-receipt" :show-role-guide="false">
                        <template #actions>
                            <a :href="workspace.services_url" class="bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-plus mr-2 text-amber-300" aria-hidden="true"></i>Browse services</a>
                        </template>
                    </ProviderPageHeader>

                    <nav class="mt-4 grid grid-cols-4 border border-slate-300 bg-white" aria-label="Service request pages">
                        <a v-for="section in queueSections" :key="section.key" :href="section.href" :aria-current="section.key === activeQueue ? 'page' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-4 last:border-r-0', section.key === activeQueue ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                            <i :class="['fa-solid', section.icon, section.key === activeQueue ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ section.shortLabel }}</span><span :class="['mt-0.5 block truncate text-[0.68rem]', section.key === activeQueue ? 'text-slate-300' : 'text-slate-500']">{{ section.count }} request{{ section.count === 1 ? '' : 's' }}</span></span>
                        </a>
                    </nav>

                    <section v-if="leadTask" class="mt-3 border border-slate-300 border-l-4 border-l-amber-500 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex items-center gap-4 px-5 py-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Next task</p>
                                <h2 class="mt-0.5 truncate text-base font-bold text-slate-950">{{ leadTask.plan_name }}</h2>
                                <p class="mt-0.5 truncate text-sm text-slate-500">{{ leadTask.work_detail }}</p>
                            </div>
                            <div class="shrink-0"><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', stateClass(leadTask.work_state)]"><i :class="stateIcon(leadTask.work_state)" aria-hidden="true"></i>{{ leadTask.work_label }}</span><p class="mt-1 text-xs font-semibold text-slate-600">{{ money(leadTask.amount, leadTask.currency) }}</p></div>
                            <a :href="leadTask.action_url" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">{{ leadTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-3 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
                            <div><h2 class="text-base font-bold text-slate-950">{{ pagination.total }} request{{ pagination.total === 1 ? '' : 's' }}</h2><p class="mt-0.5 text-xs text-slate-500">{{ summary.total }} total service requests for {{ workspace.organization_name }}.</p></div>
                            <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-circle-notch mr-1.5 animate-spin" aria-hidden="true"></i>Updating</span>
                        </header>

                        <div class="border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <label class="relative block"><span class="sr-only">Search requests</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchQuery" type="search" placeholder="Search service or reference" class="w-full rounded-sm border border-slate-300 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                        <div v-if="purchases.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[70rem] table-fixed">
                                <caption class="sr-only">{{ activeSection.label }}</caption>
                                <colgroup><col class="w-[34%]"><col class="w-[28%]"><col class="w-[25%]"><col class="w-[13%]"></colgroup>
                                <thead><tr><th scope="col">Service request</th><th scope="col">Work status</th><th scope="col">Payment and date</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="purchase in purchases" :key="purchase.id">
                                        <td><div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></span><div class="min-w-0"><p class="truncate font-bold text-slate-950">{{ purchase.plan_name }}</p><p class="mt-1 font-mono text-xs text-slate-500">{{ purchase.reference_number }}</p></div></div></td>
                                        <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', stateClass(purchase.work_state)]"><i :class="stateIcon(purchase.work_state)" aria-hidden="true"></i>{{ purchase.work_label }}</span><p class="mt-1 line-clamp-1 text-xs text-slate-500">{{ purchase.work_detail }}</p></td>
                                        <td><p class="font-bold text-slate-950">{{ money(purchase.amount, purchase.currency) }}</p><p class="mt-0.5 text-xs capitalize text-slate-500">{{ purchase.status }} payment</p><p class="mt-1 text-xs text-slate-500"><i class="fa-regular fa-calendar mr-1.5 text-slate-400" aria-hidden="true"></i>{{ dateTime(purchase.created_at) }}</p></td>
                                        <td><div class="flex items-center gap-2"><button v-if="purchase.can_refresh_payment" type="button" :disabled="syncingReference === purchase.reference_number" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600 hover:border-slate-900 disabled:opacity-40" title="Refresh payment status" aria-label="Refresh payment status" @click="syncPayment(purchase)"><i :class="['fa-solid fa-rotate-right', syncingReference === purchase.reference_number ? 'animate-spin' : '']" aria-hidden="true"></i></button><a :href="purchase.action_url" class="grid h-9 w-9 place-items-center bg-slate-950 text-white hover:bg-slate-800" :title="purchase.action_label" :aria-label="purchase.action_label"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="px-6 py-12 text-center"><i class="fa-solid fa-inbox text-2xl text-slate-300" aria-hidden="true"></i><h3 class="mt-3 text-sm font-bold text-slate-900">No requests on this page</h3><p class="mt-1 text-sm text-slate-500">{{ searchQuery ? 'Clear the search to check the full list.' : 'Requests will appear here when they reach this service state.' }}</p></div>
                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="requests" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
