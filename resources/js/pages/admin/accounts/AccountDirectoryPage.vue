<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import AccountDirectoryTable from './AccountDirectoryTable.vue';

const loading = ref(true);
const errorMessage = ref('');
const search = ref('');
const requestedRole = new URLSearchParams(window.location.search).get('role');
const role = ref(['applicant', 'provider'].includes(requestedRole) ? requestedRole : 'all');
const accounts = ref([]);
const stats = ref({ total_users: 0, active_users: 0, recent_signups: 0 });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => {
    if (!pagination.value.total) return '0 accounts';
    return `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`;
});

async function loadAccounts(page = 1) {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/accounts/data', {
            params: {
                page,
                per_page: 10,
                search: search.value.trim() || undefined,
                role: role.value,
                attention: 'all',
            },
        });
        accounts.value = response.data.users ?? [];
        stats.value = response.data.stats ?? stats.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The account directory could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
    loadAccounts(page);
}

watch([search, role], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadAccounts(1), 250);
});

onMounted(loadAccounts);
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

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <dl class="mt-4 grid grid-cols-3 border border-slate-300 bg-white shadow-sm">
            <div class="border-r border-slate-200 px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">All accounts</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ stats.total_users }}</dd></div>
            <div class="border-r border-slate-200 px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Active</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ stats.active_users }}</dd></div>
            <div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Added this week</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ stats.recent_signups }}</dd></div>
        </dl>

        <AdminTableFrame title="Portal accounts" :description="resultLabel" class="mt-4">
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

            <AccountDirectoryTable :accounts="accounts" :loading="loading" />

            <template v-if="pagination.last_page > 1" #footer>
                <div class="flex items-center justify-between text-sm text-slate-600">
                    <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                    <div class="flex gap-2">
                        <button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button>
                        <button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button>
                    </div>
                </div>
            </template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
