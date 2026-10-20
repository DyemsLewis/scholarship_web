<script setup>
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
});

const emit = defineEmits(['preview', 'upload']);

function statusClass(status) {
    return {
        accepted: 'bg-emerald-100 text-emerald-800',
        pending: 'bg-sky-100 text-sky-800',
        rejected: 'bg-amber-100 text-amber-800',
        needs_replacement: 'bg-amber-100 text-amber-800',
        missing: 'bg-slate-100 text-slate-600',
    }[status] ?? 'bg-slate-100 text-slate-600';
}
</script>

<template>
    <section id="application-files" class="scroll-mt-4 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Supporting files</p>
                <h2 class="mt-1 text-lg font-bold text-slate-950">Application documents</h2>
                <p class="mt-1 text-sm text-slate-600">Review each requirement and any provider feedback.</p>
            </div>
            <div class="shrink-0 text-left sm:text-right">
                <p class="text-sm font-bold text-slate-950">
                    {{ summary.accepted ?? 0 }} of {{ summary.required ?? 0 }} accepted
                </p>
                <p class="mt-0.5 text-xs text-slate-500">{{ summary.uploaded ?? 0 }} uploaded</p>
            </div>
        </header>

        <div v-if="$slots.terms && canEdit" class="border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
            <slot name="terms"></slot>
        </div>

        <div v-if="documents.length" class="divide-y divide-slate-200">
            <article v-for="document in documents" :key="document.requirement" class="px-4 py-4 sm:px-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                            <i :class="document.file ? 'fa-solid fa-file-lines' : 'fa-regular fa-file'" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-sm font-bold text-slate-950">{{ document.requirement }}</h3>
                                <span v-if="!document.required" class="rounded bg-slate-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-500">Optional</span>
                                <span :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', statusClass(document.status)]">
                                    {{ document.status_label }}
                                </span>
                            </div>
                            <p v-if="document.file" class="mt-1 truncate text-xs text-slate-500">
                                {{ document.file.name }}<span v-if="document.file.uploaded_at"> · {{ document.file.uploaded_at }}</span>
                            </p>
                            <p v-else class="mt-1 text-xs text-slate-500">
                                {{ document.required ? 'No file uploaded yet.' : 'Upload only if it supports your application.' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            v-if="document.file?.view_url"
                            type="button"
                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                            @click="emit('preview', document)"
                        >
                            <i class="fa-regular fa-eye text-xs" aria-hidden="true"></i>
                            View
                        </button>
                        <button
                            v-if="canEdit && document.can_replace"
                            type="button"
                            :disabled="Boolean(uploadingRequirement)"
                            class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
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
