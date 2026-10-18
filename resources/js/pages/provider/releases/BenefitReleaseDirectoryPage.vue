<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const queueByPath = {
    '/provider/workspaces/releases/issues': 'issues',
    '/provider/workspaces/releases/record': 'record',
    '/provider/workspaces/releases/upcoming': 'upcoming',
    '/provider/workspaces/releases/history': 'history',
};
const queueCopy = {
    issues: { title: 'Reported release issues', description: 'Resolve recipient reports about missing or incorrect benefits.', empty: 'No release issues need a response.' },
    record: { title: 'Record distribution', description: 'Record results and proof for distributions that are due.', empty: 'No distributions are ready to record.' },
    upcoming: { title: 'Upcoming releases', description: 'Review scheduled distributions before their release date.', empty: 'No upcoming benefit releases are scheduled.' },
    history: { title: 'Release history', description: 'Reference completed benefit distributions and recipient receipts.', empty: 'No completed benefit releases are recorded.' },
};

const path = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/releases/issues';
const activeQueue = queueByPath[path] ?? 'issues';
const pageCopy = queueCopy[activeQueue];
const workspace = ref(null);
const summary = ref({ issues: 0, record: 0, upcoming: 0, history: 0 });
const programs = ref([]);
const releases = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
const selectedProgram = ref(new URLSearchParams(window.location.search).get('program_id') ?? '');
const isLoading = ref(true);
const errorMessage = ref('');
let searchTimer = null;
let requestVersion = 0;

const queues = computed(() => [
    { key: 'issues', label: 'Reported issues', count: summary.value.issues, href: '/provider/workspaces/releases/issues' },
    { key: 'record', label: 'Ready to record', count: summary.value.record, href: '/provider/workspaces/releases/record' },
    { key: 'upcoming', label: 'Upcoming', count: summary.value.upcoming, href: '/provider/workspaces/releases/upcoming' },
    { key: 'history', label: 'History', count: summary.value.history, href: '/provider/workspaces/releases/history' },
]);
const resultRange = computed(() => pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 releases');

function stateClass(state) {
    if (state === 'issues') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (state === 'record') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function timingLabel(release) {
    if (!release.release_label) return 'Date not set';
    if (release.timing_state === 'completed') return release.release_label;
    if (release.days_until_release < 0) return `${Math.abs(release.days_until_release)} days overdue`;
    if (release.days_until_release === 0) return 'Due today';
    return release.release_label;
}

function progressLabel(release) {
    if (activeQueue === 'issues') return `${release.counts.open_issues} issue${release.counts.open_issues === 1 ? '' : 's'} open`;
    if (activeQueue === 'record') return `${release.counts.pending} of ${release.counts.recipients} to record`;
    return `${release.counts.released} of ${release.counts.recipients} released`;
}

async function loadReleases(page = 1) {
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/releases/data', {
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
        releases.value = response.data.releases ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The benefit release queue could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadReleases(1), 300);
});
watch(selectedProgram, () => loadReleases(1));
onMounted(() => loadReleases());
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="release-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Benefit distribution</p><h1 id="release-directory-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1><p class="mt-2 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p></div>
                        <div class="flex items-center gap-4"><div v-if="workspace" class="border-r border-slate-200 pr-5 text-right"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Release scope</p><p class="mt-1 text-sm font-semibold text-slate-800">{{ workspace.organization_name }}</p><p class="mt-1 text-xs text-slate-500">{{ workspace.program_access_mode === 'selected' ? 'Assigned programs' : 'All programs' }}</p></div><a href="/provider/workspaces/releases/schedule" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-plus text-amber-300" aria-hidden="true"></i>Schedule release</a></div>
                    </div>
                </header>

                <nav class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Benefit release queues">
                    <a v-for="queue in queues" :key="queue.key" :href="queue.href" :aria-current="activeQueue === queue.key ? 'page' : undefined" :class="['flex min-h-16 items-center justify-between border-r border-slate-200 px-5 last:border-r-0', activeQueue === queue.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50']"><span class="text-sm font-bold">{{ queue.label }}</span><span :class="['min-w-7 px-2 py-1 text-center text-xs font-bold', activeQueue === queue.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-700']">{{ queue.count }}</span></a>
                </nav>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Benefit releases">
                    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
                        <label class="relative min-w-0 flex-1"><span class="sr-only">Search releases</span><i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" class="h-11 w-full border border-slate-300 bg-white pl-10 pr-4 text-sm outline-none focus:border-slate-950" placeholder="Search release or program"></label>
                        <label class="w-72"><span class="sr-only">Filter by program</span><select v-model="selectedProgram" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option value="">All programs</option><option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }}</option></select></label>
                    </div>

                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                    <div v-if="isLoading" class="px-6 py-16 text-center text-sm font-semibold text-slate-500">Loading benefit releases...</div>
                    <div v-else-if="!releases.length" class="px-6 py-16 text-center"><i class="fa-solid fa-hand-holding-dollar text-3xl text-slate-300" aria-hidden="true"></i><p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p><p class="mt-1 text-xs text-slate-500">Scheduled distributions move here as their status changes.</p></div>

                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[72rem] table-fixed border-collapse text-left">
                            <thead class="bg-slate-50"><tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500"><th class="w-[31%] px-5 py-3">Distribution</th><th class="w-[20%] px-5 py-3">Current work</th><th class="w-[18%] px-5 py-3">Recipients</th><th class="w-[19%] px-5 py-3">Schedule</th><th class="w-[12%] px-5 py-3 text-right">Action</th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="release in releases" :key="release.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><p class="truncate text-sm font-bold text-slate-950">{{ release.title }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ release.program.title }}</p><p class="mt-1 truncate text-xs font-semibold text-slate-500">{{ release.amount_label || release.benefit_description }}</p></td>
                                    <td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', stateClass(release.work_state)]">{{ release.work_label }}</span><p class="mt-2 line-clamp-1 text-xs text-slate-500">{{ release.detail }}</p></td>
                                    <td class="px-5 py-4"><p class="text-sm font-bold text-slate-900">{{ progressLabel(release) }}</p><p class="mt-1 text-xs text-slate-500">{{ release.counts.proof }} proof file{{ release.counts.proof === 1 ? '' : 's' }}</p></td>
                                    <td class="px-5 py-4"><p :class="['text-sm font-bold', release.timing_state === 'overdue' || release.timing_state === 'attention' ? 'text-rose-700' : release.timing_state === 'soon' || release.timing_state === 'today' ? 'text-amber-700' : 'text-slate-800']">{{ timingLabel(release) }}</p><p class="mt-1 text-xs text-slate-500">{{ release.release_method_label }}</p></td>
                                    <td class="px-5 py-4 text-right"><a :href="release.action_url" class="inline-flex h-9 items-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800">Open <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3"><p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p><div class="flex gap-2"><button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading" @click="loadReleases(pagination.current_page - 1)">Previous</button><button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading" @click="loadReleases(pagination.current_page + 1)">Next</button></div></footer>
                </section>
            </div>
        </section>
    </main>
</template>
