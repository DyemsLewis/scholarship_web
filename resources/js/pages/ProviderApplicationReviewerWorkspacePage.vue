<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

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
        returned: 'bg-sky-100 text-sky-800',
        updated: 'bg-sky-100 text-sky-800',
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
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading application verification...
                </div>

                <div v-else-if="errorMessage && !workspace" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-4 border-l-4 border-slate-950 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-start gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                                    <i class="fa-solid fa-magnifying-glass-chart"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-amber-700">{{ workspace?.role }}</p>
                                    <h1 class="mt-1 font-display text-2xl font-bold text-slate-950">Application verification</h1>
                                    <p class="mt-1 text-sm text-slate-600">Confirm applicant details, eligibility, and evidence.</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 text-xs font-semibold text-slate-600">
                                <span class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2"><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold text-slate-600 sm:px-6">
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace?.organization_name }}</span>
                            <span><i class="fa-solid fa-user-check mr-2 text-slate-400"></i>{{ workspace?.staff_name }}</span>
                            <span v-if="summary.oldest_wait_days"><i class="fa-regular fa-clock mr-2 text-slate-400"></i>Oldest: {{ summary.oldest_wait_days }} days</span>
                        </div>
                    </header>

                    <section v-if="nextReview" class="mt-4 overflow-hidden rounded-lg border border-amber-300 bg-white shadow-sm">
                        <div class="grid sm:grid-cols-[7rem_minmax(0,1fr)_auto] sm:items-stretch">
                            <div class="flex items-center justify-center bg-amber-300 px-4 py-3 text-slate-950 sm:py-5">
                                <div class="text-center">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.18em]">Review next</p>
                                    <i class="fa-solid fa-arrow-right mt-2 text-lg"></i>
                                </div>
                            </div>
                            <div class="flex min-w-0 items-center gap-3 px-5 py-4 sm:px-6">
                                <img v-if="nextReview.applicant.profile_photo_url" :src="nextReview.applicant.profile_photo_url" :alt="nextReview.applicant.name" class="h-11 w-11 shrink-0 rounded-md object-cover">
                                <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-sm font-black text-slate-600">{{ applicantInitials(nextReview.applicant.name) }}</span>
                                <div class="min-w-0">
                                    <h2 class="truncate text-base font-bold text-slate-950">{{ nextReview.applicant.name }}</h2>
                                    <p class="mt-1 truncate text-sm text-slate-600">{{ nextReview.program.title }} · {{ nextReview.evidence.label }}</p>
                                </div>
                            </div>
                            <div class="flex items-center px-5 pb-4 sm:px-6 sm:py-4">
                                <a :href="nextReview.detail_url" class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto">
                                    Open review<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section v-else class="mt-4 flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 sm:px-6">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-200 text-emerald-900"><i class="fa-solid fa-check"></i></span>
                        <div>
                            <h2 class="text-sm font-bold text-emerald-950">Your verification queue is clear</h2>
                            <p class="mt-0.5 text-sm text-emerald-800">New or reassigned applications will appear here.</p>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                                <div>
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Verification queue</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">Applicants ready for screening</h2>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-[minmax(15rem,1fr)_minmax(12rem,.7fr)] xl:w-[38rem]">
                                    <label class="relative block">
                                        <span class="sr-only">Search applicants</span>
                                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                        <input v-model="searchQuery" type="search" placeholder="Search applicant or program" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
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

                            <div class="mt-4 flex items-center gap-2 overflow-x-auto pb-1" aria-label="Review queue filters">
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
                            <div class="hidden grid-cols-[minmax(15rem,1.3fr)_minmax(11rem,.85fr)_8rem_9rem_minmax(9rem,.7fr)_3rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Applicant</span>
                                <span>Evidence</span>
                                <span>Eligibility</span>
                                <span>Waiting</span>
                                <span>Assignment</span>
                                <span class="sr-only">Action</span>
                            </div>

                            <article v-for="application in applications" :key="application.id" class="grid gap-4 px-5 py-4 transition hover:bg-slate-50 sm:px-6 xl:grid-cols-[minmax(15rem,1.3fr)_minmax(11rem,.85fr)_8rem_9rem_minmax(9rem,.7fr)_3rem] xl:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <img v-if="application.applicant.profile_photo_url" :src="application.applicant.profile_photo_url" :alt="application.applicant.name" class="h-11 w-11 shrink-0 rounded-md object-cover">
                                    <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-100 text-xs font-black text-slate-600">{{ applicantInitials(application.applicant.name) }}</span>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-bold text-slate-950">{{ application.applicant.name }}</h3>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ application.program.title }}</p>
                                    </div>
                                </div>

                                <div>
                                    <span :class="['inline-flex rounded-md px-2.5 py-1.5 text-[0.67rem] font-black uppercase tracking-wide', evidenceClass(application.evidence.state)]">{{ application.evidence.label }}</span>
                                    <p class="mt-1.5 text-xs text-slate-500">{{ application.evidence.detail }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-black text-slate-950">{{ application.eligibility_score === null ? 'Review' : `${application.eligibility_score}%` }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Profile fit</p>
                                </div>

                                <div>
                                    <p :class="['text-sm font-bold', waitClass(application.waiting_days)]">{{ waitLabel(application.waiting_days) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ application.submitted_at }}</p>
                                </div>

                                <div>
                                    <p :class="['text-sm font-bold', assignmentClass(application.assignment.state)]">{{ application.assignment.label }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ application.applicant.verification_label }}</p>
                                </div>

                                <a :href="application.detail_url" class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 text-slate-700 transition hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Review ${application.applicant.name}`" title="Open review">
                                    <i class="fa-solid fa-arrow-right text-xs"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-clipboard-check"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No applications in this queue</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another assignment filter, program, or search.</p>
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
                            <h2 class="text-sm font-bold text-slate-950">Verification handoff</h2>
                            <p class="mt-0.5 text-sm text-slate-600">Applications that pass screening move to the selection team for activities and results.</p>
                        </div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
