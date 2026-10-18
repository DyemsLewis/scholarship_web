<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { shouldPreviewStaffFileClick } from '../support/staffFilePreview';
import ZoomableImagePreview from './ZoomableImagePreview.vue';

const preview = ref(null);
const loading = ref(false);
const mimeType = ref('');
const loadError = ref('');
const closeButton = ref(null);
let requestId = 0;
let returnFocus = null;

const isImage = computed(() => mimeType.value.startsWith('image/'));
const isPdf = computed(() => mimeType.value === 'application/pdf');
const canEmbed = computed(() => (
    isPdf.value
    || mimeType.value.startsWith('text/')
    || ['application/json', 'application/xml'].includes(mimeType.value)
));

function cleanLabel(value) {
    return String(value || '')
        .replace(/^(open|view|preview|review)\s+/i, '')
        .replace(/\s+(file|document)$/i, '')
        .trim();
}

function previewTitle(anchor) {
    const explicit = cleanLabel(anchor.dataset.fileName || anchor.textContent);

    if (explicit && !['file', 'document', 'attachment', 'receipt', 'evidence'].includes(explicit.toLowerCase())) {
        return explicit;
    }

    const container = anchor.closest('tr, li, article, section');
    const nearbyName = container?.querySelector('[data-file-name], strong, h3, p')?.textContent;
    const nearby = cleanLabel(nearbyName);

    if (nearby) {
        return nearby;
    }

    return cleanLabel(anchor.getAttribute('aria-label') || anchor.getAttribute('title')) || 'File preview';
}

function downloadUrl(anchor) {
    if (anchor.dataset.downloadUrl) {
        return anchor.dataset.downloadUrl;
    }

    const container = anchor.closest('tr, li, article, section');
    const downloadLink = container?.querySelector('a[download], a[title*="Download" i], a[aria-label*="Download" i]');

    return downloadLink?.href || anchor.href;
}

function inferredMimeType(title, url) {
    const candidate = `${title} ${new URL(url, window.location.href).pathname}`;

    if (/\.(gif|jpe?g|png|webp|bmp|svg)(?:\s|$)/i.test(candidate)) return 'image/*';
    if (/\.pdf(?:\s|$)/i.test(candidate)) return 'application/pdf';
    if (/\.(txt|csv|json|xml)(?:\s|$)/i.test(candidate)) return 'text/plain';

    return '';
}

async function inspectFile(id) {
    loading.value = true;
    loadError.value = '';
    mimeType.value = inferredMimeType(preview.value.title, preview.value.viewUrl);

    try {
        const response = await fetch(preview.value.viewUrl, {
            method: 'HEAD',
            credentials: 'same-origin',
            headers: { Accept: '*/*' },
        });

        if (!response.ok) {
            throw new Error('The file could not be loaded.');
        }

        if (id !== requestId) return;
        mimeType.value = (response.headers.get('content-type') || mimeType.value).split(';')[0].trim();
    } catch (error) {
        if (id !== requestId) return;

        if (!mimeType.value) {
            loadError.value = error.message || 'The file could not be previewed.';
        }
    } finally {
        if (id === requestId) loading.value = false;
    }
}

async function openPreview(anchor) {
    requestId += 1;
    returnFocus = anchor;
    preview.value = {
        viewUrl: anchor.href,
        downloadUrl: downloadUrl(anchor),
        title: previewTitle(anchor),
    };
    document.body.classList.add('overflow-hidden');
    await nextTick();
    closeButton.value?.focus();
    inspectFile(requestId);
}

function closePreview() {
    requestId += 1;
    preview.value = null;
    loading.value = false;
    mimeType.value = '';
    loadError.value = '';
    document.body.classList.remove('overflow-hidden');
    returnFocus?.focus?.();
    returnFocus = null;
}

function handleDocumentClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    const anchor = target?.closest('a[href]');

    if (!shouldPreviewStaffFileClick(event, anchor, window.location.href)) {
        return;
    }

    event.preventDefault();
    openPreview(anchor);
}

function handleKeydown(event) {
    if (event.key === 'Escape' && preview.value) {
        closePreview();
    }
}

onMounted(() => {
    document.addEventListener('click', handleDocumentClick, true);
    document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener('click', handleDocumentClick, true);
    document.removeEventListener('keydown', handleKeydown);
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="preview"
            class="fixed inset-0 z-[3200] flex items-center justify-center bg-slate-950/75 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="staff-file-preview-title"
            @click.self="closePreview"
        >
            <section class="flex h-[min(92vh,56rem)] w-full max-w-6xl flex-col overflow-hidden border border-slate-300 bg-white shadow-2xl">
                <header class="flex min-h-16 items-center justify-between gap-5 border-b border-slate-200 px-5 py-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">File preview</p>
                        <h2 id="staff-file-preview-title" class="mt-1 truncate text-lg font-bold text-slate-950">
                            {{ preview.title }}
                        </h2>
                    </div>
                    <button
                        ref="closeButton"
                        type="button"
                        class="grid h-10 w-10 shrink-0 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950 hover:text-slate-950"
                        aria-label="Close file preview"
                        @click="closePreview"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="relative min-h-0 flex-1 bg-slate-100 p-4">
                    <div v-if="loading" class="absolute inset-0 z-10 grid place-items-center bg-slate-100">
                        <div class="text-center text-slate-600">
                            <i class="fa-solid fa-circle-notch fa-spin text-xl" aria-hidden="true"></i>
                            <p class="mt-3 text-sm font-semibold">Preparing preview...</p>
                        </div>
                    </div>

                    <ZoomableImagePreview
                        v-else-if="isImage"
                        :src="preview.viewUrl"
                        :alt="preview.title"
                        class="h-full min-h-[24rem]"
                    />
                    <iframe
                        v-else-if="canEmbed"
                        :src="preview.viewUrl"
                        :title="preview.title"
                        class="h-full min-h-[24rem] w-full border border-slate-200 bg-white"
                    ></iframe>
                    <div v-else class="grid h-full min-h-[24rem] place-items-center border border-dashed border-slate-300 bg-white p-8 text-center">
                        <div class="max-w-md">
                            <span class="grid h-12 w-12 place-items-center bg-slate-100 text-slate-700 mx-auto">
                                <i class="fa-solid fa-file-arrow-down text-lg" aria-hidden="true"></i>
                            </span>
                            <h3 class="mt-4 text-base font-bold text-slate-950">
                                {{ loadError ? 'Preview could not be loaded' : 'Preview is unavailable for this file type' }}
                            </h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                {{ loadError || 'Images, PDFs, and text files open here. Download this file to use another application.' }}
                            </p>
                        </div>
                    </div>
                </div>

                <footer class="flex items-center justify-between gap-4 border-t border-slate-200 bg-white px-5 py-3">
                    <p class="text-xs text-slate-500">The workspace stays open behind this preview.</p>
                    <div class="flex items-center gap-2">
                        <a
                            :href="preview.downloadUrl"
                            download
                            data-file-preview="false"
                            class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950"
                        >
                            <i class="fa-solid fa-download text-xs" aria-hidden="true"></i>
                            Download
                        </a>
                        <button type="button" class="h-10 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800" @click="closePreview">
                            Close
                        </button>
                    </div>
                </footer>
            </section>
        </div>
    </Teleport>
</template>
