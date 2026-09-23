<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

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
const canManageAccounts = computed(() => Boolean(window.portalUser?.has_full_access || window.portalUser?.permissions?.includes('manage_accounts')));
const canManageReviews = computed(() => Boolean(window.portalUser?.has_full_access || window.portalUser?.permissions?.includes('manage_reviews')));
const hasPermission = (permission) => Boolean(
    window.portalUser?.has_full_access || window.portalUser?.permissions?.includes(permission),
);
const roleLabel = computed(() => window.portalUser?.is_managed_account
    ? (window.portalUser?.account_title || 'Admin staff')
    : 'Primary administrator');
const roleWorkspaces = computed(() => [
    {
        permission: 'manage_reports',
        label: 'Reported issues',
        description: 'Review concerns submitted by applicants and providers.',
        href: '/admin/reports',
        action: 'Open reports',
        icon: 'fa-solid fa-circle-exclamation',
    },
    {
        permission: 'manage_billing',
        label: 'Service requests',
        description: 'Coordinate provider meetings, files, and service delivery.',
        href: '/admin/billing',
        action: 'Open services',
        icon: 'fa-solid fa-headset',
    },
    {
        permission: 'view_finance',
        label: 'Platform finance',
        description: 'Review payment totals, transactions, and receipts.',
        href: '/admin/finance',
        action: 'Open finance',
        icon: 'fa-solid fa-chart-line',
    },
    {
        permission: 'view_logs',
        label: 'Activity records',
        description: 'Inspect account and security activity across the portal.',
        href: '/admin/logs',
        action: 'Open records',
        icon: 'fa-solid fa-clock-rotate-left',
    },
].filter((workspace) => hasPermission(workspace.permission)));
const workspaceDescription = computed(() => {
    if (canManageReviews.value && canManageAccounts.value) return 'Monitor reviews, accounts, operations, and platform activity.';
    if (canManageReviews.value) return 'Review applicant, provider, and scholarship verification queues.';
    if (canManageAccounts.value) return 'Manage portal accounts, access, and account status.';
    if (roleWorkspaces.value.length === 1) return roleWorkspaces.value[0].description;

    return 'Use the workspaces assigned to your administrative role.';
});
const recentUsers = computed(() => users.value.slice(0, 4));
const platformSignals = computed(() => canManageReviews.value ? [
    {
        label: 'Provider approval queue',
        icon: 'fa-solid fa-building-circle-check',
        tone: (stats.value.pending_providers || 0) > 0 ? 'warn' : 'good',
        detail: (stats.value.pending_providers || 0) > 0
            ? `${stats.value.pending_providers} provider account${stats.value.pending_providers === 1 ? '' : 's'} waiting for verification.`
            : 'No provider approvals waiting.',
        href: '/admin/reviews',
        action: 'Open reviews',
    },
    {
        label: 'Document review backlog',
        icon: 'fa-solid fa-file-circle-check',
        tone: (stats.value.documents_pending_review || 0) > 0 || (stats.value.documents_needing_replacement || 0) > 0 ? 'warn' : 'good',
        detail: `${stats.value.documents_pending_review || 0} pending review, ${stats.value.documents_needing_replacement || 0} needing replacement.`,
        href: '/admin/reviews',
        action: 'Check documents',
    },
    {
        label: 'Deadline monitoring',
        icon: 'fa-solid fa-calendar-day',
        tone: (stats.value.expired_published || 0) > 0 || (stats.value.upcoming_deadlines || 0) > 0 ? 'warn' : 'good',
        detail: `${stats.value.upcoming_deadlines || 0} upcoming deadline${(stats.value.upcoming_deadlines || 0) === 1 ? '' : 's'}, ${stats.value.expired_published || 0} expired published program${(stats.value.expired_published || 0) === 1 ? '' : 's'}.`,
        href: '/admin/reviews',
        action: 'Review programs',
    },
    {
        label: 'DSS review load',
        icon: 'fa-solid fa-scale-balanced',
        tone: (stats.value.needs_review_applications || 0) > 0 ? 'info' : 'good',
        detail: (stats.value.needs_review_applications || 0) > 0
            ? `${stats.value.needs_review_applications} application${stats.value.needs_review_applications === 1 ? '' : 's'} need closer review.`
            : 'No DSS review load detected.',
        href: '/admin/reviews',
        action: 'Review applications',
    },
] : []);

function signalClass(tone) {
    if (tone === 'good') {
        return 'bg-slate-100 text-slate-600';
    }

    if (tone === 'warn') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-200 text-slate-700';
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
        const response = await window.axios.get('/admin/dashboard/data');

        stats.value = response.data.stats;
        users.value = response.data.recent_users;
        programs.value = response.data.recent_scholarships ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load admin dashboard.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadAdminData);
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="dashboard" />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Admin dashboard"
                    title="Administration overview"
                    :description="workspaceDescription"
                    icon="fa-solid fa-gauge-high"
                >
                    <template #meta>
                        <span>{{ roleLabel }}</span>
                    </template>
                    <template v-if="canManageAccounts" #actions>
                        <a
                            href="/admin/manage-users"
                            class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                        >
                            Manage users
                        </a>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">
                    Loading admin dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="admin-content-stack">
                    <section v-if="canManageReviews" class="admin-panel overflow-hidden">
                        <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Review Workspace
                                </p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    Items that may need attention
                                </h3>
                            </div>
                            <a href="/admin/reviews" class="text-sm font-bold text-slate-700 transition hover:text-slate-950">Open all reviews</a>
                        </div>

                        <div class="divide-y divide-slate-200">
                            <a
                                v-for="signal in platformSignals"
                                :key="signal.label"
                                :href="signal.href"
                                class="group flex items-center gap-3 px-4 py-3 transition hover:bg-slate-50 sm:px-5"
                            >
                                <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-md', signalClass(signal.tone)]">
                                    <i :class="[signal.icon, 'text-xs']"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-bold text-slate-950">{{ signal.label }}</span>
                                    <span class="mt-0.5 block text-sm leading-5 text-slate-500">{{ signal.detail }}</span>
                                </span>
                                <span class="hidden shrink-0 text-xs font-bold text-slate-500 sm:block">{{ signal.action }}</span>
                                <i class="fa-solid fa-chevron-right text-[10px] text-slate-300 transition group-hover:text-slate-600"></i>
                            </a>
                        </div>
                    </section>

                    <section v-if="canManageReviews || canManageAccounts" class="grid gap-4 lg:grid-cols-2">
                        <article v-if="canManageReviews" class="admin-panel h-full p-5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                        Recent Scholarships
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Latest program activity
                                    </h3>
                                </div>
                                <a href="/admin/reviews" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100">
                                    Open reviews
                                </a>
                            </div>

                            <div v-if="programs.length" class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                <a
                                    v-for="program in programs"
                                    :key="program.id"
                                    :href="`/admin/scholarships/${program.id}/review`"
                                    class="flex min-w-0 items-center gap-3 bg-white px-3 py-2.5 transition hover:bg-slate-50"
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

                        <article v-if="canManageAccounts" class="admin-panel h-full p-5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                        Recent Accounts
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
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

                            <div class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                <div
                                    v-for="user in recentUsers"
                                    :key="user.id"
                                    class="flex flex-col gap-2 bg-white px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between"
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

                    <section v-if="!canManageReviews && !canManageAccounts && roleWorkspaces.length" class="grid gap-4 sm:grid-cols-2">
                        <a v-for="workspace in roleWorkspaces" :key="workspace.permission" :href="workspace.href" class="admin-panel group flex items-center gap-4 p-5 transition hover:border-slate-400">
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                <i :class="workspace.icon" aria-hidden="true"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-base font-bold text-slate-950">{{ workspace.label }}</span>
                                <span class="mt-1 block text-sm leading-5 text-slate-500">{{ workspace.description }}</span>
                                <span class="mt-3 block text-xs font-bold text-slate-700">{{ workspace.action }} <i class="fa-solid fa-arrow-right ml-1 text-[10px]" aria-hidden="true"></i></span>
                            </span>
                        </a>
                    </section>

                    <section v-if="!canManageReviews && !canManageAccounts && !roleWorkspaces.length" class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-bold text-slate-950">Your admin profile is available.</p>
                        <p class="mt-1 text-sm text-slate-500">No operational permission is currently assigned to this account.</p>
                    </section>

                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
