<script setup>
import { computed, ref, watch } from 'vue';
import { formatFileSize } from '../support/display';

const props = defineProps({
    document: { type: Object, default: null },
    context: { type: String, default: '' },
    saving: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['close', 'save', 'clear-error']);
const status = ref('pending');
const reviewNotes = ref('');
const localError = ref('');
const statusOptions = [
    { value: 'pending', label: 'Pending' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'needs_replacement', label: 'Needs replacement' },
    { value: 'rejected', label: 'Rejected' },
];
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100';
const labelClass = 'mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500';
const requiresNote = computed(() => ['rejected', 'needs_replacement'].includes(status.value));
const canPreview = computed(() => {
    const mimeType = props.document?.mime_type ?? '';
    const fileName = props.document?.original_name ?? '';

    return mimeType === 'application/pdf'
        || mimeType.startsWith('image/')
        || /\.(pdf|jpe?g|png)$/i.test(fileName);
});
const displayedError = computed(() => props.error || localError.value);

watch(() => props.document, (document) => {
    status.value = document?.status ?? 'pending';
    reviewNotes.value = document?.review_notes ?? '';
    localError.value = '';
});

watch([status, reviewNotes], () => {
    localError.value = '';
    emit('clear-error');
});

function requestClose() {
    if (!props.saving) {
        emit('close');
    }
}

function submitReview() {
    const note = reviewNotes.value.trim();

    if (requiresNote.value && !note) {
        localError.value = 'Add a note explaining why the file was rejected or needs replacement.';
        return;
    }

    emit('save', {
        document: props.document,
        status: status.value,
        review_notes: note,
    });
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="document"
            class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5"
            @click.self="requestClose"
        >
            <section class="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Document review</p>
                        <h2 class="mt-1 truncate text-lg font-bold text-slate-950">{{ document.document_name }}</h2>
                        <p v-if="context" class="mt-1 truncate text-xs font-semibold text-slate-600">{{ context }}</p>
                        <p class="mt-1 truncate text-xs text-slate-500">
                            {{ document.original_name }} - {{ formatFileSize(document.size) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="saving"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                        aria-label="Close document review"
                        @click="requestClose"
                    >
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto lg:grid lg:grid-cols-[minmax(0,1fr)_22rem] lg:overflow-hidden">
                    <div class="min-h-[22rem] bg-slate-100 p-3 sm:p-4 lg:min-h-0">
                        <iframe
                            v-if="canPreview"
                            :src="document.view_url"
                            :title="document.document_name"
                            class="h-[55vh] w-full rounded-md border border-slate-200 bg-white lg:h-full"
                        ></iframe>
                        <div v-else class="flex h-[55vh] flex-col items-center justify-center rounded-md border border-dashed border-slate-300 bg-white p-6 text-center lg:h-full">
                            <span class="inline-flex h-12 w-12 items-center justify-center rounded-md bg-slate-100 text-slate-700">
                                <i class="fa-solid fa-file-arrow-down text-lg"></i>
                            </span>
                            <h3 class="mt-4 font-bold text-slate-950">Preview is not available for this file type</h3>
                            <p class="mt-2 max-w-sm text-sm leading-6 text-slate-600">
                                PDF and image files open here. Download this file only if you need to review it in another application.
                            </p>
                            <a
                                :href="document.download_url"
                                class="mt-4 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                            >
                                Download original
                            </a>
                        </div>
                    </div>

                    <aside class="border-t border-slate-200 bg-white p-4 sm:p-5 lg:overflow-y-auto lg:border-l lg:border-t-0">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Review decision</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Check the file first, then record the result for the applicant.
                        </p>

                        <div class="mt-4">
                            <label :class="labelClass">Status</label>
                            <select v-model="status" :class="inputClass">
                                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <div class="mt-4">
                            <label :class="labelClass">
                                Review note <span v-if="requiresNote" class="text-rose-600">*</span>
                            </label>
                            <textarea
                                v-model="reviewNotes"
                                rows="5"
                                maxlength="1000"
                                :placeholder="requiresNote ? 'Explain what the applicant needs to correct' : 'Optional note for the applicant'"
                                :class="inputClass"
                            ></textarea>
                            <p class="mt-2 text-xs leading-5 text-slate-500">
                                A clear note is required when rejecting a file or requesting a replacement.
                            </p>
                        </div>

                        <div v-if="displayedError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">
                            {{ displayedError }}
                        </div>

                        <div class="mt-5 grid gap-2">
                            <button
                                type="button"
                                :disabled="saving"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                @click="submitReview"
                            >
                                {{ saving ? 'Saving decision...' : 'Save decision' }}
                            </button>
                            <button
                                type="button"
                                :disabled="saving"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                @click="requestClose"
                            >
                                Close
                            </button>
                        </div>
                    </aside>
                </div>
            </section>
        </div>
    </Teleport>
</template>
