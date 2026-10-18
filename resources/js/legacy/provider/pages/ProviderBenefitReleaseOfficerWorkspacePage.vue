<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
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
const url = new URL(window.location.href);
const pathSection = url.pathname.split('/').filter(Boolean).at(-1);
const pathQueueMap = { issues: 'issues', record: 'record', upcoming: 'upcoming', history: 'history' };
const queueOptions = ['issues', 'record', 'upcoming', 'history'];
const requestedQueue = pathQueueMap[pathSection] ?? url.searchParams.get('queue');
const activeQueue = ref(queueOptions.includes(requestedQueue) ? requestedQueue : 'issues');
const selectedProgram = ref(url.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const queueSections = computed(() => [
    { key: 'issues', label: 'Reported release issues', shortLabel: 'Issues', description: 'Resolve recipient reports about missing or incorrect benefits.', count: Number(summary.value.issues ?? 0), href: '/provider/workspaces/releases/issues', icon: 'fa-triangle-exclamation' },
    { key: 'record', label: 'Record distribution', shortLabel: 'Record', description: 'Record delivery results and proof for due distributions.', count: Number(summary.value.record ?? 0), href: '/provider/workspaces/releases/record', icon: 'fa-receipt' },
    { key: 'upcoming', label: 'Upcoming releases', shortLabel: 'Upcoming', description: 'Review scheduled distributions before their release date.', count: Number(summary.value.upcoming ?? 0), href: '/provider/workspaces/releases/upcoming', icon: 'fa-calendar-days' },
    { key: 'history', label: 'Release history', shortLabel: 'History', description: 'View completed benefit distribution records.', count: Number(summary.value.history ?? 0), href: '/provider/workspaces/releases/history', icon: 'fa-clock-rotate-left' },
]);
const activeSection = computed(() => queueSections.value.find((section) => section.key === activeQueue.value) ?? queueSections.value[0]);
const selectedProgramRecord = computed(() => programs.value.find((program) => String(program.id) === String(selectedProgram.value)) ?? null);
const leadTask = computed(() => {
    if (['issues', 'record'].includes(activeQueue.value) && releases.value[0]) return releases.value[0];
    if (activeQueue.value === 'issues' && nextTask.value?.kind === 'program') return nextTask.value;
    return null;
});
const isHistory = computed(() => activeQueue.value === 'history');

function stateClass(state) {
    return {
        issues: 'bg-rose-100 text-rose-800',
        record: 'bg-amber-100 text-amber-900',
        upcoming: 'bg-slate-100 text-slate-700',
        history: 'bg-slate-200 text-slate-700',
        schedule: 'bg-amber-100 text-amber-900',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function stateIcon(state) {
    return {
        issues: 'fa-solid fa-triangle-exclamation',
        record: 'fa-solid fa-receipt',
        upcoming: 'fa-solid fa-calendar-days',
        history: 'fa-solid fa-box-archive',
        schedule: 'fa-solid fa-calendar-plus',
    }[state] ?? 'fa-solid fa-hand-holding-dollar';
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
    const usesQueuePath = Object.prototype.hasOwnProperty.call(pathQueueMap, pathSection);

    if (usesQueuePath || activeQueue.value === 'issues') nextUrl.searchParams.delete('queue');
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading benefit distribution" message="Preparing releases and reported issues." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Benefit distribution is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="releases" :title="activeSection.label" :description="activeSection.description" icon="fa-solid fa-hand-holding-dollar" :show-role-guide="false" />

                    <nav class="mt-4 grid grid-cols-4 border border-slate-300 bg-white" aria-label="Benefit distribution pages">
                        <a v-for="section in queueSections" :key="section.key" :href="section.href" :aria-current="section.key === activeQueue ? 'page' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-4 last:border-r-0', section.key === activeQueue ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                            <i :class="['fa-solid', section.icon, section.key === activeQueue ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ section.shortLabel }}</span><span :class="['mt-0.5 block truncate text-[0.68rem]', section.key === activeQueue ? 'text-slate-300' : 'text-slate-500']">{{ section.count }} release{{ section.count === 1 ? '' : 's' }}</span></span>
                        </a>
                    </nav>

                    <section v-if="leadTask" class="mt-3 border border-slate-300 border-l-4 border-l-amber-500 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex items-center gap-4 px-5 py-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-700"><i :class="stateIcon(leadTask.work_state)" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.64rem] font-black uppercase tracking-[0.16em] text-amber-700">Work next</p>
                                <h2 class="mt-0.5 truncate text-base font-bold text-slate-950">{{ leadTask.title }}</h2>
                                <p class="mt-0.5 truncate text-sm text-slate-500">{{ leadTask.program.title }} / {{ leadTask.detail }}</p>
                            </div>
                            <a :href="leadTask.action_url" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">{{ leadTask.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-3 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                            <div>
                                <h2 class="text-base font-bold text-slate-950">{{ pagination.total }} distribution{{ pagination.total === 1 ? '' : 's' }}</h2>
                                <p class="mt-0.5 text-xs text-slate-500">{{ isHistory ? 'Completed distribution records are kept here.' : 'Only releases from this work state are shown.' }}</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-circle-notch mr-1.5 animate-spin" aria-hidden="true"></i>Updating</span>
                                <a v-if="selectedProgramRecord" :href="selectedProgramRecord.schedule_url" class="bg-slate-950 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-plus mr-2 text-amber-300" aria-hidden="true"></i>Schedule release</a>
                                <button v-else type="button" disabled class="cursor-not-allowed bg-slate-200 px-3.5 py-2 text-xs font-bold text-slate-500" title="Select a program first">Schedule release</button>
                            </div>
                        </header>

                        <div class="grid grid-cols-[minmax(18rem,1fr)_minmax(15rem,.55fr)] gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <label class="relative block"><span class="sr-only">Search benefit releases</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchQuery" type="search" placeholder="Search release or program" class="w-full border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            <label><span class="sr-only">Filter by program</span><select v-model="selectedProgram" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"><option value="">All assigned programs</option><option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option></select></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                        <div v-if="releases.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[72rem] table-fixed">
                                <caption class="sr-only">{{ activeSection.label }}</caption>
                                <colgroup><col class="w-[31%]"><col class="w-[27%]"><col class="w-[26%]"><col class="w-[16%]"></colgroup>
                                <thead><tr><th scope="col">Distribution and program</th><th scope="col">Current work</th><th scope="col">Delivery record</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="release in releases" :key="release.id">
                                        <td><div class="flex min-w-0 items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i></span><div class="min-w-0"><p class="truncate font-bold text-slate-950">{{ release.title }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ release.program.title }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ release.amount_label || release.benefit_description }}</p></div></div></td>
                                        <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', stateClass(release.work_state)]"><i :class="stateIcon(release.work_state)" aria-hidden="true"></i>{{ release.work_label }}</span><p class="mt-1.5 line-clamp-2 text-xs text-slate-500">{{ release.detail }}</p><p :class="['mt-1 text-xs font-semibold', timingClass(release.timing_state)]"><i class="fa-regular fa-calendar mr-1.5" aria-hidden="true"></i>{{ timingLabel(release) }}</p></td>
                                        <td><p class="font-semibold text-slate-900"><i class="fa-solid fa-users mr-1.5 text-xs text-slate-400" aria-hidden="true"></i>{{ coverageLabel(release) }}</p><p class="mt-1 text-xs text-slate-500">{{ release.counts.proof }} proof / {{ release.counts.confirmed }} confirmed</p><p class="mt-1 text-xs text-slate-500">{{ release.release_method_label }}<span v-if="release.location"> / {{ release.location }}</span></p></td>
                                        <td><a :href="release.action_url" class="inline-flex items-center border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">{{ release.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[9px]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <i class="fa-solid fa-box-open text-2xl text-slate-300" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No releases on this page</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ searchQuery || selectedProgram ? 'Clear the filters to check the full list.' : 'Releases will appear here when they reach this work state.' }}</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="distributions" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
