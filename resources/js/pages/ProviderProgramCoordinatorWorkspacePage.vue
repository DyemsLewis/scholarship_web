<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderPageHeader from '../components/ProviderPageHeader.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import ProviderWorkspaceState from '../components/ProviderWorkspaceState.vue';

const pathSection = window.location.pathname.split('/').filter(Boolean).at(-1);
const activeSection = ['drafts', 'review', 'published', 'closed'].includes(pathSection) ? pathSection : 'overview';
const isOverview = activeSection === 'overview';
const isLoading = ref(true);
const isRefreshing = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const summary = ref({ total: 0, attention: 0, applications: 0, published: 0 });
const phases = ref({ drafts: 0, review: 0, published: 0, closed: 0 });
const nextAction = ref(null);
const attentionProgramIds = ref([]);
const programs = ref([]);
const searchQuery = ref('');
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer = null;

const sectionMeta = computed(() => ({
    drafts: {
        eyebrow: 'Preparation queue',
        title: 'Drafts and requested changes',
        description: 'Continue unfinished programs and revise submissions returned by administrators.',
        emptyTitle: 'No drafts need work',
        emptyDescription: 'New drafts and requested revisions will appear here.',
    },
    review: {
        eyebrow: 'Submission queue',
        title: 'Programs in admin review',
        description: 'Track submitted programs while the platform review is in progress.',
        emptyTitle: 'Nothing is under review',
        emptyDescription: 'Submitted programs will appear here until an administrator responds.',
    },
    published: {
        eyebrow: 'Active portfolio',
        title: 'Published programs',
        description: 'Review public programs, application dates, and current intake.',
        emptyTitle: 'No published programs',
        emptyDescription: 'Approved programs will appear here after publication.',
    },
    closed: {
        eyebrow: 'Program history',
        title: 'Closed programs',
        description: 'Open completed program records without mixing them into active work.',
        emptyTitle: 'No closed programs',
        emptyDescription: 'Programs will move here after they are closed.',
    },
}[activeSection]));
const lifecycleLinks = computed(() => [
    { key: 'drafts', title: 'Drafts and changes', description: 'Programs still being prepared or returned for revision.', count: phases.value.drafts, href: '/provider/workspaces/programs/drafts', icon: 'fa-pen-ruler' },
    { key: 'review', title: 'Admin review', description: 'Submitted programs waiting for a platform decision.', count: phases.value.review, href: '/provider/workspaces/programs/review', icon: 'fa-shield-halved' },
    { key: 'published', title: 'Published programs', description: 'Programs currently visible to applicants.', count: phases.value.published, href: '/provider/workspaces/programs/published', icon: 'fa-bullhorn' },
    { key: 'closed', title: 'Closed programs', description: 'Completed or archived program records.', count: phases.value.closed, href: '/provider/workspaces/programs/closed', icon: 'fa-box-archive' },
]);
const accessLabel = computed(() => workspace.value?.program_access_mode === 'selected' ? 'Assigned programs' : 'All organization programs');

function statusClass(status) {
    return {
        published: 'bg-slate-900 text-white',
        pending_review: 'bg-slate-200 text-slate-700',
        rejected: 'bg-amber-100 text-amber-800',
        closed: 'bg-slate-200 text-slate-700',
    }[status] ?? 'bg-amber-100 text-amber-800';
}

function deadlineLabel(program) {
    if (!program.deadline) return 'No deadline set';
    if (program.deadline_state === 'passed') return `Ended ${program.deadline}`;
    if (program.deadline_days === 0) return 'Deadline today';
    if (program.deadline_state === 'due_soon') return `${program.deadline_days} day${program.deadline_days === 1 ? '' : 's'} left`;
    return program.deadline;
}

function deadlineClass(program) {
    if (program.deadline_state === 'passed') return 'text-amber-800';
    if (program.deadline_state === 'due_soon') return 'text-amber-700';
    return 'text-slate-700';
}

function capacityLabel(program) {
    return program.application_limit ? `${program.applications_count} of ${program.application_limit}` : `${program.applications_count}`;
}

async function loadWorkspace(page = 1, initial = false) {
    if (initial) isLoading.value = true;
    else isRefreshing.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/workspaces/programs/data', {
            params: {
                section: activeSection,
                search: !isOverview ? searchQuery.value.trim() || undefined : undefined,
                page,
            },
        });
        workspace.value = response.data.workspace;
        summary.value = response.data.summary ?? summary.value;
        phases.value = response.data.phases ?? phases.value;
        nextAction.value = response.data.next_action;
        attentionProgramIds.value = response.data.attention_program_ids ?? [];
        programs.value = response.data.programs ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load program coordination.';
    } finally {
        isLoading.value = false;
        isRefreshing.value = false;
    }
}

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
                <ProviderWorkspaceState v-if="isLoading" title="Loading program coordination" message="Preparing program lifecycle queues and current priorities." />
                <ProviderWorkspaceState v-else-if="errorMessage && !workspace" tone="error" title="Program coordination is unavailable" :message="errorMessage" />

                <template v-else>
                    <ProviderPageHeader
                        role-key="programs"
                        :title="isOverview ? 'Program coordination' : sectionMeta.title"
                        :description="isOverview ? 'See what needs attention, then open one focused queue.' : sectionMeta.description"
                        icon="fa-solid fa-compass-drafting"
                    >
                        <template #actions>
                            <div class="flex shrink-0 items-center gap-2">
                                <a v-if="!isOverview" href="/provider/workspaces/programs" class="rounded border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Overview</a>
                                <a href="/provider/programs/create" class="rounded bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-plus mr-2 text-xs text-amber-300"></i>New program</a>
                            </div>
                        </template>
                    </ProviderPageHeader>

                    <template v-if="isOverview">
                        <section v-if="nextAction" class="mt-4 overflow-hidden rounded-md border border-amber-300 bg-white shadow-sm">
                            <div class="grid sm:grid-cols-[5.5rem_minmax(0,1fr)_auto] sm:items-stretch">
                                <div class="flex items-center justify-center bg-amber-300 px-3 py-2.5 text-slate-950 sm:py-3"><div class="text-center"><p class="text-[0.6rem] font-black uppercase tracking-[0.16em]">Priority</p><i class="fa-solid fa-arrow-right mt-1.5 text-sm"></i></div></div>
                                <div class="px-4 py-3"><h2 class="text-base font-bold text-slate-950">{{ nextAction.title }}</h2><p class="mt-0.5 text-sm text-slate-600">{{ nextAction.description }}</p></div>
                                <div class="flex items-center px-4 pb-3 sm:py-3"><a :href="nextAction.href" class="w-full rounded bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800 sm:w-auto">{{ nextAction.label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300"></i></a></div>
                            </div>
                        </section>

                        <section class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                            <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div><p class="text-[0.68rem] font-black uppercase tracking-[0.18em] text-amber-700">Program lifecycle</p><h2 class="mt-1 text-lg font-bold text-slate-950">Choose a workspace</h2></div>
                                <p class="text-sm text-slate-500">{{ summary.total }} program{{ summary.total === 1 ? '' : 's' }} · {{ summary.attention }} need attention</p>
                            </div>
                            <div class="divide-y divide-slate-200">
                                <a v-for="item in lifecycleLinks" :key="item.key" :href="item.href" class="grid gap-3 px-5 py-4 transition hover:bg-slate-50 sm:grid-cols-[3rem_minmax(0,1fr)_5rem_2rem] sm:items-center sm:px-6">
                                    <span class="grid h-10 w-10 place-items-center rounded bg-slate-100 text-slate-700"><i :class="['fa-solid', item.icon]"></i></span>
                                    <div><h3 class="text-sm font-bold text-slate-950">{{ item.title }}</h3><p class="mt-1 text-sm text-slate-500">{{ item.description }}</p></div>
                                    <span class="text-left text-xl font-black text-slate-950 sm:text-center">{{ item.count }}</span>
                                    <i class="fa-solid fa-arrow-right text-sm text-slate-400"></i>
                                </a>
                            </div>
                        </section>

                        <section class="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold text-slate-600 sm:px-6">
                            <span><i class="fa-solid fa-building mr-2 text-slate-400"></i>{{ workspace.organization_name }}</span>
                            <span><i class="fa-solid fa-lock mr-2 text-slate-400"></i>{{ accessLabel }}</span>
                            <span><i class="fa-solid fa-file-signature mr-2 text-slate-400"></i>{{ summary.applications }} total applications</span>
                        </section>
                    </template>

                    <section v-else class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 border-b border-slate-200 px-5 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                            <div><p class="text-sm font-bold text-slate-950">{{ pagination.total }} program{{ pagination.total === 1 ? '' : 's' }}</p><p class="mt-1 text-xs text-slate-500">Only {{ sectionMeta.title.toLowerCase() }} are shown here.</p></div>
                            <label class="relative block w-full lg:max-w-sm"><span class="sr-only">Search programs</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i><input v-model="searchQuery" type="search" placeholder="Search title, category, or cycle" class="w-full rounded border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                        </div>
                        <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                        <div v-if="programs.length" class="divide-y divide-slate-200">
                            <div class="hidden grid-cols-[minmax(17rem,1.4fr)_minmax(9rem,.65fr)_minmax(11rem,.75fr)_minmax(8rem,.6fr)_9rem] gap-4 bg-slate-50 px-6 py-3 text-[0.65rem] font-black uppercase tracking-[0.15em] text-slate-500 xl:grid"><span>Program</span><span>Status</span><span>Timeline</span><span>Applications</span><span class="text-right">Action</span></div>
                            <article v-for="program in programs" :key="program.id" class="grid gap-4 px-5 py-4 sm:px-6 xl:grid-cols-[minmax(17rem,1.4fr)_minmax(9rem,.65fr)_minmax(11rem,.75fr)_minmax(8rem,.6fr)_9rem] xl:items-center">
                                <div class="flex min-w-0 items-start gap-3"><span :class="['mt-1 h-2.5 w-2.5 shrink-0 rounded-full', attentionProgramIds.includes(program.id) ? 'bg-amber-400' : 'bg-slate-300']"></span><div class="min-w-0"><h3 class="truncate text-sm font-bold text-slate-950">{{ program.title }}</h3><p class="mt-1 truncate text-xs text-slate-500">{{ [program.category, program.program_cycle].filter(Boolean).join(' · ') || 'Program details in progress' }}</p></div></div>
                                <div><span :class="['inline-flex rounded px-2.5 py-1.5 text-[0.68rem] font-black uppercase tracking-wide', statusClass(program.status)]">{{ program.status_label }}</span></div>
                                <div><p :class="['text-sm font-bold', deadlineClass(program)]">{{ deadlineLabel(program) }}</p><p class="mt-1 text-xs text-slate-500">Updated {{ program.updated_at }}</p></div>
                                <div><p class="text-sm font-black text-slate-950">{{ capacityLabel(program) }}</p><p class="mt-1 text-xs text-slate-500">{{ program.application_limit ? 'received / limit' : 'received' }}</p></div>
                                <a :href="program.action_href" class="rounded border border-slate-300 px-3.5 py-2 text-center text-xs font-bold text-slate-700 hover:border-slate-400 hover:bg-slate-50">{{ program.action_label }}</a>
                            </article>
                        </div>
                        <div v-else class="px-6 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded bg-slate-100 text-slate-400"><i class="fa-solid fa-folder-open"></i></span><h3 class="mt-3 text-sm font-bold text-slate-900">{{ sectionMeta.emptyTitle }}</h3><p class="mt-1 text-sm text-slate-500">{{ searchQuery ? 'Clear the search to see all records in this page.' : sectionMeta.emptyDescription }}</p></div>
                        <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="programs" @change="loadWorkspace" />
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
