<script setup>
import { computed, ref, watch } from 'vue';
import { useAdminResource } from '../../composables/useAdminResource.js';
import AdminDialog from '../../pages/admin/components/AdminDialog.vue';
import AdminLoadState from '../../pages/admin/components/AdminLoadState.vue';

const props = defineProps({ open: Boolean, purchaseId: { type: [Number, String], default: null } });
const emit = defineEmits(['close', 'updated']);
const record = ref(null);
const note = ref('');
const action = ref('record_check');
const saving = ref(false);
const actionError = ref('');
const resource = useAdminResource(async ({ signal }, id) => {
    const response = await window.axios.get(`/admin/workspaces/finance/records/${id}/data`, { signal });
    if (!response.data.record) throw new Error('Missing financial record.');
    return response.data.record;
}, { failureMessage: 'This financial record could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const ready = computed(() => record.value && !loading.value && !errorMessage.value);
const canSave = computed(() => ready.value && record.value.can_review && !saving.value && note.value.trim().length >= 10 && note.value.trim().length <= 1500);
const coordination = computed(() => record.value?.coordination);

async function loadRecord() {
    if (!props.open || !props.purchaseId || saving.value) return;
    const payload = await resource.load(props.purchaseId);
    if (payload) record.value = payload;
}

function close() { if (!saving.value) emit('close'); }

async function save() {
    if (!canSave.value || !props.open) return;
    if (action.value === 'request_billing' && !coordination.value.can_request_billing) return;
    saving.value = true;
    actionError.value = '';
    try {
        const response = await window.axios.post(`/admin/workspaces/finance/records/${props.purchaseId}/review`, {
            action: action.value, note: note.value.trim(), expected_finance_token: coordination.value.token,
        }, { portalToast: false });
        record.value = response.data.record;
        emit('updated', response.data.message);
        emit('close');
    } catch (error) {
        actionError.value = Object.values(error.response?.data?.errors || {})[0]?.[0] || error.response?.data?.message || 'The financial review could not be saved. Your note is kept.';
    } finally { saving.value = false; }
}

watch(() => [props.open, props.purchaseId], () => {
    resource.invalidate();
    if (!props.open) return;
    record.value = null; note.value = ''; action.value = 'record_check'; actionError.value = '';
    loadRecord();
}, { immediate: true });
</script>

<template>
    <AdminDialog :open="open" title="Financial record review" title-id="finance-record-review-title" eyebrow="Payment and service records" description="Check the record or send a question to Billing. These actions do not receive, send, or refund money." icon="fa-solid fa-receipt" wide :busy="saving" :error="actionError" :confirm-disabled="!canSave" :confirm-label="action === 'request_billing' ? 'Send to Billing' : 'Record check'" @confirm="save" @cancel="close">
        <AdminLoadState v-if="!ready" :loading="loading" :error="errorMessage" loading-message="Loading financial record..." @retry="loadRecord" />
        <template v-else>
            <div data-finance-record-review><p class="text-sm font-bold text-slate-950">{{ record.service }} <span class="font-normal text-slate-500">| {{ record.reference_number }}</span></p><p class="mt-1 text-sm text-slate-500">{{ record.provider }} | {{ record.currency }} {{ (record.amount / 100).toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</p></div>
            <dl class="divide-y divide-slate-200 border-y border-slate-200 text-sm">
                <div v-for="[label, value] in [['Payment', coordination.payment_label], ['Delivery', coordination.delivery_label], ['Refund', coordination.refund_label], ['Next team', coordination.owner]]" :key="label" class="flex gap-5 py-2.5"><dt class="w-28 shrink-0 text-slate-500">{{ label }}</dt><dd class="font-bold text-slate-800">{{ value }}</dd></div>
            </dl>
            <details class="text-sm"><summary class="cursor-pointer font-bold text-slate-700">Payment reference and dates</summary><dl class="mt-3 divide-y divide-slate-200 border-y border-slate-200"><div v-for="[label, value] in [['Confirmed on', record.paid_at], ['Payment method', record.payment_method], ['Gateway payment', record.payment_id], ['Refund reference', coordination.refund_reference]]" :key="label" class="flex gap-5 py-2.5"><dt class="w-32 shrink-0 text-slate-500">{{ label }}</dt><dd class="break-all text-slate-700">{{ value || 'Not recorded' }}</dd></div></dl><p v-if="record.failure_message" class="mt-3 break-words text-slate-600">Gateway note: {{ record.failure_message }}</p><a v-if="record.receipt_url" :href="record.receipt_url" class="mt-3 inline-flex font-bold text-slate-800 underline">Open confirmed payment receipt</a></details>
            <div v-if="coordination.active_review" class="border-l-2 border-slate-300 pl-4 text-sm"><h3 class="font-bold text-slate-800">Finance question</h3><p class="mt-1 whitespace-pre-line break-words text-slate-600">{{ coordination.active_review.request_note }}</p><template v-if="coordination.active_review.billing_response"><h3 class="mt-3 font-bold text-slate-800">Billing response</h3><p class="mt-1 whitespace-pre-line break-words text-slate-600">{{ coordination.active_review.billing_response }}</p></template></div>
            <p v-if="coordination.source_changed" class="text-sm font-semibold text-amber-800">The source record changed after this question was raised. Review the latest details before recording a check.</p>
            <p v-if="coordination.check_is_current" class="text-sm font-semibold text-slate-600">A financial check is recorded for the current details. This does not clear a pending payment or refund.</p>
            <template v-if="record.can_review">
                <label class="block text-sm font-bold text-slate-800">Next action<select v-model="action" class="mt-2 h-11 w-full rounded-sm border border-slate-300 bg-white px-3 text-sm"><option value="record_check">Record a financial check</option><option v-if="coordination.can_request_billing" value="request_billing">Request a Billing record check</option></select></label>
                <label class="block text-sm font-bold text-slate-800">{{ action === 'request_billing' ? 'Question for Billing' : 'What was checked and what remains' }}<textarea v-model="note" rows="3" minlength="10" maxlength="1500" required class="mt-2 w-full rounded-sm border border-slate-300 px-3 py-2 text-sm font-normal"></textarea></label>
                <p class="text-xs leading-5 text-slate-500">Internal note, 10 to 1500 characters. Recording a check closes the internal follow-up only; outstanding payment or refund issues remain visible.</p>
            </template>
            <details v-if="coordination.history.length" class="text-sm"><summary class="cursor-pointer font-bold text-slate-700">Previous checks ({{ coordination.history.length }})</summary><div v-for="item in coordination.history" :key="item.id" class="mt-3 border-l-2 border-slate-200 pl-3"><p class="text-xs text-slate-500">{{ item.closed_at }}</p><p class="mt-1 whitespace-pre-line break-words text-slate-600">{{ item.resolution_note }}</p></div></details>
        </template>
    </AdminDialog>
</template>
