<script setup>
import { computed } from 'vue';
import { formatFileSize } from '../../support/display';
import { evidenceFilePolicy, evidenceProgress, evidenceStatus } from '../../support/applicantProfileEvidence';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    ocr: { type: Object, default: () => ({}) },
    uploadingType: { type: String, default: '' },
    deletingId: { type: [Number, String], default: null },
    scanningId: { type: [Number, String], default: null },
    disabled: { type: Boolean, default: false },
    scanLabel: { type: Function, required: true },
    resultLabel: { type: Function, required: true },
});
defineEmits(['upload', 'preview', 'remove', 'scan']);

const progress = computed(() => evidenceProgress(props.groups.flatMap((group) => group.rows)));
const working = computed(() => props.disabled || Boolean(props.uploadingType) || props.deletingId !== null || props.scanningId !== null);
const canRetry = (row) => props.ocr.configured && row.value === 'academic_record' && row.document
    && ['failed', 'needs_review', 'unavailable', 'not_requested'].includes(row.document.ocr_status);
const policy = (row) => evidenceFilePolicy(row.value, props.ocr);
</script>

<template>
    <div data-profile-evidence-list>
        <div class="flex flex-col gap-3 border-b border-slate-200 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h3 class="text-sm font-bold text-slate-950">{{ title }}</h3>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ description }}</p>
            </div>
            <div class="shrink-0 text-xs leading-5 sm:text-right" aria-live="polite" data-evidence-progress>
                <p class="font-bold text-slate-800">{{ progress.saved }} of {{ progress.total }} required saved</p>
                <p class="text-slate-500">{{ progress.accepted }} accepted<span v-if="progress.needsReplacement" class="text-rose-700"> · {{ progress.needsReplacement }} need replacement</span></p>
            </div>
        </div>

        <div class="py-4"><slot name="terms" /></div>

        <section v-for="group in groups" :key="group.id" :aria-labelledby="`evidence-group-${group.id}`" class="mt-2 first:mt-0">
            <h4 :id="`evidence-group-${group.id}`" class="border-y border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">{{ group.label }}</h4>
            <div class="divide-y divide-slate-200">
                <article v-for="row in group.rows" :key="row.value" :data-evidence-type="row.value" class="py-4">
                    <div class="grid min-w-0 gap-x-5 gap-y-3 md:grid-cols-[minmax(0,1fr)_9rem_auto] md:items-center">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-sm text-slate-600"><i :class="row.icon" aria-hidden="true"></i></span>
                            <div class="min-w-0">
                                <h5 class="text-sm font-bold text-slate-950">{{ row.label }}</h5>
                                <p v-if="row.document" class="mt-1 break-words text-xs leading-5 text-slate-500 [overflow-wrap:anywhere]">{{ row.document.original_name }} <span class="whitespace-nowrap">· {{ formatFileSize(row.document.size) }}</span></p>
                                <p v-else class="mt-1 text-xs leading-5 text-slate-500">{{ row.required ? 'Required to verify your profile.' : 'Optional supporting record.' }}</p>
                            </div>
                        </div>
                        <div data-evidence-status class="pl-12 md:pl-0">
                            <span :class="['inline-flex rounded-sm border px-2 py-1 text-[11px] font-bold leading-4', evidenceStatus(row.document).className]">{{ evidenceStatus(row.document).label }}</span>
                        </div>
                        <div data-evidence-actions class="flex flex-wrap items-center gap-2 pl-12 md:justify-end md:pl-0">
                            <button v-if="row.document" type="button" class="inline-flex min-h-9 items-center gap-2 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600" :aria-label="`View ${row.label}`" @click="$emit('preview', row.document)"><i class="fa-solid fa-eye" aria-hidden="true"></i>View</button>
                            <label :class="['relative inline-flex min-h-9 items-center gap-2 overflow-hidden rounded-sm border px-3 text-xs font-bold focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-amber-600', working ? 'cursor-wait border-slate-200 bg-slate-100 text-slate-400' : row.document ? 'cursor-pointer border-slate-300 bg-white text-slate-700 hover:bg-slate-50' : 'cursor-pointer border-slate-950 bg-slate-950 text-white hover:bg-slate-800']">
                                <input type="file" class="absolute inset-0 h-full w-full cursor-pointer opacity-0" :aria-label="`${row.document ? 'Replace' : 'Upload'} ${row.label}`" :accept="policy(row).accept" :disabled="working" @change="$emit('upload', row.value, $event)">
                                <i :class="uploadingType === row.value ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-arrow-up-from-bracket'" aria-hidden="true"></i>
                                {{ uploadingType === row.value ? 'Uploading...' : row.document ? 'Replace' : 'Upload' }}
                            </label>
                            <button v-if="row.document" type="button" :disabled="working" class="grid h-9 w-9 place-items-center rounded-sm text-slate-500 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-40" :aria-label="`Remove ${row.label}`" @click="$emit('remove', row.document)"><i :class="deletingId === row.document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can'" aria-hidden="true"></i></button>
                        </div>
                    </div>
                    <p v-if="row.document?.review_notes" class="mt-3 border-l-2 border-amber-400 pl-3 text-xs leading-5 text-slate-700"><strong>Reviewer note:</strong> {{ row.document.review_notes }}</p>
                    <div v-if="ocr.configured && row.value === 'academic_record' && row.document" class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 pl-12 text-xs leading-5" data-evidence-scan>
                        <span class="font-semibold text-slate-600">{{ scanLabel(row.document.ocr_status) }}</span>
                        <strong v-if="row.document.ocr_status === 'succeeded'" class="text-slate-900">{{ resultLabel(row.document) }}</strong>
                        <button v-if="canRetry(row)" type="button" :disabled="working" class="min-h-8 font-bold text-amber-800 underline underline-offset-2 disabled:opacity-50" @click="$emit('scan', row.document)">{{ scanningId === row.document.id ? 'Scanning...' : 'Retry scan' }}</button>
                    </div>
                    <details class="group ml-12 mt-2 text-xs" data-evidence-help>
                        <summary class="w-fit cursor-pointer py-1 font-semibold text-slate-600 hover:text-slate-950 focus-visible:outline-2 focus-visible:outline-amber-600">What to upload</summary>
                        <div class="mt-2 space-y-2 border-l border-slate-200 pl-3 leading-5 text-slate-500">
                            <p>{{ row.description }}</p>
                            <p v-if="row.supports"><strong class="font-semibold text-slate-700">Supports:</strong> {{ row.supports }}</p>
                            <p v-if="row.context?.length"><strong class="font-semibold text-slate-700">Saved in your profile:</strong> {{ row.context.join(' / ') }}</p>
                            <p>{{ policy(row).label }}</p>
                            <p v-if="ocr.configured && row.value === 'academic_record' && row.document?.ocr_message">{{ row.document.ocr_message }}</p>
                        </div>
                    </details>
                </article>
            </div>
        </section>
    </div>
</template>
