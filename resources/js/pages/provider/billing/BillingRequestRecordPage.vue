<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { providerServiceWorkspace } from '../../../support/providerServiceWorkspaces';
import ProviderSidebar from '../ProviderSidebar.vue';

const purchaseId = document.getElementById('app')?.dataset.purchaseId ?? window.location.pathname.match(/\/provider\/billing\/(\d+)/)?.[1];
const path = window.location.pathname.replace(/\/$/, '');
const activeSection = path.endsWith('/meeting') ? 'meeting' : path.endsWith('/files') ? 'files' : path.endsWith('/updates') ? 'updates' : 'overview';
const isLoading = ref(true);
const isSaving = ref(false);
const isUploading = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const purchase = ref(null);
const briefForm = reactive({ request_summary: '', requested_outcome: '' });
const meetingForm = reactive({ meeting_scheduled_for: '', meeting_mode: 'online', meeting_purpose: '' });
const updateMessage = ref('');
const completionForm = reactive({ rating: '', feedback: '' });
const reopenReason = ref('');

const workspace = computed(() => providerServiceWorkspace(purchase.value?.plan_code));
const canWork = computed(() => purchase.value?.status === 'paid' && !['provider_review', 'completed'].includes(purchase.value?.fulfillment_status));
const canRequestMeeting = computed(() => purchase.value?.status === 'paid' && purchase.value?.fulfillment_status !== 'completed');
const canReopen = computed(() => ['provider_review', 'completed'].includes(purchase.value?.fulfillment_status));
const supportingFiles = computed(() => purchase.value?.files?.filter((file) => file.category === 'supporting') ?? []);
const deliverables = computed(() => purchase.value?.files?.filter((file) => file.category === 'deliverable') ?? []);
const completedMilestones = computed(() => purchase.value?.milestones?.filter((item) => item.completed).length ?? 0);
const minimumMeeting = computed(() => localInputDate(new Date(Date.now() + 60 * 60 * 1000).toISOString()));
const sections = computed(() => [
    { key: 'overview', label: 'Overview', icon: 'fa-solid fa-list-check', href: `/provider/billing/${purchaseId}` },
    { key: 'meeting', label: 'Meeting', icon: 'fa-regular fa-calendar', href: `/provider/billing/${purchaseId}/meeting` },
    { key: 'files', label: 'Files', icon: 'fa-regular fa-folder-open', href: `/provider/billing/${purchaseId}/files`, count: purchase.value?.files?.length ?? 0 },
    { key: 'updates', label: 'Updates', icon: 'fa-regular fa-message', href: `/provider/billing/${purchaseId}/updates`, count: purchase.value?.updates?.length ?? 0 },
]);

function money(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency, maximumFractionDigits: 0 }).format((amount || 0) / 100);
}

function dateTime(value) {
    if (!value) return 'Not set';
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value));
}

function localInputDate(value) {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

function fileSize(bytes) {
    if (!bytes) return '0 KB';
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function statusLabel(value) {
    return {
        queued: 'Waiting to start', ready: 'Waiting to start', needs_information: 'Response needed',
        in_progress: 'In progress', provider_review: 'Review delivery', completed: 'Completed',
    }[value] ?? String(value || 'pending').replaceAll('_', ' ');
}

function statusClass() {
    if (purchase.value?.fulfillment_status === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (purchase.value?.fulfillment_status === 'provider_review' || purchase.value?.fulfillment_status === 'needs_information') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function applyPurchase(payload) {
    purchase.value = payload;
    briefForm.request_summary = payload.request_summary ?? '';
    briefForm.requested_outcome = payload.requested_outcome ?? '';
    meetingForm.meeting_scheduled_for = localInputDate(payload.meeting_scheduled_for);
    meetingForm.meeting_mode = payload.meeting_mode ?? 'online';
    meetingForm.meeting_purpose = payload.meeting_purpose ?? workspace.value.meetingPurpose;
}

function actionError(error, fallback) {
    const validation = Object.values(error.response?.data?.errors ?? {})[0]?.[0];
    errorMessage.value = validation || error.response?.data?.message || fallback;
    successMessage.value = '';
}

async function loadRecord() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/billing/${purchaseId}/data`);
        applyPurchase(response.data.purchase);
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'This service request could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

async function runAction(action, successFallback) {
    isSaving.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const response = await action();
        applyPurchase(response.data.purchase);
        successMessage.value = response.data.message || successFallback;
        return true;
    } catch (error) {
        actionError(error, 'The request could not be updated.');
        return false;
    } finally {
        isSaving.value = false;
    }
}

async function saveBrief() {
    await runAction(() => window.axios.patch(`/provider/billing/${purchaseId}/request`, { ...briefForm }), 'Request brief updated.');
}

async function requestMeeting() {
    await runAction(() => window.axios.post(`/provider/billing/${purchaseId}/meeting`, { ...meetingForm }), 'Meeting request sent.');
}

async function addUpdate() {
    if (!updateMessage.value.trim()) return;
    const saved = await runAction(() => window.axios.post(`/provider/billing/${purchaseId}/updates`, { message: updateMessage.value.trim() }), 'Update sent.');
    if (saved) updateMessage.value = '';
}

async function uploadFile(event) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file) return;
    isUploading.value = true;
    errorMessage.value = '';
    const data = new FormData();
    data.append('service_file', file);
    try {
        const response = await window.axios.post(`/provider/billing/${purchaseId}/files`, data);
        applyPurchase(response.data.purchase);
        successMessage.value = response.data.message || 'File uploaded.';
    } catch (error) {
        actionError(error, 'Choose a supported file up to 10 MB.');
    } finally {
        input.value = '';
        isUploading.value = false;
    }
}

async function confirmCompletion() {
    await runAction(() => window.axios.post(`/provider/billing/${purchaseId}/confirm`, {
        rating: completionForm.rating || null,
        feedback: completionForm.feedback.trim() || null,
    }), 'Service completion confirmed.');
}

async function reopenService() {
    if (!reopenReason.value.trim()) return;
    const saved = await runAction(() => window.axios.post(`/provider/billing/${purchaseId}/reopen`, { reason: reopenReason.value.trim() }), 'Service reopened.');
    if (saved) reopenReason.value = '';
}

onMounted(loadRecord);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page" aria-labelledby="service-record-title">
            <div class="mx-auto max-w-[94rem]">
                <a href="/provider/workspaces/billing" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to request desk</a>

                <header class="mt-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                    <div class="flex items-start justify-between gap-8 px-7 py-6">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Service request</p><h1 id="service-record-title" class="mt-1 font-display text-3xl font-bold tracking-tight text-slate-950">{{ purchase?.plan_name || 'Service request' }}</h1><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{{ purchase?.plan_description || workspace.headerDescription }}</p></div>
                        <span v-if="purchase" :class="['inline-flex shrink-0 border px-3 py-2 text-[10px] font-bold uppercase tracking-wide', statusClass()]">{{ purchase.status === 'paid' ? statusLabel(purchase.fulfillment_status) : `${purchase.status} payment` }}</span>
                    </div>
                    <div v-if="purchase" class="grid grid-cols-4 divide-x divide-slate-200 border-t border-slate-200 bg-slate-50"><div class="px-6 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Amount</p><p class="mt-1 text-sm font-bold text-slate-900">{{ money(purchase.amount, purchase.currency) }}</p></div><div class="px-6 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Payment</p><p class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.status }}</p></div><div class="px-6 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Assigned to</p><p class="mt-1 text-sm font-bold text-slate-900">{{ purchase.assigned_to_name || 'Platform support queue' }}</p></div><div class="px-6 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Target</p><p class="mt-1 text-sm font-bold text-slate-900">{{ purchase.target_due_at ? dateTime(purchase.target_due_at) : 'Not scheduled' }}</p></div></div>
                </header>

                <nav v-if="purchase" class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Service request sections"><a v-for="section in sections" :key="section.key" :href="section.href" :aria-current="activeSection === section.key ? 'page' : undefined" :class="['flex h-14 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', activeSection === section.key ? 'bg-slate-950 text-white' : 'text-slate-700 hover:bg-slate-50']"><i :class="section.icon" aria-hidden="true"></i>{{ section.label }}<span v-if="section.count" :class="['px-1.5 py-0.5 text-[10px]', activeSection === section.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-600']">{{ section.count }}</span></a></nav>

                <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mt-4 border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800">{{ successMessage }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center text-sm text-slate-500">Loading service request...</div>

                <template v-else-if="purchase">
                    <template v-if="activeSection === 'overview'">
                        <section v-if="purchase.status !== 'paid'" class="mt-4 border border-amber-200 bg-amber-50 px-6 py-5 shadow-sm"><h2 class="text-base font-bold text-slate-950">Payment is {{ purchase.status }}</h2><p class="mt-2 text-sm leading-6 text-slate-600">Platform support starts only after PayMongo confirms payment.</p><div class="mt-4 flex gap-3"><a v-if="purchase.checkout_url" :href="purchase.checkout_url" class="inline-flex h-10 items-center bg-slate-950 px-4 text-sm font-bold text-white">Continue payment</a><a href="/provider/workspaces/billing/services" class="inline-flex h-10 items-center border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700">Browse services</a></div></section>

                        <section v-else class="mt-4 border border-slate-200 bg-white shadow-sm">
                            <header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-bold text-slate-950">Request brief</h2><p class="mt-1 text-xs text-slate-500">The outcome platform support is working toward.</p></header>
                            <form v-if="canWork" class="px-6 py-5" @submit.prevent="saveBrief"><label for="request-summary" class="text-sm font-bold text-slate-800">What your team needs</label><textarea id="request-summary" v-model="briefForm.request_summary" rows="4" minlength="20" maxlength="2000" required class="mt-2 w-full border border-slate-300 px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950"></textarea><label for="requested-outcome" class="mt-5 block text-sm font-bold text-slate-800">Expected outcome</label><textarea id="requested-outcome" v-model="briefForm.requested_outcome" rows="3" minlength="10" maxlength="1200" required class="mt-2 w-full border border-slate-300 px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950"></textarea><div class="mt-4 flex justify-end"><button type="submit" :disabled="isSaving" class="h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">Save brief</button></div></form>
                            <div v-else class="divide-y divide-slate-200"><div class="px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">What your team needs</p><p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ purchase.request_summary || 'No additional brief was recorded.' }}</p></div><div class="px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-400">Expected outcome</p><p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-700">{{ purchase.requested_outcome || 'No expected outcome was recorded.' }}</p></div></div>
                        </section>

                        <section v-if="purchase.status === 'paid'" class="mt-4 border border-slate-200 bg-white shadow-sm"><header class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><div><h2 class="text-base font-bold text-slate-950">Work progress</h2><p class="mt-1 text-xs text-slate-500">{{ completedMilestones }} of {{ purchase.milestones.length }} steps complete</p></div><span class="text-sm font-bold text-slate-700">{{ purchase.milestones.length ? Math.round(completedMilestones / purchase.milestones.length * 100) : 0 }}%</span></header><ul class="divide-y divide-slate-200"><li v-for="milestone in purchase.milestones" :key="milestone.id" class="flex items-center gap-3 px-6 py-3.5 text-sm font-semibold" :class="milestone.completed ? 'text-slate-500' : 'text-slate-800'"><i :class="['fa-solid', milestone.completed ? 'fa-circle-check text-emerald-600' : 'fa-circle text-slate-300']" aria-hidden="true"></i>{{ milestone.label }}</li></ul><p v-if="purchase.fulfillment_notes" class="border-t border-slate-200 bg-slate-50 px-6 py-4 text-sm leading-6 text-slate-600">{{ purchase.fulfillment_notes }}</p></section>

                        <section v-if="purchase.fulfillment_status === 'provider_review'" class="mt-4 border border-amber-300 bg-amber-50 shadow-sm"><header class="border-b border-amber-200 px-6 py-4"><h2 class="text-base font-bold text-slate-950">Review the delivered work</h2><p class="mt-1 text-xs text-slate-600">Confirm completion or explain what still needs attention.</p></header><form class="px-6 py-5" @submit.prevent="confirmCompletion"><div class="flex items-end gap-5"><label class="w-36 text-sm font-bold text-slate-800">Rating <select v-model="completionForm.rating" class="mt-2 h-10 w-full border border-slate-300 bg-white px-3 text-sm"><option value="">Optional</option><option v-for="rating in 5" :key="rating" :value="rating">{{ rating }} / 5</option></select></label><label class="flex-1 text-sm font-bold text-slate-800">Feedback <input v-model="completionForm.feedback" maxlength="2000" class="mt-2 h-10 w-full border border-slate-300 bg-white px-3 text-sm" placeholder="Optional feedback"></label><button type="submit" :disabled="isSaving" class="h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">Confirm complete</button></div></form></section>

                        <section v-if="canReopen" class="mt-4 border border-slate-200 bg-white px-6 py-5 shadow-sm"><h2 class="text-base font-bold text-slate-950">Need another revision?</h2><p class="mt-1 text-xs text-slate-500">Reopen this request with one clear explanation.</p><form class="mt-4 flex gap-3" @submit.prevent="reopenService"><input v-model="reopenReason" minlength="10" maxlength="2000" required class="h-10 flex-1 border border-slate-300 px-3 text-sm outline-none focus:border-slate-950" placeholder="Describe what still needs to be addressed"><button type="submit" :disabled="isSaving" class="h-10 border border-slate-950 bg-white px-4 text-sm font-bold text-slate-900 disabled:opacity-40">Request changes</button></form></section>
                    </template>

                    <template v-else-if="activeSection === 'meeting'">
                        <section class="mt-4 border border-slate-200 bg-white shadow-sm"><header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-bold text-slate-950">{{ workspace.meetingTitle }}</h2><p class="mt-1 text-xs text-slate-500">{{ workspace.meetingDescription }}</p></header><div v-if="purchase.meeting_scheduled_for" class="grid grid-cols-3 divide-x divide-slate-200 border-b border-slate-200 bg-slate-50"><div class="px-6 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Requested time</p><p class="mt-1 text-sm font-bold text-slate-900">{{ dateTime(purchase.meeting_scheduled_for) }}</p></div><div class="px-6 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Format</p><p class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.meeting_mode }}</p></div><div class="px-6 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Status</p><p class="mt-1 text-sm font-bold capitalize text-slate-900">{{ purchase.meeting_status || 'Pending' }}</p></div></div><div v-if="purchase.meeting_admin_note" class="border-b border-slate-200 px-6 py-4 text-sm leading-6 text-slate-600"><span class="font-bold text-slate-800">Platform note:</span> {{ purchase.meeting_admin_note }}</div><form v-if="canRequestMeeting" class="px-6 py-5" @submit.prevent="requestMeeting"><div class="grid grid-cols-2 gap-5"><label class="text-sm font-bold text-slate-800">Preferred date and time<input v-model="meetingForm.meeting_scheduled_for" type="datetime-local" :min="minimumMeeting" required class="mt-2 h-11 w-full border border-slate-300 px-3 text-sm outline-none focus:border-slate-950"></label><label class="text-sm font-bold text-slate-800">Meeting format<select v-model="meetingForm.meeting_mode" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="online">Online</option><option value="onsite">On-site</option></select></label></div><label class="mt-5 block text-sm font-bold text-slate-800">Purpose<textarea v-model="meetingForm.meeting_purpose" rows="3" minlength="10" maxlength="1000" required class="mt-2 w-full border border-slate-300 px-4 py-3 text-sm outline-none focus:border-slate-950"></textarea></label><div class="mt-4 flex justify-end"><button type="submit" :disabled="isSaving" class="h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">{{ purchase.meeting_scheduled_for ? 'Update request' : 'Request meeting' }}</button></div></form><div v-else class="px-6 py-6 text-sm text-slate-500">Meeting changes are closed for this completed service.</div></section>
                    </template>

                    <template v-else-if="activeSection === 'files'">
                        <section class="mt-4 border border-slate-200 bg-white shadow-sm"><header class="flex items-center justify-between gap-6 border-b border-slate-200 px-6 py-4"><div><h2 class="text-base font-bold text-slate-950">{{ workspace.providerFilesLabel }}</h2><p class="mt-1 text-xs text-slate-500">Reference files shared by your organization.</p></div><label v-if="canWork" class="inline-flex h-10 cursor-pointer items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white"><i class="fa-solid fa-upload" aria-hidden="true"></i>{{ isUploading ? 'Uploading...' : 'Upload file' }}<input type="file" class="sr-only" :disabled="isUploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="uploadFile"></label></header><div v-if="!supportingFiles.length" class="px-6 py-10 text-center text-sm text-slate-500">{{ workspace.providerFilesEmpty }}</div><ul v-else class="divide-y divide-slate-200"><li v-for="file in supportingFiles" :key="file.id" class="flex items-center gap-4 px-6 py-4"><span class="grid h-10 w-10 place-items-center bg-slate-100 text-slate-600"><i class="fa-regular fa-file" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><p class="truncate text-sm font-bold text-slate-900">{{ file.original_name }}</p><p class="mt-1 text-xs text-slate-500">{{ fileSize(file.size) }} · {{ file.uploaded_by }} · {{ dateTime(file.created_at) }}</p></div><a :href="file.view_url" target="_blank" rel="noopener" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-700" title="Open file"><i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i><span class="sr-only">Open {{ file.original_name }}</span></a><a :href="file.download_url" class="grid h-9 w-9 place-items-center bg-slate-950 text-white" title="Download file"><i class="fa-solid fa-download text-xs" aria-hidden="true"></i><span class="sr-only">Download {{ file.original_name }}</span></a></li></ul></section>
                        <section class="mt-4 border border-slate-200 bg-white shadow-sm"><header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-bold text-slate-950">{{ workspace.deliverablesLabel }}</h2><p class="mt-1 text-xs text-slate-500">Files returned by platform support.</p></header><div v-if="!deliverables.length" class="px-6 py-10 text-center text-sm text-slate-500">{{ workspace.deliverablesEmpty }}</div><ul v-else class="divide-y divide-slate-200"><li v-for="file in deliverables" :key="file.id" class="flex items-center gap-4 px-6 py-4"><span class="grid h-10 w-10 place-items-center bg-amber-100 text-amber-700"><i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><p class="truncate text-sm font-bold text-slate-900">{{ file.original_name }}</p><p class="mt-1 text-xs text-slate-500">{{ fileSize(file.size) }} · {{ dateTime(file.created_at) }}</p></div><a :href="file.view_url" target="_blank" rel="noopener" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-700" title="Open file"><i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i><span class="sr-only">Open {{ file.original_name }}</span></a><a :href="file.download_url" class="grid h-9 w-9 place-items-center bg-slate-950 text-white" title="Download file"><i class="fa-solid fa-download text-xs" aria-hidden="true"></i><span class="sr-only">Download {{ file.original_name }}</span></a></li></ul></section>
                    </template>

                    <template v-else>
                        <section class="mt-4 border border-slate-200 bg-white shadow-sm"><header class="border-b border-slate-200 px-6 py-4"><h2 class="text-base font-bold text-slate-950">Request updates</h2><p class="mt-1 text-xs text-slate-500">A chronological record shared with your organization.</p></header><div v-if="!purchase.updates.length" class="px-6 py-10 text-center text-sm text-slate-500">No updates have been recorded.</div><ol v-else class="divide-y divide-slate-200"><li v-for="update in [...purchase.updates].reverse()" :key="update.id" class="flex gap-4 px-6 py-5"><span class="mt-1 h-2.5 w-2.5 shrink-0 bg-amber-400"></span><div class="min-w-0 flex-1"><div class="flex items-center justify-between gap-4"><p class="text-sm font-bold text-slate-900">{{ update.actor_name }}</p><time class="text-xs font-semibold text-slate-400">{{ dateTime(update.created_at) }}</time></div><p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-600">{{ update.message }}</p></div></li></ol></section>
                        <section v-if="canWork" class="mt-4 border border-slate-200 bg-white px-6 py-5 shadow-sm"><h2 class="text-base font-bold text-slate-950">Send an update</h2><p class="mt-1 text-xs text-slate-500">Answer a platform question or share concise progress information.</p><form class="mt-4" @submit.prevent="addUpdate"><textarea v-model="updateMessage" rows="4" minlength="3" maxlength="2000" required class="w-full border border-slate-300 px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950" placeholder="Write one clear update."></textarea><div class="mt-3 flex justify-end"><button type="submit" :disabled="isSaving" class="h-10 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-40">Send update</button></div></form></section>
                    </template>
                </template>
            </div>
        </section>
    </main>
</template>
