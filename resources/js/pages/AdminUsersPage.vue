<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

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
});
const users = ref([]);

const roleFilters = computed(() => [
    { value: 'all', label: 'All roles', count: stats.value.total_users },
    { value: 'applicant', label: 'Applicants', count: stats.value.applicants },
    { value: 'provider', label: 'Providers', count: stats.value.providers },
    { value: 'admin', label: 'Admins', count: stats.value.admins },
]);

const filteredUsers = computed(() => {
    const query = search.value.trim().toLowerCase();
    const role = selectedRole.value;

    return users.value.filter((user) => {
        const matchesRole = role === 'all' || user.role === role;
        const matchesSearch = !query || [
            user.name,
            user.email,
            user.username,
            user.contact_number,
            user.role,
        ].some((value) => String(value ?? '').toLowerCase().includes(query));

        return matchesRole && matchesSearch;
    });
});

function roleLabel(role) {
    return String(role ?? 'applicant')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadAdminData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/users');

        stats.value = response.data.stats;
        users.value = response.data.users;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load users.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadAdminData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="users" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
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

                <div class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-slate-200 p-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h3 class="text-lg font-bold text-slate-950">
                                    User Records
                                </h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ filteredUsers.length }} shown from {{ stats.total_users }} total accounts.
                                </p>
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <input
                                    v-model="search"
                                    type="search"
                                    placeholder="Search users"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100 sm:w-72"
                                >

                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-3.5 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    @click="loadAdminData"
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
                                @click="selectedRole = filter.value"
                            >
                                {{ filter.label }} ({{ filter.count }})
                            </button>
                        </div>
                    </div>

                    <div v-if="isLoading" class="p-6 text-sm text-slate-500">
                        Loading users...
                    </div>

                    <div v-else-if="errorMessage" class="p-6">
                        <p class="rounded-md border border-rose-200 bg-rose-50 px-3.5 py-3 text-sm text-rose-700">
                            {{ errorMessage }}
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">
                                        Name
                                    </th>
                                    <th class="px-4 py-3">
                                        Email
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
                                        Registered
                                    </th>
                                    <th class="px-4 py-3 text-right">
                                        Action
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <tr
                                    v-for="user in filteredUsers"
                                    :key="user.id"
                                    class="hover:bg-slate-50"
                                >
                                    <td class="px-4 py-3 font-semibold text-slate-950">
                                        {{ user.name }}
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ user.email }}
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ user.username || '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ user.contact_number || '-' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            :class="[
                                                'rounded-md px-2 py-1 text-xs font-bold',
                                                user.role === 'admin'
                                                    ? 'bg-amber-100 text-amber-800'
                                                    : user.role === 'provider'
                                                        ? 'bg-sky-100 text-sky-800'
                                                        : 'bg-emerald-100 text-emerald-800'
                                            ]"
                                        >
                                            {{ roleLabel(user.role) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ user.created_at }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <a
                                            :href="`/admin/accounts/${user.id}/edit`"
                                            class="inline-flex rounded-md border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                        >
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="filteredUsers.length === 0" class="p-6 text-sm text-slate-500">
                            No users found.
                        </div>
                    </div>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
