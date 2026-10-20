<script setup>
import RecipientAgreementSummary from '../RecipientAgreementSummary.vue';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    agreement: {
        type: Object,
        default: null,
    },
    termsAccepted: {
        type: Boolean,
        default: false,
    },
    note: {
        type: String,
        default: '',
    },
    processing: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits([
    'close',
    'respond',
    'update:termsAccepted',
    'update:note',
]);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open && agreement"
            class="fixed inset-0 z-[3000] flex items-center justify-center bg-slate-950/75 p-3 sm:p-5"
            role="presentation"
            @click.self="!processing && emit('close')"
        >
            <section class="flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-md bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="recipient-agreement-title">
                <header class="flex items-start gap-3 border-t-4 border-amber-400 bg-slate-950 px-4 py-4 text-white sm:px-5">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded bg-amber-300 text-slate-950">
                        <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-300">Recipient agreement</p>
                        <h2 id="recipient-agreement-title" class="mt-1 text-xl font-bold text-white">Review the complete terms</h2>
                        <p class="mt-1 text-sm leading-5 text-slate-300">Read each section before recording your response.</p>
                    </div>
                    <button type="button" :disabled="processing" class="grid h-9 w-9 shrink-0 place-items-center rounded border border-white/20 text-slate-300 hover:bg-white/10 hover:text-white disabled:opacity-50" aria-label="Close recipient agreement" @click="emit('close')">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto bg-slate-100 p-3 sm:p-4">
                    <RecipientAgreementSummary :agreement="agreement" compact embedded />

                    <section v-if="agreement.can_respond" class="mt-4 overflow-hidden rounded-md border border-slate-300 bg-white">
                        <header class="border-b border-slate-200 px-4 py-3 sm:px-5">
                            <h3 class="text-sm font-bold text-slate-950">Your response</h3>
                            <p class="mt-1 text-xs leading-5 text-slate-600">Accept to continue as a recipient, or decline and briefly explain why.</p>
                        </header>
                        <div class="space-y-4 px-4 py-4 sm:px-5">
                            <label class="flex cursor-pointer items-start gap-3 border-l-2 border-amber-400 bg-amber-50 px-3 py-3 text-sm leading-5 text-slate-700">
                                <input
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-slate-950 focus:ring-slate-400"
                                    :checked="termsAccepted"
                                    @change="emit('update:termsAccepted', $event.target.checked)"
                                >
                                <span><strong class="text-slate-950">I reviewed the complete agreement.</strong> I understand the support, responsibilities, proof, release conditions, timeframe, consequences, and exception process.</span>
                            </label>

                            <div>
                                <div class="flex items-end justify-between gap-3">
                                    <label for="recipient-agreement-note" class="text-sm font-bold text-slate-900">Note to provider</label>
                                    <span class="text-xs text-slate-500">Optional to accept, required to decline</span>
                                </div>
                                <textarea
                                    id="recipient-agreement-note"
                                    :value="note"
                                    rows="3"
                                    maxlength="1000"
                                    class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-slate-600 focus:ring-2 focus:ring-slate-200"
                                    placeholder="Add a question or explain why you cannot accept."
                                    @input="emit('update:note', $event.target.value)"
                                ></textarea>
                            </div>
                        </div>
                    </section>

                    <section v-else-if="agreement.response_note" class="mt-4 rounded-md border border-slate-300 bg-white px-4 py-4 sm:px-5">
                        <p class="text-xs font-bold text-slate-500">Your recorded note</p>
                        <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ agreement.response_note }}</p>
                    </section>
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-5">
                    <button type="button" :disabled="processing" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="emit('close')">
                        Close
                    </button>
                    <template v-if="agreement.can_respond">
                        <button type="button" :disabled="processing" class="rounded-md border border-rose-200 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-50" @click="emit('respond', 'declined')">
                            Decline agreement
                        </button>
                        <button type="button" :disabled="processing" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50" @click="emit('respond', 'accepted')">
                            {{ processing ? 'Saving...' : 'Accept agreement' }}
                        </button>
                    </template>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
