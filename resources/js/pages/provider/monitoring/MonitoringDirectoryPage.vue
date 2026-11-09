<script setup>
import WorkspaceTextSummary from '../../../components/workspace-dashboard/WorkspaceTextSummary.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import ProviderProgramQueueContext from '../../../components/ProviderProgramQueueContext.vue';
import { createStaffQueueState, providerProgramQueueLink } from '../../../support/staffQueueState.js';

const queueByPath = {
    '/provider/workspaces/monitoring/review': 'review',
    '/provider/workspaces/monitoring/follow-ups': 'followup',
    '/provider/workspaces/monitoring/awaiting': 'awaiting',
    '/provider/workspaces/monitoring/history': 'history',
};
const queueCopy = {
    review: { title: 'Monitoring review', description: 'Review submitted records and recipient requests.', empty: 'No check-ins need review.' },
    followup: { title: 'Recipient follow-ups', description: 'Resolve corrections and open support actions.', empty: 'No recipient follow-ups are open.' },
    awaiting: { title: 'Scheduled or waiting', description: 'Track upcoming appointments and check-ins waiting on recipients.', empty: 'No check-ins are scheduled or waiting.' },
    history: { title: 'Check-in history', description: 'Reference completed monitoring periods and decisions.', empty: 'No completed check-ins are recorded.' },
};

const path = (window.portalPathname?.() ?? window.location.pathname).replace(/\/$/, '') || '/provider/workspaces/monitoring/review';
const activeQueue = queueByPath[path] ?? 'review';
const pageCopy = queueCopy[activeQueue];
const workspace = ref(null);
const summary = ref({ review: 0, followup: 0, awaiting: 0, history: 0 });
const programs = ref([]);
const checkIns = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const queueState = createStaffQueueState(window, ['search','program_id']);
const search = ref(queueState.get('search') ?? '');
const selectedProgram = ref(queueState.get('program_id') ?? '');
const isLoading = ref(true);
const errorMessage = ref('');
let searchTimer = null;
let requestVersion = 0;
let requestedPage = 1;

const queues = computed(() => [
    { key: 'review', label: 'Needs review', count: summary.value.review, href: '/provider/workspaces/monitoring/review' },
    { key: 'followup', label: 'Follow-ups', count: summary.value.followup, href: '/provider/workspaces/monitoring/follow-ups' },
    { key: 'awaiting', label: 'Scheduled or waiting', count: summary.value.awaiting, href: '/provider/workspaces/monitoring/awaiting' },
    { key: 'history', label: 'History', count: summary.value.history, href: '/provider/workspaces/monitoring/history' },
]);
const resultRange = computed(() => isLoading.value ? 'Updating results...' : errorMessage.value ? 'Results unavailable' : pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 check-ins');

function stateClass(state) {
    if (state === 'review') return 'border-amber-200 bg-amber-50 text-amber-800';
    if (state === 'followup') return 'border-rose-200 bg-rose-50 text-rose-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function deadlineLabel(checkIn) {
    if (checkIn.handoff) return checkIn.handoff.scheduled_label;
    if (!checkIn.due_label) return 'No deadline';
    if (checkIn.timing_state === 'closed') return checkIn.due_label;
    if (checkIn.timing_state === 'grace_period') return 'Request window open';
    if (checkIn.timing_state === 'extended') return 'Personal extension active';
    if (checkIn.days_until_due < 0) return `${Math.abs(checkIn.days_until_due)} days overdue`;
    if (checkIn.days_until_due === 0) return 'Due today';
    return `Due ${checkIn.due_label}`;
}

async function loadCheckIns(page = 1) {
    window.clearTimeout(searchTimer);
    requestedPage = page;
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const params = {
            queue: activeQueue,
            search: search.value.trim() || undefined,
            program_id: selectedProgram.value || undefined,
            page,
        };
        const response = await queueState.load((query) => window.axios.get('/provider/workspaces/monitoring/data', { params: query }), params, () => version === requestVersion);
        if (version !== requestVersion) return;
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        programs.value = response.data.programs ?? [];
        checkIns.value = response.data.check_ins ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The monitoring queue could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

watch(search, () => {
    requestVersion++;
    isLoading.value = true;
    errorMessage.value = '';
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadCheckIns(1), 300);
}, { flush: 'sync' });
watch(selectedProgram, () => loadCheckIns(1), { flush: 'sync' });
onMounted(() => loadCheckIns(queueState.page));
onBeforeUnmount(() => {
    requestVersion++;
    window.clearTimeout(searchTimer);
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="monitoring-directory-title">
            <div class="provider-monitoring-workspace mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div class="flex min-w-0 items-start gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Recipient monitoring</p>
                                <h1 id="monitoring-directory-title" class="monitoring-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                                <WorkspaceTextSummary class="mt-2 text-sm leading-6 text-slate-600" :text="pageCopy.description" label="details" />
                            </div>
                        </div>
                        <div v-if="workspace" class="border-l border-slate-200 pl-5 text-right">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-600">Program access</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ workspace.organization_name }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ workspace.program_access_mode === 'selected' ? 'Assigned programs' : 'All programs' }}</p>
                        </div>
                    </div>
                    <ProviderProgramQueueContext :program-id="selectedProgram" :programs="programs" class="mt-4" />
                </header>

                <nav class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Monitoring queues">
                    <a v-for="queue in queues" :key="queue.key" :href="providerProgramQueueLink(queue.href, selectedProgram)" :aria-current="activeQueue === queue.key ? 'page' : undefined" :class="['flex min-h-16 items-center justify-between border-r border-slate-200 px-5 last:border-r-0', activeQueue === queue.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50']">
                        <span class="text-sm font-bold">{{ queue.label }}</span>
                        <span :class="['min-w-7 px-2 py-1 text-center text-xs font-bold', activeQueue === queue.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-700']">{{ queue.count }}</span>
                    </a>
                </nav>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Monitoring check-ins">
                    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
                        <label class="relative min-w-0 flex-1">
                            <span class="sr-only">Search check-ins</span>
                            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input v-model="search" type="search" class="h-11 w-full border border-slate-300 bg-white pl-10 pr-4 text-sm outline-none focus:border-slate-950" placeholder="Search check-in or program">
                        </label>
                        <label class="w-72">
                            <span class="sr-only">Filter by program</span>
                            <select v-model="selectedProgram" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950">
                                <option value="">All programs</option>
                                <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }}</option>
                            </select>
                        </label>
                    </div>


                    <StaffTableLoadError v-if="errorMessage" :message="errorMessage" @retry="loadCheckIns(requestedPage)" />
                    <div v-else-if="isLoading" class="px-6 py-16 text-center">
                        <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span>
                        <p class="mt-3 text-sm font-semibold text-slate-500">Loading monitoring check-ins...</p>
                    </div>
                    <div v-else-if="!checkIns.length && !errorMessage" class="px-6 py-16 text-center">
                        <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clipboard-check text-sm" aria-hidden="true"></i></span>
                        <p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p>
                        <p class="mt-1 text-xs text-slate-500">Records appear here when their monitoring state changes.</p>
                    </div>

                    <div v-else-if="checkIns.length" class="staff-table-viewport">
                        <table class="w-full min-w-[56rem] table-fixed border-collapse text-left">
                            <colgroup><col><col class="w-48"><col class="w-36"><col class="w-44"><col class="w-32"></colgroup>
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-[0.16em] text-slate-600">
                                    <th class="px-5 py-3">Check-in</th>
                                    <th class="px-5 py-3">Current work</th>
                                    <th class="px-5 py-3">Workload</th>
                                    <th class="px-5 py-3">Schedule</th>
                                    <th class="px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="checkIn in checkIns" :key="checkIn.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <p class="monitoring-text text-sm font-bold text-slate-950">{{ checkIn.title }}</p>
                                        <p class="mt-1 monitoring-text text-xs text-slate-500">{{ checkIn.program.title }}<template v-if="checkIn.period_label"> &middot; {{ checkIn.period_label }}</template></p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span :class="['inline-flex border px-2.5 py-1 text-xs font-bold uppercase tracking-wide', stateClass(checkIn.work_state)]">{{ checkIn.work_label }}</span>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-bold text-slate-900">{{ checkIn.workload_label }}</td>
                                    <td class="px-5 py-4">
                                        <p :class="['text-sm font-bold', checkIn.timing_state === 'overdue' ? 'text-rose-700' : ['due_soon', 'grace_period'].includes(checkIn.timing_state) ? 'text-amber-700' : 'text-slate-800']">{{ deadlineLabel(checkIn) }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <a :href="queueState.link(checkIn.action_url)" class="staff-row-action staff-row-action--primary h-9 px-3 text-xs" :aria-label="`Open ${checkIn.title}`">Open <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading || Boolean(errorMessage)" @click="loadCheckIns(pagination.current_page - 1)"><i class="fa-solid fa-arrow-left text-[9px]" aria-hidden="true"></i>Previous</button>
                            <button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading || Boolean(errorMessage)" @click="loadCheckIns(pagination.current_page + 1)">Next<i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></button>
                        </div>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
