<script setup>
import { ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../composables/useModalAccessibility';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Confirm change' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirm' },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    danger: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);
const dialogPanel = ref(null);
const titleId = useId();
const messageId = useId();
const { handleKeydown } = useModalAccessibility(() => props.open, dialogPanel, close);

function close() {
    if (!props.busy) emit('cancel');
}

function confirm() {
    if (!props.busy) emit('confirm');
}

watch(() => props.error, (message) => {
    const body = dialogPanel.value?.querySelector('.settings-dialog-body');
    if (message && body) body.scrollTop = 0;
}, { flush: 'post' });
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2000] grid place-items-center bg-slate-950/60 p-6" @click.self="close">
            <section ref="dialogPanel" class="provider-settings-workspace settings-dialog w-full max-w-md border border-slate-300 bg-white shadow-2xl" role="alertdialog" aria-modal="true" :aria-labelledby="titleId" :aria-describedby="messageId" :aria-busy="busy" tabindex="-1" data-provider-action-dialog @keydown="handleKeydown">
                <header class="border-b border-slate-200 px-6 py-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-700">Confirm change</p>
                    <h2 :id="titleId" class="settings-text mt-1 text-xl font-bold text-slate-950">{{ title }}</h2>
                </header>
                <div class="settings-dialog-body space-y-4 px-6 py-5">
                    <p :id="messageId" class="settings-text text-sm leading-6 text-slate-600">{{ message }}</p>
                    <p v-if="error" class="border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ error }}</p>
                </div>
                <footer class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" :disabled="busy" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-50" @click="close">Cancel</button>
                    <button type="button" :disabled="busy" :class="['inline-flex h-10 items-center gap-2 px-4 text-sm font-bold text-white disabled:opacity-50', danger ? 'bg-rose-700 hover:bg-rose-800' : 'bg-slate-950 hover:bg-slate-800']" @click="confirm"><i v-if="busy" class="fa-solid fa-spinner fa-spin text-xs" aria-hidden="true"></i>{{ busy ? 'Saving...' : confirmLabel }}</button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
