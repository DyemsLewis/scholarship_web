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
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);
const queueHeading = computed(() => ({
    issues: 'Recipient-reported issues',
    record: 'Distributions ready to record',
    upcoming: 'Scheduled distributions',
    history: 'Completed release records',
}[activeQueue.value]));

function stateClass(state) {
    return {
        issues: 'bg-rose-100 text-rose-800',
        record: 'bg-amber-100 text-amber-900',
        upcoming: 'bg-slate-100 text-slate-700',
        history: 'bg-slate-200 text-slate-700',
        schedule: 'bg-amber-100 text-amber-900',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function timingClass(state) {
    return {
        attention: 'text-rose-700',
        overdue: 'text-rose-700',
        today: 'text-amber-700',
        soon: 'text-amber-700',
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading release desk" message="Preparing distributions, proof records, and reported issues." />

                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Benefit distribution is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="releases" title="Benefit distribution" description="Schedule approved support, record proof, and resolve receipt concerns." icon="fa-solid fa-hand-holding-dollar">
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextTask" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950">
                                <i :class="['fa-solid text-sm', nextTask.work_state === 'issues' ? 'fa-triangle-exclamation' : (nextTask.work_state === 'schedule' ? 'fa-calendar-plus' : 'fa-receipt')]"></i>
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
                            <h2 class="text-sm font-bold text-slate-950">Release records are up to date</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Future distributions remain under Upcoming.</p>
                        </div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
                            <div>
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-amber-700">Release desk</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-950">{{ queueHeading }}</h2>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <p class="text-xs font-semibold text-slate-500">{{ activeQueueTab.count }} {{ activeQueueTab.count === 1 ? 'distribution' : 'distributions' }}</p>
                                <a v-if="selectedProgramRecord" :href="selectedProgramRecord.schedule_url" class="bg-slate-950 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                    <i class="fa-solid fa-plus mr-2 text-amber-300"></i>Schedule release
                                </a>
                                <button v-else type="button" disabled class="cursor-not-allowed bg-slate-200 px-3.5 py-2 text-xs font-bold text-slate-500" title="Select a program first">Schedule release</button>
                            </div>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Benefit release work states" @select="selectQueue" />

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3 xl:grid-cols-[minmax(18rem,1fr)_minmax(14rem,.45fr)]">
                            <label class="relative block">
                                <span class="sr-only">Search benefit releases</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                <input v-model="searchQuery" type="search" placeholder="Search release or program" class="w-full rounded border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
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

                        <div v-if="releases.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(18rem,1.15fr)_minmax(18rem,1fr)_minmax(21rem,1.15fr)_10rem] gap-4 bg-slate-50 px-5 py-2.5 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Distribution</span>
                                <span>Current work</span>
                                <span>Coverage and schedule</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="release in releases" :key="release.id" class="grid gap-4 px-5 py-3.5 transition hover:bg-slate-50 xl:grid-cols-[minmax(18rem,1.15fr)_minmax(18rem,1fr)_minmax(21rem,1.15fr)_10rem] xl:items-center">
                                <div class="min-w-0">
                                    <h3 class="truncate text-sm font-bold text-slate-950">{{ release.title }}</h3>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ release.program.title }}</p>
                                    <p class="mt-0.5 truncate text-xs font-semibold text-slate-700">{{ release.amount_label || release.benefit_description }}</p>
                                </div>

                                <div>
                                    <span :class="['inline-flex px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', stateClass(release.work_state)]">{{ release.work_label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ release.detail }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ coverageLabel(release) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ release.counts.proof }} proof file{{ Number(release.counts.proof) === 1 ? '' : 's' }} · {{ release.counts.confirmed }} confirmed</p>
                                    <p :class="['mt-1 text-xs font-semibold', timingClass(release.timing_state)]">{{ timingLabel(release) }}<span class="mx-2 text-slate-300">|</span>{{ release.release_method_label }}<span v-if="release.location"> · {{ release.location }}</span></p>
                                </div>

                                <a :href="release.action_url" class="bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ release.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-box-open"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No releases in this view</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another work state, program, or search.</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="distributions" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
