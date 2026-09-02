<script setup>
import { computed, onMounted, ref } from 'vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSectionNav from '../components/ProviderSectionNav.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { formatFileSize } from '../support/display';
import { showPortalToast } from '../support/portalToast';

const purchaseId = document.getElementById('app')?.dataset.purchaseId;
const isLoading = ref(true);
const isSaving = ref(false);
const isUploading = ref(false);
const isMeetingSaving = ref(false);
const errorMessage = ref('');
const purchase = ref(null);
const previewFile = ref(null);
const requestForm = ref({ request_summary: '', requested_outcome: '' });
const responseMessage = ref('');
const completionForm = ref({ rating: '', feedback: '' });
const reopenReason = ref('');
const meetingForm = ref({ meeting_scheduled_for: '', meeting_mode: 'online', meeting_purpose: '' });

const supportingFiles = computed(() => purchase.value?.files?.filter((file) => file.category === 'supporting') ?? []);
const deliverables = computed(() => purchase.value?.files?.filter((file) => file.category === 'deliverable') ?? []);
const completedMilestones = computed(() => purchase.value?.milestones?.filter((item) => item.completed).length ?? 0);
const canWork = computed(() => purchase.value?.status === 'paid'
    && !['provider_review', 'completed'].includes(purchase.value?.fulfillment_status));
const canReopen = computed(() => ['provider_review', 'completed'].includes(purchase.value?.fulfillment_status));
const canRequestMeeting = computed(() => purchase.value?.status === 'paid'
    && purchase.value?.fulfillment_status !== 'completed');
const minimumMeetingDateTime = computed(() => dateTimeInput(new Date(Date.now() + 60 * 60 * 1000).toISOString()));

const milestoneDescriptions = {
    'Program form walkthrough': 'Review the program details, applicant information, benefits, and schedule fields needed for a complete setup.',
    'Requirement and eligibility review': 'Check that the eligibility rules and required documents are clear, relevant, and ready for applicants.',
    'Publishing-readiness check': 'Confirm the program content and workflow are complete before it is submitted for platform review.',
    'Workflow setup review': 'Review the application stages and provider actions so the cycle follows the intended process.',
    'Applicant queue organization': 'Organize applicant records and statuses so the team can review each group efficiently.',
    'Schedule and notification check': 'Confirm important dates, locations, instructions, and applicant notifications are prepared.',
    'Current-process review': 'Document how the organization currently receives, evaluates, and advances scholarship applications.',
    'Data and workflow mapping': 'Match existing applicant data and review steps to the corresponding portal fields and stages.',
    'Implementation recommendations': 'Provide practical next steps for moving the organization process into the platform.',
};

function statusLabel(value) {
    return {
        queued: 'Ready to start',
        needs_information: 'Needs your information',
        ready: 'Ready to start',
        in_progress: 'In progress',
        provider_review: 'Ready for your review',
        completed: 'Completed',
    }[value] ?? String(value ?? 'pending').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(value) {
    if (value === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (value === 'provider_review') return 'bg-sky-100 text-sky-800';
    if (value === 'needs_information') return 'bg-amber-100 text-amber-800';
    return 'bg-slate-100 text-slate-700';
}

function meetingStatusClass(value) {
    if (value === 'confirmed') return 'bg-emerald-100 text-emerald-800';
    if (value === 'declined') return 'bg-rose-100 text-rose-800';
    return 'bg-amber-100 text-amber-800';
}

function dateTime(value) {
    if (!value) return 'Not set';

    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    }).format(new Date(value));
}

function dateTimeInput(value) {
    if (!value) return '';

    const date = new Date(value);
    const offset = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function milestoneDescription(label) {
    return milestoneDescriptions[label]
        ?? 'Complete and document this agreed part of the service before the work is sent for your review.';
}

function applyPurchase(payload) {
    purchase.value = payload;
    requestForm.value = {
        request_summary: payload?.request_summary ?? '',
        requested_outcome: payload?.requested_outcome ?? '',
    };
    meetingForm.value = {
        meeting_scheduled_for: dateTimeInput(payload?.meeting_scheduled_for),
        meeting_mode: payload?.meeting_mode ?? 'online',
        meeting_purpose: payload?.meeting_purpose ?? '',
    };
}

async function loadWorkspace() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/billing/${purchaseId}/data`);
        applyPurchase(response.data.purchase);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load this service workspace.';
    } finally {
        isLoading.value = false;
    }
}

async function saveBrief() {
    if (isSaving.value) return;
    isSaving.value = true;

    try {
        const response = await window.axios.patch(`/provider/billing/${purchaseId}/request`, requestForm.value);
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'Brief updated', message: response.data.message });
    } catch (error) {
        showPortalToast({ type: 'error', title: 'Unable to update brief', message: error.response?.data?.message ?? 'Check the request details and try again.' });
    } finally {
        isSaving.value = false;
    }
}

async function requestMeeting() {
    if (isMeetingSaving.value) return;
    isMeetingSaving.value = true;

    try {
        const response = await window.axios.post(`/provider/billing/${purchaseId}/meeting`, meetingForm.value);
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'Meeting request sent', message: response.data.message });
    } catch (error) {
        const firstValidationError = Object.values(error.response?.data?.errors ?? {})[0]?.[0];
        showPortalToast({ type: 'error', title: 'Unable to request meeting', message: firstValidationError ?? error.response?.data?.message ?? 'Check the meeting details and try again.' });
    } finally {
        isMeetingSaving.value = false;
    }
}

async function addResponse() {
    if (!responseMessage.value.trim() || isSaving.value) return;
    isSaving.value = true;

    try {
        const response = await window.axios.post(`/provider/billing/${purchaseId}/updates`, { message: responseMessage.value.trim() });
        responseMessage.value = '';
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'Response sent', message: response.data.message });
    } catch (error) {
        showPortalToast({ type: 'error', title: 'Unable to send response', message: error.response?.data?.message ?? 'Please try again.' });
    } finally {
        isSaving.value = false;
    }
}

async function uploadSupportingFile(event) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file || isUploading.value) return;

    isUploading.value = true;
    const data = new FormData();
    data.append('service_file', file);

    try {
        const response = await window.axios.post(`/provider/billing/${purchaseId}/files`, data);
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'File uploaded', message: response.data.message });
    } catch (error) {
        showPortalToast({ type: 'error', title: 'Upload failed', message: error.response?.data?.message ?? 'Choose a supported file up to 10 MB.' });
    } finally {
        input.value = '';
        isUploading.value = false;
    }
}

async function confirmCompletion() {
    if (isSaving.value) return;
    isSaving.value = true;

    try {
        const response = await window.axios.post(`/provider/billing/${purchaseId}/confirm`, {
            rating: completionForm.value.rating || null,
            feedback: completionForm.value.feedback.trim() || null,
        });
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'Service completed', message: response.data.message });
    } catch (error) {
        showPortalToast({ type: 'error', title: 'Unable to confirm completion', message: error.response?.data?.message ?? 'Please try again.' });
    } finally {
        isSaving.value = false;
    }
}

async function reopenService() {
    if (!reopenReason.value.trim() || isSaving.value) return;
    isSaving.value = true;

    try {
        const response = await window.axios.post(`/provider/billing/${purchaseId}/reopen`, { reason: reopenReason.value.trim() });
        reopenReason.value = '';
        applyPurchase(response.data.purchase);
        showPortalToast({ title: 'Service reopened', message: response.data.message });
    } catch (error) {
        showPortalToast({ type: 'error', title: 'Unable to reopen service', message: error.response?.data?.message ?? 'Add a short explanation and try again.' });
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadWorkspace);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <header class="provider-hero">
                    <a href="/provider/billing" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-slate-950">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to services
                    </a>
                    <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Service workspace</p>
                            <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">{{ purchase?.plan_name ?? 'Provider service' }}</h1>
                            <p class="mt-2 font-mono text-xs text-slate-500">{{ purchase?.reference_number }}</p>
                        </div>
                        <span v-if="purchase" :class="['w-fit rounded-md px-3 py-2 text-xs font-bold', statusClass(purchase.fulfillment_status)]">
                            {{ statusLabel(purchase.fulfillment_status) }}
                        </span>
                    </div>
                </header>

                <ProviderSectionNav section="support" />

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-sm">Loading service workspace...</div>
                <div v-else-if="errorMessage || !purchase" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                <template v-else>
                    <section class="provider-panel mt-5 grid overflow-hidden sm:grid-cols-3">
                        <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                            <p class="text-xs font-semibold text-slate-500">Assigned support</p>
                            <p class="mt-1 text-sm font-bold text-slate-950">{{ purchase.assigned_to_name || 'Awaiting assignment' }}</p>
                        </div>
                        <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                            <p class="text-xs font-semibold text-slate-500">Target completion</p>
                            <p class="mt-1 text-sm font-bold text-slate-950">{{ dateTime(purchase.target_due_at) }}</p>
                        </div>
                        <div class="p-4">
                            <p class="text-xs font-semibold text-slate-500">Milestones</p>
                            <p class="mt-1 text-sm font-bold text-slate-950">{{ completedMilestones }} of {{ purchase.milestones?.length ?? 0 }} completed</p>
                        </div>
                    </section>

                    <div v-if="purchase.status !== 'paid'" class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                        Payment must be confirmed before this workspace can be updated. Return to Services to continue or check the payment status.
                    </div>

                    <div class="mt-5 space-y-4">
                        <div class="space-y-4">
                            <section class="provider-panel p-5 sm:p-6">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Request brief</p>
                                        <h2 class="mt-1 text-xl font-bold text-slate-950">What your team needs</h2>
                                    </div>
                                </div>
                                <form class="mt-5 grid gap-4" @submit.prevent="saveBrief">
                                    <label class="block">
                                        <span class="text-sm font-bold text-slate-700">Situation or challenge</span>
                                        <textarea v-model="requestForm.request_summary" rows="4" maxlength="2000" :disabled="!canWork" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100 disabled:bg-slate-50" placeholder="Explain the current process, issue, or program your team needs help with."></textarea>
                                    </label>
                                    <label class="block">
                                        <span class="text-sm font-bold text-slate-700">Expected result</span>
                                        <textarea v-model="requestForm.requested_outcome" rows="3" maxlength="1200" :disabled="!canWork" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100 disabled:bg-slate-50" placeholder="Describe what a useful completed service should provide."></textarea>
                                    </label>
                                    <button v-if="canWork" type="submit" class="w-fit rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50" :disabled="isSaving">{{ isSaving ? 'Saving...' : 'Save brief' }}</button>
                                </form>
                            </section>

                            <section class="provider-panel overflow-hidden">
                                <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Included work</p>
                                        <h2 class="mt-1 text-xl font-bold text-slate-950">Service milestones</h2>
                                        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">These checkpoints show what is included in the service and what platform support has completed.</p>
                                    </div>
                                    <span class="w-fit rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">{{ completedMilestones }} of {{ purchase.milestones?.length ?? 0 }} completed</span>
                                </div>
                                <ol class="grid gap-4 p-5 sm:p-6 lg:grid-cols-3">
                                    <li v-for="(item, index) in purchase.milestones" :key="item.id" :class="['rounded-lg border p-4', item.completed ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-slate-50']">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Milestone {{ index + 1 }}</span>
                                            <span :class="['inline-flex items-center gap-1.5 rounded px-2 py-1 text-xs font-bold', item.completed ? 'bg-emerald-100 text-emerald-800' : 'bg-white text-slate-600 ring-1 ring-slate-200']">
                                                <i :class="['fa-solid', item.completed ? 'fa-check' : 'fa-clock']" aria-hidden="true"></i>
                                                {{ item.completed ? 'Completed' : 'Pending' }}
                                            </span>
                                        </div>
                                        <h3 class="mt-3 text-base font-bold text-slate-950">{{ item.label }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ milestoneDescription(item.label) }}</p>
                                    </li>
                                </ol>
                            </section>

                            <section class="provider-panel overflow-hidden">
                                <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                                    <div class="flex items-start gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span>
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Coordination</p>
                                            <h2 class="mt-1 text-xl font-bold text-slate-950">Meeting with admin support</h2>
                                            <p class="mt-2 text-sm leading-6 text-slate-600">Choose a preferred date and time. An admin confirms the request before the meeting becomes final.</p>
                                        </div>
                                    </div>
                                    <span v-if="purchase.meeting_status" :class="['w-fit rounded-md px-3 py-2 text-xs font-bold capitalize', meetingStatusClass(purchase.meeting_status)]">{{ purchase.meeting_status }}</span>
                                </div>

                                <div class="p-5 sm:p-6">
                                    <div v-if="purchase.meeting_scheduled_for" class="grid gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Preferred schedule</p>
                                            <p class="mt-1.5 text-sm font-bold text-slate-950">{{ dateTime(purchase.meeting_scheduled_for) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Meeting format</p>
                                            <p class="mt-1.5 text-sm font-bold text-slate-950">{{ purchase.meeting_mode === 'online' ? 'Online' : 'On-site' }}</p>
                                        </div>
                                        <div class="sm:col-span-2 lg:col-span-1">
                                            <p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Purpose</p>
                                            <p class="mt-1.5 text-sm leading-6 text-slate-700">{{ purchase.meeting_purpose }}</p>
                                        </div>
                                        <div v-if="purchase.meeting_admin_note" class="border-t border-slate-200 pt-4 sm:col-span-2 lg:col-span-3">
                                            <p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Admin note or instructions</p>
                                            <p class="mt-1.5 whitespace-pre-line text-sm leading-6 text-slate-700">{{ purchase.meeting_admin_note }}</p>
                                        </div>
                                    </div>

                                    <form v-if="canRequestMeeting" :class="['grid gap-4', purchase.meeting_scheduled_for ? 'mt-5 border-t border-slate-200 pt-5' : '']" @submit.prevent="requestMeeting">
                                        <div>
                                            <h3 class="text-sm font-bold text-slate-950">{{ purchase.meeting_status === 'confirmed' ? 'Request a different time' : purchase.meeting_status ? 'Update meeting request' : 'Request a meeting' }}</h3>
                                            <p class="mt-1 text-sm text-slate-500">Use a time when your team is available to discuss this service request.</p>
                                        </div>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <label class="block">
                                                <span class="text-sm font-bold text-slate-700">Preferred date and time</span>
                                                <input v-model="meetingForm.meeting_scheduled_for" type="datetime-local" required :min="minimumMeetingDateTime" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                            </label>
                                            <label class="block">
                                                <span class="text-sm font-bold text-slate-700">Meeting format</span>
                                                <select v-model="meetingForm.meeting_mode" required class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100">
                                                    <option value="online">Online meeting</option>
                                                    <option value="onsite">On-site meeting</option>
                                                </select>
                                            </label>
                                        </div>
                                        <label class="block">
                                            <span class="text-sm font-bold text-slate-700">Meeting purpose</span>
                                            <textarea v-model="meetingForm.meeting_purpose" rows="2" required minlength="10" maxlength="1000" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100" placeholder="What would you like to discuss with platform support?"></textarea>
                                        </label>
                                        <p v-if="purchase.meeting_status === 'confirmed'" class="text-xs leading-5 text-amber-700">Submitting a different time replaces the confirmed schedule and requires admin confirmation again.</p>
                                        <button type="submit" class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50 sm:w-fit" :disabled="isMeetingSaving">
                                            {{ isMeetingSaving ? 'Sending...' : purchase.meeting_status ? 'Send updated request' : 'Request meeting' }}
                                        </button>
                                    </form>

                                    <p v-else-if="purchase.fulfillment_status === 'completed' && !purchase.meeting_scheduled_for" class="text-sm text-slate-500">This service is complete, so a new meeting can no longer be requested here.</p>
                                </div>
                            </section>

                            <section class="provider-panel overflow-hidden">
                                <div class="border-b border-slate-200 p-5 sm:p-6">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Files</p>
                                    <h2 class="mt-1 text-xl font-bold text-slate-950">Supporting files and deliverables</h2>
                                </div>
                                <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-2">
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-bold text-slate-950">Files from your team</h3>
                                        <div class="mt-3 divide-y divide-slate-200 rounded-md border border-slate-200">
                                            <button v-for="file in supportingFiles" :key="file.id" type="button" class="flex w-full items-center gap-3 p-3 text-left hover:bg-slate-50" @click="previewFile = file">
                                                <i class="fa-solid fa-file-lines text-slate-400" aria-hidden="true"></i>
                                                <span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-900">{{ file.original_name }}</span><span class="mt-0.5 block text-xs text-slate-500">{{ formatFileSize(file.size) }}</span></span>
                                            </button>
                                            <p v-if="!supportingFiles.length" class="p-4 text-sm text-slate-500">No supporting files uploaded.</p>
                                        </div>
                                        <div v-if="canWork" class="mt-3 flex justify-start">
                                            <label class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 sm:w-auto">
                                                <i class="fa-solid fa-upload" aria-hidden="true"></i>
                                                {{ isUploading ? 'Uploading...' : 'Upload file' }}
                                                <input type="file" class="sr-only" :disabled="isUploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="uploadSupportingFile">
                                            </label>
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-sm font-bold text-slate-950">Files from platform support</h3>
                                        <div class="mt-3 divide-y divide-slate-200 rounded-md border border-slate-200">
                                            <button v-for="file in deliverables" :key="file.id" type="button" class="flex w-full items-center gap-3 p-3 text-left hover:bg-slate-50" @click="previewFile = file">
                                                <i class="fa-solid fa-file-circle-check text-emerald-600" aria-hidden="true"></i>
                                                <span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-900">{{ file.original_name }}</span><span class="mt-0.5 block text-xs text-slate-500">{{ formatFileSize(file.size) }}</span></span>
                                            </button>
                                            <p v-if="!deliverables.length" class="p-4 text-sm text-slate-500">Deliverables will appear here when shared.</p>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="provider-panel overflow-hidden">
                                <div class="border-b border-slate-200 p-5 sm:p-6">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Updates</p>
                                    <h2 class="mt-1 text-xl font-bold text-slate-950">Service history</h2>
                                </div>
                                <div class="divide-y divide-slate-200">
                                    <article v-for="update in purchase.updates" :key="update.id" class="flex gap-3 p-4 sm:px-6">
                                        <span :class="['mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs', update.actor_role === 'provider' ? 'bg-amber-100 text-amber-800' : 'bg-slate-950 text-white']"><i :class="['fa-solid', update.actor_role === 'provider' ? 'fa-building' : 'fa-headset']" aria-hidden="true"></i></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-bold text-slate-950">{{ update.actor_name }}</p><p class="text-xs text-slate-400">{{ dateTime(update.created_at) }}</p></div>
                                            <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{{ update.message }}</p>
                                        </div>
                                    </article>
                                    <p v-if="!purchase.updates?.length" class="p-5 text-sm text-slate-500">No service updates yet.</p>
                                </div>
                                <form v-if="canWork" class="border-t border-slate-200 bg-slate-50 p-4 sm:px-6" @submit.prevent="addResponse">
                                    <label for="provider-service-response" class="block text-sm font-bold text-slate-700">Reply or add information</label>
                                    <textarea id="provider-service-response" v-model="responseMessage" rows="3" maxlength="2000" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3.5 py-3 text-sm leading-6 outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100" placeholder="Answer a question or share a short update."></textarea>
                                    <div class="mt-3 flex justify-start">
                                        <button type="submit" class="w-full rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50 sm:w-auto" :disabled="isSaving || !responseMessage.trim()">Send response</button>
                                    </div>
                                </form>
                            </section>
                        </div>

                        <div class="space-y-4">
                            <section v-if="purchase.fulfillment_status === 'provider_review'" class="rounded-lg border border-sky-200 bg-sky-50 p-5 shadow-sm">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-sky-700">Your decision</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Review the completed work</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-600">Check the updates and deliverables. Confirm completion if the requested result was provided, or reopen it with a clear reason.</p>
                                <label class="mt-4 block text-xs font-bold text-slate-700">Rating <span class="font-normal text-slate-500">(optional)</span>
                                    <select v-model="completionForm.rating" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="">No rating</option><option v-for="rating in 5" :key="rating" :value="rating">{{ rating }} of 5</option></select>
                                </label>
                                <label class="mt-3 block text-xs font-bold text-slate-700">Feedback <span class="font-normal text-slate-500">(optional)</span>
                                    <textarea v-model="completionForm.feedback" rows="3" maxlength="2000" class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm" placeholder="What worked well or could be improved?"></textarea>
                                </label>
                                <button type="button" class="mt-4 w-full rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" :disabled="isSaving" @click="confirmCompletion">Confirm completion</button>
                            </section>

                            <section v-if="canReopen" class="provider-panel p-5">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Still needs work?</p>
                                <textarea v-model="reopenReason" rows="3" maxlength="2000" class="mt-3 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm" placeholder="Explain what remains unresolved."></textarea>
                                <button type="button" class="mt-3 w-full rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 disabled:opacity-50" :disabled="isSaving || reopenReason.trim().length < 10" @click="reopenService">Request additional work</button>
                            </section>

                            <section v-if="purchase.fulfillment_status === 'completed'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                                <p class="text-sm font-bold text-emerald-900">Completion confirmed</p>
                                <p class="mt-1 text-sm text-emerald-800">Confirmed {{ dateTime(purchase.provider_confirmed_at) }}</p>
                            </section>
                        </div>
                    </div>
                </template>

                <ProviderFooter />
            </div>
        </section>
    </main>

    <FilePreviewModal v-if="previewFile" :file="previewFile" :title="previewFile.original_name" context="Provider service file" @close="previewFile = null" />
</template>
