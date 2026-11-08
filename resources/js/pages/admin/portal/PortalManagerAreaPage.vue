<script setup>
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { computed, onMounted } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import AdminLoadState from '../components/AdminLoadState.vue';
import WorkspaceMetricStrip from '../../../components/workspace-dashboard/WorkspaceMetricStrip.vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminTableFrame from '../components/AdminTableFrame.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import { labelFromKey } from '../../../support/display';

const area = document.getElementById('app')?.dataset.area ?? 'accounts';
const areas = {
    accounts: { eyebrow: 'Account health', title: 'Account oversight', description: 'Monitor account access issues assigned to the Account Manager.', icon: 'fa-solid fa-users-gear', endpoint: '/admin/workspaces/portal/accounts/data', owner: 'Account Manager' },
    reviews: { eyebrow: 'Verification health', title: 'Review oversight', description: 'Compare outstanding work across verification queues.', icon: 'fa-solid fa-clipboard-check', endpoint: '/admin/workspaces/portal/reviews/data', owner: 'Review Officer' },
    support: { eyebrow: 'Support health', title: 'Support oversight', description: 'Monitor unresolved concerns and response workload.', icon: 'fa-solid fa-headset', endpoint: '/admin/workspaces/portal/support/data', owner: 'Support Officer' },
    services: { eyebrow: 'Delivery health', title: 'Service oversight', description: 'Track paid service requests that are still in progress.', icon: 'fa-solid fa-file-invoice-dollar', endpoint: '/admin/workspaces/portal/billing/data', owner: 'Billing Officer' },
    finance: { eyebrow: 'Financial health', title: 'Finance oversight', description: 'Monitor confirmed revenue and payment exceptions.', icon: 'fa-solid fa-chart-line', endpoint: '/admin/workspaces/portal/finance/data', owner: 'Finance Officer' },
    records: { eyebrow: 'Record health', title: 'Records oversight', description: 'Monitor audit activity, security events, and export coverage.', icon: 'fa-solid fa-box-archive', endpoint: '/admin/workspaces/portal/records/data', owner: 'Records Officer' },
};
const config = areas[area] ?? areas.accounts;
const resource = useAdminResource(async ({ signal }) => {
    const { data } = await window.axios.get(config.endpoint, { signal });
    return data;
}, { failureMessage: 'Unable to load this oversight page.' });
const { isLoading, errorMessage } = resource;
const payload = computed(() => resource.data.value ?? {});
const accountDescription = computed(() => previewLabel(payload.value.users?.length, payload.value.summary?.attention_total, 'access exceptions'));
const supportDescription = computed(() => previewLabel(payload.value.cases?.items?.length, payload.value.cases?.pagination?.total, 'cases') + ' Oldest first.');
const financeDescription = computed(() => previewLabel(payload.value.payments?.items?.length, payload.value.payments?.pagination?.total, 'confirmed payments this month'));
const activityDescription = computed(() => previewLabel(payload.value.activity?.items?.length, payload.value.activity?.pagination?.total, 'events from the last 30 days'));
const serviceDescription = computed(() => 'Up to 5 requests per delivery queue. ' + previewLabel(serviceItems.value.length, ['ready', 'active', 'waiting'].reduce((total, key) => total + Number(payload.value.summary?.[key] ?? 0), 0), 'open requests'));

function previewLabel(shown = 0, total = 0, label) {
    return `Showing ${Number(shown)} of ${Number(total)} ${label}.`;
}

const serviceItems = computed(() => ['ready', 'active', 'waiting'].flatMap((key) => payload.value.queues?.[key]?.items ?? []));
const reviewItems = computed(() => payload.value.groups ?? []);
const metrics = computed(() => {
    const values = payload.value;

    if (area === 'accounts') return [
        metric('total', 'All accounts', values.summary?.total, 'Registered users', 'fa-solid fa-users'),
        metric('attention', 'Needs attention', values.summary?.attention_total, 'Access exceptions', 'fa-solid fa-triangle-exclamation', 'attention'),
        metric('unverified', 'Unverified', values.summary?.unverified, 'Email pending', 'fa-solid fa-envelope-circle-check'),
        metric('suspended', 'Suspended', values.summary?.suspended, 'Access blocked', 'fa-solid fa-user-lock'),
    ];
    if (area === 'reviews') return [
        metric('providers', 'Providers', values.summary?.provider_pending, 'Pending review', 'fa-solid fa-building-shield'),
        metric('programs', 'Programs', values.summary?.program_pending, 'Pending review', 'fa-solid fa-graduation-cap'),
        metric('applicants', 'Applicants', values.summary?.applicant_pending, 'Pending review', 'fa-solid fa-user-check'),
        metric('oversight', 'Award records', Number(values.summary?.selection_activity_attention ?? 0) + Number(values.summary?.benefit_attention ?? 0) + Number(values.summary?.monitoring_attention ?? 0), 'Need oversight', 'fa-solid fa-list-check', 'attention'),
    ];
    if (area === 'support') return [
        metric('response', 'Needs response', values.summary?.needs_response, 'Open cases', 'fa-solid fa-reply', 'attention'),
        metric('waiting', 'Waiting', values.summary?.waiting, 'External follow-up', 'fa-solid fa-hourglass-half'),
        metric('resolved', 'Resolved', values.summary?.resolved, 'Completed cases', 'fa-solid fa-circle-check'),
        metric('privacy', 'Privacy', values.summary?.privacy_open, 'Open requests', 'fa-solid fa-user-shield'),
    ];
    if (area === 'services') return [
        metric('ready', 'Ready', values.summary?.ready, 'Awaiting assignment', 'fa-solid fa-inbox', 'attention'),
        metric('active', 'In progress', values.summary?.active, 'Being delivered', 'fa-solid fa-gears'),
        metric('waiting', 'Waiting', values.summary?.waiting, 'Provider response', 'fa-solid fa-clock'),
        metric('meetings', 'Meetings', values.summary?.meeting_requests, 'Requested', 'fa-solid fa-video'),
    ];
    if (area === 'finance') return [
        metric('today', 'Revenue today', money(values.summary?.today, values.summary?.currency), 'Confirmed', 'fa-solid fa-peso-sign'),
        metric('month', 'This month', money(values.summary?.month, values.summary?.currency), 'Confirmed', 'fa-solid fa-chart-column'),
        metric('payments', 'Payments', values.summary?.successful_payments, 'Successful', 'fa-solid fa-receipt'),
        metric('exceptions', 'Exceptions', values.summary?.exceptions, 'Need review', 'fa-solid fa-triangle-exclamation', 'attention'),
    ];

    return [
        metric('events', 'All events', values.activity?.summary?.total, 'Audit records', 'fa-solid fa-clock-rotate-left'),
        metric('today', 'Today', values.activity?.summary?.today, 'New activity', 'fa-solid fa-calendar-day'),
        metric('security', 'Security', values.activity?.summary?.security_7_days, 'Last 7 days', 'fa-solid fa-shield-halved', 'attention'),
        metric('exports', 'Exports', values.activity?.summary?.exports_30_days, 'Last 30 days', 'fa-solid fa-file-export'),
    ];
});

function metric(key, label, value, detail, icon, tone = '') {
    return { key, label, value: value ?? 0, detail, icon, tone };
}

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: currency || 'PHP', maximumFractionDigits: 0 }).format(Number(amount ?? 0) / 100);
}

function reviewNames(group) {
    const values = (group.items ?? []).map((item) => item.name || item.title || item.recipient || item.program || item.release).filter(Boolean);
    return values.length ? values.join(' · ') : Number(group.count) > 0 ? 'Waiting records are not available in this preview.' : 'No waiting records';
}

const loadArea = () => resource.load();

onMounted(loadArea);
</script>

<template>
    <AdminWorkspacePage workspace="portal_manager" class="admin-portal-workspace">
        <AdminWorkspaceHeader :eyebrow="config.eyebrow" :title="config.title" :description="config.description" :icon="config.icon">
            <template #meta><span class="inline-flex items-center gap-2 text-xs font-bold text-slate-600"><i class="fa-solid fa-user-tie text-amber-700" aria-hidden="true"></i>{{ config.owner }}</span></template>
            <template #actions><button type="button" :disabled="isLoading" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-50" @click="loadArea"><i class="fa-solid fa-rotate text-xs" aria-hidden="true"></i>Refresh</button></template>
        </AdminWorkspaceHeader>

        <section v-if="isLoading || errorMessage" class="admin-panel mt-4"><AdminLoadState :loading="isLoading" :error="errorMessage" loading-message="Loading oversight..." @retry="loadArea" /></section>
        <template v-else>
        <WorkspaceMetricStrip :metrics="metrics" />
        <p class="mt-4 flex items-center gap-2 text-sm text-slate-600"><i class="fa-solid fa-eye text-slate-500" aria-hidden="true"></i>Read-only oversight. Decisions and follow-up remain with the {{ config.owner }}.</p>

        <AdminTableFrame v-if="area === 'accounts'" title="Access exceptions" :description="accountDescription" icon="fa-solid fa-user-lock" class="mt-4">
            <table v-if="payload.users?.length" class="portal-data-table portal-oversight-table"><colgroup><col style="width: 36%"><col style="width: 18%"><col style="width: 27%"><col style="width: 19%"></colgroup>
                <thead><tr><th class="px-5">Account</th><th>Role</th><th>Issue</th><th class="px-5">Updated</th></tr></thead>
                <tbody>
                    <tr v-for="user in payload.users" :key="user.id">
                        <td class="px-5"><div class="flex items-center gap-3"><StaffTableIdentity :kind="user.image_fit === 'contain' ? 'provider' : 'account'" :src="user.image_url" class="h-10 w-10" /><span class="min-w-0"><strong class="block break-words text-sm text-slate-950">{{ user.name }}</strong><span class="block break-words text-xs text-slate-500">{{ user.email }}</span></span></div></td>
                        <td class="text-sm font-semibold text-slate-700">{{ labelFromKey(user.role) }}</td>
                        <td><span class="inline-flex bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-900">{{ user.issue }}</span></td>
                        <td class="px-5 text-sm text-slate-500">{{ user.updated_at }}</td>
                    </tr>
                </tbody>
            </table>
            <AdminEmptyState v-else title="No account exceptions" description="All accounts currently pass the access checks." icon="fa-solid fa-user-check" />
        </AdminTableFrame>

        <AdminTableFrame v-else-if="area === 'reviews'" title="Verification queues" description="Showing up to 3 examples per queue; counts include all waiting records." icon="fa-solid fa-clipboard-check" class="mt-4">
            <table v-if="reviewItems.length" class="portal-data-table portal-oversight-table"><colgroup><col style="width: 27%"><col style="width: 13%"><col style="width: 60%"></colgroup>
                <thead><tr><th class="px-5">Queue</th><th>Waiting</th><th class="px-5">Recent examples</th></tr></thead>
                <tbody><tr v-for="group in reviewItems" :key="group.key"><td class="px-5"><p class="font-bold text-slate-950">{{ group.label }}</p><p class="mt-1 text-xs text-slate-500">Managed by Review Officer</p></td><td><span :class="['inline-flex min-w-10 justify-center px-2.5 py-1 text-xs font-black', group.count ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-600']">{{ group.count }}</span></td><td class="px-5 text-sm text-slate-600">{{ reviewNames(group) }}</td></tr></tbody>
            </table>
            <AdminEmptyState v-else title="No review queues found" description="There is no review workload to display." icon="fa-solid fa-clipboard-check" />
        </AdminTableFrame>

        <AdminTableFrame v-else-if="area === 'support'" title="Cases awaiting response" :description="supportDescription" icon="fa-solid fa-headset" class="mt-4">
            <table v-if="payload.cases?.items?.length" class="portal-data-table portal-oversight-table"><colgroup><col style="width: 35%"><col style="width: 32%"><col style="width: 16%"><col style="width: 17%"></colgroup>
                <thead><tr><th class="px-5">Concern</th><th>Reporter</th><th>Category</th><th class="px-5">Submitted</th></tr></thead>
                <tbody><tr v-for="item in payload.cases.items" :key="item.id"><td class="px-5"><p class="break-words font-bold text-slate-950">{{ item.subject }}</p><p v-if="item.program?.title" class="mt-1 break-words text-xs text-slate-500">{{ item.program.title }}</p></td><td><div class="flex items-center gap-3"><StaffTableIdentity :kind="item.reporter.image_fit === 'contain' ? 'provider' : 'applicant'" :src="item.reporter.image_url" class="h-10 w-10" /><span><strong class="block text-sm text-slate-800">{{ item.reporter.name }}</strong><span class="text-xs text-slate-500">{{ labelFromKey(item.reporter.type) }}</span></span></div></td><td class="text-sm font-semibold text-slate-700">{{ item.category_label }}</td><td class="px-5 text-sm text-slate-500">{{ item.submitted_at }}</td></tr></tbody>
            </table>
            <AdminEmptyState v-else title="No cases need a response" description="The support queue is clear." icon="fa-solid fa-comments" />
        </AdminTableFrame>

        <AdminTableFrame v-else-if="area === 'services'" title="Open delivery workload" :description="serviceDescription" icon="fa-solid fa-file-invoice-dollar" class="mt-4">
            <table v-if="serviceItems.length" class="portal-data-table portal-oversight-table"><colgroup><col style="width: 29%"><col style="width: 21%"><col style="width: 19%"><col style="width: 16%"><col style="width: 15%"></colgroup>
                <thead><tr><th class="px-5">Provider</th><th>Service</th><th>State</th><th>Assigned to</th><th class="px-5">Target</th></tr></thead>
                <tbody><tr v-for="item in serviceItems" :key="item.id"><td class="px-5"><div class="flex items-center gap-3"><StaffTableIdentity kind="provider" :src="item.provider_logo_url" class="h-10 w-10" /><span><strong class="block text-sm text-slate-900">{{ item.provider }}</strong><span class="text-xs text-slate-500">{{ item.provider_email }}</span></span></div></td><td class="text-sm font-semibold text-slate-700">{{ item.plan_name }}</td><td><span class="inline-flex bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ item.state_label }}</span></td><td class="text-sm text-slate-600">{{ item.assigned_to || 'Unassigned' }}</td><td class="px-5 text-sm text-slate-500">{{ item.target_due_at || 'Not set' }}</td></tr></tbody>
            </table>
            <AdminEmptyState v-else title="No open service requests" description="All paid requests have reached closeout." icon="fa-solid fa-circle-check" />
        </AdminTableFrame>

        <AdminTableFrame v-else-if="area === 'finance'" title="Confirmed this month" :description="financeDescription" icon="fa-solid fa-receipt" class="mt-4">
            <table v-if="payload.payments?.items?.length" class="portal-data-table portal-oversight-table"><colgroup><col style="width: 20%"><col style="width: 31%"><col style="width: 20%"><col style="width: 13%"><col style="width: 16%"></colgroup>
                <thead><tr><th class="px-5">Receipt</th><th>Provider</th><th>Service</th><th>Amount</th><th class="px-5">Paid</th></tr></thead>
                <tbody><tr v-for="item in payload.payments.items" :key="item.id"><td class="px-5 font-mono text-xs font-bold text-slate-900">{{ item.receipt_number }}</td><td><div class="flex items-center gap-3"><StaffTableIdentity kind="provider" :src="item.provider_logo_url" class="h-10 w-10" /><span><strong class="block text-sm text-slate-900">{{ item.provider }}</strong><span class="text-xs text-slate-500">{{ item.provider_email }}</span></span></div></td><td class="text-sm font-semibold text-slate-700">{{ item.service }}</td><td class="font-bold text-slate-950">{{ money(item.amount, item.currency) }}</td><td class="px-5 text-sm text-slate-500">{{ item.paid_at }}</td></tr></tbody>
            </table>
            <AdminEmptyState v-else title="No confirmed payments" description="No successful payments were recorded this month." icon="fa-solid fa-receipt" />
            <template v-if="payload.summary?.exceptions" #footer><span class="inline-flex items-center gap-2 text-sm font-bold text-amber-800"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>{{ payload.summary.exceptions }} payment {{ payload.summary.exceptions === 1 ? 'exception needs' : 'exceptions need' }} specialist review</span></template>
        </AdminTableFrame>

        <template v-else-if="area === 'records'">
            <AdminTableFrame title="Recent activity" :description="activityDescription" icon="fa-solid fa-clock-rotate-left" class="mt-4">
                    <table v-if="payload.activity?.items?.length" class="portal-data-table portal-oversight-table"><colgroup><col style="width: 39%"><col style="width: 26%"><col style="width: 16%"><col style="width: 19%"></colgroup>
                    <thead><tr><th class="px-5">Event</th><th>Actor</th><th>Category</th><th class="px-5">Recorded</th></tr></thead>
                    <tbody><tr v-for="item in payload.activity.items" :key="item.id"><td class="px-5"><p class="break-words font-bold text-slate-950">{{ item.description }}</p><p class="mt-1 text-xs text-slate-500">{{ item.action_label }}</p></td><td><p class="text-sm font-semibold text-slate-800">{{ item.actor_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ labelFromKey(item.actor_role) }}</p></td><td><span class="inline-flex bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ labelFromKey(item.category) }}</span></td><td class="px-5 text-sm text-slate-500">{{ item.created_at }}</td></tr></tbody>
                </table>
                <AdminEmptyState v-else title="No recent activity" description="No audit events were recorded in this period." icon="fa-solid fa-clock-rotate-left" />
            </AdminTableFrame>

            <AdminTableFrame title="Export coverage" description="Record counts and the latest export date for each dataset." icon="fa-solid fa-file-export" class="mt-4">
                <table v-if="payload.exports?.datasets?.length" class="portal-data-table portal-oversight-table"><colgroup><col style="width: 44%"><col style="width: 18%"><col style="width: 38%"></colgroup><thead><tr><th class="px-5">Dataset</th><th>Records</th><th class="px-5">Last exported</th></tr></thead><tbody><tr v-for="item in payload.exports.datasets" :key="item.key"><td class="px-5 font-bold text-slate-950">{{ item.name }}</td><td class="font-black text-slate-950">{{ item.records }}</td><td class="px-5 text-sm text-slate-500">{{ item.last_exported_at || 'Not yet exported' }}</td></tr></tbody></table>
                <AdminEmptyState v-else title="No dataset summary available" description="Refresh to check export coverage." icon="fa-solid fa-file-export" />
            </AdminTableFrame>
        </template>
        </template>
    </AdminWorkspacePage>
</template>
