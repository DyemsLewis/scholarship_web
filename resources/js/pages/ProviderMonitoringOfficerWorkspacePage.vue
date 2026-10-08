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
const summary = ref({ review: 0, followup: 0, awaiting: 0, history: 0 });
const nextTask = ref(null);
const programs = ref([]);
const checkIns = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const url = new URL(window.location.href);
const pathSection = url.pathname.split('/').filter(Boolean).at(-1);
const pathQueueMap = { review: 'review', 'follow-ups': 'followup', awaiting: 'awaiting', history: 'history' };
const queueOptions = ['review', 'followup', 'awaiting', 'history'];
const requestedQueue = pathQueueMap[pathSection] ?? url.searchParams.get('queue');
const activeQueue = ref(queueOptions.includes(requestedQueue) ? requestedQueue : 'review');
const selectedProgram = ref(url.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const queueSections = computed(() => [
    { key: 'review', label: 'Monitoring review', shortLabel: 'Review', description: 'Review submitted items and exception requests.', count: Number(summary.value.review ?? 0), href: '/provider/workspaces/monitoring/review', icon: 'fa-clipboard-check' },
    { key: 'followup', label: 'Recipient follow-ups', shortLabel: 'Follow-ups', description: 'Resolve corrections and open recipient interventions.', count: Number(summary.value.followup ?? 0), href: '/provider/workspaces/monitoring/follow-ups', icon: 'fa-phone' },
    { key: 'awaiting', label: 'Awaiting uploads', shortLabel: 'Awaiting', description: 'Track open check-ins that still need recipient files.', count: Number(summary.value.awaiting ?? 0), href: '/provider/workspaces/monitoring/awaiting', icon: 'fa-cloud-arrow-up' },
    { key: 'history', label: 'Check-in history', shortLabel: 'History', description: 'View closed monitoring periods and review records.', count: Number(summary.value.history ?? 0), href: '/provider/workspaces/monitoring/history', icon: 'fa-clock-rotate-left' },
]);
const activeSection = computed(() => queueSections.value.find((section) => section.key === activeQueue.value) ?? queueSections.value[0]);
const leadTask = computed(() => {
    if (['review', 'followup'].includes(activeQueue.value) && checkIns.value[0]) return checkIns.value[0];
    if (activeQueue.value === 'review' && nextTask.value?.kind === 'program') return nextTask.value;
    return null;
});
const isHistory = computed(() => activeQueue.value === 'history');

function stateClass(state) {
    return {
        review: 'bg-amber-100 text-amber-900',
        followup: 'bg-rose-100 text-rose-800',
        awaiting: 'bg-slate-100 text-slate-700',
        history: 'bg-slate-200 text-slate-700',
        publish: 'bg-amber-100 text-amber-900',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function stateIcon(state) {
    return {
        review: 'fa-solid fa-clipboard-check',
        followup: 'fa-solid fa-phone',
        awaiting: 'fa-solid fa-cloud-arrow-up',
        history: 'fa-solid fa-box-archive',
        publish: 'fa-solid fa-calendar-plus',
    }[state] ?? 'fa-solid fa-list-check';
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
    if (checkIn.timing_state === 'closed') return `Closed / ${checkIn.due_label}`;
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
    const usesQueuePath = Object.prototype.hasOwnProperty.call(pathQueueMap, pathSection);

    if (usesQueuePath || activeQueue.value === 'review') nextUrl.searchParams.delete('queue');
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading recipient monitoring" message="Preparing check-ins and follow-up work." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Recipient monitoring is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="monitoring" :title="activeSection.label" :description="activeSection.description" icon="fa-solid fa-heart-pulse" :show-role-guide="false" />

                    <nav class="mt-4 grid grid-cols-4 border border-slate-300 bg-white" aria-label="Recipient monitoring pages">
                        <a v-for="section in queueSections" :key="section.key" :href="section.href" :aria-current="section.key === activeQueue ? 'page' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-4 last:border-r-0', section.key === activeQueue ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                            <i :class="['fa-solid', section.icon, section.key === activeQueue ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ section.shortLabel }}</span><span :class="['mt-0.5 block truncate text-[0.68rem]', section.key === activeQueue ? 'text-slate-300' : 'text-slate-500']">{{ section.count }} check-in{{ section.count === 1 ? '' : 's' }}</span></span>
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
                                <h2 class="text-base font-bold text-slate-950">{{ pagination.total }} check-in{{ pagination.total === 1 ? '' : 's' }}</h2>
                                <p class="mt-0.5 text-xs text-slate-500">{{ isHistory ? 'Closed monitoring records are kept here.' : 'Only check-ins from this work state are shown.' }}</p>
                            </div>
                            <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-circle-notch mr-1.5 animate-spin" aria-hidden="true"></i>Updating</span>
                        </header>

                        <div class="grid grid-cols-[minmax(18rem,1fr)_minmax(15rem,.55fr)] gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <label class="relative block"><span class="sr-only">Search check-ins</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchQuery" type="search" placeholder="Search check-in or program" class="w-full border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            <label><span class="sr-only">Filter by program</span><select v-model="selectedProgram" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"><option value="">All assigned programs</option><option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option></select></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                        <div v-if="checkIns.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[70rem] table-fixed">
                                <caption class="sr-only">{{ activeSection.label }}</caption>
                                <colgroup><col class="w-[32%]"><col class="w-[25%]"><col class="w-[27%]"><col class="w-[16%]"></colgroup>
                                <thead><tr><th scope="col">Check-in and program</th><th scope="col">Current work</th><th scope="col">Coverage and deadline</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="checkIn in checkIns" :key="checkIn.id">
                                        <td><div class="flex min-w-0 items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i></span><div class="min-w-0"><p class="truncate font-bold text-slate-950">{{ checkIn.title }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ checkIn.program.title }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ checkIn.period_label }}</p></div></div></td>
                                        <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', stateClass(checkIn.work_state)]"><i :class="stateIcon(checkIn.work_state)" aria-hidden="true"></i>{{ checkIn.work_label }}</span><p class="mt-1.5 line-clamp-2 text-xs text-slate-500">{{ checkIn.detail }}</p></td>
                                        <td><p class="font-semibold text-slate-900"><i class="fa-solid fa-list-check mr-1.5 text-xs text-slate-400" aria-hidden="true"></i>{{ coverageLabel(checkIn) }}</p><p :class="['mt-1 text-xs font-semibold', timingClass(checkIn.timing_state)]"><i class="fa-regular fa-calendar mr-1.5" aria-hidden="true"></i>{{ timingLabel(checkIn) }}</p></td>
                                        <td><a :href="checkIn.action_url" class="inline-flex items-center border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">{{ checkIn.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[9px]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <i class="fa-solid fa-clipboard-list text-2xl text-slate-300" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No check-ins on this page</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ searchQuery || selectedProgram ? 'Clear the filters to check the full list.' : 'Check-ins will appear here when they reach this work state.' }}</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="check-ins" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
