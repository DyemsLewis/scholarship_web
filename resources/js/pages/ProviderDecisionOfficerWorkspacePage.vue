<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderApplicantPhoto from '../components/ProviderApplicantPhoto.vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ pending: 0, waitlist: 0, recorded: 0 });
const programs = ref([]);
const applications = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const url = new URL(window.location.href);
const pathSection = url.pathname.split('/').filter(Boolean).at(-1);
const pathQueueMap = { pending: 'pending', waitlist: 'waitlist', recorded: 'recorded' };
const queueOptions = ['pending', 'waitlist', 'recorded'];
const requestedQueue = pathQueueMap[pathSection] ?? url.searchParams.get('queue');
const activeQueue = ref(queueOptions.includes(requestedQueue) ? requestedQueue : 'pending');
const selectedProgram = ref(url.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const queueSections = computed(() => [
    { key: 'pending', label: 'Pending decisions', shortLabel: 'Pending', description: 'Record final outcomes for candidates who completed selection.', count: Number(summary.value.pending ?? 0), href: '/provider/workspaces/decisions/pending', icon: 'fa-gavel' },
    { key: 'waitlist', label: 'Waitlist', shortLabel: 'Waitlist', description: 'Review candidate order when an award slot becomes available.', count: Number(summary.value.waitlist ?? 0), href: '/provider/workspaces/decisions/waitlist', icon: 'fa-list-ol' },
    { key: 'recorded', label: 'Decision history', shortLabel: 'History', description: 'View selected and not selected decision records.', count: Number(summary.value.recorded ?? 0), href: '/provider/workspaces/decisions/recorded', icon: 'fa-clock-rotate-left' },
]);
const activeSection = computed(() => queueSections.value.find((section) => section.key === activeQueue.value) ?? queueSections.value[0]);
const leadDecision = computed(() => activeQueue.value === 'pending' ? applications.value[0] ?? null : null);
const isHistory = computed(() => activeQueue.value === 'recorded');

function decisionClass(state) {
    return {
        pending: 'bg-amber-100 text-amber-900',
        waitlist: 'bg-slate-100 text-slate-700',
        selected: 'bg-emerald-100 text-emerald-800',
        not_selected: 'bg-slate-200 text-slate-700',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function decisionIcon(state) {
    return {
        pending: 'fa-solid fa-clock',
        waitlist: 'fa-solid fa-list-ol',
        selected: 'fa-solid fa-circle-check',
        not_selected: 'fa-solid fa-circle-xmark',
    }[state] ?? 'fa-solid fa-gavel';
}

function capacityIcon(state) {
    return state === 'full' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-ticket';
}

function capacityClass(state) {
    return {
        available: 'text-emerald-700',
        full: 'text-rose-700',
        unlimited: 'text-slate-600',
    }[state] ?? 'text-slate-600';
}

function capacityDetail(capacity) {
    if (capacity.state === 'unlimited') return 'No slot limit';
    if (capacity.state === 'full') return 'No award slots remain';
    return `${capacity.remaining} slot${capacity.remaining === 1 ? '' : 's'} remain`;
}

function decisionDetailUrl(path) {
    const detailUrl = new URL(path, window.location.origin);
    detailUrl.searchParams.set('section', 'decision');
    detailUrl.searchParams.set('return_to', `${window.location.pathname}${window.location.search}`);

    return `${detailUrl.pathname}${detailUrl.search}`;
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);
    const usesQueuePath = Object.prototype.hasOwnProperty.call(pathQueueMap, pathSection);

    if (usesQueuePath || activeQueue.value === 'pending') nextUrl.searchParams.delete('queue');
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
        const response = await window.axios.get('/provider/workspaces/decisions/data', {
            params: {
                queue: activeQueue.value,
                search: searchQuery.value.trim() || undefined,
                program_id: selectedProgram.value || undefined,
                page,
            },
        });
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        programs.value = response.data.programs ?? [];
        applications.value = response.data.applications ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load final decisions.';
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading final decisions" message="Preparing the current decision work." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Final decisions are unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="decisions" :title="activeSection.label" :description="activeSection.description" icon="fa-solid fa-gavel" :show-role-guide="false" />

                    <nav class="mt-4 grid grid-cols-3 border border-slate-300 bg-white" aria-label="Final decision pages">
                        <a v-for="section in queueSections" :key="section.key" :href="section.href" :aria-current="section.key === activeQueue ? 'page' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-4 last:border-r-0', section.key === activeQueue ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                            <i :class="['fa-solid', section.icon, section.key === activeQueue ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ section.shortLabel }}</span><span :class="['mt-0.5 block truncate text-[0.68rem]', section.key === activeQueue ? 'text-slate-300' : 'text-slate-500']">{{ section.count }} candidate{{ section.count === 1 ? '' : 's' }}</span></span>
                        </a>
                    </nav>

                    <section v-if="leadDecision" class="mt-3 border border-slate-300 border-l-4 border-l-amber-500 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex items-center gap-4 px-5 py-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.64rem] font-black uppercase tracking-[0.16em] text-amber-700">Decide next</p>
                                <h2 class="mt-0.5 truncate text-base font-bold text-slate-950">{{ leadDecision.applicant.name }}</h2>
                                <p class="mt-0.5 truncate text-sm text-slate-500">{{ leadDecision.program.title }} / {{ capacityDetail(leadDecision.capacity) }}</p>
                            </div>
                            <a :href="decisionDetailUrl(leadDecision.detail_url)" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Record decision<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-3 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                            <div>
                                <h2 class="text-base font-bold text-slate-950">{{ pagination.total }} candidate{{ pagination.total === 1 ? '' : 's' }}</h2>
                                <p class="mt-0.5 text-xs text-slate-500">{{ isHistory ? 'Most recently recorded decisions appear first.' : 'Only records from this decision page are shown.' }}</p>
                            </div>
                            <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-circle-notch mr-1.5 animate-spin" aria-hidden="true"></i>Updating</span>
                        </header>

                        <div class="grid grid-cols-[minmax(18rem,1fr)_minmax(15rem,.55fr)] gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
                            <label class="relative block"><span class="sr-only">Search candidates</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchQuery" type="search" placeholder="Search candidate or program" class="w-full border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            <label><span class="sr-only">Filter by program</span><select v-model="selectedProgram" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"><option value="">All assigned programs</option><option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option></select></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                        <div v-if="applications.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[68rem] table-fixed">
                                <caption class="sr-only">{{ activeSection.label }}</caption>
                                <colgroup><col class="w-[32%]"><col class="w-[25%]"><col class="w-[25%]"><col class="w-[18%]"></colgroup>
                                <thead><tr><th scope="col">Candidate and program</th><th scope="col">Award capacity</th><th scope="col">Decision record</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="application in applications" :key="application.id">
                                        <td><div class="flex min-w-0 items-start gap-3"><ProviderApplicantPhoto :src="application.applicant.profile_photo_url" :name="application.applicant.name" /><div class="min-w-0"><p class="truncate font-bold text-slate-950">{{ application.applicant.name }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ application.program.title }}</p><p v-if="application.applicant.education" class="mt-1 truncate text-xs text-slate-500">{{ application.applicant.education }}</p></div></div></td>
                                        <td><p class="font-semibold text-slate-800">{{ application.capacity.label }}</p><p :class="['mt-1 text-xs font-semibold', capacityClass(application.capacity.state)]"><i :class="[capacityIcon(application.capacity.state), 'mr-1.5']" aria-hidden="true"></i>{{ capacityDetail(application.capacity) }}</p></td>
                                        <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', decisionClass(application.decision.state)]"><i :class="decisionIcon(application.decision.state)" aria-hidden="true"></i>{{ application.decision.label }}</span><p class="mt-1.5 text-xs text-slate-500">{{ application.decision.recorded_at || application.stage_status }}</p><p v-if="application.decision.reason" class="mt-1 truncate text-xs text-slate-500">{{ application.decision.reason }}</p></td>
                                        <td><a :href="decisionDetailUrl(application.detail_url)" class="inline-flex items-center border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">{{ application.decision.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[9px]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <i class="fa-solid fa-inbox text-2xl text-slate-300" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No candidates on this page</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ searchQuery || selectedProgram ? 'Clear the filters to check the full list.' : 'Candidates will appear here when they reach this decision state.' }}</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="candidates" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
