<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import ProviderProgramQueueContext from '../../../components/ProviderProgramQueueContext.vue';
import { createStaffQueueState, providerProgramQueueLink } from '../../../support/staffQueueState.js';

const queueByPath = {
    '/provider/workspaces/reviews/all': 'all',
    '/provider/workspaces/reviews/assigned': 'mine',
    '/provider/workspaces/reviews/unassigned': 'unassigned',
    '/provider/workspaces/reviews/returned': 'returned',
    '/provider/workspaces/reviews/history': 'history',
};
const queueCopy = {
    all: {
        title: 'Team review queue',
        description: 'See all active screening records in the programs you can access.',
        empty: 'No applications are awaiting screening.',
    },
    mine: {
        title: 'My review queue',
        description: 'Verify the applications assigned to you, one record at a time.',
        empty: 'No applications are assigned to you.',
    },
    unassigned: {
        title: 'Unassigned applications',
        description: 'Claim an available application before starting its review.',
        empty: 'No applications are waiting for a reviewer.',
    },
    returned: {
        title: 'Returned updates',
        description: 'Recheck applications after the applicant submitted a requested change.',
        empty: 'No applicant updates are waiting for review.',
    },
    history: {
        title: 'Review history',
        description: 'Reference screening reviews you previously completed.',
        empty: 'You have not completed a review yet.',
    },
};

const path = (window.portalPathname?.() ?? window.location.pathname).replace(/\/$/, '') || '/provider/workspaces/reviews/assigned';
const activeQueue = queueByPath[path] ?? 'mine';
const pageCopy = queueCopy[activeQueue];
const workspace = ref(null);
const summary = ref({ team: 0, mine: 0, unassigned: 0, returned: 0, history: 0, oldest_wait_days: 0 });
const programs = ref([]);
const applications = ref([]);
const nextReview = ref(null);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const queueState = createStaffQueueState(window, ['search','program_id']);
const search = ref(queueState.get('search') ?? '');
const selectedProgram = ref(queueState.get('program_id') ?? '');
const isLoading = ref(true);
const claimingId = ref(null);
const errorMessage = ref('');
const claimError = ref('');
let requestVersion = 0;
let requestedPage = 1;
let searchTimer = null;

const queues = computed(() => [
    { key: 'all', label: 'Team queue', count: summary.value.team, href: '/provider/workspaces/reviews/all' },
    { key: 'mine', label: 'My reviews', count: summary.value.mine, href: '/provider/workspaces/reviews/assigned' },
    { key: 'unassigned', label: 'Unassigned', count: summary.value.unassigned, href: '/provider/workspaces/reviews/unassigned' },
    { key: 'returned', label: 'Returned', count: summary.value.returned, href: '/provider/workspaces/reviews/returned' },
    { key: 'history', label: 'History', count: summary.value.history, href: '/provider/workspaces/reviews/history' },
]);
const resultRange = computed(() => isLoading.value ? 'Updating results...' : errorMessage.value ? 'Results unavailable' : pagination.value.total
    ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`
    : '0 applications');

function statusClass(state) {
    return {
        ready: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        returned: 'border-amber-200 bg-amber-50 text-amber-800',
        replacement: 'border-rose-200 bg-rose-50 text-rose-800',
        missing: 'border-amber-200 bg-amber-50 text-amber-800',
        pending: 'border-slate-200 bg-slate-100 text-slate-700',
        updated: 'border-sky-200 bg-sky-50 text-sky-800',
    }[state] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

function reviewUrl(application) {
    return queueState.link(application.detail_url);
}

async function loadApplications(page = 1) {
    window.clearTimeout(searchTimer);
    requestedPage = page;
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';
    claimError.value = '';

    try {
        const params = {
            queue: activeQueue,
            search: search.value.trim() || undefined,
            program_id: selectedProgram.value || undefined,
            page,
        };
        const response = await queueState.load((query) => window.axios.get('/provider/workspaces/reviews/data', { params: query }), params, () => version === requestVersion);

        if (version !== requestVersion) return;

        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        programs.value = response.data.programs ?? [];
        applications.value = response.data.applications ?? [];
        nextReview.value = response.data.next_review ?? null;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;
        errorMessage.value = error.response?.data?.message || 'The review queue could not be loaded.';
    } finally {
        if (version === requestVersion) isLoading.value = false;
    }
}

async function claimAndOpen(application) {
    if (isLoading.value || errorMessage.value || claimingId.value !== null) return;
    claimingId.value = application.id;
    claimError.value = '';

    try {
        await window.axios.patch(`/provider/applications/${application.id}/review-claim`);
        window.location.href = reviewUrl(application);
    } catch (error) {
        claimError.value = error.response?.data?.errors?.application?.[0]
            || error.response?.data?.message
            || 'This application could not be claimed.';
        claimingId.value = null;
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

        <section class="student-page" aria-labelledby="review-directory-title">
            <div class="provider-application-workspace mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div class="flex min-w-0 items-start gap-4">
                            <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-file-circle-check" aria-hidden="true"></i></span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Application review</p>
                                <h1 id="review-directory-title" class="application-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
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

                <nav class="mt-4 grid grid-cols-5 border border-slate-200 bg-white shadow-sm" aria-label="Application review queues">
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

                <section v-if="activeQueue === 'mine' && nextReview && !isLoading && !errorMessage" class="mt-4 border border-slate-200 border-l-4 border-l-amber-400 bg-white px-5 py-4 shadow-sm" aria-labelledby="next-review-title">
                    <div class="flex items-center gap-4">
                        <div class="grid h-10 w-10 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Continue reviewing</p>
                            <h2 id="next-review-title" class="mt-0.5 application-text text-base font-bold text-slate-950">{{ nextReview.applicant.name }}</h2>
                            <p class="mt-0.5 application-text text-xs text-slate-500">{{ nextReview.program.title }} · {{ nextReview.evidence.label }}</p>
                        </div>
                        <a :href="reviewUrl(nextReview)" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">Open review <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                    </div>
                </section>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Application records">
                    <header class="flex items-end gap-3 border-b border-slate-200 px-5 py-4">
                        <label class="min-w-0 flex-1">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-700">Search</span>
                            <span class="flex h-11 items-center border border-slate-300 px-3 focus-within:border-slate-700">
                                <i class="fa-solid fa-magnifying-glass mr-3 text-xs text-slate-400" aria-hidden="true"></i>
                                <input v-model="search" type="search" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm outline-none ring-0 focus:ring-0" placeholder="Applicant or program">
                            </span>
                        </label>
                        <label class="w-72">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-700">Program</span>
                            <select v-model="selectedProgram" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 focus:border-slate-700 focus:ring-slate-700">
                                <option value="">All assigned programs</option>
                                <option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option>
                            </select>
                        </label>
                    </header>

                    <div v-if="claimError" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800" role="alert">{{ claimError }}</div>

                    <div class="staff-table-viewport" :aria-busy="isLoading">
                        <StaffTableLoadError v-if="errorMessage" :message="errorMessage" @retry="loadApplications(requestedPage)" />
                        <table v-else class="w-full min-w-[56rem] table-fixed border-collapse text-left"><colgroup><col><col class="w-48"><col class="w-36"><col class="w-36"><col class="w-32"></colgroup>
                            <caption class="sr-only">Applications in {{ pageCopy.title }}. The saved match is advisory, not an eligibility decision.</caption>
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-[0.16em] text-slate-600">
                                    <th class="px-5 py-3">Applicant</th>
                                    <th class="px-5 py-3">Evidence</th>
                                    <th class="px-5 py-3">
                                        Saved match
                                        <span class="mt-0.5 block normal-case tracking-normal">Advisory only</span>
                                    </th>
                                    <th class="px-5 py-3">Queue</th>
                                    <th class="px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-if="isLoading"><td colspan="5" class="px-5 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading applications...</p></td></tr>
                                <tr v-else-if="!applications.length && !errorMessage">
                                    <td colspan="5" class="px-5 py-12 text-center">
                                        <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-circle-check text-sm" aria-hidden="true"></i></span>
                                        <p class="mt-3 font-semibold text-slate-800">{{ pageCopy.empty }}</p>
                                        <p class="mt-1 text-sm text-slate-500">{{ search || selectedProgram ? 'Clear the filters to check the full queue.' : 'New records will appear here automatically.' }}</p>
                                    </td>
                                </tr>
                                <tr v-else v-for="application in applications" :key="application.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <StaffTableIdentity :src="application.applicant.profile_photo_url" class="h-11 w-11" />
                                            <div class="min-w-0">
                                                <p class="application-text text-sm font-bold text-slate-950">{{ application.applicant.name }}</p>
                                                <p class="mt-1 application-text text-xs text-slate-500">{{ application.program.title }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span :class="['inline-flex border px-2.5 py-1 text-xs font-bold uppercase tracking-wide', statusClass(application.evidence.state)]">{{ application.evidence.label }}</span>
                                        <p v-if="application.evidence.required > 0" class="mt-1 text-xs text-slate-500">{{ application.evidence.accepted }} of {{ application.evidence.required }} accepted</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-800">{{ application.eligibility_score == null ? 'Not calculated' : `${application.eligibility_score}%` }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ application.applicant.verification_label }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-800">{{ application.assignment.label }}</p>
                                        <p v-if="activeQueue === 'history' && application.reviewed_at" class="mt-1 text-xs text-slate-500">{{ application.reviewed_at }}</p>
                                        <p v-else-if="activeQueue !== 'history' && application.waiting_days > 0" class="mt-1 text-xs text-slate-500">{{ application.waiting_days }} day{{ application.waiting_days === 1 ? '' : 's' }} waiting</p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <button v-if="application.assignment.state === 'unassigned' && activeQueue !== 'history'" type="button" class="staff-row-action staff-row-action--primary h-9 px-3 text-xs" :disabled="claimingId !== null" @click="claimAndOpen(application)" :aria-label="`${claimingId === application.id ? 'Claiming...' : 'Claim'} review for ${application.applicant.name}`">
                                            <i :class="['fa-solid text-[9px]', claimingId === application.id ? 'fa-spinner fa-spin' : 'fa-user-plus']" aria-hidden="true"></i>{{ claimingId === application.id ? 'Claiming...' : 'Claim' }}
                                        </button>
                                        <a v-else :href="reviewUrl(application)" class="staff-row-action h-9 px-3 text-xs" :aria-label="`Open review for ${application.applicant.name}`">Open <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                                    </td>
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
