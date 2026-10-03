<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
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
const queueCopy = computed(() => ({
    attention: {
        title: 'Accounts with incomplete setup',
        description: 'Follow up on email verification and temporary password replacement.',
    },
    active: {
        title: 'Staff with active workspace access',
        description: 'Review assigned roles, permissions, and program boundaries.',
    },
    suspended: {
        title: 'Accounts without sign-in access',
        description: 'Keep inactive staff separate from the current access directory.',
    },
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
        active: 'bg-emerald-100 text-emerald-800',
        suspended: 'bg-rose-100 text-rose-800',
        create: 'bg-cyan-100 text-cyan-900',
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
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">Loading team access...</div>
                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                <template v-else>
                    <header class="rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-cyan-700 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-cyan-300"><i class="fa-solid fa-users-gear"></i></span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-cyan-700">Team administrator</p>
                                    <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-950">Team access</h1>
                                    <p class="mt-1 text-sm text-slate-600">Maintain separate staff accounts and controlled workspace access.</p>
                                </div>
                            </div>
                            <div class="flex flex-col gap-3 border-t border-slate-200 pt-3 sm:flex-row sm:items-center lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0">
                                <div class="lg:text-right">
                                    <p class="text-xs font-bold text-slate-900">{{ workspace.organization_name }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ accessLabel }}</p>
                                </div>
                                <a href="/provider/team/accounts/create" class="rounded-md bg-slate-950 px-4 py-2.5 text-center text-xs font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-user-plus mr-2 text-cyan-300"></i>Add member</a>
                            </div>
                        </div>
                    </header>

                    <section v-if="nextTask" class="mt-4 overflow-hidden rounded-lg border border-slate-900 bg-white shadow-sm">
                        <div class="grid lg:grid-cols-[8rem_minmax(0,1fr)_minmax(16rem,.75fr)_auto] lg:items-stretch">
                            <div class="flex items-center justify-center bg-slate-950 px-4 py-4 text-white lg:py-5"><div class="text-center"><p class="text-[0.62rem] font-black uppercase tracking-[0.18em] text-cyan-300">Next task</p><i :class="['mt-2 text-lg fa-solid', nextTask.state === 'create' ? 'fa-user-plus' : 'fa-user-clock']"></i></div></div>
                            <div class="min-w-0 border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r"><div class="flex flex-wrap items-center gap-2"><h2 class="text-base font-bold text-slate-950">{{ nextTask.title }}</h2><span :class="['rounded px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', stateClass(nextTask.state)]">{{ nextTask.label }}</span></div><p class="mt-1 text-sm text-slate-500">{{ workspace.organization_name }}</p></div>
                            <div class="flex items-center border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r"><p class="text-sm font-semibold leading-6 text-slate-800">{{ nextTask.detail }}</p></div>
                            <div class="flex items-center px-5 py-4"><a :href="nextTask.action_url" class="w-full rounded-md bg-cyan-700 px-4 py-2.5 text-center text-sm font-black text-white hover:bg-cyan-800 lg:w-auto">{{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs"></i></a></div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div><h2 class="text-sm font-bold text-emerald-950">Staff setup is current</h2><p class="mt-0.5 text-sm text-emerald-800">No active team account is waiting for first-login setup.</p></div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                                <div><p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-cyan-700">Access desk</p><h2 class="mt-1 text-lg font-bold text-slate-950">{{ queueCopy.title }}</h2><p class="mt-1 text-sm text-slate-500">{{ queueCopy.description }}</p></div>
                                <div class="grid gap-2 sm:grid-cols-[minmax(15rem,1fr)_minmax(12rem,.7fr)] xl:w-[38rem]">
                                    <label class="relative block"><span class="sr-only">Search team accounts</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i><input v-model="searchQuery" type="search" placeholder="Search member, email, or role" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm outline-none focus:border-cyan-600 focus:ring-3 focus:ring-cyan-100"></label>
                                    <label><span class="sr-only">Filter by role</span><select v-model="selectedRole" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-600 focus:ring-3 focus:ring-cyan-100"><option value="">All roles</option><option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option></select></label>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center gap-1 overflow-x-auto border-t border-slate-200 pt-2" aria-label="Team access states">
                                <button v-for="tab in queueTabs" :key="tab.key" type="button" :class="['shrink-0 border-b-2 px-4 py-2 text-xs font-bold transition', activeQueue === tab.key ? 'border-cyan-700 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800']" @click="selectQueue(tab.key)">{{ tab.label }} <span :class="['ml-1 rounded px-1.5 py-0.5', activeQueue === tab.key ? 'bg-cyan-100 text-cyan-800' : 'bg-slate-200 text-slate-600']">{{ tab.count }}</span></button>
                                <span v-if="isRefreshing" class="ml-auto shrink-0 text-xs font-semibold text-slate-400"><i class="fa-solid fa-circle-notch mr-1 animate-spin"></i>Updating</span>
                            </div>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="accounts.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(16rem,1.2fr)_minmax(14rem,1fr)_minmax(13rem,.85fr)_11rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid"><span>Staff member</span><span>Access state</span><span>Assigned scope</span><span class="text-right">Actions</span></div>
                            <article v-for="account in accounts" :key="account.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6 xl:grid-cols-[minmax(16rem,1.2fr)_minmax(14rem,1fr)_minmax(13rem,.85fr)_11rem] xl:items-center">
                                <div class="flex min-w-0 items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-black text-cyan-300">{{ accountInitials(account.name) }}</span><div class="min-w-0"><div class="flex items-center gap-2"><h3 class="truncate text-sm font-bold text-slate-950">{{ account.name }}</h3><span v-if="account.is_current_account" class="rounded bg-slate-200 px-1.5 py-0.5 text-[0.6rem] font-black uppercase text-slate-600">You</span></div><p class="mt-1 truncate text-xs text-slate-500">{{ account.email }}</p><p class="mt-1 text-xs font-semibold text-slate-700">{{ account.team_role_label }}</p></div></div>
                                <div><span :class="['inline-flex rounded px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(account.work_state)]">{{ account.work_label }}</span><p class="mt-1.5 text-xs leading-5 text-slate-500">{{ account.detail }}</p></div>
                                <div><p class="text-sm font-bold text-slate-900">{{ scopeLabel(account) }}</p><p class="mt-1 text-xs text-slate-500">{{ account.permission_count }} permission area{{ Number(account.permission_count) === 1 ? '' : 's' }}</p><p v-if="!account.can_manage" class="mt-1 text-xs font-semibold text-amber-700"><i class="fa-solid fa-lock mr-1"></i>Protected access</p></div>
                                <div class="flex items-center justify-end gap-2">
                                    <a v-if="account.action_url" :href="account.action_url" class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 bg-white text-slate-700 hover:border-slate-900" title="Edit account" aria-label="Edit account"><i class="fa-solid fa-pen"></i></a>
                                    <button v-if="account.can_manage && !account.is_current_account" type="button" :disabled="updatingId === account.id" :class="['grid h-9 w-9 place-items-center rounded-md border disabled:opacity-50', account.account_status === 'suspended' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-rose-300 bg-rose-50 text-rose-700']" :title="account.account_status === 'suspended' ? 'Reactivate account' : 'Suspend account'" :aria-label="account.account_status === 'suspended' ? 'Reactivate account' : 'Suspend account'" @click="toggleStatus(account)"><i :class="['fa-solid', updatingId === account.id ? 'fa-circle-notch animate-spin' : (account.account_status === 'suspended' ? 'fa-user-check' : 'fa-user-slash')]" aria-hidden="true"></i></button>
                                    <span v-if="!account.can_manage" class="text-xs font-bold text-slate-400">Owner only</span>
                                </div>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-users"></i></span><h3 class="mt-3 text-sm font-bold text-slate-900">No accounts in this view</h3><p class="mt-1 text-sm text-slate-500">Try another access state, role, or search.</p></div>

                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6"><p class="text-xs font-semibold text-slate-500">{{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</p><div class="flex gap-2"><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isRefreshing" @click="loadWorkspace(pagination.current_page - 1)">Previous</button><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isRefreshing" @click="loadWorkspace(pagination.current_page + 1)">Next</button></div></div>
                    </section>

                    <p class="mt-4 border-l-2 border-cyan-700 px-4 py-2 text-sm text-slate-600">This desk manages delegated staff access only. Organization ownership and accounts with broader authority remain protected.</p>
                </template>
            </div>
        </section>

        <ConfirmationDialog :state="confirmation" @confirm="confirmConfirmation" @cancel="cancelConfirmation" />
    </main>
</template>
