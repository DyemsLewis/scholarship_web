<script setup>
import { computed, ref } from 'vue';
import AdminDialog from '../../pages/admin/components/AdminDialog.vue';

const props = defineProps({ purchaseId: { type: [Number, String], required: true }, coordination: { type: Object, default: null }, disabled: Boolean });
const emit = defineEmits(['updated']);
const open = ref(false);
const note = ref('');
const busy = ref(false);
const error = ref('');
const valid = computed(() => note.value.trim().length >= 10 && note.value.trim().length <= 1500);
const canRespond = computed(() => props.coordination?.active_review?.state === 'with_billing' && props.coordination.can_respond !== false);
function show() { if (!busy.value && !props.disabled && canRespond.value) { open.value = true; error.value = ''; note.value = ''; } }
function close() { if (!busy.value) open.value = false; }
async function submit() {
    if (!open.value || busy.value || props.disabled || !canRespond.value || !valid.value) return;
    busy.value = true; error.value = '';
    try {
        const response = await window.axios.patch(`/admin/workspaces/billing/requests/${props.purchaseId}/finance-response`, {
            note: note.value.trim(), expected_finance_token: props.coordination.token,
        }, { portalToast: false });
        emit('updated', response.data.coordination); open.value = false;
    } catch (failure) {
        error.value = Object.values(failure.response?.data?.errors || {})[0]?.[0] || failure.response?.data?.message || 'The response could not be saved. Your note has been kept.';
    } finally { busy.value = false; }
}
</script>

<template>
    <section v-if="coordination && (coordination.active_review || coordination.history.length || !['No refund recorded', 'No refund required'].includes(coordination.refund_label))" class="admin-panel mt-4 overflow-hidden" data-billing-finance>
        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span><div><h2 class="text-base font-black text-slate-950">Payment and Finance follow-up</h2><p class="mt-0.5 text-sm text-slate-500">Payment, delivery, and refund records are separate.</p></div></div><button v-if="canRespond" type="button" :disabled="disabled || busy" class="h-10 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold disabled:opacity-50" @click="show">Respond to Finance</button></header>
        <dl class="grid grid-cols-3 divide-x divide-slate-200 bg-slate-50"><div v-for="[label, value] in [['Payment', coordination.payment_label], ['Delivery', coordination.delivery_label], ['Refund', coordination.refund_label]]" :key="label" class="px-6 py-3"><dt class="text-xs text-slate-500">{{ label }}</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ value }}</dd></div></dl>
        <div v-if="coordination.active_review" class="border-t border-slate-200 px-6 py-4"><p class="text-sm font-bold text-slate-900">Next: {{ coordination.owner }}</p><p class="mt-2 whitespace-pre-line break-words text-sm text-slate-600">{{ coordination.active_review.request_note }}</p><p v-if="coordination.active_review.billing_response" class="mt-3 whitespace-pre-line break-words text-sm text-slate-600"><strong>Billing response:</strong> {{ coordination.active_review.billing_response }}</p><p v-if="coordination.source_changed" class="mt-2 text-xs font-semibold text-amber-800">The source record changed after Finance raised this question.</p></div>
        <p v-else class="border-t border-slate-200 px-6 py-3 text-xs text-slate-500">{{ coordination.check_is_current ? 'Finance checked the current details. This does not complete a pending refund.' : 'No internal Finance question is awaiting a Billing response.' }}</p>
    </section>
    <AdminDialog :open="open" title="Return a response to Finance" eyebrow="Internal record check" description="Explain what was checked and any outstanding payment or refund step. This does not change payment, refund, or service delivery states." confirm-label="Return response" :busy="busy" :error="error" :confirm-disabled="!valid || disabled" @confirm="submit" @cancel="close"><label class="block text-sm font-bold text-slate-800">Billing response<textarea v-model="note" rows="4" minlength="10" maxlength="1500" required class="mt-2 w-full rounded-sm border border-slate-300 px-3 py-2 text-sm font-normal"></textarea></label><p class="text-xs text-slate-500">Internal note only, 10 to 1500 characters.</p></AdminDialog>
</template>
