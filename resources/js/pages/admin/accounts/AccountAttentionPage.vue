<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import AccountDirectoryTable from './AccountDirectoryTable.vue';

const issueOptions = [
    { value: 'needs_attention', label: 'All issues' },
    { value: 'unverified', label: 'Email unverified' },
    { value: 'password_reset', label: 'Password reset' },
    { value: 'suspended', label: 'Suspended' },
];
const requestedIssue = new URLSearchParams(window.location.search).get('issue');
const issue = ref(issueOptions.some((option) => option.value === requestedIssue) ? requestedIssue : 'needs_attention');
const search = ref('');
const loading = ref(true);
const errorMessage = ref('');
const accounts = ref([]);
const stats = ref({ attention_total: 0, unverified_users: 0, password_resets_required: 0, suspended_users: 0 });
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
let searchTimer;

const issueCounts = computed(() => ({
    needs_attention: stats.value.attention_total,
    unverified: stats.value.unverified_users,
    password_reset: stats.value.password_resets_required,
    suspended: stats.value.suspended_users,
}));

async function loadAccounts(page = 1) {
    loading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/accounts/data', {
            params: {
                page,
                per_page: 10,
                search: search.value.trim() || undefined,
                attention: issue.value,
            },
        });
        accounts.value = response.data.users ?? [];
        stats.value = response.data.stats ?? stats.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The attention queue could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function selectIssue(value) {
    issue.value = value;
    const url = new URL(window.location.href);
    value === 'needs_attention' ? url.searchParams.delete('issue') : url.searchParams.set('issue', value);
    window.history.replaceState({}, '', url);
}

function changePage(page) {
    if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
    loadAccounts(page);
}

watch([search, issue], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadAccounts(1), 250);
});

onMounted(loadAccounts);
</script>

<template>
    <AdminWorkspacePage workspace="account_manager">
        <AdminWorkspaceHeader
            eyebrow="Account access"
            title="Needs attention"
            description="Resolve sign-in and account access issues."
            icon="fa-solid fa-triangle-exclamation"
        />

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <AdminTableFrame title="Access queue" :description="`${pagination.total} account${pagination.total === 1 ? '' : 's'} in this view`" icon="fa-solid fa-shield-halved" class="mt-4">
            <template #filters>
                <div class="grid gap-3">
                    <div class="grid grid-cols-4 overflow-hidden border border-slate-300 bg-white">
                        <button
                            v-for="option in issueOptions"
                            :key="option.value"
                            type="button"
                            :class="['flex h-11 items-center justify-between border-r border-slate-200 px-4 text-xs font-bold transition last:border-r-0', issue === option.value ? 'bg-slate-950 text-white' : 'bg-white text-slate-600 hover:bg-slate-100']"
                            @click="selectIssue(option.value)"
                        >
                            {{ option.label }} <span :class="issue === option.value ? 'text-amber-300' : 'text-slate-400'">{{ issueCounts[option.value] }}</span>
                        </button>
                    </div>
                    <label class="relative w-full">
                        <span class="sr-only">Search attention queue</span>
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                        <input v-model="search" type="search" maxlength="120" placeholder="Search name, email, or username" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950">
                    </label>
                </div>
            </template>

            <AccountDirectoryTable
                :accounts="accounts"
                :loading="loading"
                show-issue
                empty-title="No accounts need attention here"
            />

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
