<script setup>
import { computed, onMounted, ref } from 'vue';
import PortalManagerSidebar from '../../../components/PortalManagerSidebar.vue';
import TaskPageHeader from '../../../components/TaskPageHeader.vue';

const area = document.getElementById('app')?.dataset.area ?? 'accounts';
const areas = {
    accounts: { eyebrow: 'Account health', title: 'Account oversight', description: 'Watch access issues handled by the Account Manager.', icon: 'fa-solid fa-users-gear', endpoint: '/admin/workspaces/portal/accounts/data', owner: 'Account Manager' },
    reviews: { eyebrow: 'Verification health', title: 'Review oversight', description: 'Compare verification workload across review queues.', icon: 'fa-solid fa-clipboard-check', endpoint: '/admin/workspaces/portal/reviews/data', owner: 'Review Officer' },
    support: { eyebrow: 'Support health', title: 'Support oversight', description: 'See unresolved concerns and response workload.', icon: 'fa-solid fa-headset', endpoint: '/admin/workspaces/portal/support/data', owner: 'Support Officer' },
    services: { eyebrow: 'Delivery health', title: 'Service delivery oversight', description: 'Track paid requests across delivery states.', icon: 'fa-solid fa-file-invoice-dollar', endpoint: '/admin/workspaces/portal/billing/data', owner: 'Billing Officer' },
    finance: { eyebrow: 'Financial health', title: 'Finance oversight', description: 'Monitor confirmed revenue and payment exceptions.', icon: 'fa-solid fa-chart-line', endpoint: '/admin/workspaces/portal/finance/data', owner: 'Finance Officer' },
    records: { eyebrow: 'Record health', title: 'Records oversight', description: 'Monitor activity volume, security events, and exports.', icon: 'fa-solid fa-box-archive', endpoint: '/admin/workspaces/portal/records/data', owner: 'Records Officer' },
};
const config = areas[area] ?? areas.accounts;
const isLoading = ref(true);
const errorMessage = ref('');
const payload = ref({});
const serviceItems = computed(() => ['ready', 'active', 'waiting'].flatMap((key) => payload.value.queues?.[key]?.items ?? []));

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: currency || 'PHP', minimumFractionDigits: 2 }).format(Number(amount ?? 0) / 100);
}

function reviewNames(group) {
    const values = (group.items ?? []).map((item) => item.name || item.title || item.recipient || item.program || item.release).filter(Boolean);
    return values.length ? values.join(' · ') : 'No waiting records';
}

async function loadArea() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const { data } = await window.axios.get(config.endpoint);
        payload.value = data;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load this oversight page.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadArea);
</script>

<template>
    <main class="admin-shell">
        <PortalManagerSidebar />
        <section class="admin-page">
            <div class="admin-container">
                <TaskPageHeader theme="admin" :eyebrow="config.eyebrow" :title="config.title" :description="config.description" :icon="config.icon">
                    <template #actions><button type="button" class="rounded border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="loadArea"><i class="fa-solid fa-rotate mr-1.5 text-xs" aria-hidden="true"></i>Refresh</button></template>
                </TaskPageHeader>

                <div v-if="isLoading" class="admin-panel mt-5 p-6 text-sm text-slate-500">Loading oversight data...</div>
                <div v-else class="admin-content-stack">
                    <p v-if="errorMessage" class="border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                    <section class="flex items-center justify-between border border-slate-200 bg-slate-50 px-5 py-3"><p class="text-sm text-slate-600"><strong class="text-slate-950">Operational owner:</strong> {{ config.owner }}</p><p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Manager view</p></section>

                    <template v-if="area === 'accounts'">
                        <section class="admin-panel overflow-hidden"><dl class="grid grid-cols-5 divide-x divide-slate-200"><div v-for="item in [['All accounts', payload.summary?.total], ['Needs attention', payload.summary?.attention_total], ['Unverified', payload.summary?.unverified], ['Password reset', payload.summary?.password_reset], ['Suspended', payload.summary?.suspended]]" :key="item[0]" class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">{{ item[0] }}</dt><dd class="mt-1 text-xl font-black text-slate-950">{{ item[1] ?? 0 }}</dd></div></dl></section>
                        <section class="admin-panel overflow-hidden">
                            <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-black text-slate-950">Access exceptions</h2><p class="mt-1 text-sm text-slate-500">Most recently updated accounts that need officer action.</p></header>
                            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Account</th><th class="px-6 py-3">Role</th><th class="px-6 py-3">Issue</th><th class="px-6 py-3">Updated</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="user in payload.users" :key="user.id"><td class="px-6 py-4"><strong class="block text-sm text-slate-950">{{ user.name }}</strong><span class="text-xs text-slate-500">{{ user.email }}</span></td><td class="px-6 py-4 text-sm text-slate-600">{{ user.role }}</td><td class="px-6 py-4 text-sm font-semibold text-amber-800">{{ user.issue }}</td><td class="px-6 py-4 text-sm text-slate-500">{{ user.updated_at }}</td></tr><tr v-if="!payload.users?.length"><td colspan="4" class="empty-row">No account exceptions.</td></tr></tbody></table></div>
                        </section>
                    </template>

                    <template v-else-if="area === 'reviews'">
                        <section class="admin-panel overflow-hidden"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Review queue</th><th class="px-6 py-3">Waiting</th><th class="px-6 py-3">Current records</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="group in payload.groups" :key="group.key"><td class="px-6 py-5 font-bold text-slate-950">{{ group.label }}</td><td class="px-6 py-5"><span :class="['rounded px-2.5 py-1 text-xs font-black', group.count ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-800']">{{ group.count }}</span></td><td class="max-w-2xl px-6 py-5 text-sm text-slate-600">{{ reviewNames(group) }}</td></tr></tbody></table></div></section>
                    </template>

                    <template v-else-if="area === 'support'">
                        <section class="admin-panel overflow-hidden"><dl class="grid grid-cols-4 divide-x divide-slate-200"><div v-for="item in [['Needs response', payload.summary?.needs_response], ['Waiting for provider', payload.summary?.waiting], ['Resolved', payload.summary?.resolved], ['Privacy requests', payload.summary?.privacy_open]]" :key="item[0]" class="px-5 py-4"><dt class="stat-label">{{ item[0] }}</dt><dd class="stat-value">{{ item[1] ?? 0 }}</dd></div></dl></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Cases awaiting response</h2></header><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Concern</th><th class="px-6 py-3">Reporter</th><th class="px-6 py-3">Category</th><th class="px-6 py-3">Submitted</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in payload.cases?.items" :key="item.id"><td class="px-6 py-4 font-bold text-slate-950">{{ item.subject }}</td><td class="px-6 py-4"><span class="block text-sm text-slate-700">{{ item.reporter.name }}</span><span class="text-xs text-slate-500">{{ item.reporter.type }}</span></td><td class="px-6 py-4 text-sm text-slate-600">{{ item.category_label }}</td><td class="px-6 py-4 text-sm text-slate-500">{{ item.submitted_at }}</td></tr><tr v-if="!payload.cases?.items?.length"><td colspan="4" class="empty-row">No cases need a response.</td></tr></tbody></table></div></section>
                    </template>

                    <template v-else-if="area === 'services'">
                        <section class="admin-panel overflow-hidden"><dl class="grid grid-cols-4 divide-x divide-slate-200"><div v-for="item in [['Ready', payload.summary?.ready], ['In progress', payload.summary?.active], ['Waiting', payload.summary?.waiting], ['Meeting requests', payload.summary?.meeting_requests]]" :key="item[0]" class="px-5 py-4"><dt class="stat-label">{{ item[0] }}</dt><dd class="stat-value">{{ item[1] ?? 0 }}</dd></div></dl></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Open delivery workload</h2></header><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Provider</th><th class="px-6 py-3">Service</th><th class="px-6 py-3">State</th><th class="px-6 py-3">Assigned to</th><th class="px-6 py-3">Target</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in serviceItems" :key="item.id"><td class="px-6 py-4 font-bold text-slate-950">{{ item.provider }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ item.plan_name }}</td><td class="px-6 py-4 text-sm font-semibold text-slate-700">{{ item.state_label }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ item.assigned_to || 'Unassigned' }}</td><td class="px-6 py-4 text-sm text-slate-500">{{ item.target_due_at || 'Not set' }}</td></tr><tr v-if="!serviceItems.length"><td colspan="5" class="empty-row">No open service requests.</td></tr></tbody></table></div></section>
                    </template>

                    <template v-else-if="area === 'finance'">
                        <section class="admin-panel overflow-hidden"><dl class="grid grid-cols-4 divide-x divide-slate-200"><div v-for="item in [['Revenue today', money(payload.summary?.today)], ['Revenue this month', money(payload.summary?.month)], ['Confirmed payments', payload.summary?.successful_payments], ['Exceptions', payload.summary?.exceptions]]" :key="item[0]" class="px-5 py-4"><dt class="stat-label">{{ item[0] }}</dt><dd class="stat-value">{{ item[1] ?? 0 }}</dd></div></dl></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Confirmed this month</h2></header><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Receipt</th><th class="px-6 py-3">Provider</th><th class="px-6 py-3">Service</th><th class="px-6 py-3">Amount</th><th class="px-6 py-3">Paid</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in payload.payments?.items" :key="item.id"><td class="px-6 py-4 font-bold text-slate-950">{{ item.receipt_number }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ item.provider }}</td><td class="px-6 py-4 text-sm text-slate-600">{{ item.service }}</td><td class="px-6 py-4 text-sm font-bold text-slate-950">{{ money(item.amount, item.currency) }}</td><td class="px-6 py-4 text-sm text-slate-500">{{ item.paid_at }}</td></tr><tr v-if="!payload.payments?.items?.length"><td colspan="5" class="empty-row">No confirmed payments this month.</td></tr></tbody></table></div></section>
                        <section v-if="payload.exceptions?.items?.length" class="admin-panel overflow-hidden"><header class="section-head"><h2>Payment exceptions</h2></header><div class="divide-y divide-slate-200"><div v-for="item in payload.exceptions.items" :key="item.id" class="flex items-center gap-5 px-6 py-4"><span class="min-w-0 flex-1"><strong class="block text-sm text-slate-950">{{ item.provider }} · {{ item.service }}</strong><span class="mt-1 block text-xs text-slate-500">{{ item.reference_number }}</span></span><span class="text-sm font-bold capitalize text-rose-700">{{ item.status }}</span></div></div></section>
                    </template>

                    <template v-else-if="area === 'records'">
                        <section class="admin-panel overflow-hidden"><dl class="grid grid-cols-4 divide-x divide-slate-200"><div v-for="item in [['All events', payload.activity?.summary?.total], ['Today', payload.activity?.summary?.today], ['Security events', payload.activity?.summary?.security_7_days], ['Exports', payload.activity?.summary?.exports_30_days]]" :key="item[0]" class="px-5 py-4"><dt class="stat-label">{{ item[0] }}</dt><dd class="stat-value">{{ item[1] ?? 0 }}</dd></div></dl></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Recent activity</h2></header><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left"><thead class="table-head"><tr><th class="px-6 py-3">Event</th><th class="px-6 py-3">Actor</th><th class="px-6 py-3">Category</th><th class="px-6 py-3">Recorded</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in payload.activity?.items" :key="item.id"><td class="px-6 py-4"><strong class="block text-sm text-slate-950">{{ item.action_label }}</strong><span class="mt-1 block max-w-2xl text-xs text-slate-500">{{ item.description }}</span></td><td class="px-6 py-4 text-sm text-slate-600">{{ item.actor_name }}</td><td class="px-6 py-4 text-sm capitalize text-slate-600">{{ item.category }}</td><td class="px-6 py-4 text-sm text-slate-500">{{ item.created_at }}</td></tr><tr v-if="!payload.activity?.items?.length"><td colspan="4" class="empty-row">No activity in this period.</td></tr></tbody></table></div></section>
                        <section class="admin-panel overflow-hidden"><header class="section-head"><h2>Export coverage</h2></header><div class="divide-y divide-slate-200"><div v-for="item in payload.exports?.datasets" :key="item.key" class="flex items-center gap-5 px-6 py-4"><span class="min-w-0 flex-1"><strong class="block text-sm text-slate-950">{{ item.name }}</strong><span class="mt-1 block text-xs text-slate-500">Last export: {{ item.last_exported_at || 'Not yet exported' }}</span></span><span class="text-lg font-black text-slate-950">{{ item.records }}</span></div></div></section>
                    </template>
                </div>
            </div>
        </section>
    </main>
</template>

<style scoped>
.table-head {
    background: #f8fafc;
    color: #64748b;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.empty-row { padding: 2rem 1.5rem; text-align: center; color: #64748b; font-size: 0.875rem; }
.stat-label { color: #64748b; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; }
.stat-value { margin-top: 0.25rem; color: #020617; font-size: 1.25rem; font-weight: 900; }
.section-head { border-bottom: 1px solid #e2e8f0; padding: 1rem 1.5rem; }
.section-head h2 { color: #020617; font-size: 1.125rem; font-weight: 900; }
</style>
