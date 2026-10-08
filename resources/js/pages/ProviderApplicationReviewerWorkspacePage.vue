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
const summary = ref({ mine: 0, unassigned: 0, team: 0, returned: 0, history: 0, oldest_wait_days: 0 });
const nextReview = ref(null);
const programs = ref([]);
const applications = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const url = new URL(window.location.href);
const pathSection = url.pathname.split('/').filter(Boolean).at(-1);
const pathQueueMap = { assigned: 'mine', unassigned: 'unassigned', returned: 'returned', history: 'history' };
const queueOptions = ['mine', 'unassigned', 'returned', 'history', 'all'];
const requestedQueue = pathQueueMap[pathSection] ?? url.searchParams.get('queue');
const activeQueue = ref(queueOptions.includes(requestedQueue) ? requestedQueue : 'mine');
const selectedProgram = ref(url.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const queueSections = computed(() => [
    { key: 'mine', label: 'Assigned reviews', shortLabel: 'Assigned', description: 'Applications currently assigned to you.', count: Number(summary.value.mine ?? 0), href: '/provider/workspaces/reviews/assigned', icon: 'fa-user-check' },
    { key: 'unassigned', label: 'Unassigned applications', shortLabel: 'Unassigned', description: 'New applications waiting for an owner.', count: Number(summary.value.unassigned ?? 0), href: '/provider/workspaces/reviews/unassigned', icon: 'fa-user-plus' },
    { key: 'returned', label: 'Returned corrections', shortLabel: 'Corrections', description: 'Applicant corrections ready for another check.', count: Number(summary.value.returned ?? 0), href: '/provider/workspaces/reviews/returned', icon: 'fa-rotate-left' },
    { key: 'history', label: 'Review history', shortLabel: 'History', description: 'Applications you previously reviewed.', count: Number(summary.value.history ?? 0), href: '/provider/workspaces/reviews/history', icon: 'fa-clock-rotate-left' },
]);
const activeSection = computed(() => queueSections.value.find((section) => section.key === activeQueue.value) ?? queueSections.value[0]);
const isHistory = computed(() => activeQueue.value === 'history');

function evidenceClass(state) {
    return {
        ready: 'bg-emerald-100 text-emerald-800',
        not_required: 'bg-slate-100 text-slate-700',
        returned: 'bg-amber-100 text-amber-800',
        updated: 'bg-slate-100 text-slate-700',
        replacement: 'bg-rose-100 text-rose-800',
        missing: 'bg-amber-100 text-amber-800',
        pending: 'bg-amber-100 text-amber-800',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function evidenceIcon(state) {
    if (state === 'ready') return 'fa-solid fa-circle-check';
    if (state === 'returned') return 'fa-solid fa-rotate-left';
    if (['replacement', 'missing', 'pending'].includes(state)) return 'fa-solid fa-triangle-exclamation';
    return 'fa-solid fa-file-lines';
}

function waitLabel(days) {
    if (days === 0) return 'Submitted today';
    return `${days} day${days === 1 ? '' : 's'} waiting`;
}

function waitClass(days) {
    if (days >= 7) return 'text-rose-700';
    if (days >= 3) return 'text-amber-700';
    return 'text-slate-600';
}

function reviewDetailUrl(path) {
    const detailUrl = new URL(path, window.location.origin);
    detailUrl.searchParams.set('section', 'eligibility');
    detailUrl.searchParams.set('return_to', `${window.location.pathname}${window.location.search}`);

    return `${detailUrl.pathname}${detailUrl.search}`;
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);
    const usesQueuePath = Object.prototype.hasOwnProperty.call(pathQueueMap, pathSection);

    if (usesQueuePath || activeQueue.value === 'mine') nextUrl.searchParams.delete('queue');
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
        const response = await window.axios.get('/provider/workspaces/reviews/data', {
            params: {
                queue: activeQueue.value,
                search: searchQuery.value.trim() || undefined,
                program_id: selectedProgram.value || undefined,
                page,
            },
        });
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        nextReview.value = response.data.next_review;
        programs.value = response.data.programs ?? [];
        applications.value = response.data.applications ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
        syncUrl();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the application verification queue.';
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading application verification" message="Preparing your current review work." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Application verification is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader
                        role-key="reviews"
                        :title="activeSection.label"
                        :description="activeSection.description"
                        icon="fa-solid fa-magnifying-glass-chart"
                        :show-role-guide="false"
                    />

                    <nav class="mt-4 grid grid-cols-4 border border-slate-300 bg-white" aria-label="Application review pages">
                        <a v-for="section in queueSections" :key="section.key" :href="section.href" :aria-current="section.key === activeQueue ? 'page' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-4 last:border-r-0', section.key === activeQueue ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                            <i :class="['fa-solid', section.icon, section.key === activeQueue ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>
                            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ section.shortLabel }}</span><span :class="['mt-0.5 block truncate text-[0.68rem]', section.key === activeQueue ? 'text-slate-300' : 'text-slate-500']">{{ section.count }} record{{ section.count === 1 ? '' : 's' }}</span></span>
                        </a>
                    </nav>

                    <section v-if="activeQueue === 'mine' && nextReview" class="mt-3 border border-slate-300 border-l-4 border-l-amber-500 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex items-center gap-4 px-5 py-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.64rem] font-black uppercase tracking-[0.16em] text-amber-700">Review next</p>
                                <h2 class="mt-0.5 truncate text-base font-bold text-slate-950">{{ nextReview.applicant.name }}</h2>
                                <p class="mt-0.5 truncate text-sm text-slate-500">{{ nextReview.program.title }} / {{ nextReview.evidence.label }}</p>
                            </div>
                            <a :href="reviewDetailUrl(nextReview.detail_url)" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Open review<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-3 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                            <div>
                                <h2 class="text-base font-bold text-slate-950">{{ pagination.total }} application{{ pagination.total === 1 ? '' : 's' }}</h2>
                                <p v-if="!isHistory && summary.oldest_wait_days" class="mt-0.5 text-xs text-slate-500">Oldest pending application: {{ summary.oldest_wait_days }} days</p>
                                <p v-else class="mt-0.5 text-xs text-slate-500">{{ isHistory ? 'Most recently reviewed records appear first.' : 'Only records from this queue are shown.' }}</p>
                            </div>
                            <span v-if="isRefreshing" class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-circle-notch mr-1.5 animate-spin" aria-hidden="true"></i>Updating</span>
                        </header>

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3 grid-cols-[minmax(18rem,1fr)_minmax(15rem,.55fr)]">
                            <label class="relative block"><span class="sr-only">Search applicants</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchQuery" type="search" placeholder="Search applicant or program" class="w-full border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            <label><span class="sr-only">Filter by program</span><select v-model="selectedProgram" class="w-full border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"><option value="">All assigned programs</option><option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option></select></label>
                        </div>

                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                        <div v-if="applications.length" class="portal-table-scroll">
                            <table class="portal-data-table min-w-[66rem] table-fixed">
                                <caption class="sr-only">{{ activeSection.label }}</caption>
                                <colgroup><col class="w-[32%]"><col class="w-[25%]"><col class="w-[25%]"><col class="w-[18%]"></colgroup>
                                <thead><tr><th scope="col">Applicant and program</th><th scope="col">Evidence</th><th scope="col">{{ isHistory ? 'Review record' : 'Review context' }}</th><th scope="col">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="application in applications" :key="application.id">
                                        <td><div class="flex min-w-0 items-start gap-3"><ProviderApplicantPhoto :src="application.applicant.profile_photo_url" :name="application.applicant.name" /><div class="min-w-0"><p class="truncate font-bold text-slate-950">{{ application.applicant.name }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ application.program.title }}</p><p v-if="application.applicant.education" class="mt-1 truncate text-xs text-slate-500">{{ application.applicant.education }}</p></div></div></td>
                                        <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', evidenceClass(application.evidence.state)]"><i :class="evidenceIcon(application.evidence.state)" aria-hidden="true"></i>{{ application.evidence.label }}</span><p class="mt-1.5 text-xs text-slate-500">{{ application.evidence.detail }}</p></td>
                                        <td v-if="isHistory"><p class="font-semibold text-slate-800"><i class="fa-solid fa-circle-check mr-1.5 text-xs text-emerald-600" aria-hidden="true"></i>Reviewed</p><p class="mt-1 text-xs text-slate-500">{{ application.reviewed_at || 'Review date unavailable' }}</p></td>
                                        <td v-else><p class="font-semibold text-slate-800"><i class="fa-solid fa-chart-simple mr-1.5 text-xs text-slate-400" aria-hidden="true"></i>{{ application.eligibility_score === null ? 'Eligibility check needed' : `${application.eligibility_score}% profile fit` }}</p><p :class="['mt-1 text-xs font-semibold', waitClass(application.waiting_days)]"><i class="fa-regular fa-clock mr-1.5" aria-hidden="true"></i>{{ waitLabel(application.waiting_days) }}</p></td>
                                        <td><a :href="reviewDetailUrl(application.detail_url)" class="inline-flex items-center border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`${isHistory ? 'Open record for' : 'Review'} ${application.applicant.name}`">{{ isHistory ? 'Open record' : 'Review' }}<i class="fa-solid fa-arrow-right ml-2 text-[9px]" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <i :class="['fa-solid text-2xl text-slate-300', isHistory ? 'fa-clock-rotate-left' : 'fa-clipboard-check']" aria-hidden="true"></i>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No applications on this page</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ searchQuery || selectedProgram ? 'Clear the filters to check the full queue.' : 'New records will appear here when they reach this review state.' }}</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="applications" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
