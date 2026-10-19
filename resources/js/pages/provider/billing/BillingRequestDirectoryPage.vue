<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const queueByPath = {
    '/provider/workspaces/billing/requests': 'requests',
    '/provider/workspaces/billing/action': 'needs_action',
    '/provider/workspaces/billing/active': 'active',
    '/provider/workspaces/billing/waiting': 'waiting',
    '/provider/workspaces/billing/completed': 'completed',
};
const copy = {
    requests: {
        eyebrow: 'Before payment',
        title: 'Requests to finish',
        description: 'Review prepared service details and complete payment when ready.',
        empty: 'No unfinished service requests.',
        search: 'Search service or program',
    },
    needs_action: {
        eyebrow: 'Paid services',
        title: 'Action needed',
        description: 'Respond to support or review delivered work.',
        empty: 'No paid service needs your attention.',
        search: 'Search service or reference',
    },
    waiting: {
        eyebrow: 'Delivery queue',
        title: 'Waiting to start',
        description: 'Paid services queued for platform support.',
        empty: 'No paid service is waiting to start.',
        search: 'Search service or reference',
    },
    active: {
        eyebrow: 'Delivery',
        title: 'In progress',
        description: 'Track services currently handled by platform support.',
        empty: 'No service is currently in progress.',
        search: 'Search service or reference',
    },
    completed: {
        eyebrow: 'Records',
        title: 'Closed services',
        description: 'Open completed and cancelled service records.',
        empty: 'No closed service matches this view.',
        search: 'Search service or reference',
    },
};
const path = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/billing/requests';
const activeQueue = queueByPath[path] ?? 'requests';
const pageCopy = copy[activeQueue];

const isLoading = ref(true);
const syncingId = ref(null);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ requests: 0, needs_action: 0, active: 0, waiting: 0, completed: 0, total: 0 });
const entries = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
let searchTimer = null;
let requestVersion = 0;

const isRequestQueue = computed(() => activeQueue === 'requests');
const resultRange = computed(() => pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 records');
const exportUrl = computed(() => `/provider/workspaces/billing/export?${new URLSearchParams({
    queue: activeQueue,
    ...(search.value.trim() ? { search: search.value.trim() } : {}),
}).toString()}`);

function amountLabel(entry) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: entry.currency || 'PHP',
        maximumFractionDigits: 0,
    }).format((entry.amount || 0) / 100);
}

function dateLabel(value) {
    if (!value) return 'Date unavailable';
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value));
}

function statusClass(entry) {
    if (entry.fulfillment_status === 'cancelled') return 'border-slate-300 bg-slate-100 text-slate-700';
    if (entry.work_state === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (entry.work_state === 'requests' || entry.work_state === 'needs_action') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

async function loadEntries(page = 1) {
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
        entries.value = response.data.entries ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The service list could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

async function refreshPayment(entry) {
    syncingId.value = entry.id;
    errorMessage.value = '';

    try {
        await window.axios.post('/provider/billing/sync', { reference: entry.reference_number });
        await loadEntries(pagination.value.current_page);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The payment status could not be refreshed.';
    } finally {
        syncingId.value = null;
    }
}

watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadEntries(1), 300);
});
onMounted(() => loadEntries());
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page" aria-labelledby="billing-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm">
                    <div class="flex min-h-20 items-center justify-between gap-8">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">{{ pageCopy.eyebrow }}</p>
                            <h1 id="billing-directory-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                        </div>
                        <a href="/provider/workspaces/billing/services" class="inline-flex h-11 shrink-0 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">
                            Browse services
                            <i class="fa-solid fa-arrow-right text-xs text-amber-300" aria-hidden="true"></i>
                        </a>
                    </div>
                </header>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                        <label class="relative block w-full max-w-xl">
                            <span class="sr-only">{{ pageCopy.search }}</span>
                            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input v-model="search" type="search" class="h-11 w-full border border-slate-300 pl-10 pr-4 text-sm outline-none focus:border-slate-950" :placeholder="pageCopy.search">
                        </label>
                        <a v-if="!isRequestQueue" :href="exportUrl" class="inline-flex h-11 shrink-0 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950"><i class="fa-solid fa-download" aria-hidden="true"></i>Export CSV</a>
                        <div class="shrink-0 text-right">
                            <p class="text-xs font-bold text-slate-700">{{ resultRange }}</p>
                            <p v-if="workspace" class="mt-1 text-xs text-slate-500">{{ workspace.organization_name }}</p>
                        </div>
                    </div>

                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                    <div v-if="isLoading" class="px-6 py-14 text-center text-sm text-slate-500">Loading service records...</div>
                    <div v-else-if="!entries.length" class="min-h-96 px-6 py-14 text-center">
                        <i class="fa-regular fa-folder-open text-2xl text-slate-300" aria-hidden="true"></i>
                        <p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p>
                        <a v-if="isRequestQueue" href="/provider/workspaces/billing/services" class="mt-4 inline-flex h-10 items-center border border-slate-300 px-4 text-xs font-bold text-slate-700 hover:border-slate-950">Browse available services</a>
                    </div>
                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[920px] table-fixed border-collapse text-left">
                            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">
                                <tr>
                                    <th class="w-[34%] px-5 py-3">{{ isRequestQueue ? 'Service request' : 'Service' }}</th>
                                    <th class="w-[28%] px-5 py-3">{{ isRequestQueue ? 'Program and total' : 'Current state' }}</th>
                                    <th class="w-[22%] px-5 py-3">{{ isRequestQueue ? 'Next step' : 'Payment' }}</th>
                                    <th class="w-[16%] px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="entry in entries" :key="entry.id" class="align-top hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <p class="font-bold text-slate-950">{{ entry.plan_name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            <template v-if="entry.reference_number">{{ entry.reference_number }} · </template>
                                            Updated {{ dateLabel(entry.updated_at || entry.created_at) }}
                                        </p>
                                    </td>
                                    <td v-if="isRequestQueue" class="px-5 py-4">
                                        <p class="truncate text-sm font-bold text-slate-900">{{ entry.program_name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ amountLabel(entry) }}</p>
                                    </td>
                                    <td v-else class="px-5 py-4">
                                        <span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(entry)]">{{ entry.work_label }}</span>
                                        <p class="mt-2 line-clamp-2 text-xs leading-5 text-slate-500">{{ entry.work_detail }}</p>
                                    </td>
                                    <td v-if="isRequestQueue" class="px-5 py-4">
                                        <span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(entry)]">{{ entry.work_label }}</span>
                                        <p class="mt-2 line-clamp-2 text-xs leading-5 text-slate-500">{{ entry.work_detail }}</p>
                                    </td>
                                    <td v-else class="px-5 py-4">
                                        <p class="text-sm font-bold text-slate-900">{{ amountLabel(entry) }}</p>
                                        <p class="mt-1 text-xs capitalize text-slate-500">{{ entry.status }}<span v-if="entry.paid_at"> · {{ dateLabel(entry.paid_at) }}</span></p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <button v-if="entry.can_refresh_payment" type="button" :disabled="syncingId === entry.id" class="grid h-10 w-10 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 disabled:opacity-40" title="Refresh payment" @click="refreshPayment(entry)">
                                                <i :class="['fa-solid fa-rotate', syncingId === entry.id ? 'fa-spin' : '']" aria-hidden="true"></i>
                                                <span class="sr-only">Refresh payment</span>
                                            </button>
                                            <a :href="entry.action_url" :target="entry.action_url?.startsWith('http') ? '_blank' : undefined" :rel="entry.action_url?.startsWith('http') ? 'noopener' : undefined" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">
                                                {{ entry.action_label }}
                                                <i class="fa-solid fa-arrow-right text-[9px] text-amber-300" aria-hidden="true"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <footer v-if="pagination.last_page > 1" class="flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <button type="button" :disabled="pagination.current_page <= 1" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" @click="loadEntries(pagination.current_page - 1)">Previous</button>
                        <span class="px-2 text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                        <button type="button" :disabled="pagination.current_page >= pagination.last_page" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" @click="loadEntries(pagination.current_page + 1)">Next</button>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
