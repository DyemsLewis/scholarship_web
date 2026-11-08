<script setup>
import { staffQueueReturn } from '../../../support/staffQueueState.js';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';
import { computed, onMounted, ref } from 'vue';
import { useAdminResource } from '../../../composables/useAdminResource.js';
import { formatFileSize } from '../../../support/display.js';
import AdminDialog from '../components/AdminDialog.vue';
import AdminLoadState from '../components/AdminLoadState.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const caseId = (window.portalPathname?.() ?? window.location.pathname).split('/').filter(Boolean).pop();
const resource = useAdminResource(async ({ signal }) => {
    const response = await window.axios.get(`/admin/workspaces/support/cases/${caseId}/data`, { signal });
    if (!response.data?.report) throw new Error('Support case unavailable.');
    return response.data.report;
}, { failureMessage: 'This support case could not be loaded.' });
const { isLoading: loading, errorMessage } = resource;
const saving = ref(false);
const actionError = ref('');
const confirmation = ref('');
const report = ref(null);
const responseText = ref('');
const reopenOpen = ref(false);

const backUrl = computed(() => ({ needs_response: '/admin/workspaces/support/needs-response', waiting: '/admin/workspaces/support/waiting', resolved: '/admin/workspaces/support/resolved' }[report.value?.state] ?? '/admin/workspaces/support'));
const canResolve = computed(() => report.value?.admin_status === 'open');
const canReopen = computed(() => report.value?.admin_status === 'resolved');
const responseLength = computed(() => responseText.value.trim().length);
const ready = computed(() => report.value && !loading.value && !errorMessage.value);
const canSave = computed(() => ready.value && !saving.value && canResolve.value && responseLength.value >= 10 && responseLength.value <= 2000);

async function loadCase() {
    if (saving.value) return;
    const payload = await resource.load();
    if (!payload) return;
    report.value = payload;
    responseText.value = payload.admin_response || '';
}

async function updateStatus(status) {
    if (!ready.value || saving.value || !['open', 'resolved'].includes(status)) return;
    if ((status === 'resolved' && !canResolve.value) || (status === 'open' && !canReopen.value)) return;
    if (status === 'resolved' && (responseLength.value < 10 || responseLength.value > 2000)) {
        actionError.value = 'Add a response of 10 to 2000 characters before completing the platform response.';
        return;
    }
    if (status === 'resolved' && !await confirmOutcome({
        record: report.value.subject || 'Platform support case',
        outcome: 'Resolved',
        consequence: 'Your response will be saved and this support case will be marked resolved.',
    }, () => ready.value && !saving.value && canResolve.value && responseLength.value >= 10 && responseLength.value <= 2000)) return;
    saving.value = true;
    actionError.value = '';
    confirmation.value = '';
    try {
        const response = await window.axios.patch(`/admin/reports/${caseId}/status`, { status, admin_response: status === 'resolved' ? responseText.value.trim() : null }, { portalToast: false });
        report.value = response.data.support_case;
        responseText.value = report.value.admin_response || '';
        confirmation.value = response.data.message ?? 'Case updated.';
        reopenOpen.value = false;
    } catch (error) {
        actionError.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0] ?? error.response?.data?.message ?? 'The case could not be updated.';
    } finally {
        saving.value = false;
    }
}

function openReopen() {
    if (!ready.value || saving.value || !canReopen.value) return;
    actionError.value = '';
    reopenOpen.value = true;
}

function closeReopen() {
    if (saving.value) return;
    reopenOpen.value = false;
    actionError.value = '';
}

onMounted(loadCase);
const originatingQueue = staffQueueReturn(window, 'adminSupport');
</script>

<template>
    <AdminWorkspacePage workspace="support_officer" class="admin-support-workspace">
        <a :href="originatingQueue || backUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to support cases</a>

        <AdminWorkspaceHeader eyebrow="Platform support" title="Case review" description="Review the reported issue and record the platform response." icon="fa-solid fa-life-ring">
            <template v-if="ready" #actions><span :class="['inline-flex px-3 py-2 text-xs font-bold uppercase tracking-wide', report.state === 'resolved' ? 'bg-emerald-100 text-emerald-800' : report.state === 'waiting' ? 'bg-slate-200 text-slate-700' : 'bg-amber-100 text-amber-900']">{{ report.state_label }}</span></template>
        </AdminWorkspaceHeader>

        <section v-if="loading || errorMessage" class="admin-panel mt-4"><AdminLoadState :loading="loading" :error="errorMessage" loading-message="Loading case record..." @retry="loadCase" /></section>

        <div v-else-if="report" class="mt-4 space-y-4">
            <section class="admin-panel overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-5">
                    <div class="flex items-start justify-between gap-6">
                        <div class="flex min-w-0 items-start gap-4">
                            <span class="relative grid h-12 w-12 shrink-0 place-items-center overflow-hidden border border-slate-200 bg-slate-100 text-slate-500">
                                <i :class="report.reporter.type === 'Provider team' ? 'fa-solid fa-building-columns' : 'fa-solid fa-user'" aria-hidden="true"></i>
                                <img v-if="report.reporter.image_url" :src="report.reporter.image_url" :alt="`${report.reporter.name} profile`" :class="['absolute inset-0 h-full w-full bg-white', report.reporter.image_fit === 'contain' ? 'object-contain p-1' : 'object-cover']" @error="$event.currentTarget.remove()">
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-amber-700">Reported concern</p>
                                <h2 class="support-text mt-1 text-lg font-black text-slate-950">{{ report.subject }}</h2>
                                <p class="support-text mt-1 text-sm text-slate-500">{{ report.reporter.name }} · {{ report.reporter.email }}<span v-if="report.contact_number"> · {{ report.contact_number }}</span></p>
                                <p v-if="report.reporter.organization_name" class="support-text mt-1 text-xs text-slate-500">{{ report.reporter.organization_name }}</p>
                            </div>
                        </div>
                        <p class="shrink-0 text-right text-xs font-semibold text-slate-500">Submitted<br><span class="text-sm text-slate-800">{{ report.submitted_at }}</span></p>
                    </div>
                </header>
                <dl class="grid grid-cols-3 divide-x divide-slate-200 bg-slate-50">
                    <div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Category</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ report.category_label }}</dd></div>
                    <div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Program</dt><dd class="support-text mt-1 text-sm font-bold text-slate-900">{{ report.program?.title || 'General platform concern' }}</dd></div>
                    <div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Handling</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ report.coordination_label }}</dd></div>
                </dl>
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between gap-4"><p v-if="report.context_label" class="support-text text-xs font-bold uppercase tracking-wide text-slate-500">Affected area: {{ report.context_label }}</p><span v-if="report.privacy_request_type_label" class="bg-amber-100 px-2.5 py-1 text-[10px] font-bold uppercase text-amber-900">{{ report.privacy_request_type_label }}</span></div>
                    <p class="mt-3 whitespace-pre-line break-words text-sm leading-7 text-slate-700 [overflow-wrap:anywhere]">{{ report.description }}</p>
                </div>
                <div v-if="report.attachment" class="flex items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-4"><div class="min-w-0"><p class="support-text text-sm font-bold text-slate-900" data-file-name><i class="fa-solid fa-paperclip mr-2 text-amber-700" aria-hidden="true"></i>{{ report.attachment.original_name }}</p><p class="mt-1 text-xs text-slate-500">Submitted evidence<span v-if="report.attachment.size"> · {{ formatFileSize(report.attachment.size) }}</span></p></div><a :href="report.attachment.view_url" data-file-preview="true" :data-file-name="report.attachment.original_name" :data-download-url="report.attachment.download_url" class="grid h-10 w-10 shrink-0 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950" :aria-label="`Preview ${report.attachment.original_name}`" title="Preview attachment"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a></div>
            </section>

            <section v-if="report.requires_provider" class="admin-panel overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-building-circle-check" aria-hidden="true"></i></span><div><h2 class="text-base font-black text-slate-950">Provider coordination</h2><p class="mt-0.5 text-sm text-slate-500">{{ report.admin_status === 'not_required' ? 'Only the provider response is required for this case.' : 'The case closes after both teams complete their part.' }}</p></div></div></header>
                <div class="px-6 py-4"><div class="flex items-center justify-between gap-4"><p class="support-text text-sm font-bold text-slate-900">{{ report.provider_name || 'Provider team' }}</p><span :class="['shrink-0 px-2.5 py-1 text-xs font-bold uppercase', report.provider_status === 'resolved' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900']">{{ report.provider_status_label }}</span></div><p v-if="report.provider_response" class="mt-3 whitespace-pre-line break-words border-l-2 border-slate-300 pl-4 text-sm leading-6 text-slate-600 [overflow-wrap:anywhere]">{{ report.provider_response }}</p><p v-else class="mt-2 text-sm text-slate-500">The provider has not recorded a response yet.</p><p v-if="report.provider_resolved_at" class="mt-2 text-xs text-slate-500">Recorded {{ report.provider_resolved_at }}<span v-if="report.provider_resolved_by"> by {{ report.provider_resolved_by }}</span></p></div>
            </section>

            <form v-if="report.admin_status !== 'not_required'" class="admin-panel overflow-hidden" :aria-busy="saving" @submit.prevent="updateStatus('resolved')">
                <header class="border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-reply" aria-hidden="true"></i></span><div><h2 class="text-base font-black text-slate-950">Platform response</h2><p class="mt-0.5 text-sm text-slate-500">Record a concise outcome for the reporter.</p></div></div></header>
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between gap-4"><label for="admin-response" class="text-sm font-bold text-slate-800">Resolution note</label><span class="text-xs font-bold text-slate-600">{{ report.admin_status_label }}</span></div>
                    <p id="admin-response-help" class="mt-1 text-sm text-slate-500">Shared with the reporter; do not include internal passwords or private account data.</p>
                    <textarea id="admin-response" v-model="responseText" rows="4" minlength="10" maxlength="2000" :required="canResolve" :disabled="saving" :readonly="!canResolve" aria-describedby="admin-response-help admin-response-length" :aria-invalid="actionError && !reopenOpen ? 'true' : undefined" placeholder="Explain what was checked and the result." class="mt-2 w-full resize-y border border-slate-300 bg-white px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950 disabled:bg-slate-50 read-only:bg-slate-50"></textarea>
                    <div id="admin-response-length" class="mt-2 flex items-center justify-between gap-4 text-xs text-slate-500"><span v-if="report.admin_resolved_at">Completed {{ report.admin_resolved_at }}<span v-if="report.admin_resolved_by"> by {{ report.admin_resolved_by }}</span></span><span v-else>At least 10 characters</span><span class="shrink-0">{{ responseLength }}/2000</span></div>
                    <p v-if="report.state === 'waiting' && report.admin_status === 'resolved'" class="mt-3 text-sm font-semibold text-slate-700">Platform work is complete. This case stays open until the provider completes its response.</p>
                    <p v-if="actionError && !reopenOpen" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ actionError }}</p>
                    <p v-if="confirmation" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status">{{ confirmation }}</p>
                </div>
                <footer v-if="canResolve || canReopen" class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4"><button v-if="canResolve" type="submit" :disabled="!canSave" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50">{{ saving ? 'Saving...' : 'Complete platform response' }}</button><button v-else type="button" :disabled="saving" class="h-11 border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-50" @click="openReopen">{{ saving ? 'Saving...' : 'Reopen platform response' }}</button></footer>
            </form>
            <section v-else class="admin-panel flex items-center gap-3 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-reply" aria-hidden="true"></i></span><div><h2 class="font-black text-slate-950">Platform response not required</h2><p class="mt-1 text-sm text-slate-600">The provider manages this concern. Its response and status are shown above.</p></div></section>
        </div>
    </AdminWorkspacePage>

    <AdminDialog :open="reopenOpen" title="Reopen platform response?" title-id="support-reopen-title" eyebrow="Platform support" description="This clears the platform response and returns it to your queue. The provider's response is kept." confirm-label="Reopen platform response" :busy="saving" :error="actionError" :confirm-disabled="!ready || !canReopen" @confirm="updateStatus('open')" @cancel="closeReopen" />
</template>
