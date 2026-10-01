<script setup>
import { computed, onMounted, ref } from 'vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import SupportOfficerSidebar from '../components/SupportOfficerSidebar.vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isLoading = ref(true);
const usesPortalManagerWorkspace = window.location.pathname.startsWith('/admin/workspaces/portal');
const updatingId = ref(null);
const errorMessage = ref('');
const actionError = ref('');
const selectedStatus = ref('open');
const selectedCategory = ref('all');
const searchDraft = ref('');
const appliedSearch = ref('');
const selectedReport = ref(null);
const previewAttachment = ref(null);
const reports = ref([]);
const categories = ref([]);
const counts = ref({ open: 0, resolved: 0, all: 0 });
const summary = ref({ needs_action: 0, privacy_open: 0, shared_open: 0, provider_submitted_open: 0 });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });

const statusFilters = computed(() => [
    { value: 'open', label: 'Needs action', count: counts.value.open },
    { value: 'resolved', label: 'Completed', count: counts.value.resolved },
    { value: 'all', label: 'All reports', count: counts.value.all },
]);

function statusClass(status) {
    return status === 'resolved'
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-900';
}

function statusLabel(status) {
    return status === 'resolved' ? 'Completed' : 'Needs action';
}

function reportIcon(category) {
    return {
        program: 'fa-solid fa-graduation-cap',
        account: 'fa-solid fa-user-gear',
        privacy: 'fa-solid fa-user-shield',
        technical: 'fa-solid fa-screwdriver-wrench',
        service: 'fa-solid fa-hand-holding-dollar',
        data: 'fa-solid fa-database',
        other: 'fa-solid fa-circle-question',
    }[category] ?? 'fa-solid fa-life-ring';
}

function reporterName(report) {
    return report.applicant?.name || (report.submitted_by_provider ? 'Provider team' : 'Applicant');
}

function reporterType(report) {
    return report.submitted_by_provider ? 'Provider report' : 'Applicant report';
}

function coordinationLabel(report) {
    if (!report.requires_both_roles) return 'Platform support only';
    if (report.overall_status === 'resolved') return 'Both teams completed';
    if (report.provider_status === 'resolved') return 'Provider completed';
    if (report.admin_status === 'resolved') return 'Waiting for provider';
    return 'Shared with provider';
}

function completionMessage(report) {
    if (!report.requires_both_roles) {
        return report.admin_status === 'resolved'
            ? 'Platform support completed this report.'
            : 'This report is assigned to platform support.';
    }

    if (report.overall_status === 'resolved') return 'The provider and platform support have completed their parts.';
    if (report.provider_status === 'resolved') return 'The provider is finished. Platform support still needs to complete its review.';
    if (report.admin_status === 'resolved') return 'Your review is complete. The report stays open until the provider finishes.';
    return 'The provider and platform support each have a required response.';
}

async function loadReports(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/support/data', {
            params: {
                page,
                status: selectedStatus.value,
                category: selectedCategory.value,
                search: appliedSearch.value || undefined,
            },
        });
        reports.value = response.data.reports ?? [];
        categories.value = response.data.categories ?? [];
        counts.value = response.data.counts ?? counts.value;
        summary.value = response.data.summary ?? summary.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load support reports.';
    } finally {
        isLoading.value = false;
    }
}

function changeStatus(status) {
    selectedStatus.value = status;
    loadReports(1);
}

function applySearch() {
    appliedSearch.value = searchDraft.value.trim();
    loadReports(1);
}

function clearSearch() {
    searchDraft.value = '';
    appliedSearch.value = '';
    loadReports(1);
}

function openReport(report) {
    selectedReport.value = report;
    actionError.value = '';
}

function closeReport() {
    if (!updatingId.value) {
        selectedReport.value = null;
        actionError.value = '';
    }
}

async function updateStatus(report) {
    updatingId.value = report.id;
    actionError.value = '';

    try {
        const response = await window.axios.patch(`/admin/reports/${report.id}/status`, {
            status: report.status === 'resolved' ? 'open' : 'resolved',
        });
        selectedReport.value = response.data.report;
        await loadReports(pagination.value.current_page);
    } catch (error) {
        actionError.value = error.response?.data?.message ?? 'Unable to update this report.';
    } finally {
        updatingId.value = null;
    }
}

onMounted(loadReports);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar v-if="usesPortalManagerWorkspace" />
        <SupportOfficerSidebar v-else />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Resolution desk"
                    title="Support workspace"
                    description="Review concerns, check evidence, and record your team response."
                    icon="fa-solid fa-headset"
                >
                    <template #actions>
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="loadReports(pagination.current_page)">
                            <i class="fa-solid fa-rotate mr-1.5 text-xs" aria-hidden="true"></i>Refresh
                        </button>
                    </template>
                </TaskPageHeader>

                <div v-if="isLoading && !reports.length" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading support queue...</div>

                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <section class="admin-panel overflow-hidden">
                        <dl class="grid divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                            <div class="px-5 py-4">
                                <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Needs action</dt>
                                <dd class="mt-1 text-2xl font-black text-slate-950">{{ summary.needs_action }}</dd>
                            </div>
                            <div class="px-5 py-4">
                                <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Shared with providers</dt>
                                <dd class="mt-1 text-2xl font-black text-slate-950">{{ summary.shared_open }}</dd>
                            </div>
                            <div class="px-5 py-4">
                                <dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Privacy requests</dt>
                                <dd class="mt-1 text-2xl font-black text-slate-950">{{ summary.privacy_open }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Report queue</p>
                            <h2 class="mt-1 text-xl font-black text-slate-950">Concerns requiring review</h2>
                        </header>

                        <form class="grid gap-3 border-b border-slate-200 bg-slate-50 p-3 lg:grid-cols-[minmax(0,1fr)_15rem_auto]" @submit.prevent="applySearch">
                            <label class="relative">
                                <span class="sr-only">Search reports</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input v-model="searchDraft" type="search" maxlength="120" placeholder="Search subject, reporter, program, or affected area" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-slate-500">
                            </label>
                            <select v-model="selectedCategory" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none" @change="loadReports(1)">
                                <option value="all">All categories</option>
                                <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option>
                            </select>
                            <div class="flex gap-2">
                                <button type="submit" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Search</button>
                                <button v-if="appliedSearch" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-50" @click="clearSearch">Clear</button>
                            </div>
                        </form>

                        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
                            <button
                                v-for="filter in statusFilters"
                                :key="filter.value"
                                type="button"
                                :class="['rounded-md border px-3 py-2 text-xs font-bold transition', selectedStatus === filter.value ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50']"
                                @click="changeStatus(filter.value)"
                            >
                                {{ filter.label }} <span class="ml-1 opacity-70">{{ filter.count }}</span>
                            </button>
                            <p class="ml-auto text-xs font-semibold text-slate-500">{{ pagination.from || 0 }}-{{ pagination.to || 0 }} of {{ pagination.total }}</p>
                        </div>

                        <div v-if="isLoading" class="p-6 text-sm text-slate-500">Updating queue...</div>

                        <div v-else-if="reports.length" class="divide-y divide-slate-200">
                            <article v-for="report in reports" :key="report.id" class="flex flex-col gap-3 px-5 py-4 sm:px-6 lg:flex-row lg:items-center">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-sm text-amber-300">
                                    <i :class="reportIcon(report.category)" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="line-clamp-1 font-bold text-slate-950">{{ report.subject }}</h3>
                                        <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(report.status)]">{{ statusLabel(report.status) }}</span>
                                    </div>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ reporterName(report) }} &middot; {{ report.category_label }}<template v-if="report.program"> &middot; {{ report.program.title }}</template></p>
                                </div>
                                <div class="min-w-0 lg:w-44">
                                    <p class="text-xs font-bold text-slate-700">{{ coordinationLabel(report) }}</p>
                                    <p class="mt-1 truncate text-[11px] text-slate-500">{{ report.created_at }}</p>
                                </div>
                                <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800" @click="openReport(report)">Open report</button>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                            <p class="mt-3 font-bold text-slate-950">No reports in this view</p>
                            <p class="mt-1 text-sm text-slate-500">Try another status, category, or search term.</p>
                        </div>

                        <nav v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3" aria-label="Support queue pagination">
                            <button type="button" :disabled="pagination.current_page <= 1" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadReports(pagination.current_page - 1)">Previous</button>
                            <span class="text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                            <button type="button" :disabled="pagination.current_page >= pagination.last_page" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadReports(pagination.current_page + 1)">Next</button>
                        </nav>
                    </section>
                </div>
            </div>
        </section>
    </main>

    <Teleport to="body">
        <div v-if="selectedReport" class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="support-officer-report-title" @click.self="closeReport">
            <section class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i :class="reportIcon(selectedReport.category)" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ selectedReport.category_label }}</p>
                            <h2 id="support-officer-report-title" class="mt-1 text-xl font-black text-slate-950">{{ selectedReport.subject }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ reporterName(selectedReport) }} &middot; {{ reporterType(selectedReport) }}</p>
                        </div>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-600 hover:bg-slate-50" aria-label="Close report" @click="closeReport"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>

                <div class="overflow-y-auto bg-slate-50 p-5 sm:p-6">
                    <section class="overflow-hidden rounded-md border border-slate-200 bg-white">
                        <div class="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div><p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Handling status</p><p class="mt-1 text-sm font-bold text-slate-950">{{ coordinationLabel(selectedReport) }}</p></div>
                            <div class="flex flex-wrap gap-2"><span :class="['rounded-md px-2.5 py-1 text-xs font-bold', statusClass(selectedReport.admin_status)]">Platform: {{ statusLabel(selectedReport.admin_status) }}</span><span v-if="selectedReport.requires_both_roles" :class="['rounded-md px-2.5 py-1 text-xs font-bold', statusClass(selectedReport.provider_status)]">Provider: {{ statusLabel(selectedReport.provider_status) }}</span></div>
                        </div>
                        <p class="px-4 py-3 text-sm leading-6 text-slate-600">{{ completionMessage(selectedReport) }}</p>
                    </section>

                    <section class="mt-4 rounded-md border border-slate-200 bg-white p-4 sm:p-5">
                        <div class="flex flex-wrap items-center gap-2"><p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Reported concern</p><span v-if="selectedReport.privacy_request_type_label" class="rounded-md bg-amber-100 px-2 py-1 text-[10px] font-bold uppercase text-amber-900">{{ selectedReport.privacy_request_type_label }}</span></div>
                        <p v-if="selectedReport.context" class="mt-3 text-sm font-semibold text-slate-800"><span class="text-slate-500">Affected area:</span> {{ selectedReport.context }}</p>
                        <p class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-700">{{ selectedReport.description }}</p>
                        <button v-if="selectedReport.attachment" type="button" class="mt-4 inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewAttachment = selectedReport.attachment"><i class="fa-solid fa-paperclip text-amber-700" aria-hidden="true"></i>View attachment</button>
                    </section>

                    <div class="mt-4 border-t border-slate-200 pt-4 text-xs leading-5 text-slate-500">
                        <p><strong class="text-slate-700">Submitted:</strong> {{ selectedReport.created_at }}</p>
                        <p><strong class="text-slate-700">Contact:</strong> {{ selectedReport.applicant?.email || 'Not available' }}</p>
                        <p v-if="selectedReport.program"><strong class="text-slate-700">Program:</strong> {{ selectedReport.program.title }}</p>
                    </div>

                    <p v-if="actionError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ actionError }}</p>
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-white px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    <button type="button" :disabled="updatingId === selectedReport.id" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-60" @click="closeReport">Close</button>
                    <button type="button" :disabled="updatingId === selectedReport.id" :class="['rounded-md px-4 py-2.5 text-sm font-bold disabled:opacity-60', selectedReport.status === 'resolved' ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50' : 'bg-slate-950 text-white hover:bg-slate-800']" @click="updateStatus(selectedReport)">{{ updatingId === selectedReport.id ? 'Saving...' : (selectedReport.status === 'resolved' ? 'Reopen for my team' : 'Mark my part complete') }}</button>
                </footer>
            </section>
        </div>
    </Teleport>

    <FilePreviewModal :file="previewAttachment" title="Support report attachment" context="Submitted with this report" @close="previewAttachment = null" />
</template>
