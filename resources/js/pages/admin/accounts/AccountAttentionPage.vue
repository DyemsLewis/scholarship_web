<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { createStaffQueueState } from '../../../support/staffQueueState.js';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import AccountDirectoryTable from './AccountDirectoryTable.vue';

const issueOptions = [
    { value: 'needs_attention', label: 'All issues' },
    { value: 'unverified', label: 'Email unverified' },
    { value: 'password_reset', label: 'Password reset' },
    { value: 'suspended', label: 'Suspended' },
    { value: 'support_handoff', label: 'Support referrals' },
];
const queueState = createStaffQueueState(window, {'search':'search','issue':'attention'});
const requestedIssue = queueState.get('issue');
const issue = ref(issueOptions.some((option) => option.value === requestedIssue) ? requestedIssue : 'needs_attention');
const search = ref(queueState.get('search') ?? '');
const resource = useAdminResource(async ({ signal }, params) => {
    const response = await queueState.load((query) => window.axios.get('/admin/workspaces/accounts/data', { params: query, signal }), params, () => !signal.aborted);
    return response.data;
}, { failureMessage: 'The attention queue could not be loaded.' });
const { isLoading: loading, errorMessage, hasLoaded } = resource;
const accounts = computed(() => resource.data.value?.users ?? []);
const stats = computed(() => resource.data.value?.stats ?? {});
const pagination = computed(() => resource.data.value?.pagination ?? { current_page: 1, last_page: 1, total: 0 });
let searchTimer;

const issueCounts = computed(() => ({
    needs_attention: stats.value.attention_total,
    unverified: stats.value.unverified_users,
    password_reset: stats.value.password_resets_required,
    suspended: stats.value.suspended_users,
    support_handoff: stats.value.support_handoffs,
}));

function loadAccounts(page = 1) {
    clearTimeout(searchTimer);
    return resource.load({ page, per_page: 10, search: search.value.trim() || undefined, attention: issue.value });
}

function selectIssue(value) {
    issue.value = value;
    const url = new URL(window.location.href);
    value === 'needs_attention' ? url.searchParams.delete('issue') : url.searchParams.set('issue', value);
    window.history.replaceState({}, '', url);
}

function changePage(page) {
    if (loading.value || page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
    loadAccounts(page);
}

watch([search, issue], () => {
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
            title="Needs attention"
            description="Resolve sign-in and account access issues."
            icon="fa-solid fa-triangle-exclamation"
        />

        <AdminTableFrame title="Access queue" :description="loading ? 'Updating results...' : errorMessage ? 'Results unavailable' : `${pagination.total} account${pagination.total === 1 ? '' : 's'} in this view`" :loading="loading" :error="errorMessage" loading-message="Loading accounts..." icon="fa-solid fa-shield-halved" class="mt-4" @retry="resource.retry">
            <template #filters>
                <div class="grid gap-3">
                    <div class="grid grid-cols-5 overflow-hidden border border-slate-300 bg-white">
                        <button
                            v-for="option in issueOptions"
                            :key="option.value"
                            type="button"
                            :aria-pressed="issue === option.value"
                            :class="['flex h-11 items-center justify-between border-r border-slate-200 px-4 text-xs font-bold transition last:border-r-0', issue === option.value ? 'bg-slate-950 text-white' : 'bg-white text-slate-600 hover:bg-slate-100']"
                            @click="selectIssue(option.value)"
                        >
                            {{ option.label }} <span v-if="hasLoaded" :class="issue === option.value ? 'text-amber-300' : 'text-slate-500'">{{ issueCounts[option.value] }}</span>
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
                :accounts="accounts" :record-link="queueState.link"
                show-issue
                empty-title="No accounts need attention here"
            />

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
