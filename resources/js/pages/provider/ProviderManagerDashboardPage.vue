<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from './ProviderSidebar.vue';

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
            <div class="mx-auto max-w-[94rem]">
                <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                    <div class="flex items-start justify-between gap-8">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Provider management</p>
                            <h1 id="provider-dashboard-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">Provider dashboard</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-600">See what needs attention, then continue in the appropriate workspace.</p>
                        </div>
                        <div class="flex items-center gap-5">
                            <div v-if="overview.organization_name" class="text-right"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">Organization</p><p class="mt-1 text-sm font-bold text-slate-800">{{ overview.organization_name }}</p></div>
                            <a href="/provider/programs" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">Open programs <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                        </div>
                    </div>
                </header>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm font-semibold text-slate-500 shadow-sm">Loading organization overview...</div>

                <template v-else-if="!errorMessage">
                    <section class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Organization summary">
                        <div class="border-r border-slate-200 px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Published programs</p><p class="mt-1 text-2xl font-bold text-slate-950">{{ overview.summary.published_programs }}</p><p class="mt-1 text-xs text-slate-500">{{ overview.summary.programs }} total</p></div>
                        <div class="border-r border-slate-200 px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Applications</p><p class="mt-1 text-2xl font-bold text-slate-950">{{ overview.summary.applications }}</p><p class="mt-1 text-xs text-slate-500">Across all programs</p></div>
                        <div class="border-r border-slate-200 px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Active team</p><p class="mt-1 text-2xl font-bold text-slate-950">{{ overview.summary.active_team }}</p><p class="mt-1 text-xs text-slate-500">Managed staff accounts</p></div>
                        <div class="px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Needs attention</p><p class="mt-1 text-2xl font-bold" :class="overview.summary.attention ? 'text-amber-700' : 'text-slate-950'">{{ overview.summary.attention }}</p><p class="mt-1 text-xs text-slate-500">Open workflow items</p></div>
                    </section>

                    <section v-if="overview.priority_action" class="mt-4 border border-slate-200 border-l-4 border-l-amber-400 bg-white px-6 py-5 shadow-sm" aria-labelledby="manager-priority-title">
                        <div class="flex items-center gap-5">
                            <span class="grid h-11 w-11 shrink-0 place-items-center bg-amber-100 text-amber-700"><i :class="['fa-solid', overview.priority_action.count ? 'fa-arrow-right' : 'fa-check']" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.17em] text-amber-700">{{ overview.priority_action.eyebrow }}</p><h2 id="manager-priority-title" class="mt-1 text-lg font-bold text-slate-950">{{ overview.priority_action.title }}</h2><p class="mt-1 text-sm text-slate-600"><template v-if="overview.priority_action.count">{{ overview.priority_action.count }} item{{ overview.priority_action.count === 1 ? '' : 's' }} · </template>{{ overview.priority_action.description }}</p></div>
                            <a :href="overview.priority_action.href" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">{{ overview.priority_action.label }} <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                        </div>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="attention-workspaces-title">
                        <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4"><div><h2 id="attention-workspaces-title" class="text-base font-bold text-slate-950">Work requiring attention</h2><p class="mt-1 text-xs text-slate-500">Only operating areas with open work appear here.</p></div><span v-if="isManager" class="text-xs font-semibold text-slate-500">Use the sidebar for every workspace</span></header>
                        <div v-if="overview.attention_workspaces.length" class="divide-y divide-slate-200"><a v-for="workspace in overview.attention_workspaces" :key="workspace.key" :href="workspace.href" class="grid grid-cols-[1fr_8rem_3rem] items-center gap-5 px-6 py-4 hover:bg-slate-50"><div class="min-w-0"><h3 class="text-sm font-bold text-slate-950">{{ workspace.label }}</h3><p class="mt-1 truncate text-xs text-slate-500">{{ workspace.detail }}</p></div><span class="justify-self-end border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-800">{{ workspace.count }} open</span><span class="grid h-9 w-9 place-items-center bg-slate-950 text-white"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></span></a></div>
                        <div v-else class="px-6 py-10 text-center"><i class="fa-solid fa-circle-check text-xl text-emerald-600" aria-hidden="true"></i><p class="mt-2 text-sm font-bold text-slate-800">No workflow needs immediate attention.</p><p class="mt-1 text-xs text-slate-500">Detailed records remain available from the sidebar.</p></div>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="program-snapshot-title">
                        <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4"><div><h2 id="program-snapshot-title" class="text-base font-bold text-slate-950">Program snapshot</h2><p class="mt-1 text-xs text-slate-500">Recently updated scholarship programs.</p></div><a href="/provider/programs" class="text-xs font-bold text-slate-700 hover:text-amber-700">View all programs</a></header>
                        <div v-if="recentPrograms.length" class="overflow-x-auto"><table class="w-full min-w-[850px] table-fixed border-collapse text-left"><thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500"><tr><th class="w-[42%] px-6 py-3">Program</th><th class="w-[18%] px-6 py-3">Status</th><th class="w-[18%] px-6 py-3">Applications</th><th class="w-[16%] px-6 py-3">Deadline</th><th class="w-[6%] px-6 py-3"><span class="sr-only">Open</span></th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="program in recentPrograms" :key="program.id" class="hover:bg-slate-50"><td class="px-6 py-4"><p class="truncate text-sm font-bold text-slate-950">{{ program.title }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ program.category || 'Scholarship program' }}</p></td><td class="px-6 py-4"><span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(program.status)]">{{ statusLabel(program.status) }}</span></td><td class="px-6 py-4 text-sm font-bold text-slate-900">{{ program.applications_count || 0 }}</td><td class="px-6 py-4 text-xs font-semibold text-slate-600">{{ dateLabel(program.deadline) }}</td><td class="px-6 py-4 text-right"><a :href="`/provider/programs/${program.id}`" class="grid h-9 w-9 place-items-center bg-slate-950 text-white" :aria-label="`Open ${program.title}`"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td></tr></tbody></table></div>
                        <div v-else class="px-6 py-10 text-center"><p class="text-sm font-bold text-slate-800">No scholarship program has been created.</p><a href="/provider/programs/create" class="mt-3 inline-flex h-10 items-center bg-slate-950 px-4 text-xs font-bold text-white">Create a program</a></div>
                    </section>
                </template>
            </div>
        </section>
    </main>
</template>
