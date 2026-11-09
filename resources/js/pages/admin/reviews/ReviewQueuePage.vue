<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { createStaffQueueState } from '../../../support/staffQueueState.js';
import { labelFromKey } from '../../../support/display.js';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { reviewQueueStatus, reviewStatusClass, reviewStatusLabel } from '../../../support/adminReviewPresentation.js';

const queueKey = (window.portalPathname?.() ?? window.location.pathname).split('/').filter(Boolean).pop();
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
        columns: ['Recipient', 'Benefit release', 'Oversight', 'Receipt / issue'],
    },
    monitoring: {
        title: 'Monitoring oversight', description: 'Track check-ins that need provider accountability.', eyebrow: 'Recipient oversight', icon: 'fa-solid fa-chart-line', endpoint: '/admin/workspaces/reviews/monitoring/data',
        statuses: [['attention', 'Needs attention'], ['open', 'Open cases'], ['reviewed', 'Reviewed'], ['closed', 'Closed'], ['all', 'All']], search: 'Search recipient, check-in, or program', empty: 'No monitoring cases',
        columns: ['Recipient', 'Check-in', 'Oversight', 'Next action'],
    },
};
const config = configs[queueKey] ?? configs.providers;
const identityKind = ({ providers: 'provider', programs: 'program', 'selection-activities': 'program' })[queueKey] ?? 'applicant';
const queueState = createStaffQueueState(window, ['search','status']);
const allowedStatuses = config.statuses.map(([value]) => value);
const search = ref(queueState.get('search') ?? '');
const status = ref(allowedStatuses.includes(queueState.get('status')) ? queueState.get('status') : config.statuses[0][0]);
const resource = useAdminResource(async ({ signal }, params) => {
    const response = await queueState.load((query) => window.axios.get(config.endpoint, { params: query, signal }), params, () => !signal.aborted);
    return response.data;
}, { failureMessage: 'This review queue could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const items = computed(() => (resource.data.value?.items ?? []).map((item) => ({
    ...item,
    context: recordContext(item),
    context_hint: contextHint(item),
    review_status: reviewQueueStatus(item, queueKey),
})));
const pagination = computed(() => resource.data.value?.pagination ?? { current_page: 1, last_page: 1, total: 0, from: null, to: null });
let searchTimer;

const resultLabel = computed(() => pagination.value.total ? `${pagination.value.from}-${pagination.value.to} of ${pagination.value.total}` : '0 records');

function recordContext(item) {
    const context = {
        providers: item.legal_name !== item.name ? item.legal_name : null,
        programs: item.category,
        applicants: item.school,
        'selection-activities': item.stage_label,
        benefits: item.release,
        monitoring: item.cycle_title,
    }[queueKey];

    return context || (queueKey === 'providers' && item.provider_type ? labelFromKey(item.provider_type) : null);
}

function contextHint(item) {
    const underlyingStatus = item.status && item.oversight_status && item.status !== item.oversight_status
        ? reviewStatusLabel(item.status)
        : null;
    if (queueKey === 'selection-activities') return [item.schedule, underlyingStatus].filter(Boolean).join(' · ');
    if (queueKey === 'benefits') return [item.scheduled_at, underlyingStatus].filter(Boolean).join(' · ');
    if (queueKey === 'programs' && item.deadline) return `Due ${item.deadline}`;
    if (queueKey === 'monitoring' && item.due_on) return `Due ${item.due_on}`;
    if (queueKey === 'applicants') return item.education;
    return null;
}

function countLabel(count, label) {
    return `${count} ${label}${count === 1 ? '' : 's'}`;
}

function monitoringAction(item) {
    const action = item.next_action_label || item.primary_flag;
    if (action && item.next_action_owner && item.next_action_owner !== 'No action') {
        return `${item.next_action_owner}: ${action}`;
    }
    return action || item.case_status_label || 'No action';
}

function loadItems(page = 1) {
    clearTimeout(searchTimer);
    return resource.load({ page, per_page: 10, search: search.value.trim() || undefined, status: status.value });
}

function changePage(page) {
    if (loading.value || page < 1 || page > pagination.value.last_page || page === pagination.value.current_page) return;
    loadItems(page);
}

watch([search, status], () => {
    clearTimeout(searchTimer);
    resource.invalidate();
    searchTimer = setTimeout(() => loadItems(1), 250);
}, { flush: 'sync' });
onBeforeUnmount(() => clearTimeout(searchTimer));
onMounted(() => loadItems(queueState.page));
</script>

<template>
    <AdminWorkspacePage workspace="review_officer" class="admin-review-workspace">
        <AdminWorkspaceHeader :eyebrow="config.eyebrow" :title="config.title" :description="config.description" :icon="config.icon" />
        <AdminTableFrame :title="config.title" :description="loading ? 'Updating results...' : errorMessage ? 'Results unavailable' : resultLabel" :icon="config.icon" :loading="loading" :error="errorMessage" loading-message="Loading review queue..." class="mt-4" @retry="resource.retry">
            <template #filters>
                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_14rem]">
                    <label class="relative"><span class="sr-only">Search queue</span><i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i><input v-model="search" type="search" maxlength="120" :placeholder="config.search" class="h-11 w-full border border-slate-300 bg-white pl-9 pr-3 text-sm outline-none focus:border-slate-950"></label>
                    <select v-model="status" aria-label="Review status" class="h-11 border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-slate-950"><option v-for="option in config.statuses" :key="option[0]" :value="option[0]">{{ option[1] }}</option></select>
                </div>
            </template>

            <table v-if="items.length" class="portal-data-table table-fixed min-w-[56rem]">
                <colgroup><col><col class="w-52"><col class="w-44"><col class="w-44"><col class="w-20"></colgroup>
                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500"><tr><th class="border-b border-slate-200 px-5 py-3">{{ config.columns[0] }}</th><th class="border-b border-slate-200 px-4 py-3">{{ config.columns[1] }}</th><th class="border-b border-slate-200 px-4 py-3">{{ config.columns[2] }}</th><th class="border-b border-slate-200 px-4 py-3">{{ config.columns[3] }}</th><th class="border-b border-slate-200 px-5 py-3 text-right">Open</th></tr></thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    <tr v-for="item in items" :key="item.id ?? item.application_id" class="align-middle hover:bg-slate-50/70">
                        <td class="px-5 py-3.5">
                            <div class="flex min-w-0 items-center gap-3">
                                <StaffTableIdentity :kind="identityKind" :src="identityKind === 'applicant' ? item.photo_url : item.logo_url" :icon="identityKind === 'program' ? config.icon : ''" class="h-10 w-10" />
                                <span class="min-w-0"><strong class="review-text block text-sm text-slate-950">{{ item.name || item.title || item.recipient }}</strong><span v-if="item.program || item.provider || item.email" class="review-text mt-0.5 block text-xs text-slate-500">{{ item.program || item.provider || item.email }}</span></span>
                            </div>
                        </td>
                        <td class="px-4 py-3.5">
                            <p class="review-text text-sm font-semibold text-slate-700">{{ item.context || item.context_hint || 'Not provided' }}</p>
                            <p v-if="item.context && item.context_hint" class="review-text mt-0.5 text-xs text-slate-500">{{ item.context_hint }}</p>
                        </td>
                        <td class="px-4 py-3.5"><span :class="['inline-flex px-2 py-1 text-xs font-bold uppercase tracking-wide', reviewStatusClass(item.review_status)]">{{ reviewStatusLabel(item.review_status, queueKey) }}</span></td>
                        <td class="review-text px-4 py-3.5 text-sm text-slate-600">
                            <template v-if="queueKey === 'providers'">{{ countLabel(item.proof_count, 'file') }}</template>
                            <template v-else-if="queueKey === 'programs'">{{ countLabel(item.application_count, 'application') }}</template>
                            <template v-else-if="queueKey === 'applicants'">{{ countLabel(item.evidence_count, 'file') }}</template>
                            <template v-else-if="queueKey === 'selection-activities'">
                                <p>{{ countLabel(item.active_assignments_count, 'applicant') }} · {{ countLabel(item.current_evidence_count, 'file') }}</p>
                                <p v-if="item.primary_flag" class="mt-0.5 text-xs font-semibold text-amber-800">{{ item.primary_flag }}</p>
                            </template>
                            <template v-else-if="queueKey === 'benefits'">
                                <div class="flex items-center gap-2">
                                    <a v-if="item.receipt_url" :href="item.receipt_url" data-file-preview="true" :data-file-name="item.receipt_name || `${item.release || 'Benefit release'} receipt`" class="staff-row-action h-10 w-10" :aria-label="`Preview receipt for ${item.recipient}`" title="Preview receipt"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a>
                                    <span v-if="item.primary_flag" class="text-xs font-semibold text-amber-800">{{ item.primary_flag }}</span>
                                    <span v-else-if="!item.receipt_url">No receipt</span>
                                </div>
                            </template>
                            <template v-else-if="queueKey === 'monitoring'">
                                <p class="font-semibold text-slate-800">{{ monitoringAction(item) }}</p>
                                <p v-if="item.wait_label" class="mt-0.5 text-xs font-semibold text-amber-800">{{ item.wait_label }}</p>
                            </template>
                        </td>
                        <td class="px-5 py-3.5 text-right"><a v-if="item.review_url || item.recipient_url" :href="queueState.link(item.review_url || item.recipient_url)" class="staff-row-action h-10 w-10" :aria-label="`${queueKey === 'benefits' ? 'Open recipient profile for' : 'Open'} ${item.name || item.title || item.recipient}`" :title="queueKey === 'benefits' ? 'Open recipient profile' : 'Open record'"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a></td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else :title="config.empty" description="Try another status or search." :icon="config.icon" />

            <template v-if="pagination.last_page > 1" #footer><div class="flex items-center justify-between text-sm text-slate-600"><span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span><div class="flex gap-2"><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || pagination.current_page <= 1" @click="changePage(pagination.current_page - 1)">Previous</button><button type="button" class="border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40" :disabled="loading || pagination.current_page >= pagination.last_page" @click="changePage(pagination.current_page + 1)">Next</button></div></div></template>
        </AdminTableFrame>
    </AdminWorkspacePage>
</template>
