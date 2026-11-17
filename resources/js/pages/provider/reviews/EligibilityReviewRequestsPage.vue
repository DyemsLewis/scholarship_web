<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import FeedbackAlertModal from '../../../components/FeedbackAlertModal.vue';
import FilePreviewModal from '../../../components/FilePreviewModal.vue';
import { useModalAccessibility } from '../../../composables/useModalAccessibility';
import { confirmOutcome } from '../../../support/outcomeConfirmation';

const rows = ref([]), pagination = ref({ current_page: 1, last_page: 1, total: 0 }), status = ref('pending'), search = ref('');
const loading = ref(false), busy = ref(false), error = ref(''), record = ref(null), decision = ref(''), note = ref(''), reviewed = ref(false), preview = ref(null), dialog = ref(null);
let version = 0, timer;
const { handleKeydown } = useModalAccessibility(() => Boolean(record.value), dialog, close);
function failure(error) { return Object.values(error.response?.data?.errors ?? {}).flat()[0] || error.response?.data?.message || 'The eligibility review could not be updated.'; }
async function load(page = 1) {
    const revision = ++version; loading.value = true;
    try { const { data } = await window.axios.get('/provider/workspaces/reviews/eligibility-requests/data', { params: { page, status: status.value, search: search.value }, portalToast: false }); if (revision === version) { rows.value = data.requests.data; pagination.value = data.requests; } }
    catch (errorValue) { if (revision === version) error.value = failure(errorValue); }
    finally { if (revision === version) loading.value = false; }
}
async function open(id) {
    if (busy.value) return;
    busy.value = true;
    try { const { data } = await window.axios.get(`/provider/eligibility-reviews/${id}/data`, { portalToast: false }); record.value = data.request; decision.value = ''; note.value = ''; reviewed.value = false; }
    catch (value) { error.value = failure(value); }
    finally { busy.value = false; }
}
async function close() {
    if (busy.value) return;
    if ((decision.value || note.value.trim()) && !await confirmOutcome({ record: record.value?.applicant, outcome: 'Discard unsaved decision', consequence: 'No eligibility review decision will be recorded.', danger: true, confirmLabel: 'Discard changes' }, () => !busy.value && Boolean(record.value))) return;
    record.value = null;
}
function viewEvidence() { const file = record.value?.evidence; if (file) preview.value = { original_name: file.name, mime_type: file.mime, size: file.size, view_url: file.url }; }
async function save() {
    const item = record.value;
    if (busy.value || !item || !decision.value || note.value.trim().length < 10 || (item.evidence && !reviewed.value) || (decision.value === 'approved' && !item.can_approve)) return;
    if (!await confirmOutcome({ record: item.applicant, outcome: decision.value === 'approved' ? 'Approve eligibility exception' : 'Decline eligibility request', consequence: decision.value === 'approved' ? 'This permits an application for the reviewed profile and rules only. It does not select the applicant, change their profile, or reserve a slot.' : 'The applicant will receive your explanation and the existing eligibility rules remain enforced.', confirmLabel: 'Record decision' }, () => !busy.value && record.value?.id === item.id)) return;
    busy.value = true;
    try { await window.axios.patch(`/provider/eligibility-reviews/${item.id}/decision`, { decision: decision.value, decision_note: note.value.trim(), context_hash: item.context_hash, confirmed: true, evidence_reviewed: reviewed.value || !item.evidence }, { portalToast: false }); record.value = null; await load(pagination.value.current_page); }
    catch (value) { error.value = failure(value); }
    finally { busy.value = false; }
}
watch(status, () => load());
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => load(), 300); });
onMounted(async () => { await load(); const id = new URLSearchParams(window.location.search).get('request'); if (/^\d+$/.test(id || '')) await open(id); });
onBeforeUnmount(() => { version++; clearTimeout(timer); });
const labels = { pending: 'Awaiting review', approved: 'Exception approved', declined: 'Declined' };
</script>

<template>
    <main class="student-shell"><ProviderSidebar /><section class="student-page provider-application-workspace" aria-labelledby="eligibility-review-queue-title">
        <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm"><div class="flex items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i></span><div><p class="text-xs font-bold uppercase tracking-widest text-amber-700">Application review</p><h1 id="eligibility-review-queue-title" class="mt-1 font-display text-2xl font-bold text-slate-950">Eligibility review requests</h1><p class="mt-2 text-sm text-slate-600">Review an applicant's explanation before deciding whether an exception may permit an application.</p></div></div></header>
        <section class="mt-4 border border-slate-200 bg-white shadow-sm">
            <div class="flex items-end gap-4 border-b border-slate-200 px-5 py-4"><label class="min-w-0 flex-1 text-xs font-bold text-slate-700">Search applicant or program<input v-model="search" type="search" maxlength="100" class="mt-2 h-11 w-full rounded-sm border border-slate-300 px-3 text-sm font-normal"></label><label class="w-56 text-xs font-bold text-slate-700">Status<select v-model="status" class="mt-2 h-11 w-full rounded-sm border border-slate-300 bg-white px-3 text-sm font-normal"><option value="pending">Awaiting review</option><option value="approved">Approved exceptions</option><option value="declined">Declined requests</option><option value="all">All requests</option></select></label></div>
            <div v-if="loading" class="px-5 py-16 text-center text-sm text-slate-500">Loading eligibility requests...</div>
            <div v-else class="staff-table-viewport"><table class="w-full min-w-[52rem] table-fixed text-left"><colgroup><col><col class="w-52"><col class="w-44"><col class="w-36"></colgroup><thead class="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-600"><tr><th class="px-5 py-3">Applicant and program</th><th class="px-5 py-3">Submitted</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in rows" :key="item.id" class="align-middle"><td class="px-5 py-4"><div class="flex items-center gap-3"><StaffTableIdentity :src="item.photo_url || ''" class="h-11 w-11" /><div class="min-w-0"><p class="text-sm font-bold text-slate-950">{{ item.applicant }}</p><p class="mt-1 text-xs leading-5 text-slate-500">{{ item.program }}</p></div></div></td><td class="px-5 py-4 text-sm text-slate-600">{{ item.submitted_at }}</td><td class="px-5 py-4"><span class="inline-flex rounded-sm border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-bold text-slate-700">{{ labels[item.status] }}</span></td><td class="px-5 py-4 text-right"><button type="button" :disabled="busy" class="h-10 rounded-sm bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-50" @click="open(item.id)">Open review</button></td></tr><tr v-if="!rows.length"><td colspan="4" class="px-5 py-16 text-center text-sm text-slate-500">No matching eligibility review requests.</td></tr></tbody></table></div>
            <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-4 text-xs font-semibold text-slate-600"><p>{{ pagination.total }} requests</p><div class="flex gap-2"><button type="button" :disabled="loading || pagination.current_page <= 1" class="rounded-sm border border-slate-300 bg-white px-3 py-2 disabled:opacity-40" @click="load(pagination.current_page - 1)">Previous</button><button type="button" :disabled="loading || pagination.current_page >= pagination.last_page" class="rounded-sm border border-slate-300 bg-white px-3 py-2 disabled:opacity-40" @click="load(pagination.current_page + 1)">Next</button></div></footer>
        </section>
    </section></main>
    <Teleport to="body"><div v-if="record" class="fixed inset-0 z-[2500] flex items-center justify-center bg-slate-950/70 p-5" @click.self="close"><form ref="dialog" class="flex max-h-[92dvh] w-full max-w-4xl flex-col overflow-hidden rounded-md bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="eligibility-provider-review-title" tabindex="-1" @keydown="handleKeydown" @submit.prevent="save">
        <header class="flex items-start justify-between gap-4 border-t-4 border-amber-400 bg-slate-950 px-6 py-5 text-white"><div><p class="text-xs font-bold uppercase tracking-widest text-amber-300">Eligibility reconsideration</p><h2 id="eligibility-provider-review-title" class="mt-1 text-xl font-bold">{{ record.applicant }}</h2><p class="mt-1 text-sm text-slate-300">{{ record.program }}</p></div><button type="button" :disabled="busy" aria-label="Close eligibility review" class="grid h-9 w-9 place-items-center rounded border border-white/30" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">
            <p class="border-l-2 border-amber-400 bg-amber-50 px-4 py-3 text-sm leading-6 text-slate-700">Approval only allows submitting an application. The profile stays unchanged, eligibility discrepancies remain visible, and selection is a separate decision.</p>
            <p v-if="!record.context_matches" class="text-sm font-bold text-rose-700">The profile or program rules changed. Do not approve this outdated comparison; the applicant must send a new request.</p>
            <section><h3 class="text-sm font-bold text-slate-950">Required differences</h3><dl class="mt-2 divide-y divide-slate-200"><div v-for="criterion in record.criteria" :key="criterion.key" class="grid grid-cols-[13rem_1fr] gap-4 py-3 text-sm"><dt class="font-bold text-slate-900">{{ criterion.label }}</dt><dd class="text-slate-600"><p>Applicant: {{ criterion.student_value || 'Not provided' }}</p><p class="mt-1">Program: {{ criterion.requirement }}</p></dd></div></dl></section>
            <section><h3 class="text-sm font-bold text-slate-950">Applicant explanation</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ record.explanation }}</p><button v-if="record.evidence" type="button" class="mt-3 inline-flex h-10 items-center gap-2 rounded-sm border border-slate-300 px-3 text-xs font-bold text-slate-700" @click="viewEvidence"><i class="fa-regular fa-image" aria-hidden="true"></i>View supporting photo or document</button><p v-else class="mt-2 text-xs text-slate-500">No supporting file was attached.</p></section>
            <fieldset v-if="record.status === 'pending'" :disabled="busy" class="space-y-4 border-t border-slate-200 pt-5"><legend class="pt-3 text-sm font-bold text-slate-950">Record a decision</legend><label class="block text-sm font-bold text-slate-700">Outcome<select v-model="decision" required class="mt-2 h-11 w-full rounded-sm border border-slate-300 bg-white px-3 font-normal"><option value="" disabled>Select outcome</option><option value="approved" :disabled="!record.can_approve">Approve exception to apply</option><option value="declined">Decline request</option></select></label><label class="block text-sm font-bold text-slate-700">Reason shared with the applicant<textarea v-model="note" required minlength="10" maxlength="1500" rows="3" class="mt-2 w-full rounded-sm border border-slate-300 px-3 py-2 font-normal leading-6"></textarea></label><label v-if="record.evidence" class="flex items-start gap-3 text-sm text-slate-700"><input v-model="reviewed" type="checkbox" required class="mt-1 h-4 w-4">I reviewed the supporting file alongside the explanation and eligibility differences.</label></fieldset>
            <section v-else class="border-t border-slate-200 pt-4"><p class="text-sm font-bold">{{ record.status_label }}</p><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ record.decision_note }}</p><p class="mt-2 text-xs text-slate-500">{{ record.reviewer }} · {{ record.decided_at }}</p></section>
        </div><footer class="flex shrink-0 justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" :disabled="busy" class="h-11 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold" @click="close">Close</button><button v-if="record.status === 'pending'" type="submit" :disabled="busy || !decision || note.trim().length < 10 || (record.evidence && !reviewed)" class="h-11 rounded-sm bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-50">{{ busy ? 'Saving...' : 'Review decision' }}</button></footer>
    </form></div></Teleport>
    <FeedbackAlertModal v-model="error" title="Eligibility review" /><FilePreviewModal :file="preview" title="Eligibility supporting evidence" @close="preview = null" />
</template>
