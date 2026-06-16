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
    published_scholarships: 0,
    draft_scholarships: 0,
    applications: 0,
    recent_applications: 0,
    average_match_score: 0,
    average_dss_score: 0,
    highly_recommended_applications: 0,
    needs_review_applications: 0,
    saved_scholarships: 0,
    documents_pending_review: 0,
    documents_needing_replacement: 0,
    upcoming_deadlines: 0,
    expired_published: 0,
});
const users = ref([]);
const applicationStatuses = ref({
    submitted: 0,
    under_review: 0,
    qualified: 0,
    approved: 0,
    rejected: 0,
});
const deadlineWatch = ref([]);
const recentApplications = ref([]);
const monthlyApplications = ref([]);
const documentStatuses = ref({
    pending: 0,
    accepted: 0,
    rejected: 0,
    needs_replacement: 0,
});
const dssRecommendations = ref({
    highly_recommended: 0,
    recommended: 0,
    needs_review: 0,
    low_priority: 0,
    not_recommended: 0,
});
const decisionReasons = ref([]);

const statCards = computed(() => [
    {
        label: 'Total Users',
        value: stats.value.total_users,
        description: 'All registered portal accounts.',
        className: 'text-slate-950',
        accent: 'bg-slate-900',
    },
    {
        label: 'Applications',
        value: stats.value.applications,
        description: 'Submitted scholarship applications.',
        className: 'text-emerald-700',
        accent: 'bg-emerald-600',
    },
    {
        label: 'Scholarships',
        value: stats.value.scholarships,
        description: 'Programs created by providers.',
        className: 'text-sky-700',
        accent: 'bg-sky-600',
    },
    {
        label: 'Upcoming Deadlines',
        value: stats.value.upcoming_deadlines,
        description: 'Published deadlines in the next 30 days.',
        className: 'text-amber-600',
        accent: 'bg-amber-500',
    },
    {
        label: 'Avg Match',
        value: `${stats.value.average_dss_score || 0}%`,
        description: 'Average decision-support score.',
        className: 'text-indigo-700',
        accent: 'bg-indigo-600',
    },
    {
        label: 'Saved Programs',
        value: stats.value.saved_scholarships,
        description: 'Scholarships bookmarked by students.',
        className: 'text-emerald-700',
        accent: 'bg-emerald-600',
    },
    {
        label: 'Pending Documents',
        value: stats.value.documents_pending_review,
        description: 'Uploaded files waiting for review.',
        className: 'text-amber-600',
        accent: 'bg-amber-500',
    },
    {
        label: 'Recent Activity',
        value: stats.value.recent_applications + stats.value.recent_signups,
        description: 'Applications and signups in the last 7 days.',
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
const statusDetails = computed(() => [
    { label: 'Submitted', value: applicationStatuses.value.submitted, className: 'bg-amber-100 text-amber-800' },
    { label: 'Under Review', value: applicationStatuses.value.under_review, className: 'bg-sky-100 text-sky-800' },
    { label: 'Qualified', value: applicationStatuses.value.qualified, className: 'bg-indigo-100 text-indigo-800' },
    { label: 'Approved', value: applicationStatuses.value.approved, className: 'bg-emerald-100 text-emerald-800' },
    { label: 'Rejected', value: applicationStatuses.value.rejected, className: 'bg-rose-100 text-rose-800' },
]);
const documentStatusDetails = computed(() => [
    { label: 'Pending', value: documentStatuses.value.pending, className: 'bg-sky-100 text-sky-800' },
    { label: 'Accepted', value: documentStatuses.value.accepted, className: 'bg-emerald-100 text-emerald-800' },
    { label: 'Needs Replacement', value: documentStatuses.value.needs_replacement, className: 'bg-amber-100 text-amber-800' },
    { label: 'Rejected', value: documentStatuses.value.rejected, className: 'bg-rose-100 text-rose-800' },
]);
const dssRecommendationDetails = computed(() => [
    { label: 'Highly Recommended', value: dssRecommendations.value.highly_recommended, className: 'bg-emerald-100 text-emerald-800' },
    { label: 'Recommended', value: dssRecommendations.value.recommended, className: 'bg-sky-100 text-sky-800' },
    { label: 'Needs Review', value: dssRecommendations.value.needs_review, className: 'bg-amber-100 text-amber-800' },
    { label: 'Low Priority', value: dssRecommendations.value.low_priority, className: 'bg-rose-100 text-rose-800' },
    { label: 'Not Recommended', value: dssRecommendations.value.not_recommended, className: 'bg-slate-200 text-slate-700' },
]);

function labelFromKey(value) {
    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadAdminData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/analytics');

        stats.value = response.data.stats;
        applicationStatuses.value = response.data.application_statuses;
        documentStatuses.value = response.data.document_statuses;
        dssRecommendations.value = response.data.dss_recommendations;
        decisionReasons.value = response.data.decision_reasons;
        deadlineWatch.value = response.data.deadline_watch;
        users.value = response.data.recent_users;
        recentApplications.value = response.data.recent_applications_list;
        monthlyApplications.value = response.data.monthly_applications;
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

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a
                                href="/admin/manage-users"
                                class="rounded-md bg-amber-300 px-4 py-2.5 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                            >
                                Manage Users
                            </a>
                            <a
                                href="/admin/export/users"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Export Users
                            </a>
                            <a
                                href="/admin/export/applications"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Export Applications
                            </a>
                        </div>
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

                <div class="mt-6 grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Application Pipeline
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Status breakdown
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            Track where applications currently sit in the review process.
                        </p>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="status in statusDetails"
                                :key="status.label"
                                class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <span :class="['rounded-md px-2 py-1 text-xs font-bold uppercase', status.className]">
                                    {{ status.label }}
                                </span>
                                <span class="font-display text-2xl font-bold text-slate-950">
                                    {{ status.value }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-6 border-t border-slate-200 pt-5">
                            <p class="text-sm font-semibold text-slate-700">
                                DSS recommendation distribution
                            </p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div
                                    v-for="recommendation in dssRecommendationDetails"
                                    :key="recommendation.label"
                                    class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4"
                                >
                                    <span :class="['rounded-md px-2 py-1 text-xs font-bold uppercase', recommendation.className]">
                                        {{ recommendation.label }}
                                    </span>
                                    <span class="font-display text-2xl font-bold text-slate-950">
                                        {{ recommendation.value }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 border-t border-slate-200 pt-5">
                            <p class="text-sm font-semibold text-slate-700">
                                Document review status
                            </p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div
                                    v-for="status in documentStatusDetails"
                                    :key="status.label"
                                    class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4"
                                >
                                    <span :class="['rounded-md px-2 py-1 text-xs font-bold uppercase', status.className]">
                                        {{ status.label }}
                                    </span>
                                    <span class="font-display text-2xl font-bold text-slate-950">
                                        {{ status.value }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Deadline Monitoring
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Published program deadlines
                        </h3>
                        <div class="mt-5 grid gap-3">
                            <div
                                v-for="deadline in deadlineWatch"
                                :key="deadline.id"
                                class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-950">
                                        {{ deadline.title }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ deadline.deadline }}
                                    </p>
                                </div>
                                <span
                                    :class="[
                                        'shrink-0 rounded-md px-2 py-1 text-xs font-bold uppercase',
                                        deadline.days_left < 0
                                            ? 'bg-rose-100 text-rose-800'
                                            : deadline.days_left <= 7
                                                ? 'bg-amber-100 text-amber-800'
                                                : 'bg-emerald-100 text-emerald-800'
                                    ]"
                                >
                                    {{ deadline.days_left < 0 ? 'Expired' : `${deadline.days_left} days` }}
                                </span>
                            </div>

                            <div v-if="deadlineWatch.length === 0" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                No published program deadlines found.
                            </div>
                        </div>
                    </section>
                </div>

                <div class="mt-6 grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-700">
                            Decision Reasons
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Review outcome signals
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            Structured reasons make it easier to find why applications are approved, delayed, or rejected.
                        </p>

                        <div v-if="decisionReasons.length" class="mt-5 grid gap-3">
                            <div
                                v-for="reason in decisionReasons"
                                :key="reason.reason"
                                class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <p class="text-sm font-bold text-slate-950">
                                    {{ reason.label }}
                                </p>
                                <p class="font-display text-2xl font-bold text-indigo-700">
                                    {{ reason.total }}
                                </p>
                            </div>
                        </div>

                        <div v-else class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                            No decision reasons recorded yet.
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                            Monthly Trend
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Applications over time
                        </h3>
                        <div class="mt-5 grid gap-3">
                            <div
                                v-for="month in monthlyApplications"
                                :key="month.label"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex items-center justify-between gap-4">
                                    <p class="text-sm font-bold text-slate-950">
                                        {{ month.label }}
                                    </p>
                                    <p class="text-sm font-bold text-sky-700">
                                        {{ month.total }} applications
                                    </p>
                                </div>
                                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div
                                        class="h-full rounded-full bg-sky-600"
                                        :style="{ width: `${Math.min(month.total * 20, 100)}%` }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Latest Applications
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Recent submissions
                        </h3>
                        <div class="mt-5 grid gap-3">
                            <div
                                v-for="application in recentApplications"
                                :key="application.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-950">
                                            {{ application.applicant || 'Applicant' }}
                                        </p>
                                        <p class="mt-1 truncate text-sm text-slate-500">
                                            {{ application.scholarship || 'Scholarship' }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 rounded-md bg-slate-200 px-2 py-1 text-xs font-bold uppercase text-slate-700">
                                        {{ application.status }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm font-semibold text-indigo-700">
                                    DSS: {{ application.dss_score ?? 0 }}% - {{ labelFromKey(application.dss_recommendation || 'needs_review') }}
                                </p>
                                <p class="mt-2 text-sm text-slate-500">
                                    {{ application.submitted_at || 'Recently submitted' }}
                                </p>
                            </div>

                            <div v-if="recentApplications.length === 0" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                No applications submitted yet.
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
