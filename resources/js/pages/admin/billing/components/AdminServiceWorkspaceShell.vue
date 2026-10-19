<script setup>
import { computed } from 'vue';
import { formatAdminServiceDateTime } from '../../../../composables/useAdminServiceWorkspace.js';
import AdminWorkspaceHeader from '../../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../../components/AdminWorkspacePage.vue';

const props = defineProps({
    purchase: { type: Object, default: null },
    activeSection: { type: String, required: true },
    loading: { type: Boolean, default: false },
    errorMessage: { type: String, default: '' },
    successMessage: { type: String, default: '' },
    queueUrl: { type: String, default: '/admin/workspaces/billing' },
});

const sections = computed(() => {
    const id = props.purchase?.id ?? document.getElementById('app')?.dataset.purchaseId;
    const base = `/admin/workspaces/billing/requests/${id}`;
    return [
        { key: 'overview', label: 'Overview', icon: 'fa-solid fa-clipboard-list', href: base },
        { key: 'delivery', label: 'Delivery', icon: 'fa-solid fa-list-check', href: `${base}/delivery` },
        { key: 'files', label: 'Files', icon: 'fa-regular fa-folder-open', href: `${base}/files` },
        { key: 'updates', label: 'Updates', icon: 'fa-regular fa-message', href: `${base}/updates` },
        { key: 'meeting', label: 'Meeting', icon: 'fa-regular fa-calendar', href: `${base}/meeting`, attention: props.purchase?.meeting_status === 'requested' },
        { key: 'closeout', label: 'Closeout', icon: 'fa-solid fa-flag-checkered', href: `${base}/closeout`, attention: props.purchase?.cancellation_status === 'requested' },
    ];
});

function statusLabel(status) {
    return {
        queued: 'Ready to start',
        ready: 'Ready to start',
        needs_information: 'Waiting for provider',
        in_progress: 'In progress',
        provider_review: 'Provider review',
        completed: 'Completed',
        cancelled: 'Cancelled',
    }[status] ?? String(status || 'pending').replaceAll('_', ' ');
}

function statusClass(status) {
    if (status === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (['needs_information', 'provider_review'].includes(status)) return 'border-amber-200 bg-amber-50 text-amber-900';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function dueClass(status) {
    if (status === 'overdue') return 'text-rose-700';
    if (['due_today', 'due_soon'].includes(status)) return 'text-amber-700';
    return 'text-slate-500';
}
</script>

<template>
    <AdminWorkspacePage workspace="billing_officer">
        <a :href="queueUrl" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
            Back to queue
        </a>

        <AdminWorkspaceHeader
            class="mt-4"
            eyebrow="Service delivery"
            :title="purchase?.plan_name || 'Service request'"
            :description="purchase ? `${purchase.provider.name} · ${purchase.reference_number}` : 'Loading request'"
            icon="fa-solid fa-file-invoice-dollar"
        >
            <template v-if="purchase" #actions>
                <span :class="['inline-flex border px-3 py-2 text-[10px] font-bold uppercase tracking-wide', statusClass(purchase.fulfillment_status)]">
                    {{ statusLabel(purchase.fulfillment_status) }}
                </span>
            </template>
        </AdminWorkspaceHeader>

        <div v-if="purchase" class="grid grid-cols-3 divide-x divide-slate-200 border border-t-0 border-slate-200 bg-white shadow-sm">
            <div class="px-5 py-3.5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Assigned to</p><p class="mt-1 truncate text-sm font-bold text-slate-900">{{ purchase.assigned_to_name || 'Unassigned' }}</p></div>
            <div class="px-5 py-3.5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Target</p><p class="mt-1 text-sm font-bold text-slate-900">{{ formatAdminServiceDateTime(purchase.target_due_at) }}</p></div>
            <div class="px-5 py-3.5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Next owner</p><p class="mt-1 text-sm font-bold text-slate-900">{{ purchase.current_action_owner === 'provider' ? 'Provider' : (purchase.current_action_owner === 'platform' ? 'Platform team' : 'No action') }}</p></div>
        </div>

        <div v-if="purchase?.current_action" class="flex min-h-12 items-center gap-3 border border-t-0 border-slate-200 bg-white px-5 text-sm shadow-sm">
            <span class="font-black text-slate-950">Next:</span>
            <span class="text-slate-600">{{ purchase.current_action }}</span>
            <span v-if="purchase.action_due_label" :class="['ml-auto text-xs font-bold', dueClass(purchase.action_due_status)]">{{ purchase.action_due_label }}</span>
            <a v-if="purchase.current_action_owner === 'platform' && purchase.current_action_url" :href="purchase.current_action_url" class="text-xs font-black text-slate-950 underline decoration-amber-400 decoration-2 underline-offset-4">Open task</a>
        </div>

        <nav v-if="purchase" class="mt-4 grid grid-cols-6 border border-slate-200 bg-white shadow-sm" aria-label="Service delivery sections">
            <a v-for="item in sections" :key="item.key" :href="item.href" :aria-current="activeSection === item.key ? 'page' : undefined" :class="['flex h-14 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', activeSection === item.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50']">
                <i :class="item.icon" aria-hidden="true"></i>{{ item.label }}
                <span v-if="item.attention" class="h-2 w-2 bg-amber-400" title="Decision needed"></span>
            </a>
        </nav>

        <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>
        <p v-if="successMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</p>
        <div v-if="loading" class="admin-panel mt-4 px-6 py-16 text-center text-sm font-semibold text-slate-500">Loading service request...</div>
        <slot v-else-if="purchase"></slot>
    </AdminWorkspacePage>
</template>
