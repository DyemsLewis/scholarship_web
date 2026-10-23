<script setup>
import { computed } from 'vue';
import { formatServiceDateTime } from '../../../../composables/useProviderServiceWorkspace.js';
import { labelFromKey } from '../../../../support/display';
import ProviderSidebar from '../../ProviderSidebar.vue';

const props = defineProps({
    purchase: { type: Object, default: null },
    workspace: { type: Object, required: true },
    activeSection: { type: String, required: true },
    loading: { type: Boolean, default: false },
    errorMessage: { type: String, default: '' },
    successMessage: { type: String, default: '' },
    directoryUrl: { type: String, default: '/provider/workspaces/billing' },
});

const sections = computed(() => {
    const id = props.purchase?.id ?? document.getElementById('app')?.dataset.purchaseId;
    return [
        { key: 'overview', label: 'Overview', icon: 'fa-solid fa-list-check', href: `/provider/billing/${id}` },
        { key: 'meeting', label: 'Meeting', icon: 'fa-regular fa-calendar', href: `/provider/billing/${id}/meeting` },
        { key: 'files', label: 'Files', icon: 'fa-regular fa-folder-open', href: `/provider/billing/${id}/files` },
        { key: 'updates', label: 'Updates', icon: 'fa-regular fa-message', href: `/provider/billing/${id}/updates` },
        { key: 'closeout', label: 'Closeout', icon: 'fa-solid fa-flag-checkered', href: `/provider/billing/${id}/closeout`, attention: ['provider_review', 'requested'].includes(props.purchase?.fulfillment_status) || props.purchase?.cancellation_status === 'requested' },
    ];
});

function statusLabel(purchase) {
    if (!purchase) return 'Loading';
    if (purchase.status !== 'paid') return `${labelFromKey(purchase.status)} payment`;
    return {
        queued: 'Waiting to start',
        ready: 'Waiting to start',
        needs_information: 'Response needed',
        in_progress: 'In progress',
        provider_review: 'Review delivery',
        completed: 'Completed',
        cancelled: 'Cancelled',
    }[purchase.fulfillment_status] ?? String(purchase.fulfillment_status || 'pending').replaceAll('_', ' ');
}

function statusClass(purchase) {
    if (purchase?.fulfillment_status === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (purchase?.fulfillment_status === 'cancelled') return 'border-slate-300 bg-slate-100 text-slate-700';
    if (purchase?.status !== 'paid' || ['provider_review', 'needs_information'].includes(purchase?.fulfillment_status)) return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function dueClass(status) {
    if (status === 'overdue') return 'text-rose-700';
    if (['due_today', 'due_soon'].includes(status)) return 'text-amber-700';
    return 'text-slate-500';
}
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page" aria-labelledby="service-record-title">
            <div class="mx-auto max-w-[94rem]">
                <a :href="directoryUrl" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to services
                </a>

                <header class="mt-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                    <div class="flex min-h-24 items-center justify-between gap-8 px-7 py-5">
                        <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i></span><div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Service workspace</p><h1 id="service-record-title" class="mt-1 truncate font-display text-2xl font-bold tracking-tight text-slate-950">{{ purchase?.plan_name || 'Service request' }}</h1><p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{{ purchase?.plan_description || workspace.headerDescription }}</p></div></div>
                        <span v-if="purchase" :class="['inline-flex shrink-0 border px-3 py-2 text-[10px] font-bold uppercase tracking-wide', statusClass(purchase)]">{{ statusLabel(purchase) }}</span>
                    </div>
                    <div v-if="purchase" class="grid grid-cols-3 divide-x divide-slate-200 border-t border-slate-200 bg-slate-50">
                        <div class="px-6 py-3.5">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Payment</p>
                            <p class="mt-1 text-sm font-bold text-slate-900">{{ labelFromKey(purchase.status) }}</p>
                        </div>
                        <div class="px-6 py-3.5">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Support owner</p>
                            <p class="mt-1 truncate text-sm font-bold text-slate-900">{{ purchase.assigned_to_name || 'Platform support queue' }}</p>
                        </div>
                        <div class="px-6 py-3.5">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Target</p>
                            <p class="mt-1 text-sm font-bold text-slate-900">{{ formatServiceDateTime(purchase.target_due_at) }}</p>
                        </div>
                    </div>
                    <div v-if="purchase?.current_action" class="flex items-center gap-3 border-t border-slate-200 px-6 py-3 text-sm">
                        <span class="font-bold text-slate-950">Next:</span>
                        <span class="text-slate-600">{{ purchase.current_action }}</span>
                        <span v-if="purchase.action_due_label" :class="['ml-auto text-xs font-bold', dueClass(purchase.action_due_status)]">{{ purchase.action_due_label }}</span>
                        <span :class="['text-xs font-semibold text-slate-500', { 'ml-auto': !purchase.action_due_label }]">{{ purchase.current_action_owner === 'provider' ? 'Your team' : 'Platform support' }}</span>
                        <a v-if="purchase.current_action_owner === 'provider' && purchase.current_action_url" :href="purchase.current_action_url" class="inline-flex items-center gap-2 text-xs font-bold text-slate-950 underline decoration-amber-400 decoration-2 underline-offset-4">Open task<i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a>
                    </div>
                </header>

                <nav v-if="purchase" class="mt-4 grid grid-cols-5 border border-slate-200 bg-white shadow-sm" aria-label="Service workspace sections">
                    <a v-for="section in sections" :key="section.key" :href="section.href" :aria-current="activeSection === section.key ? 'page' : undefined" :class="['flex h-14 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', activeSection === section.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50']">
                        <i :class="section.icon" aria-hidden="true"></i>
                        {{ section.label }}
                        <span v-if="section.attention" class="h-2 w-2 bg-amber-400" title="Decision needed"></span>
                    </a>
                </nav>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</div>
                <div v-if="loading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading service workspace...</p></div>
                <slot v-else-if="purchase"></slot>
            </div>
        </section>
    </main>
</template>
