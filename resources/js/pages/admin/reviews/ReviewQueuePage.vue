<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const queueKey = window.location.pathname.split('/').filter(Boolean).pop();
const configs = {
    providers: {
        title: 'Provider verification', description: 'Confirm organization identity and submitted proof.', eyebrow: 'Organization review', icon: 'fa-solid fa-building-shield', endpoint: '/admin/workspaces/reviews/providers/data',
        statuses: [['pending', 'Pending'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['all', 'All']], search: 'Search provider or registration number', empty: 'No provider records',
        columns: ['Organization', 'Identity', 'Verification', 'Submitted proof'],
    },
    programs: {
        title: 'Program review', description: 'Check scholarship details before publication.', eyebrow: 'Publication review', icon: 'fa-solid fa-graduation-cap', endpoint: '/admin/workspaces/reviews/programs/data',
        statuses: [['pending_review', 'Pending'], ['published', 'Published'], ['rejected', 'Rejected'], ['all', 'All']], search: 'Search program or provider', empty: 'No program records',
        columns: ['Program', 'Program details', 'Publication status', 'Applications'],
    },
    applicants: {
        title: 'Applicant verification', description: 'Compare academic claims with supporting evidence.', eyebrow: 'Profile evidence', icon: 'fa-solid fa-user-check', endpoint: '/admin/workspaces/reviews/applicants/data',
        statuses: [['pending', 'Pending'], ['approved', 'Verified'], ['rejected', 'Replacement needed'], ['unsubmitted', 'No proof'], ['all', 'All']], search: 'Search applicant, school, or email', empty: 'No applicant records',
        columns: ['Applicant', 'Academic profile', 'Verification', 'Evidence'],
    },
    'selection-activities': {
        title: 'Selection activities', description: 'Review session readiness without changing provider records.', eyebrow: 'Application oversight', icon: 'fa-solid fa-calendar-check', endpoint: '/admin/workspaces/reviews/selection-activities/data',
        statuses: [['attention', 'Needs attention'], ['active', 'Active'], ['completed', 'Completed'], ['draft', 'Drafts'], ['cancelled', 'Cancelled'], ['all', 'All']], search: 'Search session, program, or provider', empty: 'No selection activities',
        columns: ['Activity', 'Session details', 'Oversight', 'Attendance and proof'],
    },
    benefits: {
        title: 'Benefit evidence', description: 'Review releases with missing or disputed proof.', eyebrow: 'Release oversight', icon: 'fa-solid fa-receipt', endpoint: '/admin/workspaces/reviews/benefits/data',
        statuses: [['attention', 'Needs review'], ['documented', 'Documented'], ['released', 'Released'], ['scheduled', 'Scheduled'], ['all', 'All']], search: 'Search recipient, release, or program', empty: 'No benefit records',
        columns: ['Recipient', 'Benefit release', 'Oversight', 'Receipt'],
    },
    monitoring: {
        title: 'Monitoring oversight', description: 'Track check-ins that need provider accountability.', eyebrow: 'Recipient oversight', icon: 'fa-solid fa-chart-line', endpoint: '/admin/workspaces/reviews/monitoring/data',
        statuses: [['attention', 'Needs attention'], ['open', 'Open cases'], ['reviewed', 'Reviewed'], ['closed', 'Closed'], ['all', 'All']], search: 'Search recipient, check-in, or program', empty: 'No monitoring cases',
        columns: ['Recipient', 'Check-in', 'Oversight', 'Next action'],
    },
};
const config = configs[queueKey] ?? configs.providers;
const query = new URLSearchParams(window.location.search);
const allowedStatuses = config.statuses.map(([value]) => value);
const search = ref(query.get('search') ?? '');
const status = ref(allowedStatuses.includes(query.get('status')) ? query.get('status') : config.statuses[0][0]);
const loading = ref(true);
const errorMessage = ref('');
const items = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 records');

function statusLabel(value) {
    const labels = { pending_review: 'Pending', published: 'Published', approved: 'Verified', rejected: 'Rejected', unsubmitted: 'No proof', attention: 'Needs attention', documented: 'Documented', released: 'Released', scheduled: 'Scheduled', reviewed: 'Reviewed', closed: 'Closed', stable: 'Stable', open: 'Open', pending: 'Pending', active: 'Active', completed: 'Completed', draft: 'Draft', cancelled: 'Cancelled' };
    return labels[value] ?? String(value || 'Pending').replaceAll('_', ' ');
}

function badgeClass(value) {
    if (['approved', 'published', 'documented', 'reviewed', 'stable', 'completed'].includes(value)) return 'bg-emerald-100 text-emerald-800';
    if (['rejected', 'attention', 'missed', 'withheld'].includes(value)) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-700';
}

async function loadItems(page = 1) {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(config.endpoint, { params: { page, per_page: 10, search: search.value.trim() || undefined, status: status.value } });
        items.value = response.data.items ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'This review queue could not be loaded.';
    } finally {
        loading.value = false;
    }
}

function changePage(page) {
    if (page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
    loadItems(page);
}

watch([search, status], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadItems(1), 250);
});
onMounted(loadItems);
</script>

<template>
    <AdminWorkspacePage workspace="review_officer">
        <AdminWorkspaceHeader :eyebrow="config.eyebrow" :title="config.title" :description="config.description" :icon="config.icon" />
        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <AdminTableFrame :title="config.title" :description="resultLabel" :icon="config.icon" class="mt-4">
            <template #filters>
                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_14rem]">
                    <label class="relative"><span class="sr-only">Search queue</span><i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" maxlength="120" :placeholder="config.search" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950"></label>
                    <select v-model="status" aria-label="Review status" class="h-11 border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option v-for="option in config.statuses" :key="option[0]" :value="option[0]">{{ option[1] }}</option></select>
                </div>
            </template>

            <div v-if="loading" class="px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading review queue...</div>
            <table v-else-if="items.length" class="portal-data-table min-w-[60rem]">
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">{{ config.columns[0] }}</th><th class="border-b border-slate-200 px-4 py-3">{{ config.columns[1] }}</th><th class="border-b border-slate-200 px-4 py-3">{{ config.columns[2] }}</th><th class="border-b border-slate-200 px-4 py-3">{{ config.columns[3] }}</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr></thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr v-for="item in items" :key="item.id ?? item.application_id" class="hover:bg-slate-50/70">
                        <td class="px-5 py-3.5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="relative grid h-10 w-10 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500"><i :class="config.icon" aria-hidden="true"></i><img v-if="item.logo_url || item.image_url || item.photo_url" :src="item.logo_url || item.image_url || item.photo_url" :alt="item.logo_url ? `${item.name || 'Provider'} logo` : `${item.name || item.title || 'Record'} image`" :class="['absolute inset-0 h-full w-full bg-white', item.logo_url ? 'object-contain p-1' : 'object-cover']" @error="$event.currentTarget.remove()"></span>
                                <span class="min-w-0"><strong class="block truncate text-sm text-slate-950">{{ item.name || item.title || item.recipient }}</strong><span class="mt-0.5 block truncate text-xs text-slate-500">{{ item.email || item.provider || item.program }}</span></span>
                            </div>
                        </td>
                        <td class="px-4 py-3.5"><p class="max-w-xs truncate text-sm font-semibold text-slate-700">{{ item.legal_name || item.category || item.school || item.release || item.stage_label || item.cycle_title || item.provider || 'Record details' }}</p><p class="mt-0.5 text-xs text-slate-500">{{ item.education || item.deadline || item.scheduled_at || item.schedule || item.due_on || item.updated_at || ' ' }}</p></td>
                        <td class="px-4 py-3.5"><span :class="['inline-flex px-2 py-1 text-[10px] font-bold uppercase tracking-wide', badgeClass(item.verification_status || item.status || item.oversight_status)]">{{ statusLabel(item.verification_status || item.status || item.oversight_status) }}</span><p v-if="item.primary_flag" class="mt-1 max-w-xs text-xs font-semibold text-amber-800">{{ item.primary_flag }}</p></td>
                        <td class="px-4 py-3.5 text-sm text-slate-600"><template v-if="queueKey === 'providers'">{{ item.proof_count }} proofs</template><template v-else-if="queueKey === 'programs'">{{ item.application_count }} applications</template><template v-else-if="queueKey === 'applicants'">{{ item.evidence_count }} files</template><template v-else-if="queueKey === 'selection-activities'">{{ item.active_assignments_count }} applicants · {{ item.current_evidence_count }} files</template><template v-else-if="queueKey === 'benefits'"><a v-if="item.receipt_url" :href="item.receipt_url" target="_blank" rel="noopener" class="font-bold text-slate-800 underline">Open receipt</a><span v-else>No receipt</span></template><template v-else-if="queueKey === 'monitoring'"><strong class="block text-sm text-slate-800">{{ item.next_action_owner }}</strong><span class="mt-0.5 block text-xs text-slate-500">{{ item.next_action_label || item.case_status_label }}</span><span v-if="item.wait_label" class="mt-1 block text-xs font-bold text-amber-800">{{ item.wait_label }}</span></template><template v-else>{{ item.submission_count }} submissions · {{ item.release_count }} releases</template></td>
                        <td class="px-5 py-3.5 text-right"><a v-if="item.review_url || item.recipient_url" :href="item.review_url || item.recipient_url" class="inline-grid h-9 w-9 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Open ${item.name || item.title || item.recipient}`" title="Open record"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else :title="config.empty" description="Try another status or search." :icon="config.icon" />

            <template v-if="pagination.last_page > 1" #footer><div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div></template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
