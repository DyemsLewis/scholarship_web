<script setup>
import { staffQueueLink, staffQueueReturn } from '../../../support/staffQueueState.js';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';
import { computed, reactive, ref } from 'vue';
import { formatAdminServiceDateTime, useAdminServiceWorkspace } from '../../../composables/useAdminServiceWorkspace.js';
import AdminServiceWorkspaceShell from './components/AdminServiceWorkspaceShell.vue';

const { purchaseId, purchase, isLoading, loadError, loadRecord, isSaving, errorMessage, successMessage, queueUrl, runAction } = useAdminServiceWorkspace('closeout');
const decision = reactive({ decision_note: '', refund_status: 'not_applicable', refund_reference: '' });
const restoreReason = ref('');
const canRestore = computed(() => purchase.value?.fulfillment_status === 'cancelled'
    && purchase.value?.status === 'paid' && !['manual_refund_pending', 'refunded'].includes(purchase.value?.refund_status));
const canDecide = computed(() => purchase.value?.status === 'paid' && purchase.value?.cancellation_status === 'requested');

async function decide(value) {
    if (!canDecide.value || isSaving.value || !['approved', 'declined'].includes(value)) return;
    if (decision.decision_note.trim().length < 10 || decision.decision_note.trim().length > 2000) {
        errorMessage.value = 'Add a decision note of 10 to 2000 characters.'; return;
    }
    if (value === 'approved' && decision.refund_status === 'refunded' && decision.refund_reference.trim().length < 3) {
        errorMessage.value = 'Add the reference for the completed refund.'; return;
    }
    if (!await confirmOutcome({
        record: purchase.value.service?.name || purchase.value.service_name || 'Service request',
        outcome: value === 'approved' ? `Cancellation approved / Refund: ${readable(decision.refund_status)}` : 'Cancellation declined',
        consequence: 'This saves the cancellation decision and refund record. It does not send an external refund automatically.',
        danger: value === 'approved',
    }, () => canDecide.value && !isSaving.value)) return;
    await runAction((config) => window.axios.patch(`/admin/billing/${purchaseId}/cancellation`, {
        decision: value,
        decision_note: decision.decision_note.trim(),
        refund_status: value === 'approved' ? decision.refund_status : null,
        refund_reference: value === 'approved' ? decision.refund_reference.trim() || null : null,
    }, config), value === 'approved' ? 'Cancellation approved.' : 'Cancellation declined.');
}

async function restore() {
    if (!canRestore.value || isSaving.value) return;
    if (restoreReason.value.trim().length < 10 || restoreReason.value.trim().length > 2000) { errorMessage.value = 'Add a restoration reason of 10 to 2000 characters.'; return; }
    if (!await confirmOutcome({ record: 'Service request', outcome: 'Service restored', consequence: 'The cancelled service will return to the delivery workflow. Existing payment records remain unchanged.' }, () => canRestore.value && !isSaving.value)) return;
    const saved = await runAction((config) => window.axios.post(`/admin/billing/${purchaseId}/restore`, {
        reason: restoreReason.value.trim(),
    }, config), 'Service restored.');
    if (saved) restoreReason.value = '';
}

function readable(value) { return String(value || 'Not recorded').replaceAll('_', ' '); }
function serviceRecordLink(href) {
    return staffQueueLink(href, staffQueueReturn(window, 'adminBilling'), window.location.origin);
}
</script>

<template>
    <AdminServiceWorkspaceShell :purchase="purchase" active-section="closeout" :loading="isLoading" :load-error="loadError" @retry="loadRecord" :error-message="errorMessage" :success-message="successMessage" :queue-url="queueUrl">
        <section class="admin-panel mt-4 overflow-hidden">
            <header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-flag-checkered" aria-hidden="true"></i></span><div><h2 class="text-lg font-black text-slate-950">Closeout</h2><p class="mt-0.5 text-sm text-slate-500">Review provider acceptance, revisions, and cancellation.</p></div></div><a :href="serviceRecordLink(`/admin/workspaces/billing/requests/${purchaseId}/history`)" class="inline-flex h-10 items-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:border-slate-950">View audit history <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a></header>

            <div class="divide-y divide-slate-200">
                <article class="px-6 py-5"><h3 class="text-sm font-black text-slate-950">Delivery outcome</h3><p class="mt-2 text-sm text-slate-600"><span class="font-bold capitalize text-slate-900">{{ readable(purchase.fulfillment_status) }}</span><span v-if="purchase.provider_confirmed_at"> · confirmed {{ formatAdminServiceDateTime(purchase.provider_confirmed_at) }}</span><span v-if="purchase.revision_count"> · {{ purchase.revision_count }} revision{{ purchase.revision_count === 1 ? '' : 's' }}</span></p><p v-if="purchase.provider_feedback" class="mt-3 border-l-2 border-amber-400 pl-3 text-sm text-slate-700">{{ purchase.provider_feedback }}</p></article>

                <article v-if="purchase.cancellation_status" class="px-6 py-5"><h3 class="text-sm font-black capitalize text-slate-950">Cancellation {{ readable(purchase.cancellation_status) }}</h3><dl class="mt-3 divide-y divide-slate-200 border-y border-slate-200 text-sm"><div class="flex gap-8 py-3"><dt class="w-40 text-slate-500">Provider reason</dt><dd class="font-semibold text-slate-900">{{ purchase.cancellation_reason }}</dd></div><div v-if="purchase.cancellation_decision_note" class="flex gap-8 py-3"><dt class="w-40 text-slate-500">Decision note</dt><dd class="font-semibold text-slate-900">{{ purchase.cancellation_decision_note }}</dd></div><div v-if="purchase.refund_status" class="flex gap-8 py-3"><dt class="w-40 text-slate-500">Refund record</dt><dd class="font-semibold capitalize text-slate-900">{{ readable(purchase.refund_status) }}<span v-if="purchase.refund_reference"> · {{ purchase.refund_reference }}</span></dd></div></dl></article>

                <form v-if="canDecide" class="px-6 py-5" :aria-busy="isSaving" @submit.prevent="decide('approved')">
                    <h3 class="text-sm font-black text-slate-950">Record cancellation decision</h3>
                    <fieldset :disabled="isSaving">
                        <label class="mt-4 block text-sm font-bold text-slate-700">Decision note <span class="font-normal text-slate-500">shared with provider</span><textarea v-model="decision.decision_note" rows="3" minlength="10" maxlength="2000" required class="mt-2 w-full border border-slate-300 px-3 py-2 text-sm"></textarea></label>
                        <div class="mt-4 grid grid-cols-[16rem_minmax(0,1fr)] items-end gap-4">
                            <label :class="['text-sm font-bold text-slate-700', decision.refund_status !== 'refunded' ? 'col-span-2' : '']">Refund disposition<select v-model="decision.refund_status" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="not_applicable">No refund required</option><option value="manual_refund_pending">Manual refund pending</option><option value="refunded">Refund completed</option></select></label>
                            <label v-if="decision.refund_status === 'refunded'" class="text-sm font-bold text-slate-700">Refund reference<input v-model="decision.refund_reference" minlength="3" maxlength="120" required class="mt-2 h-11 w-full border border-slate-300 px-3 text-sm"></label>
                        </div>
                        <div class="mt-4 flex justify-end gap-2"><button type="button" :disabled="isSaving || decision.decision_note.trim().length < 10" class="h-11 border border-slate-300 px-4 text-sm font-bold text-slate-700 disabled:opacity-40" @click="decide('declined')">Decline</button><button type="submit" :disabled="isSaving || decision.decision_note.trim().length < 10" class="h-11 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">{{ isSaving ? 'Saving...' : 'Approve cancellation' }}</button></div>
                    </fieldset>
                    <p class="mt-3 text-sm text-slate-500">This records the refund disposition; it does not issue a payment-gateway refund.</p>
                </form>

                <form v-if="canRestore" class="px-6 py-5" :aria-busy="isSaving" @submit.prevent="restore"><h3 class="text-sm font-black text-slate-950">Restore cancelled service</h3><p class="mt-1 text-sm text-slate-500">Use only when no refund is pending or completed. The reason is shared with the provider.</p><fieldset :disabled="isSaving" class="mt-3 flex gap-3"><label class="min-w-0 flex-1"><span class="sr-only">Restoration reason</span><input v-model="restoreReason" minlength="10" maxlength="2000" required class="h-11 w-full border border-slate-300 px-3 text-sm" placeholder="Why is this service being restored?"></label><button type="submit" :disabled="isSaving" class="h-11 border border-slate-950 px-4 text-sm font-bold text-slate-950 disabled:opacity-40">{{ isSaving ? 'Saving...' : 'Restore service' }}</button></fieldset></form>
            </div>
        </section>
    </AdminServiceWorkspaceShell>
</template>
