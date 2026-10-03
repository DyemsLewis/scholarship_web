<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

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
        waitlist: 'bg-sky-100 text-sky-800',
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
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading decision docket...
                </div>

                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-slate-950 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                                    <i class="fa-solid fa-gavel"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-amber-700">Decision officer</p>
                                    <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-950">Final decisions</h1>
                                    <p class="mt-1 text-sm text-slate-600">Record outcomes and manage the waitlist within available award slots.</p>
                                </div>
                            </div>
                            <div class="border-t border-slate-200 pt-3 text-left lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0 lg:text-right">
                                <p class="text-xs font-bold text-slate-900">{{ workspace.organization_name }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ accessLabel }}</p>
                            </div>
                        </div>
                    </header>

                    <section v-if="nextDecision" class="mt-4 overflow-hidden rounded-lg border border-slate-900 bg-white shadow-sm">
                        <div class="grid lg:grid-cols-[8rem_minmax(0,1fr)_minmax(13rem,.55fr)_auto] lg:items-stretch">
                            <div class="flex items-center justify-center bg-slate-950 px-4 py-4 text-white lg:py-5">
                                <div class="text-center">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.18em] text-amber-300">Next decision</p>
                                    <i class="fa-solid fa-scale-balanced mt-2 text-lg"></i>
                                </div>
                            </div>
                            <div class="flex min-w-0 items-center gap-3 border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r">
                                <img v-if="nextDecision.applicant.profile_photo_url" :src="nextDecision.applicant.profile_photo_url" :alt="nextDecision.applicant.name" class="h-11 w-11 shrink-0 rounded-md object-cover">
                                <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-sm font-black text-slate-600">{{ applicantInitials(nextDecision.applicant.name) }}</span>
                                <div class="min-w-0">
                                    <h2 class="truncate text-base font-bold text-slate-950">{{ nextDecision.applicant.name }}</h2>
                                    <p class="mt-1 truncate text-sm text-slate-500">{{ nextDecision.program.title }}</p>
                                </div>
                            </div>
                            <div class="flex items-center border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r">
                                <div>
                                    <p class="text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-400">Award capacity</p>
                                    <p class="mt-1 text-sm font-bold text-slate-900">{{ nextDecision.capacity.label }}</p>
                                    <p :class="['mt-0.5 text-xs font-semibold', capacityClass(nextDecision.capacity.state)]">{{ capacityDetail(nextDecision.capacity) }}</p>
                                </div>
                            </div>
                            <div class="flex items-center px-5 py-4">
                                <a :href="nextDecision.detail_url" class="w-full rounded-md bg-amber-300 px-4 py-2.5 text-center text-sm font-black text-slate-950 transition hover:bg-amber-400 lg:w-auto">
                                    {{ nextDecision.decision.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-emerald-950">No final decision is waiting</h2>
                            <p class="mt-0.5 text-sm text-emerald-800">New candidates appear after all configured selection activities are complete.</p>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200">
                            <div class="flex flex-col gap-4 px-5 py-4 sm:px-6 xl:flex-row xl:items-end xl:justify-between">
                                <div>
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Decision docket</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">{{ queueHeading }}</h2>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-[minmax(15rem,1fr)_minmax(12rem,.7fr)] xl:w-[38rem]">
                                    <label class="relative block">
                                        <span class="sr-only">Search candidates</span>
                                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                        <input v-model="searchQuery" type="search" placeholder="Search candidate or program" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                    </label>
                                    <label>
                                        <span class="sr-only">Filter by program</span>
                                        <select v-model="selectedProgram" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                            <option value="">All assigned programs</option>
                                            <option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option>
                                        </select>
                                    </label>
                                </div>
                            </div>

                            <div class="flex items-center gap-1 overflow-x-auto border-t border-slate-200 bg-slate-50 px-5 py-2 sm:px-6" aria-label="Decision queues">
                                <button
                                    v-for="tab in queueTabs"
                                    :key="tab.key"
                                    type="button"
                                    :class="[
                                        'shrink-0 border-b-2 px-4 py-2 text-xs font-bold transition',
                                        activeQueue === tab.key ? 'border-slate-950 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800',
                                    ]"
                                    @click="selectQueue(tab.key)"
                                >
                                    {{ tab.label }} <span :class="['ml-1 rounded px-1.5 py-0.5', activeQueue === tab.key ? 'bg-amber-200 text-slate-950' : 'bg-slate-200 text-slate-600']">{{ tab.count }}</span>
                                </button>
                                <span v-if="isRefreshing" class="ml-auto shrink-0 text-xs font-semibold text-slate-400"><i class="fa-solid fa-circle-notch mr-1 animate-spin"></i>Updating</span>
                            </div>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="applications.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(15rem,1.2fr)_minmax(13rem,1fr)_minmax(12rem,.75fr)_minmax(10rem,.7fr)_10rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Candidate</span>
                                <span>Program</span>
                                <span>Capacity</span>
                                <span>Decision state</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="application in applications" :key="application.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6 xl:grid-cols-[minmax(15rem,1.2fr)_minmax(13rem,1fr)_minmax(12rem,.75fr)_minmax(10rem,.7fr)_10rem] xl:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="application.applicant.name" class="h-11 w-11 shrink-0 rounded-md object-cover">
                                    <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(application.applicant.name) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</h3>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ application.applicant.education || application.stage_status }}</p>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-slate-800">{{ application.program.title }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ application.stage_status }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-bold text-slate-900">{{ application.capacity.label }}</p>
                                    <p :class="['mt-1 text-xs font-semibold', capacityClass(application.capacity.state)]">{{ capacityDetail(application.capacity) }}</p>
                                </div>

                                <div>
                                    <span :class="['inline-flex rounded px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', decisionClass(application.decision.state)]">{{ application.decision.label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ application.decision.recorded_at }}</p>
                                </div>

                                <a :href="application.detail_url" class="rounded-md bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ application.decision.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-inbox"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No candidates in this docket</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another decision state, program, or search.</p>
                        </div>

                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6">
                            <p class="text-xs font-semibold text-slate-500">{{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</p>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="pagination.current_page <= 1 || isRefreshing" @click="loadWorkspace(pagination.current_page - 1)">Previous</button>
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isRefreshing" @click="loadWorkspace(pagination.current_page + 1)">Next</button>
                            </div>
                        </div>
                    </section>

                    <p class="mt-4 border-l-2 border-amber-400 px-4 py-2 text-sm text-slate-600">
                        Use the published program terms and completed stage records. Add a clear reason whenever an applicant is not selected.
                    </p>
                </template>
            </div>
        </section>
    </main>
</template>
