<script setup>
import AdminReviewGuide from '../../../components/admin/AdminReviewGuide.vue';
import AdminReviewNavigation from '../../../components/admin/AdminReviewNavigation.vue';
import { useAdminReviewNavigation } from '../../../composables/useAdminReviewNavigation.js';
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import AdminLoadState from '../components/AdminLoadState.vue';
import ProgramReviewDetailsModal from './ProgramReviewDetailsModal.vue';
import { programReviewSections } from '../../../support/adminProgramReview.js';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';
import { reviewErrorMessage, reviewRequirementItems, reviewStatusLabel } from '../../../support/adminReviewPresentation.js';

const programId = (window.portalPathname?.() ?? window.location.pathname).match(/programs\/(\d+)/)?.[1];
const navigation = useAdminReviewNavigation('programs', programId);
const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get(`/admin/scholarships/${programId}/review/data`, { signal });
    if (!response.data.scholarship) throw new Error('Missing program.');
    return response.data.scholarship;
}, { failureMessage: 'The program record could not be loaded.' });
const { isLoading: loading, errorMessage: loadError } = resource;
const saving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const program = ref(null);
const decision = ref('published');
const notes = ref('');
const stale = ref(false);
const showDetails = ref(false);
const detailsSection = ref('overview');
const requirements = computed(() => reviewRequirementItems(program.value?.requirements));
const statusClass = computed(() => program.value?.status === 'published' ? 'bg-emerald-100 text-emerald-800' : program.value?.status === 'rejected' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700');
const inReview = computed(() => ['pending_review', 'published', 'rejected'].includes(program.value?.status));
const canSave = computed(() => inReview.value && !loading.value && !loadError.value && !saving.value
    && program.value.can_review !== false
    && !stale.value && Boolean(program.value.review_workflow?.token)
    && (decision.value !== 'published' || program.value.review_workflow?.ready === true)
    && (decision.value !== 'rejected' || Boolean(notes.value.trim())));

async function loadRecord({ preserveDraft = false } = {}) {
    if (saving.value) return;
    errorMessage.value = '';
    const payload = await resource.load();
    if (payload) {
        program.value = payload;
        stale.value = false;
        navigation.load();
        if (!preserveDraft) {
            decision.value = payload.status === 'pending_review' ? 'published' : inReview.value ? payload.status : 'pending_review';
            notes.value = '';
        }
    }
}

async function saveDecision() {
    if (!canSave.value) return;
    if (!await confirmOutcome({ record: program.value.title, outcome: reviewStatusLabel(decision.value, 'programs'),
        danger: decision.value === 'rejected', consequence: decision.value === 'published'
            ? 'The program will be published and visible to applicants.' : 'This saves the publication-review outcome and review note.' }, () => canSave.value)) return;
    saving.value = true; errorMessage.value = ''; successMessage.value = '';
    try {
        const response = await window.axios.patch(`/admin/scholarships/${programId}/review`, { status: decision.value, review_notes: notes.value.trim() || null, expected_review_token: program.value.review_workflow.token }, { portalToast: false });
        program.value = response.data.scholarship;
        successMessage.value = response.data.message;
        navigation.load();
    } catch (error) {
        stale.value = error.response?.status === 409;
        errorMessage.value = reviewErrorMessage(error, 'The decision could not be saved.');
    } finally { saving.value = false; }
}

function continueReview() {
    document.getElementById('publication-decision')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function openDetails(section = 'overview') {
    detailsSection.value = section;
    showDetails.value = true;
}
onMounted(loadRecord);
</script>

<template>
    <AdminWorkspacePage workspace="review_officer" class="admin-review-workspace">
        <AdminReviewNavigation :back-url="navigation.backUrl" :next="navigation.next.value" :loading="navigation.loading.value" :error="navigation.error.value" @retry="navigation.load" />
        <AdminWorkspaceHeader eyebrow="Program review" title="Review a scholarship program" description="Inspect the submitted offer, then record the publication decision." icon="fa-solid fa-graduation-cap" />
        <section v-if="loading || loadError" class="admin-panel mt-4 overflow-hidden"><AdminLoadState :loading="loading" :error="loadError" loading-message="Loading program record..." error-title="Publication record unavailable" @retry="loadRecord" /></section>
        <template v-else-if="program">
            <FeedbackToast v-if="successMessage" :message="successMessage" type="success" />
            <section class="admin-panel mt-4 overflow-hidden rounded-sm" data-program-review-summary>
                <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5"><span class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-sm border border-slate-200 bg-slate-50 text-slate-500"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><img v-if="program.logo_url" :src="program.logo_url" :alt="`${program.title} logo`" class="absolute inset-0 h-full w-full bg-white object-contain p-1" @error="$event.currentTarget.remove()"></span><div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Submitted program</p><h2 class="review-text mt-1 text-xl font-bold text-slate-950">{{ program.title }}</h2><p class="review-text mt-1 text-sm text-slate-500">{{ program.provider }}</p></div><span :class="['shrink-0 rounded-sm px-3 py-1.5 text-xs font-bold uppercase', statusClass]">{{ reviewStatusLabel(program.status, 'programs') }}</span></div>
                <dl class="grid grid-cols-4 divide-x divide-slate-200 bg-slate-50/70"><div class="px-6 py-3.5"><dt class="text-xs text-slate-500">Application period</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ program.application_opens_at || 'Not set' }} to {{ program.deadline || 'Not set' }}</dd></div><div class="px-6 py-3.5"><dt class="text-xs text-slate-500">Available slots</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ program.slots_available ?? 'Not limited' }}</dd></div><div class="px-6 py-3.5"><dt class="text-xs text-slate-500">Required files</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ requirements.length }} listed</dd></div><div class="px-6 py-3.5"><dt class="text-xs text-slate-500">Provider verification</dt><dd class="mt-1 text-sm font-semibold text-slate-950">{{ program.provider_verification_status === 'approved' ? 'Verified organization' : 'Needs verification' }}</dd></div></dl>
            </section>
            <AdminReviewGuide :workflow="program.review_workflow" kind="program" @continue="continueReview" />
            <div class="mt-4 grid grid-cols-[minmax(0,1.1fr)_minmax(23rem,0.9fr)] items-start gap-4" data-program-review-layout>
                <section class="admin-panel overflow-hidden rounded-sm" aria-labelledby="program-information-title">
                    <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-5"><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Inspect the offer</p><h2 id="program-information-title" class="mt-1 text-lg font-bold text-slate-950">Program information</h2><p class="mt-1 text-sm text-slate-500">Open one section at a time without leaving this review.</p></div><button type="button" class="inline-flex h-10 shrink-0 items-center gap-2 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950" aria-haspopup="dialog" :aria-expanded="showDetails" @click="openDetails()">Open details<i class="fa-solid fa-expand text-xs" aria-hidden="true"></i></button></header>
                    <div class="divide-y divide-slate-200"><button v-for="section in programReviewSections" :key="section.key" type="button" class="flex w-full items-center gap-4 px-6 py-4 text-left hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-inset focus-visible:outline-slate-950" aria-haspopup="dialog" :aria-label="`Open ${section.title.toLowerCase()}`" @click="openDetails(section.key)"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-500"><i :class="section.icon" aria-hidden="true"></i></span><span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-950">{{ section.title }}</span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ section.description }}</span></span><i class="fa-solid fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i></button></div>
                </section>
                <form v-if="inReview && program.can_review !== false" id="publication-decision" class="admin-panel scroll-mt-5 overflow-hidden rounded-sm" :aria-busy="saving" @submit.prevent="saveDecision">
                    <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Publication decision</p><h2 class="mt-1 text-lg font-bold text-slate-950">Record the review outcome</h2></div></header>
                    <div class="space-y-5 px-6 py-5">
                        <template v-if="errorMessage"><FeedbackToast :message="errorMessage" /></template>
                        <p class="text-sm leading-6 text-slate-600">Inspect the program details before deciding. Publishing makes this offer visible to applicants.</p>
                        <fieldset :disabled="saving" class="space-y-5">
                            <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700">Outcome</span><select v-model="decision" class="h-11 w-full rounded-sm border border-slate-300 bg-white px-3"><option value="published" :disabled="program.review_workflow?.ready !== true">Publish</option><option value="rejected">Return to provider</option><option value="pending_review">Keep in review</option></select><p v-if="program.review_workflow?.ready !== true" class="mt-2 text-xs leading-5 text-amber-800">Resolve the publication issues above before publishing.</p></label>
                            <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700">Review note <span class="text-xs font-normal text-slate-500">{{ decision === 'rejected' ? '(required)' : '(optional)' }}</span></span><textarea v-model="notes" :required="decision === 'rejected'" rows="5" maxlength="1500" class="w-full rounded-sm border border-slate-300 px-3 py-2 text-sm leading-6" :placeholder="decision === 'rejected' ? 'Explain what the provider needs to correct.' : 'Add context for this publication decision.'"></textarea></label>
                        </fieldset>
                    </div>
                    <footer class="flex items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-4"><p class="text-xs leading-5 text-slate-500">You will confirm the outcome before it is saved.</p><button type="submit" :disabled="!canSave" class="inline-flex h-11 shrink-0 items-center gap-2 rounded-sm bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-50"><i class="fa-solid fa-clipboard-check text-xs text-amber-300" aria-hidden="true"></i>{{ saving ? 'Saving...' : 'Save decision' }}</button></footer>
                </form>
                <section v-else id="publication-decision" class="admin-panel scroll-mt-5 rounded-sm p-6"><span class="grid h-10 w-10 place-items-center rounded-sm bg-slate-100 text-slate-500"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><h2 class="mt-4 text-lg font-bold text-slate-950">{{ program.can_review === false ? 'Publication oversight' : 'Not in publication review' }}</h2><p class="mt-2 text-sm leading-6 text-slate-600">{{ program.can_review === false ? 'This is a read-only view. The Review Officer records the publication decision. You can inspect all program details without changing the outcome.' : 'This record is read-only until it enters the publication review workflow. The submitted program details remain available.' }}</p></section>
            </div>
            <ProgramReviewDetailsModal :open="showDetails" :program="program" :section="detailsSection" @close="showDetails = false" />
        </template>
    </AdminWorkspacePage>
</template>
