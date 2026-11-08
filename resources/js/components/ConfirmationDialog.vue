<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../composables/useModalAccessibility';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    open: { type: Boolean, default: false },
    layer: { type: Number, default: 3000 },
    title: { type: String, default: 'Confirm action' },
    message: { type: String, default: 'Please confirm that you want to continue.' },
    confirmLabel: { type: String, default: 'Confirm' },
    cancelLabel: { type: String, default: 'Cancel' },
    eyebrow: { type: String, default: 'Confirmation required' },
    tone: { type: String, default: 'warning' },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    confirmDisabled: { type: Boolean, default: false },
    showCancel: { type: Boolean, default: true },
    titleId: { type: String, default: '' },
    messageId: { type: String, default: '' },
});

const emit = defineEmits(['confirm', 'cancel']);
const cancelButton = ref(null);
const confirmButton = ref(null);
const dialog = ref(null);
const body = ref(null);
const id = useId();
const headingId = computed(() => props.titleId || `${id}-confirmation-title`);
const descriptionId = computed(() => props.messageId || `${id}-confirmation-message`);
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, cancel);

function cancel() {
    if (!props.busy) emit('cancel');
}

function confirm() {
    if (!props.busy && !props.confirmDisabled) emit('confirm');
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        nextTick(() => {
            if (props.open) (cancelButton.value || confirmButton.value)?.focus({ preventScroll: true });
        });
    }
}, { immediate: true });
watch(() => props.error, (message) => {
    if (message && body.value) body.value.scrollTop = 0;
}, { flush: 'post' });
watch(() => props.busy, (busy) => {
    if (busy && props.open) dialog.value?.focus({ preventScroll: true });
}, { flush: 'post' });
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[3000] flex items-center justify-center bg-slate-950/55 p-4"
            :style="{ zIndex: layer }"
            role="presentation"
            @click.self="cancel"
        >
            <section
                v-bind="$attrs"
                ref="dialog"
                :class="[
                    'confirmation-dialog flex max-h-[calc(100dvh-2rem)] w-full max-w-md flex-col overflow-hidden rounded-md border border-slate-300 border-t-4 bg-white shadow-2xl',
                    tone === 'danger' ? 'border-t-rose-600' : 'border-t-amber-400',
                ]"
                role="alertdialog"
                aria-modal="true"
                :aria-labelledby="headingId"
                :aria-describedby="descriptionId"
                :aria-busy="busy"
                tabindex="-1"
                @keydown="handleKeydown"
            >
                <div ref="body" class="confirmation-dialog-body settings-dialog-body flex min-h-0 items-start gap-4 overflow-y-auto p-5 sm:p-6">
                    <div
                        :class="[
                            'flex h-11 w-11 shrink-0 items-center justify-center rounded-sm text-base',
                            tone === 'danger' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-800',
                        ]"
                    >
                        <i :class="[tone === 'danger' ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-circle-exclamation', 'leading-none']" aria-hidden="true"></i>
                    </div>
                    <div class="min-w-0">
                        <p :class="['text-[10px] font-bold uppercase tracking-[0.16em]', tone === 'danger' ? 'text-rose-700' : 'text-amber-700']">{{ eyebrow }}</p>
                        <h2 :id="headingId" class="mt-1 break-words text-lg font-bold text-slate-950">{{ title }}</h2>
                        <p :id="descriptionId" class="mt-2 whitespace-pre-line break-words text-sm leading-6 text-slate-600 [overflow-wrap:anywhere]">{{ message }}</p>
                        <p v-if="error" role="alert" class="mt-4 border-l-4 border-rose-500 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 [overflow-wrap:anywhere]">{{ error }}</p>
                    </div>
                </div>
                <div class="flex shrink-0 flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end">
                    <button v-if="showCancel" ref="cancelButton" type="button" :disabled="busy" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" @click="cancel">
                        {{ cancelLabel }}
                    </button>
                    <button
                        ref="confirmButton"
                        type="button"
                        :disabled="busy || confirmDisabled"
                        :class="[
                            'inline-flex items-center justify-center gap-2 rounded-md px-4 py-2.5 text-sm font-bold text-white transition disabled:cursor-not-allowed disabled:opacity-50',
                            tone === 'danger' ? 'bg-rose-700 hover:bg-rose-800' : 'bg-slate-900 hover:bg-slate-800',
                        ]"
                        @click="confirm"
                    >
                        <i v-if="busy" class="fa-solid fa-spinner fa-spin text-xs leading-none" aria-hidden="true"></i>{{ busy ? 'Saving...' : confirmLabel }}
                    </button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
