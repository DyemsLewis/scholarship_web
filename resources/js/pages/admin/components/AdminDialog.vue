<script setup>
import { computed, ref, useId, watch } from 'vue';
import { useModalAccessibility } from '../../../composables/useModalAccessibility';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    titleId: { type: String, default: '' },
    description: { type: String, default: '' },
    eyebrow: { type: String, default: 'Confirm change' },
    icon: { type: String, default: 'fa-solid fa-clipboard-check' },
    confirmLabel: { type: String, default: 'Save changes' },
    confirmDisabled: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    danger: { type: Boolean, default: false },
    wide: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);
const dialog = ref(null);
const body = ref(null);
const id = useId();
const headingId = computed(() => props.titleId || `${id}-title`);
const descriptionId = `${id}-description`;
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);

function close() {
    if (!props.busy) emit('cancel');
}

function confirm() {
    if (!props.busy && !props.confirmDisabled) emit('confirm');
}

watch(() => props.error, (message) => {
    if (message && body.value) body.value.scrollTop = 0;
}, { flush: 'post' });

watch(() => props.busy, (busy) => {
    if (busy && props.open) dialog.value?.focus({ preventScroll: true });
}, { flush: 'post' });
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2000] grid place-items-center bg-slate-950/60 p-6" @click.self="close">
            <form ref="dialog" :class="['admin-dialog w-full rounded-sm border border-slate-300 bg-white text-slate-900 shadow-2xl', wide ? 'max-w-2xl' : 'max-w-lg']" role="dialog" aria-modal="true" :aria-labelledby="headingId" :aria-describedby="description ? descriptionId : undefined" :aria-busy="busy" tabindex="-1" @keydown="handleKeydown" @submit.prevent="confirm">
                <header class="flex items-start justify-between gap-5 border-b border-slate-200 px-6 py-5">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="admin-dialog-icon grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600" aria-hidden="true"><i :class="icon"></i></span>
                        <div class="min-w-0">
                            <p v-if="eyebrow" class="text-xs font-bold uppercase tracking-wide text-amber-700">{{ eyebrow }}</p>
                            <h2 :id="headingId" class="mt-1 text-xl font-bold leading-7 text-slate-950">{{ title }}</h2>
                            <p v-if="description" :id="descriptionId" class="mt-1 text-sm leading-6 text-slate-600">{{ description }}</p>
                        </div>
                    </div>
                    <button type="button" :disabled="busy" class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-slate-300 text-slate-600 hover:border-slate-700 disabled:opacity-50" aria-label="Close dialog" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div ref="body" class="admin-dialog-body space-y-5 px-6 py-5">
                    <p v-if="error" class="border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ error }}</p>
                    <fieldset :disabled="busy" :inert="busy || undefined" class="min-w-0 space-y-5"><slot></slot></fieldset>
                </div>
                <footer class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <button type="button" :disabled="busy" class="h-10 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-700 disabled:opacity-50" @click="close">Cancel</button>
                    <button type="submit" :disabled="busy || confirmDisabled" :class="['inline-flex h-10 items-center gap-2 rounded-sm px-4 text-sm font-bold text-white disabled:opacity-50', danger ? 'bg-rose-700 hover:bg-rose-800' : 'bg-slate-950 hover:bg-slate-800']"><i v-if="busy" class="fa-solid fa-spinner fa-spin text-xs" aria-hidden="true"></i>{{ busy ? 'Saving...' : confirmLabel }}</button>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
