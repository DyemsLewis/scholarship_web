<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Check required information' },
    message: { type: String, default: '' },
    buttonLabel: { type: String, default: 'OK' },
});

const emit = defineEmits(['close']);
const closeButton = ref(null);

function handleKeydown(event) {
    if (props.open && event.key === 'Escape') {
        emit('close');
    }
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        nextTick(() => closeButton.value?.focus());
    }
});

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[2100] flex items-center justify-center bg-slate-950/55 p-4"
            role="presentation"
            @click.self="emit('close')"
        >
            <section
                class="w-full max-w-md overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="form-alert-title"
                aria-describedby="form-alert-message"
            >
                <div class="flex items-start gap-3 p-5">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <h2 id="form-alert-title" class="text-lg font-bold text-slate-950">{{ title }}</h2>
                        <p id="form-alert-message" class="mt-2 text-sm leading-6 text-slate-600">{{ message }}</p>
                    </div>
                </div>
                <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
                    <button
                        ref="closeButton"
                        type="button"
                        class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                        @click="emit('close')"
                    >
                        {{ buttonLabel }}
                    </button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
