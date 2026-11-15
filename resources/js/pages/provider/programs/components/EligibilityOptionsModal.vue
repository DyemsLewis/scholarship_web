<script setup>
import { ref } from 'vue';
import { useModalAccessibility } from '../../../../composables/useModalAccessibility';

const props = defineProps({
    open: Boolean, id: { type: String, required: true }, title: { type: String, required: true },
    description: { type: String, default: 'Apply your selections to this form. Save the program to keep your changes.' },
    busy: Boolean,
});
const emit = defineEmits(['apply', 'close']);
const dialog = ref(null);
const close = () => { if (!props.busy) emit('close'); };
const { handleKeydown } = useModalAccessibility(() => props.open, dialog, close);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2100] flex items-center justify-center bg-slate-950/55 p-6" role="presentation" @click.self="close">
            <form :id="`${id}-options`" ref="dialog" class="provider-program-workspace flex max-h-[calc(100dvh-3rem)] w-full max-w-3xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white shadow-2xl" role="dialog" aria-modal="true" :aria-labelledby="`${id}-title`" :aria-describedby="`${id}-description`" :data-eligibility-options-for="id" tabindex="-1" @keydown="handleKeydown" @submit.prevent="!busy && emit('apply')">
                <header class="flex shrink-0 items-start justify-between gap-5 border-b border-slate-200 px-6 py-5">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300" aria-hidden="true"><i class="fa-solid fa-list-check block text-sm leading-none"></i></span>
                        <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Eligibility options</p><h2 :id="`${id}-title`" class="mt-1 text-lg font-bold text-slate-950">{{ title }}</h2><p :id="`${id}-description`" class="mt-1 text-xs leading-5 text-slate-500">{{ description }}</p></div>
                    </div>
                    <button type="button" :disabled="busy" :aria-label="`Close ${title}`" class="grid size-9 shrink-0 place-items-center rounded-sm border border-slate-300 text-slate-600 hover:bg-slate-50" @click="close"><i class="fa-solid fa-xmark block text-sm leading-none" aria-hidden="true"></i></button>
                </header>
                <div class="min-h-0 space-y-5 overflow-y-auto overscroll-contain px-6 py-5"><slot /></div>
                <footer class="flex shrink-0 items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-xs text-slate-500">Changes apply only when you confirm.</p>
                    <div class="flex shrink-0 gap-2"><button type="button" :disabled="busy" class="rounded-sm border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700" @click="close">Cancel</button><button type="submit" :disabled="busy" class="rounded-sm bg-slate-950 px-4 py-2.5 text-xs font-bold text-white disabled:opacity-50">Apply selections</button></div>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
