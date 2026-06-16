<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const stats = ref({
    scholarships: 0,
    applications: 0,
    drafts: 0,
    under_review: 0,
    approved: 0,
    rejected: 0,
    average_dss_score: 0,
});
const programPerformance = ref([]);
const statusCounts = ref({
    submitted: 0,
    under_review: 0,
    qualified: 0,
    approved: 0,
    rejected: 0,
});

const providerTypeLabels = {
    school: 'School / University',
    foundation: 'Foundation',
    government: 'Government Office',
    company: 'Company / Sponsor',
    non_profit: 'Non-profit Organization',
    other: 'Other Provider',
};

const statCards = computed(() => [
    {
        label: 'Programs',
        value: stats.value.scholarships,
        description: 'Scholarship programs prepared by this provider.',
        href: '/provider/programs',
        className: 'text-sky-700',
        accent: 'bg-sky-600',
    },
    {
        label: 'Applications',
        value: stats.value.applications,
        description: 'Applicant records connected to provider programs.',
        href: '/provider/applications',
        className: 'text-emerald-700',
        accent: 'bg-emerald-600',
    },
    {
        label: 'DSS Avg',
        value: `${stats.value.average_dss_score || 0}%`,
        description: 'Average decision-support score for applications.',
        href: '/provider/applications',
        className: 'text-indigo-700',
        accent: 'bg-indigo-600',
    },
    {
        label: 'Drafts',
        value: stats.value.drafts,
        description: 'Programs waiting to be completed.',
        href: '/provider/programs',
        className: 'text-amber-600',
        accent: 'bg-amber-500',
    },
]);
const statusDetails = computed(() => [
    { label: 'Submitted', value: statusCounts.value.submitted, className: 'bg-amber-100 text-amber-800' },
    { label: 'Under Review', value: statusCounts.value.under_review, className: 'bg-sky-100 text-sky-800' },
    { label: 'Qualified', value: statusCounts.value.qualified, className: 'bg-indigo-100 text-indigo-800' },
    { label: 'Approved', value: statusCounts.value.approved, className: 'bg-emerald-100 text-emerald-800' },
    { label: 'Rejected', value: statusCounts.value.rejected, className: 'bg-rose-100 text-rose-800' },
]);

const contactPerson = computed(() => {
    if (!user.value?.first_name && !user.value?.last_name) {
        return 'Not provided';
    }

    const middle = user.value?.middle_initial ? `${user.value.middle_initial}.` : '';

    return [user.value.first_name, middle, user.value.last_name]
        .filter(Boolean)
        .join(' ');
});

function providerTypeLabel(type) {
    return providerTypeLabels[type] ?? 'Not provided';
}

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/applications/data');

        user.value = response.data.user;
        stats.value = response.data.stats;
        programPerformance.value = response.data.program_performance;
        statusCounts.value = response.data.status_counts;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider dashboard.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                                Provider Dashboard
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Overview and account details
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Use this dashboard for quick provider status only. Programs and applications now have their own pages.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Signed in as
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.name ?? 'Provider' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <a
                            v-for="card in statCards"
                            :key="card.label"
                            :href="card.href"
                            class="group relative overflow-hidden rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md"
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
                            <p class="mt-4 text-sm font-bold text-slate-900 group-hover:text-sky-700">
                                Open {{ card.label.toLowerCase() }}
                            </p>
                        </a>
                    </div>

                    <section class="grid gap-6 xl:grid-cols-[0.85fr_1.15fr]">
                        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                Application Pipeline
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Review status counts
                            </h3>
                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                <div
                                    v-for="status in statusDetails"
                                    :key="status.label"
                                    class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-3"
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

                        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                        Program Performance
                                    </p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                                        Applications per scholarship
                                    </h3>
                                </div>
                                <a
                                    href="/provider/export/applications"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Export CSV
                                </a>
                            </div>

                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="program in programPerformance.slice(0, 5)"
                                    :key="program.id"
                                    class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                                >
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-950">
                                                {{ program.title }}
                                            </p>
                                            <p class="mt-1 text-sm text-slate-500">
                                                {{ program.applications }} application{{ program.applications === 1 ? '' : 's' }} - {{ program.complete_applications }} complete checklist{{ program.complete_applications === 1 ? '' : 's' }} - DSS {{ program.average_dss_score || 0 }}%
                                            </p>
                                        </div>
                                        <span
                                            :class="[
                                                'shrink-0 rounded-md px-2 py-1 text-xs font-bold uppercase',
                                                program.days_left === null
                                                    ? 'bg-slate-200 text-slate-700'
                                                    : program.days_left < 0
                                                        ? 'bg-rose-100 text-rose-800'
                                                        : program.days_left <= 7
                                                            ? 'bg-amber-100 text-amber-800'
                                                            : 'bg-emerald-100 text-emerald-800'
                                            ]"
                                        >
                                            {{ program.days_left === null ? 'No deadline' : program.days_left < 0 ? 'Expired' : `${program.days_left} days left` }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="programPerformance.length === 0" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                    No programs yet.
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                Provider Profile
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Account details
                            </h3>
                            <div class="mt-5 grid gap-3 text-sm">
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Organization
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ user?.provider_name || user?.name }}
                                    </p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Provider type
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ providerTypeLabel(user?.provider_type) }}
                                    </p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Contact person
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ contactPerson }}
                                    </p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Email
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ user?.email }}
                                    </p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Contact number
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ user?.contact_number || 'Not provided' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                Quick Actions
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Continue provider work
                            </h3>
                            <div class="mt-5 grid gap-3">
                                <a
                                    href="/provider/programs"
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4 transition hover:border-emerald-200 hover:bg-emerald-50"
                                >
                                    <p class="font-bold text-slate-950">
                                        Create or edit scholarship programs
                                    </p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        Manage titles, requirements, deadlines, and publication status.
                                    </p>
                                </a>
                                <a
                                    href="/provider/applications"
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4 transition hover:border-sky-200 hover:bg-sky-50"
                                >
                                    <p class="font-bold text-slate-950">
                                        Review applicant activity
                                    </p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        This area is prepared for application records once applicant submissions are connected.
                                    </p>
                                </a>
                                <a
                                    href="/provider/export/applications"
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4 transition hover:border-amber-200 hover:bg-amber-50"
                                >
                                    <p class="font-bold text-slate-950">
                                        Export application report
                                    </p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        Download provider application records as a CSV file.
                                    </p>
                                </a>
                            </div>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
