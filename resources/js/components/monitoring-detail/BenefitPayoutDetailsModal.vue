<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility.js';
import { confirmOutcome } from '../../support/outcomeConfirmation.js';
import { showPortalToast } from '../../support/portalToast';

const props = defineProps({
    open: Boolean,
    releaseTitle: { type: String, required: true },
    payout: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);
const id = useId();
const dialog = ref(null);
const busy = ref(false);
const error = ref('');
const form = ref({});
const wallet = computed(() => props.payout.method === 'e_wallet');
const replacing = computed(() => props.payout.revision != null);
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);

watch(() => props.open, () => {
    form.value = { institution: '', account_holder: '', account_number: '' };
    error.value = '';
}, { immediate: true });

function close() {
    if (!busy.value) emit('close');
}

async function submit() {
    if (busy.value) return;
    error.value = '';
    busy.value = true;
    try {
        if (!await confirmOutcome({
            title: replacing.value ? 'Replace payout details?' : 'Submit payout details?',
            consequence: replacing.value
                ? 'These details will replace the previous submission for this release. No money will be sent.'
                : 'These details will be saved privately for this release. Submitting them does not send money.',
            confirmLabel: replacing.value ? 'Replace details' : 'Submit details',
        }, () => props.open && !!props.payout.submit_url)) return;

        await window.axios.post(props.payout.submit_url, {
            ...form.value,
            method: props.payout.method,
            expected_revision: props.payout.revision,
            confirmed: true,
        }, { portalToast: false });
        showPortalToast({ type: 'success', title: 'Payout details submitted', message: 'No money was sent.' });
        emit('close');
        emit('saved');
    } catch (failure) {
        const response = failure.response;
        error.value = response?.status === 422
            ? Object.values(response.data?.errors ?? {}).flat()[0] || 'Check the details you entered.'
            : response?.status === 409
                ? 'This release changed. Close this form and refresh the release before trying again.'
                : 'We could not save your payout details. Please try again.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2200] grid place-items-center bg-slate-950/65 p-3 sm:p-5" @click.self="close">
            <form ref="dialog" role="dialog" aria-modal="true" :aria-labelledby="`${id}-title`" tabindex="-1" data-payout-details-dialog
                class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white text-slate-950 shadow-2xl sm:max-h-[calc(100dvh-2.5rem)]"
                autocomplete="off" @keydown="handleKeydown" @submit.prevent="submit">
                <header class="flex shrink-0 items-start gap-3 border-b-2 border-amber-400 px-4 py-4 sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300">
                        <i :class="wallet ? 'fa-solid fa-wallet' : 'fa-solid fa-building-columns'" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">{{ wallet ? 'E-wallet' : 'Bank transfer' }}</p>
                        <h2 :id="`${id}-title`" class="mt-1 font-display text-xl font-bold sm:text-2xl">{{ replacing ? 'Replace payout details' : 'Enter payout details' }}</h2>
                        <p class="mt-1 break-words text-sm text-slate-600">{{ releaseTitle }}</p>
                    </div>
                    <button type="button" :disabled="busy" aria-label="Close payout form" class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-300 text-slate-600 hover:bg-slate-50" @click="close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">
                    <div class="flex items-start gap-2.5 rounded-sm border border-slate-200 bg-slate-50 px-3 py-3 text-sm leading-5 text-slate-700">
                        <i class="fa-solid fa-lock mt-1 shrink-0 text-xs text-slate-600" aria-hidden="true"></i>
                        <p>Use the account where you want to receive this benefit. Never enter a password, PIN, or one-time code.</p>
                    </div>
                    <p v-if="error" role="alert" class="rounded-sm border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">{{ error }}</p>
                    <label class="block text-sm font-bold text-slate-700">
                        {{ wallet ? 'Wallet provider' : 'Bank name' }}
                        <input v-model.trim="form.institution" name="institution" required maxlength="120" :disabled="busy" class="mt-1.5 block min-h-11 w-full rounded-sm border border-slate-300 px-3 font-normal text-slate-950">
                    </label>
                    <label class="block text-sm font-bold text-slate-700">
                        Account holder name
                        <input v-model.trim="form.account_holder" name="account_holder" required maxlength="120" :disabled="busy" class="mt-1.5 block min-h-11 w-full rounded-sm border border-slate-300 px-3 font-normal text-slate-950">
                        <span class="mt-1 block text-xs font-normal text-slate-600">Enter the name as it appears on the account.</span>
                    </label>
                    <label class="block text-sm font-bold text-slate-700">
                        {{ wallet ? 'Registered mobile number' : 'Bank account number' }}
                        <input v-model.trim="form.account_number" name="account_number" :inputmode="wallet ? 'tel' : 'numeric'" required :maxlength="wallet ? 13 : 34" :disabled="busy" :aria-describedby="`${id}-number-hint`" class="mt-1.5 block min-h-11 w-full rounded-sm border border-slate-300 px-3 font-normal text-slate-950">
                        <span :id="`${id}-number-hint`" class="mt-1 block text-xs font-normal text-slate-600">{{ wallet ? 'Use 09XXXXXXXXX or +639XXXXXXXXX.' : 'Use the account number, not the number on your debit card.' }}</span>
                    </label>
                </div>

                <footer class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-6">
                    <button type="button" :disabled="busy" class="student-button student-button-secondary" @click="close">Cancel</button>
                    <button type="submit" :disabled="busy" class="student-button student-button-primary disabled:opacity-50">{{ busy ? 'Please wait...' : 'Review and submit' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
