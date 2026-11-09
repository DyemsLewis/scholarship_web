<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableLoadError from '../../../components/StaffTableLoadError.vue';
import { createStaffQueueState } from '../../../support/staffQueueState.js';

const filterFromPath = {
    '/provider/programs/drafts': 'drafts',
    '/provider/programs/review': 'review',
    '/provider/programs/published': 'published',
    '/provider/programs/closed': 'closed',
};
const currentPath = (window.portalPathname?.() ?? window.location.pathname).replace(/\/$/, '');
const isMonitoringDirectory = currentPath === '/provider/monitoring/plans';
const filterCopy = {
    all: {
        title: 'Programs',
        description: 'Track each scholarship from draft through publication and closure.',
    },
    drafts: {
        title: 'Drafts & changes',
        description: 'Continue unfinished programs and address requested changes.',
    },
    review: {
        title: 'Under review',
        description: 'See programs currently awaiting an administrator decision.',
    },
    published: {
        title: 'Published programs',
        description: 'Review programs currently visible to applicants.',
    },
    closed: {
        title: 'Closed programs',
        description: 'Reference programs that are no longer accepting applications.',
    },
};

const activeFilter = filterFromPath[currentPath] ?? 'all';
const pageCopy = isMonitoringDirectory ? {
    title: 'Monitoring plans',
    description: "Set each program's review schedule, requirements, and check-in periods.",
} : filterCopy[activeFilter];
const programs = ref([]);
const summary = ref({ total: 0, needs_action: 0, under_review: 0, published: 0, active: 0, draft: 0, not_set: 0 });
const context = ref({ organization_name: '', access_scope: 'all' });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
const queueState = createStaffQueueState(window, ['search','sort']);
const search = ref(queueState.get('search') ?? '');
const savedSort = queueState.get('sort');
const sort = ref(['updated', 'deadline', 'title'].includes(savedSort) ? savedSort : 'updated');
const isLoading = ref(true);
const errorMessage = ref('');
let requestVersion = 0;
let requestedParams;

const rangeLabel = computed(() => {
    if (isLoading.value) return 'Updating results...';
    if (errorMessage.value) return 'Results unavailable';
    if (!pagination.value.total) {
        return '0 programs';
    }

    return `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}`;
});

function statusClass(tone) {
    return {
        positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        attention: 'border-amber-200 bg-amber-50 text-amber-800',
        waiting: 'border-sky-200 bg-sky-50 text-sky-800',
        neutral: 'border-slate-200 bg-slate-100 text-slate-700',
    }[tone] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

function applicationLabel(program) {
    const received = program.applications.received;
    const limit = program.applications.limit;

    return limit === null ? `${received} received` : `${received} of ${limit}`;
}

function programActionLabel(program) {
    if (!isMonitoringDirectory) return 'Open';
    const plan = program.monitoring;
    if (plan.status === 'active') return plan.can_publish_check_in ? 'Schedule' : 'Open plan';
    return plan.can_edit_plan ? 'Set up' : 'View plan';
}

async function loadPrograms(page = 1, params = {
    filter: activeFilter,
    workspace: isMonitoringDirectory ? 'monitoring' : 'programs',
    search: search.value.trim() || undefined,
    sort: sort.value,
    page,
}) {
    requestedParams = params;
    const version = ++requestVersion;
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await queueState.load((query) => window.axios.get('/provider/programs/data', { params: query }), params, () => version === requestVersion);

        if (version !== requestVersion) return;

        programs.value = response.data.programs ?? [];
        summary.value = response.data.summary ?? summary.value;
        context.value = response.data.context ?? context.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        if (version !== requestVersion) return;

        errorMessage.value = error.response?.data?.message
            || 'Programs could not be loaded. Please try again.';
    } finally {
        if (version === requestVersion) {
            isLoading.value = false;
        }
    }
}

function submitSearch() {
    loadPrograms(1);
}

function clearSearch() {
    search.value = '';
    loadPrograms(1);
}

function retryPrograms() {
    if (errorMessage.value && !isLoading.value) return loadPrograms(requestedParams.page, requestedParams);
}

onMounted(() => loadPrograms(queueState.page));
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="program-directory-title">
            <div class="provider-program-workspace mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-6 py-5 shadow-sm">
                    <div class="flex items-center justify-between gap-8">
                        <div class="flex min-w-0 flex-1 items-center gap-4">
                            <span class="program-icon grid h-11 w-11 shrink-0 place-items-center bg-slate-950 text-amber-300" aria-hidden="true">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="program-label font-bold uppercase text-amber-700">{{ isMonitoringDirectory ? 'Recipient monitoring' : 'Program workspace' }}</p>
                                <h1 id="program-directory-title" class="program-page-title mt-1 font-display font-bold tracking-tight text-slate-950">{{ pageCopy.title }}</h1>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ pageCopy.description }}</p>
                            </div>
                        </div>
                        <div class="program-page-actions">
                            <div v-if="context.organization_name" class="min-w-0 max-w-56 border-l border-slate-200 pl-5 text-right">
                                <p class="program-label font-bold uppercase text-slate-600">Organization</p>
                                <p class="program-text mt-1 text-sm font-semibold text-slate-800">{{ context.organization_name }}</p>
                                <p v-if="context.access_scope === 'assigned'" class="mt-1 text-xs text-slate-500">Assigned programs only</p>
                            </div>
                            <a v-if="!isMonitoringDirectory" href="/provider/programs/create" class="program-action inline-flex h-11 shrink-0 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">
                                <i class="fa-solid fa-plus text-xs text-amber-300" aria-hidden="true"></i>
                                Create program
                            </a>
                        </div>
                    </div>
                </header>

                <section class="program-summary mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" :aria-label="isMonitoringDirectory ? 'Monitoring plan summary' : 'Program summary'" :aria-busy="isLoading">
                    <div class="flex items-center gap-4 border-r border-slate-200 px-5 py-4">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600" aria-hidden="true"><i :class="['fa-solid text-sm', isMonitoringDirectory ? 'fa-clipboard-list' : 'fa-layer-group']"></i></span>
                        <div class="min-w-0"><p class="program-label font-bold uppercase text-slate-600">{{ isMonitoringDirectory ? 'Programs to monitor' : 'All programs' }}</p><p class="mt-1 text-2xl font-bold text-slate-950">{{ isLoading ? '...' : summary.total }}</p></div>
                    </div>
                    <div class="flex items-center gap-4 border-r border-slate-200 px-5 py-4">
                        <span :class="['grid h-9 w-9 shrink-0 place-items-center', (isMonitoringDirectory ? summary.not_set : summary.needs_action) ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600']" aria-hidden="true"><i :class="['fa-solid text-sm', (isMonitoringDirectory ? summary.not_set : summary.needs_action) ? 'fa-bell' : 'fa-check']"></i></span>
                        <div class="min-w-0"><p class="program-label font-bold uppercase text-slate-600">{{ isMonitoringDirectory ? 'Not set up' : 'Needs action' }}</p><p :class="['mt-1 text-2xl font-bold', (isMonitoringDirectory ? summary.not_set : summary.needs_action) ? 'text-amber-700' : 'text-slate-950']">{{ isLoading ? '...' : isMonitoringDirectory ? summary.not_set : summary.needs_action }}</p></div>
                    </div>
                    <div class="flex items-center gap-4 border-r border-slate-200 px-5 py-4">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left text-sm"></i></span>
                        <div class="min-w-0"><p class="program-label font-bold uppercase text-slate-600">{{ isMonitoringDirectory ? 'Draft plans' : 'Under review' }}</p><p class="mt-1 text-2xl font-bold text-slate-950">{{ isLoading ? '...' : isMonitoringDirectory ? summary.draft : summary.under_review }}</p></div>
                    </div>
                    <div class="flex items-center gap-4 px-5 py-4">
                        <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600" aria-hidden="true"><i class="fa-solid fa-circle-check text-sm"></i></span>
                        <div class="min-w-0"><p class="program-label font-bold uppercase text-slate-600">{{ isMonitoringDirectory ? 'Active plans' : 'Published' }}</p><p class="mt-1 text-2xl font-bold text-slate-950">{{ isLoading ? '...' : isMonitoringDirectory ? summary.active : summary.published }}</p></div>
                    </div>
                </section>

                <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Program directory">
                    <form class="flex items-end gap-3 border-b border-slate-200 px-5 py-4" @submit.prevent="submitSearch">
                        <label class="min-w-0 flex-1">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-700">{{ isMonitoringDirectory ? 'Find a program' : 'Search programs' }}</span>
                            <span class="flex h-11 items-center border border-slate-300 bg-white px-3 focus-within:border-slate-700">
                                <i class="fa-solid fa-magnifying-glass mr-3 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="search"
                                    type="search"
                                    class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 outline-none ring-0 placeholder:text-slate-400 focus:ring-0"
                                    placeholder="Search by title, category, or cycle"
                                >
                            </span>
                        </label>
                        <label class="w-56">
                            <span class="mb-1.5 block text-xs font-semibold text-slate-700">Sort by</span>
                            <select v-model="sort" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 focus:border-slate-700 focus:ring-slate-700" @change="loadPrograms(1)">
                                <option value="updated">Recently updated</option>
                                <option value="deadline">Nearest deadline</option>
                                <option value="title">Program title</option>
                            </select>
                        </label>
                        <button type="submit" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800">
                            Search
                        </button>
                        <button v-if="search" type="button" class="h-11 border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="clearSearch">
                            Clear
                        </button>
                    </form>

                    <div class="staff-table-viewport" :aria-busy="isLoading">
                        <StaffTableLoadError v-if="errorMessage" :message="errorMessage" @retry="retryPrograms" />
                        <table v-else class="program-directory-table w-full min-w-[56rem] table-fixed border-collapse text-left">
                            <colgroup><col><col class="w-32"><col class="w-32"><col class="w-36"><col class="w-[7.5rem]"><col class="w-36"></colgroup>
                            <caption class="sr-only">Programs in the selected lifecycle section</caption>
                            <thead class="bg-slate-50">
                                <tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-wide text-slate-600">
                                    <th class="px-5 py-3">Program</th>
                                    <th class="px-5 py-3">{{ isMonitoringDirectory ? 'Plan status' : 'Status' }}</th>
                                    <th class="px-5 py-3">{{ isMonitoringDirectory ? 'Requirements' : 'Applications' }}</th>
                                    <th class="px-5 py-3">{{ isMonitoringDirectory ? 'Check-ins' : 'Deadline' }}</th>
                                    <th class="px-5 py-3">Updated</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-if="isLoading">
                                    <td colspan="6" class="px-5 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-circle-notch animate-spin text-sm"></i></span><p class="mt-2 text-sm font-semibold text-slate-500">Loading programs...</p></td>
                                </tr>
                                <tr v-else-if="!programs.length && !errorMessage">
                                    <td colspan="6" class="px-5 py-12 text-center">
                                        <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-folder-open text-sm"></i></span>
                                        <p class="mt-2 font-semibold text-slate-800">No programs found</p>
                                        <p class="mt-1 text-sm text-slate-500">Try another search or open a different program section.</p>
                                    </td>
                                </tr>
                                <tr v-else v-for="program in programs" :key="program.id" class="align-middle hover:bg-slate-50/70">
                                    <td class="px-5 py-4">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <StaffTableIdentity kind="program" :src="program.logo_url" class="h-11 w-11" />
                                            <div class="min-w-0">
                                                <a :href="isMonitoringDirectory ? program.monitoring.plan_url : queueState.link(program.record_url)" class="program-text block text-sm font-semibold text-slate-950 hover:text-amber-700">{{ program.title }}</a>
                                                <p v-if="program.cycle || (isMonitoringDirectory && program.monitoring.frequency_label)" class="program-text mt-1 text-xs text-slate-600">
                                                    {{ program.cycle }}<template v-if="isMonitoringDirectory && program.monitoring.frequency_label"><template v-if="program.cycle"> &middot; </template>{{ program.monitoring.frequency_label }}</template>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span :class="['program-status inline-flex border px-2.5 py-1 font-bold uppercase tracking-wide', statusClass(isMonitoringDirectory ? program.monitoring.status_tone : program.status.tone)]">
                                            {{ isMonitoringDirectory ? program.monitoring.status_label : program.status.label }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-sm font-semibold text-slate-800">{{ isMonitoringDirectory ? program.monitoring.requirements_count : applicationLabel(program) }}</td>
                                    <td class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-800">{{ isMonitoringDirectory ? `${program.monitoring.check_ins_count} published` : program.application_period.deadline.display }}</p>
                                        <p v-if="!isMonitoringDirectory && program.application_period.deadline.state === 'today'" class="mt-1 text-xs font-semibold text-amber-800">Due today</p>
                                        <p v-else-if="!isMonitoringDirectory && program.application_period.deadline.state === 'passed'" class="mt-1 text-xs text-slate-500">Past deadline</p>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-slate-600">{{ program.updated_display || 'Not available' }}</td>
                                    <td class="px-4 py-4 text-right">
                                        <a :href="isMonitoringDirectory ? program.monitoring.plan_url : queueState.link(program.record_url)" class="staff-row-action staff-row-action--primary program-action h-10 px-3 text-sm" :aria-label="`${programActionLabel(program)}: ${program.title}`">
                                            {{ programActionLabel(program) }}
                                            <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <footer class="program-form-footer flex items-center justify-between border-t border-slate-200 px-5 py-3">
                        <p class="text-xs font-semibold text-slate-500">{{ isLoading || errorMessage ? rangeLabel : `Showing ${rangeLabel}` }}</p>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="h-9 border border-slate-300 px-3 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="pagination.current_page <= 1 || isLoading || Boolean(errorMessage)"
                                @click="loadPrograms(pagination.current_page - 1)"
                            >
                                Previous
                            </button>
                            <span class="px-2 text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
                            <button
                                type="button"
                                class="h-9 border border-slate-300 px-3 text-xs font-bold text-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                                :disabled="pagination.current_page >= pagination.last_page || isLoading || Boolean(errorMessage)"
                                @click="loadPrograms(pagination.current_page + 1)"
                            >
                                Next
                            </button>
                        </div>
                    </footer>
                </section>
            </div>
        </section>
    </main>
</template>
