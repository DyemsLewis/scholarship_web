<script setup>
import ApplicantProfileEvidenceList from './ApplicantProfileEvidenceList.vue';
import TermsAgreement from '../TermsAgreement.vue';
import { disclosureSummary, inputClass, labelClass, optionalLabel, secondaryButton, sectionTitle } from '../../support/profileEditorStyles';

const form = defineModel({ type: Object, required: true });
const consent = defineModel('consent', { type: Boolean, default: false });
defineProps({
    groups: { type: Array, default: () => [] },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    reviewerNote: { type: String, default: '' },
    ocr: { type: Object, default: () => ({}) },
    uploadingType: { type: String, default: '' },
    deletingId: { type: [Number, String], default: null },
    scanningId: { type: [Number, String], default: null },
    disabled: { type: Boolean, default: false },
    scanLabel: { type: Function, required: true },
    resultLabel: { type: Function, required: true },
    manualResultAllowed: { type: Boolean, default: false },
    gradingScaleOptions: { type: Array, required: true },
    resultInputLabel: { type: String, default: 'Academic result' },
    legacyDocuments: { type: Array, default: () => [] },
});
defineEmits(['upload', 'preview', 'remove', 'scan']);
</script>

<template>
    <div id="profile-verification" data-evidence-editor class="px-4 pb-4 pt-4 sm:px-6">
        <p v-if="reviewerNote" data-evidence-reviewer-note class="mb-4 border-l-2 border-amber-400 pl-3 text-xs leading-5 text-slate-700"><strong>Reviewer note:</strong> {{ reviewerNote }}</p>
        <ApplicantProfileEvidenceList
            :groups="groups" :title="title" :description="description" :ocr="ocr"
            :uploading-type="uploadingType" :deleting-id="deletingId" :scanning-id="scanningId"
            :disabled="disabled" :scan-label="scanLabel" :result-label="resultLabel"
            @upload="(type, event) => $emit('upload', type, event)"
            @preview="$emit('preview', $event)" @remove="$emit('remove', $event)" @scan="$emit('scan', $event)"
        >
            <template #terms>
                <div data-profile-evidence-terms><TermsAgreement v-model="consent" context="document" :modal-layer="2700" /></div>
            </template>
            <template v-if="manualResultAllowed" #academic-result>
                <section data-manual-academic-result class="mt-3 border-l-2 border-amber-400 pl-3" aria-labelledby="evidence-manual-result-title">
                    <h4 id="evidence-manual-result-title" :class="sectionTitle">Enter the result shown on your record</h4>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Use the exact result. It stays pending until a reviewer confirms it.</p>
                    <div class="mt-3 grid items-start gap-4 sm:grid-cols-2">
                        <div class="min-w-0"><label :class="labelClass" for="fallback-grading-scale">Grading scale</label><select id="fallback-grading-scale" v-model="form.grading_scale" :class="inputClass"><option value="">Select grading scale</option><option v-for="option in gradingScaleOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
                        <div v-if="['percentage', 'grade_point'].includes(form.grading_scale)" class="min-w-0"><label :class="labelClass" for="fallback-academic-result">{{ resultInputLabel }}</label><input id="fallback-academic-result" v-model="form.gwa" type="number" min="0" :max="form.grading_scale === 'grade_point' ? 5 : 100" step="0.01" :placeholder="form.grading_scale === 'grade_point' ? 'Example: 1.75' : 'Example: 92.50'" :class="inputClass"></div>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Select Save changes below to submit this result for review.</p>
                </section>
            </template>
        </ApplicantProfileEvidenceList>

        <details v-if="legacyDocuments.length" data-older-evidence class="group border-t border-slate-200 py-4">
            <summary :class="disclosureSummary"><span>Older proof files <span :class="optionalLabel">({{ legacyDocuments.length }})</span></span><i class="fa-solid fa-chevron-down text-[10px] text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i></summary>
            <p class="mt-2 text-xs leading-5 text-slate-500">Kept for reference, not counted toward required evidence.</p>
            <div class="mt-2 divide-y divide-slate-200">
                <div v-for="document in legacyDocuments" :key="document.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <p class="min-w-0 flex-1 break-words text-xs leading-5 text-slate-700 [overflow-wrap:anywhere]">{{ document.original_name }}</p>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" :disabled="disabled" :class="secondaryButton" :aria-label="`View ${document.original_name}`" @click="$emit('preview', document)">View</button>
                        <button type="button" :disabled="disabled || Boolean(uploadingType) || deletingId !== null || scanningId !== null" class="grid h-9 w-9 place-items-center rounded-sm text-slate-500 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-50" :aria-label="`Remove ${document.original_name}`" @click="$emit('remove', document)"><i :class="deletingId === document.id ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-trash-can'" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
        </details>
        <p class="border-t border-slate-200 pt-3 text-xs leading-5 text-slate-500">Only authorized reviewers can access these files.</p>
    </div>
</template>
