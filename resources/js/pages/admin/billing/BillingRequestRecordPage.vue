<script setup>
import { computed, onMounted, ref } from 'vue';
import FilePreviewModal from '../../../components/FilePreviewModal.vue';
import { formatFileSize } from '../../../support/display';
import AdminWorkspaceHeader from '../components/AdminWorkspaceHeader.vue';
import AdminWorkspacePage from '../components/AdminWorkspacePage.vue';

const purchaseId = document.getElementById('app')?.dataset.purchaseId;
const segments = window.location.pathname.split('/').filter(Boolean);
const section = ['delivery', 'files', 'updates', 'meeting'].includes(segments.at(-1)) ? segments.at(-1) : 'overview';
const baseUrl = `/admin/workspaces/billing/requests/${purchaseId}`;
const loading = ref(true);
const saving = ref(false);
const uploading = ref(false);
const errorMessage = ref('');
const actionError = ref('');
const confirmation = ref('');
const purchase = ref(null);
const assignees = ref([]);
const statusOptions = ref([]);
const previewFile = ref(null);
const workflow = ref({ fulfillment_status: 'ready', assigned_to: '', priority: 'normal', target_due_at: '', milestones: [], fulfillment_notes: '', provider_update: '', internal_note: '' });
const updateForm = ref({ kind: 'progress_update', message: '' });
const meetingNote = ref('');
const minimumTargetDate = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000).toISOString().slice(0, 10);

const tabs = computed(() => [
    { key: 'overview', label: 'Overview', icon: 'fa-solid fa-clipboard', href: baseUrl },
    { key: 'delivery', label: 'Delivery plan', icon: 'fa-solid fa-list-check', href: `${baseUrl}/delivery` },
    { key: 'files', label: 'Files', icon: 'fa-solid fa-folder-open', href: `${baseUrl}/files`, count: purchase.value?.files?.length ?? 0 },
    { key: 'updates', label: 'Updates', icon: 'fa-solid fa-message', href: `${baseUrl}/updates`, count: purchase.value?.updates?.length ?? 0 },
    { key: 'meeting', label: 'Meeting', icon: 'fa-solid fa-calendar-check', href: `${baseUrl}/meeting`, alert: purchase.value?.meeting_status === 'requested' },
]);
const supportingFiles = computed(() => purchase.value?.files?.filter((file) => file.category === 'supporting') ?? []);
const deliverables = computed(() => purchase.value?.files?.filter((file) => file.category === 'deliverable') ?? []);
const completedMilestones = computed(() => workflow.value.milestones.filter((item) => item.completed).length);

function statusLabel(value) { return { queued: 'Ready to start', ready: 'Ready to start', needs_information: 'Needs information', in_progress: 'In progress', provider_review: 'Provider review', completed: 'Completed' }[value] ?? value; }
function statusClass(value) { if (value === 'completed') return 'bg-emerald-100 text-emerald-800'; if (['needs_information', 'provider_review'].includes(value)) return 'bg-amber-100 text-amber-900'; return 'bg-slate-200 text-slate-800'; }
function dateTime(value) { return value ? new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value)) : 'Not set'; }
function dateInput(value) { if (!value) return ''; const date = new Date(value); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10); }
function money(amount, currency = 'PHP') { return new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format(Number(amount ?? 0) / 100); }

function applyPurchase(payload) {
    purchase.value = payload;
    workflow.value = {
        fulfillment_status: payload.fulfillment_status === 'queued' ? 'ready' : payload.fulfillment_status,
        assigned_to: payload.assigned_to ?? '',
        priority: payload.priority ?? 'normal',
        target_due_at: dateInput(payload.target_due_at),
        milestones: (payload.milestones ?? []).map((item) => ({ ...item })),
        fulfillment_notes: payload.fulfillment_notes ?? '',
        provider_update: '',
        internal_note: '',
    };
    meetingNote.value = payload.meeting_admin_note ?? '';
}

async function loadRequest() {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/admin/billing/${purchaseId}/data`);
        assignees.value = response.data.assignees ?? [];
        statusOptions.value = response.data.status_options ?? [];
        applyPurchase(response.data.purchase);
    } catch (error) { errorMessage.value = error.response?.data?.message ?? 'The service request could not be loaded.'; }
    finally { loading.value = false; }
}

function beginAction() { saving.value = true; actionError.value = ''; confirmation.value = ''; }
function actionFailed(error, fallback) { actionError.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0] ?? error.response?.data?.message ?? fallback; }

async function saveDelivery() {
    if (saving.value) return;
    beginAction();
    try {
        const response = await window.axios.patch(`/admin/billing/${purchaseId}/fulfillment`, {
            ...workflow.value,
            assigned_to: workflow.value.assigned_to || null,
            target_due_at: workflow.value.target_due_at || null,
            provider_update: workflow.value.provider_update.trim() || null,
            internal_note: workflow.value.internal_note.trim() || null,
        });
        applyPurchase(response.data.purchase);
        confirmation.value = response.data.message;
    } catch (error) { actionFailed(error, 'The delivery plan could not be saved.'); }
    finally { saving.value = false; }
}

async function addUpdate() {
    if (!updateForm.value.message.trim() || saving.value) return;
    beginAction();
    try {
        const response = await window.axios.post(`/admin/billing/${purchaseId}/updates`, { ...updateForm.value, message: updateForm.value.message.trim() });
        updateForm.value.message = '';
        applyPurchase(response.data.purchase);
        confirmation.value = response.data.message;
    } catch (error) { actionFailed(error, 'The update could not be added.'); }
    finally { saving.value = false; }
}

async function decideMeeting(meetingStatus) {
    if (saving.value) return;
    beginAction();
    try {
        const response = await window.axios.patch(`/admin/billing/${purchaseId}/meeting`, { meeting_status: meetingStatus, meeting_admin_note: meetingNote.value.trim() || null });
        applyPurchase(response.data.purchase);
        confirmation.value = response.data.message;
    } catch (error) { actionFailed(error, 'The meeting decision could not be saved.'); }
    finally { saving.value = false; }
}

async function uploadDeliverable(event) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file || uploading.value) return;
    uploading.value = true;
    actionError.value = '';
    const data = new FormData(); data.append('service_file', file);
    try {
        const response = await window.axios.post(`/admin/billing/${purchaseId}/deliverables`, data);
        applyPurchase(response.data.purchase);
        confirmation.value = response.data.message;
    } catch (error) { actionFailed(error, 'The deliverable could not be uploaded.'); }
    finally { uploading.value = false; input.value = ''; }
}

onMounted(loadRequest);
</script>

<template>
    <AdminWorkspacePage workspace="billing_officer">
        <a href="/admin/workspaces/billing" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to billing overview</a>
        <AdminWorkspaceHeader eyebrow="Service request" :title="purchase?.plan_name || 'Request record'" :description="purchase ? `${purchase.provider.name} · ${purchase.reference_number}` : 'Loading request details'" icon="fa-solid fa-file-invoice-dollar">
            <template v-if="purchase" #actions><span :class="['inline-flex px-3 py-2 text-xs font-bold uppercase tracking-wide', statusClass(purchase.fulfillment_status)]">{{ statusLabel(purchase.fulfillment_status) }}</span></template>
        </AdminWorkspaceHeader>

        <div v-if="loading" class="admin-panel mt-4 px-6 py-14 text-center text-sm font-semibold text-slate-500">Loading service request...</div>
        <p v-else-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>

        <template v-else-if="purchase">
            <nav class="admin-panel mt-4 grid grid-cols-5 overflow-hidden" aria-label="Service request sections">
                <a v-for="tab in tabs" :key="tab.key" :href="tab.href" :aria-current="section === tab.key ? 'page' : undefined" :class="['flex h-14 items-center justify-center gap-2 border-r border-slate-200 px-3 text-sm font-bold last:border-r-0', section === tab.key ? 'bg-slate-950 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']"><i :class="tab.icon" aria-hidden="true"></i>{{ tab.label }}<span v-if="tab.alert" class="h-2 w-2 bg-amber-400" title="Action needed"></span><span v-else-if="tab.count" class="bg-slate-200 px-1.5 py-0.5 text-[10px] text-slate-700">{{ tab.count }}</span></a>
            </nav>

            <p v-if="actionError" class="mt-4 border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ actionError }}</p>
            <p v-if="confirmation" class="mt-4 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status">{{ confirmation }}</p>

            <section v-if="section === 'overview'" class="admin-panel mt-4 overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-black text-slate-950">Request overview</h2><p class="mt-1 text-sm text-slate-500">The provider, payment, and requested outcome.</p></header>
                <dl class="grid grid-cols-4 divide-x divide-slate-200 bg-slate-50"><div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Provider</dt><dd class="mt-1 truncate text-sm font-bold text-slate-900">{{ purchase.provider.name }}</dd></div><div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Payment</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ money(purchase.amount, purchase.currency) }}</dd></div><div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Paid</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ dateTime(purchase.paid_at) }}</dd></div><div class="px-5 py-3"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Assigned to</dt><dd class="mt-1 truncate text-sm font-bold text-slate-900">{{ purchase.assigned_to_name || 'Unassigned' }}</dd></div></dl>
                <div class="divide-y divide-slate-200">
                    <article class="px-6 py-5"><h3 class="text-sm font-black text-slate-950">Service</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ purchase.plan_description || purchase.plan_name }}</p></article>
                    <article class="px-6 py-5"><h3 class="text-sm font-black text-slate-950">Provider request</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ purchase.request_summary || 'No request summary was supplied.' }}</p></article>
                    <article class="px-6 py-5"><h3 class="text-sm font-black text-slate-950">Expected outcome</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ purchase.requested_outcome || 'No expected outcome was supplied.' }}</p></article>
                </div>
            </section>

            <form v-else-if="section === 'delivery'" class="admin-panel mt-4 overflow-hidden" @submit.prevent="saveDelivery">
                <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-black text-slate-950">Delivery plan</h2><p class="mt-1 text-sm text-slate-500">Assign the request, track milestones, and choose the next state.</p></header>
                <div class="space-y-6 px-6 py-5">
                    <div class="grid grid-cols-4 gap-4"><label class="text-sm font-bold text-slate-700">Status<select v-model="workflow.fulfillment_status" :disabled="purchase.fulfillment_status === 'completed'" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label><label class="text-sm font-bold text-slate-700">Assignee<select v-model="workflow.assigned_to" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="">Unassigned</option><option v-for="person in assignees" :key="person.id" :value="person.id">{{ person.name }}</option></select></label><label class="text-sm font-bold text-slate-700">Priority<select v-model="workflow.priority" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label><label class="text-sm font-bold text-slate-700">Target date<input v-model="workflow.target_due_at" type="date" :min="minimumTargetDate" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"></label></div>
                    <fieldset><div class="flex items-center justify-between"><legend class="text-sm font-black text-slate-900">Milestones</legend><span class="text-xs font-bold text-slate-500">{{ completedMilestones }} of {{ workflow.milestones.length }} complete</span></div><div class="mt-3 divide-y divide-slate-200 border border-slate-200"><label v-for="item in workflow.milestones" :key="item.id" class="flex cursor-pointer items-center gap-3 px-4 py-3"><input v-model="item.completed" type="checkbox" class="h-4 w-4 accent-slate-950"><span class="text-sm font-semibold text-slate-700">{{ item.label }}</span></label><p v-if="!workflow.milestones.length" class="px-4 py-5 text-sm text-slate-500">No milestones are configured.</p></div></fieldset>
                    <label class="block text-sm font-bold text-slate-700">Status summary <span class="font-normal text-slate-500">visible to provider</span><textarea v-model="workflow.fulfillment_notes" rows="3" maxlength="2000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" placeholder="Briefly describe current progress."></textarea></label>
                    <label v-if="['needs_information', 'provider_review'].includes(workflow.fulfillment_status)" class="block text-sm font-bold text-slate-700">Message to provider <span class="font-normal text-slate-500">required</span><textarea v-model="workflow.provider_update" rows="3" maxlength="2000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" :placeholder="workflow.fulfillment_status === 'needs_information' ? 'Explain what information is needed.' : 'Summarize completed work for review.'"></textarea></label>
                    <label class="block text-sm font-bold text-slate-700">Internal note <span class="font-normal text-slate-500">optional</span><textarea v-model="workflow.internal_note" rows="2" maxlength="2000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" placeholder="Only administrators can see this note."></textarea></label>
                </div>
                <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="submit" :disabled="saving || purchase.fulfillment_status === 'completed'" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40">{{ saving ? 'Saving...' : 'Save delivery plan' }}</button></footer>
            </form>

            <section v-else-if="section === 'files'" class="admin-panel mt-4 overflow-hidden">
                <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4"><div><h2 class="text-lg font-black text-slate-950">Service files</h2><p class="mt-1 text-sm text-slate-500">Review provider files and share deliverables.</p></div><label v-if="purchase.fulfillment_status !== 'completed'" class="inline-flex h-10 cursor-pointer items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white"><i class="fa-solid fa-upload" aria-hidden="true"></i>{{ uploading ? 'Uploading...' : 'Upload deliverable' }}<input type="file" class="sr-only" :disabled="uploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="uploadDeliverable"></label></header>
                <div><section class="border-b border-slate-200 px-6 py-5"><h3 class="text-sm font-black text-slate-900">Provider files</h3><div class="mt-3 divide-y divide-slate-200 border border-slate-200"><button v-for="file in supportingFiles" :key="file.id" type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50" @click="previewFile = file"><i class="fa-solid fa-file-lines text-slate-400" aria-hidden="true"></i><span class="min-w-0 flex-1"><strong class="block truncate text-sm text-slate-900">{{ file.original_name }}</strong><span class="text-xs text-slate-500">{{ formatFileSize(file.size) }} · {{ file.uploaded_by }}</span></span><i class="fa-solid fa-eye text-xs text-slate-400" aria-hidden="true"></i></button><p v-if="!supportingFiles.length" class="px-4 py-6 text-sm text-slate-500">No provider files uploaded.</p></div></section><section class="px-6 py-5"><h3 class="text-sm font-black text-slate-900">Deliverables</h3><div class="mt-3 divide-y divide-slate-200 border border-slate-200"><button v-for="file in deliverables" :key="file.id" type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50" @click="previewFile = file"><i class="fa-solid fa-file-circle-check text-emerald-700" aria-hidden="true"></i><span class="min-w-0 flex-1"><strong class="block truncate text-sm text-slate-900">{{ file.original_name }}</strong><span class="text-xs text-slate-500">{{ formatFileSize(file.size) }} · {{ file.uploaded_by }}</span></span><i class="fa-solid fa-eye text-xs text-slate-400" aria-hidden="true"></i></button><p v-if="!deliverables.length" class="px-4 py-6 text-sm text-slate-500">No deliverables uploaded.</p></div></section></div>
            </section>

            <section v-else-if="section === 'updates'" class="admin-panel mt-4 overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-black text-slate-950">Updates</h2><p class="mt-1 text-sm text-slate-500">Keep provider communication and internal notes in one timeline.</p></header>
                <div class="divide-y divide-slate-200"><article v-for="entry in [...(purchase.updates || [])].reverse()" :key="entry.id" :class="['px-6 py-4', entry.visible_to_provider ? 'bg-white' : 'bg-amber-50/50']"><div class="flex items-center gap-2"><p class="text-sm font-bold text-slate-900">{{ entry.actor_name }}</p><span v-if="!entry.visible_to_provider" class="bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-900">Internal</span><span class="ml-auto text-xs text-slate-500">{{ dateTime(entry.created_at) }}</span></div><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ entry.message }}</p></article><p v-if="!purchase.updates?.length" class="px-6 py-10 text-center text-sm text-slate-500">No updates recorded.</p></div>
                <form class="border-t border-slate-200 bg-slate-50 px-6 py-5" @submit.prevent="addUpdate"><div class="grid grid-cols-[14rem_minmax(0,1fr)_auto] items-end gap-3"><label class="text-sm font-bold text-slate-700">Update type<select v-model="updateForm.kind" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="progress_update">Provider update</option><option value="clarification_request">Request information</option><option value="internal_note">Internal note</option></select></label><label class="text-sm font-bold text-slate-700">Message<textarea v-model="updateForm.message" rows="2" maxlength="2000" class="mt-2 w-full border border-slate-300 bg-white px-3 py-2 font-normal" placeholder="Add a clear update."></textarea></label><button type="submit" :disabled="saving || !updateForm.message.trim()" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-40">Add update</button></div></form>
            </section>

            <section v-else class="admin-panel mt-4 overflow-hidden">
                <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-lg font-black text-slate-950">Meeting request</h2><p class="mt-1 text-sm text-slate-500">Review the provider's proposed service meeting.</p></header>
                <div v-if="purchase.meeting_scheduled_for" class="px-6 py-5"><dl class="grid grid-cols-3 divide-x divide-slate-200 border border-slate-200 bg-slate-50"><div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase text-slate-500">Schedule</dt><dd class="mt-1 text-sm font-bold text-slate-900">{{ dateTime(purchase.meeting_scheduled_for) }}</dd></div><div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase text-slate-500">Method</dt><dd class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.meeting_mode }}</dd></div><div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase text-slate-500">Status</dt><dd class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.meeting_status }}</dd></div></dl><div class="mt-5"><h3 class="text-sm font-black text-slate-900">Purpose</h3><p class="mt-2 text-sm leading-6 text-slate-600">{{ purchase.meeting_purpose }}</p></div><label v-if="purchase.meeting_status === 'requested'" class="mt-5 block text-sm font-bold text-slate-700">Response note <span class="font-normal text-slate-500">required when declining</span><textarea v-model="meetingNote" rows="3" maxlength="1000" class="mt-2 w-full border border-slate-300 px-4 py-3 font-normal" placeholder="Add meeting instructions or explain why another time is needed."></textarea></label><p v-else-if="purchase.meeting_admin_note" class="mt-5 border-l-2 border-slate-300 pl-4 text-sm leading-6 text-slate-600">{{ purchase.meeting_admin_note }}</p></div>
                <div v-else class="px-6 py-12 text-center"><i class="fa-solid fa-calendar text-2xl text-slate-300" aria-hidden="true"></i><p class="mt-3 font-bold text-slate-900">No meeting requested</p><p class="mt-1 text-sm text-slate-500">A provider request will appear here for confirmation.</p></div>
                <footer v-if="purchase.meeting_status === 'requested'" class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" :disabled="saving" class="h-11 border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700" @click="decideMeeting('declined')">Decline</button><button type="button" :disabled="saving" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white" @click="decideMeeting('confirmed')">Confirm meeting</button></footer>
            </section>
        </template>
    </AdminWorkspacePage>

    <FilePreviewModal v-if="previewFile" :file="previewFile" :title="previewFile.original_name" context="Provider service file" @close="previewFile = null" />
</template>
