<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isLoading = ref(true);
const errorMessage = ref('');
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
].filter((option) => option.value !== 'admin' || !window.portalUser?.is_managed_account));
const applicantVerificationStates = {
    approved: {
        label: 'Profile verified',
        icon: 'fa-solid fa-circle-check',
        className: 'bg-emerald-100 text-emerald-700',
    },
    pending: {
        label: 'Proof pending',
        icon: 'fa-solid fa-clock',
        className: 'bg-amber-100 text-amber-700',
    },
    rejected: {
        label: 'Proof rejected',
        icon: 'fa-solid fa-circle-xmark',
        className: 'bg-rose-100 text-rose-700',
    },
    missing: {
        label: 'No academic record',
        icon: 'fa-solid fa-circle-minus',
        className: 'bg-slate-100 text-slate-500',
    },
};

const paginationLabel = computed(() => {
    if (!pagination.value.total) {
        return '0 accounts';
    }

    const range = `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`;

    return pagination.value.total === stats.value.total_users ? range : `${range} matches`;
});

function roleLabel(role) {
    return String(role ?? 'applicant')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusLabel(status) {
    return status === 'suspended' ? 'Suspended' : 'Active';
}

function userInitials(user) {
    return String(user?.name || user?.username || 'User')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function applicantVerificationState(status) {
    return applicantVerificationStates[status] ?? applicantVerificationStates.missing;
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

function goToPage(page) {
    if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) {
        return;
    }

    loadAdminData(page);
}

watch([search, selectedRole], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadAdminData(1), 300);
});

onMounted(loadAdminData);
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="users" />

        <section class="admin-page">
            <div class="admin-container min-w-0">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Accounts"
                    title="Manage platform accounts"
                    description="Find an account, check its access, or create a managed account."
                    icon="fa-solid fa-users-gear"
                >
                    <template v-if="!isLoading" #meta>
                        <span>{{ stats.total_users }} total accounts</span>
                        <span v-if="stats.suspended_users">{{ stats.suspended_users }} suspended</span>
                        <span v-if="stats.password_resets_required">{{ stats.password_resets_required }} require a password reset</span>
                    </template>
                    <template #actions>
                        <a
                            href="/admin/accounts/create"
                            class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            Create account
                        </a>
                    </template>
                </TaskPageHeader>

                <section class="admin-panel mt-5 overflow-hidden">
                    <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/80 p-3 lg:flex-row lg:items-center">
                            <div class="relative w-full lg:max-w-md">
                                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="search"
                                    type="search"
                                    placeholder="Search name, email, or username"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                >
                            </div>
                            <label class="flex min-w-0 items-center gap-2 lg:ml-auto">
                                <span class="shrink-0 text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Role</span>
                                <select
                                    v-model="selectedRole"
                                    class="min-w-40 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500"
                                >
                                    <option v-for="filter in roleFilters" :key="filter.value" :value="filter.value">
                                        {{ filter.label }} ({{ filter.count }})
                                    </option>
                                </select>
                            </label>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">
                                {{ paginationLabel }}
                            </p>
                    </div>

                    <div v-if="isLoading" class="p-6 text-sm text-slate-500">
                        Loading accounts...
                    </div>

                    <div v-else-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        {{ errorMessage }}
                    </div>

                    <div v-else-if="users.length" class="bg-white">
                        <div class="portal-record-head hidden grid-cols-[minmax(0,1fr)_7.5rem_8.5rem_12rem_5.5rem] items-center gap-3 lg:grid">
                            <span>Account</span>
                            <span class="text-center">Role</span>
                            <span class="text-center">Access</span>
                            <span class="text-center">Verification</span>
                            <span class="text-center">Action</span>
                        </div>
                        <article
                            v-for="user in users"
                            :key="user.id"
                            class="portal-record-row grid gap-3 lg:grid-cols-[minmax(0,1fr)_7.5rem_8.5rem_12rem_5.5rem] lg:items-center"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-[11px] font-bold tracking-[0.08em] text-white">
                                    {{ userInitials(user) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h4 class="truncate text-sm font-bold leading-5 text-slate-950">{{ user.name }}</h4>
                                    <p class="mt-0.5 truncate text-xs leading-5 text-slate-500">
                                        {{ user.email }}
                                        <template v-if="user.username">
                                            <span class="mx-1 text-slate-300">&middot;</span>
                                            @{{ user.username }}
                                        </template>
                                    </p>
                                </div>
                            </div>

                            <div class="portal-record-cell-center lg:block">
                                <span class="portal-record-mobile-label lg:hidden">Role</span>
                                <span
                                    :class="[
                                        'inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase',
                                        user.role === 'admin'
                                            ? 'bg-amber-100 text-amber-800'
                                            : user.role === 'provider'
                                                ? 'bg-slate-200 text-slate-700'
                                                : 'bg-emerald-100 text-emerald-800'
                                    ]"
                                >
                                    {{ roleLabel(user.role) }}
                                </span>
                            </div>

                            <div class="portal-record-cell-center">
                                <span class="portal-record-mobile-label lg:hidden">Access</span>
                                <div class="flex items-center gap-1.5">
                                    <span
                                        :class="[
                                            'inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase',
                                            user.account_status === 'suspended'
                                                ? 'bg-rose-100 text-rose-800'
                                                : 'bg-emerald-100 text-emerald-800'
                                        ]"
                                    >
                                        {{ statusLabel(user.account_status) }}
                                    </span>
                                    <span
                                        v-if="user.must_reset_password"
                                        title="Password reset required"
                                        aria-label="Password reset required"
                                        class="inline-grid h-6 w-6 place-items-center rounded-md bg-slate-900 text-[10px] text-white"
                                    >
                                        <i class="fa-solid fa-key" aria-hidden="true"></i>
                                        <span class="sr-only">Password reset required</span>
                                    </span>
                                </div>
                            </div>

                            <div class="portal-record-cell-center">
                                <span class="portal-record-mobile-label lg:hidden">Verification</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1.5 text-xs font-semibold',
                                            user.email_verified ? 'text-emerald-700' : 'text-amber-700'
                                        ]"
                                    >
                                        <i :class="user.email_verified ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'" aria-hidden="true"></i>
                                        {{ user.email_verified ? 'Email verified' : 'Email unverified' }}
                                    </span>
                                    <span
                                        v-if="user.role === 'applicant'"
                                        :title="applicantVerificationState(user.applicant_verification_status).label"
                                        :aria-label="applicantVerificationState(user.applicant_verification_status).label"
                                        :class="['inline-grid h-6 w-6 shrink-0 place-items-center rounded-md text-xs', applicantVerificationState(user.applicant_verification_status).className]"
                                    >
                                        <i :class="applicantVerificationState(user.applicant_verification_status).icon" aria-hidden="true"></i>
                                        <span class="sr-only">{{ applicantVerificationState(user.applicant_verification_status).label }}</span>
                                    </span>
                                </div>
                            </div>

                            <div class="portal-record-cell-center">
                                <span class="portal-record-mobile-label lg:hidden">Action</span>
                                <a
                                    :href="`/admin/accounts/${user.id}/edit`"
                                    class="inline-flex rounded-md bg-slate-950 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-slate-800"
                                >
                                    Manage
                                </a>
                            </div>
                        </article>
                    </div>

                    <div v-else class="portal-table-empty">
                        <p class="portal-table-empty-title">No matching accounts</p>
                        <p class="portal-table-empty-copy">Try another role or search term.</p>
                    </div>

                    <div
                        v-if="pagination.last_page > 1"
                        class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="pagination.current_page <= 1"
                                @click="goToPage(pagination.current_page - 1)"
                            >
                                Previous
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="pagination.current_page >= pagination.last_page"
                                @click="goToPage(pagination.current_page + 1)"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </section>

            </div>
        </section>
    </main>
</template>
