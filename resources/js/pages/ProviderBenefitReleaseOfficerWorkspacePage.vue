<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ issues: 0, record: 0, upcoming: 0, history: 0 });
const nextTask = ref(null);
const programs = ref([]);
const releases = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const allowedQueues = ['issues', 'record', 'upcoming', 'history'];
const pageUrl = new URL(window.location.href);
const requestedQueue = pageUrl.searchParams.get('queue');
const activeQueue = ref(allowedQueues.includes(requestedQueue) ? requestedQueue : 'issues');
const selectedProgram = ref(pageUrl.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected'
    ? 'Assigned programs only'
    : 'All organization programs');
const selectedProgramRecord = computed(() => programs.value.find((program) => String(program.id) === String(selectedProgram.value)) ?? null);
const queueTabs = computed(() => [
    { key: 'issues', label: 'Reported issues', count: Number(summary.value.issues ?? 0) },
    { key: 'record', label: 'Record distribution', count: Number(summary.value.record ?? 0) },
    { key: 'upcoming', label: 'Upcoming', count: Number(summary.value.upcoming ?? 0) },
    { key: 'history', label: 'History', count: Number(summary.value.history ?? 0) },
]);
const queueCopy = computed(() => ({
    issues: {
        title: 'Recipient-reported release issues',
        description: 'Resolve incomplete, missing, or disputed benefits before routine distribution work.',
    },
    record: {
        title: 'Distributions ready to record',
        description: 'Add the result and acknowledgement proof for releases that are already due.',
    },
    upcoming: {
        title: 'Scheduled distributions',
        description: 'Prepare future releases without mixing them into work that needs action today.',
    },
    history: {
        title: 'Completed release records',
        description: 'Review distribution results, proof coverage, and recipient confirmations.',
    },
}[activeQueue.value]));

function stateClass(state) {
    return {
        issues: 'bg-rose-100 text-rose-800',
        record: 'bg-amber-100 text-amber-900',
        upcoming: 'bg-sky-100 text-sky-800',
        history: 'bg-slate-200 text-slate-700',
        schedule: 'bg-emerald-100 text-emerald-800',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function timingClass(state) {
    return {
        attention: 'text-rose-700',
        overdue: 'text-rose-700',
        today: 'text-amber-700',
        soon: 'text-sky-700',
        scheduled: 'text-slate-700',
        completed: 'text-slate-500',
    }[state] ?? 'text-slate-500';
}

function timingLabel(release) {
    if (release.timing_state === 'attention') return 'Issue requires action';
    if (release.timing_state === 'completed') return 'Distribution completed';
    if (release.days_until_release < 0) return `${Math.abs(release.days_until_release)} day${Math.abs(release.days_until_release) === 1 ? '' : 's'} past schedule`;
    if (release.days_until_release === 0) return 'Scheduled today';
    return release.release_label ? `Scheduled ${release.release_label}` : 'Schedule not set';
}

function coverageLabel(release) {
    const counts = release.counts ?? {};

    if (release.work_state === 'issues') return `${counts.open_issues ?? 0} open issue${Number(counts.open_issues) === 1 ? '' : 's'}`;
    if (release.work_state === 'record') return `${counts.pending ?? 0} result${Number(counts.pending) === 1 ? '' : 's'} pending`;
    if (release.work_state === 'history') return `${counts.released ?? 0} of ${counts.recipients ?? 0} released`;
    return `${counts.recipients ?? 0} recipient${Number(counts.recipients) === 1 ? '' : 's'}`;
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);

    if (activeQueue.value === 'issues') nextUrl.searchParams.delete('queue');
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
        const response = await window.axios.get('/provider/workspaces/releases/data', {
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
        releases.value = response.data.releases ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load benefit distributions.';
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
                    Loading release desk...
                </div>

                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-emerald-700 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-emerald-300">
                                    <i class="fa-solid fa-hand-holding-dollar"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-emerald-700">Benefit release officer</p>
                                    <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-950">Benefit distribution</h1>
                                    <p class="mt-1 text-sm text-slate-600">Schedule support, record proof, and resolve receipt concerns.</p>
                                </div>
                            </div>
                            <div class="border-t border-slate-200 pt-3 text-left lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0 lg:text-right">
                                <p class="text-xs font-bold text-slate-900">{{ workspace.organization_name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ accessLabel }}</p>
                            </div>
                        </div>
                    </header>

                    <section v-if="nextTask" class="mt-4 overflow-hidden rounded-lg border border-slate-900 bg-white shadow-sm">
                        <div class="grid lg:grid-cols-[8rem_minmax(0,1fr)_minmax(16rem,.75fr)_auto] lg:items-stretch">
                            <div class="flex items-center justify-center bg-slate-950 px-4 py-4 text-white lg:py-5">
                                <div class="text-center">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.18em] text-emerald-300">Next task</p>
                                    <i :class="['mt-2 text-lg fa-solid', nextTask.work_state === 'issues' ? 'fa-triangle-exclamation' : (nextTask.work_state === 'schedule' ? 'fa-calendar-plus' : 'fa-receipt')]"></i>
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
                                <a :href="nextTask.action_url" class="w-full rounded-md bg-emerald-700 px-4 py-2.5 text-center text-sm font-black text-white transition hover:bg-emerald-800 lg:w-auto">
                                    {{ nextTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-emerald-950">Release records are up to date</h2>
                            <p class="mt-0.5 text-sm text-emerald-800">Future schedules remain available under Upcoming.</p>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                                <div>
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-emerald-700">Release desk</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">{{ queueCopy.title }}</h2>
                                    <p class="mt-1 text-sm text-slate-500">{{ queueCopy.description }}</p>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-[minmax(14rem,1fr)_minmax(12rem,.8fr)_auto] xl:w-[48rem]">
                                    <label class="relative block">
                                        <span class="sr-only">Search benefit releases</span>
                                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                        <input v-model="searchQuery" type="search" placeholder="Search release or program" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100">
                                    </label>
                                    <label>
                                        <span class="sr-only">Filter by program</span>
                                        <select v-model="selectedProgram" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100">
                                            <option value="">All assigned programs</option>
                                            <option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option>
                                        </select>
                                    </label>
                                    <a v-if="selectedProgramRecord" :href="selectedProgramRecord.schedule_url" class="rounded-md bg-slate-950 px-4 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                        <i class="fa-solid fa-plus mr-2 text-emerald-300"></i>Schedule
                                    </a>
                                    <button v-else type="button" disabled class="cursor-not-allowed rounded-md bg-slate-200 px-4 py-2.5 text-xs font-bold text-slate-500" title="Select a program first">Schedule</button>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center gap-1 overflow-x-auto border-t border-slate-200 pt-2" aria-label="Benefit release work states">
                                <button
                                    v-for="tab in queueTabs"
                                    :key="tab.key"
                                    type="button"
                                    :class="[
                                        'shrink-0 border-b-2 px-4 py-2 text-xs font-bold transition',
                                        activeQueue === tab.key ? 'border-emerald-700 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800',
                                    ]"
                                    @click="selectQueue(tab.key)"
                                >
                                    {{ tab.label }} <span :class="['ml-1 rounded px-1.5 py-0.5', activeQueue === tab.key ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600']">{{ tab.count }}</span>
                                </button>
                                <span v-if="isRefreshing" class="ml-auto shrink-0 text-xs font-semibold text-slate-400"><i class="fa-solid fa-circle-notch mr-1 animate-spin"></i>Updating</span>
                            </div>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="releases.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(15rem,1.15fr)_minmax(13rem,1fr)_minmax(12rem,.8fr)_minmax(12rem,.8fr)_10rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Distribution</span>
                                <span>Current work</span>
                                <span>Coverage</span>
                                <span>Schedule</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="release in releases" :key="release.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6 xl:grid-cols-[minmax(15rem,1.15fr)_minmax(13rem,1fr)_minmax(12rem,.8fr)_minmax(12rem,.8fr)_10rem] xl:items-center">
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-bold text-slate-950">{{ release.title }}</h3>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ release.program.title }}</p>
                                    <p class="mt-1 truncate text-xs font-semibold text-slate-700">{{ release.amount_label || release.benefit_description }}</p>
                                </div>

                                <div>
                                    <span :class="['inline-flex rounded px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(release.work_state)]">{{ release.work_label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ release.detail }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ coverageLabel(release) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ release.counts.proof }} proof file{{ Number(release.counts.proof) === 1 ? '' : 's' }} · {{ release.counts.confirmed }} confirmed</p>
                                </div>

                                <div>
                                    <p :class="['text-sm font-bold', timingClass(release.timing_state)]">{{ timingLabel(release) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ release.release_method_label }}<span v-if="release.location"> · {{ release.location }}</span></p>
                                </div>

                                <a :href="release.action_url" class="rounded-md bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ release.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-emerald-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-box-open"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No releases in this view</h3>
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

                    <p class="mt-4 border-l-2 border-emerald-700 px-4 py-2 text-sm text-slate-600">
                        This desk covers benefit scheduling, release proof, and receipt issues only. Check-in reviews and support outcomes remain in their assigned workspaces.
                    </p>
                </template>
            </div>
        </section>
    </main>
</template>
