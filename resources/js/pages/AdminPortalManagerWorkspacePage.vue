<script setup>
import { onMounted, ref } from 'vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const summary = ref({
    account_attention: 0,
    pending_reviews: 0,
    open_reports: 0,
    active_services: 0,
    month_revenue: 0,
    activity_today: 0,
});

const workstreams = [
    { key: 'account_attention', title: 'Account access', description: 'Resolve verification, reset, and suspension issues.', href: '/admin/workspaces/portal/accounts', icon: 'fa-solid fa-users-gear', action: 'Open accounts' },
    { key: 'pending_reviews', title: 'Verification reviews', description: 'Review providers, programs, applicants, and oversight flags.', href: '/admin/workspaces/portal/reviews', icon: 'fa-solid fa-clipboard-check', action: 'Open reviews' },
    { key: 'open_reports', title: 'Support reports', description: 'Coordinate open platform and provider concerns.', href: '/admin/workspaces/portal/support', icon: 'fa-solid fa-headset', action: 'Open support' },
    { key: 'active_services', title: 'Paid services', description: 'Track service requests that are not yet completed.', href: '/admin/workspaces/portal/billing', icon: 'fa-solid fa-file-invoice-dollar', action: 'Open services' },
];

function money(amount) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
    }).format(Number(amount ?? 0) / 100);
}

async function loadOverview() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/portal/data');
        summary.value = { ...summary.value, ...response.data.summary };
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load portal operations.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadOverview);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Operations control"
                    title="Portal manager workspace"
                    description="Choose a workstream and focus on the records that need attention."
                    icon="fa-solid fa-shield-halved"
                >
                    <template #actions>
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="loadOverview"><i class="fa-solid fa-rotate mr-1.5 text-xs" aria-hidden="true"></i>Refresh</button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading portal operations...</div>
                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <section class="admin-panel overflow-hidden">
                        <dl class="grid divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Needs attention</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ summary.account_attention + summary.pending_reviews + summary.open_reports }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Revenue this month</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ money(summary.month_revenue) }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Activity today</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ summary.activity_today }}</dd></div>
                        </dl>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <header class="border-b border-slate-200 px-5 py-4 sm:px-6"><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Workstreams</p><h2 class="mt-1 text-xl font-black text-slate-950">Operational queues</h2></header>
                        <div class="divide-y divide-slate-200">
                            <article v-for="workstream in workstreams" :key="workstream.key" class="flex flex-col gap-4 px-5 py-5 sm:px-6 lg:flex-row lg:items-center">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i :class="workstream.icon" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1"><h3 class="font-bold text-slate-950">{{ workstream.title }}</h3><p class="mt-1 text-sm text-slate-500">{{ workstream.description }}</p></div>
                                <div class="shrink-0 lg:w-28"><p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Open items</p><p class="mt-1 text-xl font-black text-slate-950">{{ summary[workstream.key] }}</p></div>
                                <a :href="workstream.href" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">{{ workstream.action }}</a>
                            </article>
                        </div>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <header class="border-b border-slate-200 px-5 py-4 sm:px-6"><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Oversight</p><h2 class="mt-1 text-xl font-black text-slate-950">Financial and records access</h2></header>
                        <div class="divide-y divide-slate-200">
                            <a href="/admin/workspaces/portal/finance" class="flex items-center gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6"><i class="fa-solid fa-chart-line w-5 text-center text-amber-700" aria-hidden="true"></i><span class="min-w-0 flex-1"><strong class="block text-sm text-slate-950">Finance overview</strong><span class="mt-1 block text-xs text-slate-500">Revenue health and confirmed payment receipts.</span></span><i class="fa-solid fa-arrow-right text-xs text-slate-400" aria-hidden="true"></i></a>
                            <a href="/admin/workspaces/portal/records/activity" class="flex items-center gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6"><i class="fa-solid fa-box-archive w-5 text-center text-amber-700" aria-hidden="true"></i><span class="min-w-0 flex-1"><strong class="block text-sm text-slate-950">Audit and exports</strong><span class="mt-1 block text-xs text-slate-500">Activity records and authorized CSV datasets.</span></span><i class="fa-solid fa-arrow-right text-xs text-slate-400" aria-hidden="true"></i></a>
                        </div>
                    </section>
                </div>
            </div>
        </section>
    </main>
</template>
