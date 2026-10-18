<script setup>
import { computed, onMounted, ref } from 'vue';
import FilePreviewModal from '../../../components/FilePreviewModal.vue';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const caseId = window.location.pathname.split('/').filter(Boolean).pop();
const loading = ref(true);
const saving = ref(false);
const errorMessage = ref('');
const actionError = ref('');
const confirmation = ref('');
const report = ref(null);
const responseText = ref('');
const previewAttachment = ref(null);

const backUrl = computed(() => ({ needs_response: '/admin/workspaces/support/needs-response', waiting: '/admin/workspaces/support/waiting', resolved: '/admin/workspaces/support/resolved' }[report.value?.state] ?? '/admin/workspaces/support'));
const canResolve = computed(() => report.value?.admin_status === 'open');
const responseLength = computed(() => responseText.value.trim().length);

async function loadCase() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/admin/workspaces/support/cases/${caseId}/data`);
        report.value = response.data.report;
        responseText.value = response.data.report.admin_response || '';
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'This support case could not be loaded.';
    } finally {
        loading.value = false;
    }
}

async function updateStatus(status) {
    if (status === 'resolved' && responseLength.value < 10) {
        actionError.value = 'Add a short response of at least 10 characters before completing the case.';
        return;
    }
    saving.value = true;
    actionError.value = '';
    confirmation.value = '';
    try {
        const response = await window.axios.patch(`/admin/reports/${caseId}/status`, { status, admin_response: status === 'resolved' ? responseText.value.trim() : null });
        confirmation.value = response.data.message ?? 'Case updated.';
        await loadCase();
    } catch (error) {
        actionError.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0] ?? error.response?.data?.message ?? 'The case could not be updated.';
    } finally {
        saving.value = false;
    }
}

onMounted(loadCase);
</script>

<template>
    <AdminWorkspacePage workspace="support_officer">
        <a :href="backUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to support cases</a>

        <AdminWorkspaceHeader eyebrow="Support case" :title="report?.subject || 'Case record'" description="Review the concern, coordination state, and recorded response." icon="fa-solid fa-life-ring">
            <template v-if="report" #actions><span :class="['inline-flex px-3 py-2 text-xs font-bold uppercase tracking-wide', report.state === 'resolved' ? 'bg-emerald-100 text-emerald-800' : report.state === 'waiting' ? 'bg-slate-200 text-slate-700' : 'bg-amber-100 text-amber-900']">{{ report.state_label }}</span></template>
        </AdminWorkspaceHeader>

        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading case record...</div>
        <p v-else-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <div v-else-if="report" class="mt-4 space-y-4">
            <section class="admin-panel overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.17em] text-amber-700">Reporter</p><div class="mt-2 flex items-center justify-between gap-5"><div><h2 class="text-lg font-black text-slate-950">{{ report.reporter.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ report.reporter.type }} · {{ report.reporter.email }}<span v-if="report.contact_number"> · {{ report.contact_number }}</span></p></div><p class="text-right text-xs font-semibold text-slate-500">Submitted<br><span class="text-sm text-slate-800">{{ report.submitted_at }}</span></p></div></header>
                <dl class="grid grid-cols-3 divide-x divide-slate-200 bg-slate-50">
                    <div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Category</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ report.category_label }}</dd></div>
                    <div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Program</dt><dd class="mt-1 truncate text-sm font-bold text-slate-900">{{ report.program?.title || 'General platform concern' }}</dd></div>
                    <div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Handling</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ report.coordination_label }}</dd></div>
                </dl>
            </section>

            <section class="admin-panel overflow-hidden">
                <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4"><div><h2 class="text-base font-black text-slate-950">Reported concern</h2><p v-if="report.context" class="mt-1 text-sm text-slate-500">Affected area: {{ report.context }}</p></div><span v-if="report.privacy_request_type_label" class="bg-amber-100 px-2.5 py-1 text-[10px] font-bold uppercase text-amber-900">{{ report.privacy_request_type_label }}</span></header>
                <p class="whitespace-pre-line px-6 py-5 text-sm leading-7 text-slate-700">{{ report.description }}</p>
                <div v-if="report.attachment" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-4"><div class="min-w-0"><p class="truncate text-sm font-bold text-slate-900"><i class="fa-solid fa-paperclip mr-2 text-amber-700" aria-hidden="true"></i>{{ report.attachment.original_name }}</p><p class="mt-1 text-xs text-slate-500">Submitted evidence</p></div><button type="button" class="grid h-9 w-9 shrink-0 place-items-center border border-slate-300 bg-white text-slate-700 hover:border-slate-950" aria-label="Preview attachment" title="Preview attachment" @click="previewAttachment = report.attachment"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></button></div>
            </section>

            <section v-if="report.requires_provider" class="admin-panel overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-black text-slate-950">Provider coordination</h2><p class="mt-1 text-sm text-slate-500">The case closes after both teams complete their part.</p></header>
                <div class="px-6 py-4"><div class="flex items-center justify-between"><p class="text-sm font-bold text-slate-900">Provider status</p><span :class="['px-2.5 py-1 text-xs font-bold uppercase', report.provider_status === 'resolved' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900']">{{ report.provider_status_label }}</span></div><p v-if="report.provider_response" class="mt-3 border-l-2 border-slate-300 pl-4 text-sm leading-6 text-slate-600">{{ report.provider_response }}</p><p v-if="report.provider_resolved_at" class="mt-2 text-xs text-slate-500">Recorded {{ report.provider_resolved_at }}<span v-if="report.provider_resolved_by"> by {{ report.provider_resolved_by }}</span></p></div>
            </section>

            <section class="admin-panel overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-black text-slate-950">Platform response</h2><p class="mt-1 text-sm text-slate-500">Record a concise outcome for the reporter.</p></header>
                <div class="px-6 py-5">
                    <label for="admin-response" class="text-sm font-bold text-slate-800">Resolution note</label>
                    <textarea id="admin-response" v-model="responseText" rows="4" maxlength="2000" :disabled="!canResolve" placeholder="Explain what was checked and the result." class="mt-2 w-full resize-y border border-slate-300 bg-white px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950 disabled:bg-slate-50"></textarea>
                    <div class="mt-2 flex items-center justify-between text-xs text-slate-500"><span v-if="report.admin_resolved_at">Completed {{ report.admin_resolved_at }}<span v-if="report.admin_resolved_by"> by {{ report.admin_resolved_by }}</span></span><span v-else>At least 10 characters</span><span>{{ responseLength }}/2000</span></div>
                    <p v-if="actionError" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ actionError }}</p>
                    <p v-if="confirmation" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status">{{ confirmation }}</p>
                </div>
                <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4"><button v-if="canResolve" type="button" :disabled="saving" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50" @click="updateStatus('resolved')">{{ saving ? 'Saving...' : 'Complete platform response' }}</button><button v-else type="button" :disabled="saving" class="h-11 border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 hover:border-slate-950 disabled:opacity-50" @click="updateStatus('open')">{{ saving ? 'Saving...' : 'Reopen platform response' }}</button></footer>
            </section>
        </div>
    </AdminWorkspacePage>

    <FilePreviewModal :file="previewAttachment" title="Support case attachment" context="Submitted with this case" @close="previewAttachment = null" />
</template>
