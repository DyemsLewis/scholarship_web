<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const query = new URLSearchParams(window.location.search);
const loading = ref(true);
const errorMessage = ref('');
const search = ref(query.get('search') ?? '');
const categoryOptions = ['all', 'security', 'accounts', 'reviews', 'operations', 'exports'];
const actorRoleOptions = ['all', 'admin', 'provider', 'applicant', 'system'];
const periodOptions = ['today', 'week', 'month', 'all'];
const category = ref(categoryOptions.includes(query.get('category')) ? query.get('category') : 'all');
const actorRole = ref(actorRoleOptions.includes(query.get('actor_role')) ? query.get('actor_role') : 'all');
const period = ref(periodOptions.includes(query.get('period')) ? query.get('period') : 'month');
const legacyAction = query.get('action') || '';
const items = ref([]);
const summary = ref({ total: 0, today: 0, security_7_days: 0, exports_30_days: 0 });
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 records');

function roleLabel(value) {
    return value === 'system' ? 'System' : String(value).replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function categoryLabel(value) {
    return { security: 'Security', accounts: 'Accounts', reviews: 'Reviews', operations: 'Operations', exports: 'Exports' }[value] ?? 'Operations';
}

function categoryClass(value) {
    if (value === 'security') return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-700';
}

async function loadActivity(page = 1) {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/admin/workspaces/records/activity/data', {
            params: {
                page,
                per_page: 15,
                search: search.value.trim() || undefined,
                category: category.value,
                actor_role: actorRole.value,
                period: period.value,
                action: legacyAction || undefined,
            },
        });
        items.value = response.data.items ?? [];
        summary.value = { ...summary.value, ...(response.data.summary ?? {}) };
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Activity records could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    if (page >= 1 && page <= pagination.value.last_page && page !== pagination.value.current_page) loadActivity(page);
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadActivity(1), 250);
});
watch([category, actorRole, period], () => loadActivity(1));
onMounted(loadActivity);
</script>

<template>
    <AdminWorkspacePage workspace="records_officer">
        <AdminWorkspaceHeader
            eyebrow="Audit records"
            title="Activity records"
            description="Find a recorded platform action and inspect its source."
            icon="fa-solid fa-clock-rotate-left"
        >
            <template #actions><a href="/admin/workspaces/records/exports" class="inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800">Open export desk<i class="fa-solid fa-arrow-right text-[10px] text-amber-300" aria-hidden="true"></i></a></template>
        </AdminWorkspaceHeader>

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <section class="admin-panel mt-4 overflow-hidden">
            <dl class="grid grid-cols-4 divide-x divide-slate-200">
                <div class="flex items-center gap-3 px-5 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></span><div><dt class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">All records</dt><dd class="mt-0.5 text-xl font-black text-slate-950">{{ summary.total }}</dd></div></div>
                <div class="flex items-center gap-3 px-5 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span><div><dt class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Recorded today</dt><dd class="mt-0.5 text-xl font-black text-slate-950">{{ summary.today }}</dd></div></div>
                <div class="flex items-center gap-3 px-5 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-amber-100 text-amber-800"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span><div><dt class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Failed sign-ins · 7 days</dt><dd class="mt-0.5 text-xl font-black text-slate-950">{{ summary.security_7_days }}</dd></div></div>
                <div class="flex items-center gap-3 px-5 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-export" aria-hidden="true"></i></span><div><dt class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Exports · 30 days</dt><dd class="mt-0.5 text-xl font-black text-slate-950">{{ summary.exports_30_days }}</dd></div></div>
            </dl>
        </section>

        <AdminTableFrame title="Audit trail" :description="resultLabel" icon="fa-solid fa-clock-rotate-left" class="mt-4">
            <template #filters>
                <div class="grid grid-cols-[minmax(22rem,1fr)_13rem_12rem_12rem] gap-3">
                    <label class="relative block"><span class="sr-only">Search activity records</span><i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" maxlength="120" placeholder="Search actor, action, description, or IP" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950"></label>
                    <label><span class="sr-only">Record category</span><select v-model="category" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700"><option value="all">All categories</option><option value="security">Security</option><option value="accounts">Accounts</option><option value="reviews">Reviews</option><option value="operations">Operations</option><option value="exports">Exports</option></select></label>
                    <label><span class="sr-only">Actor role</span><select v-model="actorRole" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700"><option value="all">All roles</option><option value="admin">Admin</option><option value="provider">Provider</option><option value="applicant">Applicant</option><option value="system">System</option></select></label>
                    <label><span class="sr-only">Record period</span><select v-model="period" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700"><option value="today">Today</option><option value="week">Last 7 days</option><option value="month">Last 30 days</option><option value="all">All time</option></select></label>
                </div>
            </template>

            <div v-if="loading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading activity records...</div>
            <table v-else-if="items.length" class="portal-data-table min-w-[68rem]">
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">Event</th><th class="border-b border-slate-200 px-4 py-3">Actor</th><th class="border-b border-slate-200 px-4 py-3">Category</th><th class="border-b border-slate-200 px-4 py-3">Source</th><th class="border-b border-slate-200 px-4 py-3">Recorded</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr></thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3.5"><p class="max-w-lg truncate text-sm font-bold text-slate-950">{{ item.description }}</p><p class="mt-1 text-xs text-slate-500">{{ item.action_label }}</p></td>
                        <td class="px-4 py-3.5"><p class="max-w-xs truncate text-sm font-semibold text-slate-800">{{ item.actor_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ roleLabel(item.actor_role) }}</p></td>
                        <td class="px-4 py-3.5"><span :class="['inline-flex px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', categoryClass(item.category)]">{{ categoryLabel(item.category) }}</span></td>
                        <td class="px-4 py-3.5 font-mono text-xs font-semibold text-slate-600">{{ item.ip_address || 'Not recorded' }}</td>
                        <td class="px-4 py-3.5 text-sm text-slate-600">{{ item.created_at }}</td>
                        <td class="px-5 py-3.5 text-right"><a :href="item.record_url" class="inline-grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open ${item.action_label}`" title="Open record"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else title="No activity records found" description="Try another category, role, period, or search term." icon="fa-solid fa-clock-rotate-left" />

            <template v-if="pagination.last_page > 1" #footer><div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div></template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
