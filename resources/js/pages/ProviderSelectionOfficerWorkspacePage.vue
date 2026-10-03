<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ setup: 0, results: 0, active: 0 });
const stages = ref({ formal_application: 0, exam: 0, interview: 0 });
const nextActivity = ref(null);
const programs = ref([]);
const applications = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const allowedQueues = ['setup', 'results', 'all'];
const pageUrl = new URL(window.location.href);
const requestedQueue = pageUrl.searchParams.get('queue');
const activeQueue = ref(allowedQueues.includes(requestedQueue) ? requestedQueue : 'setup');
const selectedProgram = ref(pageUrl.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected'
    ? 'Assigned programs only'
    : 'All organization programs');
const queueTabs = computed(() => [
    { key: 'setup', label: 'Activities to run', count: Number(summary.value.setup ?? 0) },
    { key: 'results', label: 'Ready for results', count: Number(summary.value.results ?? 0) },
    { key: 'all', label: 'All active', count: Number(summary.value.active ?? 0) },
]);
const stageLine = computed(() => [
    { key: 'formal_application', label: 'Formal application', count: Number(stages.value.formal_application ?? 0), icon: 'fa-solid fa-file-signature' },
    { key: 'exam', label: 'Exam', count: Number(stages.value.exam ?? 0), icon: 'fa-solid fa-pen-to-square' },
    { key: 'interview', label: 'Interview', count: Number(stages.value.interview ?? 0), icon: 'fa-solid fa-comments' },
]);

function applicantInitials(name) {
    return String(name ?? 'Applicant')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function activityClass(state) {
    return {
        result: 'bg-emerald-100 text-emerald-800',
        complete: 'bg-amber-100 text-amber-800',
        setup: 'bg-rose-100 text-rose-800',
        upcoming: 'bg-sky-100 text-sky-800',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function stageIcon(stage) {
    return {
        formal_application: 'fa-solid fa-file-signature',
        exam: 'fa-solid fa-pen-to-square',
        interview: 'fa-solid fa-comments',
    }[stage] ?? 'fa-solid fa-list-check';
}

function activityDetail(application) {
    const activity = application.activity;

    if (activity.scheduled_at) {
        return [activity.scheduled_at, activity.mode_label].filter(Boolean).join(' · ');
    }

    if (activity.state === 'result' && application.stage.key === 'formal_application') {
        return 'No dated activity required';
    }

    return 'No active schedule';
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);

    if (activeQueue.value === 'setup') nextUrl.searchParams.delete('queue');
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
        const response = await window.axios.get('/provider/workspaces/selection/data', {
            params: {
                queue: activeQueue.value,
                search: searchQuery.value.trim() || undefined,
                program_id: selectedProgram.value || undefined,
                page,
            },
        });
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        stages.value = response.data.stages ?? stages.value;
        nextActivity.value = response.data.next_activity;
        programs.value = response.data.programs ?? [];
        applications.value = response.data.applications ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load selection activities.';
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
                    Loading selection activities...
                </div>

                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-slate-950 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-start gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                                    <i class="fa-solid fa-calendar-check"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-amber-700">{{ workspace?.role }}</p>
                                    <h1 class="mt-1 font-display text-2xl font-bold text-slate-950">Selection activities</h1>
                                    <p class="mt-1 text-sm text-slate-600">Run applicant activities and record their results.</p>
                                </div>
                            </div>
                            <span class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600"><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold text-slate-600 sm:px-6">
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace?.organization_name }}</span>
                            <span><i class="fa-solid fa-user-clock mr-2 text-slate-400"></i>{{ workspace?.staff_name }}</span>
                            <span><i class="fa-solid fa-people-group mr-2 text-slate-400"></i>{{ summary.active }} active candidate{{ summary.active === 1 ? '' : 's' }}</span>
                        </div>
                    </header>

                    <section v-if="nextActivity" class="mt-4 overflow-hidden rounded-lg border border-slate-900 bg-white shadow-sm">
                        <div class="grid sm:grid-cols-[7rem_minmax(0,1fr)_auto] sm:items-stretch">
                            <div class="flex items-center justify-center bg-slate-950 px-4 py-3 text-white sm:py-5">
                                <div class="text-center">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.18em] text-amber-300">Next task</p>
                                    <i class="fa-solid fa-forward-step mt-2 text-lg"></i>
                                </div>
                            </div>
                            <div class="flex min-w-0 items-center gap-3 px-5 py-4 sm:px-6">
                                <img v-if="nextActivity.applicant.profile_photo_url" :src="nextActivity.applicant.profile_photo_url" :alt="nextActivity.applicant.name" class="h-11 w-11 shrink-0 rounded-md object-cover">
                                <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-sm font-black text-slate-600">{{ applicantInitials(nextActivity.applicant.name) }}</span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="truncate text-base font-bold text-slate-950">{{ nextActivity.applicant.name }}</h2>
                                        <span :class="['rounded px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', activityClass(nextActivity.activity.state)]">{{ nextActivity.activity.label }}</span>
                                    </div>
                                    <p class="mt-1 truncate text-sm text-slate-600">{{ nextActivity.stage.label }} · {{ nextActivity.program.title }}</p>
                                </div>
                            </div>
                            <div class="flex items-center px-5 pb-4 sm:px-6 sm:py-4">
                                <a :href="nextActivity.detail_url" class="w-full rounded-md bg-amber-300 px-4 py-2.5 text-center text-sm font-black text-slate-950 transition hover:bg-amber-400 sm:w-auto">
                                    {{ nextActivity.activity.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-emerald-950">No selection activities are waiting</h2>
                            <p class="mt-0.5 text-sm text-emerald-800">Candidates appear here after application verification.</p>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div>
                                <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Current pipeline</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Candidates by activity</h2>
                            </div>
                            <p class="mt-1 text-sm text-slate-500 sm:mt-0">Verification complete, final decision not yet recorded</p>
                        </div>
                        <div class="grid sm:grid-cols-3">
                            <div v-for="(stage, index) in stageLine" :key="stage.key" class="flex items-center gap-3 border-b border-slate-200 px-5 py-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600"><i :class="stage.icon"></i></span>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-slate-500">{{ index + 1 }}. {{ stage.label }}</p>
                                    <p class="mt-0.5 text-lg font-black text-slate-950">{{ stage.count }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                                <div>
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Activity queue</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">Selection work in your scope</h2>
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

                            <div class="mt-4 flex items-center gap-2 overflow-x-auto pb-1" aria-label="Selection activity filters">
                                <button
                                    v-for="tab in queueTabs"
                                    :key="tab.key"
                                    type="button"
                                    :class="[
                                        'shrink-0 rounded-md px-3 py-2 text-xs font-bold transition',
                                        activeQueue === tab.key ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="selectQueue(tab.key)"
                                >
                                    {{ tab.label }} <span :class="['ml-1', activeQueue === tab.key ? 'text-amber-300' : 'text-slate-400']">{{ tab.count }}</span>
                                </button>
                                <span v-if="isRefreshing" class="ml-auto shrink-0 text-xs font-semibold text-slate-400"><i class="fa-solid fa-circle-notch mr-1 animate-spin"></i>Updating</span>
                            </div>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800 sm:px-6">{{ errorMessage }}</div>

                        <div v-if="applications.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(15rem,1.2fr)_minmax(9rem,.65fr)_minmax(15rem,1fr)_9rem_10rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Candidate</span>
                                <span>Stage</span>
                                <span>Activity</span>
                                <span>Waiting</span>
                                <span class="text-right">Next action</span>
                            </div>

                            <article v-for="application in applications" :key="application.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6 xl:grid-cols-[minmax(15rem,1.2fr)_minmax(9rem,.65fr)_minmax(15rem,1fr)_9rem_10rem] xl:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="application.applicant.name" class="h-11 w-11 shrink-0 rounded-md object-cover">
                                    <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(application.applicant.name) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</h3>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ application.program.title }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-slate-100 text-xs text-slate-600"><i :class="stageIcon(application.stage.key)"></i></span>
                                    <span class="text-sm font-bold text-slate-800">{{ application.stage.label }}</span>
                                </div>

                                <div>
                                    <span :class="['inline-flex rounded-md px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', activityClass(application.activity.state)]">{{ application.activity.label }}</span>
                                    <p class="mt-1.5 truncate text-xs text-slate-500">{{ activityDetail(application) }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-bold text-slate-800">{{ application.waiting_days }} day{{ application.waiting_days === 1 ? '' : 's' }}</p>
                                    <p class="mt-1 text-xs text-slate-500">in selection</p>
                                </div>

                                <a :href="application.detail_url" class="rounded-md bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ application.activity.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-calendar-day"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No candidates in this view</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another activity filter, program, or search.</p>
                        </div>

                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6">
                            <p class="text-xs font-semibold text-slate-500">{{ pagination.from }}-{{ pagination.to }} of {{ pagination.total }}</p>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="pagination.current_page <= 1 || isRefreshing" @click="loadWorkspace(pagination.current_page - 1)">Previous</button>
                                <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isRefreshing" @click="loadWorkspace(pagination.current_page + 1)">Next</button>
                            </div>
                        </div>
                    </section>

                    <section class="mt-4 flex items-start gap-3 rounded-lg border border-slate-300 bg-slate-50 px-5 py-4 sm:px-6">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white text-slate-700 shadow-sm"><i class="fa-solid fa-arrow-right-arrow-left"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-slate-950">Selection handoff</h2>
                            <p class="mt-0.5 text-sm text-slate-600">Candidates who pass their final configured activity move to the decision officer.</p>
                        </div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
