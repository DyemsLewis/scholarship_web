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
const summary = ref({ mine: 0, unassigned: 0, team: 0, assigned_elsewhere: 0, oldest_wait_days: 0 });
const nextReview = ref(null);
const programs = ref([]);
const applications = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: 0, to: 0 });
const queueOptions = ['mine', 'unassigned', 'all'];
const url = new URL(window.location.href);
const requestedQueue = url.searchParams.get('queue');
const activeQueue = ref(queueOptions.includes(requestedQueue) ? requestedQueue : 'mine');
const selectedProgram = ref(url.searchParams.get('program_id') ?? '');
const searchQuery = ref('');
let searchTimer = null;

const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected'
    ? 'Assigned programs only'
    : 'All organization programs');
const queueTabs = computed(() => [
    { key: 'mine', label: 'Assigned to me', count: Number(summary.value.mine ?? 0) },
    { key: 'unassigned', label: 'Unassigned', count: Number(summary.value.unassigned ?? 0) },
    { key: 'all', label: 'Team queue', count: Number(summary.value.team ?? 0) },
]);
const activeQueueTab = computed(() => queueTabs.value.find((tab) => tab.key === activeQueue.value) ?? queueTabs.value[0]);

function applicantInitials(name) {
    return String(name ?? 'Applicant')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function evidenceClass(state) {
    return {
        ready: 'bg-emerald-100 text-emerald-800',
        not_required: 'bg-slate-100 text-slate-700',
        returned: 'bg-slate-100 text-slate-700',
        updated: 'bg-slate-100 text-slate-700',
        replacement: 'bg-rose-100 text-rose-800',
        missing: 'bg-amber-100 text-amber-800',
        pending: 'bg-amber-100 text-amber-800',
    }[state] ?? 'bg-slate-100 text-slate-700';
}

function assignmentClass(state) {
    return {
        mine: 'text-emerald-700',
        unassigned: 'text-amber-700',
        team: 'text-slate-600',
    }[state] ?? 'text-slate-600';
}

function waitLabel(days) {
    if (days === 0) return 'Submitted today';
    return `${days} day${days === 1 ? '' : 's'} waiting`;
}

function waitClass(days) {
    if (days >= 7) return 'text-rose-700';
    if (days >= 3) return 'text-amber-700';
    return 'text-slate-700';
}

function syncUrl() {
    const nextUrl = new URL(window.location.href);

    if (activeQueue.value === 'mine') nextUrl.searchParams.delete('queue');
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
                <ProviderWorkspaceState v-if="isLoading" title="Loading application verification" message="Preparing your assigned review queue." />

                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Application verification is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader role-key="reviews" title="Application verification" description="Confirm applicant details, eligibility, and evidence before selection begins." icon="fa-solid fa-magnifying-glass-chart">
                        <template #meta>
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace?.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextReview" class="mt-3 overflow-hidden rounded border border-amber-300 bg-white shadow-sm">
                        <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-300 text-slate-950"><i class="fa-solid fa-arrow-right text-sm"></i></span>
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <img v-if="nextReview.applicant.profile_photo_url" :src="nextReview.applicant.profile_photo_url" :alt="nextReview.applicant.name" class="h-9 w-9 shrink-0 object-cover">
                                <span v-else class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(nextReview.applicant.name) }}</span>
                                <div class="min-w-0">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Review next</p>
                                    <h2 class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ nextReview.applicant.name }}</h2>
                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ nextReview.program.title }} · {{ nextReview.evidence.label }}</p>
                                </div>
                            </div>
                            <div class="shrink-0">
                                <a :href="nextReview.detail_url" class="inline-flex items-center bg-slate-950 px-3.5 py-2 text-xs font-bold text-white transition hover:bg-slate-800">
                                    Open review<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-3 flex items-center gap-3 rounded border border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-slate-950">Your verification queue is clear</h2>
                            <p class="mt-0.5 text-xs text-slate-500">New or reassigned applications will appear here.</p>
                        </div>
                    </section>

                    <section class="mt-3 overflow-hidden rounded border border-slate-300 bg-white shadow-sm">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5">
                            <div>
                                <p class="text-[0.62rem] font-black uppercase tracking-[0.16em] text-amber-700">Review queue</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-950">{{ activeQueueTab.label }}</h2>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-slate-950">{{ pagination.total }} application{{ pagination.total === 1 ? '' : 's' }}</p>
                                <p v-if="summary.oldest_wait_days" class="mt-0.5 text-xs text-slate-500">Oldest waiting {{ summary.oldest_wait_days }} days</p>
                            </div>
                        </header>

                        <ProviderQueueTabs :tabs="queueTabs" :active-key="activeQueue" :busy="isRefreshing" aria-label="Review queue filters" @select="selectQueue" />

                        <div class="grid gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:grid-cols-[minmax(16rem,1fr)_minmax(13rem,.65fr)] sm:px-5">
                            <label class="relative block">
                                <span class="sr-only">Search applicants</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                <input v-model="searchQuery" type="search" placeholder="Search applicant or program" class="w-full rounded border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
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
                            <div class="hidden grid-cols-[minmax(16rem,1.25fr)_minmax(17rem,1fr)_minmax(13rem,.7fr)_7rem] gap-5 bg-slate-50 px-5 py-3 text-[0.65rem] font-black uppercase tracking-[0.14em] text-slate-500 xl:grid">
                                <span>Applicant</span>
                                <span>Review readiness</span>
                                <span>Queue status</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="application in applications" :key="application.id" class="grid gap-4 px-4 py-3.5 transition hover:bg-slate-50 sm:px-5 xl:grid-cols-[minmax(16rem,1.25fr)_minmax(17rem,1fr)_minmax(13rem,.7fr)_7rem] xl:items-center xl:gap-5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="application.applicant.name" class="h-10 w-10 shrink-0 object-cover">
                                    <span v-else class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(application.applicant.name) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</h3>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ application.program.title }}</p>
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span :class="['inline-flex px-2 py-1 text-[0.62rem] font-black uppercase tracking-wide', evidenceClass(application.evidence.state)]">{{ application.evidence.label }}</span>
                                        <span class="text-xs font-bold text-slate-700">{{ application.eligibility_score === null ? 'Fit needs review' : `${application.eligibility_score}% profile fit` }}</span>
                                    </div>
                                    <p class="mt-1 line-clamp-1 text-xs text-slate-500">{{ application.applicant.verification_label }} · {{ application.evidence.detail }}</p>
                                </div>

                                <div>
                                    <p :class="['text-sm font-bold', assignmentClass(application.assignment.state)]">{{ application.assignment.label }}</p>
                                    <p :class="['mt-0.5 text-xs font-semibold', waitClass(application.waiting_days)]">{{ waitLabel(application.waiting_days) }}</p>
                                </div>

                                <a :href="application.detail_url" class="inline-flex items-center justify-center gap-2 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:border-slate-950 hover:bg-slate-950 hover:text-white xl:justify-self-end" :aria-label="`Review ${application.applicant.name}`">
                                    Review<i class="fa-solid fa-arrow-right text-[9px]"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-clipboard-check"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No applications in this queue</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another assignment filter, program, or search.</p>
                        </div>

                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="applications" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
