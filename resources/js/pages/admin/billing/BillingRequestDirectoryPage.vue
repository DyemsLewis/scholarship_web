<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { createStaffQueueState } from '../../../support/staffQueueState.js';
import { labelFromKey } from '../../../support/display.js';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const queueKey = (window.portalPathname?.() ?? window.location.pathname).split('/').filter(Boolean).pop();
const configs = {
    ready: { title: 'Ready to start', description: 'Assign and prepare paid service requests.', empty: 'No requests are ready', icon: 'fa-solid fa-play' },
    active: { title: 'Active delivery', description: 'Continue work already in progress.', empty: 'No services are in progress', icon: 'fa-solid fa-list-check' },
    waiting: { title: 'Waiting on provider', description: 'Track requested information and provider review.', empty: 'No requests are waiting', icon: 'fa-solid fa-clock' },
    completed: { title: 'Closed requests', description: 'Review completed and cancelled service records.', empty: 'No closed requests yet', icon: 'fa-solid fa-circle-check' },
};
const config = configs[queueKey] ?? configs.ready;
const queueState = createStaffQueueState(window, ['search']);
const search = ref(queueState.get('search') ?? '');
const resource = useAdminResource(async ({ signal }, params) => {
    const response = await queueState.load((query) => window.axios.get('/admin/workspaces/billing/queue-data', { params: query, signal }), params, () => !signal.aborted);
    return response.data;
}, { failureMessage: 'This service queue could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const items = computed(() => resource.data.value?.items ?? []);
const pagination = computed(() => resource.data.value?.pagination ?? { current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;
const resultLabel = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 requests');
const exportUrl = computed(() => `/admin/workspaces/billing/export?${new URLSearchParams({
    queue: queueKey,
    ...(search.value.trim() ? { search: search.value.trim() } : {}),
}).toString()}`);

function money(amount, currency = 'PHP') { return new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format(Number(amount ?? 0) / 100); }
function priorityClass(priority) { return ['urgent', 'high'].includes(priority) ? 'text-amber-800' : 'text-slate-600'; }

function loadItems(page = 1) {
    clearTimeout(searchTimer);
    return resource.load({ queue: queueKey, page, per_page: 10, search: search.value.trim() || undefined });
}
function changePage(page) { if (!loading.value && page >= 1 && page <= pagination.value.last_page && page !== pagination.value.current_page) loadItems(page); }
watch(search, () => { clearTimeout(searchTimer); resource.invalidate(); searchTimer = setTimeout(() => loadItems(1), 250); }, { flush: 'sync' });
onBeforeUnmount(() => clearTimeout(searchTimer));
onMounted(() => loadItems(queueState.page));
</script>

<template>
    <AdminWorkspacePage workspace="billing_officer" class="admin-billing-workspace">
        <AdminWorkspaceHeader eyebrow="Service delivery" :title="config.title" :description="config.description" :icon="config.icon" />
        <AdminTableFrame title="Service requests" :description="loading ? 'Updating results...' : errorMessage ? 'Results unavailable' : resultLabel" :icon="config.icon" :loading="loading" :error="errorMessage" loading-message="Loading service requests..." class="mt-4" @retry="resource.retry">
            <template #filters><div class="flex items-center gap-3"><label class="relative block flex-1"><span class="sr-only">Search service requests</span><i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" maxlength="120" placeholder="Search provider, service, or reference" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950"></label><a :href="exportUrl" class="inline-flex h-11 shrink-0 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950"><i class="fa-solid fa-download" aria-hidden="true"></i>Export CSV</a></div></template>
            <table v-if="items.length" class="portal-data-table table-fixed min-w-[56rem]">
                <colgroup><col><col class="w-56"><col class="w-40"><col class="w-40"><col class="w-36"><col class="w-20"></colgroup>
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">Service</th><th class="border-b border-slate-200 px-4 py-3">Provider</th><th class="border-b border-slate-200 px-4 py-3">Work state</th><th class="border-b border-slate-200 px-4 py-3">Assignment</th><th class="border-b border-slate-200 px-4 py-3">Paid</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr></thead>
                <tbody class="divide-y divide-slate-200 bg-white"><tr v-for="item in items" :key="item.id" class="hover:bg-slate-50/70">
                    <td class="px-5 py-3.5"><p class="billing-text text-sm font-bold text-slate-950">{{ item.plan_name }}</p><p class="mt-1 text-xs text-slate-500">{{ item.reference_number }} · {{ money(item.amount, item.currency) }}</p></td>
                    <td class="px-4 py-3.5"><div class="flex min-w-0 items-center gap-3"><StaffTableIdentity kind="provider" :src="item.provider_logo_url" class="h-10 w-10" /><span class="min-w-0"><span class="block billing-text text-sm font-semibold text-slate-800">{{ item.provider }}</span><span class="mt-0.5 block billing-text text-xs text-slate-500">{{ item.provider_email }}</span></span></div></td>
                    <td class="px-4 py-3.5"><p class="text-sm font-bold text-slate-800">{{ item.state_label }}</p><p v-if="item.meeting_requested" class="mt-1 text-xs font-bold text-amber-700"><i class="fa-solid fa-calendar-check mr-1" aria-hidden="true"></i>Meeting decision needed</p><p v-else-if="item.milestone_progress.total" class="mt-1 text-xs text-slate-500">{{ item.milestone_progress.complete }} of {{ item.milestone_progress.total }} milestones</p></td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-slate-700">{{ item.assigned_to || 'Unassigned' }}</p><p :class="['mt-0.5 text-xs font-bold', item.action_overdue ? 'text-rose-700' : priorityClass(item.priority)]">{{ item.action_due_label || `${labelFromKey(item.priority)} priority` }}</p></td>
                    <td class="px-4 py-3.5 text-sm text-slate-600">{{ item.paid_at }}</td>
                    <td class="px-5 py-3.5 text-right"><a :href="queueState.link(item.action_url)" class="staff-row-action h-10 w-10" :aria-label="`Open ${item.plan_name}`" title="Open request"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                </tr></tbody>
            </table>
            <AdminEmptyState v-else :title="search.trim() ? 'No matching requests' : config.empty" description="Try another search or return to the overview." :icon="config.icon" />
            <template v-if="pagination.last_page > 1" #footer><div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div></template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
