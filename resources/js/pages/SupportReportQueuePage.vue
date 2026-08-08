<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isAdmin = window.location.pathname.startsWith('/admin');
const Sidebar = isAdmin ? AdminSidebar : ProviderSidebar;
const Footer = isAdmin ? AdminFooter : ProviderFooter;
const basePath = isAdmin ? '/admin/reports' : '/provider/reports';
const isLoading = ref(true);
const updatingId = ref(null);
const errorMessage = ref('');
const actionError = ref('');
const selectedStatus = ref('open');
const selectedReport = ref(null);
const reports = ref([]);
const counts = ref({ open: 0, resolved: 0, all: 0 });
const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
});

const pageCopy = computed(() => ({
    eyebrow: isAdmin ? 'Platform Support' : 'Program Support',
    title: isAdmin ? 'Applicant reports' : 'Reported issues',
    description: isAdmin
        ? 'Review concerns submitted by applicants and coordinate program reports with providers.'
        : 'Handle applicant concerns connected to programs managed by your organization.',
    queueEyebrow: isAdmin ? 'Report Review Queue' : 'Issue Queue',
    queueTitle: isAdmin ? 'Review applicant concerns' : 'Respond to program concerns',
    queueDescription: isAdmin
        ? 'Open a report to review its details and record the platform support response.'
        : 'Open a report to review the concern and record your organization response.',
}));
const statusFilters = computed(() => [
    { value: 'open', label: 'Needs action', count: counts.value.open },
    { value: 'resolved', label: 'Completed', count: counts.value.resolved },
    { value: 'all', label: 'All reports', count: counts.value.all },
]);

function statusClass(status) {
    return status === 'resolved'
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-800';
}

function statusLabel(status) {
    return status === 'resolved' ? 'Completed' : 'Needs action';
}

function overallStatusLabel(status) {
    return status === 'resolved' ? 'Resolved' : 'In progress';
}

function reportIcon(category) {
    return {
        program: 'fa-solid fa-graduation-cap',
        account: 'fa-solid fa-user-gear',
        technical: 'fa-solid fa-screwdriver-wrench',
        other: 'fa-solid fa-circle-question',
    }[category] ?? 'fa-solid fa-life-ring';
}

function reportInitials(report) {
    return String(report.applicant?.name || report.subject || 'Report')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function handlingMessage(report) {
    if (!report.requires_both_roles) {
        return report.admin_status === 'resolved'
            ? 'Platform support completed this report.'
            : 'Waiting for platform support.';
    }

    if (report.overall_status === 'resolved') {
        return 'Provider and platform support both completed this report.';
    }

    if (report.provider_status === 'resolved') {
        return 'Provider response complete; waiting for platform support.';
    }

    if (report.admin_status === 'resolved') {
        return 'Platform review complete; waiting for the provider response.';
    }

    return 'Waiting for both the provider and platform support.';
}

async function loadReports(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`${basePath}/data`, {
            params: {
                page,
                status: selectedStatus.value,
            },
        });

        reports.value = response.data.reports ?? [];
        counts.value = response.data.counts ?? counts.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applicant reports.';
    } finally {
        isLoading.value = false;
    }
}

function changeFilter(status) {
    selectedStatus.value = status;
    loadReports(1);
}

function openReport(report) {
    actionError.value = '';
    selectedReport.value = report;
}

function closeReport() {
    if (updatingId.value) {
        return;
    }

    selectedReport.value = null;
    actionError.value = '';
}

async function updateStatus(report) {
    const nextStatus = report.status === 'resolved' ? 'open' : 'resolved';

    updatingId.value = report.id;
    actionError.value = '';

    try {
        const response = await window.axios.patch(`${basePath}/${report.id}/status`, { status: nextStatus });
        selectedReport.value = response.data.report;
        await loadReports(pagination.value.current_page);
    } catch (error) {
        actionError.value = error.response?.data?.message ?? 'Unable to update this report.';
    } finally {
        updatingId.value = null;
    }
}

onMounted(() => loadReports());
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <component :is="Sidebar" :active="isAdmin ? 'reports' : undefined" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header :class="isAdmin ? 'admin-hero' : 'provider-hero'">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">{{ pageCopy.eyebrow }}</p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">{{ pageCopy.title }}</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                        </div>
                        <button type="button" class="rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200" @click="loadReports(pagination.current_page)">
                            Refresh reports
                        </button>
                    </div>
                </header>

                <section class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">{{ pageCopy.queueEyebrow }}</p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">{{ pageCopy.queueTitle }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">{{ pageCopy.queueDescription }}</p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button
                                v-for="filter in statusFilters"
                                :key="filter.value"
                                type="button"
                                :class="[
                                    'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                    selectedStatus === filter.value
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50',
                                ]"
                                @click="changeFilter(filter.value)"
                            >
                                {{ filter.label }} ({{ filter.count }})
                            </button>
                        </div>
                    </div>

                    <div v-if="isLoading" class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">
                        Loading reports...
                    </div>

                    <p v-else-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                        {{ errorMessage }}
                    </p>

                    <div v-else-if="reports.length" class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                        <article
                            v-for="report in reports"
                            :key="report.id"
                            class="flex items-center gap-3 border-b border-slate-200 px-3 py-3 transition last:border-b-0 hover:bg-slate-50 sm:px-4"
                        >
                            <div class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-white ring-1 ring-slate-200">
                                <i :class="reportIcon(report.category)" aria-hidden="true"></i>
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex min-w-0 items-center gap-2">
                                    <h4 class="truncate text-sm font-bold text-slate-950 sm:text-base">{{ report.subject }}</h4>
                                    <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(report.status)]">
                                        {{ statusLabel(report.status) }}
                                    </span>
                                </div>
                                <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">{{ report.description }}</p>
                                <div class="mt-1 hidden flex-wrap items-center gap-x-3 gap-y-1 text-[11px] font-semibold text-slate-500 sm:flex">
                                    <span>{{ report.applicant?.name || 'Applicant' }}</span>
                                    <span>{{ report.category_label }}</span>
                                    <span v-if="report.program">{{ report.program.title }}</span>
                                    <span>{{ report.created_at }}</span>
                                </div>
                            </div>

                            <div class="hidden shrink-0 text-right lg:block">
                                <p :class="['text-xs font-bold', report.overall_status === 'resolved' ? 'text-emerald-700' : 'text-slate-600']">
                                    {{ overallStatusLabel(report.overall_status) }}
                                </p>
                                <p class="mt-1 max-w-64 truncate text-[11px] text-slate-500">{{ handlingMessage(report) }}</p>
                            </div>

                            <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800" @click="openReport(report)">
                                View details
                            </button>
                        </article>
                    </div>

                    <div v-else class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                        <p class="text-sm font-bold text-slate-900">No reports in this view</p>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Choose another status or wait for a new applicant concern.</p>
                    </div>

                    <div v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between gap-3">
                        <button
                            type="button"
                            :disabled="pagination.current_page <= 1"
                            class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-40"
                            @click="loadReports(pagination.current_page - 1)"
                        >
                            Previous
                        </button>
                        <span class="text-xs font-semibold text-slate-500">
                            Page {{ pagination.current_page }} of {{ pagination.last_page }}
                        </span>
                        <button
                            type="button"
                            :disabled="pagination.current_page >= pagination.last_page"
                            class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-40"
                            @click="loadReports(pagination.current_page + 1)"
                        >
                            Next
                        </button>
                    </div>
                </section>

                <component :is="Footer" />
            </div>
        </section>
    </main>

    <Teleport to="body">
        <div
            v-if="selectedReport"
            class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="support-report-detail-title"
            @click.self="closeReport"
        >
            <section class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-black tracking-[0.08em] text-amber-200">
                            {{ reportInitials(selectedReport) }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">{{ selectedReport.category_label }}</p>
                            <h2 id="support-report-detail-title" class="mt-1 text-xl font-bold text-slate-950 sm:text-2xl">{{ selectedReport.subject }}</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ selectedReport.applicant?.name || 'Applicant' }}
                                <span v-if="selectedReport.program"> - {{ selectedReport.program.title }}</span>
                            </p>
                        </div>
                    </div>
                    <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-300 text-slate-600 transition hover:bg-slate-50 hover:text-slate-950" aria-label="Close report details" @click="closeReport">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="overflow-y-auto bg-slate-50 p-5 sm:p-6">
                    <div :class="['grid overflow-hidden rounded-md border border-slate-200 bg-white', selectedReport.requires_both_roles ? 'sm:grid-cols-3' : 'sm:grid-cols-2']">
                        <div v-if="selectedReport.requires_both_roles" class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Provider response</p>
                            <span :class="['mt-2 inline-flex rounded-md px-2.5 py-1 text-xs font-bold', statusClass(selectedReport.provider_status)]">
                                {{ statusLabel(selectedReport.provider_status) }}
                            </span>
                            <p v-if="selectedReport.provider_resolved_at" class="mt-2 text-xs leading-5 text-slate-500">
                                {{ selectedReport.provider_resolved_by || 'Provider staff' }} - {{ selectedReport.provider_resolved_at }}
                            </p>
                        </div>

                        <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Platform review</p>
                            <span :class="['mt-2 inline-flex rounded-md px-2.5 py-1 text-xs font-bold', statusClass(selectedReport.admin_status)]">
                                {{ statusLabel(selectedReport.admin_status) }}
                            </span>
                            <p v-if="selectedReport.admin_resolved_at" class="mt-2 text-xs leading-5 text-slate-500">
                                {{ selectedReport.admin_resolved_by || 'Admin staff' }} - {{ selectedReport.admin_resolved_at }}
                            </p>
                        </div>

                        <div class="p-4">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Overall report</p>
                            <span :class="['mt-2 inline-flex rounded-md px-2.5 py-1 text-xs font-bold', statusClass(selectedReport.overall_status)]">
                                {{ overallStatusLabel(selectedReport.overall_status) }}
                            </span>
                            <p class="mt-2 text-xs leading-5 text-slate-500">{{ handlingMessage(selectedReport) }}</p>
                        </div>
                    </div>

                    <div v-if="selectedReport.requires_both_roles" class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        Program reports close for the applicant only after both the provider and platform support complete their part. Each role can reopen only its own handling state.
                    </div>

                    <section class="mt-4 rounded-md border border-slate-200 bg-white p-4 sm:p-5">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Reported concern</p>
                        <p class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-700">{{ selectedReport.description }}</p>
                    </section>

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-md border border-slate-200 bg-white p-3">
                            <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Applicant</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ selectedReport.applicant?.name || 'Applicant' }}</dd>
                            <dd class="mt-1 text-xs text-slate-500">{{ selectedReport.applicant?.email || 'Email not available' }}</dd>
                        </div>
                        <div class="rounded-md border border-slate-200 bg-white p-3">
                            <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Submitted</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ selectedReport.created_at }}</dd>
                            <dd class="mt-1 text-xs text-slate-500">{{ selectedReport.program?.title || 'Platform concern' }}</dd>
                        </div>
                    </dl>

                    <p v-if="actionError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ actionError }}</p>
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <p class="text-xs font-semibold text-slate-500">
                        Updating this changes only the {{ isAdmin ? 'platform' : 'provider' }} handling state.
                    </p>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row">
                        <button type="button" :disabled="updatingId === selectedReport.id" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60" @click="closeReport">
                            Close
                        </button>
                        <button
                            type="button"
                            :disabled="updatingId === selectedReport.id"
                            :class="[
                                'rounded-md px-4 py-2.5 text-sm font-bold transition disabled:cursor-not-allowed disabled:opacity-60',
                                selectedReport.status === 'resolved'
                                    ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                    : 'bg-slate-950 text-white hover:bg-slate-800',
                            ]"
                            @click="updateStatus(selectedReport)"
                        >
                            {{ updatingId === selectedReport.id
                                ? 'Saving...'
                                : (selectedReport.status === 'resolved' ? 'Reopen for my team' : 'Mark my part complete') }}
                        </button>
                    </div>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
