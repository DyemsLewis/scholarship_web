<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from './ProviderSidebar.vue';
import WorkspaceDashboardHeader from '../../components/workspace-dashboard/WorkspaceDashboardHeader.vue';
import WorkspaceMetricStrip from '../../components/workspace-dashboard/WorkspaceMetricStrip.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const overview = ref({
    organization_name: '',
    summary: { programs: 0, published_programs: 0, applications: 0, attention: 0, active_team: 0 },
    priority_action: null,
    attention_workspaces: [],
});

const recentPrograms = computed(() => scholarships.value.slice(0, 5));
const isManager = computed(() => user.value?.account_title === 'manager' || user.value?.permissions?.length > 1);
const dashboardWorkspace = {
    key: 'provider',
    eyebrow: 'Provider management',
    title: 'Provider dashboard',
    description: 'See what needs attention, then continue in the appropriate workspace.',
    icon: 'fa-solid fa-gauge-high',
};
const metrics = computed(() => [
    { key: 'published', label: 'Published programs', value: overview.value.summary.published_programs, detail: `${overview.value.summary.programs} total`, icon: 'fa-solid fa-graduation-cap' },
    { key: 'applications', label: 'Applications', value: overview.value.summary.applications, detail: 'Across all programs', icon: 'fa-solid fa-file-lines' },
    { key: 'team', label: 'Active team', value: overview.value.summary.active_team, detail: 'Managed staff accounts', icon: 'fa-solid fa-user-group' },
    { key: 'attention', label: 'Needs attention', value: overview.value.summary.attention, detail: 'Open workflow items', icon: overview.value.summary.attention ? 'fa-solid fa-bell' : 'fa-solid fa-check', tone: overview.value.summary.attention ? 'attention' : 'neutral' },
]);

const workspaceIcons = {
    programs: 'fa-solid fa-graduation-cap',
    reviews: 'fa-solid fa-file-circle-check',
    selection: 'fa-solid fa-calendar-check',
    decisions: 'fa-solid fa-gavel',
    recipients: 'fa-solid fa-award',
    monitoring: 'fa-solid fa-chart-line',
    releases: 'fa-solid fa-hand-holding-heart',
    support: 'fa-solid fa-headset',
    billing: 'fa-solid fa-receipt',
    profile: 'fa-solid fa-building-circle-check',
};

function workspaceIcon(key) {
    return workspaceIcons[key] ?? 'fa-solid fa-folder-open';
}

const priorityActionIcon = computed(() => {
    if (!overview.value.priority_action?.count) return 'fa-solid fa-check';

    const href = overview.value.priority_action.href ?? '';
    const workspace = Object.keys(workspaceIcons).find((key) => href.includes(`/workspaces/${key}`));

    return workspaceIcon(workspace ?? (href.includes('/provider/programs') ? 'programs' : ''));
});

function statusLabel(value) {
    return {
        draft: 'Draft', rejected: 'Changes requested', pending_review: 'Admin review',
        published: 'Published', closed: 'Closed',
    }[value] ?? String(value || 'Not set').replaceAll('_', ' ');
}

function statusClass(value) {
    if (value === 'published') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (value === 'rejected') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (value === 'draft' || value === 'pending_review') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function dateLabel(value) {
    if (!value) return 'No deadline';
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/provider/dashboard/data');
        user.value = response.data.user;
        scholarships.value = response.data.scholarships ?? [];
        overview.value = response.data.manager_overview ?? overview.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'The provider dashboard could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="provider-dashboard-title">
            <div class="provider-dashboard mx-auto max-w-[94rem]">
                <WorkspaceDashboardHeader :workspace="dashboardWorkspace">
                    <template #actions>
                        <div class="provider-dashboard-header-actions flex min-w-0 items-center gap-5">
                            <div v-if="overview.organization_name" class="provider-dashboard-organization min-w-0 text-right">
                                <p class="workspace-dashboard-label font-bold uppercase text-slate-600">Organization</p>
                                <p class="workspace-dashboard-text mt-1 text-sm font-bold text-slate-800">{{ overview.organization_name }}</p>
                            </div>
                            <a href="/provider/programs" class="workspace-dashboard-action inline-flex h-11 shrink-0 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-folder-open text-xs text-amber-300" aria-hidden="true"></i>Open programs</a>
                        </div>
                    </template>
                </WorkspaceDashboardHeader>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm font-semibold text-slate-500 shadow-sm">Loading organization overview...</div>

                <template v-else-if="!errorMessage">
                    <WorkspaceMetricStrip :metrics="metrics" />

                    <section v-if="overview.priority_action" class="mt-4 border border-slate-200 border-l-4 border-l-amber-400 bg-white px-6 py-5 shadow-sm" aria-labelledby="manager-priority-title">
                        <div class="flex items-center gap-5">
                            <span class="workspace-dashboard-icon grid h-11 w-11 shrink-0 place-items-center bg-amber-100 text-amber-700"><i :class="priorityActionIcon" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1"><p class="workspace-dashboard-label text-xs font-bold uppercase tracking-wide text-amber-700">{{ overview.priority_action.eyebrow }}</p><h2 id="manager-priority-title" class="workspace-dashboard-text mt-1 text-lg font-bold text-slate-950">{{ overview.priority_action.title }}</h2><p class="mt-1 text-sm text-slate-600"><template v-if="overview.priority_action.count">{{ overview.priority_action.count }} item{{ overview.priority_action.count === 1 ? '' : 's' }} · </template>{{ overview.priority_action.description }}</p></div>
                            <a :href="overview.priority_action.href" class="workspace-dashboard-action inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">{{ overview.priority_action.label }} <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="attention-workspaces-title">
                        <header class="workspace-dashboard-section-header flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4"><div class="workspace-dashboard-heading flex min-w-0 items-center gap-3"><span class="workspace-dashboard-icon grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-list-check text-sm" aria-hidden="true"></i></span><div class="min-w-0"><h2 id="attention-workspaces-title" class="text-base font-bold text-slate-950">Work requiring attention</h2><p class="mt-1 text-xs text-slate-600">Only operating areas with open work appear here.</p></div></div><span v-if="isManager" class="inline-flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-600"><i class="fa-regular fa-compass" aria-hidden="true"></i>Use the sidebar for every workspace</span></header>
                        <div v-if="overview.attention_workspaces.length" class="divide-y divide-slate-200"><a v-for="workspace in overview.attention_workspaces" :key="workspace.key" :href="workspace.href" class="provider-dashboard-workspace grid grid-cols-[minmax(0,1fr)_8rem_3rem] items-center gap-5 px-6 py-4 hover:bg-slate-50"><div class="flex min-w-0 items-center gap-4"><span class="workspace-dashboard-icon grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i :class="[workspaceIcon(workspace.key), 'text-sm']" aria-hidden="true"></i></span><div class="min-w-0"><h3 class="workspace-dashboard-text text-sm font-bold text-slate-950">{{ workspace.label }}</h3><p class="workspace-dashboard-text mt-1 text-xs text-slate-600">{{ workspace.detail }}</p></div></div><span class="workspace-dashboard-status inline-flex items-center justify-self-end gap-1.5 border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-800"><i class="fa-solid fa-bell text-xs" aria-hidden="true"></i>{{ workspace.count }} open</span><span class="grid h-9 w-9 place-items-center bg-slate-950 text-white" aria-hidden="true"><i class="fa-solid fa-arrow-right text-xs"></i></span></a></div>
                        <div v-else class="px-6 py-10 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-emerald-50 text-emerald-700" aria-hidden="true"><i class="fa-solid fa-circle-check text-sm"></i></span><p class="mt-2 text-sm font-bold text-slate-800">No workflow needs immediate attention.</p><p class="mt-1 text-xs text-slate-500">Detailed records remain available from the sidebar.</p></div>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="program-snapshot-title">
                        <header class="workspace-dashboard-section-header flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4"><div class="workspace-dashboard-heading flex min-w-0 items-center gap-3"><span class="workspace-dashboard-icon grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-graduation-cap text-sm" aria-hidden="true"></i></span><div class="min-w-0"><h2 id="program-snapshot-title" class="text-base font-bold text-slate-950">Program snapshot</h2><p class="mt-1 text-xs text-slate-600">Recently updated scholarship programs.</p></div></div><a href="/provider/programs" class="workspace-dashboard-action inline-flex items-center gap-2 text-xs font-bold text-slate-700 hover:text-amber-700">View all programs <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></header>
                        <div v-if="recentPrograms.length" class="staff-table-viewport">
                            <table class="w-full min-w-[850px] table-fixed border-collapse text-left">
                                <colgroup><col class="w-[38%]"><col class="w-[18%]"><col class="w-[16%]"><col><col class="w-20"></colgroup>
                                <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-600">
                                    <tr><th class="px-6 py-3">Program</th><th class="px-6 py-3">Status</th><th class="px-6 py-3">Applications</th><th class="px-6 py-3">Deadline</th><th class="px-4 py-3"><span class="sr-only">Open</span></th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    <tr v-for="program in recentPrograms" :key="program.id" class="hover:bg-slate-50">
                                        <td class="px-6 py-4">
                                            <div class="flex min-w-0 items-center gap-3">
                                                <img v-if="program.logo_url" :src="program.logo_url" :alt="`${program.title} logo`" class="h-10 w-10 shrink-0 border border-slate-200 bg-white object-contain">
                                                <span v-else class="workspace-dashboard-icon grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-graduation-cap text-sm"></i></span>
                                                <div class="min-w-0"><p class="workspace-dashboard-text text-sm font-bold text-slate-950">{{ program.title }}</p><p class="workspace-dashboard-text mt-1 text-xs text-slate-600">{{ program.category || 'Scholarship program' }}</p></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4"><span :class="['workspace-dashboard-status inline-flex border px-2.5 py-1 text-xs font-bold uppercase tracking-wide', statusClass(program.status)]">{{ statusLabel(program.status) }}</span></td>
                                        <td class="px-6 py-4 text-sm font-bold text-slate-900">{{ program.applications_count || 0 }}</td>
                                        <td class="px-6 py-4 text-xs font-semibold text-slate-600">{{ dateLabel(program.deadline) }}</td>
                                        <td class="px-4 py-4"><a :href="`/provider/programs/${program.id}`" class="workspace-dashboard-action grid h-10 w-10 place-items-center bg-slate-950 text-white" :aria-label="`Open ${program.title}`"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="px-6 py-10 text-center"><span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-400" aria-hidden="true"><i class="fa-solid fa-graduation-cap"></i></span><p class="mt-3 text-sm font-bold text-slate-800">No scholarship program has been created.</p><a href="/provider/programs/create" class="mt-3 inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white"><i class="fa-solid fa-plus text-[10px] text-amber-300" aria-hidden="true"></i>Create a program</a></div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
