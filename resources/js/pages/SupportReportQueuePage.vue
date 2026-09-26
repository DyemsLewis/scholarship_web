<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isAdmin = window.location.pathname.startsWith('/admin');
const Sidebar = isAdmin ? AdminSidebar : ProviderSidebar;
const basePath = isAdmin ? '/admin/reports' : '/provider/reports';
const isLoading = ref(true);
const updatingId = ref(null);
const errorMessage = ref('');
const actionError = ref('');
const selectedStatus = ref('open');
const selectedReport = ref(null);
const previewAttachment = ref(null);
const reports = ref([]);
const providerCategories = ref([]);
const providerPrograms = ref([]);
const showProviderReportForm = ref(false);
const isSubmittingProviderReport = ref(false);
const providerReportError = ref('');
const providerReportFile = ref(null);
const providerReportForm = ref(emptyProviderReportForm());
const counts = ref({ open: 0, resolved: 0, all: 0 });
const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
});

const pageCopy = computed(() => ({
    eyebrow: isAdmin ? 'Platform Support' : 'Provider Support',
    title: isAdmin ? 'Applicant and provider reports' : 'Reports and support',
    description: isAdmin
        ? 'Review concerns submitted by applicants and coordinate program reports with providers.'
        : 'Respond to applicant concerns and follow problems sent to platform support.',
}));
const statusFilters = computed(() => [
    { value: 'open', label: isAdmin ? 'Needs action' : 'Open', count: counts.value.open },
    { value: 'resolved', label: 'Completed', count: counts.value.resolved },
    { value: 'all', label: 'All reports', count: counts.value.all },
]);

function emptyProviderReportForm() {
    return {
        category: 'technical',
        scholarshipId: '',
        context: '',
        subject: '',
        description: '',
    };
}

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
        privacy: 'fa-solid fa-user-shield',
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

function reporterName(report) {
    if (report.submitted_by_provider) {
        return report.applicant?.name || 'Provider team';
    }

    return report.applicant?.name || 'Applicant';
}

function reporterType(report) {
    return report.submitted_by_provider ? 'Provider report' : 'Applicant report';
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
        providerCategories.value = response.data.categories ?? providerCategories.value;
        providerPrograms.value = response.data.programs ?? providerPrograms.value;
        counts.value = response.data.counts ?? counts.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applicant reports.';
    } finally {
        isLoading.value = false;
    }
}

function openProviderReportForm() {
    providerReportForm.value = emptyProviderReportForm();
    providerReportFile.value = null;
    providerReportError.value = '';
    showProviderReportForm.value = true;
}

function closeProviderReportForm() {
    if (isSubmittingProviderReport.value) {
        return;
    }

    showProviderReportForm.value = false;
    providerReportError.value = '';
}

function selectProviderReportFile(event) {
    providerReportFile.value = event.target.files?.[0] ?? null;
}

async function submitProviderReport() {
    isSubmittingProviderReport.value = true;
    providerReportError.value = '';

    const payload = new FormData();
    payload.append('category', providerReportForm.value.category);
    payload.append('subject', providerReportForm.value.subject);
    payload.append('description', providerReportForm.value.description);

    if (providerReportForm.value.scholarshipId) {
        payload.append('scholarship_id', providerReportForm.value.scholarshipId);
    }

    if (providerReportForm.value.context.trim()) {
        payload.append('context', providerReportForm.value.context.trim());
    }

    if (providerReportFile.value) {
        payload.append('attachment_file', providerReportFile.value);
    }

    try {
        await window.axios.post('/provider/reports', payload);
        showProviderReportForm.value = false;
        selectedStatus.value = 'open';
        await loadReports(1);
    } catch (error) {
        providerReportError.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to submit this report.';
    } finally {
        isSubmittingProviderReport.value = false;
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

function openReportAttachment(report) {
    previewAttachment.value = report.attachment ?? null;
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
    <main :class="isAdmin ? 'admin-shell' : 'provider-shell'">
        <component :is="Sidebar" :active="isAdmin ? 'reports' : undefined" />

        <section :class="isAdmin ? 'admin-page' : 'provider-page'">
            <div :class="isAdmin ? 'admin-container' : 'provider-container'">
                <TaskPageHeader
                    :theme="isAdmin ? 'admin' : 'provider'"
                    :eyebrow="pageCopy.eyebrow"
                    :title="pageCopy.title"
                    :description="pageCopy.description"
                    icon="fa-solid fa-circle-exclamation"
                >
                    <template #meta>
                        <span>{{ counts.open }} needing action</span>
                        <span>{{ counts.all }} total reports</span>
                    </template>
                    <template v-if="!isAdmin" #actions>
                        <button type="button" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800" @click="openProviderReportForm">
                            <i class="fa-solid fa-circle-exclamation text-amber-300" aria-hidden="true"></i>
                            Report a problem
                        </button>
                        <button type="button" class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="loadReports(pagination.current_page)">
                            <i class="fa-solid fa-rotate-right text-xs" aria-hidden="true"></i>
                            Refresh
                        </button>
                    </template>
                </TaskPageHeader>

                <section :class="[isAdmin ? 'admin-panel' : 'provider-panel', 'mt-5 overflow-hidden']">
                    <div v-if="!isAdmin" class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="font-bold text-slate-950">Issue queue</h3>
                            <p class="mt-1 text-sm text-slate-500">Open an item to review its details or update your team response.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
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

                    <div v-else class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/80 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <label class="flex items-center gap-2">
                            <span class="shrink-0 text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Status</span>
                            <select
                                :value="selectedStatus"
                                class="min-w-44 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-slate-500"
                                @change="changeFilter($event.target.value)"
                            >
                                <option v-for="filter in statusFilters" :key="filter.value" :value="filter.value">
                                    {{ filter.label }} ({{ filter.count }})
                                </option>
                            </select>
                        </label>
                        <p class="text-xs font-semibold text-slate-500">
                            {{ pagination.total }} report{{ pagination.total === 1 ? '' : 's' }} in this view
                        </p>
                    </div>

                    <div v-if="isLoading" class="p-6 text-sm text-slate-500">
                        Loading reports...
                    </div>

                    <p v-else-if="errorMessage" class="border-t border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                        {{ errorMessage }}
                    </p>

                    <div v-else-if="reports.length" :class="isAdmin ? 'divide-y divide-slate-200 bg-white' : 'bg-white'">
                        <div v-if="!isAdmin" class="portal-record-head hidden grid-cols-[minmax(0,1fr)_16rem_8rem] items-center gap-3 lg:grid">
                            <span>Report</span>
                            <span>Status</span>
                            <span class="text-center">Action</span>
                        </div>
                        <article
                            v-for="report in reports"
                            :key="report.id"
                            :class="[
                                'portal-record-row grid gap-3 lg:items-center',
                                isAdmin ? 'lg:grid-cols-[minmax(0,1fr)_13rem_6rem]' : 'lg:grid-cols-[minmax(0,1fr)_16rem_8rem]',
                            ]"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-sm text-white ring-1 ring-slate-200">
                                    <i :class="reportIcon(report.category)" aria-hidden="true"></i>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-start gap-2">
                                        <h4 class="line-clamp-2 text-sm font-bold leading-5 text-slate-950">{{ report.subject }}</h4>
                                        <span :class="['inline-flex shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase lg:hidden', statusClass(report.status)]">
                                            {{ statusLabel(report.status) }}
                                        </span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] font-semibold text-slate-500">
                                        <span>{{ reporterName(report) }}</span>
                                        <span>{{ report.category_label }}</span>
                                        <span v-if="report.privacy_request_type_label" class="text-amber-700">{{ report.privacy_request_type_label }}</span>
                                        <span v-if="!isAdmin">{{ reporterType(report) }}</span>
                                        <span v-if="!isAdmin && report.program">{{ report.program.title }}</span>
                                        <span>{{ report.created_at }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="hidden min-w-0 text-left lg:block">
                                <span :class="['inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(report.status)]">{{ statusLabel(report.status) }}</span>
                                <p v-if="isAdmin" class="mt-1.5 truncate text-[11px] font-semibold text-slate-500">
                                    {{ report.requires_both_roles ? 'Shared with provider' : 'Admin handling' }}
                                </p>
                                <template v-else>
                                    <p :class="['mt-1.5 text-xs font-bold', report.overall_status === 'resolved' ? 'text-emerald-700' : 'text-slate-600']">
                                        {{ overallStatusLabel(report.overall_status) }}
                                    </p>
                                    <p class="mt-1 truncate text-[11px] text-slate-500">{{ handlingMessage(report) }}</p>
                                </template>
                            </div>

                            <button type="button" class="inline-flex w-full shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-slate-800" @click="openReport(report)">
                                Open
                            </button>
                        </article>
                    </div>

                    <div v-else class="p-6">
                        <p class="text-sm font-bold text-slate-900">No reports in this view</p>
                        <p class="mt-1 text-sm leading-6 text-slate-500">
                            {{ isAdmin ? 'Choose another status to review completed concerns.' : 'Choose another status or submit a report when your team encounters a problem.' }}
                        </p>
                    </div>

                    <div v-if="pagination.last_page > 1" class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/70 px-4 py-3">
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

            </div>
        </section>
    </main>

    <Teleport to="body">
        <div
            v-if="!isAdmin && showProviderReportForm"
            class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="provider-report-form-title"
            @click.self="closeProviderReportForm"
        >
            <form class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl" @submit.prevent="submitProviderReport">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                            <i class="fa-solid fa-life-ring" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Platform support</p>
                            <h2 id="provider-report-form-title" class="mt-1 text-xl font-bold text-slate-950">Report a problem</h2>
                            <p class="mt-1 text-sm leading-6 text-slate-500">Tell the admin what happened and where it occurred.</p>
                        </div>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-600 transition hover:bg-slate-50" aria-label="Close report form" @click="closeProviderReportForm">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="overflow-y-auto bg-slate-50 p-5 sm:p-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label>
                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Problem type</span>
                            <select v-model="providerReportForm.category" required class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-amber-500">
                                <option v-for="category in providerCategories" :key="category.value" :value="category.value" class="bg-white text-slate-900">{{ category.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Affected program <span class="font-normal normal-case tracking-normal text-slate-400">(optional)</span></span>
                            <select v-model="providerReportForm.scholarshipId" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-amber-500">
                                <option value="" class="bg-white text-slate-900">General platform concern</option>
                                <option v-for="program in providerPrograms" :key="program.id" :value="program.id" class="bg-white text-slate-900">{{ program.title }}</option>
                            </select>
                        </label>
                    </div>

                    <label class="mt-4 block">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Affected page or application <span class="font-normal normal-case tracking-normal text-slate-400">(optional)</span></span>
                        <input v-model="providerReportForm.context" type="text" maxlength="255" placeholder="Example: Applications page or Application #24" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500">
                    </label>

                    <label class="mt-4 block">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Short subject</span>
                        <input v-model="providerReportForm.subject" type="text" minlength="5" maxlength="150" required placeholder="Briefly name the problem" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500">
                    </label>

                    <label class="mt-4 block">
                        <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">What happened?</span>
                        <textarea v-model="providerReportForm.description" rows="5" minlength="10" maxlength="2000" required placeholder="Describe what you were doing, what went wrong, and what you expected to happen." class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500"></textarea>
                    </label>

                    <label class="mt-4 block rounded-md border border-dashed border-slate-300 bg-white p-4">
                        <span class="flex items-center gap-2 text-sm font-bold text-slate-900">
                            <i class="fa-regular fa-image text-amber-700" aria-hidden="true"></i>
                            Screenshot or PDF <span class="font-normal text-slate-400">(optional)</span>
                        </span>
                        <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-3 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:font-bold file:text-white" @change="selectProviderReportFile">
                        <span class="mt-2 block text-xs text-slate-500">JPG, PNG, WebP, or PDF up to 5MB.</span>
                    </label>

                    <p v-if="providerReportError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ providerReportError }}</p>
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-white px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    <button type="button" :disabled="isSubmittingProviderReport" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60" @click="closeProviderReportForm">Cancel</button>
                    <button type="submit" :disabled="isSubmittingProviderReport" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                        {{ isSubmittingProviderReport ? 'Sending...' : 'Send to admin support' }}
                    </button>
                </footer>
            </form>
        </div>
    </Teleport>

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
                                {{ reporterName(selectedReport) }} - {{ reporterType(selectedReport) }}
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
                        <p v-if="selectedReport.privacy_request_type_label" class="mt-3 inline-flex rounded-md bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-900">
                            {{ selectedReport.privacy_request_type_label }}
                        </p>
                        <div v-if="selectedReport.context" class="mt-3 rounded-md bg-slate-50 px-3 py-2.5">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Affected area</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ selectedReport.context }}</p>
                        </div>
                        <p class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-700">{{ selectedReport.description }}</p>
                        <button v-if="selectedReport.attachment" type="button" class="mt-4 inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="openReportAttachment(selectedReport)">
                            <i class="fa-solid fa-paperclip text-amber-700" aria-hidden="true"></i>
                            View attachment
                        </button>
                    </section>

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-md border border-slate-200 bg-white p-3">
                            <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Submitted by</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ reporterName(selectedReport) }}</dd>
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
                        {{ selectedReport.can_update_status
                            ? `Updating this changes only the ${isAdmin ? 'platform' : 'provider'} handling state.`
                            : 'Platform support controls this report status. Your team can track updates here.' }}
                    </p>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row">
                        <button type="button" :disabled="updatingId === selectedReport.id" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60" @click="closeReport">
                            Close
                        </button>
                        <button
                            v-if="selectedReport.can_update_status"
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

    <FilePreviewModal
        :file="previewAttachment"
        title="Support report attachment"
        context="Submitted with this report"
        @close="previewAttachment = null"
    />
</template>
