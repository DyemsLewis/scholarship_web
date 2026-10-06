<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderQueueTabs from '../components/ProviderQueueTabs.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ review: 0, followup: 0, awaiting: 0, history: 0 });
const nextTask = ref(null);
const programs = ref([]);
const checkIns = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const allowedQueues = ['review', 'followup', 'awaiting', 'history'];
const pageUrl = new URL(window.location.href);
const requestedQueue = pageUrl.searchParams.get('queue');
const activeQueue = ref(allowedQueues.includes(requestedQueue) ? requestedQueue : 'review');
const selectedProgram = ref(pageUrl.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected'
    ? 'Assigned programs only'
    : 'All organization programs');
const queueTabs = computed(() => [
    { key: 'review', label: 'Review queue', count: Number(summary.value.review ?? 0) },
    { key: 'followup', label: 'Follow-ups', count: Number(summary.value.followup ?? 0) },
    { key: 'awaiting', label: 'Awaiting uploads', count: Number(summary.value.awaiting ?? 0) },
    { key: 'history', label: 'History', count: Number(summary.value.history ?? 0) },
]);
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);
const queueHeading = computed(() => ({
    review: 'Check-ins ready for review',
    followup: 'Recipients needing follow-up',
    awaiting: 'Open check-ins awaiting uploads',
    history: 'Past check-ins',
}[activeQueue.value]));

function stateClass(state) {
    return {
        review: 'bg-amber-100 text-amber-900',
        followup: 'bg-rose-100 text-rose-800',
        awaiting: 'bg-slate-100 text-slate-700',
        history: 'bg-slate-200 text-slate-700',
        publish: 'bg-amber-100 text-amber-900',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function timingClass(state) {
    return {
        overdue: 'text-rose-700',
        due_soon: 'text-amber-700',
        scheduled: 'text-slate-600',
        closed: 'text-slate-500',
    }[state] ?? 'text-slate-500';
}

function timingLabel(checkIn) {
    if (!checkIn.due_label) return 'No deadline';
    if (checkIn.timing_state === 'closed') return `Closed · ${checkIn.due_label}`;
    if (checkIn.days_until_due < 0) return `${Math.abs(checkIn.days_until_due)} day${Math.abs(checkIn.days_until_due) === 1 ? '' : 's'} overdue`;
    if (checkIn.days_until_due === 0) return 'Due today';
    return `Due ${checkIn.due_label}`;
}

function coverageLabel(checkIn) {
    const expected = Number(checkIn.counts.expected_items ?? 0);
    const received = Number(checkIn.counts.received_items ?? 0);

    if (expected > 0) return `${received} of ${expected} items received`;
    return `${checkIn.recipient_count} recipient${checkIn.recipient_count === 1 ? '' : 's'}`;
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);

    if (activeQueue.value === 'review') nextUrl.searchParams.delete('queue');
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
        const response = await window.axios.get('/provider/workspaces/monitoring/data', {
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
        programs.value = response.data.programs ?? [];
        checkIns.value = response.data.check_ins ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load recipient monitoring.';
    } finally {
        isLoading.value = false;
        isRefreshing.value = false;
    }
}

function selectQueue(queue) {
    activeQueue.value = queue;
    loadWorkspace(1);
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading monitoring desk" message="Preparing check-ins, follow-ups, and recipient deadlines." />

                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Recipient monitoring is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="monitoring" title="Recipient monitoring" description="Review check-ins, decide requests, and prioritize overdue recipient follow-ups." icon="fa-solid fa-heart-pulse">
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextTask" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950">
                                <i :class="['fa-solid text-sm', nextTask.work_state === 'publish' ? 'fa-calendar-plus' : 'fa-clipboard-check']"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Next task</p>
                                <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextTask.title }}</h2>
                                <p class="mt-0.5 truncate text-xs text-slate-500">{{ nextTask.program.title }}</p>
                            </div>
                            <div class="shrink-0 border-l border-slate-200 pl-4">
                                <span :class="['inline-flex px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', stateClass(nextTask.work_state)]">{{ nextTask.work_label }}</span>
                                <p class="mt-1 max-w-sm text-xs font-semibold text-slate-600">{{ nextTask.detail }}</p>
                            </div>
                            <a :href="nextTask.action_url" class="shrink-0 bg-slate-950 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                {{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                            </a>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-slate-950">No monitoring review is waiting</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Open check-ins remain under Awaiting uploads.</p>
                        </div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
                            <div>
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-amber-700">Monitoring desk</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-950">{{ queueHeading }}</h2>
                            </div>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">{{ activeQueueTab.count }} {{ activeQueueTab.count === 1 ? 'check-in' : 'check-ins' }}</p>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Monitoring work states" @select="selectQueue" />

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3 xl:grid-cols-[minmax(18rem,1fr)_minmax(14rem,.45fr)]">
                            <label class="relative block">
                                <span class="sr-only">Search check-ins</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                <input v-model="searchQuery" type="search" placeholder="Search check-in or program" class="w-full rounded border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                            </label>
                            <label>
                                <span class="sr-only">Filter by program</span>
                                <select v-model="selectedProgram" class="w-full rounded border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    <option value="">All assigned programs</option>
                                    <option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option>
                                </select>
                            </label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="checkIns.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(18rem,1.15fr)_minmax(19rem,1.1fr)_minmax(18rem,1fr)_10rem] gap-4 bg-slate-50 px-5 py-2.5 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Check-in</span>
                                <span>Current work</span>
                                <span>Coverage and deadline</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="checkIn in checkIns" :key="checkIn.id" class="grid gap-4 px-5 py-3.5 transition hover:bg-slate-50 xl:grid-cols-[minmax(18rem,1.15fr)_minmax(19rem,1.1fr)_minmax(18rem,1fr)_10rem] xl:items-center">
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-bold text-slate-950">{{ checkIn.title }}</h3>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ checkIn.program.title }} · {{ checkIn.period_label }}</p>
                                </div>

                                <div>
                                    <span :class="['inline-flex px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(checkIn.work_state)]">{{ checkIn.work_label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ checkIn.detail }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ coverageLabel(checkIn) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ checkIn.recipient_count }} recipient{{ checkIn.recipient_count === 1 ? '' : 's' }}<span class="mx-2 text-slate-300">|</span><span :class="timingClass(checkIn.timing_state)">{{ timingLabel(checkIn) }}</span></p>
                                </div>

                                <a :href="checkIn.action_url" class="bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ checkIn.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-clipboard-list"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No check-ins in this view</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another work state, program, or search.</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="check-ins" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
