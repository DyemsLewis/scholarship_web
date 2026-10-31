<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import ApplicantProfileRecord from '../../../components/profile/ApplicantProfileRecord.vue';
import { applicantProfileDetails } from '../../../support/applicantProfileDetails';
import { profileEducationSummary, profileVerificationLabel } from '../../../support/applicantProfileLabels';
import AdminLoadState from '../components/AdminLoadState.vue';
import { useAdminResource } from '../../../composables/useAdminResource';
import { reviewErrorMessage, reviewStatusLabel } from '../../../support/adminReviewPresentation';

const applicantId = (window.portalPathname?.() ?? window.location.pathname).match(/applicants\/(\d+)/)?.[1];
const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get(`/admin/applicants/${applicantId}/review/data`, { signal });
    if (!response.data?.applicant) throw new Error('Applicant record unavailable.');
    return response.data.applicant;
}, { failureMessage: 'The applicant record could not be loaded.' });
const { isLoading: loading, errorMessage: loadError } = resource;
const saving = ref(false);
const photoSaving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const applicant = ref(null);
const decision = ref('approved');
const notes = ref('');
const gradingScale = ref('percentage');
const academicResult = ref('');
const photoReason = ref('');
const photoError = ref('');
const busy = computed(() => saving.value || photoSaving.value);
const ready = computed(() => applicant.value && !loading.value && !loadError.value);
const verificationStatus = computed(() => applicant.value?.verification_oversight?.status || applicant.value?.applicant_verification_status || 'pending');
const missingEvidence = computed(() => (applicant.value?.profile_evidence_requirements ?? [])
    .filter((requirement) => requirement.required
        && !applicant.value?.verification_documents?.some((document) => document.document_type === requirement.value)));
const noteRequired = computed(() => decision.value === 'rejected'
    || (decision.value === 'pending' && verificationStatus.value === 'approved'));
const numericScale = computed(() => ['percentage', 'grade_point'].includes(gradingScale.value));
const resultMaximum = computed(() => gradingScale.value === 'grade_point' ? 5 : 100);
const validResult = computed(() => !numericScale.value || (String(academicResult.value).trim() !== ''
    && Number.isFinite(Number(academicResult.value)) && Number(academicResult.value) > 0
    && Number(academicResult.value) <= resultMaximum.value));
const canSave = computed(() => ready.value && !busy.value
    && (!noteRequired.value || notes.value.trim().length > 0)
    && (decision.value !== 'approved' || (!missingEvidence.value.length && validResult.value)));
const applicantProfileRecord = computed(() => ({
    name: applicant.value?.name || applicant.value?.username || 'Applicant record',
    photoUrl: applicant.value?.profile_photo_url,
    educationSummary: profileEducationSummary(applicant.value),
}));
const applicantProfileSections = computed(() => applicantProfileDetails(applicant.value, { omitUnavailable: true }));
async function loadRecord() {
    if (busy.value) return;
    errorMessage.value = ''; photoError.value = '';
    const payload = await resource.load();
    if (!payload) return;
    applicant.value = payload;
    decision.value = verificationStatus.value === 'pending' ? 'approved' : verificationStatus.value;
    notes.value = applicant.value.applicant_verification_notes ?? '';
    gradingScale.value = applicant.value.grading_scale || 'percentage';
    academicResult.value = applicant.value.gwa ?? '';
}

async function saveDecision() {
    if (!canSave.value) return;
    saving.value = true; errorMessage.value = ''; successMessage.value = '';
    try {
        const response = await window.axios.patch(`/admin/users/${applicantId}/profile-verification`, {
            verification_status: decision.value,
            verification_notes: notes.value.trim() || null,
            academic_grading_scale: gradingScale.value,
            academic_result: numericScale.value && academicResult.value !== '' ? Number(academicResult.value) : null,
        }, { portalToast: false });
        applicant.value = response.data.applicant;
        gradingScale.value = applicant.value.grading_scale || gradingScale.value;
        academicResult.value = applicant.value.gwa ?? '';
        successMessage.value = response.data.message;
    } catch (error) {
        errorMessage.value = reviewErrorMessage(error, 'The decision could not be saved.');
    } finally { saving.value = false; }
}

async function reviewPhoto(action) {
    if (!ready.value || busy.value || !['approve', 'request_replacement'].includes(action)) return;
    if (action === 'approve' && !applicant.value.profile_photo_url) return;
    if (action === 'request_replacement' && !photoReason.value.trim()) return;
    photoSaving.value = true; photoError.value = ''; successMessage.value = '';
    try {
        const response = await window.axios.patch(`/admin/applicants/${applicantId}/profile-photo-review`, { action, reason: action === 'request_replacement' ? photoReason.value.trim() : null }, { portalToast: false });
        applicant.value = { ...applicant.value, ...response.data.applicant,
            profile_photo_review_status: response.data.photo_review_status,
            profile_photo_review_note: response.data.reason };
        successMessage.value = response.data.message;
    } catch (error) {
        photoError.value = reviewErrorMessage(error, 'The photo review could not be saved.');
    } finally { photoSaving.value = false; }
}

function documentLabel(type) {
    const requirement = applicant.value?.profile_evidence_requirements?.find((item) => item.value === type);
    if (requirement) return requirement.label;

    if (type === 'no_current_scholarship_proof') {
        return 'Proof of no current scholarship';
    }

    return String(type).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}
onMounted(loadRecord);
</script>

<template>
    <AdminWorkspacePage workspace="review_officer" class="admin-review-workspace">
        <a href="/admin/workspaces/reviews/applicants" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs"></i>Applicant queue</a>
        <AdminWorkspaceHeader eyebrow="Applicant verification" title="Applicant profile record" description="Compare identity, school, household, and residence details with supporting evidence." icon="fa-solid fa-user-check" />
        <section v-if="loading || loadError" class="admin-panel mt-4"><AdminLoadState :loading="loading" :error="loadError" loading-message="Loading applicant record..." @retry="loadRecord" /></section>
        <template v-else-if="applicant">
            <p v-if="successMessage" role="status" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ successMessage }}</p>
            <ApplicantProfileRecord
                class="mt-4"
                :record="applicantProfileRecord"
                :sections="applicantProfileSections"
                :verification-status="verificationStatus"
                :verification-label="profileVerificationLabel(verificationStatus)"
                :show-trust-notice="false"
                show-data-source-notice
            />

            <p v-if="missingEvidence.length" class="mt-4 border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-950"><strong>Evidence still needed:</strong> {{ missingEvidence.map((item) => item.label).join(', ') }}. Approval is available once all required records are uploaded.</p>

            <section class="admin-panel mt-4 overflow-hidden">
                <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Supporting evidence</h2><p class="mt-0.5 text-sm text-slate-500">Check each saved claim against its file. OCR results are not verified values.</p></div></header>
                <div v-if="applicant.verification_documents?.length" class="staff-table-viewport"><table class="portal-data-table table-fixed min-w-[56rem]">
                    <colgroup><col><col class="w-44"><col class="w-36"><col class="w-28"></colgroup>
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><tr><th class="px-6 py-3">Evidence</th><th class="px-4 py-3">Review state</th><th class="px-4 py-3">OCR result</th><th class="px-6 py-3 text-right">Preview</th></tr></thead>
                    <tbody class="divide-y divide-slate-200"><tr v-for="document in applicant.verification_documents" :key="document.id">
                        <td class="px-6 py-4"><p class="text-sm font-bold text-slate-950">{{ documentLabel(document.document_type) }}</p><p class="review-text mt-0.5 text-xs text-slate-500" data-file-name>{{ document.original_name }}</p></td>
                        <td class="px-4 py-4 text-sm font-semibold text-slate-700">{{ reviewStatusLabel(document.status, 'applicants') }}</td><td class="px-4 py-4 text-sm text-slate-600">{{ document.ocr_grade ?? 'Not extracted' }}</td>
                        <td class="px-6 py-4 text-right"><a :href="document.view_url" data-file-preview="true" :data-file-name="document.original_name" class="inline-grid h-10 w-10 place-items-center border border-slate-300 text-slate-700 hover:bg-slate-950 hover:text-white" :aria-label="`Preview ${document.original_name}`" title="Preview evidence"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a></td>
                    </tr></tbody>
                </table></div><p v-else class="px-6 py-10 text-center text-sm font-semibold text-slate-500">No supporting evidence has been uploaded.</p>
            </section>

            <section class="admin-panel mt-4 p-6" :aria-busy="photoSaving">
                <div class="flex items-start justify-between gap-6"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-image-portrait" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Applicant photo</h2><p class="mt-0.5 text-sm text-slate-500">Check that the image clearly identifies the applicant.</p></div></div><span class="bg-slate-100 px-2.5 py-1.5 text-xs font-bold uppercase text-slate-700">{{ documentLabel(applicant.profile_photo_review_status || 'Not checked') }}</span></div>
                <a v-if="applicant.profile_photo_url" :href="applicant.profile_photo_url" data-file-preview="true" :data-file-name="`${applicant.name || 'Applicant'} photo`" class="mt-4 inline-flex min-h-10 items-center gap-2 text-sm font-bold text-slate-700"><i class="fa-solid fa-eye" aria-hidden="true"></i>Preview photo</a>
                <p v-if="applicant.profile_photo_review_note" class="mt-2 text-sm text-slate-600">{{ applicant.profile_photo_review_note }}</p>
                <fieldset :disabled="busy" class="mt-4 flex items-end gap-3">
                    <label class="min-w-0 flex-1"><span class="mb-1.5 block text-sm font-bold text-slate-700">Replacement reason</span><input v-model="photoReason" type="text" maxlength="1000" class="h-11 w-full border border-slate-300 px-3" placeholder="Required only when requesting a replacement"></label>
                    <button type="button" :disabled="busy || !photoReason.trim()" class="h-11 border border-slate-300 bg-white px-4 text-sm font-bold disabled:opacity-40" @click="reviewPhoto('request_replacement')">Request replacement</button>
                    <button type="button" :disabled="busy || !applicant.profile_photo_url" class="h-11 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40" @click="reviewPhoto('approve')">{{ photoSaving ? 'Saving...' : 'Approve photo' }}</button>
                </fieldset>
                <p v-if="photoError" role="alert" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ photoError }}</p>
            </section>

            <form class="admin-panel mt-4 p-6" :aria-busy="saving" @submit.prevent="saveDecision">
                <div class="flex items-start justify-between gap-8"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Decision</p><h2 class="mt-0.5 text-lg font-black text-slate-950">Record profile verification</h2><p class="mt-0.5 text-sm text-slate-500">Use the values shown in the academic record.</p></div></div><button type="submit" :disabled="!canSave" class="bg-slate-950 px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{{ saving ? 'Saving...' : 'Save decision' }}</button></div>
                <fieldset :disabled="busy">
                    <div class="mt-5 grid grid-cols-3 gap-4">
                        <label><span class="mb-1.5 block text-sm font-bold text-slate-700">Outcome</span><select v-model="decision" class="h-11 w-full border border-slate-300 bg-white px-3"><option value="approved">Verify profile</option><option value="rejected">Request replacement</option><option value="pending">Reopen review</option></select></label>
                        <label><span class="mb-1.5 block text-sm font-bold text-slate-700">Grading scale</span><select v-model="gradingScale" class="h-11 w-full border border-slate-300 bg-white px-3"><option value="percentage">Percentage</option><option value="grade_point">Grade point</option><option value="pass_fail">Pass / fail</option><option value="other">Other</option></select></label>
                        <label><span class="mb-1.5 block text-sm font-bold text-slate-700">Verified result</span><input v-model="academicResult" type="number" step="0.01" :min="0.01" :max="resultMaximum" :required="decision === 'approved' && numericScale" class="h-11 w-full border border-slate-300 px-3" :disabled="!numericScale"></label>
                    </div>
                    <p v-if="decision === 'approved' && !validResult" class="mt-2 text-sm text-amber-900">Enter a result greater than 0 and at most {{ resultMaximum }} to verify this profile.</p>
                    <label class="mt-4 block"><span class="mb-1.5 block text-sm font-bold text-slate-700">Review note <span v-if="noteRequired" class="text-rose-700">required</span><span v-else class="font-normal text-slate-500">(optional)</span></span><textarea v-model="notes" rows="3" maxlength="1500" :required="noteRequired" class="w-full border border-slate-300 px-3 py-2" placeholder="State what needs correction or why the review was reopened."></textarea></label>
                </fieldset>
                <p v-if="errorMessage" role="alert" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</p>
            </form>
        </template>
    </AdminWorkspacePage>
</template>
