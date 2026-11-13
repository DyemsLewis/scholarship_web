<script setup>
import { confirmOutcome } from '../../support/outcomeConfirmation.js';
import { ref, watch } from 'vue';
import { useModalAccessibility } from '../../composables/useModalAccessibility';
import { showPortalToast } from '../../support/portalToast';

const props = defineProps({
    open: { type: Boolean, default: false },
    release: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);
const dialog = ref(null);
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);
const isSaving = ref(false);
const today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Manila' }).format();
const form = ref(defaultForm());

function defaultForm() {
    return {
        received_on: today,
        recipient_note: '',
        acknowledged: false,
    };
}

watch(() => props.open, (open) => {
    if (open) form.value = defaultForm();
}, { immediate: true });


function close() {
    if (!isSaving.value) emit('close');
}

async function submit() {
    if (!props.release || isSaving.value) return;
    if (!form.value.acknowledged) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that your response is accurate.',
        });
        return;
    }

    if (!await confirmOutcome({
        record: props.release.title || 'Benefit release',
        outcome: 'Received as listed',
        consequence: 'Confirm only if you actually received this benefit. Your response and receipt date will be recorded.',
        confirmLabel: 'Confirm receipt',
    }, () => props.open && !!props.release && !isSaving.value && form.value.acknowledged)) return;
    isSaving.value = true;
    const data = new FormData();
    data.append('response_type', 'confirmed');
    data.append('acknowledged', '1');
    data.append('received_on', form.value.received_on);
    if (form.value.recipient_note) data.append('recipient_note', form.value.recipient_note);

    try {
        const response = await window.axios.post(
            `/dashboard/benefit-release-records/${props.release.record_id}/response`,
            data,
        );
        showPortalToast({ type: 'success', title: 'Receipt confirmed', message: response.data.message });
        emit('saved');
        emit('close');
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Response not saved',
            message: errors
                ? Object.values(errors).flat()[0]
                : (error.response?.data?.message ?? 'Unable to record your response.'),
        });
    } finally {
        isSaving.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[2200] grid place-items-center bg-slate-950/65 p-3 sm:p-5"
            @click.self="close"
        >
            <form ref="dialog" role="dialog" aria-modal="true" aria-labelledby="benefit-response-title" tabindex="-1" class="flex max-h-[calc(100dvh-1.5rem)] w-full max-w-xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white text-slate-950 shadow-2xl sm:max-h-[calc(100dvh-2.5rem)]" @keydown="handleKeydown" @submit.prevent="submit">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Benefit receipt</p>
                        <h2 id="benefit-response-title" class="mt-1 text-xl font-bold">Confirm what you received</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ release?.title }}</p>
                    </div>
                    <button type="button" :disabled="isSaving" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-300 text-slate-500 hover:text-slate-950" aria-label="Close response" @click="close">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-5">
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-bold text-slate-700">Date received</span>
                        <input v-model="form.received_on" type="date" :min="release?.transfer_sent_on || undefined" :max="today" required class="w-full rounded-sm border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    </label>
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-bold text-slate-700">Note <span class="font-normal text-slate-500">(optional)</span></span>
                        <textarea v-model="form.recipient_note" maxlength="1000" rows="3" class="w-full resize-y rounded-sm border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea>
                    </label>

                    <label class="flex items-start gap-3 border border-slate-200 bg-white p-3 text-sm leading-6 text-slate-700">
                        <input v-model="form.acknowledged" type="checkbox" class="mt-1 h-4 w-4 border-slate-300 text-slate-950">
                        <span>I received the benefit as listed.</span>
                    </label>
                </div>

                <footer class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <button type="button" :disabled="isSaving" class="rounded-sm border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="close">Cancel</button>
                    <button type="submit" :disabled="isSaving || !form.acknowledged" class="rounded-sm bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isSaving ? 'Saving...' : 'Continue' }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
