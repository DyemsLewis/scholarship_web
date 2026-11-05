<script setup>
import { computed, ref, watch } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { evidenceEventLabel, evidenceLabel, evidenceReviewDate } from '../../../support/applicantEvidenceReview.js';
import { labelFromKey } from '../../../support/display.js';

const props = defineProps({ applicant: { type: Object, required: true }, version: { type: Number, default: 0 } });
const open = ref(false);
const page = ref(1);
const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get(props.applicant.evidence_review_history_url, { params: { page: page.value }, signal });
    if (!Array.isArray(response.data?.history?.data)) throw new Error('History unavailable.');
    return response.data.history;
}, { failureMessage: 'Review history could not be loaded.' });
const { data: history, isLoading: loading, errorMessage: error, hasLoaded } = resource;
const events = computed(() => history.value?.data ?? []);
async function loadPage(number = 1) {
    if (!props.applicant.evidence_review_history_url) return;
    page.value = number;
    await resource.load();
}
function toggle(event) {
    open.value = event.target.open;
    if (open.value && !hasLoaded.value) loadPage();
}
watch(() => props.version, () => {
    if (open.value) loadPage();
    else { resource.invalidate(); hasLoaded.value = false; }
});
</script>

<template>
    <details v-if="applicant.evidence_review_history_url" class="admin-panel mt-4" @toggle="toggle">
        <summary class="cursor-pointer px-6 py-4 text-sm font-bold text-slate-700"><i class="fa-solid fa-clock-rotate-left mr-2 text-slate-500" aria-hidden="true"></i>Document review history <span class="ml-2 font-normal text-slate-500">Uploads, decisions, and changes</span></summary>
        <div class="border-t border-slate-200 px-6 py-4" :aria-busy="loading">
            <p v-if="loading" role="status" class="py-4 text-sm text-slate-500">Loading review history...</p>
            <div v-else-if="error" role="alert" class="py-3 text-sm text-slate-600">{{ error }} <button type="button" class="ml-2 font-bold underline" @click="loadPage(page)">Try again</button></div>
            <ol v-else-if="events.length" class="divide-y divide-slate-200">
                <li v-for="event in events" :key="event.id" class="py-4">
                    <div class="flex items-baseline justify-between gap-6"><h3 class="text-sm font-bold text-slate-950">{{ evidenceEventLabel(event.action) }} <span class="font-normal text-slate-500">| {{ evidenceLabel(applicant, event.document_type) }}</span></h3><time class="shrink-0 text-xs text-slate-500">{{ evidenceReviewDate(event.created_at) }}</time></div>
                    <p class="mt-1 break-words text-xs text-slate-500">{{ event.original_name }} | {{ event.actor_name || 'System' }}</p>
                    <p v-if="event.note" class="mt-2 whitespace-pre-line break-words text-sm text-slate-700">{{ event.note }}</p>
                    <p v-if="event.changed_fields?.length" class="mt-2 text-xs text-slate-600">Changed: {{ event.changed_fields.map((field) => labelFromKey(field)).join(', ') }}</p>
                </li>
            </ol>
            <p v-else class="py-4 text-sm text-slate-500">No document review events recorded. Earlier approvals have no individual review history.</p>
            <nav v-if="!error && history?.last_page > 1" class="mt-3 flex items-center justify-between border-t border-slate-200 pt-4" aria-label="Document review history pages"><p class="text-xs text-slate-500">Page {{ history.current_page }} of {{ history.last_page }}</p><div class="flex gap-2"><button type="button" :disabled="loading || history.current_page <= 1" class="border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadPage(history.current_page - 1)">Previous</button><button type="button" :disabled="loading || history.current_page >= history.last_page" class="border border-slate-300 px-3 py-2 text-xs font-bold disabled:opacity-40" @click="loadPage(history.current_page + 1)">Next</button></div></nav>
        </div>
    </details>
</template>
