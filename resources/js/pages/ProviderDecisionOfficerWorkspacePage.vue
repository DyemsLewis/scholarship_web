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
const summary = ref({ pending: 0, waitlist: 0, recorded: 0 });
const nextDecision = ref(null);
const programs = ref([]);
const applications = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const allowedQueues = ['pending', 'waitlist', 'recorded'];
const pageUrl = new URL(window.location.href);
const requestedQueue = pageUrl.searchParams.get('queue');
const activeQueue = ref(allowedQueues.includes(requestedQueue) ? requestedQueue : 'pending');
const selectedProgram = ref(pageUrl.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected'
    ? 'Assigned programs only'
    : 'All organization programs');
const queueTabs = computed(() => [
    { key: 'pending', label: 'Decision required', count: Number(summary.value.pending ?? 0) },
    { key: 'waitlist', label: 'Waitlist', count: Number(summary.value.waitlist ?? 0) },
    { key: 'recorded', label: 'Recorded', count: Number(summary.value.recorded ?? 0) },
]);
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);
const queueHeading = computed(() => ({
    pending: 'Candidates ready for an outcome',
    waitlist: 'Candidates held for an available slot',
    recorded: 'Completed decision records',
}[activeQueue.value]));

function applicantInitials(name) {
    return String(name ?? 'Applicant')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function decisionClass(state) {
    return {
        pending: 'bg-amber-100 text-amber-900',
        waitlist: 'bg-slate-100 text-slate-700',
        selected: 'bg-emerald-100 text-emerald-800',
        not_selected: 'bg-slate-200 text-slate-700',
    }[state] ?? 'bg-slate-100 text-slate-700';
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

function syncUrl() {
    const nextUrl = new URL(window.location.href);

    if (activeQueue.value === 'pending') nextUrl.searchParams.delete('queue');
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
        nextDecision.value = response.data.next_decision;
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading decision docket" message="Preparing candidates, waitlist positions, and award capacity." />

                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Final decisions are unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="decisions" title="Final decisions" description="Record auditable outcomes and manage the waitlist within available award slots." icon="fa-solid fa-gavel">
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextDecision" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950"><i class="fa-solid fa-scale-balanced text-sm"></i></span>
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <img v-if="nextDecision.applicant.profile_photo_url" :src="nextDecision.applicant.profile_photo_url" :alt="nextDecision.applicant.name" class="h-9 w-9 shrink-0 object-cover">
                                <span v-else class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(nextDecision.applicant.name) }}</span>
                                <div class="min-w-0">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Next decision</p>
                                    <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextDecision.applicant.name }}</h2>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ nextDecision.program.title }}</p>
                                </div>
                            </div>
                            <div class="shrink-0 border-l border-slate-200 pl-4">
                                <p class="text-xs font-bold text-slate-900">{{ nextDecision.capacity.label }}</p>
                                <p :class="['mt-0.5 text-xs font-semibold', capacityClass(nextDecision.capacity.state)]">{{ capacityDetail(nextDecision.capacity) }}</p>
                            </div>
                            <div class="shrink-0">
                                <a :href="nextDecision.detail_url" class="inline-flex items-center bg-slate-950 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ nextDecision.decision.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-slate-950">No final decision is waiting</h2>
                            <p class="mt-0.5 text-xs text-slate-500">New candidates appear after all configured selection activities are complete.</p>
                        </div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
                            <div>
                                <p class="text-[0.65rem] font-black uppercase tracking-[0.16em] text-amber-700">Decision docket</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-950">{{ queueHeading }}</h2>
                            </div>
                            <p class="shrink-0 text-xs font-semibold text-slate-500">{{ activeQueueTab.count }} {{ activeQueueTab.count === 1 ? 'candidate' : 'candidates' }}</p>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Decision queues" @select="selectQueue" />

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3 xl:grid-cols-[minmax(18rem,1fr)_minmax(14rem,.45fr)]">
                            <label class="relative block">
                                <span class="sr-only">Search candidates</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                <input v-model="searchQuery" type="search" placeholder="Search candidate or program" class="w-full rounded border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
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

                        <div v-if="applications.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(16rem,1.1fr)_minmax(21rem,1.25fr)_minmax(13rem,.75fr)_10rem] gap-4 bg-slate-50 px-5 py-2.5 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Candidate</span>
                                <span>Program and capacity</span>
                                <span>Decision status</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="application in applications" :key="application.id" class="grid gap-4 px-5 py-3.5 transition hover:bg-slate-50 xl:grid-cols-[minmax(16rem,1.1fr)_minmax(21rem,1.25fr)_minmax(13rem,.75fr)_10rem] xl:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="application.applicant.name" class="h-10 w-10 shrink-0 object-cover">
                                    <span v-else class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(application.applicant.name) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</h3>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ application.applicant.education || application.stage_status }}</p>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-800">{{ application.program.title }}</p>
                                    <div class="mt-1 flex items-center gap-2 text-xs">
                                        <span class="font-semibold text-slate-600">{{ application.capacity.label }}</span>
                                        <span class="text-slate-300">|</span>
                                        <span :class="['font-semibold', capacityClass(application.capacity.state)]">{{ capacityDetail(application.capacity) }}</span>
                                    </div>
                                </div>

                                <div>
                                    <span :class="['inline-flex px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', decisionClass(application.decision.state)]">{{ application.decision.label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ application.decision.recorded_at || application.stage_status }}</p>
                                </div>

                                <a :href="application.detail_url" class="bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ application.decision.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-inbox"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No candidates in this docket</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another decision state, program, or search.</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="candidates" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
