<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderQueueTabs from '../components/ProviderQueueTabs.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';

const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ attention: 0, active: 0, suspended: 0, total: 0 });
const nextTask = ref(null);
const roles = ref([]);
const accounts = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const updatingId = ref(null);
const allowedQueues = ['attention', 'active', 'suspended'];
const pageUrl = new URL(window.location.href);
const requestedQueue = pageUrl.searchParams.get('queue');
const activeQueue = ref(allowedQueues.includes(requestedQueue) ? requestedQueue : 'attention');
const selectedRole = ref(pageUrl.searchParams.get('role') ?? '');
const searchQuery = ref('');
let searchTimer = null;
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected'
    ? 'Delegation limited to assigned programs'
    : `${workspace.value?.grantable_permission_count ?? 0} permission area${Number(workspace.value?.grantable_permission_count) === 1 ? '' : 's'} grantable`);
const queueTabs = computed(() => [
    { key: 'attention', label: 'Needs attention', count: Number(summary.value.attention ?? 0) },
    { key: 'active', label: 'Active access', count: Number(summary.value.active ?? 0) },
    { key: 'suspended', label: 'Suspended', count: Number(summary.value.suspended ?? 0) },
]);
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);
const queueHeading = computed(() => ({
    attention: 'Accounts with incomplete setup',
    active: 'Staff with active access',
    suspended: 'Accounts without sign-in access',
}[activeQueue.value]));

function accountInitials(name) {
    return String(name ?? 'Staff')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function stateClass(state) {
    return {
        attention: 'bg-amber-100 text-amber-900',
        active: 'bg-slate-100 text-slate-700',
        suspended: 'bg-rose-100 text-rose-800',
        create: 'bg-amber-100 text-amber-900',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function scopeLabel(account) {
    if (account.program_access_mode === 'all') return 'All organization programs';
    return `${account.assigned_program_count} assigned program${Number(account.assigned_program_count) === 1 ? '' : 's'}`;
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);

    if (activeQueue.value === 'attention') nextUrl.searchParams.delete('queue');
    else nextUrl.searchParams.set('queue', activeQueue.value);

    if (selectedRole.value) nextUrl.searchParams.set('role', selectedRole.value);
    else nextUrl.searchParams.delete('role');

    window.history.replaceState({}, '', nextUrl);
}

async function loadWorkspace(page = 1, initial = false) {
    if (initial) isLoading.value = true;
    else isRefreshing.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/team/data', {
            params: {
                queue: activeQueue.value,
                search: searchQuery.value.trim() || undefined,
                role: selectedRole.value || undefined,
                page,
            },
        });
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        nextTask.value = response.data.next_task;
        roles.value = response.data.roles ?? [];
        accounts.value = response.data.accounts ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load team access.';
    } finally {
        isLoading.value = false;
        isRefreshing.value = false;
    }
}

function selectQueue(queue) {
    activeQueue.value = queue;
    loadWorkspace(1);
}

async function toggleStatus(account) {
    const suspending = account.account_status !== 'suspended';
    const confirmed = await requestConfirmation({
        title: suspending ? 'Suspend this staff account?' : 'Reactivate this staff account?',
        message: suspending
            ? `${account.name} will lose sign-in access until reactivated.`
            : `${account.name} will regain the role and program access currently assigned.`,
        confirmLabel: suspending ? 'Suspend account' : 'Reactivate account',
        tone: suspending ? 'danger' : 'default',
    });

    if (!confirmed) return;

    updatingId.value = account.id;
    errorMessage.value = '';

    try {
        await window.axios.patch(`/provider/team/accounts/${account.id}/status`, {
            account_status: suspending ? 'suspended' : 'active',
        });
        await loadWorkspace(pagination.value.current_page);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update this account.';
    } finally {
        updatingId.value = null;
    }
}

watch(selectedRole, () => loadWorkspace(1));
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading team access" message="Preparing account setup, active access, and suspended records." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Team access is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="team" title="Team access" description="Maintain individual staff accounts and deliberately scoped workspace access." icon="fa-solid fa-users-gear">
                        <template #actions>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <a href="/provider/team/accounts/create" class="bg-slate-950 px-4 py-2.5 text-center text-xs font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-user-plus mr-2 text-amber-300"></i>Add member</a>
                            </div>
                        </template>
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextTask" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950"><i :class="['fa-solid text-sm', nextTask.state === 'create' ? 'fa-user-plus' : 'fa-user-clock']"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Next task</p>
                                <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextTask.title }}</h2>
                                <p class="mt-0.5 truncate text-xs text-slate-500">{{ workspace.organization_name }}</p>
                            </div>
                            <div class="shrink-0 border-l border-slate-200 pl-4">
                                <span :class="['inline-flex px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', stateClass(nextTask.state)]">{{ nextTask.label }}</span>
                                <p class="mt-1 max-w-sm text-xs font-semibold text-slate-600">{{ nextTask.detail }}</p>
                            </div>
                            <a :href="nextTask.action_url" class="shrink-0 bg-slate-950 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-800">{{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i></a>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div><h2 class="text-sm font-bold text-slate-950">Staff setup is current</h2><p class="mt-0.5 text-xs text-slate-500">No active team account is waiting for first-login setup.</p></div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
                            <div><p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-amber-700">Access directory</p><h2 class="mt-0.5 text-base font-bold text-slate-950">{{ queueHeading }}</h2></div>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">{{ activeQueueTab.count }} {{ activeQueueTab.count === 1 ? 'account' : 'accounts' }}</p>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Team access states" @select="selectQueue" />

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3 xl:grid-cols-[minmax(18rem,1fr)_minmax(14rem,.45fr)]">
                            <label class="relative block"><span class="sr-only">Search team accounts</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i><input v-model="searchQuery" type="search" placeholder="Search member, email, or role" class="w-full rounded border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            <label><span class="sr-only">Filter by role</span><select v-model="selectedRole" class="w-full rounded border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"><option value="">All roles</option><option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option></select></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="accounts.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(18rem,1.2fr)_minmax(17rem,1fr)_minmax(16rem,.9fr)_10rem] gap-4 bg-slate-50 px-5 py-2.5 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid"><span>Staff member</span><span>Access state</span><span>Assigned scope</span><span class="text-right">Actions</span></div>
                            <article v-for="account in accounts" :key="account.id" class="grid gap-4 px-5 py-3.5 transition hover:bg-slate-50 xl:grid-cols-[minmax(18rem,1.2fr)_minmax(17rem,1fr)_minmax(16rem,.9fr)_10rem] xl:items-center">
                                <div class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-950 text-xs font-black text-amber-300">{{ accountInitials(account.name) }}</span><div class="min-w-0"><div class="flex items-center gap-2"><h3 class="truncate text-sm font-bold text-slate-950">{{ account.name }}</h3><span v-if="account.is_current_account" class="bg-slate-200 px-1.5 py-0.5 text-[0.6rem] font-black uppercase text-slate-600">You</span></div><p class="mt-0.5 truncate text-xs text-slate-500">{{ account.email }}</p><p class="mt-0.5 text-xs font-semibold text-slate-700">{{ account.team_role_label }}</p></div></div>
                                <div><span :class="['inline-flex px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(account.work_state)]">{{ account.work_label }}</span><p class="mt-1.5 text-xs leading-5 text-slate-500">{{ account.detail }}</p></div>
                                <div><p class="text-sm font-bold text-slate-900">{{ scopeLabel(account) }}</p><p class="mt-1 text-xs text-slate-500">{{ account.permission_count }} permission area{{ Number(account.permission_count) === 1 ? '' : 's' }}</p><p v-if="!account.can_manage" class="mt-1 text-xs font-semibold text-amber-700"><i class="fa-solid fa-lock mr-1"></i>Protected access</p></div>
                                <div class="flex items-center justify-end gap-2">
                                    <a v-if="account.action_url" :href="account.action_url" class="grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-900" title="Edit account" aria-label="Edit account"><i class="fa-solid fa-pen"></i></a>
                                    <button v-if="account.can_manage && !account.is_current_account" type="button" :disabled="updatingId === account.id" :class="['grid h-9 w-9 place-items-center border disabled:opacity-50', account.account_status === 'suspended' ? 'border-slate-300 bg-white text-slate-700' : 'border-rose-300 bg-rose-50 text-rose-700']" :title="account.account_status === 'suspended' ? 'Reactivate account' : 'Suspend account'" :aria-label="account.account_status === 'suspended' ? 'Reactivate account' : 'Suspend account'" @click="toggleStatus(account)"><i :class="['fa-solid', updatingId === account.id ? 'fa-circle-notch animate-spin' : (account.account_status === 'suspended' ? 'fa-user-check' : 'fa-user-slash')]" aria-hidden="true"></i></button>
                                    <span v-if="!account.can_manage" class="text-xs font-bold text-slate-400">Owner only</span>
                                </div>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-users"></i></span><h3 class="mt-3 text-sm font-bold text-slate-900">No accounts in this view</h3><p class="mt-1 text-sm text-slate-500">Try another access state, role, or search.</p></div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="accounts" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>

        <ConfirmationDialog :state="confirmation" @confirm="confirmConfirmation" @cancel="cancelConfirmation" />
    </main>
</template>
