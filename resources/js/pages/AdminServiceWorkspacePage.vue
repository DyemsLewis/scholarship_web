<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import { formatFileSize } from '../support/display';
import { showPortalToast } from '../support/portalToast';

const purchaseId = document.getElementById('app')?.dataset.purchaseId;
const isLoading = ref(true);
const isSaving = ref(false);
const isUploading = ref(false);
const errorMessage = ref('');
const purchase = ref(null);
const assignees = ref([]);
const statusOptions = ref([]);
const previewFile = ref(null);
const workflowForm = ref({
    fulfillment_status: 'ready',
    assigned_to: '',
    priority: 'normal',
    target_due_at: '',
    milestones: [],
    fulfillment_notes: '',
    provider_update: '',
    internal_note: '',
});
const updateForm = ref({ kind: 'progress_update', message: '' });

const supportingFiles = computed(() => purchase.value?.files?.filter((file) => file.category === 'supporting') ?? []);
const deliverables = computed(() => purchase.value?.files?.filter((file) => file.category === 'deliverable') ?? []);
const completedMilestones = computed(() => workflowForm.value.milestones.filter((item) => item.completed).length);

function statusLabel(value) {
    return {
        queued: 'Ready to start', needs_information: 'Needs information', ready: 'Ready to start',
        in_progress: 'In progress', provider_review: 'Provider review', completed: 'Completed',
    }[value] ?? String(value ?? 'pending').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(value) {
    if (value === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (value === 'provider_review') return 'bg-sky-100 text-sky-800';
    if (value === 'needs_information') return 'bg-amber-100 text-amber-800';
    if (value === 'in_progress') return 'bg-indigo-100 text-indigo-800';
    return 'bg-slate-100 text-slate-700';
}

function dateTime(value) {
    if (!value) return 'Not set';
    return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value));
}

function dateInput(value) {
    if (!value) return '';
    const date = new Date(value);
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 10);
}

function applyPurchase(payload) {
    purchase.value = payload;
    workflowForm.value = {
        fulfillment_status: payload.fulfillment_status === 'queued' ? 'ready' : payload.fulfillment_status,
        assigned_to: payload.assigned_to ?? '',
        priority: payload.priority ?? 'normal',
        target_due_at: dateInput(payload.target_due_at),
        milestones: (payload.milestones ?? []).map((item) => ({ ...item })),
        fulfillment_notes: payload.fulfillment_notes ?? '',
        provider_update: '',
        internal_note: '',
    };
}

async function loadWorkspace() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/admin/billing/${purchaseId}/data`);
        assignees.value = response.data.assignees ?? [];
        statusOptions.value = response.data.status_options ?? [];
        applyPurchase(response.data.purchase);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load this service request.';
    } finally {
        isLoading.value = false;
    }
}

async function saveWorkflow() {
    if (isSaving.value) return;
    isSaving.value = true;
    try {
        const response = await window.axios.patch(`/admin/billing/${purchaseId}/fulfillment`, {
            ...workflowForm.value,
            assigned_to: workflowForm.value.assigned_to || null,
            target_due_at: workflowForm.value.target_due_at || null,
            provider_update: workflowForm.value.provider_update.trim() || null,
            internal_note: workflowForm.value.internal_note.trim() || null,
        });
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'Service workspace updated', message: response.data.message });
    } catch (error) {
        const firstValidationError = Object.values(error.response?.data?.errors ?? {})[0]?.[0];
        showPortalToast({ type: 'error', title: 'Unable to save service', message: firstValidationError ?? error.response?.data?.message ?? 'Review the workflow fields and try again.' });
    } finally {
        isSaving.value = false;
    }
}

async function addUpdate() {
    if (!updateForm.value.message.trim() || isSaving.value) return;
    isSaving.value = true;
    try {
        const response = await window.axios.post(`/admin/billing/${purchaseId}/updates`, {
            kind: updateForm.value.kind,
            message: updateForm.value.message.trim(),
        });
        updateForm.value.message = '';
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'Update added', message: response.data.message });
    } catch (error) {
        showPortalToast({ type: 'error', title: 'Unable to add update', message: error.response?.data?.message ?? 'Please try again.' });
    } finally {
        isSaving.value = false;
    }
}

async function uploadDeliverable(event) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file || isUploading.value) return;
    isUploading.value = true;
    const data = new FormData();
    data.append('service_file', file);

    try {
        const response = await window.axios.post(`/admin/billing/${purchaseId}/deliverables`, data);
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'Deliverable shared', message: response.data.message });
    } catch (error) {
        showPortalToast({ type: 'error', title: 'Upload failed', message: error.response?.data?.message ?? 'Choose a supported file up to 10 MB.' });
    } finally {
        input.value = '';
        isUploading.value = false;
    }
}

onMounted(loadWorkspace);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="billing" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <a href="/admin/billing" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to service queue</a>
                    <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Service request</p>
                            <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">{{ purchase?.plan_name ?? 'Provider service' }}</h1>
                            <p class="mt-2 text-sm text-slate-600">{{ purchase?.provider?.name }} <span class="mx-1 text-slate-300">|</span> <span class="font-mono text-xs">{{ purchase?.reference_number }}</span></p>
                        </div>
                        <span v-if="purchase" :class="['w-fit rounded-md px-3 py-2 text-xs font-bold', statusClass(purchase.fulfillment_status)]">{{ statusLabel(purchase.fulfillment_status) }}</span>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-sm">Loading service request...</div>
                <div v-else-if="errorMessage || !purchase" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                <template v-else>
                    <section class="mt-6 grid overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm sm:grid-cols-4">
                        <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r"><p class="text-xs font-semibold text-slate-500">Payment</p><p class="mt-1 text-sm font-bold text-slate-950">{{ statusLabel(purchase.status) }}</p></div>
                        <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r"><p class="text-xs font-semibold text-slate-500">Priority</p><p class="mt-1 text-sm font-bold text-slate-950">{{ statusLabel(purchase.priority) }}</p></div>
                        <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r"><p class="text-xs font-semibold text-slate-500">Assigned to</p><p class="mt-1 text-sm font-bold text-slate-950">{{ purchase.assigned_to_name || 'Unassigned' }}</p></div>
                        <div class="p-4"><p class="text-xs font-semibold text-slate-500">Target date</p><p class="mt-1 text-sm font-bold text-slate-950">{{ dateTime(purchase.target_due_at) }}</p></div>
                    </section>

                    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(20rem,0.75fr)]">
                        <div class="space-y-6">
                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Provider brief</p>
                                <h2 class="mt-1 text-xl font-bold text-slate-950">Requested support</h2>
                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-bold text-slate-500">Situation or challenge</p><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ purchase.request_summary || 'The provider has not supplied a service brief.' }}</p></div>
                                    <div class="rounded-md border border-slate-200 bg-slate-50 p-4"><p class="text-xs font-bold text-slate-500">Expected result</p><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ purchase.requested_outcome || 'No expected result was supplied.' }}</p></div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                                <div class="flex items-start justify-between gap-3">
                                    <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Workflow</p><h2 class="mt-1 text-xl font-bold text-slate-950">Manage the service</h2></div>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{{ completedMilestones }}/{{ workflowForm.milestones.length }} milestones</span>
                                </div>
                                <form class="mt-5" @submit.prevent="saveWorkflow">
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <label class="text-sm font-bold text-slate-700">Status<select v-model="workflowForm.fulfillment_status" :disabled="purchase.fulfillment_status === 'completed'" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal disabled:bg-slate-50"><option v-if="purchase.fulfillment_status === 'completed'" value="completed">Completed</option><option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                                        <label class="text-sm font-bold text-slate-700">Assigned staff<select v-model="workflowForm.assigned_to" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"><option value="">Unassigned</option><option v-for="admin in assignees" :key="admin.id" :value="admin.id">{{ admin.name }}</option></select></label>
                                        <label class="text-sm font-bold text-slate-700">Priority<select v-model="workflowForm.priority" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
                                        <label class="text-sm font-bold text-slate-700">Target completion<input v-model="workflowForm.target_due_at" type="date" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"></label>
                                    </div>

                                    <fieldset class="mt-5 rounded-md border border-slate-200 p-4">
                                        <legend class="px-1 text-sm font-bold text-slate-700">Service milestones</legend>
                                        <label v-for="item in workflowForm.milestones" :key="item.id" class="flex cursor-pointer items-start gap-3 border-b border-slate-100 py-2.5 last:border-b-0"><input v-model="item.completed" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-amber-400"><span class="text-sm text-slate-700">{{ item.label }}</span></label>
                                    </fieldset>

                                    <label class="mt-5 block text-sm font-bold text-slate-700">Current service summary <span class="font-normal text-slate-500">(shown to provider)</span><textarea v-model="workflowForm.fulfillment_notes" rows="2" maxlength="2000" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal" placeholder="Short current-state summary"></textarea></label>
                                    <label class="mt-4 block text-sm font-bold text-slate-700">Provider update <span class="font-normal text-slate-500">(required for information requests and provider review)</span><textarea v-model="workflowForm.provider_update" rows="3" maxlength="2000" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal" placeholder="Explain progress, needed information, or the completed result."></textarea></label>
                                    <label class="mt-4 block text-sm font-bold text-slate-700">Internal note <span class="font-normal text-slate-500">(admin only)</span><textarea v-model="workflowForm.internal_note" rows="2" maxlength="2000" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal" placeholder="Optional coordination note hidden from providers"></textarea></label>
                                    <button type="submit" class="mt-5 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" :disabled="isSaving || purchase.status !== 'paid' || purchase.fulfillment_status === 'completed'">{{ isSaving ? 'Saving...' : 'Save workflow' }}</button>
                                    <p v-if="workflowForm.fulfillment_status === 'provider_review'" class="mt-3 text-xs leading-5 text-slate-500">All milestones and a provider update are required. The provider, not the admin, confirms final completion.</p>
                                </form>
                            </section>

                            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="border-b border-slate-200 p-5 sm:p-6"><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">History</p><h2 class="mt-1 text-xl font-bold text-slate-950">Updates and internal notes</h2></div>
                                <div class="divide-y divide-slate-200">
                                    <article v-for="update in purchase.updates" :key="update.id" :class="['flex gap-3 p-4 sm:px-6', update.visible_to_provider ? '' : 'bg-amber-50/60']">
                                        <span :class="['mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs', update.actor_role === 'provider' ? 'bg-amber-100 text-amber-800' : 'bg-slate-950 text-white']"><i :class="['fa-solid', update.actor_role === 'provider' ? 'fa-building' : 'fa-headset']" aria-hidden="true"></i></span>
                                        <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><p class="text-sm font-bold text-slate-950">{{ update.actor_name }}</p><span v-if="!update.visible_to_provider" class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase text-amber-800">Internal</span><span class="ml-auto text-xs text-slate-400">{{ dateTime(update.created_at) }}</span></div><p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{{ update.message }}</p></div>
                                    </article>
                                    <p v-if="!purchase.updates?.length" class="p-5 text-sm text-slate-500">No updates recorded.</p>
                                </div>
                                <form class="border-t border-slate-200 bg-slate-50 p-4 sm:px-6" @submit.prevent="addUpdate">
                                    <div class="grid gap-2 sm:grid-cols-[12rem_minmax(0,1fr)_auto] sm:items-end">
                                        <label class="text-xs font-bold text-slate-700">Update type<select v-model="updateForm.kind" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal"><option value="progress_update">Provider update</option><option value="clarification_request">Request information</option><option value="internal_note">Internal note</option></select></label>
                                        <label class="text-xs font-bold text-slate-700">Message<textarea v-model="updateForm.message" rows="2" maxlength="2000" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-normal" placeholder="Add a clear update"></textarea></label>
                                        <button type="submit" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" :disabled="isSaving || !updateForm.message.trim()">Add update</button>
                                    </div>
                                </form>
                            </section>
                        </div>

                        <aside class="space-y-6">
                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex items-center justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Deliverables</p><h2 class="mt-1 text-lg font-bold text-slate-950">Files for provider</h2></div><label v-if="purchase.status === 'paid' && purchase.fulfillment_status !== 'completed'" class="cursor-pointer rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white"><span>{{ isUploading ? 'Uploading...' : 'Upload' }}</span><input type="file" class="sr-only" :disabled="isUploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="uploadDeliverable"></label></div>
                                <div class="mt-4 divide-y divide-slate-200 rounded-md border border-slate-200"><button v-for="file in deliverables" :key="file.id" type="button" class="flex w-full items-center gap-3 p-3 text-left hover:bg-slate-50" @click="previewFile = file"><i class="fa-solid fa-file-circle-check text-emerald-600" aria-hidden="true"></i><span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-900">{{ file.original_name }}</span><span class="block text-xs text-slate-500">{{ formatFileSize(file.size) }}</span></span></button><p v-if="!deliverables.length" class="p-4 text-sm text-slate-500">No deliverables uploaded.</p></div>
                            </section>

                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Provider files</p><h2 class="mt-1 text-lg font-bold text-slate-950">Supporting material</h2>
                                <div class="mt-4 divide-y divide-slate-200 rounded-md border border-slate-200"><button v-for="file in supportingFiles" :key="file.id" type="button" class="flex w-full items-center gap-3 p-3 text-left hover:bg-slate-50" @click="previewFile = file"><i class="fa-solid fa-file-lines text-slate-400" aria-hidden="true"></i><span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-900">{{ file.original_name }}</span><span class="block text-xs text-slate-500">{{ formatFileSize(file.size) }}</span></span></button><p v-if="!supportingFiles.length" class="p-4 text-sm text-slate-500">No provider files uploaded.</p></div>
                            </section>

                            <section v-if="purchase.fulfillment_status === 'completed'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-5 shadow-sm"><p class="text-sm font-bold text-emerald-900">Provider confirmed completion</p><p class="mt-1 text-sm text-emerald-800">{{ dateTime(purchase.provider_confirmed_at) }}</p><p v-if="purchase.provider_rating" class="mt-3 text-sm font-bold text-slate-900">Rating: {{ purchase.provider_rating }} of 5</p><p v-if="purchase.provider_feedback" class="mt-2 text-sm leading-6 text-slate-700">{{ purchase.provider_feedback }}</p></section>
                        </aside>
                    </div>
                </template>

                <AdminFooter />
            </div>
        </section>
    </main>

    <FilePreviewModal v-if="previewFile" :file="previewFile" :title="previewFile.original_name" context="Provider service file" @close="previewFile = null" />
</template>
