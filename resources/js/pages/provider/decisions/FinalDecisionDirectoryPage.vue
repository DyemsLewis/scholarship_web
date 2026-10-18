<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

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

const path = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/decisions/pending';
const requestedQueue = new URLSearchParams(window.location.search).get('queue');
const activeQueue = queueByPath[path] ?? (['pending', 'waitlist', 'recorded'].includes(requestedQueue) ? requestedQueue : 'pending');
const pageCopy = queueCopy[activeQueue];
const workspace = ref(null);
const summary = ref({ pending: 0, waitlist: 0, recorded: 0 });
const programs = ref([]);
const applications = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
const selectedProgram = ref(new URLSearchParams(window.location.search).get('program_id') ?? '');
const isLoading = ref(true);
const errorMessage = ref('');
let requestVersion = 0;
let searchTimer = null;

const queues = computed(() => [
    { key: 'pending', label: 'Pending', count: summary.value.pending, href: '/provider/workspaces/decisions/pending' },
    { key: 'waitlist', label: 'Waitlist', count: summary.value.waitlist, href: '/provider/workspaces/decisions/waitlist' },
    { key: 'recorded', label: 'History', count: summary.value.recorded, href: '/provider/workspaces/decisions/recorded' },
]);
const resultRange = computed(() => pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 applicants');
const selectedProgramSummary = computed(() => programs.value.find((program) => Number(program.id) === Number(selectedProgram.value)) ?? null);

function recordUrl(application) {
    const returnTo = `${path}${window.location.search}`;
    return `${application.detail_url}?return_to=${encodeURIComponent(returnTo)}`;
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
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/decisions/data', {
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
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => loadApplications(1), 300);
});
watch(selectedProgram, () => loadApplications(1));
onMounted(() => loadApplications());
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="decision-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Final decisions</p>
                            <h1 id="decision-directory-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                        </div>
                        <div v-if="workspace" class="border-l border-slate-200 pl-5 text-right">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Decision scope</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ workspace.organization_name }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ workspace.program_access_mode === 'selected' ? 'Assigned programs' : 'All programs' }}</p>
                        </div>
                    </div>
                </header>

                <nav class="mt-4 grid grid-cols-3 border border-slate-200 bg-white shadow-sm" aria-label="Final decision queues">
                    <a
                        v-for="queue in queues"
                        :key="queue.key"
                        :href="queue.href"
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
                    <div class="min-w-0 px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Selected program</p><p class="mt-1 truncate text-sm font-bold text-slate-950">{{ selectedProgramSummary.title }}</p></div>
                    <div class="border-l border-slate-200 px-6 py-4 text-center"><p class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Slots</p><p class="mt-1 text-sm font-bold text-slate-950">{{ selectedProgramSummary.slots_total ?? 'No limit' }}</p></div>
                    <div class="border-l border-slate-200 px-6 py-4 text-center"><p class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Awarded</p><p class="mt-1 text-sm font-bold text-slate-950">{{ selectedProgramSummary.slots_occupied }}</p></div>
                    <div class="border-l border-slate-200 px-6 py-4 text-center"><p class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Remaining</p><p class="mt-1 text-sm font-bold text-slate-950">{{ selectedProgramSummary.slots_remaining ?? 'No limit' }}</p></div>
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

                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                    <div v-if="isLoading" class="px-6 py-16 text-center text-sm font-semibold text-slate-500">Loading final decisions...</div>
                    <div v-else-if="!applications.length" class="px-6 py-16 text-center">
                        <i class="fa-solid fa-gavel text-3xl text-slate-300" aria-hidden="true"></i>
                        <p class="mt-3 text-sm font-bold text-slate-800">{{ pageCopy.empty }}</p>
                        <p class="mt-1 text-xs text-slate-500">This docket updates as applicants complete selection.</p>
                    </div>

                    <div v-else class="staff-table-viewport">
                        <table class="w-full min-w-[70rem] table-fixed border-collapse text-left">
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">
                                    <th class="w-[31%] px-5 py-3">Applicant</th>
                                    <th class="w-[19%] px-5 py-3">Review status</th>
                                    <th class="w-[20%] px-5 py-3">Outcome</th>
                                    <th class="w-[18%] px-5 py-3">Program capacity</th>
                                    <th class="w-[12%] px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="application in applications" :key="application.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="`${application.applicant.name} profile`" class="h-11 w-11 shrink-0 border border-slate-200 object-cover">
                                            <span v-else class="grid h-11 w-11 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                            <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ application.program.title }}</p></div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4"><p class="text-sm font-bold text-slate-900">{{ application.stage_status }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ application.applicant.education || 'Verified applicant record' }}</p></td>
                                    <td class="px-5 py-4"><span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', decisionClass(application.decision.state)]">{{ application.decision.label }}</span><p class="mt-2 text-xs text-slate-500">{{ application.decision.reason || application.decision.recorded_at || `${application.waiting_days} days waiting` }}</p></td>
                                    <td class="px-5 py-4"><p class="text-sm font-bold text-slate-900">{{ application.capacity.label }}</p><p class="mt-1 text-xs text-slate-500">{{ application.capacity.remaining === null ? 'Selection is not capped' : `${application.capacity.remaining} remaining` }}</p></td>
                                    <td class="px-5 py-4 text-right"><a :href="recordUrl(application)" class="inline-flex h-9 items-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800">{{ application.decision.action_label }} <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3">
                        <p class="text-xs font-semibold text-slate-500">{{ resultRange }}</p>
                        <div class="flex gap-2">
                            <button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page <= 1 || isLoading" @click="loadApplications(pagination.current_page - 1)">Previous</button>
                            <button type="button" class="h-9 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page || isLoading" @click="loadApplications(pagination.current_page + 1)">Next</button>
                        </div>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
