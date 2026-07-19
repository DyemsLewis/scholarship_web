<script setup>
import { computed, onUnmounted, watch } from 'vue';
import { formatFileSize, labelFromKey } from '../support/display';

const props = defineProps({
    proof: { type: Object, default: null },
    applicantName: { type: String, default: 'Applicant' },
});

const emit = defineEmits(['close']);
const canPreview = computed(() => {
    const mimeType = props.proof?.mime_type ?? '';
    const fileName = props.proof?.original_name ?? '';

    return mimeType === 'application/pdf'
        || mimeType.startsWith('image/')
        || /\.(pdf|jpe?g|png)$/i.test(fileName);
});
const proofLabel = computed(() => labelFromKey(props.proof?.document_type || 'profile proof'));

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

watch(() => props.proof, (proof) => {
    document.body.classList.toggle('overflow-hidden', Boolean(proof));
}, { immediate: true });

onUnmounted(() => {
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="proof"
            class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5"
            role="dialog"
            aria-modal="true"
            tabindex="-1"
            @click.self="emit('close')"
            @keydown.esc="emit('close')"
        >
            <section class="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Applicant profile proof</p>
                        <h2 class="mt-1 truncate text-lg font-bold text-slate-950">{{ proofLabel }}</h2>
                        <p class="mt-1 truncate text-xs font-semibold text-slate-600">{{ applicantName }}</p>
                        <p class="mt-1 truncate text-xs text-slate-500">
                            {{ proof.original_name }} - {{ formatFileSize(proof.size) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                        aria-label="Close profile proof"
                        @click="emit('close')"
                    >
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto lg:grid lg:grid-cols-[minmax(0,1fr)_20rem] lg:overflow-hidden">
                    <div class="min-h-[22rem] bg-slate-100 p-3 sm:p-4 lg:min-h-0">
                        <iframe
                            v-if="canPreview"
                            :src="proof.view_url"
                            :title="proofLabel"
                            class="h-[55vh] w-full rounded-md border border-slate-200 bg-white lg:h-full"
                        ></iframe>
                        <div v-else class="flex h-[55vh] flex-col items-center justify-center rounded-md border border-dashed border-slate-300 bg-white p-6 text-center lg:h-full">
                            <span class="inline-flex h-12 w-12 items-center justify-center rounded-md bg-slate-100 text-slate-700">
                                <i class="fa-solid fa-file-lines text-lg" aria-hidden="true"></i>
                            </span>
                            <h3 class="mt-4 font-bold text-slate-950">Preview is unavailable for this file type</h3>
                            <p class="mt-2 max-w-sm text-sm leading-6 text-slate-600">
                                Open the original file in a compatible application to review it.
                            </p>
                            <a
                                :href="proof.view_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-4 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Open original
                            </a>
                        </div>
                    </div>

                    <aside class="border-t border-slate-200 bg-white p-4 sm:p-5 lg:overflow-y-auto lg:border-l lg:border-t-0">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Admin verification</p>
                            <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(proof.status)]">
                                {{ labelFromKey(proof.status || 'submitted') }}
                            </span>
                        </div>

                        <p class="mt-4 text-sm leading-6 text-slate-600">
                            This file supports the applicant profile and is separate from the scholarship requirement checklist.
                        </p>

                        <dl class="mt-4 grid gap-3 text-sm">
                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <dt class="font-semibold text-slate-500">Uploaded</dt>
                                <dd class="mt-1 font-bold text-slate-950">{{ proof.uploaded_at || 'Date unavailable' }}</dd>
                            </div>
                            <div v-if="proof.review_notes" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <dt class="font-semibold text-slate-500">Admin note</dt>
                                <dd class="mt-1 leading-6 text-slate-700">{{ proof.review_notes }}</dd>
                            </div>
                        </dl>

                        <div class="mt-5 grid gap-2">
                            <a
                                :href="proof.view_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                            >
                                Open in new tab
                            </a>
                            <button
                                type="button"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="emit('close')"
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
