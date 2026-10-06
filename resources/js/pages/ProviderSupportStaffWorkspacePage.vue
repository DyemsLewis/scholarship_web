<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderQueueTabs from '../components/ProviderQueueTabs.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';

const allowedQueues = ['needs_action', 'waiting', 'submitted', 'resolved'];
const pageUrl = new URL(window.location.href);
const requestedQueue = pageUrl.searchParams.get('queue');
const activeQueue = ref(allowedQueues.includes(requestedQueue) ? requestedQueue : 'needs_action');
const selectedProgram = ref(pageUrl.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const actionError = ref('');
const workspace = ref(null);
const summary = ref({ needs_action: 0, waiting: 0, submitted: 0, resolved: 0, total: 0 });
const nextTask = ref(null);
const categories = ref([]);
const programs = ref([]);
const reports = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const selectedReport = ref(null);
const updatingId = ref(null);
const showPlatformForm = ref(false);
const isSubmitting = ref(false);
const platformFormError = ref('');
const platformFile = ref(null);
const platformForm = ref(emptyPlatformForm());
let searchTimer = null;
const { confirmation, requestConfirmation, confirmConfirmation, cancelConfirmation } = useConfirmationDialog();

const queueTabs = computed(() => [
    { key: 'needs_action', label: 'Needs response', count: Number(summary.value.needs_action ?? 0) },
    { key: 'waiting', label: 'Waiting for platform', count: Number(summary.value.waiting ?? 0) },
    { key: 'submitted', label: 'Platform reports', count: Number(summary.value.submitted ?? 0) },
    { key: 'resolved', label: 'Resolved', count: Number(summary.value.resolved ?? 0) },
]);
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);
const queueHeading = computed(() => ({
    needs_action: 'Applicant concerns requiring a response',
    waiting: 'Responses awaiting platform review',
    submitted: 'Reports sent to platform support',
    resolved: 'Completed support cases',
}[activeQueue.value]));

function emptyPlatformForm() {
    return {
        category: 'technical',
        scholarshipId: '',
        context: '',
        subject: '',
        description: '',
    };
}

function stateClass(state) {
    return {
        needs_action: 'bg-amber-100 text-amber-900',
        waiting: 'bg-slate-100 text-slate-700',
        submitted: 'bg-slate-100 text-slate-700',
        resolved: 'bg-slate-200 text-slate-700',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function reportIcon(category) {
    return {
        program: 'fa-graduation-cap',
        account: 'fa-user-gear',
        technical: 'fa-screwdriver-wrench',
        service: 'fa-handshake-angle',
        data: 'fa-database',
        other: 'fa-circle-question',
    }[category] ?? 'fa-life-ring';
}

function withWorkspaceState(report) {
    if (report.submitted_by_provider) {
        return {
            ...report,
            work_state: report.admin_status === 'resolved' ? 'resolved' : 'submitted',
            work_label: report.admin_status === 'resolved' ? 'Resolved' : 'With platform support',
            work_detail: report.admin_status === 'resolved'
                ? 'Platform support completed this report.'
                : 'Your organization submitted this report and is waiting for an update.',
        };
    }

    if (report.provider_status === 'open') {
        return {
            ...report,
            work_state: 'needs_action',
            work_label: 'Response needed',
            work_detail: 'Review the concern and record the provider response.',
        };
    }

    if (report.overall_status === 'resolved') {
        return {
            ...report,
            work_state: 'resolved',
            work_label: 'Resolved',
            work_detail: 'All required support handling is complete.',
        };
    }

    return {
        ...report,
        work_state: 'waiting',
        work_label: 'Waiting for platform',
        work_detail: 'The provider response is complete; platform support is still reviewing.',
    };
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);

    if (activeQueue.value === 'needs_action') nextUrl.searchParams.delete('queue');
    else nextUrl.searchParams.set('queue', activeQueue.value);

    if (selectedProgram.value) nextUrl.searchParams.set('program_id', selectedProgram.value);
    else nextUrl.searchParams.delete('program_id');

    window.history.replaceState({}, '', nextUrl);
}

async function loadWorkspace(page = 1, initial = false) {
    if (initial) isLoading.value = true;
    else isRefreshing.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/support/data', {
            params: {
                queue: activeQueue.value,
                search: searchQuery.value.trim() || undefined,
                program_id: selectedProgram.value || undefined,
                page,
            },
        });
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        nextTask.value = response.data.next_task;
        categories.value = response.data.categories ?? [];
        programs.value = response.data.programs ?? [];
        reports.value = response.data.reports ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the support desk.';
    } finally {
        isLoading.value = false;
        isRefreshing.value = false;
    }
}

async function selectQueue(queue) {
    activeQueue.value = queue;
    selectedReport.value = null;
    await loadWorkspace(1);
}

async function openNextTask() {
    if (!nextTask.value) return;

    if (activeQueue.value !== 'needs_action' || selectedProgram.value || searchQuery.value) {
        activeQueue.value = 'needs_action';
        selectedProgram.value = '';
        searchQuery.value = '';
        await loadWorkspace(1);
    }

    selectedReport.value = reports.value.find((report) => report.id === nextTask.value?.report_id) ?? null;
}

function openReport(report) {
    actionError.value = '';
    selectedReport.value = report;
}

function closeReport() {
    if (!updatingId.value) selectedReport.value = null;
}

async function toggleProviderResponse(report) {
    if (!report.can_update_status) return;

    const completing = report.status !== 'resolved';
    const confirmed = await requestConfirmation({
        title: completing ? 'Complete the provider response?' : 'Reopen the provider response?',
        message: completing
            ? 'This records that your organization has finished reviewing the applicant concern.'
            : 'Use this when the concern needs another response from your organization.',
        confirmLabel: completing ? 'Complete response' : 'Reopen response',
        tone: completing ? 'default' : 'danger',
    });

    if (!confirmed) return;

    updatingId.value = report.id;
    actionError.value = '';

    try {
        const response = await window.axios.patch(`/provider/reports/${report.id}/status`, {
            status: completing ? 'resolved' : 'open',
        });
        selectedReport.value = withWorkspaceState(response.data.report);
        await loadWorkspace(pagination.value.current_page);
    } catch (error) {
        actionError.value = error.response?.data?.message ?? 'Unable to update this case.';
    } finally {
        updatingId.value = null;
    }
}

function openPlatformForm() {
    platformForm.value = emptyPlatformForm();
    platformFile.value = null;
    platformFormError.value = '';
    showPlatformForm.value = true;
}

function closePlatformForm() {
    if (!isSubmitting.value) showPlatformForm.value = false;
}

function selectPlatformFile(event) {
    platformFile.value = event.target.files?.[0] ?? null;
}

async function submitPlatformReport() {
    isSubmitting.value = true;
    platformFormError.value = '';
    const payload = new FormData();
    payload.append('category', platformForm.value.category);
    payload.append('subject', platformForm.value.subject);
    payload.append('description', platformForm.value.description);

    if (platformForm.value.scholarshipId) payload.append('scholarship_id', platformForm.value.scholarshipId);
    if (platformForm.value.context.trim()) payload.append('context', platformForm.value.context.trim());
    if (platformFile.value) payload.append('attachment_file', platformFile.value);

    try {
        await window.axios.post('/provider/reports', payload);
        showPlatformForm.value = false;
        activeQueue.value = 'submitted';
        selectedProgram.value = '';
        searchQuery.value = '';
        await loadWorkspace(1);
    } catch (error) {
        platformFormError.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to send this report.';
    } finally {
        isSubmitting.value = false;
    }
}

watch(selectedProgram, () => loadWorkspace(1));
watch(searchQuery, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadWorkspace(1), 300);
});

onMounted(() => loadWorkspace(1, true));
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">Loading support desk...</div>
                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                <template v-else>
                    <ProviderPageHeader role-key="support" title="Support desk" description="Resolve applicant concerns and platform reports with clear ownership and follow-through." icon="fa-solid fa-headset">
                        <template #actions>
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                <button type="button" class="bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="openPlatformForm"><i class="fa-solid fa-circle-exclamation mr-2 text-amber-300"></i>Contact platform</button>
                            </div>
                        </template>
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ workspace.program_access_mode === 'selected' ? 'Assigned programs only' : 'All organization programs' }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextTask" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950"><i class="fa-solid fa-headset text-sm"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Next case</p>
                                <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextTask.title }}</h2>
                                <p class="mt-0.5 truncate text-xs text-slate-500">{{ nextTask.applicant }} · {{ nextTask.program }}</p>
                            </div>
                            <p class="shrink-0 border-l border-slate-200 pl-4 text-xs font-semibold text-slate-600">Submitted {{ nextTask.submitted_at }}</p>
                            <button type="button" class="shrink-0 bg-slate-950 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openNextTask">Review case<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i></button>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div><h2 class="text-sm font-bold text-slate-950">No applicant response is waiting</h2><p class="mt-0.5 text-xs text-slate-500">New program concerns will appear here.</p></div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
                            <div><p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-slate-500">Case queue</p><h2 class="mt-0.5 text-base font-bold text-slate-950">{{ queueHeading }}</h2></div>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">{{ activeQueueTab.count }} {{ activeQueueTab.count === 1 ? 'case' : 'cases' }}</p>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Support case states" @select="selectQueue" />

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3 xl:grid-cols-[minmax(18rem,1fr)_minmax(14rem,.45fr)]">
                            <label class="relative block"><span class="sr-only">Search cases</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i><input v-model="searchQuery" type="search" placeholder="Search concern or applicant" class="w-full rounded border border-slate-300 py-2.5 pl-10 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            <label><span class="sr-only">Filter by program</span><select v-model="selectedProgram" class="w-full rounded border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"><option value="">All assigned programs</option><option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }}</option></select></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                        <div v-if="reports.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(19rem,1.2fr)_minmax(16rem,.85fr)_minmax(18rem,1fr)_4rem] gap-4 bg-slate-50 px-5 py-2.5 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid"><span>Concern</span><span>Reporter and program</span><span>Case status</span><span class="text-center">Open</span></div>
                            <article v-for="report in reports" :key="report.id" class="grid gap-4 px-5 py-3.5 hover:bg-slate-50 xl:grid-cols-[minmax(19rem,1.2fr)_minmax(16rem,.85fr)_minmax(18rem,1fr)_4rem] xl:items-center">
                                <div class="flex min-w-0 items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-950 text-amber-300"><i :class="['fa-solid', reportIcon(report.category)]"></i></span><div class="min-w-0"><h3 class="truncate text-sm font-bold text-slate-950">{{ report.subject }}</h3><p class="mt-0.5 text-xs font-semibold text-slate-600">{{ report.category_label }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ report.context || report.created_at }}</p></div></div>
                                <div><p class="text-sm font-bold text-slate-900">{{ report.applicant?.name || 'Provider team' }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ report.program?.title || (report.submitted_by_provider ? 'General platform report' : 'General concern') }}</p></div>
                                <div><span :class="['inline-flex px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(report.work_state)]">{{ report.work_label }}</span><p class="mt-1.5 text-xs leading-5 text-slate-500">{{ report.work_detail }}</p></div>
                                <div class="text-center"><button type="button" class="grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-900" title="Open case" aria-label="Open case" @click="openReport(report)"><i class="fa-solid fa-arrow-right"></i></button></div>
                            </article>
                        </div>
                        <div v-else class="px-6 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-inbox"></i></span><h3 class="mt-3 text-sm font-bold text-slate-900">No cases in this queue</h3><p class="mt-1 text-sm text-slate-500">Try another state, program, or search.</p></div>
                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6"><p class="text-xs font-semibold text-slate-500">{{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</p><div class="flex gap-2"><button type="button" class="rounded border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1 || isRefreshing" @click="loadWorkspace(pagination.current_page - 1)">Previous</button><button type="button" class="rounded border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isRefreshing" @click="loadWorkspace(pagination.current_page + 1)">Next</button></div></div>
                    </section>
                </template>
            </div>
        </section>

        <ConfirmationDialog :state="confirmation" @confirm="confirmConfirmation" @cancel="cancelConfirmation" />

        <Teleport to="body">
            <div v-if="selectedReport" class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" @click.self="closeReport">
                <section class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded bg-white shadow-2xl">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6"><div class="flex min-w-0 items-start gap-3"><span class="grid h-11 w-11 shrink-0 place-items-center bg-slate-950 text-amber-300"><i :class="['fa-solid', reportIcon(selectedReport.category)]"></i></span><div class="min-w-0"><p class="text-xs font-black uppercase tracking-[0.16em] text-amber-700">{{ selectedReport.category_label }}</p><h2 class="mt-1 text-xl font-bold text-slate-950">{{ selectedReport.subject }}</h2><p class="mt-1 text-sm text-slate-500">{{ selectedReport.program?.title || 'General support concern' }}</p></div></div><button type="button" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-300 text-slate-600" aria-label="Close case" @click="closeReport"><i class="fa-solid fa-xmark"></i></button></header>
                    <div class="overflow-y-auto bg-slate-50 p-5 sm:p-6">
                        <div class="border border-slate-200 bg-white"><div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-[0.65rem] font-black uppercase tracking-[0.14em] text-slate-500">Handling state</p><span :class="['mt-2 inline-flex px-2.5 py-1.5 text-xs font-black uppercase', stateClass(selectedReport.work_state)]">{{ selectedReport.work_label }}</span></div><p class="text-sm text-slate-500">Submitted {{ selectedReport.created_at }}</p></div><div class="p-4 sm:p-5"><p class="text-[0.65rem] font-black uppercase tracking-[0.14em] text-slate-500">Concern</p><p class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-700">{{ selectedReport.description }}</p><div v-if="selectedReport.context" class="mt-4 border-l-2 border-amber-500 pl-3"><p class="text-xs font-bold text-slate-500">Affected area</p><p class="mt-1 text-sm font-semibold text-slate-800">{{ selectedReport.context }}</p></div><a v-if="selectedReport.attachment" :href="selectedReport.attachment.view_url" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-2 border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700"><i class="fa-solid fa-paperclip text-amber-700"></i>View attachment</a></div><div class="border-t border-slate-200 p-4 sm:p-5"><p class="text-[0.65rem] font-black uppercase tracking-[0.14em] text-slate-500">Submitted by</p><p class="mt-2 text-sm font-bold text-slate-950">{{ selectedReport.applicant?.name || 'Provider team' }}</p><p class="mt-1 text-xs text-slate-500">{{ selectedReport.applicant?.email }}</p></div></div>
                        <p v-if="actionError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ actionError }}</p>
                    </div>
                    <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"><p class="text-xs text-slate-500">{{ selectedReport.work_detail }}</p><div class="flex gap-2"><button type="button" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeReport">Close</button><button v-if="selectedReport.can_update_status" type="button" :disabled="updatingId === selectedReport.id" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" @click="toggleProviderResponse(selectedReport)">{{ updatingId === selectedReport.id ? 'Saving...' : (selectedReport.status === 'resolved' ? 'Reopen response' : 'Complete response') }}</button></div></footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showPlatformForm" class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" @click.self="closePlatformForm">
                <form class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded bg-white shadow-2xl" @submit.prevent="submitPlatformReport">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6"><div><p class="text-xs font-black uppercase tracking-[0.16em] text-amber-700">Platform support</p><h2 class="mt-1 text-xl font-bold text-slate-950">Report a platform problem</h2><p class="mt-1 text-sm text-slate-500">Send one clear issue for administrator follow-up.</p></div><button type="button" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600" aria-label="Close form" @click="closePlatformForm"><i class="fa-solid fa-xmark"></i></button></header>
                    <div class="overflow-y-auto bg-slate-50 p-5 sm:p-6"><div class="grid gap-4 sm:grid-cols-2"><label><span class="text-xs font-bold text-slate-600">Problem type</span><select v-model="platformForm.category" required class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"><option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option></select></label><label><span class="text-xs font-bold text-slate-600">Related program <span class="font-normal text-slate-400">(optional)</span></span><select v-model="platformForm.scholarshipId" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">General platform issue</option><option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }}</option></select></label></div><label class="mt-4 block"><span class="text-xs font-bold text-slate-600">Affected page <span class="font-normal text-slate-400">(optional)</span></span><input v-model="platformForm.context" type="text" maxlength="255" placeholder="Example: Applications page" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label><label class="mt-4 block"><span class="text-xs font-bold text-slate-600">Subject</span><input v-model="platformForm.subject" type="text" minlength="5" maxlength="150" required placeholder="Briefly name the problem" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label><label class="mt-4 block"><span class="text-xs font-bold text-slate-600">What happened?</span><textarea v-model="platformForm.description" rows="5" minlength="10" maxlength="2000" required placeholder="Describe the problem and what you expected." class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label><label class="mt-4 block rounded-md border border-dashed border-slate-300 bg-white p-4"><span class="text-sm font-bold text-slate-900">Screenshot or PDF <span class="font-normal text-slate-400">(optional)</span></span><input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-3 block w-full text-sm text-slate-600 file:mr-3 file:rounded file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:font-bold file:text-white" @change="selectPlatformFile"></label><p v-if="platformFormError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ platformFormError }}</p></div>
                    <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" :disabled="isSubmitting" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="closePlatformForm">Cancel</button><button type="submit" :disabled="isSubmitting" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isSubmitting ? 'Sending...' : 'Send report' }}</button></footer>
                </form>
            </div>
        </Teleport>
    </main>
</template>
