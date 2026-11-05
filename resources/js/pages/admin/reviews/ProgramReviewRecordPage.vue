<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import AdminLoadState from '../components/AdminLoadState.vue';
import EducationEligibilitySummary from '../../../components/EducationEligibilitySummary.vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { reviewErrorMessage, reviewRequirementItems, reviewStatusLabel } from '../../../support/adminReviewPresentation.js';

const programId = (window.portalPathname?.() ?? window.location.pathname).match(/programs\/(\d+)/)?.[1];
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
const requirements = computed(() => reviewRequirementItems(program.value?.requirements));
const statusClass = computed(() => program.value?.status === 'published' ? 'bg-emerald-100 text-emerald-800' : program.value?.status === 'rejected' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700');
const inReview = computed(() => ['pending_review', 'published', 'rejected'].includes(program.value?.status));
const canSave = computed(() => inReview.value && !loading.value && !loadError.value && !saving.value
    && (decision.value !== 'rejected' || Boolean(notes.value.trim())));

async function loadRecord() {
    if (saving.value) return;
    errorMessage.value = '';
    const payload = await resource.load();
    if (payload) {
        program.value = payload;
        decision.value = payload.status === 'pending_review' ? 'published' : inReview.value ? payload.status : 'pending_review';
    }
}

async function saveDecision() {
    if (!canSave.value) return;
    saving.value = true; errorMessage.value = ''; successMessage.value = '';
    try {
        const response = await window.axios.patch(`/admin/scholarships/${programId}/review`, { status: decision.value, review_notes: notes.value.trim() || null }, { portalToast: false });
        program.value = response.data.scholarship;
        successMessage.value = response.data.message;
    } catch (error) {
        errorMessage.value = reviewErrorMessage(error, 'The decision could not be saved.');
    } finally { saving.value = false; }
}

onMounted(loadRecord);
</script>

<template>
    <AdminWorkspacePage workspace="review_officer" class="admin-review-workspace">
        <a href="/admin/workspaces/reviews/programs" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs"></i>Program queue</a>
        <AdminWorkspaceHeader eyebrow="Program review" title="Publication record" description="Check program details and applicant-facing terms." icon="fa-solid fa-graduation-cap" />
        <section v-if="loading || loadError" class="admin-panel mt-4 overflow-hidden"><AdminLoadState :loading="loading" :error="loadError" loading-message="Loading program record..." error-title="Publication record unavailable" @retry="loadRecord" /></section>
        <template v-else-if="program">
            <p v-if="successMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</p>
            <section class="admin-panel mt-4 overflow-hidden">
                <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5"><span class="relative grid h-16 w-16 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><img v-if="program.logo_url" :src="program.logo_url" :alt="`${program.title} logo`" class="absolute inset-0 h-full w-full bg-white object-contain p-1" @error="$event.currentTarget.remove()"></span><div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Scholarship program</p><h2 class="review-text mt-1 text-xl font-black text-slate-950">{{ program.title }}</h2><p class="review-text mt-1 text-sm text-slate-500">{{ program.provider }}</p></div><span :class="['shrink-0 px-3 py-1.5 text-xs font-bold uppercase', statusClass]">{{ reviewStatusLabel(program.status, 'programs') }}</span></div>
                <dl class="grid grid-cols-4 divide-x divide-slate-200"><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Category</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ program.category || 'Not specified' }}</dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Application period</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ program.application_opens_at || 'Not set' }} to {{ program.deadline || 'Not set' }}</dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Available slots</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ program.slots_available ?? 'Not limited' }}</dd></div><div class="px-5 py-4"><dt class="text-xs font-semibold text-slate-500">Minimum grade</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ program.minimum_grade_label || 'Not required' }}</dd></div></dl>
            </section>
            <section class="admin-panel mt-4 divide-y divide-slate-200">
                <div class="px-6 py-5"><h2 class="flex items-center gap-2 font-black text-slate-950"><i class="fa-solid fa-align-left text-sm text-slate-500" aria-hidden="true"></i>Program summary</h2><p class="mt-2 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-600">{{ program.description || 'No description provided.' }}</p></div>
                <div class="px-6 py-5"><h2 class="flex items-center gap-2 font-black text-slate-950"><i class="fa-solid fa-list-check text-sm text-slate-500" aria-hidden="true"></i>Eligibility and required files</h2><p class="mt-2 text-sm leading-6 text-slate-600">{{ program.eligibility || 'No general eligibility note.' }}</p><ul v-if="requirements.length" class="mt-3 list-inside list-disc space-y-1 text-sm text-slate-700"><li v-for="(requirement, index) in requirements" :key="index" class="review-text">{{ requirement }}</li></ul><p v-else class="mt-3 text-sm text-slate-500">No required files listed.</p></div>
                <div v-if="program.education_eligibility_policy?.requires_completed_qualification || program.education_eligibility_policy?.enrollment_statuses?.length" class="px-6 py-5"><h2 class="text-sm font-bold text-slate-950">Qualification and enrollment rules</h2><EducationEligibilitySummary :policy="program.education_eligibility_policy" :modes="program.eligibility_rule_modes" /></div>
                <details class="group px-6 py-5"><summary class="flex cursor-pointer list-none items-center justify-between font-black text-slate-950"><span class="flex items-center gap-2"><i class="fa-solid fa-file-contract text-sm text-slate-500" aria-hidden="true"></i>Applicant terms and support</span><i class="fa-solid fa-chevron-down text-xs text-slate-400 group-open:rotate-180" aria-hidden="true"></i></summary><dl class="mt-4 divide-y divide-slate-200 border-y border-slate-200 text-sm"><div class="grid grid-cols-[12rem_minmax(0,1fr)] gap-6 py-3"><dt class="font-bold text-slate-700">Benefits</dt><dd class="text-slate-600">{{ program.benefit_summary || 'Not specified' }}</dd></div><div class="grid grid-cols-[12rem_minmax(0,1fr)] gap-6 py-3"><dt class="font-bold text-slate-700">Recipient agreement</dt><dd class="whitespace-pre-line text-slate-600">{{ program.recipient_agreement || 'No additional agreement.' }}</dd></div><div class="grid grid-cols-[12rem_minmax(0,1fr)] gap-6 py-3"><dt class="font-bold text-slate-700">Provider handoff</dt><dd class="whitespace-pre-line text-slate-600">{{ program.handoff_instructions || 'No external handoff.' }}</dd></div></dl></details>
            </section>
            <form v-if="inReview" class="admin-panel mt-4 p-6" :aria-busy="saving" @submit.prevent="saveDecision">
                <div class="flex items-start justify-between gap-8"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Decision</p><h2 class="mt-0.5 text-lg font-black text-slate-950">Record publication decision</h2><p class="mt-0.5 text-sm text-slate-500">Publishing makes the program visible to applicants.</p></div></div><button type="submit" :disabled="!canSave" class="bg-slate-950 px-5 py-3 text-sm font-bold text-white disabled:opacity-50">{{ saving ? 'Saving...' : 'Save decision' }}</button></div>
                <p v-if="errorMessage" class="mt-4 border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>
                <fieldset :disabled="saving" class="mt-5 grid grid-cols-[14rem_minmax(0,1fr)] gap-4">
                    <label><span class="mb-1.5 block text-sm font-bold text-slate-700">Outcome</span><select v-model="decision" class="h-11 w-full border border-slate-300 bg-white px-3"><option value="published">Publish</option><option value="rejected">Return to provider</option><option value="pending_review">Keep in review</option></select></label>
                    <label><span class="mb-1.5 block text-sm font-bold text-slate-700">Review note <span v-if="decision === 'rejected'" class="text-rose-700">required</span></span><textarea v-model="notes" :required="decision === 'rejected'" rows="3" maxlength="1500" class="w-full border border-slate-300 px-3 py-2" placeholder="State what must be corrected."></textarea></label>
                </fieldset>
            </form>
            <section v-else class="admin-panel mt-4 px-6 py-5"><h2 class="text-sm font-bold text-slate-950">Not in publication review</h2><p class="mt-1 text-sm text-slate-600">This record is read-only until it enters the publication review workflow.</p></section>
        </template>
    </AdminWorkspacePage>
</template>
