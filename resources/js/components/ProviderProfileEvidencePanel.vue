<script setup>
import { formatFileSize, labelFromKey } from '../support/display';

defineProps({
    proofs: { type: Array, default: () => [] },
    academicProof: { type: Object, default: null },
    schoolIdProof: { type: Object, default: null },
    scanRequired: { type: Boolean, default: false },
    scanReady: { type: Boolean, default: true },
    profileStatus: { type: String, default: 'pending' },
    gradingScale: { type: [String, Number], default: '' },
    academicResult: { type: [String, Number], default: '' },
    resultIsNumeric: { type: Boolean, default: false },
    canVerify: { type: Boolean, default: false },
    verifying: { type: Boolean, default: false },
});

const emit = defineEmits(['update:gradingScale', 'update:academicResult', 'verify', 'open']);
const academicScaleOptions = [
    { value: 'percentage', label: 'General average / percentage' },
    { value: 'grade_point', label: 'GWA / GPA grade point' },
    { value: 'pass_fail', label: 'Pass/fail or competency based' },
    { value: 'other', label: 'Other grading scale' },
];
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100';

function verificationClass(status) {
    if (['approved', 'accepted', 'verified'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'needs_replacement'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function scanStatusLabel(status) {
    return {
        succeeded: 'Result extracted',
        needs_review: 'Result not found',
        failed: 'Scan failed',
        unavailable: 'Scanner unavailable',
        not_requested: 'Not scanned',
    }[status] ?? 'Not scanned';
}

function scanStatusClass(status) {
    if (status === 'succeeded') {
        return 'bg-sky-100 text-sky-800';
    }

    if (['failed', 'needs_review'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function extractedAcademicResult(proof) {
    if (proof?.ocr_grading_scale === 'pass_fail') {
        return 'Pass / competency result';
    }

    if (proof?.ocr_grade !== null && proof?.ocr_grade !== undefined) {
        return proof.ocr_grading_scale === 'percentage'
            ? `${proof.ocr_grade}%`
            : `${proof.ocr_grade} GWA / GPA`;
    }

    return 'No result extracted';
}
</script>

<template>
    <section class="provider-panel overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Profile evidence</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">School and academic records</h3>
            </div>
            <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                {{ proofs.length }} record{{ proofs.length === 1 ? '' : 's' }}
            </span>
        </div>

        <p v-if="scanRequired && academicProof && !scanReady" class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900">
            No usable result was extracted. Check the record manually or request a clearer file.
        </p>
        <p v-if="!schoolIdProof" class="border-b border-amber-200 bg-amber-50 px-5 py-3 text-sm text-amber-900">
            A recent school ID is required before verification.
        </p>

        <div v-if="academicProof && profileStatus === 'pending'" class="border-b border-slate-200 bg-slate-50 p-4 sm:px-5">
            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                <label class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-700">Grading scale</span>
                    <select :value="gradingScale" :class="inputClass" @change="emit('update:gradingScale', $event.target.value)">
                        <option value="">Select grading scale</option>
                        <option v-for="option in academicScaleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label v-if="resultIsNumeric" class="block">
                    <span class="mb-1.5 block text-xs font-bold text-slate-700">Result on record</span>
                    <input
                        :value="academicResult"
                        type="number"
                        min="0.01"
                        :max="gradingScale === 'grade_point' ? 5 : 100"
                        step="0.01"
                        :placeholder="gradingScale === 'grade_point' ? 'Example: 1.75' : 'Example: 89.50'"
                        :class="inputClass"
                        @input="emit('update:academicResult', $event.target.value)"
                    >
                </label>
                <div v-else-if="gradingScale" class="rounded-md border border-slate-200 bg-white px-3 py-2.5 text-xs text-slate-600">Confirm the result from the uploaded record.</div>
                <button v-if="canVerify" type="button" :disabled="verifying" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50" @click="emit('verify')">
                    {{ verifying ? 'Verifying...' : 'Verify result' }}
                </button>
            </div>
        </div>

        <div v-if="proofs.length" class="divide-y divide-slate-200">
            <article v-for="proof in proofs" :key="proof.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-bold text-slate-950">{{ labelFromKey(proof.document_type) }}</p>
                        <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', verificationClass(proof.status)]">{{ labelFromKey(proof.status || 'submitted') }}</span>
                    </div>
                    <p class="mt-1 truncate text-xs text-slate-500">{{ proof.original_name }} - {{ formatFileSize(proof.size) }}</p>
                    <div v-if="scanRequired && proof.document_type === 'academic_record'" class="mt-2 flex flex-wrap items-center gap-2">
                        <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', scanStatusClass(proof.ocr_status)]">{{ scanStatusLabel(proof.ocr_status) }}</span>
                        <strong v-if="proof.ocr_status === 'succeeded'" class="text-xs text-slate-900">{{ extractedAcademicResult(proof) }}</strong>
                    </div>
                </div>
                <button type="button" class="shrink-0 rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="emit('open', proof)">View record</button>
            </article>
        </div>
        <p v-else class="p-5 text-sm text-slate-600">No profile evidence was submitted.</p>
    </section>
</template>
