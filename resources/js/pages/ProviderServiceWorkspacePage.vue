<script setup>
import { computed, onMounted, ref } from 'vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { formatFileSize } from '../support/display';
import { showPortalToast } from '../support/portalToast';

const purchaseId = document.getElementById('app')?.dataset.purchaseId;
const isLoading = ref(true);
const isSaving = ref(false);
const isUploading = ref(false);
const errorMessage = ref('');
const purchase = ref(null);
const previewFile = ref(null);
const requestForm = ref({ request_summary: '', requested_outcome: '' });
const responseMessage = ref('');
const completionForm = ref({ rating: '', feedback: '' });
const reopenReason = ref('');

const supportingFiles = computed(() => purchase.value?.files?.filter((file) => file.category === 'supporting') ?? []);
const deliverables = computed(() => purchase.value?.files?.filter((file) => file.category === 'deliverable') ?? []);
const completedMilestones = computed(() => purchase.value?.milestones?.filter((item) => item.completed).length ?? 0);
const canWork = computed(() => purchase.value?.status === 'paid'
    && !['provider_review', 'completed'].includes(purchase.value?.fulfillment_status));
const canReopen = computed(() => ['provider_review', 'completed'].includes(purchase.value?.fulfillment_status));

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

function dateTime(value) {
    if (!value) return 'Not set';

    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    }).format(new Date(value));
}

function applyPurchase(payload) {
    purchase.value = payload;
    requestForm.value = {
        request_summary: payload?.request_summary ?? '',
        requested_outcome: payload?.requested_outcome ?? '',
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
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
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

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-sm">Loading service workspace...</div>
                <div v-else-if="errorMessage || !purchase" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">{{ errorMessage }}</div>

                <template v-else>
                    <section class="mt-6 grid overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm sm:grid-cols-3">
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

                    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(19rem,0.7fr)]">
                        <div class="space-y-6">
                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
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

                            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                <div class="border-b border-slate-200 p-5 sm:p-6">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Files</p>
                                    <h2 class="mt-1 text-xl font-bold text-slate-950">Supporting files and deliverables</h2>
                                </div>
                                <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-2">
                                    <div>
                                        <div class="flex items-center justify-between gap-3">
                                            <h3 class="text-sm font-bold text-slate-950">Files from your team</h3>
                                            <label v-if="canWork" class="cursor-pointer rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                                                {{ isUploading ? 'Uploading...' : 'Upload file' }}
                                                <input type="file" class="sr-only" :disabled="isUploading" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="uploadSupportingFile">
                                            </label>
                                        </div>
                                        <div class="mt-3 divide-y divide-slate-200 rounded-md border border-slate-200">
                                            <button v-for="file in supportingFiles" :key="file.id" type="button" class="flex w-full items-center gap-3 p-3 text-left hover:bg-slate-50" @click="previewFile = file">
                                                <i class="fa-solid fa-file-lines text-slate-400" aria-hidden="true"></i>
                                                <span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-900">{{ file.original_name }}</span><span class="mt-0.5 block text-xs text-slate-500">{{ formatFileSize(file.size) }}</span></span>
                                            </button>
                                            <p v-if="!supportingFiles.length" class="p-4 text-sm text-slate-500">No supporting files uploaded.</p>
                                        </div>
                                    </div>
                                    <div>
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

                            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
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
                                    <label class="text-sm font-bold text-slate-700">Reply or add information</label>
                                    <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end">
                                        <textarea v-model="responseMessage" rows="2" maxlength="2000" class="min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100" placeholder="Answer a question or share a short update."></textarea>
                                        <button type="submit" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" :disabled="isSaving || !responseMessage.trim()">Send response</button>
                                    </div>
                                </form>
                            </section>
                        </div>

                        <aside class="space-y-6">
                            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Included work</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Milestones</h2>
                                <ul class="mt-4 space-y-3">
                                    <li v-for="item in purchase.milestones" :key="item.id" class="flex items-start gap-3 text-sm">
                                        <span :class="['mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded text-[10px]', item.completed ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400']"><i :class="['fa-solid', item.completed ? 'fa-check' : 'fa-minus']" aria-hidden="true"></i></span>
                                        <span :class="item.completed ? 'font-semibold text-slate-900' : 'text-slate-600'">{{ item.label }}</span>
                                    </li>
                                </ul>
                            </section>

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

                            <section v-if="canReopen" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Still needs work?</p>
                                <textarea v-model="reopenReason" rows="3" maxlength="2000" class="mt-3 w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm" placeholder="Explain what remains unresolved."></textarea>
                                <button type="button" class="mt-3 w-full rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 disabled:opacity-50" :disabled="isSaving || reopenReason.trim().length < 10" @click="reopenService">Request additional work</button>
                            </section>

                            <section v-if="purchase.fulfillment_status === 'completed'" class="rounded-lg border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                                <p class="text-sm font-bold text-emerald-900">Completion confirmed</p>
                                <p class="mt-1 text-sm text-emerald-800">Confirmed {{ dateTime(purchase.provider_confirmed_at) }}</p>
                            </section>
                        </aside>
                    </div>
                </template>

                <ProviderFooter />
            </div>
        </section>
    </main>

    <FilePreviewModal v-if="previewFile" :file="previewFile" :title="previewFile.original_name" context="Provider service file" @close="previewFile = null" />
</template>
