<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import SelectionSessionFormModal from './components/SelectionSessionFormModal.vue';

const queueByPath = {
    '/provider/workspaces/selection/active': 'active',
    '/provider/workspaces/selection/setup': 'unassigned',
    '/provider/workspaces/selection/unassigned': 'unassigned',
    '/provider/workspaces/selection/results': 'results',
    '/provider/workspaces/selection/history': 'history',
};
const copyByQueue = {
    active: { title: 'Selection sessions', description: 'Prepare and run formal application, examination, and interview sessions.', empty: 'No active sessions.' },
    unassigned: { title: 'Unassigned applicants', description: 'Place applicants into a session for their current selection stage.', empty: 'Every applicant is assigned to a session.' },
    results: { title: 'Results to record', description: 'Review completed participation before recording each applicant outcome.', empty: 'No completed activities are waiting for an outcome.' },
    history: { title: 'Session history', description: 'Reference completed and cancelled selection sessions.', empty: 'No session history yet.' },
};

const path = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/selection/active';
const activeQueue = queueByPath[path] ?? 'active';
const pageCopy = copyByQueue[activeQueue];
const workspace = ref(null);
const summary = ref({ active: 0, unassigned: 0, results: 0, history: 0 });
const programs = ref([]);
const sessions = ref([]);
const applications = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
const selectedProgram = ref(new URLSearchParams(window.location.search).get('program_id') ?? '');
const selectedStage = ref(new URLSearchParams(window.location.search).get('stage_key') ?? '');
const isLoading = ref(true);
const errorMessage = ref('');
const showSessionModal = ref(false);
const sessionContext = ref({ programId: '', stageKey: '', applicationId: null });
let requestVersion = 0;
let searchTimer = null;

const queues = computed(() => [
    { key: 'active', label: 'Sessions', count: summary.value.active, href: '/provider/workspaces/selection/active' },
    { key: 'unassigned', label: 'Unassigned', count: summary.value.unassigned, href: '/provider/workspaces/selection/unassigned' },
    { key: 'results', label: 'Results', count: summary.value.results, href: '/provider/workspaces/selection/results' },
    { key: 'history', label: 'History', count: summary.value.history, href: '/provider/workspaces/selection/history' },
]);
const stageOptions = computed(() => {
    const labels = new Map();
    programs.value.forEach((program) => (program.stages ?? []).forEach((stage) => labels.set(stage, labelize(stage))));
    return [...labels].map(([key, label]) => ({ key, label }));
});
const isSessionQueue = computed(() => ['active', 'history'].includes(activeQueue));
const resultRange = computed(() => pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : isSessionQueue.value ? '0 sessions' : '0 applicants');

function labelize(value) {
    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not set';
}

function statusClass(status) {
    return {
        draft: 'border-slate-300 bg-white text-slate-700',
        scheduled: 'border-sky-200 bg-sky-50 text-sky-800',
        ongoing: 'border-amber-200 bg-amber-50 text-amber-800',
        completed: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        cancelled: 'border-rose-200 bg-rose-50 text-rose-800',
    }[status] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

function formatDate(value) {
    if (!value) return 'Date not set';
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value));
}

function openSessionModal(context = {}) {
    sessionContext.value = {
        programId: context.programId ?? '',
        stageKey: context.stageKey ?? '',
        applicationId: context.applicationId ?? null,
    };
    showSessionModal.value = true;
}

function closeSessionModal() {
    showSessionModal.value = false;
}

function handleSessionSaved(session) {
    showSessionModal.value = false;
    window.location.href = `/provider/workspaces/selection/sessions/${session.id}`;
}

async function loadRecords(page = 1) {
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/workspaces/selection/sessions/data', {
            params: { queue: activeQueue, search: search.value.trim() || undefined, program_id: selectedProgram.value || undefined, stage_key: selectedStage.value || undefined, page },
        });
        if (version !== requestVersion) return;
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        programs.value = response.data.programs ?? [];
        sessions.value = response.data.sessions ?? [];
        applications.value = response.data.applications ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The selection workspace could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadRecords(1), 300);
});
watch([selectedProgram, selectedStage], () => loadRecords(1));
onMounted(() => loadRecords());
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page provider-page" aria-labelledby="selection-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="provider-hero task-page-header border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 items-center gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Selection workflow</p>
                                <h1 id="selection-directory-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                                <p class="mt-1 text-sm text-slate-600">{{ pageCopy.description }}</p>
                            </div>
                        </div>
                        <button type="button" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800" @click="openSessionModal()"><i class="fa-solid fa-plus text-[10px]" aria-hidden="true"></i>Create session</button>
                    </div>
                </header>

                <nav class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Selection queues">
                    <a v-for="queue in queues" :key="queue.key" :href="queue.href" :aria-current="activeQueue === queue.key ? 'page' : undefined" :class="['flex min-h-14 items-center justify-between border-r border-slate-200 px-5 last:border-r-0', activeQueue === queue.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50']">
                        <span class="text-sm font-bold">{{ queue.label }}</span>
                        <span :class="['min-w-7 px-2 py-1 text-center text-xs font-bold', activeQueue === queue.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-700']">{{ queue.count }}</span>
                    </a>
                </nav>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" :aria-label="pageCopy.title">
                    <header class="flex items-end gap-3 border-b border-slate-200 px-5 py-4">
                        <label class="min-w-0 flex-1"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Search</span><span class="flex h-10 items-center border border-slate-300 px-3 focus-within:border-slate-700"><i class="fa-solid fa-magnifying-glass mr-3 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm outline-none ring-0 focus:ring-0" :placeholder="isSessionQueue ? 'Session or program' : 'Applicant or program'"></span></label>
                        <label class="w-72"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Program</span><select v-model="selectedProgram" class="h-10 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 focus:border-slate-700 focus:ring-slate-700"><option value="">All assigned programs</option><option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option></select></label>
                        <label class="w-56"><span class="mb-1.5 block text-xs font-semibold text-slate-700">Stage</span><select v-model="selectedStage" class="h-10 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 focus:border-slate-700 focus:ring-slate-700"><option value="">All stages</option><option v-for="stage in stageOptions" :key="stage.key" :value="stage.key">{{ stage.label }}</option></select></label>
                    </header>
                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>

                    <div class="staff-table-viewport">
                        <table v-if="isSessionQueue" class="w-full min-w-[70rem] table-fixed border-collapse text-left">
                            <caption class="sr-only">{{ pageCopy.title }}</caption>
                            <thead class="bg-slate-50"><tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><th class="w-[29%] px-5 py-3">Session</th><th class="w-[26%] px-5 py-3">Program</th><th class="w-[20%] px-5 py-3">Schedule</th><th class="w-[12%] px-5 py-3">Roster</th><th class="w-[8%] px-5 py-3">Status</th><th class="w-[5%] px-5 py-3 text-right"><span class="sr-only">Action</span></th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-if="isLoading && !sessions.length"><td colspan="6" class="px-5 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading sessions...</p></td></tr>
                                <tr v-else-if="!sessions.length"><td colspan="6" class="px-5 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i></span><p class="mt-3 font-semibold text-slate-800">{{ pageCopy.empty }}</p><p class="mt-1 text-sm text-slate-500">{{ search || selectedProgram || selectedStage ? 'Clear the filters to see all records.' : 'Create a session when applicants reach a selection stage.' }}</p></td></tr>
                                <tr v-for="session in sessions" :key="session.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><p class="truncate text-sm font-bold text-slate-950">{{ session.title }}</p><p class="mt-1 text-xs text-slate-500">{{ labelize(session.stage_key) }} · {{ labelize(session.mode) }}</p></td>
                                    <td class="px-5 py-4"><p class="truncate text-sm font-semibold text-slate-800">{{ session.program?.title }}</p><p v-if="session.proof_required" class="mt-1 text-xs text-slate-500">Proof required</p></td>
                                    <td class="px-5 py-4"><p class="text-sm font-semibold text-slate-800">{{ formatDate(session.starts_at) }}</p><p v-if="session.venue" class="mt-1 truncate text-xs text-slate-500">{{ session.venue }}</p></td>
                                    <td class="px-5 py-4"><p class="text-sm font-bold text-slate-900">{{ session.capacity.assigned }} assigned</p><p class="mt-1 text-xs text-slate-500">{{ session.capacity.limit ? `${session.capacity.remaining} spaces left` : 'No capacity limit' }}</p></td>
                                    <td class="px-5 py-4"><span :class="['inline-flex border px-2 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(session.status)]">{{ labelize(session.status) }}</span></td>
                                    <td class="px-5 py-4 text-right"><a :href="`/provider/workspaces/selection/sessions/${session.id}`" class="inline-flex h-9 w-9 items-center justify-center border border-slate-300 text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open ${session.title}`"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                                </tr>
                            </tbody>
                        </table>

                        <table v-else class="w-full min-w-[68rem] table-fixed border-collapse text-left">
                            <caption class="sr-only">{{ pageCopy.title }}</caption>
                            <thead class="bg-slate-50"><tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><th class="w-[34%] px-5 py-3">Applicant</th><th class="w-[31%] px-5 py-3">Program</th><th class="w-[18%] px-5 py-3">Stage</th><th class="w-[12%] px-5 py-3">Submitted</th><th class="w-[5%] px-5 py-3 text-right"><span class="sr-only">Action</span></th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-if="isLoading && !applications.length"><td colspan="5" class="px-5 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading applicants...</p></td></tr>
                                <tr v-else-if="!applications.length"><td colspan="5" class="px-5 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-user-clock" aria-hidden="true"></i></span><p class="mt-3 font-semibold text-slate-800">{{ pageCopy.empty }}</p><p class="mt-1 text-sm text-slate-500">{{ search || selectedProgram || selectedStage ? 'Clear the filters to see all records.' : 'Records move here automatically as work is completed.' }}</p></td></tr>
                                <tr v-for="application in applications" :key="application.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4"><div class="flex min-w-0 items-center gap-3"><img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="`${application.applicant.name} profile`" class="h-11 w-11 shrink-0 border border-slate-200 object-cover"><span v-else class="grid h-11 w-11 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i></span><div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ application.applicant.education || 'Education not provided' }}</p></div></div></td>
                                    <td class="px-5 py-4"><p class="truncate text-sm font-semibold text-slate-800">{{ application.program.title }}</p></td>
                                    <td class="px-5 py-4"><p class="text-sm font-bold text-slate-900">{{ application.stage.label }}</p><p class="mt-1 text-xs text-slate-500">{{ activeQueue === 'results' ? 'Activity complete' : 'Needs a session' }}</p></td>
                                    <td class="px-5 py-4 text-sm text-slate-600">{{ application.submitted_at || 'Not available' }}</td>
                                    <td class="px-5 py-4 text-right">
                                        <button v-if="activeQueue === 'unassigned'" type="button" class="inline-flex h-9 w-9 items-center justify-center border border-slate-300 text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`${application.action.label} for ${application.applicant.name}`" @click="openSessionModal({ programId: application.program.id, stageKey: application.stage.key, applicationId: application.id })"><i class="fa-solid fa-calendar-plus text-xs" aria-hidden="true"></i></button>
                                        <a v-else :href="application.action.url" class="inline-flex h-9 w-9 items-center justify-center border border-slate-300 text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`${application.action.label} for ${application.applicant.name}`"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3"><p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p><div class="flex gap-2"><button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading" @click="loadRecords(pagination.current_page - 1)">Previous</button><button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading" @click="loadRecords(pagination.current_page + 1)">Next</button></div></footer>
                </section>
            </div>
        </section>

        <SelectionSessionFormModal
            :open="showSessionModal"
            :program-id="sessionContext.programId"
            :stage-key="sessionContext.stageKey"
            :application-id="sessionContext.applicationId"
            @close="closeSessionModal"
            @saved="handleSessionSaved"
        />
    </main>
</template>
