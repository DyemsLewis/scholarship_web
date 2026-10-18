<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const applicationId = window.location.pathname.match(/monitoring\/(\d+)/)?.[1];
const caseId = new URLSearchParams(window.location.search).get('case');
const loading = ref(true);
const saving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const record = ref(null);
const reviewOpen = ref(false);
const outcome = ref('reviewed');
const notes = ref('');

const requirementProgress = computed(() => {
    if (!record.value?.requirements_total) return 'No checklist items';
    return `${record.value.requirements_confirmed} of ${record.value.requirements_total} confirmed`;
});

function requestConfig() {
    return { params: caseId ? { case: caseId } : {} };
}

async function loadRecord() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/admin/monitoring/${applicationId}/data`, requestConfig());
        record.value = response.data.record;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'The monitoring record could not be loaded.';
    } finally {
        loading.value = false;
    }
}

async function saveReview() {
    saving.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const response = await window.axios.post(
            `/admin/monitoring/${applicationId}/oversight-reviews`,
            { outcome: outcome.value, notes: notes.value.trim() || null },
            requestConfig(),
        );
        record.value = response.data.record;
        successMessage.value = response.data.message;
        notes.value = '';
        reviewOpen.value = false;
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            ?? Object.values(error.response?.data?.errors ?? {})[0]?.[0]
            ?? 'The oversight review could not be saved.';
    } finally {
        saving.value = false;
    }
}

function requirementClass(status) {
    if (status === 'confirmed') return 'bg-emerald-100 text-emerald-800';
    if (['attention', 'overdue'].includes(status)) return 'bg-amber-100 text-amber-900';
    return 'bg-slate-100 text-slate-700';
}

function closeOnEscape(event) {
    if (event.key === 'Escape') reviewOpen.value = false;
}

onMounted(() => {
    loadRecord();
    window.addEventListener('keydown', closeOnEscape);
});
onBeforeUnmount(() => window.removeEventListener('keydown', closeOnEscape));
</script>

<template>
    <AdminWorkspacePage workspace="review_officer">
        <a href="/admin/workspaces/reviews/monitoring" class="mb-3 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Monitoring queue</a>
        <AdminWorkspaceHeader eyebrow="Monitoring oversight" title="Check-in review" description="Confirm the exception and provider response for this check-in." icon="fa-solid fa-chart-line">
            <template v-if="record" #actions><button type="button" class="bg-slate-950 px-5 py-3 text-sm font-bold text-white" @click="reviewOpen = true">Record review</button></template>
        </AdminWorkspaceHeader>

        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading monitoring record...</div>
        <p v-else-if="errorMessage && !record" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</p>

        <template v-else-if="record">
            <p v-if="successMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ successMessage }}</p>
            <p v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ errorMessage }}</p>

            <section class="admin-panel mt-4 overflow-hidden">
                <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5">
                    <span class="relative grid h-14 w-14 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i><img v-if="record.applicant_photo_url" :src="record.applicant_photo_url" alt="Applicant profile" class="absolute inset-0 h-full w-full object-cover" @error="$event.currentTarget.remove()"></span>
                    <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Recipient check-in</p><h2 class="mt-1 truncate text-xl font-black text-slate-950">{{ record.applicant_name }}</h2><p class="mt-1 truncate text-sm text-slate-500">{{ record.program_title }} · {{ record.provider_name }}</p></div>
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
                <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><div><h2 class="font-black text-slate-950">Requirement progress</h2><p class="mt-1 text-sm text-slate-500">Only the records tied to this check-in are shown.</p></div><span class="text-sm font-bold text-slate-700">{{ requirementProgress }}</span></header>
                <table class="w-full border-collapse text-left">
                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><tr><th class="border-b border-slate-200 px-6 py-3">Requirement</th><th class="border-b border-slate-200 px-4 py-3">Status</th><th class="border-b border-slate-200 px-6 py-3 text-right">Evidence</th></tr></thead>
                    <tbody class="divide-y divide-slate-200"><tr v-for="item in record.requirements" :key="item.id"><td class="px-6 py-4 text-sm font-bold text-slate-950">{{ item.title }}</td><td class="px-4 py-4"><span :class="['inline-flex px-2 py-1 text-[10px] font-bold uppercase tracking-wide', requirementClass(item.status)]">{{ item.status_label }}</span></td><td class="px-6 py-4 text-right"><a v-if="item.file" :href="item.file.view_url" target="_blank" rel="noopener" class="text-sm font-bold text-slate-700 underline">Open file</a><span v-else class="text-sm text-slate-400">No file</span></td></tr></tbody>
                </table>
            </section>

            <details v-if="record.exception_context || record.timeline?.length || record.last_review" class="admin-panel mt-4 overflow-hidden">
                <summary class="flex cursor-pointer list-none items-center justify-between px-6 py-4"><div><h2 class="font-black text-slate-950">Review history</h2><p class="mt-1 text-sm text-slate-500">Requests, follow-ups, and prior admin review.</p></div><i class="fa-solid fa-chevron-down text-xs text-slate-500" aria-hidden="true"></i></summary>
                <div class="border-t border-slate-200">
                    <div v-if="record.last_review" class="bg-slate-50 px-6 py-4"><p class="text-xs font-bold uppercase tracking-wide text-slate-500">Latest admin review</p><p class="mt-1 text-sm font-bold text-slate-950">{{ record.last_review.outcome_label }} · {{ record.last_review.reviewed_at }}</p><p v-if="record.last_review.notes" class="mt-1 text-sm text-slate-600">{{ record.last_review.notes }}</p></div>
                    <div v-if="record.timeline?.length" class="divide-y divide-slate-200"><article v-for="item in record.timeline.slice(0, 8)" :key="item.id" class="grid grid-cols-[10rem_minmax(0,1fr)] gap-5 px-6 py-4"><p class="text-xs font-semibold text-slate-500">{{ item.occurred_at }}</p><div><h3 class="text-sm font-bold text-slate-950">{{ item.title }}</h3><p v-if="item.detail || item.reason" class="mt-1 text-sm text-slate-600">{{ item.detail || item.reason }}</p></div></article></div>
                </div>
            </details>
        </template>

        <div v-if="reviewOpen" class="fixed inset-0 z-[80] grid place-items-center bg-slate-950/60 p-6" role="dialog" aria-modal="true" aria-labelledby="monitoring-review-title" @click.self="reviewOpen = false">
            <form class="w-full max-w-2xl border border-slate-300 bg-white shadow-2xl" @submit.prevent="saveReview">
                <header class="flex items-start justify-between border-b border-slate-200 px-6 py-5"><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Admin oversight</p><h2 id="monitoring-review-title" class="mt-1 text-xl font-black text-slate-950">Record review</h2><p class="mt-1 text-sm text-slate-500">Follow-up and escalation notify the provider.</p></div><button type="button" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600" aria-label="Close" @click="reviewOpen = false"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                <div class="space-y-4 px-6 py-5"><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Outcome</span><select v-model="outcome" class="h-11 w-full border border-slate-300 bg-white px-3"><option value="reviewed">Reviewed, no action</option><option value="follow_up_required">Provider follow-up</option><option value="escalated">Escalate</option></select></label><label><span class="mb-1.5 block text-sm font-bold text-slate-700">Review note <span v-if="outcome !== 'reviewed'" class="text-rose-700">required</span></span><textarea v-model="notes" rows="4" maxlength="2000" class="w-full border border-slate-300 px-3 py-2" placeholder="State the action expected from the provider."></textarea></label></div>
                <footer class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700" @click="reviewOpen = false">Cancel</button><button type="submit" :disabled="saving" class="bg-slate-950 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ saving ? 'Saving...' : 'Save review' }}</button></footer>
            </form>
        </div>
    </AdminWorkspacePage>
</template>
