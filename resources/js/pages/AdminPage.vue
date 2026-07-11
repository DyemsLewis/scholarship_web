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
    documents_pending_review: 0,
    documents_needing_replacement: 0,
    upcoming_deadlines: 0,
    expired_published: 0,
    needs_review_applications: 0,
});
const users = ref([]);
const programs = ref([]);
const recentUsers = computed(() => users.value.slice(0, 4));
const platformSignals = computed(() => [
    {
        label: 'Provider approval queue',
        tone: (stats.value.pending_providers || 0) > 0 ? 'warn' : 'good',
        detail: (stats.value.pending_providers || 0) > 0
            ? `${stats.value.pending_providers} provider account${stats.value.pending_providers === 1 ? '' : 's'} waiting for verification.`
            : 'No provider approvals waiting.',
        href: '/admin/reviews',
        action: 'Open reviews',
    },
    {
        label: 'Document review backlog',
        tone: (stats.value.documents_pending_review || 0) > 0 || (stats.value.documents_needing_replacement || 0) > 0 ? 'warn' : 'good',
        detail: `${stats.value.documents_pending_review || 0} pending review, ${stats.value.documents_needing_replacement || 0} needing replacement.`,
        href: '/admin/reviews',
        action: 'Check documents',
    },
    {
        label: 'Deadline monitoring',
        tone: (stats.value.expired_published || 0) > 0 || (stats.value.upcoming_deadlines || 0) > 0 ? 'warn' : 'good',
        detail: `${stats.value.upcoming_deadlines || 0} upcoming deadline${(stats.value.upcoming_deadlines || 0) === 1 ? '' : 's'}, ${stats.value.expired_published || 0} expired published program${(stats.value.expired_published || 0) === 1 ? '' : 's'}.`,
        href: '/admin/platform-analytics',
        action: 'View analytics',
    },
    {
        label: 'DSS review load',
        tone: (stats.value.needs_review_applications || 0) > 0 ? 'info' : 'good',
        detail: (stats.value.needs_review_applications || 0) > 0
            ? `${stats.value.needs_review_applications} application${stats.value.needs_review_applications === 1 ? '' : 's'} need closer review.`
            : 'No DSS review load detected.',
        href: '/admin/reviews',
        action: 'Review applications',
    },
]);

function signalClass(tone) {
    if (tone === 'good') {
        return 'border-emerald-100 bg-emerald-50 text-emerald-800';
    }

    if (tone === 'warn') {
        return 'border-amber-100 bg-amber-50 text-amber-900';
    }

    return 'border-slate-200 bg-slate-50 text-slate-700';
}

function roleClass(role) {
    if (role === 'admin') {
        return 'bg-amber-100 text-amber-800';
    }

    if (role === 'provider') {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-emerald-100 text-emerald-800';
}

function statusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function statusLabel(status) {
    return String(status ?? 'draft')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadAdminData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/analytics');

        stats.value = response.data.stats;
        users.value = response.data.recent_users;
        programs.value = response.data.recent_scholarships ?? [];
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
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="dashboard" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-6xl">
                <header class="admin-hero">
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
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Platform Risk Signals
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Data checks that need admin attention
                                </h3>
                            </div>
                            <p class="rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">
                                Reviews, documents, deadlines, DSS
                            </p>
                        </div>

                        <div class="mt-5 grid gap-3 lg:grid-cols-4">
                            <a
                                v-for="signal in platformSignals"
                                :key="signal.label"
                                :href="signal.href"
                                :class="['rounded-lg border p-4 transition hover:bg-white', signalClass(signal.tone)]"
                            >
                                <p class="text-sm font-bold">
                                    {{ signal.label }}
                                </p>
                                <p class="mt-2 min-h-12 text-sm leading-6 opacity-85">
                                    {{ signal.detail }}
                                </p>
                                <p class="mt-4 text-sm font-bold">
                                    {{ signal.action }}
                                </p>
                            </a>
                        </div>
                    </section>

                    <section class="grid gap-6 lg:grid-cols-2">
                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                        Recent Scholarships
                                    </p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                                        Latest program activity
                                    </h3>
                                </div>
                                <a href="/admin/reviews" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100">
                                    Open reviews
                                </a>
                            </div>

                            <div v-if="programs.length" class="mt-5 grid gap-3">
                                <a
                                    v-for="program in programs"
                                    :key="program.id"
                                    :href="`/admin/reviews#program-${program.id}`"
                                    class="flex min-w-0 items-center gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 transition hover:bg-white"
                                >
                                    <img :src="program.image_url || '/uploads/scholarship-default.jpg'" :alt="program.title" class="h-10 w-10 shrink-0 rounded-md bg-white object-contain p-1 ring-1 ring-slate-200">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-bold text-slate-950">{{ program.title }}</span>
                                        <span class="mt-1 block truncate text-xs text-slate-500">{{ program.provider || 'Provider' }} - {{ program.updated_at }}</span>
                                    </span>
                                    <span :class="['shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(program.status)]">
                                        {{ statusLabel(program.status) }}
                                    </span>
                                </a>
                            </div>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
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

                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
