<script setup>
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';
import { computed, ref, watch } from 'vue';
import { adminServiceDateInput, adminServiceTargetDateError, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';

const { purchaseId, purchase, assignees, statusOptions, isLoading, loadError, loadRecord, isSaving, canEdit, errorMessage, successMessage, queueUrl, runAction } = useAdminServiceWorkspace('delivery');
const workflow = ref({ fulfillment_status: 'ready', assigned_to: '', priority: 'normal', target_due_at: '', milestones: [], fulfillment_notes: '', provider_update: '', internal_note: '' });
const today = adminServiceDateInput(new Date());
const savedTargetDate = computed(() => adminServiceDateInput(purchase.value?.target_due_at));
const savedTargetOverdue = computed(() => Boolean(savedTargetDate.value && savedTargetDate.value < today));
const minimumTargetDate = computed(() => savedTargetOverdue.value && workflow.value.target_due_at === savedTargetDate.value ? savedTargetDate.value : today);
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
    if (!canEdit.value || isSaving.value) return;
    const dateError = adminServiceTargetDateError(workflow.value.target_due_at, purchase.value.target_due_at);
    if (dateError) { errorMessage.value = dateError; return; }
    if (needsProviderMessage.value && !workflow.value.provider_update.trim()) {
        errorMessage.value = 'Add a provider message explaining what to send or review.';
        return;
    }
    if (workflow.value.fulfillment_status !== purchase.value.fulfillment_status && !await confirmOutcome({
        record: 'Service delivery', outcome: workflow.value.fulfillment_status,
        consequence: 'The delivery status and updates will be saved. Provider acceptance remains a separate action.',
    }, () => canEdit.value && !isSaving.value)) return;
    await runAction(
        (config) => window.axios.patch(`/admin/billing/${purchaseId}/fulfillment`, {
            ...workflow.value,
            assigned_to: workflow.value.assigned_to || null,
            target_due_at: workflow.value.target_due_at || null,
            provider_update: workflow.value.provider_update.trim() || null,
            internal_note: workflow.value.internal_note.trim() || null,
        }, config),
        'Delivery plan saved.',
    );
}
</script>

<template>
    <AdminServiceWorkspaceShell
        :purchase="purchase"
        active-section="delivery"
        :loading="isLoading" :load-error="loadError" @retry="loadRecord"
        :error-message="errorMessage"
        :success-message="successMessage"
        :queue-url="queueUrl"
    >
        <form class="admin-panel mt-4 overflow-hidden" :aria-busy="isSaving" @submit.prevent="saveDelivery">
            <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span><div><h2 class="text-lg font-black text-slate-950">Delivery plan</h2><p class="mt-0.5 text-sm text-slate-500">Assign the work, set its state, and track the agreed output.</p></div></header>
            <p v-if="!canEdit" class="border-b border-slate-200 bg-slate-50 px-6 py-4 text-sm font-semibold text-slate-700">{{ purchase.cancellation_status === 'requested' ? 'Review the cancellation in Closeout before continuing delivery.' : 'Delivery is read-only for this request state.' }}</p>
            <fieldset :disabled="isSaving || !canEdit" class="space-y-6 px-6 py-5">
                <div class="grid grid-cols-4 gap-4">
                    <label class="text-sm font-bold text-slate-700">Work state<select v-model="workflow.fulfillment_status" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option v-if="['completed', 'cancelled'].includes(purchase.fulfillment_status)" :value="purchase.fulfillment_status">{{ purchase.fulfillment_status === 'completed' ? 'Completed' : 'Cancelled' }}</option><option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                    <label class="text-sm font-bold text-slate-700">Assignee<select v-model="workflow.assigned_to" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="">Unassigned</option><option v-for="person in assignees" :key="person.id" :value="person.id">{{ person.name }}</option></select></label>
                    <label class="text-sm font-bold text-slate-700">Priority<select v-model="workflow.priority" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
                    <label class="text-sm font-bold text-slate-700">Target date<input v-model="workflow.target_due_at" type="date" :min="minimumTargetDate" aria-describedby="target-date-help" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"></label>
                </div>
                <p id="target-date-help" :class="['text-sm', savedTargetOverdue ? 'font-semibold text-amber-800' : 'text-slate-500']">{{ savedTargetOverdue ? 'The saved target is overdue. You can keep it while updating progress, or set today or a future date.' : 'New target dates must be today or later. Dates use Philippine time.' }}</p>

                <fieldset>
                    <div class="flex items-center justify-between"><legend class="text-sm font-black text-slate-900">Delivery milestones</legend><span class="text-xs font-bold text-slate-500">{{ completedMilestones }} of {{ workflow.milestones.length }} complete</span></div>
                    <div class="mt-3 divide-y divide-slate-200 border border-slate-200">
                        <label v-for="item in workflow.milestones" :key="item.id" class="flex cursor-pointer items-center gap-3 px-4 py-3"><input v-model="item.completed" type="checkbox" class="h-4 w-4 accent-slate-950"><span class="text-sm font-semibold text-slate-700">{{ item.label }}</span></label>
                        <p v-if="!workflow.milestones.length" class="px-4 py-6 text-sm text-slate-500">No milestones are configured for this service.</p>
                    </div>
                </fieldset>

                <label class="block text-sm font-bold text-slate-700">Current progress <span class="font-normal text-slate-500">visible to provider</span><textarea v-model="workflow.fulfillment_notes" rows="3" maxlength="2000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" placeholder="Briefly state what is complete and what comes next."></textarea></label>
                <label v-if="needsProviderMessage" class="block text-sm font-bold text-slate-700">Provider message <span class="font-normal text-slate-500">required and shared with provider</span><textarea v-model="workflow.provider_update" rows="3" maxlength="2000" required class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" :placeholder="workflow.fulfillment_status === 'needs_information' ? 'State exactly what the provider must send.' : 'Summarize what the provider should review.'"></textarea></label>
                <label class="block text-sm font-bold text-slate-700">Internal note <span class="font-normal text-slate-500">optional, administrators only</span><textarea v-model="workflow.internal_note" rows="2" maxlength="2000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" placeholder="Visible only to administrators."></textarea></label>
            </fieldset>
            <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="submit" :disabled="isSaving || !canEdit" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40">{{ isSaving ? 'Saving...' : 'Save delivery plan' }}</button></footer>
        </form>
    </AdminServiceWorkspaceShell>
</template>
