<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({});
const phases = ref({ drafts: 0, review: 0, published: 0, closed: 0 });
const nextAction = ref(null);
const attentionProgramIds = ref([]);
const programs = ref([]);
const searchQuery = ref('');
const allowedPhases = ['all', 'attention', 'drafts', 'review', 'published', 'closed'];
const requestedPhase = new URLSearchParams(window.location.search).get('phase');
const activePhase = ref(allowedPhases.includes(requestedPhase) ? requestedPhase : 'all');

const phaseOptions = computed(() => [
    { key: 'all', label: 'All programs', count: Number(summary.value.total ?? 0) },
    { key: 'attention', label: 'Needs attention', count: Number(summary.value.attention ?? 0) },
    { key: 'drafts', label: 'Draft setup', count: Number(phases.value.drafts ?? 0) },
    { key: 'review', label: 'Admin review', count: Number(phases.value.review ?? 0) },
    { key: 'published', label: 'Published', count: Number(phases.value.published ?? 0) },
    { key: 'closed', label: 'Closed', count: Number(phases.value.closed ?? 0) },
]);
const filteredPrograms = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return programs.value.filter((program) => {
        const phaseMatches = activePhase.value === 'all'
            || (activePhase.value === 'attention'
                ? attentionProgramIds.value.includes(program.id)
                : program.phase === activePhase.value);
        const searchText = [program.title, program.category, program.program_cycle]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();

        return phaseMatches && (!query || searchText.includes(query));
    });
});
const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected'
    ? 'Assigned programs only'
    : 'All organization programs');

function selectPhase(phase) {
    activePhase.value = phase;
    const url = new URL(window.location.href);

    if (phase === 'all') {
        url.searchParams.delete('phase');
    } else {
        url.searchParams.set('phase', phase);
    }

    window.history.replaceState({}, '', url);
}

function statusClass(status) {
    return {
        published: 'bg-emerald-100 text-emerald-800',
        pending_review: 'bg-sky-100 text-sky-800',
        rejected: 'bg-rose-100 text-rose-800',
        closed: 'bg-slate-200 text-slate-700',
    }[status] ?? 'bg-amber-100 text-amber-800';
}

function deadlineLabel(program) {
    if (!program.deadline) {
        return 'No deadline set';
    }

    if (program.deadline_state === 'passed') {
        return `Ended ${program.deadline}`;
    }

    if (program.deadline_days === 0) {
        return 'Deadline today';
    }

    if (program.deadline_state === 'due_soon') {
        return `${program.deadline_days} day${program.deadline_days === 1 ? '' : 's'} left`;
    }

    return program.deadline;
}

function deadlineClass(program) {
    if (program.deadline_state === 'passed') return 'text-rose-700';
    if (program.deadline_state === 'due_soon') return 'text-amber-700';

    return 'text-slate-700';
}

function capacityLabel(program) {
    if (program.application_limit) {
        return `${program.applications_count} of ${program.application_limit}`;
    }

    return `${program.applications_count}`;
}

async function loadWorkspace() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/programs/data');
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? {};
        phases.value = response.data.phases ?? phases.value;
        nextAction.value = response.data.next_action;
        attentionProgramIds.value = response.data.attention_program_ids ?? [];
        programs.value = response.data.programs ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the program coordination workspace.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadWorkspace);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading program coordination...
                </div>

                <div v-else-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <header class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-[0_10px_28px_rgba(8,20,38,0.07)]">
                        <div class="flex flex-col gap-5 border-l-4 border-slate-950 px-5 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-start gap-4">
                                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                                    <i class="fa-solid fa-compass-drafting"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.2em] text-amber-700">{{ workspace?.role }}</p>
                                    <h1 class="mt-1 font-display text-2xl font-bold text-slate-950">Program coordination</h1>
                                    <p class="mt-1 text-sm text-slate-600">Build, publish, and maintain scholarship programs.</p>
                                </div>
                            </div>
                            <a href="/provider/programs/create" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                                <i class="fa-solid fa-plus mr-2 text-xs text-amber-300"></i>New program
                            </a>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold text-slate-600 sm:px-6">
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace?.organization_name }}</span>
                            <span><i class="fa-solid fa-user mr-2 text-slate-400"></i>{{ workspace?.staff_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                        </div>
                    </header>

                    <section v-if="nextAction" class="mt-4 overflow-hidden rounded-lg border border-amber-300 bg-white shadow-sm">
                        <div class="grid sm:grid-cols-[7rem_minmax(0,1fr)_auto] sm:items-stretch">
                            <div class="flex items-center justify-center bg-amber-300 px-4 py-3 text-slate-950 sm:py-5">
                                <div class="text-center">
                                    <p class="text-[0.62rem] font-black uppercase tracking-[0.18em]">Priority</p>
                                    <i class="fa-solid fa-arrow-right mt-2 text-lg"></i>
                                </div>
                            </div>
                            <div class="px-5 py-4 sm:px-6">
                                <h2 class="text-lg font-bold text-slate-950">{{ nextAction.title }}</h2>
                                <p class="mt-1 text-sm text-slate-600">{{ nextAction.description }}</p>
                            </div>
                            <div class="flex items-center px-5 pb-4 sm:px-6 sm:py-4">
                                <a :href="nextAction.href" class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto">
                                    {{ nextAction.label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300"></i>
                                </a>
                            </div>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between sm:px-6">
                            <div>
                                <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Program lifecycle</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Portfolio progress</h2>
                            </div>
                            <p class="mt-1 text-sm text-slate-500 sm:mt-0">{{ summary.total }} program{{ summary.total === 1 ? '' : 's' }} in your scope</p>
                        </div>
                        <div class="grid sm:grid-cols-4">
                            <button
                                v-for="(phase, index) in phaseOptions.slice(2)"
                                :key="phase.key"
                                type="button"
                                :class="[
                                    'group relative flex items-center gap-3 border-b border-slate-200 px-5 py-4 text-left transition last:border-b-0 hover:bg-slate-50 sm:border-b-0 sm:border-r sm:last:border-r-0',
                                    activePhase === phase.key ? 'bg-amber-50' : 'bg-white',
                                ]"
                                @click="selectPhase(phase.key)"
                            >
                                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-sm font-black', activePhase === phase.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-600']">{{ index + 1 }}</span>
                                <span>
                                    <span class="block text-xs font-bold text-slate-500">{{ phase.label }}</span>
                                    <span class="mt-0.5 block text-lg font-black text-slate-950">{{ phase.count }}</span>
                                </span>
                            </button>
                        </div>
                    </section>

                    <section class="mt-4 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div>
                                    <p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Work queue</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">Programs you can manage</h2>
                                </div>
                                <label class="relative block w-full lg:max-w-sm">
                                    <span class="sr-only">Search programs</span>
                                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                    <input v-model="searchQuery" type="search" placeholder="Search title, category, or cycle" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                </label>
                            </div>

                            <div class="mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="Program queue filters">
                                <button
                                    v-for="phase in phaseOptions"
                                    :key="phase.key"
                                    type="button"
                                    :class="[
                                        'shrink-0 rounded-md px-3 py-2 text-xs font-bold transition',
                                        activePhase === phase.key ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="selectPhase(phase.key)"
                                >
                                    {{ phase.label }} <span :class="['ml-1', activePhase === phase.key ? 'text-amber-300' : 'text-slate-400']">{{ phase.count }}</span>
                                </button>
                            </div>
                        </div>

                        <div v-if="filteredPrograms.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(17rem,1.4fr)_minmax(9rem,.65fr)_minmax(11rem,.75fr)_minmax(8rem,.6fr)_9rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid">
                                <span>Program</span>
                                <span>Phase</span>
                                <span>Timeline</span>
                                <span>Applications</span>
                                <span class="text-right">Action</span>
                            </div>

                            <article v-for="program in filteredPrograms" :key="program.id" class="grid gap-4 px-5 py-4 sm:px-6 xl:grid-cols-[minmax(17rem,1.4fr)_minmax(9rem,.65fr)_minmax(11rem,.75fr)_minmax(8rem,.6fr)_9rem] xl:items-center">
                                <div class="min-w-0">
                                    <div class="flex items-start gap-3">
                                        <span :class="['mt-1 h-2.5 w-2.5 shrink-0 rounded-full', attentionProgramIds.includes(program.id) ? 'bg-amber-400' : 'bg-slate-300']"></span>
                                        <div class="min-w-0">
                                            <h3 class="truncate text-sm font-bold text-slate-950">{{ program.title }}</h3>
                                            <p class="mt-1 truncate text-xs text-slate-500">{{ [program.category, program.program_cycle].filter(Boolean).join(' · ') || 'Program details in progress' }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <span :class="['inline-flex rounded-md px-2.5 py-1.5 text-[0.68rem] font-black uppercase tracking-wide', statusClass(program.status)]">{{ program.status_label }}</span>
                                </div>

                                <div>
                                    <p :class="['text-sm font-bold', deadlineClass(program)]">{{ deadlineLabel(program) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">Updated {{ program.updated_at }}</p>
                                </div>

                                <div>
                                    <p class="text-sm font-black text-slate-950">{{ capacityLabel(program) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ program.application_limit ? 'received / limit' : 'received' }}</p>
                                </div>

                                <a :href="program.action_href" class="rounded-md border border-slate-300 px-3.5 py-2 text-center text-xs font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50">
                                    {{ program.action_label }}
                                </a>
                            </article>
                        </div>

                        <div v-else class="px-6 py-12 text-center">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-folder-open"></i></span>
                            <h3 class="mt-3 text-sm font-bold text-slate-900">No programs in this view</h3>
                            <p class="mt-1 text-sm text-slate-500">Try another phase or clear the search.</p>
                        </div>
                    </section>

                    <section class="mt-4 flex flex-col gap-3 rounded-lg border border-slate-300 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white text-slate-700 shadow-sm"><i class="fa-solid fa-arrow-right-arrow-left"></i></span>
                            <div>
                                <h2 class="text-sm font-bold text-slate-950">Workflow handoff</h2>
                                <p class="mt-0.5 text-sm text-slate-600">After publication, application verification moves to the assigned reviewer.</p>
                            </div>
                        </div>
                        <a href="/provider/profile/details" class="shrink-0 text-sm font-bold text-slate-700 hover:text-slate-950">View organization <i class="fa-solid fa-arrow-right ml-1 text-xs"></i></a>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
