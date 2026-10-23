<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    eyebrow: {
        type: String,
        default: 'Edit applicant profile',
    },
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    icon: {
        type: String,
        default: 'fa-solid fa-pen',
    },
    size: {
        type: String,
        default: 'standard',
        validator: (value) => ['compact', 'standard', 'wide'].includes(value),
    },
    saving: {
        type: Boolean,
        default: false,
    },
    dirty: {
        type: Boolean,
        default: false,
    },
    saveDisabled: {
        type: Boolean,
        default: false,
    },
    saveLabel: {
        type: String,
        default: 'Save changes',
    },
    closeLabel: {
        type: String,
        default: 'Cancel',
    },
    showFooter: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close', 'save']);

const dialog = ref(null);
const closeButton = ref(null);
let previouslyFocused = null;
let previousBodyOverflow = '';

const widthClass = computed(() => ({
    compact: 'max-w-2xl',
    standard: 'max-w-4xl',
    wide: 'max-w-6xl',
}[props.size]));

const actionLabel = computed(() => props.saving ? 'Saving...' : props.saveLabel);

function focusableElements() {
    if (!dialog.value) {
        return [];
    }

    return [...dialog.value.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )].filter((element) => !element.hasAttribute('hidden') && element.offsetParent !== null);
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');
        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const elements = focusableElements();

    if (!elements.length) {
        event.preventDefault();
        dialog.value?.focus();
        return;
    }

    const first = elements[0];
    const last = elements[elements.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(() => props.open, async (open) => {
    if (open) {
        previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        await nextTick();
        closeButton.value?.focus();
        return;
    }

    document.body.style.overflow = previousBodyOverflow;
    previouslyFocused?.focus?.();
    previouslyFocused = null;
}, { immediate: true });

onBeforeUnmount(() => {
    document.body.style.overflow = previousBodyOverflow;
});
</script>

<template>
    <Teleport to="body">
        <Transition name="profile-modal">
            <div
                v-if="open"
                class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-2 sm:p-5"
                role="presentation"
                @mousedown.self="$emit('close')"
            >
                <section
                    ref="dialog"
                    :class="['flex max-h-[96vh] w-full flex-col overflow-hidden rounded-lg border border-slate-300 bg-white shadow-2xl outline-none sm:max-h-[92vh]', widthClass]"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="profile-editor-title"
                    aria-describedby="profile-editor-description"
                    tabindex="-1"
                    @keydown="handleKeydown"
                >
                    <header class="shrink-0 border-b border-slate-200 bg-white px-4 py-4 sm:px-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-sm text-amber-300">
                                    <i :class="icon" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">{{ eyebrow }}</p>
                                    <h2 id="profile-editor-title" class="mt-1 text-xl font-bold leading-tight text-slate-950">{{ title }}</h2>
                                    <p v-if="description" id="profile-editor-description" class="mt-1 max-w-3xl text-sm leading-5 text-slate-500">{{ description }}</p>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <slot name="status" />
                                <button
                                    ref="closeButton"
                                    type="button"
                                    class="grid h-10 w-10 place-items-center rounded-md border border-slate-300 bg-white text-slate-600 transition hover:bg-slate-100 hover:text-slate-950"
                                    aria-label="Close profile editor"
                                    title="Close profile editor"
                                    @click="$emit('close')"
                                >
                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-white">
                        <slot />
                    </div>

                    <footer v-if="showFooter" class="shrink-0 border-t border-slate-200 bg-white px-4 py-3 shadow-[0_-8px_20px_rgba(15,23,42,0.06)] sm:px-5">
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p :class="['text-xs font-semibold', dirty ? 'text-amber-700' : 'text-slate-400']" aria-live="polite">
                                {{ dirty ? 'Unsaved changes' : 'No unsaved changes' }}
                            </p>
                            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="saving"
                                    @click="$emit('close')"
                                >
                                    {{ closeLabel }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="saving || saveDisabled"
                                    @click="$emit('save')"
                                >
                                    <i :class="saving ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-floppy-disk'" aria-hidden="true"></i>
                                    {{ actionLabel }}
                                </button>
                            </div>
                        </div>
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.profile-modal-enter-active,
.profile-modal-leave-active {
    transition: opacity 160ms ease;
}

.profile-modal-enter-active section,
.profile-modal-leave-active section {
    transition: transform 160ms ease, opacity 160ms ease;
}

.profile-modal-enter-from,
.profile-modal-leave-to {
    opacity: 0;
}

.profile-modal-enter-from section,
.profile-modal-leave-to section {
    opacity: 0;
    transform: translateY(10px);
}

@media (prefers-reduced-motion: reduce) {
    .profile-modal-enter-active,
    .profile-modal-leave-active,
    .profile-modal-enter-active section,
    .profile-modal-leave-active section {
        transition: none;
    }
}
</style>
