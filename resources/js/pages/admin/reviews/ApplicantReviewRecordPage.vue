<script setup>
import { staffQueueReturn } from '../../../support/staffQueueState.js';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import ApplicantProfileRecord from '../../../components/profile/ApplicantProfileRecord.vue';
import ApplicantProfileDetails from '../../../components/profile/ApplicantProfileDetails.vue';
import ApplicantEvidenceReviewDialog from './ApplicantEvidenceReviewDialog.vue';
import ApplicantEvidenceHistory from './ApplicantEvidenceHistory.vue';
import { applicantProfileDetails } from '../../../support/applicantProfileDetails';
import { profileEducationSummary, profileVerificationLabel } from '../../../support/applicantProfileLabels';
import AdminLoadState from '../components/AdminLoadState.vue';
import { useAdminResource } from '../../../composables/useAdminResource';
import { reviewErrorMessage } from '../../../support/adminReviewPresentation';
import { evidenceRows, evidenceReviewDate } from '../../../support/applicantEvidenceReview.js';

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
const photoReason = ref('');
const photoError = ref('');
const selectedDocumentId = ref(null);
const evidenceSaving = ref(false);
const historyVersion = ref(0);
const recordStale = ref(false);
const reviewRows = computed(() => evidenceRows(applicant.value));
const selectedDocument = computed(() => applicant.value?.verification_documents?.find((document) => document.id === selectedDocumentId.value) ?? null);
const evidenceReady = computed(() => applicant.value?.evidence_review?.required_evidence_accepted === true);
const canReviewEvidence = computed(() => applicant.value?.can_review_evidence === true);
const busy = computed(() => saving.value || photoSaving.value || evidenceSaving.value);
const ready = computed(() => applicant.value && !loading.value && !loadError.value);
const verificationStatus = computed(() => applicant.value?.verification_oversight?.status || applicant.value?.applicant_verification_status || 'pending');
const missingEvidence = computed(() => (applicant.value?.profile_evidence_requirements ?? [])
    .filter((requirement) => requirement.required
        && !applicant.value?.verification_documents?.some((document) => document.document_type === requirement.value)));
const noteRequired = computed(() => decision.value === 'rejected'
    || (decision.value === 'pending' && verificationStatus.value === 'approved'));
const canSave = computed(() => ready.value && canReviewEvidence.value && !busy.value && !recordStale.value
    && ['approved', 'rejected', 'pending'].includes(decision.value)
    && (!noteRequired.value || notes.value.trim().length > 0)
    && (decision.value !== 'approved' || (evidenceReady.value && !missingEvidence.value.length)));
const applicantProfileRecord = computed(() => ({
    name: applicant.value?.name || applicant.value?.username || 'Applicant record',
    photoUrl: applicant.value?.profile_photo_url,
    educationSummary: profileEducationSummary(applicant.value),
}));
const applicantProfileSections = computed(() => applicantProfileDetails(applicant.value, { omitUnavailable: true }));
async function loadRecord({ preserveDraft = false } = {}) {
    if (busy.value) return;
    errorMessage.value = ''; photoError.value = '';
    const payload = await resource.load();
    if (!payload) return;
    applicant.value = payload;
    recordStale.value = false;
    if (preserveDraft) return;
    decision.value = ['approved', 'rejected'].includes(verificationStatus.value) ? verificationStatus.value : 'approved';
    notes.value = applicant.value.applicant_verification_notes ?? '';
}

async function saveDecision() {
    if (!canSave.value) return;
    if (!await confirmOutcome({
        record: applicant.value.name,
        outcome: profileVerificationLabel(decision.value),
        consequence: 'This saves the final profile verification result. Approval allows the applicant to proceed with eligible applications; it does not award a scholarship.',
        danger: decision.value === 'rejected',
    }, () => canSave.value)) return;
    saving.value = true; errorMessage.value = ''; successMessage.value = '';
    try {
        const response = await window.axios.patch(`/admin/users/${applicantId}/profile-verification`, {
            verification_status: decision.value,
            verification_notes: notes.value.trim() || null,
        }, { portalToast: false });
        applicant.value = response.data.applicant;
        successMessage.value = response.data.message;
        historyVersion.value++;
    } catch (error) {
        errorMessage.value = reviewErrorMessage(error, 'The decision could not be saved.');
        recordStale.value = error.response?.status === 409 || !!error.response?.data?.errors?.evidence_reviews || !!error.response?.data?.missing_evidence;
    } finally { saving.value = false; }
}

function openEvidence(document) {
    if (!ready.value || busy.value || !document) return;
    selectedDocumentId.value = document.id;
}

function handleDecisionChange() {
    if (notes.value === applicant.value?.applicant_verification_notes) notes.value = '';
}

function evidenceSaved(payload) {
    const user = payload.user;
    applicant.value = { ...applicant.value, ...user,
        evidence_review: payload.evidence_review,
        verification_documents: applicant.value.verification_documents.map((document) => document.id === payload.document.id ? { ...document, ...payload.document } : document),
        verification_oversight: { ...applicant.value.verification_oversight, status: user.applicant_verification_status,
            review_note: user.applicant_verification_notes, reviewer_name: null, verified_at: null },
    };
    selectedDocumentId.value = null;
    historyVersion.value++;
    successMessage.value = payload.message;
    errorMessage.value = '';
}

async function reloadEvidence() {
    if (busy.value) return;
    selectedDocumentId.value = null;
    await loadRecord({ preserveDraft: true });
    if (!loadError.value) successMessage.value = 'Record reloaded. Your draft final decision was kept; compare it with the current evidence.';
    historyVersion.value++;
}

async function reviewPhoto(action) {
    if (!ready.value || !canReviewEvidence.value || busy.value || !['approve', 'request_replacement'].includes(action)) return;
    if (action === 'approve' && !applicant.value.profile_photo_url) return;
    if (action === 'request_replacement' && !photoReason.value.trim()) return;
    if (!await confirmOutcome({
        record: `${applicant.value.name} / Applicant photo`,
        outcome: action === 'approve' ? 'Photo accepted' : 'Replacement requested',
        consequence: 'This updates the photo review only. The final profile verification is recorded separately.',
        danger: action === 'request_replacement',
    }, () => ready.value && canReviewEvidence.value && !busy.value
        && (action === 'approve' ? !!applicant.value.profile_photo_url : !!photoReason.value.trim()))) return;
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
const backToQueue = staffQueueReturn(window, 'adminReviews', '/admin/workspaces/reviews/applicants');
</script>

<template>
    <AdminWorkspacePage workspace="review_officer" class="admin-review-workspace">
        <a :href="backToQueue" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs"></i>Back to review queue</a>
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
            >
                <template #details><details><summary class="cursor-pointer px-5 py-3 text-sm font-bold text-slate-600">View complete applicant profile</summary><ApplicantProfileDetails :sections="applicantProfileSections" /></details></template>
            </ApplicantProfileRecord>

            <p v-if="applicant.evidence_review?.legacy_profile_approval" class="mt-4 border border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-600"><strong>Earlier verified profile.</strong> The existing approval is retained. A new verification decision requires individual reviews of the current required documents.</p>

            <section id="supporting-evidence" class="admin-panel mt-4 scroll-mt-5 overflow-hidden">
                <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Step 1 · Check the evidence</p><h2 class="mt-0.5 font-black text-slate-950">Supporting evidence</h2><p class="mt-0.5 text-sm text-slate-500">Check each saved claim against its file. OCR results are not verified values.</p></div></div><div class="shrink-0 text-right"><p class="text-sm font-black text-slate-950">{{ applicant.evidence_review?.accepted_count ?? 0 }} of {{ applicant.evidence_review?.required_count ?? 0 }} accepted</p><p class="mt-1 text-xs text-slate-500">Required documents</p></div></header>
                <div v-if="reviewRows.length" class="staff-table-viewport staff-table-viewport--compact"><table class="portal-data-table table-fixed min-w-[56rem]">
                    <colgroup><col><col class="w-64"><col class="w-44"></colgroup>
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><tr><th class="px-6 py-3">Evidence</th><th class="px-4 py-3">Document decision</th><th class="px-6 py-3 text-right">Action</th></tr></thead>
                    <tbody class="divide-y divide-slate-200"><tr v-for="row in reviewRows" :key="row.type">
                        <td class="px-6 py-4"><p class="text-sm font-bold text-slate-950">{{ row.label }} <span class="ml-1 text-[10px] font-normal text-slate-500">{{ row.retired ? 'Retired' : row.required ? 'Required' : 'Optional' }}</span></p><p class="review-text mt-1 text-xs text-slate-500" data-file-name>{{ row.document?.original_name || 'Waiting for the applicant to upload this record.' }}</p></td>
                        <td class="px-4 py-4"><span :class="['inline-block px-2 py-1 text-xs font-bold', row.state.class]">{{ row.state.label }}</span><p v-if="row.document?.reviewed_at" class="mt-1 text-xs text-slate-500">{{ row.document.reviewed_by || 'Reviewer' }} · {{ evidenceReviewDate(row.document.reviewed_at) }}</p></td>
                        <td class="px-6 py-4 text-right"><div v-if="row.document" class="flex justify-end gap-2"><a v-if="row.document.file_available !== false" :href="row.document.view_url" data-file-preview="true" :data-file-name="row.document.original_name" class="inline-grid h-10 w-10 shrink-0 place-items-center border border-slate-300 text-slate-600 hover:bg-slate-50" :aria-label="`Preview ${row.label}`" title="Preview evidence"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a><button type="button" :disabled="busy" class="h-10 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:border-slate-700 disabled:opacity-40" @click="openEvidence(row.document)">{{ row.retired || !canReviewEvidence || row.state.key === 'accepted' ? 'Details' : 'Review' }}</button></div><span v-else class="text-xs text-slate-400">Not available</span></td>
                    </tr></tbody>
                </table></div><p v-else class="px-6 py-10 text-center text-sm font-semibold text-slate-500">No supporting evidence has been uploaded.</p>
                <p class="border-t border-slate-200 bg-slate-50 px-6 py-3 text-xs text-slate-500">Accepting a document does not verify the profile. Finish the separate profile decision after reviewing every required record.</p>
            </section>

            <details class="admin-panel mt-4 p-6" :aria-busy="photoSaving">
                <summary class="cursor-pointer text-sm font-bold text-slate-700">Applicant photo review <span class="ml-2 font-normal text-slate-500">{{ documentLabel(applicant.profile_photo_review_status || 'Not checked') }}</span></summary>
                <div class="mt-5">
                <div class="flex items-start justify-between gap-6"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-image-portrait" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Applicant photo</h2><p class="mt-0.5 text-sm text-slate-500">Check that the image clearly identifies the applicant.</p></div></div><span class="bg-slate-100 px-2.5 py-1.5 text-xs font-bold uppercase text-slate-700">{{ documentLabel(applicant.profile_photo_review_status || 'Not checked') }}</span></div>
                <a v-if="applicant.profile_photo_url" :href="applicant.profile_photo_url" data-file-preview="true" :data-file-name="`${applicant.name || 'Applicant'} photo`" class="mt-4 inline-flex min-h-10 items-center gap-2 text-sm font-bold text-slate-700"><i class="fa-solid fa-eye" aria-hidden="true"></i>Preview photo</a>
                <p v-if="applicant.profile_photo_review_note" class="mt-2 text-sm text-slate-600">{{ applicant.profile_photo_review_note }}</p>
                <fieldset :disabled="busy || !canReviewEvidence" class="mt-4 flex items-end gap-3">
                    <label class="min-w-0 flex-1"><span class="mb-1.5 block text-sm font-bold text-slate-700">Replacement reason</span><input v-model="photoReason" type="text" maxlength="1000" class="h-11 w-full border border-slate-300 px-3" placeholder="Required only when requesting a replacement"></label>
                    <button type="button" :disabled="busy || !photoReason.trim()" class="h-11 border border-slate-300 bg-white px-4 text-sm font-bold disabled:opacity-40" @click="reviewPhoto('request_replacement')">Request replacement</button>
                    <button type="button" :disabled="busy || !applicant.profile_photo_url" class="h-11 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40" @click="reviewPhoto('approve')">{{ photoSaving ? 'Saving...' : 'Approve photo' }}</button>
                </fieldset>
                <p v-if="photoError" role="alert" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ photoError }}</p>
                </div>
            </details>

            <form id="final-profile-verification" class="admin-panel mt-4 p-6" :aria-busy="saving" @submit.prevent="saveDecision">
                <div class="flex items-start justify-between gap-8"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Step 2 · Final profile decision</p><h2 class="mt-0.5 text-lg font-black text-slate-950">Record profile verification</h2><p class="mt-0.5 text-sm text-slate-500">Record the overall profile outcome after the individual evidence reviews.</p></div></div><button type="submit" :disabled="!canSave || !canReviewEvidence" class="bg-slate-950 px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{{ saving ? 'Saving...' : 'Save decision' }}</button></div>
                <p v-if="!evidenceReady" class="mt-4 border-l-2 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-950">Verification is not ready. {{ missingEvidence.length ? missingEvidence.length + ' required document(s) still need uploading. ' : '' }}Review and accept all current required documents above. Requesting a correction or reopening is still available.</p>
                <p v-else class="mt-4 flex items-center gap-2 text-sm font-bold text-emerald-800"><i class="fa-solid fa-check" aria-hidden="true"></i>All required evidence is accepted. Complete the final profile decision.</p>
                <fieldset :disabled="busy || !canReviewEvidence">
                    <div class="mt-5">
                        <label><span class="mb-1.5 block text-sm font-bold text-slate-700">Outcome</span><select v-model="decision" class="h-11 w-full border border-slate-300 bg-white px-3" @change="handleDecisionChange"><option value="approved">Verify profile</option><option value="rejected">Request profile correction</option><option value="pending">{{ verificationStatus === 'approved' ? 'Reopen review' : 'Keep under review' }}</option></select></label>
                    </div>
                    <label class="mt-4 block"><span class="mb-1.5 block text-sm font-bold text-slate-700">Review note <span v-if="noteRequired" class="text-rose-700">required</span><span v-else class="font-normal text-slate-500">(optional)</span></span><textarea v-model="notes" rows="3" maxlength="1500" :required="noteRequired" class="w-full border border-slate-300 px-3 py-2" placeholder="State what needs correction or why the review was reopened."></textarea></label>
                </fieldset>
                <div v-if="errorMessage" role="alert" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"><p>{{ errorMessage }}</p><button v-if="recordStale" type="button" class="mt-2 underline underline-offset-2" @click="reloadEvidence">Reload current evidence</button></div>
            </form>
            <ApplicantEvidenceHistory :applicant="applicant" :version="historyVersion" />
            <ApplicantEvidenceReviewDialog :document="selectedDocument" :applicant="applicant" :allowed="canReviewEvidence" @close="selectedDocumentId = null" @saved="evidenceSaved" @reload="reloadEvidence" @busy="evidenceSaving = $event" />
        </template>
    </AdminWorkspacePage>
</template>
