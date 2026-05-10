<script setup>
import { computed, onMounted, ref } from 'vue';
import SiteNavbar from '../components/SiteNavbar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const search = ref('');
const stats = ref({
    total_users: 0,
    admins: 0,
    applicants: 0,
    recent_signups: 0,
});
const users = ref([]);

const filteredUsers = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (!query) {
        return users.value;
    }

    return users.value.filter((user) => [
        user.name,
        user.email,
        user.username,
        user.contact_number,
        user.role,
    ].some((value) => String(value ?? '').toLowerCase().includes(query)));
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
        errorMessage.value = error.response?.data?.message ?? 'Unable to load admin data.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/login';
}

onMounted(loadAdminData);
</script>

<template>
    <main class="min-h-screen bg-slate-50 text-slate-900">
        <SiteNavbar />

        <section class="border-b border-slate-200 bg-white px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto flex max-w-6xl flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                        Admin Panel
                    </p>
                    <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Scholarship Users
                    </h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Review registered applicant accounts and monitor portal activity.
                    </p>
                </div>

                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                    @click="logout"
                >
                    Logout
                </button>
            </div>
        </section>

        <section class="px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold text-slate-500">
                            Total Users
                        </p>
                        <p class="mt-3 font-display text-3xl font-bold text-slate-950">
                            {{ stats.total_users }}
                        </p>
                    </article>

                    <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold text-slate-500">
                            Applicants
                        </p>
                        <p class="mt-3 font-display text-3xl font-bold text-emerald-700">
                            {{ stats.applicants }}
                        </p>
                    </article>

                    <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold text-slate-500">
                            Admins
                        </p>
                        <p class="mt-3 font-display text-3xl font-bold text-amber-600">
                            {{ stats.admins }}
                        </p>
                    </article>

                    <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold text-slate-500">
                            Recent Signups
                        </p>
                        <p class="mt-3 font-display text-3xl font-bold text-sky-700">
                            {{ stats.recent_signups }}
                        </p>
                    </article>
                </div>

                <div class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-slate-950">
                                Registered Accounts
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ filteredUsers.length }} shown
                            </p>
                        </div>

                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search users"
                            class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100 sm:max-w-xs"
                        >
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
                                                user.role === 'admin' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'
                                            ]"
                                        >
                                            {{ roleLabel(user.role) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ user.created_at }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="filteredUsers.length === 0" class="p-6 text-sm text-slate-500">
                            No users found.
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>
