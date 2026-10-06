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
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);
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
        upcoming: 'bg-slate-100 text-slate-700',
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading selection activities" message="Preparing schedules and results that need attention." />

                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Selection activities are unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="selection" title="Selection activities" description="Coordinate applicant assessments and record complete, defensible results." icon="fa-solid fa-calendar-check">
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace?.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextActivity" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950"><i class="fa-solid fa-forward-step text-sm"></i></span>
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <img v-if="nextActivity.applicant.profile_photo_url" :src="nextActivity.applicant.profile_photo_url" :alt="nextActivity.applicant.name" class="h-9 w-9 shrink-0 object-cover">
                                <span v-else class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(nextActivity.applicant.name) }}</span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Next task</p>
                                        <span :class="['px-2 py-0.5 text-[0.6rem] font-black uppercase tracking-wide', activityClass(nextActivity.activity.state)]">{{ nextActivity.activity.label }}</span>
                                    </div>
                                    <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextActivity.applicant.name }}</h2>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ nextActivity.stage.label }} · {{ nextActivity.program.title }}</p>
                                </div>
                            </div>
                            <div class="shrink-0">
                                <a :href="nextActivity.detail_url" class="inline-flex items-center bg-slate-950 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ nextActivity.activity.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-xs"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-slate-950">No selection activities are waiting</h2>
                            <p class="mt-0.5 text-xs text-slate-500">Candidates appear here after application verification.</p>
                        </div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-4 py-3 sm:px-5">
                            <div>
                                <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Activity queue</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-950">{{ activeQueueTab.label }}</h2>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                <span v-for="stage in stageLine" :key="stage.key"><strong class="text-slate-900">{{ stage.count }}</strong> {{ stage.label }}</span>
                            </div>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Selection activity filters" @select="selectQueue" />

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:grid-cols-[minmax(16rem,1fr)_minmax(13rem,.65fr)] sm:px-5">
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
                            <div class="hidden grid-cols-[minmax(16rem,1.1fr)_minmax(22rem,1.35fr)_9rem_10rem] gap-5 bg-slate-50 px-5 py-3 text-[0.65rem] font-black uppercase tracking-[0.14em] text-slate-500 xl:grid">
                                <span>Candidate</span>
                                <span>Activity step</span>
                                <span>Waiting</span>
                                <span class="text-right">Next action</span>
                            </div>

                            <article v-for="application in applications" :key="application.id" class="grid gap-4 px-4 py-3.5 transition hover:bg-slate-50 sm:px-5 xl:grid-cols-[minmax(16rem,1.1fr)_minmax(22rem,1.35fr)_9rem_10rem] xl:items-center xl:gap-5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="application.applicant.name" class="h-10 w-10 shrink-0 object-cover">
                                    <span v-else class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(application.applicant.name) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</h3>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ application.program.title }}</p>
                                    </div>
                                </div>

                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-xs text-slate-600"><i :class="stageIcon(application.stage.key)"></i></span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-bold text-slate-900">{{ application.stage.label }}</span>
                                            <span :class="['inline-flex px-2 py-1 text-[0.6rem] font-black uppercase tracking-wide', activityClass(application.activity.state)]">{{ application.activity.label }}</span>
                                        </div>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ activityDetail(application) }}</p>
                                    </div>
                                </div>

                                <div>
                                    <p class="text-sm font-bold text-slate-800">{{ application.waiting_days }} day{{ application.waiting_days === 1 ? '' : 's' }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">in this stage</p>
                                </div>

                                <a :href="application.detail_url" class="bg-slate-950 px-3.5 py-2.5 text-center text-xs font-bold text-white transition hover:bg-slate-800">
                                    {{ application.activity.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem] text-amber-300"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-calendar-day"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No candidates in this view</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another activity filter, program, or search.</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="candidates" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
