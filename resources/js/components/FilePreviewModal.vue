<script setup>
import { computed, onUnmounted, watch } from 'vue';
import { formatFileSize } from '../support/display';

const props = defineProps({
    file: { type: Object, default: null },
    title: { type: String, default: 'File preview' },
    context: { type: String, default: '' },
});

const emit = defineEmits(['close']);
const fileName = computed(() => props.file?.original_name || props.title);
const previewUrl = computed(() => props.file?.view_url || '');
const downloadUrl = computed(() => props.file?.download_url || props.file?.view_url || '');
const isImage = computed(() => {
    const mimeType = props.file?.mime_type ?? '';

    return mimeType.startsWith('image/')
        || /\.(gif|jpe?g|png|webp|bmp)$/i.test(fileName.value);
});
const isPdf = computed(() => {
    const mimeType = props.file?.mime_type ?? '';

    return mimeType === 'application/pdf' || /\.pdf$/i.test(fileName.value);
});

watch(() => props.file, (file) => {
    document.body.classList.toggle('overflow-hidden', Boolean(file));
}, { immediate: true });

onUnmounted(() => {
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="file"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5"
            role="dialog"
            aria-modal="true"
            tabindex="-1"
            @click.self="emit('close')"
            @keydown.esc="emit('close')"
        >
            <section class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">File preview</p>
                        <h2 class="mt-1 truncate text-lg font-bold text-slate-950">{{ title }}</h2>
                        <p v-if="context" class="mt-1 truncate text-xs font-semibold text-slate-600">{{ context }}</p>
                        <p class="mt-1 truncate text-xs text-slate-500">
                            {{ fileName }}<template v-if="file.size"> - {{ formatFileSize(file.size) }}</template>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                        aria-label="Close file preview"
                        @click="emit('close')"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-[22rem] flex-1 overflow-auto bg-slate-100 p-3 sm:p-4">
                    <div v-if="isImage" class="flex min-h-[22rem] h-[65vh] items-center justify-center overflow-auto rounded-md border border-slate-200 bg-white p-3">
                        <img
                            :src="previewUrl"
                            :alt="title"
                            class="max-h-full max-w-full object-contain"
                        >
                    </div>
                    <iframe
                        v-else-if="isPdf"
                        :src="previewUrl"
                        :title="title"
                        class="h-[65vh] min-h-[22rem] w-full rounded-md border border-slate-200 bg-white"
                    ></iframe>
                    <div v-else class="flex h-[65vh] min-h-[22rem] flex-col items-center justify-center rounded-md border border-dashed border-slate-300 bg-white p-6 text-center">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-md bg-slate-100 text-slate-700">
                            <i class="fa-solid fa-file-arrow-down text-lg" aria-hidden="true"></i>
                        </span>
                        <h3 class="mt-4 font-bold text-slate-950">Preview is unavailable for this file type</h3>
                        <p class="mt-2 max-w-md text-sm leading-6 text-slate-600">
                            Images and PDF files appear here. Download this file to open it in a compatible application.
                        </p>
                    </div>
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-5">
                    <a
                        v-if="downloadUrl"
                        :href="downloadUrl"
                        download
                        class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                    >
                        Download file
                    </a>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                        @click="emit('close')"
                    >
                        Close
                    </button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
