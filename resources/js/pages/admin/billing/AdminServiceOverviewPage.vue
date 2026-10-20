<script setup>
import { computed } from 'vue';
import { formatAdminServiceDateTime, formatAdminServiceMoney, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';

const { purchaseId, purchase, isLoading, errorMessage, successMessage, queueUrl } = useAdminServiceWorkspace('overview');
const milestoneProgress = computed(() => {
    const milestones = purchase.value?.milestones ?? [];
    return `${milestones.filter((item) => item.completed).length} of ${milestones.length}`;
});
</script>

<template>
    <AdminServiceWorkspaceShell
        :purchase="purchase"
        active-section="overview"
        :loading="isLoading"
        :error-message="errorMessage"
        :success-message="successMessage"
        :queue-url="queueUrl"
    >
        <section class="admin-panel mt-4 overflow-hidden">
            <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4">
                <div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i></span><div><h2 class="text-lg font-black text-slate-950">Request brief</h2><p class="mt-0.5 text-sm text-slate-500">What was purchased and what the provider expects.</p></div></div>
                <a :href="`/admin/workspaces/billing/requests/${purchaseId}/delivery`" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white">Open delivery plan <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
            </header>

            <dl class="grid grid-cols-4 divide-x divide-slate-200 border-b border-slate-200 bg-slate-50">
                <div class="px-5 py-3.5"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Payment</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ formatAdminServiceMoney(purchase.amount, purchase.currency) }}</dd></div>
                <div class="px-5 py-3.5"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Paid</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ formatAdminServiceDateTime(purchase.paid_at) }}</dd></div>
                <div class="px-5 py-3.5"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Priority</dt><dd class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.priority }}</dd></div>
                <div class="px-5 py-3.5"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Milestones</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ milestoneProgress }} complete</dd></div>
            </dl>

            <div class="divide-y divide-slate-200">
                <article class="px-6 py-5"><h3 class="text-sm font-black text-slate-950">Service scope</h3><p class="mt-2 max-w-5xl text-sm leading-6 text-slate-600">{{ purchase.plan_description || purchase.plan_name }}</p></article>
                <article class="px-6 py-5"><h3 class="text-sm font-black text-slate-950">Provider request</h3><p class="mt-2 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ purchase.request_summary || 'No additional request summary was supplied.' }}</p></article>
                <article class="px-6 py-5"><h3 class="text-sm font-black text-slate-950">Expected outcome</h3><p class="mt-2 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ purchase.requested_outcome || 'No additional outcome was supplied.' }}</p></article>
            </div>
        </section>
    </AdminServiceWorkspaceShell>
</template>
