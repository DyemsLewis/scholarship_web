<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import WorkspaceDashboardHeader from '../../../components/workspace-dashboard/WorkspaceDashboardHeader.vue';
import WorkspaceMetricStrip from '../../../components/workspace-dashboard/WorkspaceMetricStrip.vue';

const context = ref({ organization_name: '', access_scope: 'all' });
const summary = ref({ total: 0, needs_action: 0, under_review: 0, published: 0 });
const priorities = ref([]);
const deadlines = ref([]);
const recent = ref([]);
const isLoading = ref(true);
const errorMessage = ref('');
const dashboardWorkspace = {
    key: 'coordinator',
    eyebrow: 'Program coordination',
    title: 'Program dashboard',
    description: 'See what needs attention before opening the full program directory.',
    icon: 'fa-solid fa-graduation-cap',
};
const metrics = computed(() => [
    { key: 'programs', label: 'Programs', value: summary.value.total, detail: 'All records', icon: 'fa-solid fa-layer-group' },
    { key: 'attention', label: 'Needs action', value: summary.value.needs_action, detail: 'Drafts or changes', icon: summary.value.needs_action ? 'fa-solid fa-bell' : 'fa-solid fa-check', tone: summary.value.needs_action ? 'attention' : 'neutral' },
    { key: 'review', label: 'Under review', value: summary.value.under_review, detail: 'With administrators', icon: 'fa-solid fa-clock-rotate-left' },
    { key: 'published', label: 'Published', value: summary.value.published, detail: 'Visible to applicants', icon: 'fa-solid fa-circle-check' },
]);

const focusMessage = computed(() => {
    if (summary.value.needs_action === 0) {
        return 'No program drafts or requested changes need attention.';
    }

    return `${summary.value.needs_action} program${summary.value.needs_action === 1 ? '' : 's'} ${summary.value.needs_action === 1 ? 'needs' : 'need'} your attention.`;
});

function statusClass(tone) {
    return {
        positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        attention: 'border-amber-200 bg-amber-50 text-amber-800',
        waiting: 'border-sky-200 bg-sky-50 text-sky-800',
        neutral: 'border-slate-200 bg-slate-100 text-slate-700',
    }[tone] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

function deadlineNote(deadline) {
    if (deadline.days_remaining === 0) return 'Due today';
    if (deadline.days_remaining === 1) return '1 day remaining';
    return `${deadline.days_remaining} days remaining`;
}

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/programs/dashboard/data');
        context.value = response.data.context ?? context.value;
        summary.value = response.data.summary ?? summary.value;
        priorities.value = response.data.priorities ?? [];
        deadlines.value = response.data.deadlines ?? [];
        recent.value = response.data.recent ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            || 'The program dashboard could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="coordinator-dashboard-title">
            <div class="provider-dashboard mx-auto max-w-[94rem]">
                <WorkspaceDashboardHeader :workspace="dashboardWorkspace" :primary-action="{ label: 'Open programs', url: '/provider/programs', icon: 'fa-solid fa-folder-open' }" />

                <div v-if="errorMessage" class="mt-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">
                    {{ errorMessage }}
                </div>

                <template v-else>
                    <WorkspaceMetricStrip :metrics="metrics" :loading="isLoading" />

                    <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-12 text-center text-sm font-semibold text-slate-500 shadow-sm">
                        Loading program priorities...
                    </div>

                    <div v-else class="mt-4 space-y-4">
                        <section class="border border-slate-200 bg-white shadow-sm" aria-labelledby="priority-work-heading">
                            <header class="workspace-dashboard-section-header flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
                                <div class="workspace-dashboard-heading flex min-w-0 items-center gap-3">
                                    <span :class="['workspace-dashboard-icon grid h-9 w-9 shrink-0 place-items-center', summary.needs_action ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600']" aria-hidden="true"><i :class="['fa-solid text-sm', summary.needs_action ? 'fa-list-check' : 'fa-check']"></i></span>
                                    <div>
                                        <p class="workspace-dashboard-label text-xs font-bold uppercase tracking-wide text-amber-700">Current focus</p>
                                        <h2 id="priority-work-heading" class="mt-0.5 text-lg font-bold text-slate-950">Priority work</h2>
                                        <p class="mt-1 text-sm text-slate-500">{{ focusMessage }}</p>
                                    </div>
                                </div>
                                <a href="/provider/programs/drafts" class="workspace-dashboard-action inline-flex shrink-0 items-center gap-2 text-xs font-bold text-slate-700 hover:text-amber-700">View drafts & changes <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                            </header>

                            <div v-if="priorities.length" class="divide-y divide-slate-200">
                                <article v-for="program in priorities" :key="program.id" class="grid grid-cols-[minmax(0,1fr)_13rem_7rem] items-center gap-5 px-6 py-4">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-wide text-amber-700">{{ program.priority_label }}</p>
                                        <h3 class="workspace-dashboard-text mt-1 font-semibold text-slate-950">{{ program.title }}</h3>
                                        <p class="workspace-dashboard-text mt-1 text-xs text-slate-600">{{ program.category || 'Uncategorized' }}<template v-if="program.cycle"> · {{ program.cycle }}</template></p>
                                    </div>
                                    <span :class="['workspace-dashboard-status justify-self-start border px-2.5 py-1 text-xs font-bold uppercase tracking-wide', statusClass(program.status.tone)]">
                                        {{ program.status.label }}
                                    </span>
                                    <a :href="program.record_url" class="workspace-dashboard-action inline-flex h-10 items-center justify-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800" :aria-label="`Open ${program.title}`">
                                        Open
                                        <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </article>
                            </div>
                            <div v-else class="px-6 py-8 text-center">
                                <span class="mx-auto grid h-9 w-9 place-items-center bg-emerald-50 text-emerald-700" aria-hidden="true"><i class="fa-solid fa-circle-check text-sm"></i></span>
                                <p class="mt-2 text-sm font-bold text-slate-800">Nothing needs action right now.</p>
                            </div>
                        </section>

                        <section class="border border-slate-200 bg-white shadow-sm" aria-labelledby="upcoming-deadlines-heading">
                            <header class="workspace-dashboard-section-header flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
                                <div class="workspace-dashboard-heading flex min-w-0 items-center gap-3">
                                    <span class="workspace-dashboard-icon grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600" aria-hidden="true"><i class="fa-solid fa-calendar-days text-sm"></i></span>
                                    <div>
                                        <p class="workspace-dashboard-label text-xs font-bold uppercase tracking-wide text-slate-600">Schedule</p>
                                        <h2 id="upcoming-deadlines-heading" class="mt-0.5 text-lg font-bold text-slate-950">Upcoming deadlines</h2>
                                    </div>
                                </div>
                                <a href="/provider/programs/published" class="workspace-dashboard-action inline-flex shrink-0 items-center gap-2 text-xs font-bold text-slate-700 hover:text-amber-700">View published programs <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                            </header>

                            <div v-if="deadlines.length" class="divide-y divide-slate-200">
                                <article v-for="program in deadlines" :key="program.id" class="grid grid-cols-[minmax(0,1fr)_13rem_7rem] items-center gap-5 px-6 py-4">
                                    <div class="min-w-0">
                                        <h3 class="workspace-dashboard-text font-semibold text-slate-950">{{ program.title }}</h3>
                                        <p class="workspace-dashboard-text mt-1 text-xs text-slate-600">{{ program.category || 'Uncategorized' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900"><i class="fa-regular fa-calendar mr-2 text-xs text-slate-400" aria-hidden="true"></i>{{ program.deadline.display }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ deadlineNote(program.deadline) }}</p>
                                    </div>
                                    <a :href="program.record_url" class="workspace-dashboard-action inline-flex h-10 items-center justify-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50" :aria-label="`View ${program.title}`">
                                        View
                                        <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </article>
                            </div>
                            <div v-else class="px-6 py-8 text-center">
                                <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-regular fa-calendar-check text-sm"></i></span>
                                <p class="mt-2 text-sm font-bold text-slate-800">No published application deadlines are scheduled.</p>
                            </div>
                        </section>

                        <section class="border border-slate-200 bg-white shadow-sm" aria-labelledby="recent-programs-heading">
                            <header class="workspace-dashboard-section-header border-b border-slate-200 px-6 py-4">
                                <div class="workspace-dashboard-heading flex min-w-0 items-center gap-3">
                                    <span class="workspace-dashboard-icon grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left text-sm"></i></span>
                                    <div>
                                        <p class="workspace-dashboard-label text-xs font-bold uppercase tracking-wide text-slate-600">Activity</p>
                                        <h2 id="recent-programs-heading" class="mt-0.5 text-lg font-bold text-slate-950">Recently updated</h2>
                                    </div>
                                </div>
                            </header>

                            <div v-if="recent.length" class="divide-y divide-slate-200">
                                <a v-for="program in recent" :key="program.id" :href="program.record_url" class="provider-dashboard-workspace grid grid-cols-[minmax(0,1fr)_12rem_8rem] items-center gap-5 px-6 py-3.5 hover:bg-slate-50">
                                    <span class="flex min-w-0 items-center gap-3"><span class="workspace-dashboard-icon grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-graduation-cap text-xs"></i></span><span class="workspace-dashboard-text text-sm font-semibold text-slate-900">{{ program.title }}</span></span>
                                    <span :class="['workspace-dashboard-status justify-self-start border px-2.5 py-1 text-xs font-bold uppercase tracking-wide', statusClass(program.status.tone)]">{{ program.status.label }}</span>
                                    <span class="text-right text-xs font-medium text-slate-500">{{ program.updated_display || 'Not available' }}</span>
                                </a>
                            </div>
                            <div v-else class="px-6 py-8 text-center">
                                <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left text-sm"></i></span>
                                <p class="mt-2 text-sm font-bold text-slate-800">No program updates are available yet.</p>
                            </div>
                        </section>
                    </div>
                </template>
            </div>
        </section>
    </main>
</template>
