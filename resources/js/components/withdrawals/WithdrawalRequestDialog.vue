<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';
import { useWithdrawalAction } from '../../composables/useWithdrawalAction.js';
import { withdrawalAgreementRows, withdrawalClass, withdrawalDate, withdrawalError, withdrawalLabel } from '../../support/recipientWithdrawal.js';

const props = defineProps({
    open: Boolean,
    mode: { type: String, default: 'view' },
    request: { type: Object, default: null },
    endpoint: { type: String, default: '' },
    context: { type: String, default: '' },
    agreement: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved', 'refreshed', 'busy', 'reload']);
const dialog = ref(null);
const current = ref(null);
const intent = ref('view');
const reloading = ref(false);
const discardPrompt = ref(false);
const titleId = `${useId()}-withdrawal-title`;
const formId = `${useId()}-withdrawal-form`;
const { notes, outcome, confirmed, saving, stale, saved, error, canSave, reset, refreshed, save } = useWithdrawalAction({
    mode: () => intent.value, record: () => current.value, endpoint: () => props.endpoint,
    send: (method, ...args) => window.axios[method](...args),
    onSaved: (data) => { emit('saved', data); emit('close'); },
});
const busy = computed(() => saving.value || reloading.value);
const writable = computed(() => intent.value === 'create' || (current.value?.status === 'pending'
    && (intent.value === 'review' ? current.value.can_review : intent.value === 'cancel' && current.value.can_cancel)));
const dirty = computed(() => !saved.value && (!!notes.value.trim() || !!outcome.value || confirmed.value));
const title = computed(() => intent.value === 'create' ? 'Request scholarship withdrawal'
    : intent.value === 'cancel' && writable.value ? 'Cancel your withdrawal request'
        : writable.value && intent.value === 'review' ? 'Review withdrawal request' : 'Scholarship withdrawal request');
const buttonLabel = computed(() => intent.value === 'create' ? 'Send request'
    : intent.value === 'cancel' ? 'Cancel withdrawal request'
        : outcome.value === 'approved' ? 'Approve withdrawal' : outcome.value === 'declined' ? 'Decline request' : 'Record decision');
const recordedAgreement = computed(() => current.value?.agreement ?? props.agreement);
const agreementRows = computed(() => withdrawalAgreementRows(recordedAgreement.value));
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);
watch(() => props.open, (open) => {
    if (!open) return;
    current.value = props.request;
    intent.value = props.mode;
    discardPrompt.value = false;
    reset();
}, { immediate: true });
watch(busy, (value) => emit('busy', value), { flush: 'sync' });
watch(discardPrompt, async (value, previous) => {
    if (!value && !previous) return;
    await nextTick();
    const target = dialog.value?.querySelector(value ? '[data-keep-editing]' : 'textarea:not([disabled])');
    (target ?? dialog.value)?.focus({ preventScroll: true });
});
function close() {
    if (busy.value) return;
    if (dirty.value) { discardPrompt.value = true; return; }
    emit('close');
}
async function reload() {
    if (busy.value) return;
    reloading.value = true;
    try {
        const response = await window.axios.get(current.value?.read_url || props.endpoint, { portalToast: false });
        current.value = response.data.request ?? response.data.requests?.data?.[0] ?? null;
        if (intent.value === 'create' && current.value) intent.value = 'view';
        refreshed();
        if (current.value) emit('refreshed', current.value);
    } catch (failure) { error.value = withdrawalError(failure, 'The current request could not be loaded. Try again before deciding.'); }
    finally { reloading.value = false; }
}
async function submit() {
    await save();
    if (error.value) {
        await nextTick();
        dialog.value?.querySelector('[data-withdrawal-error]')?.focus();
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2300] grid place-items-center bg-slate-950/65 p-2 sm:p-5" @click.self="close">
            <section ref="dialog" data-withdrawal-dialog class="flex max-h-[calc(100dvh-1rem)] w-full max-w-2xl flex-col overflow-hidden rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl sm:max-h-[calc(100dvh-2.5rem)]" role="dialog" aria-modal="true" :aria-labelledby="titleId" :aria-busy="busy" tabindex="-1" @keydown="handleKeydown">
                <header class="flex shrink-0 items-start gap-3 border-b border-amber-400 bg-slate-950 px-4 py-4 text-white sm:px-6">
                    <span class="grid h-10 w-10 shrink-0 place-items-center bg-white/10 text-amber-300"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-300">Accepted scholarship</p><h2 :id="titleId" class="mt-1 text-xl font-bold">{{ title }}</h2><p class="mt-1 break-words text-xs leading-5 text-slate-300">{{ current?.program?.title || context }}</p></div>
                    <button type="button" :disabled="busy" class="grid h-10 w-10 shrink-0 place-items-center border border-white/25 text-white disabled:opacity-40" aria-label="Close withdrawal dialog" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-5 sm:px-6" data-withdrawal-body>
                    <div v-if="current" class="flex flex-wrap items-center justify-between gap-2"><span :class="['inline-flex border px-2.5 py-1 text-xs font-bold', withdrawalClass(current.status)]">{{ withdrawalLabel(current.status) }}</span><span class="text-xs text-slate-500">Requested {{ withdrawalDate(current.requested_at) }}</span></div>
                    <p v-if="intent === 'create'" class="border-l-2 border-amber-400 bg-amber-50 px-4 py-3 text-sm leading-6 text-slate-700">This asks the provider to end your accepted scholarship. Your support stays unchanged while they review the request.</p>
                    <section v-if="current" class="space-y-4">
                        <div><h3 class="text-xs font-bold text-slate-500">{{ intent === 'review' ? current.applicant?.name + ' requested to leave' : 'Reason for withdrawal' }}</h3><p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-900">{{ current.reason }}</p></div>
                        <div v-if="current.review_note" class="border-l-2 border-slate-300 pl-4"><h3 class="text-xs font-bold text-slate-500">Provider response</h3><p class="mt-2 whitespace-pre-line break-words text-sm leading-6">{{ current.review_note }}</p><p class="mt-2 text-xs text-slate-500">{{ withdrawalDate(current.resolved_at) }}</p></div>
                    </section>
                    <details class="border-y border-slate-200 py-3">
                        <summary class="cursor-pointer text-sm font-bold text-slate-700">Accepted terms and exit guidance</summary>
                        <p v-if="recordedAgreement?.accepted_at" class="mt-3 text-xs text-slate-500">Accepted {{ withdrawalDate(recordedAgreement.accepted_at) }}</p>
                        <dl v-if="agreementRows.length" class="mt-3 space-y-4"><div v-for="[label, value] in agreementRows" :key="label"><dt class="text-xs font-bold text-slate-500">{{ label }}</dt><dd class="mt-1 whitespace-pre-line break-words text-sm leading-6">{{ value }}</dd></div></dl>
                        <p v-else class="mt-3 text-sm leading-6 text-slate-600">No detailed exit terms were stored with this acceptance. Ask the provider to clarify any separate agreement.</p>
                        <p class="mt-3 text-xs leading-5 text-slate-500">This is the saved agreement, not the program's latest terms. This process does not automatically impose penalties or blacklist an applicant.</p>
                    </details>
                    <form v-if="writable" :id="formId" @submit.prevent="submit">
                        <fieldset :disabled="busy || stale || discardPrompt" class="space-y-4">
                            <legend class="sr-only">{{ title }}</legend>
                            <div v-if="intent === 'review'" class="space-y-2">
                                <p class="text-sm font-bold text-slate-900">Choose an outcome</p>
                                <label v-for="choice in [{ value: 'approved', label: 'Approve withdrawal', hint: 'End future benefits from this decision. Keep prior records.' }, { value: 'declined', label: 'Decline this request', hint: 'Keep support unchanged and explain the next step.' }]" :key="choice.value" :class="['flex cursor-pointer items-start gap-3 border p-3', outcome === choice.value ? 'border-slate-950 bg-slate-50' : 'border-slate-300']"><input v-model="outcome" type="radio" :name="formId + '-outcome'" :value="choice.value" class="mt-1"><span><span class="block text-sm font-bold">{{ choice.label }}</span><span class="mt-1 block text-xs leading-5 text-slate-600">{{ choice.hint }}</span></span></label>
                            </div>
                            <p v-if="intent === 'cancel'" class="text-sm leading-6 text-slate-700">Cancel only this pending request. Your scholarship support will stay unchanged. You can send a new request later if needed.</p>
                            <label v-else class="block"><span class="text-sm font-bold text-slate-900">{{ intent === 'create' ? 'Why do you need to leave?' : 'Explanation for the applicant' }}</span><textarea v-model="notes" :aria-describedby="formId + '-note-help'" rows="4" minlength="10" maxlength="2000" required class="mt-2 w-full border border-slate-300 bg-white px-3 py-3 text-sm leading-6 outline-none focus:border-slate-950" :placeholder="intent === 'create' ? 'Briefly explain your circumstances.' : 'Explain the outcome and any next steps.'"></textarea><span :id="formId + '-note-help'" class="mt-1 flex justify-between gap-3 text-xs text-slate-500"><span>10 to 2,000 characters. Do not include passwords or bank details.</span><span class="shrink-0">{{ notes.length }}/2000</span></span></label>
                            <label class="flex items-start gap-3 border-l-2 border-amber-400 bg-amber-50 px-3 py-3 text-sm leading-5 text-slate-700"><input v-model="confirmed" type="checkbox" required class="mt-0.5 shrink-0"><span>{{ intent === 'create' ? 'I understand this is a request. Support changes only after provider approval.' : intent === 'cancel' ? 'I want to cancel this pending request, not my scholarship.' : outcome === 'approved' ? 'I reviewed the request and understand that approval ends future benefits, without erasing earlier releases.' : 'I reviewed the request and will notify the applicant with this explanation.' }}</span></label>
                        </fieldset>
                    </form>
                    <p v-else-if="current?.status === 'approved'" class="text-sm leading-6 text-slate-600">Future benefits ended when this request was approved. Your original selection, accepted agreement, and earlier releases remain recorded.</p>
                    <details v-if="current?.history?.length" class="border-t border-slate-200 pt-3"><summary class="cursor-pointer text-sm font-bold text-slate-700">Request history ({{ current.history.length }})</summary><ol class="mt-3 divide-y divide-slate-200"><li v-for="event in current.history" :key="event.revision" class="py-3"><p class="text-sm font-bold">{{ withdrawalLabel(event.to_status) }}</p><p class="mt-1 text-xs text-slate-500">{{ event.actor_name }} &middot; {{ withdrawalDate(event.created_at) }}</p><p class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ event.note }}</p></li></ol></details>
                    <div v-if="error" data-withdrawal-error tabindex="-1" role="alert" class="border-l-4 border-amber-400 bg-amber-50 p-3 text-sm leading-6 text-amber-950"><p>{{ error }}</p><button v-if="stale" type="button" :disabled="busy" class="mt-2 font-bold underline disabled:opacity-40" @click="reload">{{ reloading ? 'Reloading...' : 'Reload current request' }}</button></div>
                </div>
                <footer class="shrink-0 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-6">
                    <div v-if="discardPrompt" class="space-y-3" role="alert"><p class="text-sm font-bold">Discard this unsaved explanation?</p><div class="flex flex-wrap justify-end gap-2"><button data-keep-editing type="button" class="border border-slate-300 bg-white px-4 py-2 text-sm font-bold" @click="discardPrompt = false">Keep editing</button><button type="button" class="bg-slate-950 px-4 py-2 text-sm font-bold text-white" @click="emit('close')">Discard changes</button></div></div>
                    <div v-else class="flex flex-wrap items-center justify-end gap-2"><button v-if="intent === 'view' && current?.can_cancel" type="button" class="mr-auto text-sm font-bold text-slate-600 underline" @click="intent = 'cancel'">Cancel pending request</button><button type="button" :disabled="busy" class="min-h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-40" @click="close">{{ writable ? 'Back' : 'Close' }}</button><button v-if="writable" type="submit" :form="formId" :disabled="!canSave || busy" class="min-h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">{{ saving ? 'Saving...' : buttonLabel }}</button></div>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
