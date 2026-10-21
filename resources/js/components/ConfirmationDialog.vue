<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Confirm action' },
    message: { type: String, default: 'Please confirm that you want to continue.' },
    confirmLabel: { type: String, default: 'Confirm' },
    cancelLabel: { type: String, default: 'Cancel' },
    eyebrow: { type: String, default: 'Confirmation required' },
    tone: { type: String, default: 'warning' },
});

const emit = defineEmits(['confirm', 'cancel']);
const cancelButton = ref(null);

function handleKeydown(event) {
    if (props.open && event.key === 'Escape') {
        emit('cancel');
    }
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        nextTick(() => cancelButton.value?.focus());
    }
});

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/55 p-4"
            role="presentation"
            @click.self="emit('cancel')"
        >
            <section
                :class="[
                    'w-full max-w-md overflow-hidden rounded-md border border-slate-300 border-t-4 bg-white shadow-2xl',
                    tone === 'danger' ? 'border-t-rose-600' : 'border-t-amber-400',
                ]"
                role="alertdialog"
                aria-modal="true"
                :aria-labelledby="'confirmation-dialog-title'"
                :aria-describedby="'confirmation-dialog-message'"
            >
                <div class="flex items-start gap-4 p-5 sm:p-6">
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
                        <h2 id="confirmation-dialog-title" class="mt-1 text-lg font-bold text-slate-950">{{ title }}</h2>
                        <p id="confirmation-dialog-message" class="mt-2 text-sm leading-6 text-slate-600">{{ message }}</p>
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end">
                    <button ref="cancelButton" type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100" @click="emit('cancel')">
                        {{ cancelLabel }}
                    </button>
                    <button
                        type="button"
                        :class="[
                            'rounded-md px-4 py-2.5 text-sm font-bold text-white transition',
                            tone === 'danger' ? 'bg-rose-700 hover:bg-rose-800' : 'bg-slate-900 hover:bg-slate-800',
                        ]"
                        @click="emit('confirm')"
                    >
                        {{ confirmLabel }}
                    </button>
                </div>
            </section>
        </div>
    </Teleport>
</template>
