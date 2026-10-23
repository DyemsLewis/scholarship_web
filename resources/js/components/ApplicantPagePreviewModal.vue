<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import ApplicantPreviewViewport from './ApplicantPreviewViewport.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Applicant preview' },
    description: { type: String, default: 'This is how the saved information appears to applicants.' },
    status: { type: String, default: '' },
    scenario: { type: String, default: 'general' },
    showScenario: { type: Boolean, default: false },
    fullPageUrl: { type: String, default: '' },
});

const emit = defineEmits(['close', 'update:scenario']);
const closeButton = ref(null);
const dialogPanel = ref(null);
let previousFocus = null;
let bodyWasLocked = false;
let modalEnvironmentActive = false;

function handleKeydown(event) {
    if (event.key === 'Escape') {
        emit('close');
        return;
    }

    if (event.key !== 'Tab' || !dialogPanel.value) {
        return;
    }

    const focusable = [...dialogPanel.value.querySelectorAll(
        'a[href], button:not([disabled]), select:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )].filter((element) => !element.hasAttribute('hidden'));

    if (!focusable.length) {
        event.preventDefault();
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function releaseModalEnvironment() {
    if (!modalEnvironmentActive) {
        return;
    }

    window.removeEventListener('keydown', handleKeydown);

    if (!bodyWasLocked) {
        document.body.classList.remove('overflow-hidden');
    }

    if (previousFocus instanceof HTMLElement) {
        previousFocus.focus();
    }

    previousFocus = null;
    bodyWasLocked = false;
    modalEnvironmentActive = false;
}

watch(() => props.open, async (isOpen) => {
    if (!isOpen) {
        releaseModalEnvironment();
        return;
    }

    previousFocus = document.activeElement;
    bodyWasLocked = document.body.classList.contains('overflow-hidden');
    modalEnvironmentActive = true;
    document.body.classList.add('overflow-hidden');
    window.addEventListener('keydown', handleKeydown);

    await nextTick();
    closeButton.value?.focus();
}, { immediate: true });

onBeforeUnmount(releaseModalEnvironment);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[190] flex items-center justify-center bg-slate-950/70 p-4"
            role="presentation"
            data-applicant-page-preview-modal
            @click.self="emit('close')"
        >
            <section
                ref="dialogPanel"
                class="flex h-[calc(100dvh-2rem)] w-full max-w-[100rem] flex-col overflow-hidden border border-slate-300 bg-white shadow-2xl"
                role="dialog"
                aria-modal="true"
                aria-labelledby="applicant-page-preview-title"
                aria-describedby="applicant-page-preview-description"
            >
                <header class="flex shrink-0 items-center justify-between gap-6 border-b border-slate-300 bg-white px-5 py-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-950 text-amber-300" aria-hidden="true">
                            <i class="fa-solid fa-eye"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Applicant view</p>
                                <span v-if="status" class="border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600">{{ status }}</span>
                            </div>
                            <h2 id="applicant-page-preview-title" class="mt-0.5 truncate text-lg font-bold text-slate-950">{{ title }}</h2>
                            <p id="applicant-page-preview-description" class="mt-0.5 truncate text-xs text-slate-500">{{ description }}</p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <label v-if="showScenario" class="flex h-10 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700">
                            Applicant state
                            <select :value="scenario" class="bg-transparent text-xs font-bold text-slate-950 outline-none" @change="emit('update:scenario', $event.target.value)">
                                <option value="general">General</option>
                                <option value="eligible">Eligible</option>
                                <option value="ineligible">Not eligible</option>
                            </select>
                        </label>

                        <a v-if="fullPageUrl" :href="fullPageUrl" target="_blank" rel="noopener" class="grid h-10 w-10 place-items-center border border-slate-300 text-slate-600 hover:border-slate-950 hover:text-slate-950" aria-label="Open full-page preview" title="Open full-page preview">
                            <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                        </a>

                        <button ref="closeButton" type="button" class="grid h-10 w-10 place-items-center bg-slate-950 text-white hover:bg-slate-800" aria-label="Close applicant preview" @click="emit('close')">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                </header>

                <ApplicantPreviewViewport>
                    <slot />
                </ApplicantPreviewViewport>
            </section>
        </div>
    </Teleport>
</template>
