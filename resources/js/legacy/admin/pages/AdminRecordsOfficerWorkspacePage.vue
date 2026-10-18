<script setup>
import { computed, onMounted, ref } from 'vue';
import RecordsOfficerSidebar from '../components/RecordsOfficerSidebar.vue';
import PortalManagerSidebar from '../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';

const isExportsPage = window.location.pathname.endsWith('/exports');
const usesPortalManagerWorkspace = window.location.pathname.startsWith('/admin/workspaces/portal');
const recordsWorkspaceBase = usesPortalManagerWorkspace ? '/admin/workspaces/portal/records' : '/admin/workspaces/records';
const isLoading = ref(!isExportsPage);
const errorMessage = ref('');
const selectedAction = ref('all');
const selectedRole = ref('all');
const searchDraft = ref('');
const appliedSearch = ref('');
const selectedEntry = ref(null);
const monitoringStatus = ref('all');
const entries = ref([]);
const filters = ref({ all: 0 });
const roles = ref({ all: 0, admin: 0, provider: 0, applicant: 0, system: 0 });
const pagination = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null });

const actionFilters = computed(() => Object.entries(filters.value).map(([action, count]) => ({
    value: action,
    label: action === 'all' ? 'All activity' : actionLabel(action),
    count,
})));
const roleFilters = computed(() => Object.entries(roles.value).map(([role, count]) => ({
    value: role,
    label: role === 'all' ? 'All account roles' : actionLabel(role),
    count,
})));
const securityCount = computed(() => Number(filters.value.login_failed ?? 0));
const monitoringExportUrl = computed(() => `/admin/export/monitoring?status=${encodeURIComponent(monitoringStatus.value)}`);

function actionLabel(action) {
    return String(action ?? 'system').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function actionClass(action) {
    if (action === 'login_failed') return 'bg-rose-100 text-rose-800';
    if (['account_created', 'registered'].includes(action)) return 'bg-amber-100 text-amber-900';
    if (action === 'login') return 'bg-emerald-100 text-emerald-800';
    return 'bg-slate-100 text-slate-700';
}

function actionIcon(action) {
    if (action === 'login_failed') return 'fa-triangle-exclamation';
    if (['account_created', 'registered'].includes(action)) return 'fa-user-plus';
    if (action === 'login') return 'fa-right-to-bracket';
    if (action === 'logout') return 'fa-right-from-bracket';
    return 'fa-clock-rotate-left';
}

async function loadLogs(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/workspaces/records/activity/data', {
            params: {
                page,
                per_page: pagination.value.per_page,
                action: selectedAction.value,
                actor_role: selectedRole.value,
                search: appliedSearch.value || undefined,
            },
        });
        entries.value = response.data.entries ?? [];
        filters.value = response.data.filters ?? filters.value;
        roles.value = response.data.roles ?? roles.value;
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load activity records.';
    } finally {
        isLoading.value = false;
    }
}

function applySearch() {
    appliedSearch.value = searchDraft.value.trim();
    loadLogs(1);
}

function clearSearch() {
    searchDraft.value = '';
    appliedSearch.value = '';
    loadLogs(1);
}

onMounted(() => {
    if (!isExportsPage) loadLogs();
});
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar v-if="usesPortalManagerWorkspace" />
        <RecordsOfficerSidebar v-else />

        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader
                    theme="admin"
                    eyebrow="Records desk"
                    :title="isExportsPage ? 'Data exports' : 'Activity records'"
                    :description="isExportsPage ? 'Download authorized platform datasets as CSV files.' : 'Review account, security, and operational actions recorded by the platform.'"
                    :icon="isExportsPage ? 'fa-solid fa-file-export' : 'fa-solid fa-clock-rotate-left'"
                >
                    <template #actions>
                        <a :href="isExportsPage ? `${recordsWorkspaceBase}/activity` : `${recordsWorkspaceBase}/exports`" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            <i :class="[isExportsPage ? 'fa-solid fa-clock-rotate-left' : 'fa-solid fa-file-export', 'mr-1.5 text-xs']" aria-hidden="true"></i>{{ isExportsPage ? 'View activity' : 'Open exports' }}
                        </a>
                    </template>
                </TaskPageHeader>

                <template v-if="!isExportsPage">
                    <div v-if="isLoading && !entries.length" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading activity records...</div>

                    <div v-else class="admin-content-stack">
                        <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                        <section class="admin-panel overflow-hidden">
                            <dl class="grid divide-y divide-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                                <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Recorded actions</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ filters.all }}</dd></div>
                                <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Failed sign-ins</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ securityCount }}</dd></div>
                                <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Current view</dt><dd class="mt-1 text-2xl font-black text-slate-950">{{ pagination.total }}</dd></div>
                            </dl>
                        </section>

                        <section class="admin-panel overflow-hidden">
                            <form class="grid gap-3 border-b border-slate-200 bg-slate-50 p-3 xl:grid-cols-[minmax(0,1fr)_15rem_13rem_auto]" @submit.prevent="applySearch">
                                <label class="relative"><span class="sr-only">Search activity</span><i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="searchDraft" type="search" maxlength="120" placeholder="Search actor, action description, or IP address" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-slate-500"></label>
                                <select v-model="selectedAction" aria-label="Activity type" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none" @change="loadLogs(1)"><option v-for="filter in actionFilters" :key="filter.value" :value="filter.value">{{ filter.label }} ({{ filter.count }})</option></select>
                                <select v-model="selectedRole" aria-label="Actor role" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none" @change="loadLogs(1)"><option v-for="role in roleFilters" :key="role.value" :value="role.value">{{ role.label }} ({{ role.count }})</option></select>
                                <div class="flex gap-2"><button type="submit" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Search</button><button v-if="appliedSearch" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-bold text-slate-600" @click="clearSearch">Clear</button></div>
                            </form>

                            <div v-if="isLoading" class="p-6 text-sm text-slate-500">Updating records...</div>
                            <div v-else-if="entries.length" class="divide-y divide-slate-200">
                                <article v-for="entry in entries" :key="entry.id" class="flex flex-col gap-3 px-5 py-4 sm:px-6 lg:flex-row lg:items-center">
                                    <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md text-xs', actionClass(entry.action)]"><i :class="['fa-solid', actionIcon(entry.action)]" aria-hidden="true"></i></span>
                                    <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h3 class="line-clamp-1 font-bold text-slate-950">{{ entry.description }}</h3><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', actionClass(entry.action)]">{{ actionLabel(entry.action) }}</span></div><p class="mt-1 truncate text-xs text-slate-500">{{ entry.actor_name }} &middot; {{ actionLabel(entry.actor_role || 'system') }} &middot; {{ entry.created_at }}</p></div>
                                    <button type="button" class="inline-flex shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="selectedEntry = entry">View details</button>
                                </article>
                            </div>
                            <div v-else class="px-6 py-12 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-400"><i class="fa-solid fa-check" aria-hidden="true"></i></span><p class="mt-3 font-bold text-slate-950">No activity in this view</p><p class="mt-1 text-sm text-slate-500">Try another action, role, or search term.</p></div>

                            <nav v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3" aria-label="Activity pagination"><button type="button" :disabled="pagination.current_page <= 1" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadLogs(pagination.current_page - 1)">Previous</button><span class="text-xs font-semibold text-slate-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><button type="button" :disabled="pagination.current_page >= pagination.last_page" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadLogs(pagination.current_page + 1)">Next</button></nav>
                        </section>
                    </div>
                </template>

                <section v-else class="admin-panel mt-5 overflow-hidden">
                    <header class="border-b border-slate-200 px-5 py-4 sm:px-6"><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Authorized datasets</p><h2 class="mt-1 text-xl font-black text-slate-950">Choose a record export</h2></header>
                    <div class="divide-y divide-slate-200">
                        <article class="flex flex-col gap-4 px-5 py-5 sm:px-6 lg:flex-row lg:items-center"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-users" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><h3 class="font-bold text-slate-950">User account records</h3><p class="mt-1 text-sm text-slate-500">Account identity, role, verification, status, and creation date.</p></div><a href="/admin/export/users" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-download mr-2 text-xs" aria-hidden="true"></i>Download CSV</a></article>
                        <article class="flex flex-col gap-4 px-5 py-5 sm:px-6 lg:flex-row lg:items-center"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-folder-open" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><h3 class="font-bold text-slate-950">Scholarship applications</h3><p class="mt-1 text-sm text-slate-500">Application status, decision support, documents, awards, and review notes.</p></div><a href="/admin/export/applications" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-download mr-2 text-xs" aria-hidden="true"></i>Download CSV</a></article>
                        <article class="flex flex-col gap-4 px-5 py-5 sm:px-6 lg:flex-row lg:items-center"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><h3 class="font-bold text-slate-950">Recipient monitoring</h3><p class="mt-1 text-sm text-slate-500">Requirements, benefit releases, support status, and oversight outcomes.</p></div><div class="flex shrink-0 flex-col gap-2 sm:flex-row"><select v-model="monitoringStatus" aria-label="Monitoring status" class="rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700"><option value="all">All records</option><option value="attention">Needs attention</option><option value="reviewed">Reviewed</option><option value="stable">Stable</option><option value="closed">Closed</option></select><a :href="monitoringExportUrl" class="inline-flex items-center justify-center rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-download mr-2 text-xs" aria-hidden="true"></i>Download CSV</a></div></article>
                    </div>
                    <p class="border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-500 sm:px-6">Exports may contain personal or operational information. Store downloaded files securely and share them only for an authorized platform purpose.</p>
                </section>
            </div>
        </section>

        <Teleport to="body">
            <div v-if="selectedEntry" class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="records-entry-title" @click.self="selectedEntry = null">
                <section class="flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-950 p-5 text-white"><div><p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-300">Activity record</p><h2 id="records-entry-title" class="mt-1 text-xl font-black">{{ actionLabel(selectedEntry.action) }}</h2></div><button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-white/20 text-slate-300 hover:bg-white/10" aria-label="Close details" @click="selectedEntry = null"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                    <div class="overflow-y-auto p-5 sm:p-6"><p class="font-bold leading-6 text-slate-950">{{ selectedEntry.description }}</p><dl class="mt-5 grid gap-4 border-y border-slate-200 py-5 sm:grid-cols-2"><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Performed by</dt><dd class="mt-1 text-sm font-semibold text-slate-900">{{ selectedEntry.actor_name }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Role</dt><dd class="mt-1 text-sm font-semibold text-slate-900">{{ actionLabel(selectedEntry.actor_role || 'system') }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Recorded</dt><dd class="mt-1 text-sm font-semibold text-slate-900">{{ selectedEntry.created_at }}</dd></div><div><dt class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">IP address</dt><dd class="mt-1 font-mono text-sm font-semibold text-slate-900">{{ selectedEntry.ip_address || 'Not recorded' }}</dd></div></dl><div class="mt-5"><p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Recorded details</p><p class="mt-2 rounded-md bg-slate-50 p-3 text-sm leading-6 text-slate-700">{{ selectedEntry.metadata_summary || 'No additional details were recorded.' }}</p></div></div>
                    <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4"><button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white" @click="selectedEntry = null">Close</button></footer>
                </section>
            </div>
        </Teleport>
    </main>
</template>
