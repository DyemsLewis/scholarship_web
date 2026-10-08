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
        title: 'Drafts and requested changes',
        description: 'Finish new programs and revise submissions returned by the platform review team.',
        emptyTitle: 'No drafts need work',
        emptyDescription: 'New drafts and requested revisions will appear here.',
    },
    review: {
        title: 'Programs in admin review',
        description: 'Track submitted programs while the platform review team checks them.',
        emptyTitle: 'Nothing is under review',
        emptyDescription: 'Submitted programs will remain here until an administrator responds.',
    },
    published: {
        title: 'Published programs',
        description: 'Check active application dates, applicant volume, and public program information.',
        emptyTitle: 'No published programs',
        emptyDescription: 'Approved programs will appear here after publication.',
    },
    closed: {
        title: 'Closed programs',
        description: 'Use this page for completed program records that no longer need active work.',
        emptyTitle: 'No closed programs',
        emptyDescription: 'Programs will move here after they are closed.',
    },
}[activeSection]));
const lifecycleLinks = computed(() => [
    { key: 'drafts', title: 'Drafts and changes', description: 'Prepare or revise a program.', count: phases.value.drafts, href: '/provider/workspaces/programs/drafts', icon: 'fa-pen-ruler' },
    { key: 'review', title: 'Admin review', description: 'Follow submitted programs.', count: phases.value.review, href: '/provider/workspaces/programs/review', icon: 'fa-shield-halved' },
    { key: 'published', title: 'Published', description: 'Maintain active program details.', count: phases.value.published, href: '/provider/workspaces/programs/published', icon: 'fa-bullhorn' },
    { key: 'closed', title: 'Closed', description: 'Open past program records.', count: phases.value.closed, href: '/provider/workspaces/programs/closed', icon: 'fa-box-archive' },
]);

function statusClass(status) {
    return {
        published: 'bg-slate-900 text-white',
        pending_review: 'bg-slate-200 text-slate-700',
        rejected: 'bg-amber-100 text-amber-800',
        closed: 'bg-slate-200 text-slate-700',
    }[status] ?? 'bg-amber-100 text-amber-800';
}

function statusIcon(status) {
    return {
        published: 'fa-solid fa-circle-check',
        pending_review: 'fa-solid fa-clock',
        rejected: 'fa-solid fa-triangle-exclamation',
        closed: 'fa-solid fa-box-archive',
    }[status] ?? 'fa-solid fa-pen-ruler';
}

function deadlineLabel(program) {
    if (!program.deadline) return 'No deadline set';
    if (program.deadline_state === 'passed') return `Ended ${program.deadline}`;
    if (program.deadline_days === 0) return 'Deadline today';
    if (program.deadline_state === 'due_soon') return `${program.deadline_days} day${program.deadline_days === 1 ? '' : 's'} left`;
    return program.deadline;
}

function deadlineClass(program) {
    if (program.deadline_state === 'passed') return 'text-rose-700';
    if (program.deadline_state === 'due_soon') return 'text-amber-700';
    return 'text-slate-600';
}

function capacityLabel(program) {
    return program.application_limit ? `${program.applications_count} / ${program.application_limit}` : `${program.applications_count}`;
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
                        :description="isOverview ? 'Create, submit, publish, and close scholarship programs from one focused workspace.' : sectionMeta.description"
                        icon="fa-solid fa-compass-drafting"
                        :show-role-guide="false"
                    >
                        <template #actions>
                            <a v-if="!isOverview" href="/provider/workspaces/programs" class="border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                <i class="fa-solid fa-arrow-left mr-2 text-xs text-slate-400" aria-hidden="true"></i>Overview
                            </a>
                            <a href="/provider/programs/create" class="bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800">
                                <i class="fa-solid fa-plus mr-2 text-xs text-amber-300" aria-hidden="true"></i>New program
                            </a>
                        </template>
                    </ProviderPageHeader>

                    <section v-if="nextAction" class="mt-4 border border-slate-300 border-l-4 border-l-amber-500 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                        <div class="flex items-center gap-4 px-5 py-4">
                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[0.64rem] font-black uppercase tracking-[0.16em] text-amber-700">Priority</p>
                                <h2 class="mt-0.5 text-base font-bold text-slate-950">{{ nextAction.title }}</h2>
                                <p class="mt-0.5 text-sm text-slate-600">{{ nextAction.description }}</p>
                            </div>
                            <a :href="nextAction.href" class="shrink-0 bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">{{ nextAction.label }}<i class="fa-solid fa-arrow-right ml-2 text-xs text-amber-300" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <template v-if="isOverview">
                        <section class="mt-4 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                            <header class="border-b border-slate-200 px-5 py-4">
                                <h2 class="text-base font-bold text-slate-950">Program workflow</h2>
                                <p class="mt-1 text-sm text-slate-500">Open the stage that matches the work you need to complete.</p>
                            </header>

                            <nav class="divide-y divide-slate-200" aria-label="Program lifecycle">
                                <a v-for="item in lifecycleLinks" :key="item.key" :href="item.href" class="group grid grid-cols-[2.5rem_minmax(0,1fr)_auto_auto] items-center gap-4 px-5 py-4 transition hover:bg-slate-50">
                                    <span class="grid h-9 w-9 place-items-center bg-slate-100 text-sm text-slate-600 group-hover:bg-slate-950 group-hover:text-amber-300"><i :class="['fa-solid', item.icon]" aria-hidden="true"></i></span>
                                    <span class="min-w-0"><span class="block font-bold text-slate-950">{{ item.title }}</span><span class="mt-0.5 block text-sm text-slate-500">{{ item.description }}</span></span>
                                    <span class="min-w-10 text-right text-xl font-black tabular-nums text-slate-950">{{ item.count }}</span>
                                    <i class="fa-solid fa-arrow-right text-xs text-slate-400 group-hover:text-slate-950" aria-hidden="true"></i>
                                </a>
                            </nav>

                            <div class="grid grid-cols-3 border-t border-slate-200 bg-slate-50">
                                <div class="px-5 py-3"><p class="text-[0.62rem] font-black uppercase tracking-[0.14em] text-slate-500">Programs</p><p class="mt-1 font-bold text-slate-950">{{ summary.total }} total</p></div>
                                <div class="border-l border-slate-200 px-5 py-3"><p class="text-[0.62rem] font-black uppercase tracking-[0.14em] text-slate-500">Applications</p><p class="mt-1 font-bold text-slate-950">{{ summary.applications }} received</p></div>
                                <div class="border-l border-slate-200 px-5 py-3"><p class="text-[0.62rem] font-black uppercase tracking-[0.14em] text-slate-500">Attention</p><p :class="['mt-1 font-bold', summary.attention ? 'text-amber-700' : 'text-emerald-700']">{{ summary.attention ? `${summary.attention} need action` : 'Nothing pending' }}</p></div>
                            </div>
                        </section>
                    </template>

                    <template v-else>
                        <nav class="mt-4 grid grid-cols-4 border border-slate-300 bg-white" aria-label="Program lifecycle pages">
                            <a v-for="item in lifecycleLinks" :key="item.key" :href="item.href" :aria-current="item.key === activeSection ? 'page' : undefined" :class="['flex min-h-12 items-center justify-between gap-3 border-r border-slate-200 px-4 text-sm font-bold last:border-r-0', item.key === activeSection ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">
                                <span>{{ item.title }}</span>
                                <span :class="['min-w-6 px-1.5 py-0.5 text-center text-xs font-black tabular-nums', item.key === activeSection ? 'bg-white/10 text-amber-300' : 'bg-slate-100 text-slate-600']">{{ item.count }}</span>
                            </a>
                        </nav>

                        <section class="mt-3 border border-slate-300 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
                            <div class="flex items-center justify-between gap-5 border-b border-slate-200 px-5 py-4">
                                <div><h2 class="text-base font-bold text-slate-950">{{ pagination.total }} program{{ pagination.total === 1 ? '' : 's' }}</h2><p class="mt-0.5 text-xs text-slate-500">Showing only this lifecycle stage.</p></div>
                                <label class="relative block w-full max-w-sm"><span class="sr-only">Search programs</span><i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchQuery" type="search" placeholder="Search title, category, or cycle" class="w-full border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"></label>
                            </div>

                            <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                            <div v-if="programs.length" class="portal-table-scroll">
                                <table class="portal-data-table min-w-[60rem] table-fixed">
                                    <caption class="sr-only">{{ sectionMeta.title }}</caption>
                                    <colgroup><col class="w-[37%]"><col class="w-[26%]"><col class="w-[17%]"><col class="w-[20%]"></colgroup>
                                    <thead><tr><th scope="col">Program</th><th scope="col">Status and timeline</th><th scope="col">Applications</th><th scope="col">Action</th></tr></thead>
                                    <tbody>
                                        <tr v-for="program in programs" :key="program.id">
                                            <td><div class="flex min-w-0 items-start gap-3"><span :class="['mt-0.5 grid h-8 w-8 shrink-0 place-items-center', attentionProgramIds.includes(program.id) ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500']"><i :class="attentionProgramIds.includes(program.id) ? 'fa-solid fa-triangle-exclamation' : 'fa-regular fa-folder'" aria-hidden="true"></i></span><div class="min-w-0"><p class="truncate font-bold text-slate-950">{{ program.title }}</p><p class="mt-0.5 truncate text-xs text-slate-500">{{ [program.category, program.program_cycle].filter(Boolean).join(' / ') || 'Program details in progress' }}</p></div></div></td>
                                            <td><span :class="['inline-flex items-center gap-1.5 px-2 py-1 text-[0.65rem] font-black uppercase tracking-wide', statusClass(program.status)]"><i :class="statusIcon(program.status)" aria-hidden="true"></i>{{ program.status_label }}</span><p :class="['mt-1.5 text-xs font-semibold', deadlineClass(program)]"><i class="fa-regular fa-calendar mr-1.5" aria-hidden="true"></i>{{ deadlineLabel(program) }}</p></td>
                                            <td><p class="font-bold text-slate-950">{{ capacityLabel(program) }}</p><p class="mt-0.5 text-xs text-slate-500">{{ program.application_limit ? 'received / limit' : 'received' }}</p></td>
                                            <td><a :href="program.action_href" class="inline-flex items-center border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white">{{ program.action_label }}<i class="fa-solid fa-arrow-right ml-2 text-[0.65rem]" aria-hidden="true"></i></a></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div v-else class="px-6 py-12 text-center"><i class="fa-solid fa-folder-open text-2xl text-slate-300" aria-hidden="true"></i><h3 class="mt-3 text-sm font-bold text-slate-900">{{ sectionMeta.emptyTitle }}</h3><p class="mt-1 text-sm text-slate-500">{{ searchQuery ? 'Clear the search to see all records on this page.' : sectionMeta.emptyDescription }}</p></div>
                            <ProviderPagination :pagination="pagination" :busy="isRefreshing" item-label="programs" @change="loadWorkspace" />
                        </section>
                    </template>
                </template>
            </div>
        </section>
    </main>
</template>
