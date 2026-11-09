<script setup>
import WorkspaceTextSummary from '../../../components/workspace-dashboard/WorkspaceTextSummary.vue';
import { staffQueueReturn } from '../../../support/staffQueueState.js';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import LocationMapLink from '../../../components/LocationMapLink.vue';
import AdminLoadState from '../components/AdminLoadState.vue';
import ProviderDocumentReviewDialog from './ProviderDocumentReviewDialog.vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { labelFromKey } from '../../../support/display.js';
import { reviewErrorMessage, reviewStatusLabel } from '../../../support/adminReviewPresentation.js';

const providerId = (window.portalPathname?.() ?? window.location.pathname).match(/providers\/(\d+)/)?.[1];
const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get(`/admin/providers/${providerId}/review/data`, { signal });
    if (!response.data.provider) throw new Error('Missing provider.');
    return response.data.provider;
}, { failureMessage: 'The provider record could not be loaded.' });
const { isLoading: loading, errorMessage: loadError } = resource;
const saving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const provider = ref(null);
const decision = ref('approved');
const notes = ref('');
const reviewDocument = ref(null);
const documentSaving = ref(false);
const stale = ref(false);
const documentSummary = computed(() => provider.value?.document_review ?? { total: 0, accepted: 0, awaiting_review: 0, replacement_requested: 0 });

const statusClass = computed(() => provider.value?.verification_status === 'approved' ? 'bg-emerald-100 text-emerald-800' : provider.value?.verification_status === 'rejected' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700');
const canSave = computed(() => Boolean(provider.value) && !loading.value && !loadError.value && !saving.value && !reviewDocument.value && !stale.value
    && (decision.value !== 'approved' || (documentSummary.value.can_approve && documentSummary.value.token))
    && (decision.value !== 'rejected' || Boolean(notes.value.trim())));

async function loadRecord() {
    if (saving.value || documentSaving.value) return;
    errorMessage.value = '';
    const payload = await resource.load();
    if (payload) {
        provider.value = payload;
        stale.value = false;
        decision.value = provider.value.verification_status === 'pending' ? 'approved' : provider.value.verification_status;
        notes.value = provider.value.verification_notes ?? '';
    }
}

async function saveDecision() {
    if (!canSave.value) return;
    if (!await confirmOutcome({
        record: provider.value.name,
        outcome: reviewStatusLabel(decision.value, 'providers'),
        consequence: 'This records the organization verification decision and updates its access to provider workflows. Program publication remains a separate review.',
        danger: decision.value === 'rejected',
    }, () => canSave.value)) return;
    saving.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const response = await window.axios.patch(`/admin/providers/${providerId}/verification`, { verification_status: decision.value, verification_notes: notes.value.trim() || null, expected_review_token: documentSummary.value.token }, { portalToast: false });
        provider.value = {
            ...response.data.provider,
            team_members_count: provider.value.team_members_count,
            programs_count: provider.value.programs_count,
            published_programs_count: provider.value.published_programs_count,
            programs_in_review_count: provider.value.programs_in_review_count,
        };
        notes.value = provider.value.verification_notes ?? '';
        successMessage.value = response.data.message;
    } catch (error) {
        stale.value = error.response?.status === 409;
        errorMessage.value = reviewErrorMessage(error, 'The decision could not be saved.');
    } finally {
        saving.value = false;
    }
}

function openDocument(document) {
    if (!saving.value && !loading.value && !documentSaving.value) reviewDocument.value = document;
}
async function refreshReviews(result) {
    reviewDocument.value = null;
    stale.value = true;
    const payload = await resource.load();
    if (payload) { provider.value = payload; stale.value = false; }
    if (result?.message) successMessage.value = result.message;
}
function documentState(document) {
    if (document.review_status === 'approved') return { label: 'Accepted', class: 'bg-emerald-100 text-emerald-800' };
    if (document.review_status === 'rejected') return { label: 'Needs replacement', class: 'bg-amber-100 text-amber-900' };
    return { label: 'Awaiting review', class: 'bg-slate-100 text-slate-700' };
}

onMounted(loadRecord);
const backToQueue = staffQueueReturn(window, 'adminReviews', '/admin/workspaces/reviews/providers');
</script>

<template>
    <AdminWorkspacePage workspace="review_officer" class="admin-review-workspace">
        <a :href="backToQueue" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Provider queue</a>
        <AdminWorkspaceHeader eyebrow="Provider verification" title="Organization record" description="Confirm the organization and its submitted proof." icon="fa-solid fa-building-shield" />
        <section v-if="loading || loadError" class="admin-panel mt-4 overflow-hidden"><AdminLoadState :loading="loading" :error="loadError" loading-message="Loading provider record..." error-title="Organization record unavailable" @retry="loadRecord" /></section>

        <template v-else-if="provider">
            <p v-if="successMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</p>

            <section class="admin-panel mt-4 overflow-hidden">
                <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5">
                    <span class="relative grid h-16 w-16 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-building-columns text-xl" aria-hidden="true"></i><img v-if="provider.provider_logo_url" :src="provider.provider_logo_url" :alt="`${provider.provider_name} logo`" class="absolute inset-0 h-full w-full bg-white object-contain p-1" @error="$event.currentTarget.remove()"></span>
                    <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Organization record</p><h2 class="review-text mt-1 text-xl font-black text-slate-950">{{ provider.legal_name || provider.provider_name }}</h2><p class="review-text mt-1 text-sm text-slate-500">{{ provider.email }}</p></div>
                    <span :class="['shrink-0 px-3 py-1.5 text-xs font-bold uppercase', statusClass]">{{ reviewStatusLabel(provider.verification_status, 'providers') }}</span>
                </div>
                <dl class="grid grid-cols-4 divide-x divide-slate-200">
                    <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Provider type</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ labelFromKey(provider.provider_type) || 'Not provided' }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Registration number</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ provider.registration_number || 'Not provided' }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Programs</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ provider.programs_count }}</dd></div>
                    <div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Team accounts</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ provider.team_members_count }}</dd></div>
                </dl>
                <div v-if="provider.provider_address" class="border-t border-slate-200 px-6 py-4"><p class="text-xs font-semibold text-slate-500">Office address</p><p class="mt-1 text-sm font-bold text-slate-950">{{ provider.provider_address }}</p><LocationMapLink :address="provider.provider_address" :latitude="provider.provider_latitude" :longitude="provider.provider_longitude" :title="provider.provider_name || 'Organization office'" /></div>
            </section>

            <section class="admin-panel mt-4 overflow-hidden">
                <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Step 1</p><h2 class="mt-0.5 font-black text-slate-950">Review submitted proof</h2><p class="mt-0.5 text-sm text-slate-500">Inspect each file and record its own outcome.</p></div></div><p class="shrink-0 text-sm font-semibold text-slate-600">{{ documentSummary.accepted }} of {{ documentSummary.total }} accepted</p></header>
                <p v-if="provider.verification_status === 'approved' && documentSummary.awaiting_review" class="border-b border-slate-200 px-6 py-3 text-xs leading-5 text-slate-600">Existing account approval is unchanged. Historical file approvals need individual reviews before another final account verification.</p>
                <div v-if="provider.verification_documents?.length" class="staff-table-viewport staff-table-viewport--compact">
                    <table class="portal-data-table table-fixed min-w-[52rem]">
                        <colgroup><col><col class="w-48"><col class="w-44"><col class="w-36"></colgroup>
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">
                            <tr><th class="px-6 py-3">Document</th><th class="px-4 py-3">Review status</th><th class="px-4 py-3">Last reviewed</th><th class="px-6 py-3 text-right">Action</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="document in provider.verification_documents" :key="document.id" class="align-middle">
                                <td class="px-6 py-4">
                                    <p class="text-sm font-bold text-slate-950">{{ labelFromKey(document.document_type) }}</p>
                                    <p class="review-text mt-0.5 text-xs text-slate-500" data-file-name>{{ document.original_name }}</p>
                                    <WorkspaceTextSummary v-if="document.review_notes" disclosure-only class="review-text mt-0.5 text-xs text-slate-600" :text="document.review_notes" label="review note" />
                                </td>
                                <td class="px-4 py-4">
                                    <span :class="['inline-flex px-2.5 py-1.5 text-xs font-bold', documentState(document).class]">{{ documentState(document).label }}</span>
                                    <p v-if="document.file_available === false" class="mt-1 text-xs text-amber-800">File unavailable</p>
                                </td>
                                <td class="px-4 py-4 text-xs text-slate-600">
                                    <template v-if="document.reviewed_at"><p class="font-semibold">{{ document.reviewed_by }}</p><p class="mt-1">{{ document.reviewed_at }}</p></template>
                                    <span v-else>Not individually reviewed</span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button type="button" :disabled="saving || loading" data-review-provider-document class="staff-row-action h-10 whitespace-nowrap px-3 text-xs" :aria-label="`Review ${document.original_name}`" @click="openDocument(document)" title="Review document"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Review</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-6 py-10 text-center text-sm font-semibold text-slate-500">No verification proof has been submitted.</p>
            </section>

            <form class="admin-panel mt-4 p-6" :aria-busy="saving" @submit.prevent="saveDecision">
                <div class="flex items-start justify-between gap-8"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Step 2</p><h2 class="mt-0.5 text-lg font-black text-slate-950">Final provider account decision</h2><p class="mt-0.5 text-sm text-slate-500">Approval requires every current document to be accepted. This does not change individual file outcomes.</p></div></div><button type="submit" :disabled="!canSave" class="bg-slate-950 px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{{ saving ? 'Saving...' : 'Save decision' }}</button></div>
                <p v-if="errorMessage" class="mt-4 border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>
                <p v-if="decision === 'approved' && !documentSummary.can_approve" class="mt-4 text-sm font-semibold text-amber-800">{{ documentSummary.unavailable_files ? 'A saved file is unavailable. Request a replacement before approval.' : documentSummary.total ? 'Finish the document reviews in Step 1. Unreviewed files and replacement requests block approval.' : 'Uploaded organization proof is required before approval.' }}</p>
                <button v-if="stale" type="button" class="mt-3 text-sm font-bold underline underline-offset-2" @click="refreshReviews()">Reload current record</button>
                <fieldset :disabled="saving" class="mt-5 grid grid-cols-[14rem_minmax(0,1fr)] gap-4"><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Outcome</span><select v-model="decision" class="h-11 w-full border border-slate-300 bg-white px-3"><option value="approved">Approve</option><option value="rejected">Reject</option><option value="pending">Return to pending</option></select></label><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Review note <span v-if="decision === 'rejected'" class="text-rose-700">required</span></span><textarea v-model="notes" :required="decision === 'rejected'" rows="3" maxlength="1500" class="w-full border border-slate-300 px-3 py-2" placeholder="State what must be corrected."></textarea></label></fieldset>
            </form>
        </template>
        <ProviderDocumentReviewDialog v-if="provider" :document="reviewDocument" :provider="provider" @close="reviewDocument = null" @busy="documentSaving = $event" @saved="refreshReviews" @reload="refreshReviews()" />
    </AdminWorkspacePage>
</template>
