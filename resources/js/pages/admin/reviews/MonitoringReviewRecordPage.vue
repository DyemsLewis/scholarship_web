<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminEmptyState from '../components/AdminEmptyState.vue';
import AdminDialog from '../components/AdminDialog.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';
import AdminLoadState from '../components/AdminLoadState.vue';
import { useAdminResource } from '../../../composables/useAdminResource';
import { reviewErrorMessage } from '../../../support/adminReviewPresentation';

const applicationId = (window.portalPathname?.() ?? window.location.pathname).match(/monitoring\/(\d+)/)?.[1];
const caseId = new URLSearchParams(window.location.search).get('case');
const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get(`/admin/monitoring/${applicationId}/data`, { ...requestConfig(), signal });
    if (!response.data?.record) throw new Error('Monitoring record unavailable.');
    return response.data.record;
}, { failureMessage: 'The monitoring record could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const saving = ref(false);
const successMessage = ref('');
const record = ref(null);
const reviewOpen = ref(false);
const outcome = ref('reviewed');
const notes = ref('');
const reviewError = ref('');

const requirementProgress = computed(() => {
    if (!record.value?.requirements_total) return 'No checklist items';
    return `${record.value.requirements_confirmed} of ${record.value.requirements_total} confirmed`;
});
const canSaveReview = computed(() => outcome.value === 'reviewed' || notes.value.trim().length > 0);

function requestConfig() {
    return { params: caseId ? { case: caseId } : {} };
}

async function loadRecord() {
    if (saving.value) return;
    const payload = await resource.load();
    if (payload) record.value = payload;
}

async function saveReview() {
    if (saving.value || loading.value || errorMessage.value || !record.value || !canSaveReview.value) return;
    saving.value = true;
    reviewError.value = '';
    successMessage.value = '';
    try {
        const response = await window.axios.post(
            `/admin/monitoring/${applicationId}/oversight-reviews`,
            { outcome: outcome.value, notes: notes.value.trim() || null },
            { ...requestConfig(), portalToast: false },
        );
        record.value = response.data.record;
        successMessage.value = response.data.message;
        notes.value = '';
        reviewOpen.value = false;
    } catch (error) {
        reviewError.value = reviewErrorMessage(error, 'The oversight review could not be saved.');
    } finally {
        saving.value = false;
    }
}

function requirementClass(status) {
    if (status === 'confirmed') return 'bg-emerald-100 text-emerald-800';
    if (['attention', 'overdue'].includes(status)) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-700';
}

function openReview() {
    if (saving.value || loading.value || errorMessage.value || !record.value) return;
    reviewError.value = '';
    reviewOpen.value = true;
}

function closeReview() {
    if (!saving.value) reviewOpen.value = false;
}

onMounted(loadRecord);
</script>

<template>
    <AdminWorkspacePage workspace="review_officer" class="admin-review-workspace">
        <a href="/admin/workspaces/reviews/monitoring" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Monitoring queue</a>
        <AdminWorkspaceHeader eyebrow="Monitoring oversight" title="Check-in review" description="Confirm the exception and provider response for this check-in." icon="fa-solid fa-chart-line">
            <template v-if="record && !loading && !errorMessage" #actions><button type="button" :disabled="saving" class="inline-flex items-center gap-2 bg-slate-950 px-5 py-3 text-sm font-bold text-white disabled:opacity-50" @click="openReview"><i class="fa-solid fa-clipboard-check text-xs text-amber-300" aria-hidden="true"></i>Record review</button></template>
        </AdminWorkspaceHeader>

        <section v-if="loading || errorMessage" class="admin-panel mt-4"><AdminLoadState :loading="loading" :error="errorMessage" loading-message="Loading monitoring record..." @retry="loadRecord" /></section>

        <template v-else-if="record">
            <p v-if="successMessage" role="status" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ successMessage }}</p>

            <section class="admin-panel mt-4 overflow-hidden">
                <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5">
                    <span class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i><img v-if="record.applicant_photo_url" :src="record.applicant_photo_url" alt="Applicant profile" class="absolute inset-0 h-full w-full object-cover" @error="$event.currentTarget.remove()"></span>
                    <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Recipient check-in</p><h2 class="review-text mt-1 text-xl font-black text-slate-950">{{ record.applicant_name }}</h2><p class="review-text mt-1 text-sm text-slate-500">{{ record.program_title }} · {{ record.provider_name }}</p></div>
                    <span class="bg-slate-100 px-3 py-1.5 text-xs font-bold uppercase text-slate-700">{{ record.oversight_label }}</span>
                </div>
                <dl class="grid grid-cols-3 divide-x divide-slate-200 bg-slate-50">
                    <div class="px-6 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Check-in</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ record.cycle_title }}</dd></div>
                    <div class="px-6 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Progress</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ requirementProgress }}</dd></div>
                    <div class="px-6 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Deadline</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ record.due_on || 'Not set' }} <span class="font-semibold text-slate-500">· {{ record.due_status_label }}</span></dd></div>
                </dl>
            </section>

            <section :class="['admin-panel mt-4 border-l-4 p-6', record.current_issue ? 'border-l-amber-500' : 'border-l-emerald-600']">
                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Current exception</p>
                <h2 class="mt-2 text-lg font-black text-slate-950">{{ record.current_issue?.title || 'No unresolved exception' }}</h2>
                <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-600">{{ record.current_issue?.detail || 'The check-in does not currently require admin intervention.' }}</p>
                <div v-if="record.provider_action" class="mt-4 flex items-center gap-3 border-t border-slate-200 pt-4"><span class="grid h-9 w-9 place-items-center bg-slate-950 text-white"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span><div><p class="text-xs font-semibold text-slate-500">Next action · {{ record.provider_action.role_label }}</p><p class="text-sm font-bold text-slate-950">{{ record.provider_action.label }}</p></div></div>
            </section>

            <section class="admin-panel mt-4 overflow-hidden">
                <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Requirement progress</h2><p class="mt-0.5 text-sm text-slate-500">Only records tied to this check-in are shown.</p></div></div><span class="text-sm font-bold text-slate-700">{{ requirementProgress }}</span></header>
                <div v-if="record.requirements?.length" class="staff-table-viewport"><table class="portal-data-table table-fixed min-w-[48rem]">
                    <colgroup><col><col class="w-44"><col class="w-28"></colgroup>
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><tr><th class="border-b border-slate-200 px-6 py-3">Requirement</th><th class="border-b border-slate-200 px-4 py-3">Status</th><th class="border-b border-slate-200 px-6 py-3 text-right">Evidence</th></tr></thead>
                    <tbody class="divide-y divide-slate-200"><tr v-for="item in record.requirements" :key="item.id"><td class="px-6 py-4 text-sm font-bold text-slate-950">{{ item.title }}<span v-if="item.file?.name" class="review-text mt-1 block text-xs font-normal text-slate-500" data-file-name>{{ item.file.name }}</span></td><td class="px-4 py-4"><span :class="['inline-flex px-2 py-1 text-[10px] font-bold uppercase tracking-wide', requirementClass(item.status)]">{{ item.status_label }}</span></td><td class="px-6 py-4 text-right"><a v-if="item.file" :href="item.file.view_url" data-file-preview="true" :data-file-name="item.file.name || item.title" class="inline-grid h-10 w-10 place-items-center border border-slate-300 text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" :aria-label="`Preview ${item.file.name || item.title}`" title="Preview evidence"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a><span v-else class="text-sm text-slate-400">No file</span></td></tr></tbody>
                </table></div>
                <AdminEmptyState v-else title="No requirements recorded" description="This check-in does not contain a requirement checklist." icon="fa-solid fa-list-check" />
            </section>

            <details v-if="record.exception_context || record.timeline?.length || record.last_review" class="admin-panel mt-4 overflow-hidden">
                <summary class="flex cursor-pointer list-none items-center justify-between px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Review history</h2><p class="mt-0.5 text-sm text-slate-500">Requests, follow-ups, and prior admin review.</p></div></div><i class="fa-solid fa-chevron-down text-xs text-slate-500" aria-hidden="true"></i></summary>
                <div class="border-t border-slate-200">
                    <div v-if="record.last_review" class="bg-slate-50 px-6 py-4"><p class="text-xs font-bold uppercase tracking-wide text-slate-500">Latest admin review</p><p class="mt-1 text-sm font-bold text-slate-950">{{ record.last_review.outcome_label }} · {{ record.last_review.reviewed_at }}</p><p v-if="record.last_review.notes" class="mt-1 text-sm text-slate-600">{{ record.last_review.notes }}</p></div>
                    <div v-if="record.timeline?.length" class="divide-y divide-slate-200"><article v-for="item in record.timeline.slice(0, 8)" :key="item.id" class="grid grid-cols-[10rem_minmax(0,1fr)] gap-5 px-6 py-4"><p class="text-xs font-semibold text-slate-500">{{ item.occurred_at }}</p><div><h3 class="text-sm font-bold text-slate-950">{{ item.title }}</h3><p v-if="item.detail || item.reason" class="mt-1 text-sm text-slate-600">{{ item.detail || item.reason }}</p></div></article></div>
                </div>
            </details>
        </template>

        <AdminDialog :open="reviewOpen" title="Record review" title-id="monitoring-review-title" eyebrow="Admin oversight" description="Follow-up and escalation notify the provider." confirm-label="Save review" :busy="saving" :confirm-disabled="!canSaveReview || loading || !!errorMessage" :error="reviewError" wide @confirm="saveReview" @cancel="closeReview">
            <label class="block"><span class="mb-1.5 block text-sm font-bold text-slate-700">Outcome</span><select v-model="outcome" class="h-11 w-full rounded-sm border border-slate-300 bg-white px-3"><option value="reviewed">Reviewed, no action</option><option value="follow_up_required">Provider follow-up</option><option value="escalated">Escalate</option></select></label>
            <label class="block"><span class="mb-1.5 block text-sm font-bold text-slate-700">Review note <span v-if="outcome !== 'reviewed'" class="font-normal text-rose-700">required</span></span><textarea v-model="notes" rows="4" maxlength="2000" class="w-full rounded-sm border border-slate-300 px-3 py-2" placeholder="State the action expected from the provider."></textarea></label>
        </AdminDialog>
    </AdminWorkspacePage>
</template>
