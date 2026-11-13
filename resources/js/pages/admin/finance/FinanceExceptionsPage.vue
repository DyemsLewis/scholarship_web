<script setup>
import WorkspaceTextSummary from '../../../components/workspace-dashboard/WorkspaceTextSummary.vue';
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { createStaffQueueState } from '../../../support/staffQueueState.js';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { labelFromKey } from '../../../support/display';
import FinanceRecordReviewDialog from '../../../components/admin/FinanceRecordReviewDialog.vue';

const queueState = createStaffQueueState(window, ['search','status']);
const search = ref(queueState.get('search') ?? '');
const status = ref(['pending', 'failed', 'refund', 'unconfirmed', 'follow_up', 'needs_review'].includes(queueState.get('status')) ? queueState.get('status') : 'all');
const selectedId = ref(null);
const reviewOpen = ref(false);
const reviewMessage = ref('');
const resource = useAdminResource(async ({ signal }, params) => {
    const response = await queueState.load((query) => window.axios.get('/admin/workspaces/finance/exceptions/data', { params: query, signal }), params, () => !signal.aborted);
    return response.data;
}, { failureMessage: 'Payment exceptions could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const items = computed(() => resource.data.value?.items ?? []);
const pagination = computed(() => resource.data.value?.pagination ?? { current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => loading.value ? 'Updating results...' : errorMessage.value ? 'Results unavailable' : pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 exceptions');

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format(Number(amount ?? 0) / 100);
}

function statusClass(value) {
    return value === 'failed' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-900';
}

function loadExceptions(page = 1) {
    clearTimeout(searchTimer);
    return resource.load({ page, per_page: 15, status: status.value, search: search.value.trim() || undefined });
}

function changePage(page) {
    if (!loading.value && !errorMessage.value && page >= 1 && page <= pagination.value.last_page && page !== pagination.value.current_page) loadExceptions(page);
}

function openReview(item) {
    if (loading.value || errorMessage.value) return;
    selectedId.value = item.id;
    reviewOpen.value = true;
}

function reviewSaved(message) {
    reviewMessage.value = message;
    loadExceptions(pagination.value.current_page);
}

watch(search, () => {
    resource.invalidate();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadExceptions(1), 250);
}, { flush: 'sync' });
watch(status, () => {
    resource.invalidate();
    loadExceptions(1);
}, { flush: 'sync' });
onBeforeUnmount(() => clearTimeout(searchTimer));
onMounted(() => loadExceptions(queueState.page));
</script>

<template>
    <AdminWorkspacePage workspace="finance_officer" class="admin-finance-workspace">
        <AdminWorkspaceHeader
            eyebrow="Payment follow-up"
            title="Payment exceptions"
            description="Review payment exceptions, refund follow-up, and questions returned by Billing."
            icon="fa-solid fa-triangle-exclamation"
        />

        <AdminTableFrame title="Exception records" :description="resultLabel" icon="fa-solid fa-triangle-exclamation" class="mt-4" :loading="loading" :error="errorMessage" @retry="resource.retry">
            <template #filters>
                <div class="grid grid-cols-[minmax(24rem,1fr)_14rem] gap-3">
                    <label class="relative block"><span class="sr-only">Search payment exceptions</span><i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" maxlength="120" placeholder="Search provider, service, or reference" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950"></label>
                    <label><span class="sr-only">Exception status</span><select v-model="status" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option value="all">All exceptions</option><option value="needs_review">Needs Finance review</option><option value="pending">Pending</option><option value="failed">Failed</option><option value="refund">Refund pending</option><option value="unconfirmed">Confirmation incomplete</option><option value="follow_up">Internal follow-up</option></select></label>
                </div>
            </template>

            <p v-if="reviewMessage" class="border-b border-slate-200 bg-slate-50 px-6 py-3 text-sm font-semibold text-slate-700" role="status">{{ reviewMessage }}</p>
            <table v-if="items.length" class="portal-data-table admin-ledger-table">
                <colgroup><col style="width: 16%"><col style="width: 21%"><col style="width: 16%"><col style="width: 17%"><col style="width: 11%"><col style="width: 11%"><col style="width: 8%"></colgroup>
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">Reference</th><th class="border-b border-slate-200 px-4 py-3">Provider</th><th class="border-b border-slate-200 px-4 py-3">Service</th><th class="border-b border-slate-200 px-4 py-3">Status</th><th class="border-b border-slate-200 px-4 py-3">Amount</th><th class="border-b border-slate-200 px-5 py-3">Recorded</th><th class="border-b border-slate-200 px-4 py-3 text-right">Open</th></tr></thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr v-for="item in items" :key="item.id" class="align-middle hover:bg-slate-50/70">
                        <td class="px-5 py-3.5"><p class="break-words font-mono text-xs font-bold text-slate-900">{{ item.reference_number }}</p><WorkspaceTextSummary v-if="item.reason" disclosure-only class="mt-0.5 text-xs text-slate-600" :text="item.reason" label="reason" /></td>
                        <td class="px-4 py-3.5"><div class="flex min-w-0 items-center gap-3"><StaffTableIdentity kind="provider" :src="item.provider_logo_url" class="h-10 w-10" /><span class="min-w-0"><span class="block break-words text-sm font-bold text-slate-900">{{ item.provider }}</span><span v-if="item.provider_email" class="mt-0.5 block text-xs text-slate-500 [overflow-wrap:anywhere]">{{ item.provider_email }}</span></span></div></td>
                        <td class="px-4 py-3.5 text-sm font-semibold text-slate-700">{{ item.service }}</td>
                        <td class="px-4 py-3.5"><span :class="['inline-flex px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(item.status)]">{{ item.exception_label || labelFromKey(item.status) }}</span></td>
                        <td class="px-4 py-3.5 text-sm font-black text-slate-950">{{ money(item.amount, item.currency) }}</td>
                        <td class="px-5 py-3.5 text-sm text-slate-600">{{ item.failed_at || item.created_at }}</td>
                        <td class="px-4 py-3.5 text-right"><button type="button" class="staff-row-action h-10 w-10" :aria-label="`Review ${item.reference_number}`" title="Review financial record" @click="openReview(item)"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button></td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else title="No matching payment exceptions" description="No payment, refund, or internal follow-up matches this filter." icon="fa-solid fa-circle-check" />

            <template v-if="pagination.last_page > 1" #footer>
                <div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || errorMessage || pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || errorMessage || pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div>
            </template>
        </AdminTableFrame>
    </AdminWorkspacePage>
    <FinanceRecordReviewDialog :open="reviewOpen" :purchase-id="selectedId" @close="reviewOpen = false" @updated="reviewSaved" />
</template>
