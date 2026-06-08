<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const stats = ref({
    total_users: 0,
    admins: 0,
    applicants: 0,
    providers: 0,
    recent_signups: 0,
});
const users = ref([]);

const statCards = computed(() => [
    {
        label: 'Total Users',
        value: stats.value.total_users,
        description: 'All registered portal accounts.',
        className: 'text-slate-950',
        accent: 'bg-slate-900',
    },
    {
        label: 'Applicants',
        value: stats.value.applicants,
        description: 'Student accounts in the portal.',
        className: 'text-emerald-700',
        accent: 'bg-emerald-600',
    },
    {
        label: 'Providers',
        value: stats.value.providers,
        description: 'Scholarship provider accounts.',
        className: 'text-sky-700',
        accent: 'bg-sky-600',
    },
    {
        label: 'Admins',
        value: stats.value.admins,
        description: 'Accounts with admin access.',
        className: 'text-amber-600',
        accent: 'bg-amber-500',
    },
    {
        label: 'Recent Signups',
        value: stats.value.recent_signups,
        description: 'New accounts in the last 7 days.',
        className: 'text-slate-700',
        accent: 'bg-slate-500',
    },
]);

const roleDetails = computed(() => {
    const total = Math.max(stats.value.total_users, 1);

    return [
        {
            label: 'Applicants',
            value: stats.value.applicants,
            percent: Math.round((stats.value.applicants / total) * 100),
            bar: 'bg-emerald-600',
        },
        {
            label: 'Providers',
            value: stats.value.providers,
            percent: Math.round((stats.value.providers / total) * 100),
            bar: 'bg-sky-600',
        },
        {
            label: 'Admins',
            value: stats.value.admins,
            percent: Math.round((stats.value.admins / total) * 100),
            bar: 'bg-amber-500',
        },
    ];
});

const recentUsers = computed(() => users.value.slice(0, 5));

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
    window.location.href = '/';
}

onMounted(loadAdminData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="dashboard" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
                                Admin Dashboard
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                System Overview
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                A quick overview of account activity and role distribution across the scholarship portal.
                            </p>
                        </div>

                        <a
                            href="/admin/manage-users"
                            class="rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                        >
                            Manage Users
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading dashboard details...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <article
                        v-for="card in statCards"
                        :key="card.label"
                        class="relative overflow-hidden rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
                    >
                        <div :class="['absolute left-0 top-0 h-full w-1', card.accent]"></div>
                        <p class="text-sm font-semibold text-slate-500">
                            {{ card.label }}
                        </p>
                        <p :class="['mt-3 font-display text-3xl font-bold', card.className]">
                            {{ card.value }}
                        </p>
                        <p class="mt-3 text-sm leading-5 text-slate-500">
                            {{ card.description }}
                        </p>
                    </article>
                </div>

                <div class="mt-6 grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                            Account Details
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Role distribution
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            A quick look at how registered accounts are grouped across the admin, provider, and applicant roles.
                        </p>

                        <div class="mt-5 grid gap-4">
                            <div
                                v-for="detail in roleDetails"
                                :key="detail.label"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">
                                            {{ detail.label }}
                                        </p>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ detail.value }} accounts
                                        </p>
                                    </div>
                                    <p class="font-display text-2xl font-bold text-slate-950">
                                        {{ detail.percent }}%
                                    </p>
                                </div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div
                                        :class="['h-full rounded-full', detail.bar]"
                                        :style="{ width: `${detail.percent}%` }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Recent Activity
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Latest registered accounts
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            The five newest accounts are shown here for quick admin awareness.
                        </p>

                        <div class="mt-5 grid gap-3">
                            <div
                                v-for="user in recentUsers"
                                :key="user.id"
                                class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-950">
                                        {{ user.name }}
                                    </p>
                                    <p class="mt-1 truncate text-sm text-slate-500">
                                        {{ user.email }}
                                    </p>
                                </div>
                                <span
                                    :class="[
                                        'shrink-0 rounded-md px-2 py-1 text-xs font-bold',
                                        user.role === 'admin'
                                            ? 'bg-amber-100 text-amber-800'
                                            : user.role === 'provider'
                                                ? 'bg-sky-100 text-sky-800'
                                                : 'bg-emerald-100 text-emerald-800'
                                    ]"
                                >
                                    {{ user.role }}
                                </span>
                            </div>

                            <div v-if="recentUsers.length === 0" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                No recent accounts found.
                            </div>
                        </div>
                    </section>
                </div>

                <div class="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                Admin Action
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Keep full account work in Manage Users
                            </h3>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Use the dedicated page to search, filter, and review complete user records while this dashboard stays focused on important details.
                            </p>
                        </div>

                        <a
                            href="/admin/manage-users"
                            class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            Open Manage Users
                        </a>
                    </div>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
