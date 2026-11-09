<script setup>
import { confirmOutcome } from '../support/outcomeConfirmation.js';
import { nextTick, ref, watch } from 'vue';
import { useModalAccessibility } from '../composables/useModalAccessibility';

const props = defineProps({
    open: { type: Boolean, default: false },
    decision: { type: Object, default: () => ({}) },
    recordName: { type: String, default: '' },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['confirm', 'cancel']);
const reason = ref('');
const reasonInput = ref(null);
const dialog = ref(null);
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, cancel);

watch(() => props.open, (isOpen) => {
    if (!isOpen) return;
    reason.value = '';
    nextTick(() => reasonInput.value?.focus({ preventScroll: true }));
});

function cancel() {
    if (!props.busy) emit('cancel');
}

async function submit() {
    const value = reason.value.trim();
    const reference = props.decision.decision_reference;
    if (value.length < 10 || props.busy || !props.decision.can_reopen || !reference) return;
    if (!await confirmOutcome({
        title: 'Confirm decision correction',
        record: props.recordName,
        outcome: `Reopen ${props.decision.stage_label || 'latest decision'}`,
        details: [`Current result: ${props.decision.result_label || 'Recorded'}`],
        consequence: 'The application will return to this stage. The original result and your reason remain in history, and the applicant will be notified.',
        confirmLabel: 'Reopen decision', danger: true,
    }, () => props.open && !props.busy && props.decision.can_reopen
        && props.decision.decision_reference === reference && reason.value.trim() === value)) return;
    emit('confirm', { reason: value, decision_reference: reference });
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[2100] flex items-center justify-center bg-slate-950/60 p-4"
            role="presentation"
            @click.self="cancel"
        >
            <form ref="dialog" class="flex max-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-md border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="decision-reopen-title" :aria-busy="busy" tabindex="-1" @keydown="handleKeydown" @submit.prevent="submit">
                <header class="border-b border-slate-200 px-6 py-5">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Decision correction</p>
                    <h2 id="decision-reopen-title" class="mt-1 text-xl font-bold text-slate-950">Reopen {{ decision.stage_label || 'latest decision' }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Return the application to this stage. The original result stays in history and the applicant will be notified.</p>
                </header>
                <div class="min-h-0 overflow-y-auto px-6 py-5">
                    <div class="border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        <p v-if="recordName" class="mb-1 font-bold">{{ recordName }}</p>
                        Current result: <strong>{{ decision.result_label || 'Recorded' }}</strong>
                    </div>
                    <p v-if="error" role="alert" class="mt-4 rounded-sm border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ error }}</p>
                    <label class="mt-5 block text-sm font-semibold text-slate-700">
                        Reason for reopening
                        <textarea
                            ref="reasonInput"
                            v-model="reason"
                            rows="4"
                            required
                            minlength="10"
                            maxlength="1000"
                            :disabled="busy"
                            class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950"
                            placeholder="Explain the mistake or information that needs another review."
                        ></textarea>
                    </label>
                    <p class="mt-2 text-xs text-slate-500">This reason becomes part of the application audit history.</p>
                </div>
                <footer class="flex shrink-0 justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" class="h-10 rounded-[5px] border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" :disabled="busy" @click="cancel">Cancel</button>
                    <button type="submit" class="h-10 rounded-[5px] bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-50" :disabled="busy || !decision.can_reopen || !decision.decision_reference || reason.trim().length < 10">{{ busy ? 'Reopening...' : 'Review correction' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
