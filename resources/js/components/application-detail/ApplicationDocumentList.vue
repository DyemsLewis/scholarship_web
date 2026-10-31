<script setup>
import { computed } from 'vue';

const props = defineProps({
    documents: {
        type: Array,
        default: () => [],
    },
    summary: {
        type: Object,
        default: () => ({}),
    },
    canEdit: {
        type: Boolean,
        default: false,
    },
    uploadingRequirement: {
        type: String,
        default: '',
    },
    uploadEnabled: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['preview', 'upload']);
const attentionCount = computed(() => props.documents.filter((document) => document.needs_attention).length);
const fileColumns = 'md:grid-cols-[minmax(0,1fr)_10rem_12rem]';

function statusClass(status) {
    return {
        accepted: 'student-badge-positive',
        pending: 'student-badge-info',
        rejected: 'student-badge-attention',
        needs_replacement: 'student-badge-attention',
        missing: '',
    }[status] ?? '';
}
</script>

<template>
    <section id="application-files" class="scroll-mt-4 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div>
                <h2 class="font-display text-lg font-bold text-slate-950">
                    {{ attentionCount ? `${attentionCount} ${attentionCount === 1 ? 'file needs' : 'files need'} attention` : 'Documents sent to the provider' }}
                </h2>
                <p class="mt-1 text-sm text-slate-600">
                    {{ attentionCount ? 'Start with the highlighted requirement below.' : 'Your required files are ready for provider review.' }}
                </p>
            </div>
            <div class="shrink-0 text-left sm:text-right">
                <p class="text-sm font-bold text-slate-950">
                    {{ summary.accepted ?? 0 }} of {{ summary.required ?? 0 }} accepted
                </p>
                <p class="mt-0.5 text-xs text-slate-500">{{ summary.uploaded ?? 0 }} uploaded</p>
            </div>
        </header>

        <div v-if="$slots.terms && canEdit" class="border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-6">
            <slot name="terms"></slot>
            <p id="application-upload-consent" :class="['mt-2 text-xs leading-5', uploadEnabled ? 'text-slate-600' : 'font-semibold text-amber-800']" role="status" aria-live="polite">{{ uploadEnabled ? 'Upload and Replace are enabled for this visit.' : 'Accept the upload terms above to enable Upload and Replace. You can still view your submitted files.' }}</p>
        </div>

        <div v-if="documents.length" class="divide-y divide-slate-200">
            <div :class="fileColumns" class="student-detail-label hidden items-center gap-4 border-l-4 border-transparent bg-slate-50 px-6 py-2.5 md:grid" data-application-file-header>
                <span>Requirement</span>
                <span class="text-center" data-document-status>Provider status</span>
                <span class="text-right" data-document-actions>Actions</span>
            </div>
            <article
                v-for="document in documents"
                :key="document.requirement"
                :class="['border-l-4 px-4 py-4 sm:px-6', document.needs_attention ? 'border-l-amber-400 bg-amber-50/40' : 'border-l-transparent']"
                data-application-file-row
            >
                <div :class="fileColumns" class="grid gap-3 md:items-center md:gap-4">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600">
                            <i :class="document.file ? 'fa-solid fa-file-lines' : 'fa-regular fa-file'" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-slate-950">{{ document.requirement }}</h3>
                                <span v-if="!document.required" class="rounded bg-slate-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-500">Optional</span>
                            </div>
                            <p v-if="document.file" class="mt-1 truncate text-xs text-slate-500">
                                {{ document.file.name }}<span v-if="document.file.uploaded_at"> · {{ document.file.uploaded_at }}</span>
                            </p>
                            <p v-else class="mt-1 text-xs text-slate-500">
                                {{ document.required ? 'No file uploaded yet.' : 'Upload only if it supports your application.' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex md:justify-center" data-document-status>
                        <span :class="['student-badge w-fit min-w-28 justify-center text-center md:w-full', statusClass(document.status)]">
                            {{ document.status_label }}
                        </span>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2 md:justify-end" data-document-actions>
                        <button
                            v-if="document.file?.view_url"
                            type="button"
                            class="student-button student-button-secondary student-button-compact"
                            @click="emit('preview', document)"
                        >
                            <i class="fa-regular fa-eye text-xs" aria-hidden="true"></i>
                            View
                        </button>
                        <button
                            v-if="canEdit && document.can_replace"
                            type="button"
                            :disabled="Boolean(uploadingRequirement) || !uploadEnabled"
                            :aria-describedby="$slots.terms ? 'application-upload-consent' : undefined"
                            class="student-button student-button-primary student-button-compact"
                            @click="emit('upload', document.requirement)"
                        >
                            <i class="fa-solid fa-arrow-up-from-bracket text-xs" aria-hidden="true"></i>
                            {{ uploadingRequirement === document.requirement ? 'Uploading...' : (document.file ? 'Replace' : 'Upload') }}
                        </button>
                    </div>
                </div>

                <div v-if="document.feedback" class="mt-3 border-l-2 border-amber-400 bg-amber-50 px-3 py-2 text-sm leading-5 text-amber-950">
                    <span class="font-bold">Provider feedback:</span> {{ document.feedback }}
                </div>
            </article>
        </div>

        <div v-else class="px-4 py-8 text-center sm:px-5">
            <span class="mx-auto grid h-10 w-10 place-items-center rounded-md bg-slate-100 text-slate-500">
                <i class="fa-solid fa-check" aria-hidden="true"></i>
            </span>
            <h3 class="mt-3 text-sm font-bold text-slate-950">No application files required</h3>
            <p class="mt-1 text-sm text-slate-600">The provider is reviewing the submitted profile information.</p>
        </div>
    </section>
</template>
