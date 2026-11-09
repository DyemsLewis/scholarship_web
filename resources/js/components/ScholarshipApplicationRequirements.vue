<script setup>
import { computed } from 'vue';
import { eligibilityValue } from '../support/scholarshipEligibility';
import { withPreparationContext } from '../support/scholarshipPreparationContext';

const props = defineProps({
    requirements: { type: Object, default: () => ({}) },
    preparation: { type: Object, default: () => ({}) },
    scholarshipId: { type: [String, Number], default: '' },
    preview: { type: Boolean, default: false },
});
const readiness = computed(() => props.requirements.readiness ?? {});
const rows = computed(() => readiness.value.items ?? []);
const questions = computed(() => props.requirements.questions ?? []);
const isPreview = computed(() => props.preview || props.preparation.mode === 'preview');
const hasApplied = computed(() => !isPreview.value && props.preparation.has_applied === true);
const showFileReadiness = computed(() => !isPreview.value && !hasApplied.value);
const documentsHref = computed(() => showFileReadiness.value && rows.value.length && props.requirements.manage_files_href
    ? withPreparationContext(props.requirements.manage_files_href, props.scholarshipId) : null);
</script>

<template>
    <section class="student-card overflow-hidden rounded-md border-slate-300" data-scholarship-application-files aria-labelledby="scholarship-requirements-title">
        <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-5 sm:px-6">
            <div class="min-w-0 flex-1">
                <p class="student-kicker">{{ hasApplied ? 'Program reference' : 'Before you submit' }}</p>
                <h2 id="scholarship-requirements-title" class="mt-1.5 font-display text-2xl font-bold text-slate-950">Application checklist</h2>
            </div>
            <a v-if="documentsHref" :href="documentsHref" class="student-button student-button-secondary w-fit shrink-0"><i class="fa-solid fa-folder-open text-xs" aria-hidden="true"></i>My Documents</a>
        </header>

        <div class="border-b border-slate-200 bg-slate-50/70 px-5 py-3 sm:px-6">
            <p class="text-xs font-bold text-slate-800">{{ requirements.review_method_label }}</p>
            <p class="mt-1 text-xs leading-5 text-slate-600">{{ requirements.review_method_note }}</p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4 sm:px-6">
            <h4 class="text-sm font-bold text-slate-950">Required files <span class="ml-1 text-xs font-normal text-slate-500">({{ rows.length }})</span></h4>
            <p v-if="rows.length && showFileReadiness" class="text-xs font-semibold tabular-nums text-slate-600">{{ readiness.ready_count ?? 0 }} of {{ readiness.required_count ?? rows.length }} saved</p>
            <span v-else-if="rows.length && isPreview" class="text-xs text-slate-500">Read-only preview</span>
        </div>
        <ul v-if="rows.length" class="divide-y divide-slate-200" aria-label="Files required before submission">
            <li v-for="row in rows" :key="row.name" data-required-file class="grid min-w-0 grid-cols-[1.25rem_minmax(0,1fr)] items-center gap-x-2 gap-y-1.5 px-5 py-3 sm:grid-cols-[1.25rem_minmax(0,1fr)_7rem] sm:px-6">
                <i class="fa-regular fa-file-lines pt-1 text-sm text-slate-400" aria-hidden="true"></i>
                <p class="text-sm font-semibold leading-6 text-slate-900 [overflow-wrap:anywhere]">{{ eligibilityValue(row.name) }}</p>
                <div v-if="!hasApplied" data-file-status class="col-start-2 sm:col-start-auto"><span :class="['student-badge', isPreview ? '' : row.status === 'ready' ? 'student-badge-positive' : 'student-badge-attention']">{{ isPreview ? 'Required file' : row.status === 'ready' ? 'Saved file' : 'Upload needed' }}</span></div>
            </li>
        </ul>
        <p v-else class="px-5 py-5 text-sm leading-6 text-slate-600 sm:px-6">{{ requirements.review_method === 'provider_review' ? 'No initial files are needed. The provider begins with your verified profile and any required answers.' : 'No files are required for the initial application. Complete any required questions and review the terms in the form.' }}</p>

        <div v-if="rows.length || hasApplied" class="border-t border-slate-200 px-5 py-3 text-xs leading-5 text-slate-600 sm:px-6">
            <p>{{ hasApplied ? 'These are the published requirements, not your submitted attachments. Check your application record for the files you sent.' : isPreview ? 'Applicants see which files they already have saved.' : 'Saved files can be attached in the form. Upload any missing files there, including provider-specific documents.' }}</p>
            <details v-if="!hasApplied && requirements.upload_policy" data-file-guidance class="mt-1.5">
                <summary class="w-fit cursor-pointer py-1 font-semibold text-slate-700">Accepted file formats and size</summary>
                <p class="mt-1">PDF, JPG, JPEG, PNG, DOC or DOCX, up to {{ requirements.upload_policy.max_size_mb }} MB per file. Use clear, readable copies.</p>
            </details>
        </div>

        <details v-if="!hasApplied && questions.length" data-provider-questions class="group border-t border-slate-200">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-sm font-bold text-slate-950 sm:px-6 [&::-webkit-details-marker]:hidden"><span>Questions in the application form <span class="ml-1 text-xs font-normal text-slate-500">({{ questions.length }})</span></span><i class="fa-solid fa-chevron-down shrink-0 text-[10px] text-slate-500 group-open:rotate-180" aria-hidden="true"></i></summary>
            <div class="border-t border-slate-200 px-5 py-4 sm:px-6">
                <p class="text-xs leading-5 text-slate-500">Preview the questions here. Enter your answers only in the application form.</p>
                <ol class="mt-3 divide-y divide-slate-200">
                    <li v-for="(question, index) in questions" :key="question.id" class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"><span class="pt-0.5 text-xs font-semibold tabular-nums text-slate-400">{{ index + 1 }}.</span><p class="min-w-0 flex-1 text-sm leading-6 text-slate-800 [overflow-wrap:anywhere]">{{ question.prompt }}</p><span class="shrink-0 pt-0.5 text-xs font-semibold text-slate-500">{{ question.required ? 'Required' : 'Optional' }}</span></li>
                </ol>
            </div>
        </details>

        <details v-if="requirements.optional_documents?.length" data-optional-files class="group border-t border-slate-200">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-sm font-bold text-slate-950 sm:px-6 [&::-webkit-details-marker]:hidden"><span>Optional supporting files <span class="ml-1 text-xs font-normal text-slate-500">({{ requirements.optional_documents.length }})</span></span><i class="fa-solid fa-chevron-down text-[10px] text-slate-500 group-open:rotate-180" aria-hidden="true"></i></summary>
            <div class="border-t border-slate-200 px-5 py-4 sm:px-6"><p class="text-xs leading-5 text-slate-500">Include these in your initial application only if useful. They are not required to submit.</p><ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-700"><li v-for="name in requirements.optional_documents" :key="name">{{ eligibilityValue(name) }}</li></ul></div>
        </details>
        <details v-if="requirements.post_qualification_documents?.length" data-later-files class="group border-t border-slate-200">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 text-sm font-bold text-slate-950 sm:px-6 [&::-webkit-details-marker]:hidden"><span>Documents after qualification <span class="ml-1 text-xs font-normal text-slate-500">({{ requirements.post_qualification_documents.length }})</span></span><i class="fa-solid fa-chevron-down text-[10px] text-slate-500 group-open:rotate-180" aria-hidden="true"></i></summary>
            <div class="border-t border-slate-200 px-5 py-4 sm:px-6"><p class="text-xs leading-5 text-slate-500">Not needed for the initial submission. Follow the provider instructions if you advance.</p><ul class="mt-3 list-disc space-y-2 pl-5 text-sm leading-6 text-slate-700"><li v-for="name in requirements.post_qualification_documents" :key="name">{{ eligibilityValue(name) }}</li></ul></div>
        </details>

    </section>
</template>
