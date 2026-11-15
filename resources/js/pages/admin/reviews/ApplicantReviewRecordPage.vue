<script setup>
import AdminReviewGuide from '../../../components/admin/AdminReviewGuide.vue';
import AdminReviewNavigation from '../../../components/admin/AdminReviewNavigation.vue';
import { useAdminReviewNavigation } from '../../../composables/useAdminReviewNavigation.js';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import ApplicantProfileRecord from '../../../components/profile/ApplicantProfileRecord.vue';
import ApplicantProfileDetails from '../../../components/profile/ApplicantProfileDetails.vue';
import ApplicantEvidenceReviewDialog from './ApplicantEvidenceReviewDialog.vue';
import ApplicantEvidenceHistory from './ApplicantEvidenceHistory.vue';
import ApplicantVerificationProfileModal from './ApplicantVerificationProfileModal.vue';
import { applicantProfileDetails } from '../../../support/applicantProfileDetails';
import { profileEducationSummary, profileVerificationLabel } from '../../../support/applicantProfileLabels';
import AdminLoadState from '../components/AdminLoadState.vue';
import { useAdminResource } from '../../../composables/useAdminResource';
import { reviewErrorMessage } from '../../../support/adminReviewPresentation';
import { evidenceRows, evidenceReviewDate } from '../../../support/applicantEvidenceReview.js';

const applicantId = (window.portalPathname?.() ?? window.location.pathname).match(/applicants\/(\d+)/)?.[1];
const navigation = useAdminReviewNavigation('applicants', applicantId);
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
const evidenceFilter = ref('attention');
const showProfile = ref(false);
const profileSection = ref('personal');
const reviewRows = computed(() => evidenceRows(applicant.value));
const outstandingRows = computed(() => reviewRows.value.filter((row) => !row.retired && row.state.key !== 'accepted'));
const visibleRows = computed(() => evidenceFilter.value === 'all' ? reviewRows.value : outstandingRows.value);
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
    && Boolean(applicant.value.review_workflow?.token)
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
    navigation.load();
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
            expected_review_token: applicant.value.review_workflow.token,
        }, { portalToast: false });
        applicant.value = response.data.applicant;
        successMessage.value = response.data.message;
        historyVersion.value++;
        navigation.load();
    } catch (error) {
        errorMessage.value = reviewErrorMessage(error, 'The decision could not be saved.');
        recordStale.value = error.response?.status === 409 || !!error.response?.data?.errors?.evidence_reviews || !!error.response?.data?.missing_evidence;
    } finally { saving.value = false; }
}

function openEvidence(document) {
    if (!ready.value || busy.value || showProfile.value || !document) return;
    selectedDocumentId.value = document.id;
}

function openProfile(section = 'personal') {
    if (!ready.value || busy.value || selectedDocument.value) return;
    profileSection.value = section;
    showProfile.value = true;
}

function handleDecisionChange() {
    if (notes.value === applicant.value?.applicant_verification_notes) notes.value = '';
}

function evidenceSaved(payload) {
    const user = payload.user;
    applicant.value = { ...applicant.value, ...user,
        review_workflow: payload.review_workflow,
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

function continueReview() {
    if (busy.value) return;
    const row = applicant.value?.review_workflow?.review_count
        ? outstandingRows.value.find((item) => item.required && item.document && ['pending', 'unavailable'].includes(item.state.key)) : null;
    if (row) openEvidence(row.document);
    else document.getElementById('final-profile-verification')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function reviewPhoto(action) {
    if (!ready.value || !canReviewEvidence.value || busy.value || recordStale.value || !['approve', 'request_replacement'].includes(action)) return;
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
        const response = await window.axios.patch(`/admin/applicants/${applicantId}/profile-photo-review`, { action, reason: action === 'request_replacement' ? photoReason.value.trim() : null, expected_review_token: applicant.value.review_workflow?.token }, { portalToast: false });
        applicant.value = { ...applicant.value, ...response.data.applicant,
            profile_photo_review_status: response.data.photo_review_status,
            profile_photo_review_note: response.data.reason };
        successMessage.value = response.data.message;
    } catch (error) {
        photoError.value = reviewErrorMessage(error, 'The photo review could not be saved.');
        if (error.response?.status === 409) recordStale.value = true;
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
        <AdminReviewNavigation :back-url="navigation.backUrl" :next="navigation.next.value" :loading="navigation.loading.value" :error="navigation.error.value" @retry="navigation.load" />
        <AdminWorkspaceHeader eyebrow="Applicant verification" title="Verify an applicant profile" description="Compare the supporting evidence, then record the overall profile outcome." icon="fa-solid fa-user-check" />
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
                <template #actions><button type="button" :disabled="busy" class="inline-flex h-10 items-center gap-2 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-40" aria-label="View complete applicant profile" aria-haspopup="dialog" @click="openProfile()"><i class="fa-regular fa-address-card text-xs" aria-hidden="true"></i>View profile</button><button type="button" :disabled="busy" class="inline-flex h-10 items-center gap-2 rounded-sm bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40" aria-haspopup="dialog" @click="openProfile('photo')"><i class="fa-solid fa-image-portrait text-xs text-amber-300" aria-hidden="true"></i>Review photo</button></template>
                <template #details><dl class="grid grid-cols-4 divide-x divide-slate-200" data-applicant-verification-summary><div class="min-w-0 px-5 py-3.5"><dt class="text-xs text-slate-500">School / institution</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-950">{{ applicant.school || 'Not provided' }}</dd></div><div class="min-w-0 px-5 py-3.5"><dt class="text-xs text-slate-500">Contact email</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-950">{{ applicant.email || 'Not provided' }}</dd></div><div class="px-5 py-3.5"><dt class="text-xs text-slate-500">Required evidence</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ applicant.evidence_review?.accepted_count ?? 0 }} of {{ applicant.evidence_review?.required_count ?? 0 }} accepted</dd></div><div class="px-5 py-3.5"><dt class="text-xs text-slate-500">Photo review</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ documentLabel(applicant.profile_photo_review_status || 'Not checked') }}</dd></div></dl></template>
            </ApplicantProfileRecord>

            <AdminReviewGuide :workflow="applicant.review_workflow" kind="applicant" @continue="continueReview" />

            <p v-if="applicant.evidence_review?.legacy_profile_approval" class="mt-4 border border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-600"><strong>Earlier verified profile.</strong> The existing approval is retained. A new verification decision requires individual reviews of the current required documents.</p>

            <div class="mt-4 space-y-4" data-applicant-verification-layout>
            <section id="supporting-evidence" class="admin-panel scroll-mt-5 overflow-hidden rounded-sm">
                <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Step 1 · Check the evidence</p><h2 class="mt-0.5 font-black text-slate-950">Supporting evidence</h2><p class="mt-0.5 text-sm text-slate-500">Check each saved claim against its file. OCR results are not verified values.</p></div></div><div class="shrink-0 text-right"><p class="text-sm font-black text-slate-950">{{ applicant.evidence_review?.accepted_count ?? 0 }} of {{ applicant.evidence_review?.required_count ?? 0 }} accepted</p><p class="mt-1 text-xs text-slate-500">Required documents</p></div></header>
                <div class="flex items-center gap-2 border-b border-slate-200 px-6 py-3" aria-label="Evidence filter"><button type="button" :aria-pressed="evidenceFilter === 'attention'" :class="['rounded-sm border px-3 py-2 text-xs font-bold', evidenceFilter === 'attention' ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 bg-white text-slate-600']" @click="evidenceFilter = 'attention'">Needs attention ({{ outstandingRows.length }})</button><button type="button" :aria-pressed="evidenceFilter === 'all'" :class="['rounded-sm border px-3 py-2 text-xs font-bold', evidenceFilter === 'all' ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 bg-white text-slate-600']" @click="evidenceFilter = 'all'">All evidence ({{ reviewRows.length }})</button></div>
                <div v-if="visibleRows.length" class="staff-table-viewport staff-table-viewport--compact"><table class="portal-data-table w-full table-fixed">
                    <colgroup><col><col class="w-44"><col class="w-24"></colgroup>
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><tr><th scope="col" class="px-5 py-3">Supporting record</th><th scope="col" class="px-4 py-3">Review status</th><th scope="col" class="px-5 py-3 text-right">Review</th></tr></thead>
                    <tbody class="divide-y divide-slate-200"><tr v-for="row in visibleRows" :key="row.type" class="align-middle">
                        <td class="px-5 py-4" :title="row.document?.original_name"><p class="review-text text-sm font-bold text-slate-950">{{ row.label }}</p><p class="mt-1 text-xs text-slate-500">{{ row.retired ? 'Retired' : row.required ? 'Required' : 'Optional' }}</p></td>
                        <td class="px-4 py-4"><span :class="['inline-block px-2 py-1 text-xs font-bold', row.state.class]">{{ row.state.label }}</span><p v-if="applicant.review_workflow?.changed_keys?.includes('evidence:' + row.type)" class="mt-1 text-xs font-bold text-amber-800">Updated since last decision</p><p v-else-if="row.document?.reviewed_at" class="mt-1 text-xs text-slate-500">{{ row.document.reviewed_by || 'Reviewer' }} · {{ evidenceReviewDate(row.document.reviewed_at) }}</p></td>
                        <td class="px-5 py-4 text-right"><button v-if="row.document" type="button" :disabled="busy" class="staff-row-action h-10 w-10 rounded-sm" :aria-label="`${row.retired || !canReviewEvidence || row.state.key === 'accepted' ? 'View' : 'Review'} ${row.label}`" :title="row.retired || !canReviewEvidence || row.state.key === 'accepted' ? 'View document review' : 'Review document'" @click="openEvidence(row.document)"><i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></button><span v-else class="text-xs text-slate-400">No file</span></td>
                    </tr></tbody>
                </table></div><p v-else class="px-6 py-8 text-center text-sm font-semibold text-slate-500">{{ evidenceFilter === 'attention' && reviewRows.length ? 'No outstanding evidence in this view. Current acceptances are kept in All evidence.' : 'No supporting evidence has been uploaded.' }}</p>
                <p class="border-t border-slate-200 bg-slate-50 px-6 py-3 text-xs text-slate-500">Accepting a document does not verify the profile. Finish the separate profile decision after reviewing every required record.</p>
            </section>

            <form v-if="canReviewEvidence" id="final-profile-verification" class="admin-panel scroll-mt-5 overflow-hidden rounded-sm" :aria-busy="saving" @submit.prevent="saveDecision">
                <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Step 2 · Final profile decision</p><h2 class="mt-1 text-lg font-bold text-slate-950">Record profile verification</h2></div></header>
                <div class="space-y-5 px-6 py-5">
                    <p v-if="!evidenceReady" class="rounded-sm border-l-2 border-amber-400 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">Verification is not ready. {{ missingEvidence.length ? missingEvidence.length + ' required document(s) still need uploading. ' : '' }}Review and accept all current required documents. You can still request corrections or keep the review open.</p>
                    <p v-else class="flex items-start gap-2 text-sm leading-6 text-emerald-800"><i class="fa-solid fa-check mt-1.5 shrink-0" aria-hidden="true"></i>All required evidence is accepted. Complete the final profile decision.</p>
                    <fieldset :disabled="busy" class="space-y-5">
                        <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700">Outcome</span><select v-model="decision" class="h-11 w-full rounded-sm border border-slate-300 bg-white px-3 text-sm" @change="handleDecisionChange"><option value="approved" :disabled="!evidenceReady || missingEvidence.length > 0">Verify profile</option><option value="rejected">Request profile correction</option><option value="pending">{{ verificationStatus === 'approved' ? 'Reopen review' : 'Keep under review' }}</option></select></label>
                        <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700">Review note <span :class="['text-xs font-normal', noteRequired ? 'text-amber-800' : 'text-slate-500']">{{ noteRequired ? '(required)' : '(optional)' }}</span></span><textarea v-model="notes" rows="5" maxlength="1500" :required="noteRequired" class="w-full rounded-sm border border-slate-300 px-3 py-2 text-sm leading-6" placeholder="State what needs correction or why the review was reopened."></textarea></label>
                    </fieldset>
                    <div v-if="errorMessage" role="alert" class="border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"><p>{{ errorMessage }}</p><button v-if="recordStale" type="button" class="mt-2 underline underline-offset-2" @click="reloadEvidence">Reload current evidence</button></div>
                </div>
                <footer class="flex items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-4"><p class="text-xs leading-5 text-slate-500">Profile verification is not a scholarship award.</p><button type="submit" :disabled="!canSave" class="inline-flex h-11 shrink-0 items-center gap-2 rounded-sm bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-50"><i class="fa-solid fa-clipboard-check text-xs text-amber-300" aria-hidden="true"></i>{{ saving ? 'Saving...' : 'Save decision' }}</button></footer>
            </form>
            <section v-else id="final-profile-verification" class="admin-panel scroll-mt-5 rounded-sm p-6"><span class="grid h-10 w-10 place-items-center rounded-sm bg-slate-100 text-slate-500"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><h2 class="mt-4 text-lg font-bold text-slate-950">Verification oversight</h2><p class="mt-2 text-sm leading-6 text-slate-600">This record is read-only. The Review Officer reviews evidence and records the final profile verification. You can inspect the profile, files, and review history.</p></section>
            </div>
            <ApplicantEvidenceHistory :applicant="applicant" :version="historyVersion" />
            <ApplicantVerificationProfileModal :open="showProfile" :applicant="applicant" :sections="applicantProfileSections" :initial-section="profileSection" :busy="photoSaving" @close="showProfile = false">
                <template #default="{ section }"><ApplicantProfileDetails :sections="[section]" /></template>
                <template #photo>
                    <div class="flex items-start justify-between gap-5"><div><h3 class="text-lg font-bold text-slate-950">Applicant photo review</h3><p class="mt-1 text-sm text-slate-500">Check that the image clearly identifies the applicant.</p></div><span class="rounded-sm bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700">{{ documentLabel(applicant.profile_photo_review_status || 'Not checked') }}</span></div>
                    <div class="mt-6 grid grid-cols-[14rem_minmax(0,1fr)] items-start gap-7">
                        <div><div class="relative grid aspect-square place-items-center overflow-hidden rounded-sm border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user text-5xl" aria-hidden="true"></i><img v-if="applicant.profile_photo_url" :src="applicant.profile_photo_url" :alt="`${applicant.name} applicant photo`" class="absolute inset-0 h-full w-full object-contain" @error="$event.currentTarget.remove()"></div><a v-if="applicant.profile_photo_url" :href="applicant.profile_photo_url" data-file-preview="true" :data-file-name="`${applicant.name || 'Applicant'} photo`" class="mt-3 inline-flex min-h-10 items-center gap-2 text-sm font-bold text-slate-700"><i class="fa-solid fa-expand text-xs" aria-hidden="true"></i>Preview photo</a></div>
                        <div class="space-y-5"><p v-if="applicant.profile_photo_review_note" class="whitespace-pre-line text-sm leading-6 text-slate-600">{{ applicant.profile_photo_review_note }}</p><fieldset :disabled="busy || !canReviewEvidence" class="space-y-5"><label class="block"><span class="mb-2 block text-sm font-bold text-slate-700">Replacement reason</span><textarea v-model="photoReason" rows="3" maxlength="1000" class="w-full rounded-sm border border-slate-300 px-3 py-2 text-sm leading-6" placeholder="Required only when requesting a replacement"></textarea></label><div class="flex items-center gap-3"><button type="button" :disabled="busy || !photoReason.trim()" class="h-11 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-40" @click="reviewPhoto('request_replacement')">Request replacement</button><button type="button" :disabled="busy || !applicant.profile_photo_url" class="h-11 rounded-sm bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40" @click="reviewPhoto('approve')">{{ photoSaving ? 'Saving...' : 'Approve photo' }}</button></div></fieldset><p v-if="!canReviewEvidence" class="text-sm text-slate-500">This is a read-only photo record.</p><div v-if="photoError" role="alert" class="border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"><p>{{ photoError }}</p><button v-if="recordStale" type="button" class="mt-2 underline" @click="reloadEvidence">Reload current record</button></div></div>
                    </div>
                </template>
            </ApplicantVerificationProfileModal>
            <ApplicantEvidenceReviewDialog :document="selectedDocument" :applicant="applicant" :allowed="canReviewEvidence" @close="selectedDocumentId = null" @saved="evidenceSaved" @reload="reloadEvidence" @busy="evidenceSaving = $event" />
        </template>
    </AdminWorkspacePage>
</template>
