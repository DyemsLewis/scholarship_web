<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AccountManagerSidebar from '../components/AccountManagerSidebar.vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { limitPhoneNumber } from '../support/phoneNumber';
import { showPortalToast } from '../support/portalToast';

const isLoading = ref(true);
const usesPortalManagerWorkspace = window.location.pathname.startsWith('/admin/workspaces/portal');
const isSaving = ref(false);
const activeAction = ref('');
const errorMessage = ref('');
const modalError = ref('');
const search = ref('');
const selectedRole = ref('all');
const attentionFilter = ref('all');
const users = ref([]);
const selectedAccount = ref(null);
const showCreateModal = ref(false);
const suspensionReason = ref('');
const stats = ref({
    total_users: 0,
    active_users: 0,
    recent_signups: 0,
    unverified_users: 0,
    suspended_users: 0,
    password_resets_required: 0,
    attention_total: 0,
});
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const createForm = ref(emptyCreateForm());
let searchTimer;

const attentionOptions = computed(() => [
    { value: 'all', label: 'All accounts', count: stats.value.total_users },
    { value: 'unverified', label: 'Email unverified', count: stats.value.unverified_users },
    { value: 'password_reset', label: 'Password reset', count: stats.value.password_resets_required },
    { value: 'suspended', label: 'Suspended', count: stats.value.suspended_users },
]);

const paginationLabel = computed(() => {
    if (!pagination.value.total) return '0 accounts';
    return `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`;
});

function emptyCreateForm() {
    return {
        first_name: '',
        middle_initial: '',
        last_name: '',
        email: '',
        username: '',
        contact_number: '',
        role: 'applicant',
        password: '',
        password_confirmation: '',
    };
}

function roleLabel(role) {
    return role === 'provider' ? 'Provider' : 'Applicant';
}

function initials(user) {
    return String(user?.name || user?.username || 'U')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function firstValidationError(error, fallback) {
    return Object.values(error.response?.data?.errors ?? {})[0]?.[0]
        ?? error.response?.data?.message
        ?? fallback;
}

async function loadWorkspace(page = 1, options = {}) {
    if (!options.silent) isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/accounts/data', {
            params: {
                search: search.value.trim() || undefined,
                role: selectedRole.value,
                attention: attentionFilter.value,
                page,
                per_page: 10,
            },
        });
        stats.value = response.data.stats;
        users.value = response.data.users;
        pagination.value = response.data.pagination;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the account workspace.';
    } finally {
        if (!options.silent) isLoading.value = false;
    }
}

function openCreateAccount() {
    createForm.value = emptyCreateForm();
    modalError.value = '';
    showCreateModal.value = true;
}

function closeCreateAccount() {
    if (isSaving.value) return;
    showCreateModal.value = false;
    modalError.value = '';
}

async function createAccount() {
    if (isSaving.value) return;
    isSaving.value = true;
    modalError.value = '';

    try {
        const response = await window.axios.post('/admin/users', {
            ...createForm.value,
            middle_initial: createForm.value.middle_initial.trim().toUpperCase(),
            contact_number: createForm.value.contact_number.trim(),
            account_title: null,
            permissions: null,
        });
        showCreateModal.value = false;
        showPortalToast({ title: 'Account created', message: response.data.message });
        await loadWorkspace(1, { silent: true });
    } catch (error) {
        modalError.value = firstValidationError(error, 'Review the account details and try again.');
    } finally {
        isSaving.value = false;
    }
}

function openAccount(account) {
    selectedAccount.value = { ...account };
    suspensionReason.value = account.suspension_reason ?? '';
    modalError.value = '';
}

function closeAccount() {
    if (activeAction.value) return;
    selectedAccount.value = null;
    suspensionReason.value = '';
    modalError.value = '';
}

async function openAccountById(accountId) {
    try {
        const response = await window.axios.get(`/admin/users/${accountId}`);
        openAccount(response.data.user);
    } catch (error) {
        showPortalToast({ type: 'error', title: 'Account unavailable', message: error.response?.data?.message ?? 'The account could not be opened.' });
    }
}

async function runAccountAction(action, request, successTitle) {
    if (!selectedAccount.value || activeAction.value) return;
    activeAction.value = action;
    modalError.value = '';

    try {
        const response = await request();
        selectedAccount.value = { ...selectedAccount.value, ...response.data.user };
        showPortalToast({ title: successTitle, message: response.data.message });
        await loadWorkspace(pagination.value.current_page, { silent: true });
    } catch (error) {
        modalError.value = firstValidationError(error, 'The account action could not be completed.');
    } finally {
        activeAction.value = '';
    }
}

function resendVerification() {
    runAccountAction(
        'verification',
        () => window.axios.post(`/admin/users/${selectedAccount.value.id}/verification-email`),
        'Verification sent',
    );
}

function requirePasswordReset() {
    runAccountAction(
        'password',
        () => window.axios.post(`/admin/users/${selectedAccount.value.id}/force-password-reset`),
        'Password reset required',
    );
}

function changeAccountStatus() {
    const isSuspended = selectedAccount.value.account_status === 'suspended';
    if (!isSuspended && suspensionReason.value.trim().length < 5) {
        modalError.value = 'Add a short reason before suspending this account.';
        return;
    }

    runAccountAction(
        'status',
        () => window.axios.patch(`/admin/users/${selectedAccount.value.id}/status`, {
            account_status: isSuspended ? 'active' : 'suspended',
            suspension_reason: isSuspended ? null : suspensionReason.value.trim(),
        }),
        isSuspended ? 'Account reactivated' : 'Account suspended',
    );
}

function goToPage(page) {
    if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
    loadWorkspace(page);
}

watch([search, selectedRole, attentionFilter], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadWorkspace(1), 300);
});

onMounted(async () => {
    await loadWorkspace();
    const params = new URLSearchParams(window.location.search);
    if (params.get('create') === '1') openCreateAccount();
    if (params.get('account')) await openAccountById(params.get('account'));
});
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar v-if="usesPortalManagerWorkspace" />
        <AccountManagerSidebar v-else />

        <section class="admin-page">
            <div class="admin-container min-w-0">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Account manager workspace"
                    title="Account access desk"
                    description="Create accounts and resolve sign-in access issues."
                    icon="fa-solid fa-user-shield"
                >
                    <template #actions>
                        <button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="openCreateAccount">
                            Create account
                        </button>
                    </template>
                </TaskPageHeader>

                <div v-if="errorMessage" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

                <section class="mt-5 grid overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm sm:grid-cols-3">
                    <div class="border-b border-slate-200 px-5 py-4 sm:border-b-0 sm:border-r">
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Active accounts</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ stats.active_users }}</p>
                    </div>
                    <div class="border-b border-slate-200 px-5 py-4 sm:border-b-0 sm:border-r">
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Needs attention</p>
                        <p class="mt-1 text-2xl font-black text-amber-700">{{ stats.attention_total }}</p>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">New this week</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ stats.recent_signups }}</p>
                    </div>
                </section>

                <section class="admin-panel mt-5 overflow-hidden">
                    <header class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-lg font-bold text-slate-950">Account directory</h2>
                        <p class="mt-1 text-sm text-slate-500">Find an applicant or provider and resolve account access.</p>
                    </header>

                    <div class="grid gap-3 border-b border-slate-200 bg-slate-50 p-3 lg:grid-cols-[minmax(16rem,1fr)_11rem]">
                        <label class="relative block">
                            <span class="sr-only">Search accounts</span>
                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input v-model="search" type="search" placeholder="Search name, email, or username" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                        </label>
                        <label>
                            <span class="sr-only">Filter by role</span>
                            <select v-model="selectedRole" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500">
                                <option value="all">All roles</option>
                                <option value="applicant">Applicants</option>
                                <option value="provider">Providers</option>
                            </select>
                        </label>
                    </div>

                    <div class="flex gap-2 overflow-x-auto border-b border-slate-200 px-3 py-3">
                        <button v-for="option in attentionOptions" :key="option.value" type="button" :class="['shrink-0 rounded-md px-3 py-2 text-xs font-bold transition', attentionFilter === option.value ? 'bg-slate-950 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100']" @click="attentionFilter = option.value">
                            {{ option.label }} <span :class="attentionFilter === option.value ? 'text-amber-300' : 'text-slate-400'">{{ option.count }}</span>
                        </button>
                        <span class="ml-auto hidden self-center text-xs font-semibold text-slate-500 sm:block">{{ paginationLabel }}</span>
                    </div>

                    <div v-if="isLoading" class="p-8 text-center text-sm text-slate-500">Loading account workspace...</div>
                    <div v-else-if="users.length">
                        <div class="portal-record-head hidden grid-cols-[minmax(0,1fr)_7rem_9rem_11rem_6rem] items-center gap-3 lg:grid">
                            <span>Account</span><span>Role</span><span>Access</span><span>Sign-in</span><span class="text-right">Action</span>
                        </div>
                        <article v-for="user in users" :key="user.id" class="portal-record-row grid gap-3 lg:grid-cols-[minmax(0,1fr)_7rem_9rem_11rem_6rem] lg:items-center">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-bold text-white">{{ initials(user) }}</span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-bold text-slate-950">{{ user.name }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-slate-500">{{ user.email }}</span>
                                </span>
                            </div>
                            <div><span class="portal-record-mobile-label lg:hidden">Role</span><span class="text-sm font-semibold text-slate-700">{{ roleLabel(user.role) }}</span></div>
                            <div><span class="portal-record-mobile-label lg:hidden">Access</span><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', user.account_status === 'suspended' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800']">{{ user.account_status === 'suspended' ? 'Suspended' : 'Active' }}</span></div>
                            <div class="flex flex-wrap gap-1.5">
                                <span class="portal-record-mobile-label w-full lg:hidden">Sign-in</span>
                                <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', user.email_verified ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">{{ user.email_verified ? 'Verified' : 'Unverified' }}</span>
                                <span v-if="user.must_reset_password" class="rounded-md bg-slate-200 px-2 py-1 text-[10px] font-bold uppercase text-slate-700">Reset</span>
                            </div>
                            <div class="lg:text-right"><button type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openAccount(user)">Manage</button></div>
                        </article>
                    </div>
                    <div v-else class="portal-table-empty">
                        <p class="portal-table-empty-title">No matching accounts</p>
                        <p class="portal-table-empty-copy">Try another search or access filter.</p>
                    </div>

                    <footer v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                        <div class="flex gap-2">
                            <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 font-semibold disabled:opacity-50" :disabled="pagination.current_page <= 1" @click="goToPage(pagination.current_page - 1)">Previous</button>
                            <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 font-semibold disabled:opacity-50" :disabled="pagination.current_page >= pagination.last_page" @click="goToPage(pagination.current_page + 1)">Next</button>
                        </div>
                    </footer>
                </section>
            </div>
        </section>
    </main>

    <div v-if="showCreateModal" class="fixed inset-0 z-[90] grid place-items-center bg-slate-950/70 p-4" @click.self="closeCreateAccount">
        <section class="flex max-h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="create-account-title">
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">New portal account</p><h2 id="create-account-title" class="mt-1 text-xl font-bold text-slate-950">Create an applicant or provider</h2></div>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500" aria-label="Close" @click="closeCreateAccount"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>
            <form class="overflow-y-auto" @submit.prevent="createAccount">
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    <label class="sm:col-span-2"><span class="text-sm font-bold text-slate-700">Account type</span><select v-model="createForm.role" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="applicant">Applicant</option><option value="provider">Scholarship provider</option></select></label>
                    <label><span class="text-sm font-bold text-slate-700">First name</span><input v-model="createForm.first_name" required maxlength="255" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                    <label><span class="text-sm font-bold text-slate-700">Last name</span><input v-model="createForm.last_name" required maxlength="255" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                    <label><span class="text-sm font-bold text-slate-700">Middle initial</span><input v-model="createForm.middle_initial" required maxlength="1" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm uppercase"></label>
                    <label><span class="text-sm font-bold text-slate-700">Contact number</span><input :value="createForm.contact_number" required inputmode="tel" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm" @input="createForm.contact_number = limitPhoneNumber($event.target.value)"></label>
                    <label><span class="text-sm font-bold text-slate-700">Email</span><input v-model="createForm.email" required type="email" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                    <label><span class="text-sm font-bold text-slate-700">Username</span><input v-model="createForm.username" required minlength="4" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                    <label><span class="text-sm font-bold text-slate-700">Temporary password</span><input v-model="createForm.password" required type="password" minlength="8" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                    <label><span class="text-sm font-bold text-slate-700">Confirm password</span><input v-model="createForm.password_confirmation" required type="password" minlength="8" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                    <p v-if="modalError" class="sm:col-span-2 rounded-md bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ modalError }}</p>
                </div>
                <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4"><button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeCreateAccount">Cancel</button><button type="submit" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" :disabled="isSaving">{{ isSaving ? 'Creating...' : 'Create account' }}</button></footer>
            </form>
        </section>
    </div>

    <div v-if="selectedAccount" class="fixed inset-0 z-[90] grid place-items-center bg-slate-950/70 p-4" @click.self="closeAccount">
        <section class="flex max-h-[94vh] w-full max-w-xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="manage-account-title">
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <div class="min-w-0"><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Account controls</p><h2 id="manage-account-title" class="mt-1 truncate text-xl font-bold text-slate-950">{{ selectedAccount.name }}</h2><p class="mt-1 truncate text-sm text-slate-500">{{ selectedAccount.email }}</p></div>
                <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500" aria-label="Close" @click="closeAccount"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>
            <div class="overflow-y-auto">
                <div class="grid grid-cols-2 border-b border-slate-200 bg-slate-50">
                    <div class="border-r border-slate-200 px-5 py-3"><p class="text-xs font-bold text-slate-500">Role</p><p class="mt-1 text-sm font-bold text-slate-950">{{ roleLabel(selectedAccount.role) }}</p></div>
                    <div class="px-5 py-3"><p class="text-xs font-bold text-slate-500">Access</p><p class="mt-1 text-sm font-bold text-slate-950">{{ selectedAccount.account_status === 'suspended' ? 'Suspended' : 'Active' }}</p></div>
                </div>
                <div class="divide-y divide-slate-200">
                    <div class="flex items-center justify-between gap-4 p-5"><div><p class="text-sm font-bold text-slate-950">Email verification</p><p class="mt-1 text-xs text-slate-500">{{ selectedAccount.email_verified ? 'Email is verified.' : 'Send a new verification link.' }}</p></div><button v-if="!selectedAccount.email_verified" type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-50" :disabled="Boolean(activeAction)" @click="resendVerification">{{ activeAction === 'verification' ? 'Sending...' : 'Resend email' }}</button><span v-else class="text-xs font-bold text-emerald-700">Verified</span></div>
                    <div class="flex items-center justify-between gap-4 p-5"><div><p class="text-sm font-bold text-slate-950">Password access</p><p class="mt-1 text-xs text-slate-500">Require a secure password change on the next sign-in.</p></div><button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-50" :disabled="Boolean(activeAction)" @click="requirePasswordReset">{{ activeAction === 'password' ? 'Sending...' : 'Require reset' }}</button></div>
                    <div class="p-5"><p class="text-sm font-bold text-slate-950">Account status</p><p class="mt-1 text-xs text-slate-500">{{ selectedAccount.account_status === 'suspended' ? 'Restore access when the issue is resolved.' : 'Suspending prevents this account from signing in.' }}</p><textarea v-if="selectedAccount.account_status !== 'suspended'" v-model="suspensionReason" rows="2" maxlength="1000" class="mt-3 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm" placeholder="Reason for suspension"></textarea><button type="button" :class="['mt-3 rounded-md px-3 py-2 text-xs font-bold disabled:opacity-50', selectedAccount.account_status === 'suspended' ? 'bg-slate-950 text-white' : 'border border-rose-300 bg-white text-rose-700']" :disabled="Boolean(activeAction)" @click="changeAccountStatus">{{ activeAction === 'status' ? 'Saving...' : selectedAccount.account_status === 'suspended' ? 'Reactivate account' : 'Suspend account' }}</button></div>
                </div>
                <p v-if="modalError" class="mx-5 mb-5 rounded-md bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ modalError }}</p>
            </div>
        </section>
    </div>
</template>
