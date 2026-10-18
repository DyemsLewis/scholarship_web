<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const queueByPath = {
    '/provider/workspaces/reviews/assigned': 'mine',
    '/provider/workspaces/reviews/unassigned': 'unassigned',
    '/provider/workspaces/reviews/returned': 'returned',
    '/provider/workspaces/reviews/history': 'history',
};
const queueCopy = {
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

const path = window.location.pathname.replace(/\/$/, '') || '/provider/workspaces/reviews/assigned';
const activeQueue = queueByPath[path] ?? 'mine';
const pageCopy = queueCopy[activeQueue];
const workspace = ref(null);
const summary = ref({ mine: 0, unassigned: 0, returned: 0, history: 0, oldest_wait_days: 0 });
const programs = ref([]);
const applications = ref([]);
const nextReview = ref(null);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const search = ref('');
const selectedProgram = ref(new URLSearchParams(window.location.search).get('program_id') ?? '');
const isLoading = ref(true);
const claimingId = ref(null);
const errorMessage = ref('');
let requestVersion = 0;
let searchTimer = null;

const queues = computed(() => [
    { key: 'mine', label: 'My reviews', count: summary.value.mine, href: '/provider/workspaces/reviews/assigned' },
    { key: 'unassigned', label: 'Unassigned', count: summary.value.unassigned, href: '/provider/workspaces/reviews/unassigned' },
    { key: 'returned', label: 'Returned', count: summary.value.returned, href: '/provider/workspaces/reviews/returned' },
    { key: 'history', label: 'History', count: summary.value.history, href: '/provider/workspaces/reviews/history' },
]);
const resultRange = computed(() => pagination.value.total
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
    return `${application.detail_url}?return_to=${encodeURIComponent(path)}`;
}

async function loadApplications(page = 1) {
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/reviews/data', {
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
    claimingId.value = application.id;
    errorMessage.value = '';

    try {
        await window.axios.patch(`/provider/applications/${application.id}/review-claim`);
        window.location.href = reviewUrl(application);
    } catch (error) {
        errorMessage.value = error.response?.data?.errors?.application?.[0]
            || error.response?.data?.message
            || 'This application could not be claimed.';
        claimingId.value = null;
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

        <section class="student-page" aria-labelledby="review-directory-title">
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Application review</p>
                            <h1 id="review-directory-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                        </div>
                        <div v-if="workspace" class="border-l border-slate-200 pl-5 text-right">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Review scope</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800">{{ workspace.organization_name }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ workspace.program_access_mode === 'selected' ? 'Assigned programs' : 'All programs' }}</p>
                        </div>
                    </div>
                </header>

                <nav class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Application review queues">
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

                <section v-if="activeQueue === 'mine' && nextReview" class="mt-4 border border-slate-200 border-l-4 border-l-amber-400 bg-white px-5 py-4 shadow-sm" aria-labelledby="next-review-title">
                    <div class="flex items-center gap-4">
                        <div class="grid h-10 w-10 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Continue reviewing</p>
                            <h2 id="next-review-title" class="mt-0.5 truncate text-base font-bold text-slate-950">{{ nextReview.applicant.name }}</h2>
                            <p class="mt-0.5 truncate text-xs text-slate-500">{{ nextReview.program.title }} · {{ nextReview.evidence.label }}</p>
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

                    <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>

                    <div class="staff-table-viewport">
                        <table class="w-full min-w-[70rem] table-fixed border-collapse text-left">
                            <caption class="sr-only">Applications in {{ pageCopy.title }}</caption>
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">
                                    <th class="w-[32%] px-5 py-3">Applicant</th>
                                    <th class="w-[25%] px-5 py-3">Evidence</th>
                                    <th class="w-[19%] px-5 py-3">Eligibility</th>
                                    <th class="w-[14%] px-5 py-3">Queue</th>
                                    <th class="w-[10%] px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-if="isLoading && !applications.length"><td colspan="5" class="px-5 py-12 text-center text-sm font-semibold text-slate-500">Loading applications...</td></tr>
                                <tr v-else-if="!applications.length">
                                    <td colspan="5" class="px-5 py-12 text-center">
                                        <p class="font-semibold text-slate-800">{{ pageCopy.empty }}</p>
                                        <p class="mt-1 text-sm text-slate-500">{{ search || selectedProgram ? 'Clear the filters to check the full queue.' : 'New records will appear here automatically.' }}</p>
                                    </td>
                                </tr>
                                <tr v-for="application in applications" :key="application.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="`${application.applicant.name} profile`" class="h-11 w-11 shrink-0 border border-slate-200 object-cover">
                                            <span v-else class="grid h-11 w-11 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</p>
                                                <p class="mt-1 truncate text-xs text-slate-500">{{ application.program.title }}</p>
                                                <p v-if="application.applicant.education" class="mt-1 truncate text-xs text-slate-500">{{ application.applicant.education }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(application.evidence.state)]">{{ application.evidence.label }}</span>
                                        <p class="mt-1.5 text-xs text-slate-500">{{ application.evidence.detail }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-800">{{ application.eligibility_score === null ? 'Check required' : `${application.eligibility_score}% match` }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ application.applicant.verification_label }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-800">{{ application.assignment.label }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ activeQueue === 'history' ? (application.reviewed_at || 'Reviewed') : `${application.waiting_days} day${application.waiting_days === 1 ? '' : 's'} waiting` }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <button v-if="application.assignment.state === 'unassigned' && activeQueue !== 'history'" type="button" class="inline-flex h-9 items-center bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="claimingId === application.id" @click="claimAndOpen(application)">
                                            {{ claimingId === application.id ? 'Claiming...' : 'Claim' }}
                                        </button>
                                        <a v-else :href="reviewUrl(application)" class="inline-flex h-9 items-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">Open <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                                    </td>
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
