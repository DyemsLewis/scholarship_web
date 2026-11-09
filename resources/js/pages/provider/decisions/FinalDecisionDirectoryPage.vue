<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import ProviderProgramQueueContext from '../../../components/ProviderProgramQueueContext.vue';
import { createStaffQueueState, providerProgramQueueLink } from '../../../support/staffQueueState.js';

const queueByPath = {
    '/provider/workspaces/decisions/pending': 'pending',
    '/provider/workspaces/decisions/waitlist': 'waitlist',
    '/provider/workspaces/decisions/recorded': 'recorded',
};
const queueCopy = {
    pending: {
        title: 'Pending decisions',
        description: 'Record the final outcome after every configured stage is complete.',
        empty: 'No applicants are waiting for a final decision.',
    },
    waitlist: {
        title: 'Waitlist',
        description: 'Review alternates when a scholarship slot becomes available.',
        empty: 'No applicants are currently waitlisted.',
    },
    recorded: {
        title: 'Decision history',
        description: 'Reference selected and declined application outcomes.',
        empty: 'No final decisions have been recorded.',
    },
};

const path = (window.portalPathname?.() ?? window.location.pathname).replace(/\/$/, '') || '/provider/workspaces/decisions/pending';
const requestedQueue = new URLSearchParams(window.location.search).get('queue');
const activeQueue = queueByPath[path] ?? (['pending', 'waitlist', 'recorded'].includes(requestedQueue) ? requestedQueue : 'pending');
const pageCopy = queueCopy[activeQueue];
const workspace = ref(null);
const summary = ref({ pending: 0, waitlist: 0, recorded: 0 });
const programs = ref([]);
const applications = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const queueState = createStaffQueueState(window, ['search','program_id'], { path: `/provider/workspaces/decisions/${activeQueue}` });
const search = ref(queueState.get('search') ?? '');
const selectedProgram = ref(queueState.get('program_id') ?? '');
const isLoading = ref(true);
const errorMessage = ref('');
let requestVersion = 0;
let requestedPage = 1;
let searchTimer = null;

const queues = computed(() => [
    { key: 'pending', label: 'Pending', count: summary.value.pending, href: '/provider/workspaces/decisions/pending' },
    { key: 'waitlist', label: 'Waitlist', count: summary.value.waitlist, href: '/provider/workspaces/decisions/waitlist' },
    { key: 'recorded', label: 'History', count: summary.value.recorded, href: '/provider/workspaces/decisions/recorded' },
]);
const resultRange = computed(() => isLoading.value ? 'Updating results...' : errorMessage.value ? 'Results unavailable' : pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 applicants');
const selectedProgramSummary = computed(() => programs.value.find((program) => Number(program.id) === Number(selectedProgram.value)) ?? null);

function recordUrl(application) {
    return queueState.link(application.detail_url);
}

function decisionClass(state) {
    return {
        selected: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        waitlist: 'border-amber-200 bg-amber-50 text-amber-800',
        not_selected: 'border-slate-300 bg-slate-100 text-slate-700',
        pending: 'border-amber-200 bg-amber-50 text-amber-800',
    }[state] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

async function loadApplications(page = 1) {
    window.clearTimeout(searchTimer);
    requestedPage = page;
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const params = {
            queue: activeQueue,
            search: search.value.trim() || undefined,
            program_id: selectedProgram.value || undefined,
            page,
        };
        const response = await queueState.load((query) => window.axios.get('/provider/workspaces/decisions/data', { params: query }), params, () => version === requestVersion);

        if (version !== requestVersion) return;
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        programs.value = response.data.programs ?? [];
        applications.value = response.data.applications ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The final decision docket could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

watch(search, () => {
    requestVersion++;
    isLoading.value = true;
    errorMessage.value = '';
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadApplications(1), 300);
}, { flush: 'sync' });
watch(selectedProgram, () => loadApplications(1), { flush: 'sync' });
onMounted(() => loadApplications(queueState.page));
onBeforeUnmount(() => {
    requestVersion++;
    window.clearTimeout(searchTimer);
});
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="decision-directory-title">
            <div class="provider-application-workspace mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div class="flex min-w-0 items-start gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Final decisions</p>
                                <h1 id="decision-directory-title" class="application-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                            </div>
                        </div>
                        <div v-if="workspace" class="border-l border-slate-200 pl-5 text-right">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-600">Program access</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ workspace.organization_name }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ workspace.program_access_mode === 'selected' ? 'Assigned programs' : 'All programs' }}</p>
                        </div>
                    </div>
                    <ProviderProgramQueueContext :program-id="selectedProgram" :programs="programs" class="mt-4" />
                </header>

                <nav class="mt-4 grid grid-cols-3 border border-slate-200 bg-white shadow-sm" aria-label="Final decision queues">
                    <a
                        v-for="queue in queues"
                        :key="queue.key"
                        :href="providerProgramQueueLink(queue.href, selectedProgram)"
                        :aria-current="activeQueue === queue.key ? 'page' : undefined"
                        :class="[
                            'flex min-h-16 items-center justify-between border-r border-slate-200 px-5 last:border-r-0',
                            activeQueue === queue.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50',
                        ]"
                    >
                        <span class="text-sm font-bold">{{ queue.label }}</span>
                        <span :class="['min-w-7 px-2 py-1 text-center text-xs font-bold', activeQueue === queue.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-700']">{{ queue.count }}</span>
                    </a>
                </nav>

                <section v-if="selectedProgramSummary" class="mt-4 grid grid-cols-[1fr_auto_auto_auto] items-center border border-slate-200 bg-white shadow-sm" aria-label="Selected program capacity">
                    <div class="min-w-0 px-5 py-4"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-600">Selected program</p><p class="mt-1 application-text text-sm font-bold text-slate-950">{{ selectedProgramSummary.title }}</p></div>
                    <div class="border-l border-slate-200 px-6 py-4 text-center"><p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Slots</p><p class="mt-1 text-sm font-bold text-slate-950">{{ selectedProgramSummary.slots_total ?? 'No limit' }}</p></div>
                    <div class="border-l border-slate-200 px-6 py-4 text-center"><p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Awarded</p><p class="mt-1 text-sm font-bold text-slate-950">{{ selectedProgramSummary.slots_occupied }}</p></div>
                    <div class="border-l border-slate-200 px-6 py-4 text-center"><p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Remaining</p><p class="mt-1 text-sm font-bold text-slate-950">{{ selectedProgramSummary.slots_remaining ?? 'No limit' }}</p></div>
                </section>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Final decision list">
                    <div class="flex items-center gap-3 border-b border-slate-200 p-4">
                        <label class="relative min-w-0 flex-1">
                            <span class="sr-only">Search applicants</span>
                            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                            <input v-model="search" type="search" class="h-11 w-full border border-slate-300 bg-white pl-10 pr-4 text-sm outline-none focus:border-slate-950" placeholder="Search applicant or program">
                        </label>
                        <label class="w-72">
                            <span class="sr-only">Filter by program</span>
                            <select v-model="selectedProgram" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950">
                                <option value="">All programs</option>
                                <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.title }}</option>
                            </select>
                        </label>
                    </div>


                    <StaffTableLoadError v-if="errorMessage" :message="errorMessage" @retry="loadApplications(requestedPage)" />
                    <div v-else-if="isLoading" class="px-6 py-16 text-center">
                        <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span>
                        <p class="mt-3 text-sm font-semibold text-slate-500">Loading final decisions...</p>
                    </div>
                    <div v-else-if="!applications.length && !errorMessage" class="px-6 py-16 text-center">
                        <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel text-sm" aria-hidden="true"></i></span>
                        <p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p>
                        <p class="mt-1 text-xs text-slate-500">This docket updates as applicants complete selection.</p>
                    </div>

                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[56rem] table-fixed border-collapse text-left"><colgroup><col><col class="w-36"><col class="w-36"><col class="w-36"><col class="w-44"></colgroup>
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-[0.16em] text-slate-600">
                                    <th class="px-5 py-3">Applicant</th>
                                    <th class="px-5 py-3">Review status</th>
                                    <th class="px-5 py-3">Outcome</th>
                                    <th class="px-5 py-3">Program capacity</th>
                                    <th class="px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="application in applications" :key="application.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <StaffTableIdentity :src="application.applicant.profile_photo_url" class="h-11 w-11" />
                                            <div class="min-w-0"><p class="application-text text-sm font-bold text-slate-950">{{ application.applicant.name }}</p><p class="mt-1 application-text text-xs text-slate-500">{{ application.program.title }}</p></div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-bold text-slate-900">{{ application.stage_status }}</td>
                                    <td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-xs font-bold uppercase tracking-wide', decisionClass(application.decision.state)]">{{ application.decision.label }}</span></td>
                                    <td :class="['px-5 py-4 text-sm font-bold', application.capacity.remaining === 0 ? 'text-rose-700' : 'text-slate-900']">{{ application.capacity.remaining === null ? 'No slot limit' : application.capacity.remaining === 0 ? 'No slots left' : `${application.capacity.remaining} remaining` }}</td>
                                    <td class="px-5 py-4 text-right"><a :href="recordUrl(application)" class="staff-row-action staff-row-action--primary h-9 px-3 text-xs" :aria-label="`${application.decision.action_label} for ${application.applicant.name}`">{{ application.decision.action_label }} <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading || Boolean(errorMessage)" @click="loadApplications(pagination.current_page - 1)"><i class="fa-solid fa-arrow-left text-[9px]" aria-hidden="true"></i>Previous</button>
                            <button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading || Boolean(errorMessage)" @click="loadApplications(pagination.current_page + 1)">Next<i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></button>
                        </div>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
