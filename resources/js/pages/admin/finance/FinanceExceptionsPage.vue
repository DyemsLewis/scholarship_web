<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { labelFromKey } from '../../../support/display';

const query = new URLSearchParams(window.location.search);
const loading = ref(true);
const errorMessage = ref('');
const search = ref(query.get('search') ?? '');
const status = ref(['pending', 'failed'].includes(query.get('status')) ? query.get('status') : 'all');
const items = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 exceptions');

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format(Number(amount ?? 0) / 100);
}

function statusClass(value) {
    return value === 'failed' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-900';
}

async function loadExceptions(page = 1) {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/admin/workspaces/finance/exceptions/data', {
            params: { page, per_page: 15, status: status.value, search: search.value.trim() || undefined },
        });
        items.value = response.data.items ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Payment exceptions could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    if (page >= 1 && page <= pagination.value.last_page && page !== pagination.value.current_page) loadExceptions(page);
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadExceptions(1), 250);
});
watch(status, () => loadExceptions(1));
onMounted(loadExceptions);
</script>

<template>
    <AdminWorkspacePage workspace="finance_officer">
        <AdminWorkspaceHeader
            eyebrow="Payment follow-up"
            title="Payment exceptions"
            description="Review pending and failed provider service payments."
            icon="fa-solid fa-triangle-exclamation"
        />

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <AdminTableFrame title="Exception records" :description="resultLabel" icon="fa-solid fa-triangle-exclamation" class="mt-4">
            <template #filters>
                <div class="grid grid-cols-[minmax(24rem,1fr)_14rem] gap-3">
                    <label class="relative block"><span class="sr-only">Search payment exceptions</span><i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" maxlength="120" placeholder="Search provider, service, or reference" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950"></label>
                    <label><span class="sr-only">Exception status</span><select v-model="status" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option value="all">All exceptions</option><option value="pending">Pending</option><option value="failed">Failed</option></select></label>
                </div>
            </template>

            <div v-if="loading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading payment exceptions...</div>
            <table v-else-if="items.length" class="portal-data-table min-w-[68rem]">
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">Reference</th><th class="border-b border-slate-200 px-4 py-3">Provider</th><th class="border-b border-slate-200 px-4 py-3">Service</th><th class="border-b border-slate-200 px-4 py-3">Status</th><th class="border-b border-slate-200 px-4 py-3">Amount</th><th class="border-b border-slate-200 px-5 py-3">Recorded</th></tr></thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3.5 font-mono text-xs font-bold text-slate-900">{{ item.reference_number }}</td>
                        <td class="px-4 py-3.5"><div class="flex min-w-0 items-center gap-3"><span class="relative grid h-10 w-10 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-building-columns" aria-hidden="true"></i><img v-if="item.provider_logo_url" :src="item.provider_logo_url" :alt="`${item.provider} logo`" class="absolute inset-0 h-full w-full bg-white object-contain p-1" @error="$event.currentTarget.remove()"></span><span class="min-w-0"><span class="block max-w-xs truncate text-sm font-bold text-slate-900">{{ item.provider }}</span><span class="mt-0.5 block max-w-xs truncate text-xs text-slate-500">{{ item.provider_email }}</span></span></div></td>
                        <td class="px-4 py-3.5 text-sm font-semibold text-slate-700">{{ item.service }}</td>
                        <td class="px-4 py-3.5"><span :class="['inline-flex px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(item.status)]">{{ labelFromKey(item.status) }}</span><p v-if="item.reason" class="mt-1 max-w-md text-xs leading-5 text-slate-500">{{ item.reason }}</p></td>
                        <td class="px-4 py-3.5 text-sm font-black text-slate-950">{{ money(item.amount, item.currency) }}</td>
                        <td class="px-5 py-3.5 text-sm text-slate-600">{{ item.failed_at || item.created_at }}</td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else title="No payment exceptions" description="No pending or failed records match this filter." icon="fa-solid fa-circle-check" />

            <template v-if="pagination.last_page > 1" #footer>
                <div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div>
            </template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
