<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderApplicantPhoto from '../../../components/ProviderApplicantPhoto.vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const caseId = window.location.pathname.match(/\/cases\/(\d+)/)?.[1];
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const workspace = ref(null);
const report = ref(null);
const responseText = ref('');
const confirmation = ref(null);

const backUrl = computed(() => {
    if (report.value?.work_state === 'waiting') return '/provider/workspaces/support/waiting';
    if (report.value?.work_state === 'submitted') return '/provider/workspaces/support/platform';
    if (report.value?.work_state === 'resolved') return '/provider/workspaces/support/resolved';
    return '/provider/workspaces/support';
});
const isIncomingCase = computed(() => report.value?.requires_both_roles && !report.value?.submitted_by_provider);
const canRespond = computed(() => isIncomingCase.value && report.value?.provider_status === 'open');
const responseLength = computed(() => responseText.value.trim().length);
const reporterLabel = computed(() => report.value?.applicant?.role === 'provider' ? 'Provider team' : 'Applicant');
const progressSteps = computed(() => {
    if (!report.value) return [];

    if (report.value.submitted_by_provider) {
        return [
            { label: 'Report sent', detail: report.value.created_at, complete: true },
            {
                label: 'Platform review',
                detail: report.value.admin_status === 'resolved' ? (report.value.admin_resolved_at || 'Completed') : 'In progress',
                complete: report.value.admin_status === 'resolved',
                current: report.value.admin_status !== 'resolved',
            },
            {
                label: 'Closed',
                detail: report.value.resolved_at || 'Not yet complete',
                complete: report.value.overall_status === 'resolved',
            },
        ];
    }

    return [
        { label: 'Concern received', detail: report.value.created_at, complete: true },
        {
            label: 'Provider response',
            detail: report.value.provider_status === 'resolved' ? (report.value.provider_resolved_at || 'Completed') : 'Response required',
            complete: report.value.provider_status === 'resolved',
            current: report.value.provider_status === 'open',
        },
        {
            label: 'Platform review',
            detail: report.value.admin_status === 'resolved' ? (report.value.admin_resolved_at || 'Completed') : 'Pending completion',
            complete: report.value.admin_status === 'resolved',
            current: report.value.provider_status === 'resolved' && report.value.admin_status !== 'resolved',
        },
    ];
});

function stateClass(state) {
    if (state === 'needs_action') return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function fileSize(bytes) {
    if (!bytes) return '';
    const value = Number(bytes);
    if (value < 1024 * 1024) return `${Math.max(1, Math.round(value / 1024))} KB`;
    return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

async function loadCase() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/workspaces/support/cases/${caseId}/data`);
        workspace.value = response.data.workspace;
        report.value = response.data.report;
        responseText.value = response.data.report.provider_response || '';
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'This support case could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

function requestComplete() {
    if (responseLength.value < 10) {
        errorMessage.value = 'Add a clear provider response of at least 10 characters.';
        return;
    }
    errorMessage.value = '';
    confirmation.value = {
        status: 'resolved',
        title: 'Send this response?',
        detail: 'The applicant will be able to read it. Platform support will continue its review if needed.',
        action: 'Send response',
    };
}

function requestReopen() {
    confirmation.value = {
        status: 'open',
        title: 'Reopen the response?',
        detail: 'The current provider response will be cleared so you can record a corrected answer.',
        action: 'Reopen response',
    };
}

async function confirmStatus() {
    if (!confirmation.value) return;
    isSaving.value = true;
    errorMessage.value = '';
    try {
        const payload = { status: confirmation.value.status };
        if (confirmation.value.status === 'resolved') payload.provider_response = responseText.value.trim();
        const response = await window.axios.patch(`/provider/reports/${caseId}/status`, payload);
        report.value = { ...report.value, ...response.data.report };
        responseText.value = report.value.provider_response || '';
        confirmation.value = null;
    } catch (error) {
        errorMessage.value = Object.values(error.response?.data?.errors ?? {})[0]?.[0]
            || error.response?.data?.message
            || 'The provider response could not be saved.';
        confirmation.value = null;
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadCase);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="support-case-title">
            <div class="mx-auto max-w-[94rem]">
                <a :href="backUrl" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to support cases</a>

                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-20 text-center text-sm font-semibold text-slate-500 shadow-sm">Loading support case...</div>
                <div v-else-if="!report" class="mt-4 border border-rose-200 bg-rose-50 px-6 py-12 text-center text-sm font-semibold text-rose-800" role="alert">{{ errorMessage || 'This support case is unavailable.' }}</div>

                <template v-else>
                    <header class="mt-4 border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-5 shadow-sm">
                        <div class="flex items-center justify-between gap-8">
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Support case</p>
                                <h1 id="support-case-title" class="mt-1 truncate font-display text-3xl font-bold tracking-tight text-slate-950">{{ report.subject }}</h1>
                                <p class="mt-1.5 text-sm text-slate-600">{{ report.category_label }} · Received {{ report.created_at }}</p>
                            </div>
                            <span :class="['inline-flex shrink-0 border px-3 py-2 text-[11px] font-bold uppercase tracking-wide', stateClass(report.work_state)]">{{ report.work_label }}</span>
                        </div>
                    </header>

                    <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Case identity">
                        <div class="grid grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,0.75fr)] divide-x divide-slate-200">
                            <div class="flex min-w-0 items-center gap-4 px-6 py-5">
                                <ProviderApplicantPhoto :src="report.applicant?.profile_photo_url" :name="report.applicant?.name || 'Provider team'" />
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ reporterLabel }}</p>
                                    <p class="mt-1 truncate text-base font-bold text-slate-950">{{ report.applicant?.name || 'Provider team' }}</p>
                                    <p class="mt-1 truncate text-sm text-slate-600">{{ report.applicant?.email }}<span v-if="report.applicant?.contact_number"> · {{ report.applicant.contact_number }}</span></p>
                                </div>
                            </div>
                            <div class="px-6 py-5">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Related program</p>
                                <p class="mt-2 truncate text-sm font-bold text-slate-950">{{ report.program?.title || 'General platform issue' }}</p>
                            </div>
                            <div class="px-6 py-5">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Assigned to</p>
                                <p class="mt-2 text-sm font-bold text-slate-950">{{ report.submitted_by_provider ? 'Platform support' : 'Provider support' }}</p>
                            </div>
                        </div>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="reported-issue-heading">
                        <header class="border-b border-slate-200 px-6 py-4">
                            <h2 id="reported-issue-heading" class="text-base font-bold text-slate-950">Reported issue</h2>
                            <p class="mt-1 text-xs text-slate-500">Read the original concern before taking action.</p>
                        </header>
                        <div class="px-6 py-5"><p class="whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ report.description }}</p></div>
                        <div v-if="report.context || report.attachment" class="flex items-center gap-6 border-t border-slate-200 bg-slate-50 px-6 py-4">
                            <div v-if="report.context" class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Affected page or context</p><p class="mt-1 truncate text-sm font-semibold text-slate-800">{{ report.context }}</p></div>
                            <a v-if="report.attachment" :href="report.attachment.view_url" target="_blank" rel="noopener" class="inline-flex h-10 max-w-md shrink-0 items-center gap-3 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-800 hover:border-slate-950"><i class="fa-solid fa-paperclip text-slate-500" aria-hidden="true"></i><span class="max-w-64 truncate">{{ report.attachment.original_name }}</span><span class="text-xs font-normal text-slate-500">{{ fileSize(report.attachment.size) }}</span></a>
                        </div>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="case-progress-heading">
                        <header class="border-b border-slate-200 px-6 py-4"><h2 id="case-progress-heading" class="text-base font-bold text-slate-950">Case progress</h2></header>
                        <ol class="grid grid-cols-3 divide-x divide-slate-200">
                            <li v-for="(step, index) in progressSteps" :key="step.label" class="flex items-start gap-3 px-6 py-5">
                                <span :class="['grid h-8 w-8 shrink-0 place-items-center text-xs font-black', step.current ? 'bg-amber-300 text-slate-950' : step.complete ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-500']"><i v-if="step.complete" class="fa-solid fa-check" aria-hidden="true"></i><span v-else>{{ index + 1 }}</span></span>
                                <div class="min-w-0"><p class="text-sm font-bold text-slate-950">{{ step.label }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ step.detail }}</p></div>
                            </li>
                        </ol>
                    </section>

                    <section v-if="isIncomingCase" class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="provider-response-heading">
                        <header class="flex items-center justify-between gap-5 border-b border-slate-200 px-6 py-4">
                            <div><h2 id="provider-response-heading" class="text-base font-bold text-slate-950">Response to applicant</h2><p class="mt-1 text-xs text-slate-500">Give one clear answer and the next action, if any.</p></div>
                            <button v-if="!canRespond" type="button" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:border-slate-950" @click="requestReopen">Reopen response</button>
                        </header>
                        <div v-if="canRespond" class="px-6 py-5">
                            <label for="provider-response" class="sr-only">Response to applicant</label>
                            <textarea id="provider-response" v-model="responseText" rows="6" maxlength="2000" class="w-full border border-slate-300 px-4 py-3 text-sm leading-6 outline-none focus:border-slate-950" placeholder="Explain the answer and what the applicant should do next."></textarea>
                            <div class="mt-3 flex items-center justify-between"><p class="text-xs text-slate-500">{{ responseLength }}/2000 characters</p><button type="button" :disabled="isSaving || responseLength < 10" class="h-11 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40" @click="requestComplete">Send response</button></div>
                        </div>
                        <div v-else class="px-6 py-5"><p class="whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ report.provider_response || 'No written provider response was recorded.' }}</p><p v-if="report.provider_resolved_by" class="mt-4 text-xs font-semibold text-slate-500">Sent by {{ report.provider_resolved_by }} · {{ report.provider_resolved_at }}</p></div>
                    </section>

                    <section v-else class="mt-4 border border-slate-200 bg-white px-6 py-5 shadow-sm">
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Platform review</p>
                        <h2 class="mt-1 text-base font-bold text-slate-950">No provider response is required</h2>
                        <p class="mt-1 text-sm text-slate-600">Your organization submitted this issue. Platform support controls its review and closure.</p>
                    </section>
                </template>
            </div>
        </section>

        <div v-if="confirmation" class="fixed inset-0 z-[120] grid place-items-center bg-slate-950/60 p-6" role="dialog" aria-modal="true" aria-labelledby="support-confirm-title">
            <section class="w-full max-w-md border border-slate-300 bg-white shadow-2xl">
                <header class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Confirm update</p><h2 id="support-confirm-title" class="mt-1 text-xl font-bold text-slate-950">{{ confirmation.title }}</h2></header>
                <p class="px-6 py-5 text-sm leading-6 text-slate-600">{{ confirmation.detail }}</p>
                <footer class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700" @click="confirmation = null">Cancel</button><button type="button" :disabled="isSaving" class="h-10 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-40" @click="confirmStatus">{{ confirmation.action }}</button></footer>
            </section>
        </div>
    </main>
</template>
