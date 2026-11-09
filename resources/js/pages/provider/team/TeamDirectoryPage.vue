<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import { createStaffQueueState } from '../../../support/staffQueueState.js';
import ProviderActionDialog from '../../../components/ProviderActionDialog.vue';

const queueByPath = {
    '/provider/workspaces/team/setup': 'attention',
    '/provider/workspaces/team/active': 'active',
    '/provider/workspaces/team/suspended': 'suspended',
};
const queueCopy = {
    attention: { title: 'Setup required', description: 'Finish account setup before staff begin provider work.', empty: 'No staff accounts need setup.' },
    active: { title: 'Active accounts', description: 'Review current roles, responsibilities, and program access.', empty: 'No active staff accounts match this view.' },
    suspended: { title: 'Suspended accounts', description: 'Review staff accounts whose sign-in access is disabled.', empty: 'No staff accounts are suspended.' },
};
const path = (window.portalPathname?.() ?? window.location.pathname).replace(/\/$/, '') || '/provider/workspaces/team/setup';
const activeQueue = queueByPath[path] ?? 'attention';
const pageCopy = queueCopy[activeQueue];
const isLoading = ref(true);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ attention: 0, active: 0, suspended: 0, total: 0 });
const nextTask = ref(null);
const roles = ref([]);
const accounts = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const queueState = createStaffQueueState(window, ['search','role']);
const search = ref(queueState.get('search') ?? '');
const selectedRole = ref(queueState.get('role') ?? '');
const updatingId = ref(null);
const confirmation = ref(null);
const confirmationError = ref('');
let searchTimer = null;
let requestVersion = 0;
let requestedPage = 1;

const resultRange = computed(() => isLoading.value ? 'Updating results...' : errorMessage.value ? 'Results unavailable' : pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 accounts');

function statusClass(state) {
    if (state === 'attention') return 'border-amber-200 bg-amber-50 text-amber-800';
    if (state === 'suspended') return 'border-rose-200 bg-rose-50 text-rose-800';
    return 'border-emerald-200 bg-emerald-50 text-emerald-800';
}

function accessLabel(account) {
    if (account.program_access_mode === 'all') return 'All organization programs';
    return `${account.assigned_program_count} assigned program${Number(account.assigned_program_count) === 1 ? '' : 's'}`;
}

function accountStateLabel(account) {
    return account.work_state === 'attention' && !account.email_verified && account.must_reset_password
        ? 'Email and password pending'
        : account.work_label;
}

function requestStatusChange(account) {
    if (!account.can_manage || account.is_current_account || updatingId.value !== null) return;
    confirmationError.value = '';
    const suspending = account.account_status !== 'suspended';
    confirmation.value = {
        account,
        status: suspending ? 'suspended' : 'active',
        title: suspending ? 'Suspend staff access?' : 'Reactivate staff access?',
        detail: suspending
            ? `${account.name} will be unable to sign in until the account is reactivated.`
            : `${account.name} will regain the role and program access currently assigned.`,
        action: suspending ? 'Suspend account' : 'Reactivate account',
    };
}

async function confirmStatusChange() {
    const request = confirmation.value;
    if (!request || updatingId.value !== null) return;
    updatingId.value = request.account.id;
    confirmationError.value = '';
    try {
        await window.axios.patch(`/provider/team/accounts/${request.account.id}/status`, { account_status: request.status });
        confirmation.value = null;
        await loadAccounts(pagination.value.current_page);
    } catch (error) {
        confirmationError.value = error.response?.data?.message || 'The account status could not be updated.';
    } finally {
        updatingId.value = null;
    }
}

function cancelStatusChange() {
    if (updatingId.value !== null) return;
    confirmation.value = null;
    confirmationError.value = '';
}

async function loadAccounts(page = 1) {
    window.clearTimeout(searchTimer);
    requestedPage = page;
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const params = {
            queue: activeQueue,
            search: search.value.trim() || undefined,
            role: selectedRole.value || undefined,
            page,
        };
        const response = await queueState.load((query) => window.axios.get('/provider/workspaces/team/data', { params: query }), params, () => version === requestVersion);
        if (version !== requestVersion) return;
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        nextTask.value = response.data.next_task;
        roles.value = response.data.roles ?? [];
        accounts.value = response.data.accounts ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The team account list could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

watch(search, () => {
    requestVersion++;
    isLoading.value = true;
    errorMessage.value = '';
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadAccounts(1), 300);
}, { flush: 'sync' });
watch(selectedRole, () => loadAccounts(1), { flush: 'sync' });
onMounted(() => loadAccounts(queueState.page));
onBeforeUnmount(() => {
    requestVersion++;
    window.clearTimeout(searchTimer);
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="team-directory-title">
            <div class="provider-settings-workspace mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-users-gear" aria-hidden="true"></i></span><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Team access</p><h1 id="team-directory-title" class="settings-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1><p class="mt-1 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p></div></div>
                        <div class="settings-page-actions flex items-center gap-5"><div class="settings-text text-right text-xs font-semibold text-slate-500"><p><span class="font-bold text-amber-700">{{ summary.attention }}</span> setup · <span class="font-bold text-slate-900">{{ summary.active }}</span> active · <span class="font-bold text-rose-700">{{ summary.suspended }}</span> suspended</p><p v-if="workspace" class="mt-1">{{ workspace.organization_name }}</p></div><a href="/provider/team/accounts/create" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-user-plus text-amber-300" aria-hidden="true"></i>Add team member</a></div>
                    </div>
                </header>

                <section v-if="activeQueue === 'attention' && nextTask" class="mt-4 border border-amber-300 border-l-4 border-l-amber-500 bg-amber-50 px-5 py-4 shadow-sm">
                    <div class="flex items-center gap-4"><span class="grid h-10 w-10 shrink-0 place-items-center bg-amber-200 text-slate-950"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-800">Next account task</p><h2 class="mt-1 text-base font-bold text-slate-950">{{ nextTask.title }}</h2><p class="mt-1 text-sm text-slate-600">{{ nextTask.detail }}</p></div><a v-if="nextTask.action_url" :href="queueState.link(nextTask.action_url)" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">{{ nextTask.action_label }}<i class="fa-solid fa-arrow-right text-[9px] text-amber-300" aria-hidden="true"></i></a></div>
                </section>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Team accounts">
                    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
                        <label class="relative min-w-0 flex-1"><span class="sr-only">Search team accounts</span><i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" class="h-11 w-full border border-slate-300 bg-white pl-10 pr-4 text-sm outline-none focus:border-slate-950" placeholder="Search name, email, username, or role"></label>
                        <label class="w-72"><span class="sr-only">Filter by role</span><select v-model="selectedRole" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option value="">All roles</option><option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option></select></label>
                    </div>


                    <StaffTableLoadError v-if="errorMessage" :message="errorMessage" @retry="loadAccounts(requestedPage)" />
                    <div v-else-if="isLoading" class="px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading team accounts...</p></div>
                    <div v-else-if="!accounts.length && !errorMessage" class="px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-users" aria-hidden="true"></i></span><p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p><p class="mt-1 text-xs text-slate-500">Use the sidebar to review another account state.</p></div>

                    <div v-else-if="accounts.length" class="staff-table-viewport">
                        <table class="w-full min-w-[56rem] table-fixed border-collapse text-left">
                            <colgroup><col><col class="w-36"><col class="w-48"><col class="w-32"><col class="w-36"></colgroup>
                            <caption class="sr-only">{{ pageCopy.title }}</caption>
                            <thead class="bg-slate-50"><tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-[0.16em] text-slate-600"><th class="px-5 py-3">Staff member</th><th class="px-5 py-3">Account state</th><th class="px-5 py-3">Assigned access</th><th class="px-5 py-3">Created</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="account in accounts" :key="account.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><div class="flex items-center gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span><div class="min-w-0"><p class="settings-text text-sm font-bold text-slate-950">{{ account.name }}<span v-if="account.is_current_account" class="font-normal text-slate-600"> (you)</span></p><p class="mt-1 settings-text text-xs text-slate-600">{{ account.team_role_label }}<template v-if="account.email"> &middot; {{ account.email }}</template></p></div></div></td>
                                    <td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-xs font-bold uppercase tracking-wide', statusClass(account.work_state)]">{{ accountStateLabel(account) }}</span></td>
                                    <td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ accessLabel(account) }}</p><p v-if="!account.can_manage" class="mt-1 text-xs font-semibold text-slate-500" :title="account.protected_reason"><i class="fa-solid fa-lock mr-1" aria-hidden="true"></i>Outside your access scope</p></td>
                                    <td class="px-5 py-4 text-sm font-semibold text-slate-600">{{ account.created_at }}</td>
                                    <td class="px-5 py-4"><div class="flex justify-end gap-2"><a v-if="account.action_url" :href="queueState.link(account.action_url)" class="staff-row-action staff-row-action--primary h-9 w-9" :aria-label="`Edit staff account for ${account.name}`" title="Edit staff account"><i class="fa-solid fa-pen text-xs" aria-hidden="true"></i></a><button v-if="account.can_manage && !account.is_current_account" type="button" :disabled="updatingId === account.id" :class="['grid h-9 w-9 place-items-center border disabled:opacity-40', account.account_status === 'suspended' ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' : 'border-rose-300 text-rose-700 hover:bg-rose-50']" :aria-label="account.account_status === 'suspended' ? 'Reactivate staff account' : 'Suspend staff account'" @click="requestStatusChange(account)"><i :class="['fa-solid text-xs', updatingId === account.id ? 'fa-circle-notch animate-spin' : account.account_status === 'suspended' ? 'fa-play' : 'fa-pause']" aria-hidden="true"></i></button><span v-if="!account.action_url" class="grid h-9 w-9 place-items-center border border-slate-200 text-slate-300" title="Protected account"><i class="fa-solid fa-lock text-xs" aria-hidden="true"></i></span></div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3"><p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p><div class="flex gap-2"><button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading || Boolean(errorMessage)" @click="loadAccounts(pagination.current_page - 1)">Previous</button><button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading || Boolean(errorMessage)" @click="loadAccounts(pagination.current_page + 1)">Next</button></div></footer>
                </section>
            </div>
        </section>

        <ProviderActionDialog :open="Boolean(confirmation)" :title="confirmation?.title" :message="confirmation?.detail" :confirm-label="confirmation?.action" :danger="confirmation?.status === 'suspended'" :busy="updatingId !== null" :error="confirmationError" @confirm="confirmStatusChange" @cancel="cancelStatusChange" />
    </main>
</template>
