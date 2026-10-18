<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const queueByPath = {
    '/provider/workspaces/billing/action': 'needs_action',
    '/provider/workspaces/billing/active': 'active',
    '/provider/workspaces/billing/waiting': 'waiting',
    '/provider/workspaces/billing/completed': 'completed',
};
const copy = {
    needs_action: { title: 'Requests needing action', description: 'Complete payments, answer questions, or review delivered work.', empty: 'No service requests need your attention.' },
    active: { title: 'Services in progress', description: 'Track work currently being handled by platform support.', empty: 'No services are currently in progress.' },
    waiting: { title: 'Waiting to start', description: 'View paid requests waiting for platform support.', empty: 'No paid requests are waiting to start.' },
    completed: { title: 'Completed requests', description: 'Refer to finished services and their delivered files.', empty: 'No completed service requests match this view.' },
};
const path = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/billing/action';
const activeQueue = queueByPath[path] ?? 'needs_action';
const pageCopy = copy[activeQueue];

const isLoading = ref(true);
const syncingId = ref(null);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ needs_action: 0, active: 0, waiting: 0, completed: 0, total: 0 });
const nextTask = ref(null);
const purchases = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
let searchTimer = null;
let requestVersion = 0;

const resultRange = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 requests');

function amountLabel(purchase) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: purchase.currency || 'PHP', maximumFractionDigits: 0 }).format((purchase.amount || 0) / 100);
}

function dateLabel(value) {
    if (!value) return 'Not set';
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value));
}

function statusClass(state) {
    if (state === 'needs_action') return 'border-amber-200 bg-amber-50 text-amber-800';
    if (state === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

async function loadRequests(page = 1) {
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/billing/data', {
            params: { queue: activeQueue, search: search.value.trim() || undefined, page },
        });
        if (version !== requestVersion) return;
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        nextTask.value = response.data.next_task;
        purchases.value = response.data.purchases ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The service request list could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

async function refreshPayment(purchase) {
    syncingId.value = purchase.id;
    errorMessage.value = '';
    try {
        await window.axios.post('/provider/billing/sync', { reference: purchase.reference_number });
        await loadRequests(pagination.value.current_page);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The payment status could not be refreshed.';
    } finally {
        syncingId.value = null;
    }
}

watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadRequests(1), 300);
});
onMounted(() => loadRequests());
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page" aria-labelledby="billing-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Service requests</p>
                            <h1 id="billing-directory-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                        </div>
                        <div class="flex items-center gap-5">
                            <div class="text-right text-xs font-semibold text-slate-500">
                                <p><span class="font-bold text-amber-700">{{ summary.needs_action }}</span> need action · <span class="font-bold text-slate-900">{{ summary.active }}</span> in progress</p>
                                <p v-if="workspace" class="mt-1">{{ workspace.organization_name }}</p>
                            </div>
                            <a href="/provider/workspaces/billing/services" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-plus text-amber-300" aria-hidden="true"></i>Browse services</a>
                        </div>
                    </div>
                </header>

                <section v-if="activeQueue === 'needs_action' && nextTask" class="mt-4 border border-slate-200 border-l-4 border-l-amber-400 bg-white px-5 py-4 shadow-sm">
                    <div class="flex items-center gap-4">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                        <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Priority</p><h2 class="mt-0.5 truncate text-base font-bold text-slate-950">{{ nextTask.plan_name }}</h2><p class="mt-0.5 truncate text-xs text-slate-500">{{ nextTask.work_detail }}</p></div>
                        <a :href="nextTask.action_url" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">{{ nextTask.action_label }} <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                    </div>
                </section>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                        <label class="relative block w-full max-w-xl"><span class="sr-only">Search service requests</span><i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" class="h-11 w-full border border-slate-300 pl-10 pr-4 text-sm outline-none focus:border-slate-950" placeholder="Search service or reference"></label>
                        <p class="shrink-0 text-xs font-semibold text-slate-500">{{ resultRange }}</p>
                    </div>

                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                    <div v-if="isLoading" class="px-6 py-14 text-center text-sm text-slate-500">Loading service requests...</div>
                    <div v-else-if="!purchases.length" class="px-6 py-14 text-center"><i class="fa-regular fa-folder-open text-2xl text-slate-300" aria-hidden="true"></i><p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p></div>
                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[900px] table-fixed border-collapse text-left">
                            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500"><tr><th class="w-[35%] px-5 py-3">Service</th><th class="w-[27%] px-5 py-3">Current state</th><th class="w-[20%] px-5 py-3">Payment</th><th class="w-[18%] px-5 py-3 text-right">Action</th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="purchase in purchases" :key="purchase.id" class="align-top hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><p class="font-bold text-slate-950">{{ purchase.plan_name }}</p><p class="mt-1 text-xs text-slate-500">{{ purchase.reference_number }} · Requested {{ dateLabel(purchase.created_at) }}</p></td>
                                    <td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(purchase.work_state)]">{{ purchase.work_label }}</span><p class="mt-2 line-clamp-2 text-xs leading-5 text-slate-500">{{ purchase.work_detail }}</p></td>
                                    <td class="px-5 py-4"><p class="text-sm font-bold text-slate-900">{{ amountLabel(purchase) }}</p><p class="mt-1 text-xs capitalize text-slate-500">{{ purchase.status }}<span v-if="purchase.paid_at"> · {{ dateLabel(purchase.paid_at) }}</span></p></td>
                                    <td class="px-5 py-4 text-right"><div class="inline-flex items-center gap-2"><button v-if="purchase.can_refresh_payment" type="button" :disabled="syncingId === purchase.id" class="grid h-10 w-10 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 disabled:opacity-40" title="Refresh payment" @click="refreshPayment(purchase)"><i :class="['fa-solid fa-rotate', syncingId === purchase.id ? 'fa-spin' : '']" aria-hidden="true"></i><span class="sr-only">Refresh payment</span></button><a :href="purchase.action_url" :target="purchase.action_url?.startsWith('http') ? '_blank' : undefined" :rel="purchase.action_url?.startsWith('http') ? 'noopener' : undefined" class="grid h-10 w-10 place-items-center bg-slate-950 text-white hover:bg-slate-800" :title="purchase.action_label"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><span class="sr-only">{{ purchase.action_label }}</span></a></div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <footer v-if="pagination.last_page > 1" class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3"><button type="button" :disabled="pagination.current_page <= 1" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" @click="loadRequests(pagination.current_page - 1)">Previous</button><span class="px-2 text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><button type="button" :disabled="pagination.current_page >= pagination.last_page" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" @click="loadRequests(pagination.current_page + 1)">Next</button></footer>
                </section>
            </div>
        </section>
    </main>
</template>
