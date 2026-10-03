<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

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
const queueCopy = computed(() => ({
    review: {
        title: 'Check-ins with records to review',
        description: 'Submission and exception decisions are ready for the monitoring officer.',
    },
    followup: {
        title: 'Recipients needing follow-up',
        description: 'Open interventions and requirements needing correction are kept together.',
    },
    awaiting: {
        title: 'Open check-ins awaiting recipients',
        description: 'Track progress without mixing passive waiting into the review queue.',
    },
    history: {
        title: 'Past check-ins',
        description: 'Reference closed monitoring periods separately from current work.',
    },
}[activeQueue.value]));

function stateClass(state) {
    return {
        review: 'bg-amber-100 text-amber-900',
        followup: 'bg-rose-100 text-rose-800',
        awaiting: 'bg-sky-100 text-sky-800',
        history: 'bg-slate-200 text-slate-700',
        publish: 'bg-emerald-100 text-emerald-800',
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
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading monitoring desk...
                </div>

                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-sky-700 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-sky-300">
                                    <i class="fa-solid fa-heart-pulse"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-sky-700">Monitoring officer</p>
                                    <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-950">Recipient monitoring</h1>
                                    <p class="mt-1 text-sm text-slate-600">Review check-ins, decide requests, and manage recipient follow-ups.</p>
                                </div>
                            </div>
                            <div class="border-t border-slate-200 pt-3 text-left lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0 lg:text-right">
                                <p class="text-xs font-bold text-slate-900">{{ workspace.organization_name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ accessLabel }}</p>
                            </div>
                        </div>
                    </header>

                    <section v-if="nextTask" class="mt-4 overflow-hidden rounded-lg border border-slate-900 bg-white shadow-sm">
                        <div class="grid lg:grid-cols-[8rem_minmax(0,1fr)_minmax(16rem,.7fr)_auto] lg:items-stretch">
                            <div class="flex items-center justify-center bg-slate-950 px-4 py-4 text-white lg:py-5">
                                <div class="text-center">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.18em] text-sky-300">Next task</p>
                                    <i :class="['mt-2 text-lg fa-solid', nextTask.work_state === 'publish' ? 'fa-calendar-plus' : 'fa-clipboard-check']"></i>
                                </div>
                            </div>
                            <div class="min-w-0 border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="truncate text-base font-bold text-slate-950">{{ nextTask.title }}</h2>
                                    <span :class="['rounded px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', stateClass(nextTask.work_state)]">{{ nextTask.work_label }}</span>
                                </div>
                                <p class="mt-1 truncate text-sm text-slate-500">{{ nextTask.program.title }}</p>
                            </div>
                            <div class="flex items-center border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r">
                                <div>
                                    <p class="text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-400">Why it is next</p>
                                    <p class="mt-1 text-sm font-bold text-slate-900">{{ nextTask.detail }}</p>
                                </div>
                            </div>
                            <div class="flex items-center px-5 py-4">
                                <a :href="nextTask.action_url" class="w-full rounded-md bg-sky-700 px-4 py-2.5 text-center text-sm font-black text-white transition hover:bg-sky-800 lg:w-auto">
                                    {{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-emerald-950">No monitoring review is waiting</h2>
                            <p class="mt-0.5 text-sm text-emerald-800">Open check-ins remain visible under Awaiting uploads.</p>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                                <div>
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-sky-700">Monitoring desk</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">{{ queueCopy.title }}</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ queueCopy.description }}</p>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-[minmax(15rem,1fr)_minmax(12rem,.7fr)] xl:w-[38rem]">
                                    <label class="relative block">
                                        <span class="sr-only">Search check-ins</span>
                                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                        <input v-model="searchQuery" type="search" placeholder="Search check-in or program" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                    </label>
                                    <label>
                                        <span class="sr-only">Filter by program</span>
                                        <select v-model="selectedProgram" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-sky-600 focus:ring-3 focus:ring-sky-100">
                                            <option value="">All assigned programs</option>
                                            <option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center gap-1 overflow-x-auto border-t border-slate-200 pt-2" aria-label="Monitoring work states">
                                <button
                                    v-for="tab in queueTabs"
                                    :key="tab.key"
                                    type="button"
                                    :class="[
                                        'shrink-0 border-b-2 px-4 py-2 text-xs font-bold transition',
                                        activeQueue === tab.key ? 'border-sky-700 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800',
                                    ]"
                                    @click="selectQueue(tab.key)"
                                >
                                    {{ tab.label }} <span :class="['ml-1 rounded px-1.5 py-0.5', activeQueue === tab.key ? 'bg-sky-100 text-sky-800' : 'bg-slate-200 text-slate-600']">{{ tab.count }}</span>
                                </button>
                                <span v-if="isRefreshing" class="ml-auto shrink-0 text-xs font-semibold text-slate-400"><i class="fa-solid fa-circle-notch mr-1 animate-spin"></i>Updating</span>
                            </div>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="checkIns.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(15rem,1.1fr)_minmax(13rem,1fr)_minmax(12rem,.8fr)_minmax(10rem,.65fr)_10rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Check-in</span>
                                <span>Current work</span>
                                <span>Coverage</span>
                                <span>Deadline</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="checkIn in checkIns" :key="checkIn.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6 xl:grid-cols-[minmax(15rem,1.1fr)_minmax(13rem,1fr)_minmax(12rem,.8fr)_minmax(10rem,.65fr)_10rem] xl:items-center">
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-bold text-slate-950">{{ checkIn.title }}</h3>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ checkIn.program.title }} · {{ checkIn.period_label }}</p>
                                </div>

                                <div>
                                    <span :class="['inline-flex rounded px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(checkIn.work_state)]">{{ checkIn.work_label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ checkIn.detail }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ coverageLabel(checkIn) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ checkIn.recipient_count }} recipient{{ checkIn.recipient_count === 1 ? '' : 's' }} in scope</p>
                                </div>

                                <div>
                                    <p :class="['text-sm font-bold', timingClass(checkIn.timing_state)]">{{ timingLabel(checkIn) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ checkIn.due_label || 'Deadline not set' }}</p>
                                </div>

                                <a :href="checkIn.action_url" class="rounded-md bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ checkIn.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-sky-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-clipboard-list"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No check-ins in this view</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another work state, program, or search.</p>
                        </div>

                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6">
                            <p class="text-xs font-semibold text-slate-500">{{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</p>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="pagination.current_page <= 1 || isRefreshing" @click="loadWorkspace(pagination.current_page - 1)">Previous</button>
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isRefreshing" @click="loadWorkspace(pagination.current_page + 1)">Next</button>
                            </div>
                        </div>
                    </section>

                    <p class="mt-4 border-l-2 border-sky-600 px-4 py-2 text-sm text-slate-600">
                        This desk covers recipient check-ins and follow-ups only. Benefit releases and support outcomes remain with their assigned officers.
                    </p>
                </template>
            </div>
        </section>
    </main>
</template>
