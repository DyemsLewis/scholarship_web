<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const queueByPath = {
    '/provider/workspaces/monitoring/review': 'review',
    '/provider/workspaces/monitoring/follow-ups': 'followup',
    '/provider/workspaces/monitoring/awaiting': 'awaiting',
    '/provider/workspaces/monitoring/history': 'history',
};
const queueCopy = {
    review: { title: 'Monitoring review', description: 'Review submitted records and recipient requests.', empty: 'No check-ins need review.' },
    followup: { title: 'Recipient follow-ups', description: 'Resolve corrections and open support actions.', empty: 'No recipient follow-ups are open.' },
    awaiting: { title: 'Awaiting uploads', description: 'Track open check-ins that still need recipient records.', empty: 'No check-ins are waiting for uploads.' },
    history: { title: 'Check-in history', description: 'Reference completed monitoring periods and decisions.', empty: 'No completed check-ins are recorded.' },
};

const path = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/monitoring/review';
const activeQueue = queueByPath[path] ?? 'review';
const pageCopy = queueCopy[activeQueue];
const workspace = ref(null);
const summary = ref({ review: 0, followup: 0, awaiting: 0, history: 0 });
const programs = ref([]);
const checkIns = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
const selectedProgram = ref(new URLSearchParams(window.location.search).get('program_id') ?? '');
const isLoading = ref(true);
const errorMessage = ref('');
let searchTimer = null;
let requestVersion = 0;

const queues = computed(() => [
    { key: 'review', label: 'Needs review', count: summary.value.review, href: '/provider/workspaces/monitoring/review' },
    { key: 'followup', label: 'Follow-ups', count: summary.value.followup, href: '/provider/workspaces/monitoring/follow-ups' },
    { key: 'awaiting', label: 'Awaiting uploads', count: summary.value.awaiting, href: '/provider/workspaces/monitoring/awaiting' },
    { key: 'history', label: 'History', count: summary.value.history, href: '/provider/workspaces/monitoring/history' },
]);
const resultRange = computed(() => pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 check-ins');

function stateClass(state) {
    if (state === 'review') return 'border-amber-200 bg-amber-50 text-amber-800';
    if (state === 'followup') return 'border-rose-200 bg-rose-50 text-rose-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function deadlineLabel(checkIn) {
    if (!checkIn.due_label) return 'No deadline';
    if (checkIn.timing_state === 'closed') return `Closed ${checkIn.due_label}`;
    if (checkIn.timing_state === 'grace_period') return 'Request window open';
    if (checkIn.timing_state === 'extended') return 'Personal extension active';
    if (checkIn.days_until_due < 0) return `${Math.abs(checkIn.days_until_due)} days overdue`;
    if (checkIn.days_until_due === 0) return 'Due today';
    return `Due ${checkIn.due_label}`;
}

function workloadLabel(checkIn) {
    if (activeQueue === 'review') {
        const count = Number(checkIn.counts.provider_actions ?? 0);
        return `${count} recipient${count === 1 ? '' : 's'} need a decision`;
    }
    if (activeQueue === 'followup') {
        const count = Number(checkIn.counts.followup_cases ?? 0) + Number(checkIn.counts.overdue_cases ?? 0);
        return `${count} recipient follow-up${count === 1 ? '' : 's'}`;
    }
    if (activeQueue === 'awaiting') {
        const count = Number(checkIn.counts.recipient_actions ?? 0);
        return count ? `${count} recipient${count === 1 ? '' : 's'} have the next step` : 'Waiting for the check-in to open';
    }
    return `${checkIn.counts.completed_cases ?? 0} recipient record${Number(checkIn.counts.completed_cases ?? 0) === 1 ? '' : 's'} closed`;
}

async function loadCheckIns(page = 1) {
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/monitoring/data', {
            params: {
                queue: activeQueue,
                search: search.value.trim() || undefined,
                program_id: selectedProgram.value || undefined,
                page,
            },
        });
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
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadCheckIns(1), 300);
});
watch(selectedProgram, () => loadCheckIns(1));
onMounted(() => loadCheckIns());
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="monitoring-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Recipient monitoring</p>
                            <h1 id="monitoring-directory-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                        </div>
                        <div v-if="workspace" class="border-l border-slate-200 pl-5 text-right">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Monitoring scope</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ workspace.organization_name }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ workspace.program_access_mode === 'selected' ? 'Assigned programs' : 'All programs' }}</p>
                        </div>
                    </div>
                </header>

                <nav class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Monitoring queues">
                    <a v-for="queue in queues" :key="queue.key" :href="queue.href" :aria-current="activeQueue === queue.key ? 'page' : undefined" :class="['flex min-h-16 items-center justify-between border-r border-slate-200 px-5 last:border-r-0', activeQueue === queue.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50']">
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

                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                    <div v-if="isLoading" class="px-6 py-16 text-center text-sm font-semibold text-slate-500">Loading monitoring check-ins...</div>
                    <div v-else-if="!checkIns.length" class="px-6 py-16 text-center">
                        <i class="fa-solid fa-clipboard-check text-3xl text-slate-300" aria-hidden="true"></i>
                        <p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p>
                        <p class="mt-1 text-xs text-slate-500">Records appear here when their monitoring state changes.</p>
                    </div>

                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[70rem] table-fixed border-collapse text-left">
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">
                                    <th class="w-[32%] px-5 py-3">Check-in</th>
                                    <th class="w-[23%] px-5 py-3">Current work</th>
                                    <th class="w-[20%] px-5 py-3">Coverage</th>
                                    <th class="w-[13%] px-5 py-3">Deadline</th>
                                    <th class="w-[12%] px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="checkIn in checkIns" :key="checkIn.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <p class="truncate text-sm font-bold text-slate-950">{{ checkIn.title }}</p>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ checkIn.program.title }}</p>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ checkIn.period_label }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', stateClass(checkIn.work_state)]">{{ checkIn.work_label }}</span>
                                        <p class="mt-2 line-clamp-1 text-xs text-slate-500">{{ checkIn.detail }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-bold text-slate-900">{{ workloadLabel(checkIn) }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ checkIn.recipient_count }} recipients · {{ checkIn.counts.requirements }} requirements</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p :class="['text-sm font-bold', checkIn.timing_state === 'overdue' ? 'text-rose-700' : ['due_soon', 'grace_period'].includes(checkIn.timing_state) ? 'text-amber-700' : 'text-slate-800']">{{ deadlineLabel(checkIn) }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <a :href="checkIn.action_url" class="inline-flex h-9 items-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800">Open <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading" @click="loadCheckIns(pagination.current_page - 1)">Previous</button>
                            <button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading" @click="loadCheckIns(pagination.current_page + 1)">Next</button>
                        </div>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
