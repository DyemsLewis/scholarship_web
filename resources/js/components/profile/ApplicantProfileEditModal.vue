<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { lockPageScroll } from '../../support/pageScrollLock';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
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
    busy: {
        type: Boolean,
        default: false,
    },
    instantChanges: {
        type: Boolean,
        default: false,
    },
    suspended: {
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
let releaseScrollLock = null;
let nestedOpener = null;

const widthClass = computed(() => ({
    compact: 'max-w-2xl',
    standard: 'max-w-4xl',
    wide: 'max-w-6xl',
}[props.size]));

const actionLabel = computed(() => props.saving ? 'Saving...' : props.saveLabel);
const working = computed(() => props.saving || props.busy);
const footerMessage = computed(() => props.dirty ? props.instantChanges ? 'Academic result not saved. Files are saved immediately.' : 'Profile changes not saved'
    : props.instantChanges ? 'Uploads and removals are saved immediately.' : 'No changes to save yet.');

function close() {
    if (!working.value) emit('close');
}

function focusableElements() {
    if (!dialog.value) {
        return [];
    }

    return [...dialog.value.querySelectorAll(
        'a[href], summary, button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )].filter((element) => !element.hasAttribute('hidden') && element.offsetParent !== null);
}

function handleKeydown(event) {
    if (props.suspended) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close();
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

    if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === dialog.value)) {
        event.preventDefault();
        first.focus();
    }
}

watch(() => props.open, async (open) => {
    if (open) {
        previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        releaseScrollLock ??= lockPageScroll();
        await nextTick();
        if (props.open) closeButton.value?.focus({ preventScroll: true });
        return;
    }

    releaseScrollLock?.();
    releaseScrollLock = null;
    previouslyFocused?.focus?.({ preventScroll: true });
    previouslyFocused = null;
}, { immediate: true });

watch(() => props.suspended, async (suspended) => {
    if (!props.open) return;
    if (suspended) {
        nestedOpener = document.activeElement;
        await nextTick();
        const nested = [...document.querySelectorAll('[role="dialog"], [role="alertdialog"]')]
            .filter((element) => element !== dialog.value && element.offsetParent !== null).at(-1);
        nested?.focus({ preventScroll: true });
    } else {
        await nextTick();
        nestedOpener?.focus?.({ preventScroll: true });
        nestedOpener = null;
    }
});

onBeforeUnmount(() => {
    releaseScrollLock?.();
});
</script>

<template>
    <Teleport to="body">
        <Transition name="profile-modal">
            <div
                v-if="open"
                class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-2 sm:p-5"
                role="presentation"
                @mousedown.self="close"
            >
                <section
                    ref="dialog"
                    :class="['flex max-h-[96dvh] w-full flex-col overflow-hidden rounded-sm border border-slate-300 bg-white shadow-2xl outline-none sm:max-h-[92dvh]', widthClass]"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="profile-editor-title"
                    aria-describedby="profile-editor-description"
                    :aria-busy="working"
                    tabindex="-1"
                    @keydown="handleKeydown"
                >
                    <header data-profile-editor-header class="shrink-0 border-b-2 border-amber-400 bg-[#081426] px-4 py-4 text-white sm:px-6">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-white/10 text-sm text-amber-300">
                                    <i :class="[icon, 'block leading-none']" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <h2 id="profile-editor-title" class="font-display text-xl font-bold leading-tight text-white sm:text-2xl">{{ title }}</h2>
                                    <p v-if="description" id="profile-editor-description" class="mt-1 max-w-3xl text-xs leading-5 text-slate-300">{{ description }}</p>
                                    <div v-if="$slots.status" class="mt-2"><slot name="status" /></div>
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    ref="closeButton"
                                    type="button"
                                    class="grid h-10 w-10 place-items-center rounded-sm border border-white/20 bg-white/5 text-slate-200 transition hover:bg-white/15 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-300 disabled:opacity-50"
                                    aria-label="Close profile editor"
                                    title="Close profile editor"
                                    :disabled="working"
                                    @click="close"
                                >
                                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </header>

                    <div data-profile-editor-body class="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-white">
                        <fieldset :disabled="working" class="m-0 min-w-0 border-0 p-0"><slot /></fieldset>
                    </div>

                    <footer v-if="showFooter" class="shrink-0 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p data-profile-editor-save-state :class="['text-xs leading-5', dirty ? 'font-semibold text-amber-800' : 'text-slate-500']" aria-live="polite">
                                {{ working ? 'Please wait for the current change to finish.' : footerMessage }}
                            </p>
                            <div class="flex shrink-0 items-center justify-end gap-2">
                                <button
                                    v-if="!instantChanges || dirty"
                                    type="button"
                                    class="rounded-sm border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="working"
                                    @click="close"
                                >
                                    {{ closeLabel }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-2 rounded-sm bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="working || saveDisabled"
                                    @click="$emit('save')"
                                >
                                    <i v-if="dirty || saving" :class="saving ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-floppy-disk'" aria-hidden="true"></i>
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

.profile-modal-enter-active > section,
.profile-modal-leave-active > section {
    transition: transform 160ms ease, opacity 160ms ease;
}

.profile-modal-enter-from,
.profile-modal-leave-to {
    opacity: 0;
}

.profile-modal-enter-from > section,
.profile-modal-leave-to > section {
    opacity: 0;
    transform: translateY(10px);
}

@media (prefers-reduced-motion: reduce) {
    .profile-modal-enter-active,
    .profile-modal-leave-active,
    .profile-modal-enter-active > section,
    .profile-modal-leave-active > section {
        transition: none;
    }
}
</style>
