<script setup>
import { computed, ref, watch } from 'vue';
import { adminServiceDateInput, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';

const { purchaseId, purchase, assignees, statusOptions, isLoading, isSaving, errorMessage, successMessage, queueUrl, runAction } = useAdminServiceWorkspace('delivery');
const workflow = ref({ fulfillment_status: 'ready', assigned_to: '', priority: 'normal', target_due_at: '', milestones: [], fulfillment_notes: '', provider_update: '', internal_note: '' });
const minimumTargetDate = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const completedMilestones = computed(() => workflow.value.milestones.filter((item) => item.completed).length);
const needsProviderMessage = computed(() => ['needs_information', 'provider_review'].includes(workflow.value.fulfillment_status));

watch(purchase, (payload) => {
    if (!payload) return;
    workflow.value = {
        fulfillment_status: payload.fulfillment_status === 'queued' ? 'ready' : payload.fulfillment_status,
        assigned_to: payload.assigned_to ?? '',
        priority: payload.priority ?? 'normal',
        target_due_at: adminServiceDateInput(payload.target_due_at),
        milestones: (payload.milestones ?? []).map((item) => ({ ...item })),
        fulfillment_notes: payload.fulfillment_notes ?? '',
        provider_update: '',
        internal_note: '',
    };
});

async function saveDelivery() {
    await runAction(
        () => window.axios.patch(`/admin/billing/${purchaseId}/fulfillment`, {
            ...workflow.value,
            assigned_to: workflow.value.assigned_to || null,
            target_due_at: workflow.value.target_due_at || null,
            provider_update: workflow.value.provider_update.trim() || null,
            internal_note: workflow.value.internal_note.trim() || null,
        }),
        'Delivery plan saved.',
    );
}
</script>

<template>
    <AdminServiceWorkspaceShell
        :purchase="purchase"
        active-section="delivery"
        :loading="isLoading"
        :error-message="errorMessage"
        :success-message="successMessage"
        :queue-url="queueUrl"
    >
        <form class="admin-panel mt-4 overflow-hidden" @submit.prevent="saveDelivery">
            <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-black text-slate-950">Delivery plan</h2><p class="mt-1 text-sm text-slate-500">Assign the work, set its state, and track the agreed output.</p></header>
            <div class="space-y-6 px-6 py-5">
                <div class="grid grid-cols-4 gap-4">
                    <label class="text-sm font-bold text-slate-700">Work state<select v-model="workflow.fulfillment_status" :disabled="['completed', 'cancelled'].includes(purchase.fulfillment_status)" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option v-if="purchase.fulfillment_status === 'cancelled'" value="cancelled">Cancelled</option><option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                    <label class="text-sm font-bold text-slate-700">Assignee<select v-model="workflow.assigned_to" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="">Unassigned</option><option v-for="person in assignees" :key="person.id" :value="person.id">{{ person.name }}</option></select></label>
                    <label class="text-sm font-bold text-slate-700">Priority<select v-model="workflow.priority" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
                    <label class="text-sm font-bold text-slate-700">Target date<input v-model="workflow.target_due_at" type="date" :min="minimumTargetDate" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"></label>
                </div>

                <fieldset>
                    <div class="flex items-center justify-between"><legend class="text-sm font-black text-slate-900">Delivery milestones</legend><span class="text-xs font-bold text-slate-500">{{ completedMilestones }} of {{ workflow.milestones.length }} complete</span></div>
                    <div class="mt-3 divide-y divide-slate-200 border border-slate-200">
                        <label v-for="item in workflow.milestones" :key="item.id" class="flex cursor-pointer items-center gap-3 px-4 py-3"><input v-model="item.completed" type="checkbox" class="h-4 w-4 accent-slate-950"><span class="text-sm font-semibold text-slate-700">{{ item.label }}</span></label>
                        <p v-if="!workflow.milestones.length" class="px-4 py-6 text-sm text-slate-500">No milestones are configured for this service.</p>
                    </div>
                </fieldset>

                <label class="block text-sm font-bold text-slate-700">Current progress <span class="font-normal text-slate-500">visible to provider</span><textarea v-model="workflow.fulfillment_notes" rows="3" maxlength="2000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" placeholder="Briefly state what is complete and what comes next."></textarea></label>
                <label v-if="needsProviderMessage" class="block text-sm font-bold text-slate-700">Provider message <span class="font-normal text-slate-500">required for this state</span><textarea v-model="workflow.provider_update" rows="3" maxlength="2000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" :placeholder="workflow.fulfillment_status === 'needs_information' ? 'State exactly what the provider must send.' : 'Summarize what the provider should review.'"></textarea></label>
                <label class="block text-sm font-bold text-slate-700">Internal note <span class="font-normal text-slate-500">optional</span><textarea v-model="workflow.internal_note" rows="2" maxlength="2000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" placeholder="Visible only to administrators."></textarea></label>
            </div>
            <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="submit" :disabled="isSaving || ['completed', 'cancelled'].includes(purchase.fulfillment_status)" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40">{{ isSaving ? 'Saving...' : 'Save delivery plan' }}</button></footer>
        </form>
    </AdminServiceWorkspaceShell>
</template>
