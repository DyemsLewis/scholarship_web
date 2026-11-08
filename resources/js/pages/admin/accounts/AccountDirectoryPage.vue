<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { createStaffQueueState } from '../../../support/staffQueueState.js';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import AccountDirectoryTable from './AccountDirectoryTable.vue';

const queueState = createStaffQueueState(window, ['search','role']);
const search = ref(queueState.get('search') ?? '');
const requestedRole = queueState.get('role');
const role = ref(['applicant', 'provider'].includes(requestedRole) ? requestedRole : 'all');
const resource = useAdminResource(async ({ signal }, params) => {
    const response = await queueState.load((query) => window.axios.get('/admin/workspaces/accounts/data', { params: query, signal }), params, () => !signal.aborted);
    return response.data;
}, { failureMessage: 'The account directory could not be loaded.' });
const { isLoading: loading, errorMessage, hasLoaded } = resource;
const accounts = computed(() => resource.data.value?.users ?? []);
const stats = computed(() => resource.data.value?.stats ?? {});
const pagination = computed(() => resource.data.value?.pagination ?? { current_page: 1, last_page: 1, total: 0 });
let searchTimer;

const resultLabel = computed(() => {
    if (!pagination.value.total) return '0 accounts';
    return `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`;
});

function loadAccounts(page = 1) {
    clearTimeout(searchTimer);
    return resource.load({ page, per_page: 10, search: search.value.trim() || undefined, role: role.value, attention: 'all' });
}

function changePage(page) {
    if (loading.value || page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
    loadAccounts(page);
}

watch([search, role], () => {
    clearTimeout(searchTimer);
    resource.invalidate();
    searchTimer = setTimeout(() => loadAccounts(1), 250);
}, { flush: 'sync' });
onBeforeUnmount(() => clearTimeout(searchTimer));

onMounted(() => loadAccounts(queueState.page));
</script>

<template>
    <AdminWorkspacePage workspace="account_manager">
        <AdminWorkspaceHeader
            eyebrow="Account access"
            title="Account directory"
            description="Find applicant and provider accounts."
            icon="fa-solid fa-address-book"
        >
            <template #actions>
                <a href="/admin/workspaces/accounts/create" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Create account</a>
            </template>
        </AdminWorkspaceHeader>

        <dl v-if="hasLoaded" class="mt-4 grid grid-cols-4 border border-slate-300 bg-white shadow-sm">
            <div class="flex items-center gap-3 border-r border-slate-200 px-5 py-3.5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-users" aria-hidden="true"></i></span><div><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">All accounts</dt><dd class="mt-0.5 text-xl font-black text-slate-950">{{ stats.total_users }}</dd></div></div>
            <div class="flex items-center gap-3 border-r border-slate-200 px-5 py-3.5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i></span><div><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Applicants</dt><dd class="mt-0.5 text-xl font-black text-slate-950">{{ stats.applicants }}</dd></div></div>
            <div class="flex items-center gap-3 border-r border-slate-200 px-5 py-3.5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-building-columns" aria-hidden="true"></i></span><div><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Providers</dt><dd class="mt-0.5 text-xl font-black text-slate-950">{{ stats.providers }}</dd></div></div>
            <div class="flex items-center gap-3 px-5 py-3.5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-800"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span><div><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Need attention</dt><dd class="mt-0.5 text-xl font-black text-slate-950">{{ stats.attention_total }}</dd></div></div>
        </dl>

        <AdminTableFrame title="Accounts" :description="loading ? 'Updating results...' : errorMessage ? 'Results unavailable' : resultLabel" :loading="loading" :error="errorMessage" loading-message="Loading accounts..." icon="fa-solid fa-address-book" class="mt-4" @retry="resource.retry">
            <template #filters>
                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_14rem]">
                    <label class="relative">
                        <span class="sr-only">Search accounts</span>
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                        <input v-model="search" type="search" maxlength="120" placeholder="Search name, email, or username" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950">
                    </label>
                    <select v-model="role" aria-label="Account role" class="h-11 border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950">
                        <option value="all">All account roles</option>
                        <option value="applicant">Applicants</option>
                        <option value="provider">Providers</option>
                    </select>
                </div>
            </template>

            <AccountDirectoryTable :accounts="accounts" :record-link="queueState.link" />

            <template v-if="pagination.last_page > 1" #footer>
                <div class="flex items-center justify-between text-sm text-slate-600">
                    <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                    <div class="flex gap-2">
                        <button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button>
                        <button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button>
                    </div>
                </div>
            </template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
