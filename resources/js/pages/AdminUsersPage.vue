<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const statusMessage = ref('');
const activeAction = ref('');
const search = ref('');
const selectedRole = ref('all');
const stats = ref({
    total_users: 0,
    admins: 0,
    applicants: 0,
    providers: 0,
    recent_signups: 0,
    suspended_users: 0,
    password_resets_required: 0,
});
const users = ref([]);
const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
});
let searchTimer;

const roleFilters = computed(() => [
    { value: 'all', label: 'All roles', count: stats.value.total_users },
    { value: 'applicant', label: 'Applicants', count: stats.value.applicants },
    { value: 'provider', label: 'Providers', count: stats.value.providers },
    { value: 'admin', label: 'Admins', count: stats.value.admins },
]);

const paginationLabel = computed(() => {
    if (!pagination.value.total) {
        return `0 matching accounts from ${stats.value.total_users} total accounts.`;
    }

    return `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total} matching accounts from ${stats.value.total_users} total accounts.`;
});

function roleLabel(role) {
    return String(role ?? 'applicant')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusLabel(status) {
    return status === 'suspended' ? 'Suspended' : 'Active';
}

function actionKey(user, action) {
    return `${user.id}:${action}`;
}

function isActionLoading(user, action) {
    return activeAction.value === actionKey(user, action);
}

async function loadAdminData(page = 1, options = {}) {
    if (!options.silent) {
        isLoading.value = true;
    }

    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/users', {
            params: {
                search: search.value.trim() || undefined,
                role: selectedRole.value,
                page,
                per_page: pagination.value.per_page,
            },
        });

        stats.value = response.data.stats;
        users.value = response.data.users;
        pagination.value = response.data.pagination;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load users.';
    } finally {
        if (!options.silent) {
            isLoading.value = false;
        }
    }
}

async function runUserAction(user, action, request, fallbackMessage) {
    activeAction.value = actionKey(user, action);
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await request();

        statusMessage.value = response.data.message ?? fallbackMessage;
        await loadAdminData(pagination.value.current_page, { silent: true });
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update this account.';
    } finally {
        activeAction.value = '';
    }
}

async function toggleAccountStatus(user) {
    if (user.account_status === 'suspended') {
        await runUserAction(
            user,
            'status',
            () => window.axios.patch(`/admin/users/${user.id}/status`, {
                account_status: 'active',
            }),
            'Account reactivated.',
        );
        return;
    }

    const reason = window.prompt('Reason for suspending this account:', user.suspension_reason ?? '');

    if (reason === null) {
        return;
    }

    if (!reason.trim()) {
        errorMessage.value = 'Add a reason before suspending this account.';
        statusMessage.value = '';
        return;
    }

    await runUserAction(
        user,
        'status',
        () => window.axios.patch(`/admin/users/${user.id}/status`, {
            account_status: 'suspended',
            suspension_reason: reason.trim(),
        }),
        'Account suspended.',
    );
}

async function forcePasswordReset(user) {
    await runUserAction(
        user,
        'force-reset',
        () => window.axios.post(`/admin/users/${user.id}/force-password-reset`),
        'Password reset required.',
    );
}

async function verifyEmail(user) {
    await runUserAction(
        user,
        'verify-email',
        () => window.axios.patch(`/admin/users/${user.id}/email-verification`),
        'Email marked as verified.',
    );
}

async function resendVerificationEmail(user) {
    await runUserAction(
        user,
        'resend-verification',
        () => window.axios.post(`/admin/users/${user.id}/verification-email`),
        'Verification email sent.',
    );
}

function selectRole(role) {
    selectedRole.value = role;
}

function goToPage(page) {
    if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) {
        return;
    }

    loadAdminData(page);
}

watch([search, selectedRole], () => {
    clearTimeout(searchTimer);
    statusMessage.value = '';
    searchTimer = setTimeout(() => loadAdminData(1), 300);
});

onMounted(loadAdminData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_minmax(0,1fr)]">
        <AdminSidebar active="users" />

        <section class="min-w-0 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto w-full min-w-0 max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Manage Users
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Registered Accounts
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Search, filter, and review applicant, provider, and admin accounts.
                            </p>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a
                                href="/admin/accounts/create"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Create account
                            </a>
                            <a
                                href="/admin"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                            >
                                Back to Dashboard
                            </a>
                        </div>
                    </div>
                </header>

                <div class="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-slate-200 p-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-slate-950">
                                    User Records
                                </h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ paginationLabel }}
                                </p>
                                <p class="mt-1 text-xs font-semibold text-slate-500">
                                    {{ stats.suspended_users }} suspended / {{ stats.password_resets_required }} password resets required
                                </p>
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <input
                                    v-model="search"
                                    type="search"
                                    placeholder="Search users"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100 sm:w-72"
                                >

                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    @click="loadAdminData(pagination.current_page)"
                                >
                                    Refresh
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="filter in roleFilters"
                                :key="filter.value"
                                type="button"
                                :class="[
                                    'rounded-md border px-3 py-2 text-sm font-semibold transition',
                                    selectedRole === filter.value
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-300 bg-white text-slate-600 hover:border-slate-400 hover:bg-slate-50'
                                ]"
                                @click="selectRole(filter.value)"
                            >
                                {{ filter.label }} ({{ filter.count }})
                            </button>
                        </div>

                        <p
                            v-if="statusMessage"
                            class="rounded-md border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-sm font-semibold text-emerald-700"
                        >
                            {{ statusMessage }}
                        </p>
                    </div>

                    <div v-if="isLoading" class="p-6 text-sm text-slate-500">
                        Loading users...
                    </div>

                    <div v-else-if="errorMessage" class="p-6">
                        <p class="rounded-md border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-700">
                            {{ errorMessage }}
                        </p>
                    </div>

                    <div v-else class="max-w-full overflow-x-auto">
                        <table class="w-full min-w-[58rem] table-fixed divide-y divide-slate-200 text-left text-sm xl:min-w-full">
                            <colgroup>
                                <col class="w-[16rem]">
                                <col class="w-[9rem]">
                                <col class="w-[8rem]">
                                <col class="w-[7rem]">
                                <col class="w-[10rem]">
                                <col class="w-[8rem]">
                                <col class="w-[13rem]">
                            </colgroup>
                            <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">
                                        Account
                                    </th>
                                    <th class="px-4 py-3">
                                        Username
                                    </th>
                                    <th class="px-4 py-3">
                                        Contact
                                    </th>
                                    <th class="px-4 py-3">
                                        Role
                                    </th>
                                    <th class="px-4 py-3">
                                        Status
                                    </th>
                                    <th class="px-4 py-3">
                                        Registered
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <tr
                                    v-for="user in users"
                                    :key="user.id"
                                    class="hover:bg-slate-50"
                                >
                                    <td class="px-4 py-3 align-top">
                                        <p :title="user.name" class="truncate font-semibold text-slate-950">
                                            {{ user.name }}
                                        </p>
                                        <p :title="user.email" class="mt-1 truncate text-xs text-slate-500">
                                            {{ user.email }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 align-top text-slate-600">
                                        <p :title="user.username || '-'" class="truncate">
                                            {{ user.username || '-' }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 align-top text-slate-600">
                                        <p :title="user.contact_number || '-'" class="truncate">
                                            {{ user.contact_number || '-' }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span
                                            :class="[
                                                'inline-flex whitespace-nowrap rounded-md px-2 py-1 text-xs font-bold',
                                                user.role === 'admin'
                                                    ? 'bg-amber-100 text-amber-800'
                                                    : user.role === 'provider'
                                                        ? 'bg-slate-100 text-slate-700'
                                                        : 'bg-emerald-100 text-emerald-800'
                                            ]"
                                        >
                                            {{ roleLabel(user.role) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="flex flex-wrap gap-1.5">
                                            <span
                                                :class="[
                                                    'whitespace-nowrap rounded-md px-2 py-1 text-xs font-bold',
                                                    user.account_status === 'suspended'
                                                        ? 'bg-rose-100 text-rose-800'
                                                        : 'bg-emerald-100 text-emerald-800'
                                                ]"
                                            >
                                                {{ statusLabel(user.account_status) }}
                                            </span>
                                            <span
                                                v-if="user.must_reset_password"
                                                class="whitespace-nowrap rounded-md bg-slate-900 px-2 py-1 text-xs font-bold text-white"
                                            >
                                                Reset required
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-slate-600">
                                        {{ user.created_at }}
                                    </td>
                                    <td class="px-4 py-3 text-right align-top">
                                        <div class="flex flex-wrap justify-end gap-1.5">
                                            <button
                                                type="button"
                                                class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="Boolean(activeAction)"
                                                @click="toggleAccountStatus(user)"
                                            >
                                                <span v-if="isActionLoading(user, 'status')">
                                                    {{ user.account_status === 'suspended' ? 'Reactivating...' : 'Suspending...' }}
                                                </span>
                                                <span v-else>
                                                    {{ user.account_status === 'suspended' ? 'Reactivate' : 'Suspend' }}
                                                </span>
                                            </button>

                                            <button
                                                type="button"
                                                class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="Boolean(activeAction)"
                                                @click="forcePasswordReset(user)"
                                            >
                                                {{ isActionLoading(user, 'force-reset') ? 'Resetting...' : 'Reset' }}
                                            </button>

                                            <button
                                                v-if="!user.email_verified"
                                                type="button"
                                                class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="Boolean(activeAction)"
                                                @click="verifyEmail(user)"
                                            >
                                                {{ isActionLoading(user, 'verify-email') ? 'Verifying...' : 'Verify' }}
                                            </button>

                                            <button
                                                v-if="!user.email_verified"
                                                type="button"
                                                class="rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                                :disabled="Boolean(activeAction)"
                                                @click="resendVerificationEmail(user)"
                                            >
                                                {{ isActionLoading(user, 'resend-verification') ? 'Sending...' : 'Resend' }}
                                            </button>

                                            <a
                                                :href="`/admin/accounts/${user.id}/edit`"
                                                class="inline-flex rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                            >
                                                Edit
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="users.length === 0" class="p-6 text-sm text-slate-500">
                            No users found.
                        </div>

                        <div
                            v-if="pagination.last_page > 1"
                            class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <span>
                                Page {{ pagination.current_page }} of {{ pagination.last_page }}
                            </span>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="pagination.current_page <= 1"
                                    @click="goToPage(pagination.current_page - 1)"
                                >
                                    Previous
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-3 py-1.5 font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="pagination.current_page >= pagination.last_page"
                                    @click="goToPage(pagination.current_page + 1)"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
