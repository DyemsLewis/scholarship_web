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
    scholarships: 0,
    applications: 0,
    pending_providers: 0,
});
const users = ref([]);

const focusItems = computed(() => [
    {
        label: 'Pending providers',
        detail: (stats.value.pending_providers || 0) > 0 ? 'Provider accounts need review.' : 'No provider approvals waiting.',
        href: '/admin/reviews',
        action: 'Open reviews',
    },
    {
        label: 'New signups',
        detail: 'Review recently created accounts and confirm role details.',
        href: '/admin/manage-users',
        action: 'Manage users',
    },
    {
        label: 'System activity',
        detail: 'Check login, account, application, and review events.',
        href: '/admin/logs',
        action: 'View logs',
    },
]);
const recentUsers = computed(() => users.value.slice(0, 4));

function roleClass(role) {
    if (role === 'admin') {
        return 'bg-amber-100 text-amber-800';
    }

    if (role === 'provider') {
        return 'bg-sky-100 text-sky-800';
    }

    return 'bg-emerald-100 text-emerald-800';
}

async function loadAdminData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/analytics');

        stats.value = response.data.stats;
        users.value = response.data.recent_users;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load admin dashboard.';
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
            <div class="mx-auto max-w-6xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Admin Dashboard
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Quick overview
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                A lighter dashboard for important admin actions and recent account activity.
                            </p>
                        </div>

                        <a
                            href="/admin/manage-users"
                            class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            Manage users
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading admin dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Focus Today
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Admin items that may need attention
                        </h3>

                        <div class="mt-5 grid gap-3 lg:grid-cols-3">
                            <a
                                v-for="item in focusItems"
                                :key="item.label"
                                :href="item.href"
                                class="rounded-md border border-slate-200 bg-slate-50 p-4 transition hover:border-slate-300 hover:bg-white"
                            >
                                <p class="text-sm font-bold text-slate-950">
                                    {{ item.label }}
                                </p>
                                <p class="mt-1 text-sm leading-5 text-slate-500">
                                    {{ item.detail }}
                                </p>
                                <p class="mt-4 text-sm font-bold text-slate-700">
                                    {{ item.action }}
                                </p>
                            </a>
                        </div>
                    </section>

                    <section class="grid gap-6">
                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                        Recent Accounts
                                    </p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                                        Latest registrations
                                    </h3>
                                </div>
                                <a
                                    href="/admin/manage-users"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    View all
                                </a>
                            </div>

                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="user in recentUsers"
                                    :key="user.id"
                                    class="flex flex-col gap-2 rounded-md border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-950">
                                            {{ user.name || user.email }}
                                        </p>
                                        <p class="mt-1 truncate text-xs text-slate-500">
                                            {{ user.email }} - {{ user.created_at }}
                                        </p>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', roleClass(user.role)]">
                                        {{ user.role }}
                                    </span>
                                </div>

                                <div v-if="recentUsers.length === 0" class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                    No recent accounts found.
                                </div>
                            </div>
                        </article>

                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">
                            Shortcuts
                        </p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <a href="/admin/manage-users" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                Manage users
                            </a>
                            <a href="/admin/reviews" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                Provider reviews
                            </a>
                            <a href="/admin/logs" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                View logs
                            </a>
                        </div>
                    </section>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
