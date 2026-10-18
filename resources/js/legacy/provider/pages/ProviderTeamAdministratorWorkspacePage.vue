<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
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
const url = new URL(window.location.href);
const pathSection = url.pathname.split('/').filter(Boolean).at(-1);
const pathQueueMap = { setup: 'attention', active: 'active', suspended: 'suspended' };
const queueOptions = ['attention', 'active', 'suspended'];
const requestedQueue = pathQueueMap[pathSection] ?? url.searchParams.get('queue');
const activeQueue = ref(queueOptions.includes(requestedQueue) ? requestedQueue : 'attention');
const selectedRole = ref(url.searchParams.get('role') ?? '');
const searchQuery = ref('');
let searchTimer = null;
const { confirmation, requestConfirmation, confirmConfirmation, cancelConfirmation } = useConfirmationDialog();

const queueSections = computed(() => [
    { key: 'attention', label: 'Setup required', shortLabel: 'Setup', description: 'Finish account setup before staff begin provider work.', count: Number(summary.value.attention ?? 0), href: '/provider/workspaces/team/setup', icon: 'fa-user-clock' },
    { key: 'active', label: 'Active staff accounts', shortLabel: 'Active', description: 'Manage staff roles, permissions, and program scope.', count: Number(summary.value.active ?? 0), href: '/provider/workspaces/team/active', icon: 'fa-user-check' },
    { key: 'suspended', label: 'Suspended staff accounts', shortLabel: 'Suspended', description: 'Review accounts whose sign-in access is disabled.', count: Number(summary.value.suspended ?? 0), href: '/provider/workspaces/team/suspended', icon: 'fa-user-lock' },
]);
const activeSection = computed(() => queueSections.value.find((section) => section.key === activeQueue.value) ?? queueSections.value[0]);
const leadTask = computed(() => {
    if (activeQueue.value !== 'attention') return null;
    if (accounts.value[0]) {
        return {
            title: accounts.value[0].name,
            detail: accounts.value[0].detail,
            action_label: 'Review account',
            action_url: accounts.value[0].action_url,
        };
    }
    return nextTask.value?.state === 'create' ? nextTask.value : null;
});

function stateClass(state) {
    return {
        attention: 'text-amber-700',
        active: 'text-emerald-700',
        suspended: 'text-rose-700',
        create: 'text-amber-700',
    }[state] ?? 'text-slate-700';
}

function stateIcon(state) {
    return {
        attention: 'fa-solid fa-triangle-exclamation',
        active: 'fa-solid fa-circle-check',
        suspended: 'fa-solid fa-circle-pause',
        create: 'fa-solid fa-user-plus',
    }[state] ?? 'fa-solid fa-circle-info';
}

function scopeLabel(account) {
    if (account.program_access_mode === 'all') return 'All organization programs';
    return `${account.assigned_program_count} assigned program${Number(account.assigned_program_count) === 1 ? '' : 's'}`;
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);
    const usesQueuePath = Object.prototype.hasOwnProperty.call(pathQueueMap, pathSection);

    if (usesQueuePath || activeQueue.value === 'attention') nextUrl.searchParams.delete('queue');
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading team access" message="Preparing staff account records." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Team access is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="team" :show-role-guide="false" :title="activeSection.label" :description="activeSection.description" icon="fa-solid fa-users-gear">
                        <template #actions><a href="/provider/team/accounts/create" class="bg-slate-950 px-4 py-2.5 text-center text-xs font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-user-plus mr-2 text-amber-300" aria-hidden="true"></i>Add staff account</a></template>
                    </ProviderPageHeader>

                    <nav class="mt-4 grid grid-cols-3 border border-slate-300 bg-white" aria-label="Team access pages">
                        <a v-for="section in queueSections" :key="section.key" :href="section.href" :aria-current="section.key === activeQueue ? 'page' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-4 last:border-r-0', section.key === activeQueue ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                            <i :class="['fa-solid', section.icon, section.key === activeQueue ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ section.shortLabel }}</span><span :class="['mt-0.5 block truncate text-[0.68rem]', section.key === activeQueue ? 'text-slate-300' : 'text-slate-500']">{{ section.count }} account{{ section.count === 1 ? '' : 's' }}</span></span>
                        </a>
                    </nav>

                    <section v-if="leadTask" class="mt-3 border border-slate-300 border-l-4 border-l-amber-500 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex items-center gap-4 px-5 py-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1"><p class="text-[0.64rem] font-black uppercase tracking-[0.16em] text-amber-700">Review next</p><h2 class="mt-0.5 truncate text-base font-bold text-slate-950">{{ leadTask.title }}</h2><p class="mt-0.5 line-clamp-1 text-sm text-slate-500">{{ leadTask.detail }}</p></div>
                            <a v-if="leadTask.action_url" :href="leadTask.action_url" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">{{ leadTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-3 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                            <div><h2 class="text-base font-bold text-slate-950">{{ pagination.total }} account{{ pagination.total === 1 ? '' : 's' }}</h2><p class="mt-0.5 text-xs text-slate-500">{{ summary.total }} total staff records in this organization.</p></div>
                            <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-circle-notch mr-1.5 animate-spin" aria-hidden="true"></i>Updating</span>
                        </header>

                        <div class="grid grid-cols-[minmax(18rem,1fr)_minmax(15rem,.55fr)] gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <label class="relative block"><span class="sr-only">Search team accounts</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchQuery" type="search" placeholder="Search staff name, email, or role" class="w-full border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            <label><span class="sr-only">Filter by role</span><select v-model="selectedRole" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"><option value="">All assigned roles</option><option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option></select></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                        <div v-if="accounts.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[72rem] table-fixed">
                                <caption class="sr-only">{{ activeSection.label }}</caption>
                                <colgroup><col class="w-[31%]"><col class="w-[24%]"><col class="w-[27%]"><col class="w-[18%]"></colgroup>
                                <thead><tr><th scope="col">Staff member and role</th><th scope="col">Access status</th><th scope="col">Assigned access</th><th scope="col">Account controls</th></tr></thead>
                                <tbody>
                                    <tr v-for="account in accounts" :key="account.id">
                                        <td><div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span><div class="min-w-0"><p class="truncate font-bold text-slate-950">{{ account.name }}<span v-if="account.is_current_account" class="font-normal text-slate-500"> (You)</span></p><p class="mt-0.5 truncate text-xs text-slate-500">{{ account.team_role_label }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ account.email }}</p></div></div></td>
                                        <td><p :class="['font-semibold', stateClass(account.work_state)]"><i :class="[stateIcon(account.work_state), 'mr-1.5']" aria-hidden="true"></i>{{ account.work_label }}</p><p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ account.detail }}</p></td>
                                        <td><p class="font-semibold text-slate-800">{{ scopeLabel(account) }}</p><p class="mt-1 text-xs text-slate-500">{{ account.permission_count }} permission area{{ Number(account.permission_count) === 1 ? '' : 's' }}</p><p v-if="!account.can_manage" class="mt-1 text-xs text-slate-500"><i class="fa-solid fa-lock mr-1" aria-hidden="true"></i>Protected account</p></td>
                                        <td><div class="flex flex-wrap items-center gap-3"><a v-if="account.action_url" :href="account.action_url" class="inline-flex items-center border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">Manage<i class="fa-solid fa-arrow-right ml-2 text-[9px]" aria-hidden="true"></i></a><button v-if="account.can_manage && !account.is_current_account" type="button" :disabled="updatingId === account.id" :class="['px-3 py-2 text-xs font-bold disabled:cursor-wait disabled:opacity-50', account.account_status === 'suspended' ? 'border border-slate-300 bg-white text-slate-700 hover:border-slate-950' : 'border border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100']" @click="toggleStatus(account)"><i :class="[updatingId === account.id ? 'fa-circle-notch animate-spin' : (account.account_status === 'suspended' ? 'fa-circle-play' : 'fa-circle-pause'), 'fa-solid mr-1.5']" aria-hidden="true"></i>{{ account.account_status === 'suspended' ? 'Reactivate' : 'Suspend' }}</button><span v-if="!account.can_manage && !account.action_url" class="text-xs text-slate-400"><i class="fa-solid fa-lock mr-1" aria-hidden="true"></i>Protected</span></div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="px-6 py-12 text-center"><i class="fa-solid fa-users text-2xl text-slate-300" aria-hidden="true"></i><h3 class="mt-3 text-sm font-bold text-slate-900">No accounts on this page</h3><p class="mt-1 text-sm text-slate-500">{{ searchQuery || selectedRole ? 'Clear the filters to check the full list.' : 'Staff accounts will appear here when they reach this access state.' }}</p></div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="accounts" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>

        <ConfirmationDialog :state="confirmation" @confirm="confirmConfirmation" @cancel="cancelConfirmation" />
    </main>
</template>
