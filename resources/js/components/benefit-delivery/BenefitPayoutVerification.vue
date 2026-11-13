<script setup>
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';
import { confirmOutcome } from '../../support/outcomeConfirmation.js';

const props = defineProps({ payout: { type: Object, required: true } });
const emit = defineEmits(['reviewed']);
const open = ref(false);
const busy = ref(false);
const loading = ref(false);
const record = ref(null);
const reason = ref('');
const error = ref('');
const correcting = ref(false);
const dialog = ref(null);
const titleId = useId();
let loadRequest = null;
const { handleKeydown } = useModalAccessibility(() => open.value, dialog, close);

const approved = computed(() => props.payout.status === 'approved');
const description = computed(() => ({
    required: 'The applicant needs to submit a payout destination before you can record a transfer.',
    submitted: 'Check the recipient’s destination before recording a bank or wallet transfer.',
    correction_requested: 'The applicant was asked to submit corrected details. Recording the transfer is still blocked.',
    approved: 'The destination was approved. Record the transfer only after sending it outside the portal.',
}[props.payout.status]));

async function show() {
    if (!props.payout.review_url || busy.value || open.value) return;
    open.value = true;
    loading.value = true;
    error.value = '';
    record.value = null;
    correcting.value = false;
    reason.value = '';
    const request = new AbortController();
    loadRequest = request;
    try {
        const { data } = await window.axios.get(props.payout.review_url, { signal: request.signal, portalToast: false });
        if (!request.signal.aborted) record.value = data;
    } catch (failure) {
        if (!request.signal.aborted) error.value = failure.response?.status === 409
            ? 'These details are no longer available. Close the review and reload the release.'
            : 'We could not open the payout details.';
    } finally {
        if (loadRequest === request) loading.value = false;
    }
}

function clear() {
    loadRequest?.abort();
    loadRequest = null;
    open.value = false;
    record.value = null;
    correcting.value = false;
    reason.value = '';
    error.value = '';
}

function close() {
    if (!busy.value) clear();
}

onBeforeUnmount(clear);

async function review(decision) {
    const current = record.value;
    const url = props.payout.review_url;
    const correction = decision === 'request_correction';
    if (busy.value || !current || !url || (correction && !reason.value)) return;
    const chosenReason = reason.value;
    busy.value = true;
    error.value = '';
    try {
        if (!await confirmOutcome({
            title: correction ? 'Request a payout correction?' : 'Approve these payout details?',
            consequence: correction
                ? 'The applicant will be notified. The transfer cannot be recorded until corrected details are approved.'
                : 'Approve only after checking that the destination belongs to the intended recipient. No money will be sent.',
            confirmLabel: correction ? 'Request correction' : 'Approve details',
        }, () => open.value && record.value === current && url === props.payout.review_url)) return;
        const { data } = await window.axios.patch(url, {
            decision,
            ...(correction ? { correction_reason: chosenReason } : {}),
            expected_revision: current.revision,
            confirmed: true,
        }, { portalToast: false });
        clear();
        emit('reviewed', data.message);
    } catch (failure) {
        if (failure.response?.status === 409) {
            record.value = null;
            error.value = 'The submission changed. Close and reopen the review to check the current details.';
        } else {
            error.value = 'We could not save the payout decision. Please try again.';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section data-provider-payout-status class="mt-5 flex items-center gap-4 border border-slate-200 bg-slate-50 p-4">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-building-columns" aria-hidden="true"></i></span>
        <div class="min-w-0 flex-1">
            <p class="text-xs font-bold text-slate-500">Payout verification</p>
            <h4 class="mt-0.5 font-bold text-slate-950">{{ payout.label }}</h4>
            <p class="mt-1 text-sm leading-5 text-slate-600">{{ description }}</p>
        </div>
        <button v-if="payout.review_url" type="button" class="student-button student-button-secondary shrink-0" @click="show">
            <i class="fa-solid fa-lock text-xs" aria-hidden="true"></i> {{ approved ? 'View approved details' : 'Review payout details' }}
        </button>
    </section>

    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2200] grid place-items-center bg-slate-950/65 p-4" @click.self="close">
            <section ref="dialog" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1" data-provider-payout-dialog
                class="flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white text-slate-950 shadow-2xl" @keydown="handleKeydown">
                <header class="flex shrink-0 items-start gap-3 border-b-2 border-amber-400 px-6 py-5">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">Private payout review</p>
                        <h2 :id="titleId" class="mt-1 font-display text-2xl font-bold">Verify payout details</h2>
                        <p v-if="record" class="mt-1 break-words text-sm text-slate-600">{{ record.recipient_name }} &middot; {{ record.release_title }}</p>
                    </div>
                    <button type="button" :disabled="busy" aria-label="Close payout review" class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-300 text-slate-600 hover:bg-slate-50" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <p v-if="loading" role="status" class="text-sm text-slate-600">Opening payout details...</p>
                    <p v-if="error" role="alert" class="mb-3 border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900">{{ error }}</p>
                    <template v-if="record">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-bold">{{ record.method === 'bank_transfer' ? 'Bank transfer' : 'E-wallet' }}</h3>
                                <p class="mt-0.5 text-xs text-slate-600">Submitted {{ record.submitted_label }}</p>
                            </div>
                            <span :class="['rounded-sm border px-2 py-1 text-xs font-bold', record.status === 'approved' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900']">
                                {{ record.status === 'approved' ? 'Approved' : 'Awaiting verification' }}
                            </span>
                        </div>
                        <dl class="mt-3 divide-y divide-slate-200 border-y border-slate-200 text-sm">
                            <div class="grid grid-cols-[11rem_minmax(0,1fr)] gap-4 py-2.5"><dt class="text-slate-600">{{ record.method === 'bank_transfer' ? 'Bank name' : 'Wallet provider' }}</dt><dd class="break-words font-bold">{{ record.destination.institution }}</dd></div>
                            <div class="grid grid-cols-[11rem_minmax(0,1fr)] gap-4 py-2.5"><dt class="text-slate-600">Account holder</dt><dd class="break-words font-bold">{{ record.destination.account_holder }}</dd></div>
                            <div class="grid grid-cols-[11rem_minmax(0,1fr)] gap-4 py-2.5"><dt class="text-slate-600">{{ record.method === 'bank_transfer' ? 'Account number' : 'Registered mobile number' }}</dt><dd class="break-all font-bold tabular-nums">{{ record.destination.account_number }}</dd></div>
                        </dl>
                        <p v-if="!correcting" class="mt-4 border-l-2 border-amber-400 bg-amber-50 px-3 py-2.5 text-sm leading-5 text-slate-700">
                            Check that this destination belongs to the intended recipient. Approval records your review; it does not send money.
                        </p>
                        <label v-else class="mt-4 block text-sm font-bold">
                            What should the applicant check?
                            <select v-model="reason" :disabled="busy" class="mt-1.5 block h-11 w-full rounded-sm border border-slate-300 bg-white px-3 font-normal">
                                <option disabled value="">Select a reason</option>
                                <option v-for="(label, value) in record.correction_reasons" :key="value" :value="value">{{ label }}</option>
                            </select>
                        </label>
                    </template>
                </div>

                <footer class="flex shrink-0 items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button v-if="correcting" type="button" class="student-button student-button-secondary" :disabled="busy" @click="correcting = false">Back</button>
                    <button v-else type="button" class="student-button student-button-secondary" :disabled="busy" @click="close">Close</button>
                    <template v-if="record">
                        <button v-if="correcting" type="button" :disabled="busy || !reason" class="student-button student-button-primary disabled:opacity-50" @click="review('request_correction')">Notify applicant</button>
                        <template v-else>
                            <button type="button" :disabled="busy" class="student-button student-button-secondary" @click="correcting = true">Request correction</button>
                            <button v-if="record.status === 'submitted'" type="button" :disabled="busy" class="student-button student-button-primary" @click="review('approve')">Approve details</button>
                        </template>
                    </template>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
