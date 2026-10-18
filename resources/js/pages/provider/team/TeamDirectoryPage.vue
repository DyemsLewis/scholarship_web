<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

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
const path = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/team/setup';
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
const search = ref('');
const selectedRole = ref('');
const updatingId = ref(null);
const confirmation = ref(null);
let searchTimer = null;
let requestVersion = 0;

const resultRange = computed(() => pagination.value.total
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

function requestStatusChange(account) {
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
    if (!request) return;
    updatingId.value = request.account.id;
    errorMessage.value = '';
    try {
        await window.axios.patch(`/provider/team/accounts/${request.account.id}/status`, { account_status: request.status });
        confirmation.value = null;
        await loadAccounts(pagination.value.current_page);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The account status could not be updated.';
    } finally {
        updatingId.value = null;
    }
}

async function loadAccounts(page = 1) {
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/team/data', {
            params: {
                queue: activeQueue,
                search: search.value.trim() || undefined,
                role: selectedRole.value || undefined,
                page,
            },
        });
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
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadAccounts(1), 300);
});
watch(selectedRole, () => loadAccounts(1));
onMounted(() => loadAccounts());
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="team-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Team access</p><h1 id="team-directory-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1><p class="mt-2 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p></div>
                        <div class="flex items-center gap-5"><div class="text-right text-xs font-semibold text-slate-500"><p><span class="font-bold text-amber-700">{{ summary.attention }}</span> setup · <span class="font-bold text-slate-900">{{ summary.active }}</span> active · <span class="font-bold text-rose-700">{{ summary.suspended }}</span> suspended</p><p v-if="workspace" class="mt-1">{{ workspace.organization_name }}</p></div><a href="/provider/team/accounts/create" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-user-plus text-amber-300" aria-hidden="true"></i>Add team member</a></div>
                    </div>
                </header>

                <section v-if="activeQueue === 'attention' && nextTask" class="mt-4 border border-amber-300 border-l-4 border-l-amber-500 bg-amber-50 px-5 py-4 shadow-sm">
                    <div class="flex items-center gap-4"><span class="grid h-10 w-10 shrink-0 place-items-center bg-amber-200 text-slate-950"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-800">Next account task</p><h2 class="mt-1 text-base font-bold text-slate-950">{{ nextTask.title }}</h2><p class="mt-1 text-sm text-slate-600">{{ nextTask.detail }}</p></div><a v-if="nextTask.action_url" :href="nextTask.action_url" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">{{ nextTask.action_label }}<i class="fa-solid fa-arrow-right text-[9px] text-amber-300" aria-hidden="true"></i></a></div>
                </section>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Team accounts">
                    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
                        <label class="relative min-w-0 flex-1"><span class="sr-only">Search team accounts</span><i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" class="h-11 w-full border border-slate-300 bg-white pl-10 pr-4 text-sm outline-none focus:border-slate-950" placeholder="Search name, email, username, or role"></label>
                        <label class="w-72"><span class="sr-only">Filter by role</span><select v-model="selectedRole" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option value="">All roles</option><option v-for="role in roles" :key="role.value" :value="role.value">{{ role.label }}</option></select></label>
                    </div>

                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                    <div v-if="isLoading" class="px-6 py-16 text-center text-sm font-semibold text-slate-500">Loading team accounts...</div>
                    <div v-else-if="!accounts.length" class="px-6 py-16 text-center"><i class="fa-solid fa-users text-3xl text-slate-300" aria-hidden="true"></i><p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p><p class="mt-1 text-xs text-slate-500">Use the sidebar to review another account state.</p></div>

                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[76rem] table-fixed border-collapse text-left">
                            <caption class="sr-only">{{ pageCopy.title }}</caption>
                            <thead class="bg-slate-50"><tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500"><th class="w-[29%] px-5 py-3">Staff member</th><th class="w-[20%] px-5 py-3">Account state</th><th class="w-[25%] px-5 py-3">Assigned access</th><th class="w-[14%] px-5 py-3">Created</th><th class="w-[12%] px-5 py-3 text-right">Actions</th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="account in accounts" :key="account.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span><div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ account.name }}<span v-if="account.is_current_account" class="font-normal text-slate-400"> (you)</span></p><p class="mt-1 truncate text-xs font-semibold text-slate-600">{{ account.team_role_label }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ account.email }}</p></div></div></td>
                                    <td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(account.work_state)]">{{ account.work_label }}</span><p class="mt-2 line-clamp-2 text-xs text-slate-500">{{ account.detail }}</p></td>
                                    <td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ accessLabel(account) }}</p><p class="mt-1 text-xs text-slate-500">{{ account.permission_count }} responsibility area{{ Number(account.permission_count) === 1 ? '' : 's' }}</p><p v-if="!account.can_manage" class="mt-1 text-xs font-semibold text-slate-500"><i class="fa-solid fa-lock mr-1" aria-hidden="true"></i>{{ account.protected_reason }}</p></td>
                                    <td class="px-5 py-4 text-sm font-semibold text-slate-600">{{ account.created_at }}</td>
                                    <td class="px-5 py-4"><div class="flex justify-end gap-2"><a v-if="account.action_url" :href="account.action_url" class="grid h-9 w-9 place-items-center bg-slate-950 text-white hover:bg-slate-800" aria-label="Edit staff account"><i class="fa-solid fa-pen text-xs" aria-hidden="true"></i></a><button v-if="account.can_manage && !account.is_current_account" type="button" :disabled="updatingId === account.id" :class="['grid h-9 w-9 place-items-center border disabled:opacity-40', account.account_status === 'suspended' ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' : 'border-rose-300 text-rose-700 hover:bg-rose-50']" :aria-label="account.account_status === 'suspended' ? 'Reactivate staff account' : 'Suspend staff account'" @click="requestStatusChange(account)"><i :class="['fa-solid text-xs', updatingId === account.id ? 'fa-circle-notch animate-spin' : account.account_status === 'suspended' ? 'fa-play' : 'fa-pause']" aria-hidden="true"></i></button><span v-if="!account.action_url" class="grid h-9 w-9 place-items-center border border-slate-200 text-slate-300" title="Protected account"><i class="fa-solid fa-lock text-xs" aria-hidden="true"></i></span></div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3"><p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p><div class="flex gap-2"><button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading" @click="loadAccounts(pagination.current_page - 1)">Previous</button><button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading" @click="loadAccounts(pagination.current_page + 1)">Next</button></div></footer>
                </section>
            </div>
        </section>

        <div v-if="confirmation" class="fixed inset-0 z-[120] grid place-items-center bg-slate-950/60 p-6" role="dialog" aria-modal="true" :aria-labelledby="'team-confirm-title'">
            <section class="w-full max-w-md border border-slate-300 bg-white shadow-2xl">
                <header class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Confirm account change</p><h2 id="team-confirm-title" class="mt-1 text-xl font-bold text-slate-950">{{ confirmation.title }}</h2></header>
                <p class="px-6 py-5 text-sm leading-6 text-slate-600">{{ confirmation.detail }}</p>
                <footer class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700" @click="confirmation = null">Cancel</button><button type="button" :disabled="updatingId" :class="['h-10 px-4 text-sm font-bold text-white disabled:opacity-50', confirmation.status === 'suspended' ? 'bg-rose-700 hover:bg-rose-800' : 'bg-slate-950 hover:bg-slate-800']" @click="confirmStatusChange">{{ confirmation.action }}</button></footer>
            </section>
        </div>
    </main>
</template>
