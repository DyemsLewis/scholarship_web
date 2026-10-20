<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import ConfirmationDialog from '../../../components/ConfirmationDialog.vue';
import DecisionReopenDialog from '../../../components/DecisionReopenDialog.vue';

const applicationId = document.getElementById('app')?.dataset.applicationId;
const returnTo = new URLSearchParams(window.location.search).get('return_to');
const safeReturnUrl = returnTo?.startsWith('/provider/workspaces/recipients')
    ? returnTo
    : '/provider/workspaces/recipients/agreements';
const sections = [
    { key: 'agreement', label: 'Agreement', icon: 'fa-solid fa-file-signature' },
    { key: 'support', label: 'Support status', icon: 'fa-solid fa-hand-holding-heart' },
    { key: 'activity', label: 'Support activity', icon: 'fa-solid fa-list-check' },
    { key: 'history', label: 'History', icon: 'fa-solid fa-clock-rotate-left' },
];
const requestedSection = new URLSearchParams(window.location.search).get('section');
const activeSection = ref(sections.some((section) => section.key === requestedSection) ? requestedSection : 'agreement');
const record = ref(null);
const isLoading = ref(true);
const actionBusy = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const confirmation = ref(null);
const showReopenDialog = ref(false);
const supportForm = ref({
    decision: 'renewed',
    reason_category: 'requirements_met',
    effective_on: today(),
    support_ends_on: '',
    next_review_on: '',
    notice_given_on: '',
    reason: '',
    next_period_terms: '',
    decision_document: null,
    confirmed: false,
});
const resolutionForm = ref({
    resolution_outcome: 'clarified',
    resolution_notes: '',
    resolution_proof: null,
    support_ends_on: '',
    next_review_on: '',
    next_period_terms: '',
});

const recipient = computed(() => record.value?.recipient ?? {});
const program = computed(() => record.value?.program ?? {});
const agreement = computed(() => record.value?.agreement ?? {});
const support = computed(() => record.value?.support ?? {});
const summary = computed(() => record.value?.summary ?? {});
const decisionReopen = computed(() => record.value?.decision_reopen ?? {});
const latestDecision = computed(() => support.value.latest_decision ?? record.value?.decisions?.[0] ?? null);
const agreementAccepted = computed(() => agreement.value.status === 'accepted');
const agreementDeclined = computed(() => agreement.value.status === 'declined');
const supportClosed = computed(() => Boolean(support.value.is_closed));
const openResponse = computed(() => latestDecision.value?.response_status === 'open' ? latestDecision.value : null);
const decisionCategories = computed(() => ({
    renewed: [
        ['requirements_met', 'Requirements met'],
        ['support_period_extended', 'Support period extended'],
        ['continued_funding', 'Continued funding approved'],
    ],
    completed: [
        ['program_completed', 'Program completed'],
        ['support_period_ended', 'Support period ended'],
        ['recipient_withdrew', 'Recipient withdrew'],
    ],
    terminated: [
        ['requirement_not_met', 'Requirement not met'],
        ['document_noncompliance', 'Document noncompliance'],
        ['misrepresentation', 'Misrepresentation'],
        ['recipient_withdrew', 'Recipient withdrew'],
        ['provider_funding_ended', 'Provider funding ended'],
        ['other', 'Other'],
    ],
}[supportForm.value.decision]));
const agreementRows = computed(() => {
    const snapshot = agreement.value.snapshot ?? {};
    return [
        ['Award amount', snapshot.award_amount !== null && snapshot.award_amount !== undefined ? `PHP ${Number(snapshot.award_amount).toLocaleString()}` : 'Program benefits'],
        ['Support period', [snapshot.support_starts_at, snapshot.support_ends_at].filter(Boolean).join(' to ') || 'Dates not specified'],
        ['Renewal policy', snapshot.renewal_policy],
        ['Return service', snapshot.return_service_contract],
        ['Other terms', snapshot.other_contract_terms],
    ].filter(([, value]) => value);
});

function today() {
    const date = new Date();
    const offset = date.getTimezoneOffset() * 60 * 1000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 10);
}

function labelize(value) {
    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not provided';
}

function displayValue(value) {
    if (Array.isArray(value)) return value.map((item) => displayValue(item)).filter(Boolean).join(' · ');
    if (value && typeof value === 'object') return Object.entries(value).map(([key, item]) => `${labelize(key)}: ${displayValue(item)}`).join(' · ');
    return value === null || value === undefined || value === '' ? '' : String(value);
}

function statusClass(status) {
    if (['accepted', 'active', 'renewed', 'released', 'met', 'excused', 'completed'].includes(status)) return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (['pending', 'scheduled', 'prepared', 'open'].includes(status)) return 'border-amber-200 bg-amber-50 text-amber-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) return first;
    }
    return error.response?.data?.message || fallback;
}

function resetSupportForm() {
    const decision = support.value.renewal_eligible ? 'renewed' : 'completed';
    supportForm.value = {
        decision,
        reason_category: decision === 'renewed' ? 'requirements_met' : 'program_completed',
        effective_on: today(),
        support_ends_on: '',
        next_review_on: '',
        notice_given_on: '',
        reason: '',
        next_period_terms: '',
        decision_document: null,
        confirmed: false,
    };
}

async function loadRecord() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/applications/${applicationId}/recipient-record`);
        record.value = response.data.record;
        resetSupportForm();
        if (!requestedSection && agreementAccepted.value) activeSection.value = 'support';
    } catch (error) {
        errorMessage.value = firstError(error, 'The recipient record could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

watch(() => supportForm.value.decision, (decision) => {
    const options = {
        renewed: 'requirements_met',
        completed: 'program_completed',
        terminated: 'requirement_not_met',
    };
    supportForm.value.reason_category = options[decision];
});

function askToSaveSupport() {
    const labels = { renewed: 'renew this recipient support', completed: 'mark this program support complete', terminated: 'end this recipient support early' };
    confirmation.value = {
        type: 'support',
        title: 'Confirm support outcome',
        message: `This will ${labels[supportForm.value.decision]} and notify the recipient.`,
        confirmLabel: 'Record outcome',
        tone: supportForm.value.decision === 'terminated' ? 'danger' : 'warning',
    };
}

function askToResolveRequest() {
    confirmation.value = {
        type: 'resolution',
        title: 'Resolve recipient request',
        message: 'This records the provider response and notifies the recipient.',
        confirmLabel: 'Resolve request',
        tone: 'warning',
    };
}

async function confirmAction() {
    const action = confirmation.value?.type;
    confirmation.value = null;
    if (action === 'support') await saveSupportDecision();
    if (action === 'resolution') await resolveRequest();
}

function appendFormData(formData, key, value) {
    if (value !== null && value !== undefined && value !== '') formData.append(key, value);
}

async function saveSupportDecision() {
    actionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const formData = new FormData();
        Object.entries(supportForm.value).forEach(([key, value]) => appendFormData(formData, key, key === 'confirmed' ? (value ? '1' : '') : value));
        const response = await window.axios.post(`/provider/applications/${applicationId}/support-decision`, formData);
        successMessage.value = response.data.message;
        await loadRecord();
    } catch (error) {
        errorMessage.value = firstError(error, 'The support outcome could not be recorded.');
    } finally {
        actionBusy.value = false;
    }
}

async function resolveRequest() {
    if (!openResponse.value) return;
    actionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const formData = new FormData();
        Object.entries(resolutionForm.value).forEach(([key, value]) => appendFormData(formData, key, value));
        const response = await window.axios.post(`/provider/support-decisions/${openResponse.value.id}/resolve`, formData);
        successMessage.value = response.data.message;
        await loadRecord();
    } catch (error) {
        errorMessage.value = firstError(error, 'The recipient request could not be resolved.');
    } finally {
        actionBusy.value = false;
    }
}

async function reopenDecision(reason) {
    actionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/decision/reopen`, { reason });
        showReopenDialog.value = false;
        window.location.assign(response.data.redirect_url);
    } catch (error) {
        errorMessage.value = firstError(error, 'The decision could not be reopened.');
        actionBusy.value = false;
    }
}

function updateSection(section) {
    activeSection.value = section;
    const url = new URL(window.location.href);
    url.searchParams.set('section', section);
    window.history.replaceState({}, '', url);
}

onMounted(loadRecord);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="recipient-record-title">
            <div class="mx-auto max-w-[94rem]">
                <a :href="safeReturnUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to recipients</a>
                <div v-if="errorMessage" class="mb-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mb-4 border-l-4 border-emerald-500 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</div>
                <div v-if="isLoading" class="border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading recipient record...</p></div>

                <template v-else-if="record">
                    <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                        <div class="flex items-center justify-between gap-8"><div class="flex min-w-0 items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Recipient management</p><h1 id="recipient-record-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Manage recipient support</h1><p class="mt-2 text-sm leading-6 text-slate-600">Review the agreement, support status, and recorded outcomes.</p></div></div><div class="flex items-center gap-3"><button v-if="decisionReopen.can_reopen" type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950" @click="showReopenDialog = true"><i class="fa-solid fa-rotate-left mr-2" aria-hidden="true"></i>Reopen final decision</button><span :class="['border px-3 py-2 text-[10px] font-bold uppercase tracking-wide', statusClass(support.is_closed ? 'closed' : agreement.status)]">{{ support.is_closed ? support.support_status_label : agreement.status_label }}</span></div></div>
                    </header>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Recipient identity">
                        <div class="flex items-center gap-4 px-6 py-4"><img v-if="recipient.profile_photo_url" :src="recipient.profile_photo_url" :alt="`${recipient.name} profile`" class="h-14 w-14 shrink-0 border border-slate-200 object-cover"><span v-else class="grid h-14 w-14 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i></span><div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">Recipient</p><h2 class="mt-1 truncate text-xl font-bold text-slate-950">{{ recipient.name }}</h2><p class="mt-1 truncate text-sm text-slate-500">{{ program.title }} · {{ recipient.email }}</p></div><div class="border-l border-slate-200 pl-6 text-right"><p class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Current support</p><p class="mt-1 text-sm font-bold text-slate-950">{{ support.support_status_label }}</p><p class="mt-1 text-xs text-slate-500">{{ agreement.status_label }}</p></div></div>
                    </section>

                    <nav class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Recipient record sections">
                        <button v-for="section in sections" :key="section.key" type="button" :class="['flex h-14 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', activeSection === section.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']" @click="updateSection(section.key)"><i :class="[section.icon, activeSection === section.key ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>{{ section.label }}</button>
                    </nav>

                    <section v-if="activeSection === 'agreement'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start justify-between gap-8 border-b border-slate-200 px-6 py-5"><div class="flex items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-signature text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Recipient agreement</h2><p class="mt-1 text-sm text-slate-500">Terms saved when the scholarship was awarded.</p></div></div><div class="text-right"><span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(agreement.status)]">{{ agreement.status_label }}</span><p v-if="agreement.responded_at" class="mt-2 text-xs text-slate-500">Responded {{ agreement.responded_at }}</p></div></header>
                        <div v-if="!agreement.snapshot" class="px-6 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-circle-xmark text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No recipient agreement snapshot is available.</p></div>
                        <template v-else>
                            <div v-if="agreementDeclined" class="border-b border-slate-200 bg-amber-50 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Recipient response</p><p class="mt-2 text-sm font-semibold leading-6 text-amber-950">{{ agreement.response_note || 'The recipient declined the recorded terms.' }}</p></div>
                            <dl class="divide-y divide-slate-200">
                                <div v-for="([label, value]) in agreementRows" :key="label" class="grid grid-cols-[15rem_1fr] gap-6 px-6 py-4"><dt class="text-sm font-semibold text-slate-500">{{ label }}</dt><dd class="whitespace-pre-line text-sm font-semibold leading-6 text-slate-900">{{ value }}</dd></div>
                            </dl>
                            <div v-if="agreement.snapshot.benefits?.length" class="border-t border-slate-200 px-6 py-5"><h3 class="text-base font-bold text-slate-950">Included benefits</h3><ul class="mt-3 divide-y divide-slate-200 border-y border-slate-200"><li v-for="(benefit, index) in agreement.snapshot.benefits" :key="index" class="flex items-center justify-between gap-6 py-3"><span class="text-sm font-semibold text-slate-900">{{ benefit.label || benefit.title || labelize(benefit.type) }}</span><span class="text-sm text-slate-600">{{ benefit.amount_label || displayValue(benefit) }}</span></li></ul></div>
                            <div v-if="displayValue(agreement.snapshot.recipient_expectation)" class="border-t border-slate-200 px-6 py-5"><h3 class="text-base font-bold text-slate-950">Recipient responsibilities</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ displayValue(agreement.snapshot.recipient_expectation) }}</p></div>
                        </template>
                    </section>

                    <section v-else-if="activeSection === 'support'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-hand-holding-heart text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Support status</h2><p class="mt-1 text-sm text-slate-500">Record renewal, completion, or early termination only after onboarding.</p></div></header>
                        <dl class="grid grid-cols-4 border-b border-slate-200 bg-slate-50"><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Status</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ support.support_status_label }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Monitoring</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ support.requirements_met }} of {{ support.requirements_total }} confirmed</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Benefit releases</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ support.released_count }} of {{ support.release_count }} released</dd></div><div class="px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Renewal review</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ support.renewal_eligibility_label }}</dd></div></dl>

                        <div v-if="!agreementAccepted" class="px-6 py-10 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-signature text-sm" aria-hidden="true"></i></span><h3 class="mt-3 text-lg font-bold text-slate-950">Agreement acceptance required</h3><p class="mt-2 text-sm text-slate-500">Support actions become available after the recipient accepts the terms.</p><button type="button" class="mt-5 inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" @click="updateSection('agreement')"><i class="fa-solid fa-arrow-left text-[9px]" aria-hidden="true"></i>Review agreement</button></div>

                        <template v-else>
                            <div v-if="openResponse" class="border-b border-slate-200 px-6 py-6">
                                <div class="border-l-4 border-l-amber-400 bg-amber-50 px-5 py-4"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Recipient request</p><h3 class="mt-1 text-base font-bold text-amber-950">{{ openResponse.applicant_response_label }}</h3><p class="mt-2 text-sm leading-6 text-amber-900">{{ openResponse.applicant_response_message }}</p><a v-if="openResponse.applicant_response_file" :href="openResponse.applicant_response_file.view_url" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-2 text-xs font-bold text-amber-950 underline">Open attached file<i class="fa-solid fa-arrow-up-right-from-square text-[9px]" aria-hidden="true"></i></a></div>
                                <form class="mt-5" @submit.prevent="askToResolveRequest"><div class="grid grid-cols-3 gap-4"><label class="text-sm font-semibold text-slate-700">Resolution<select v-model="resolutionForm.resolution_outcome" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950"><option value="clarified">Clarification provided</option><option value="decision_upheld">Decision upheld</option><option v-if="openResponse.decision === 'terminated'" value="support_reinstated">Support reinstated</option></select></label><label v-if="resolutionForm.resolution_outcome === 'support_reinstated'" class="text-sm font-semibold text-slate-700">Support ends<input v-model="resolutionForm.support_ends_on" type="date" :min="today()" required class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label><label v-if="resolutionForm.resolution_outcome === 'support_reinstated'" class="text-sm font-semibold text-slate-700">Next review <span class="font-normal text-slate-400">(optional)</span><input v-model="resolutionForm.next_review_on" type="date" :min="today()" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label><label class="col-span-3 text-sm font-semibold text-slate-700">Resolution notes<textarea v-model="resolutionForm.resolution_notes" required minlength="5" maxlength="1500" rows="3" class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950"></textarea></label><label v-if="resolutionForm.resolution_outcome === 'support_reinstated'" class="col-span-3 text-sm font-semibold text-slate-700">Reinstated terms<textarea v-model="resolutionForm.next_period_terms" required minlength="10" maxlength="2000" rows="3" class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950"></textarea></label><label class="col-span-3 text-sm font-semibold text-slate-700">Resolution proof <span class="font-normal text-slate-400">(optional)</span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full border border-slate-300 bg-white px-3 py-2 text-sm" @change="resolutionForm.resolution_proof = $event.target.files?.[0] || null"></label></div><button type="submit" class="mt-5 inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-60" :disabled="actionBusy"><i :class="['fa-solid text-[10px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-circle-check']" aria-hidden="true"></i>Resolve request</button></form>
                            </div>

                            <div v-if="supportClosed" class="px-6 py-8"><h3 class="text-lg font-bold text-slate-950">Support record closed</h3><p class="mt-2 text-sm text-slate-600">{{ latestDecision?.reason || latestDecision?.next_period_terms || 'The recorded support period is complete.' }}</p></div>

                            <form v-else class="px-6 py-6" @submit.prevent="askToSaveSupport">
                                <p class="text-sm font-semibold text-slate-700">{{ support.renewal_eligibility_reason }}</p>
                                <div class="mt-5 grid grid-cols-3 gap-3"><label :class="['cursor-pointer border px-4 py-4', supportForm.decision === 'renewed' ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 text-slate-700', !support.renewal_eligible ? 'cursor-not-allowed opacity-40' : '']"><input v-model="supportForm.decision" class="sr-only" type="radio" value="renewed" :disabled="!support.renewal_eligible"><span class="text-sm font-bold">Renew support</span><span class="mt-1 block text-xs opacity-70">Continue for another period.</span></label><label :class="['cursor-pointer border px-4 py-4', supportForm.decision === 'completed' ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 text-slate-700']"><input v-model="supportForm.decision" class="sr-only" type="radio" value="completed"><span class="text-sm font-bold">Complete support</span><span class="mt-1 block text-xs opacity-70">Close a finished program period.</span></label><label :class="['cursor-pointer border px-4 py-4', supportForm.decision === 'terminated' ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 text-slate-700']"><input v-model="supportForm.decision" class="sr-only" type="radio" value="terminated"><span class="text-sm font-bold">End support early</span><span class="mt-1 block text-xs opacity-70">Close before normal completion.</span></label></div>
                                <div class="mt-5 grid grid-cols-3 gap-4"><label class="text-sm font-semibold text-slate-700">Reason category<select v-model="supportForm.reason_category" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950"><option v-for="([value, label]) in decisionCategories" :key="value" :value="value">{{ label }}</option></select></label><label class="text-sm font-semibold text-slate-700">Effective date<input v-model="supportForm.effective_on" type="date" required :max="today()" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label><label v-if="supportForm.decision === 'renewed'" class="text-sm font-semibold text-slate-700">Support ends<input v-model="supportForm.support_ends_on" type="date" required :min="supportForm.effective_on" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label><label v-if="supportForm.decision === 'renewed'" class="text-sm font-semibold text-slate-700">Next review <span class="font-normal text-slate-400">(optional)</span><input v-model="supportForm.next_review_on" type="date" :min="supportForm.effective_on" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label><label v-if="supportForm.decision === 'terminated'" class="text-sm font-semibold text-slate-700">Notice date<input v-model="supportForm.notice_given_on" type="date" required :max="supportForm.effective_on" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label><label :class="['text-sm font-semibold text-slate-700', supportForm.decision === 'renewed' ? 'col-span-3' : 'col-span-2']">{{ supportForm.decision === 'renewed' ? 'Next period terms' : 'Reason' }}<textarea v-if="supportForm.decision === 'renewed'" v-model="supportForm.next_period_terms" required minlength="10" maxlength="2000" rows="3" class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950"></textarea><textarea v-else v-model="supportForm.reason" required minlength="10" maxlength="2000" rows="3" class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950"></textarea></label><label class="col-span-3 text-sm font-semibold text-slate-700">Decision document <span class="font-normal text-slate-400">(optional)</span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-2 block w-full border border-slate-300 bg-white px-3 py-2 text-sm" @change="supportForm.decision_document = $event.target.files?.[0] || null"></label></div>
                                <label class="mt-5 flex items-start gap-3 border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700"><input v-model="supportForm.confirmed" type="checkbox" required class="mt-0.5 h-4 w-4">I confirmed the supporting monitoring and release records before saving this outcome.</label><button type="submit" class="mt-5 inline-flex h-11 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-60" :disabled="actionBusy"><i :class="['fa-solid text-[10px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>Record support outcome</button>
                            </form>
                        </template>
                    </section>

                    <section v-else-if="activeSection === 'activity'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-list-check text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Support activity</h2><p class="mt-1 text-sm text-slate-500">Read-only summary of monitoring and benefit release records.</p></div></header>
                        <dl class="grid grid-cols-4 border-b border-slate-200 bg-slate-50"><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Monitoring periods</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ summary.monitoring_total || 0 }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Confirmed records</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ summary.monitoring_confirmed || 0 }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Release schedules</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ summary.release_total || 0 }}</dd></div><div class="px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Released</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ summary.released_total || 0 }}</dd></div></dl>
                        <div class="px-6 py-5"><h3 class="text-base font-bold text-slate-950">Monitoring</h3><div v-if="!record.monitoring_records?.length" class="mt-3 border-y border-slate-200 py-5 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clipboard-list text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No monitoring periods are recorded.</p></div><div v-else class="mt-4 overflow-x-auto border-y border-slate-200"><table class="w-full min-w-[52rem] text-left"><thead class="bg-slate-50 text-[9px] font-bold uppercase tracking-[0.14em] text-slate-500"><tr><th class="px-4 py-3">Period</th><th class="px-4 py-3">Requirement</th><th class="px-4 py-3">Due</th><th class="px-4 py-3">Status</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in record.monitoring_records" :key="item.id"><td class="px-4 py-3"><p class="text-sm font-bold text-slate-900">{{ item.title }}</p><p class="mt-1 text-xs text-slate-500">{{ item.period_label }}</p></td><td class="px-4 py-3 text-sm text-slate-700">{{ item.requirement_label }}</td><td class="px-4 py-3 text-sm text-slate-700">{{ item.due_label }}</td><td class="px-4 py-3"><span :class="['inline-flex border px-2 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(item.submission?.review_status || 'pending')]">{{ item.submission?.review_status_label || 'Awaiting submission' }}</span></td></tr></tbody></table></div></div>
                        <div class="border-t border-slate-200 px-6 py-5"><h3 class="text-base font-bold text-slate-950">Benefit releases</h3><div v-if="!record.benefit_releases?.length" class="mt-3 border-y border-slate-200 py-5 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-hand-holding-dollar text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No benefit releases are recorded.</p></div><div v-else class="mt-4 overflow-x-auto border-y border-slate-200"><table class="w-full min-w-[52rem] text-left"><thead class="bg-slate-50 text-[9px] font-bold uppercase tracking-[0.14em] text-slate-500"><tr><th class="px-4 py-3">Release</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Method</th><th class="px-4 py-3">Status</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="item in record.benefit_releases" :key="item.id"><td class="px-4 py-3"><p class="text-sm font-bold text-slate-900">{{ item.title }}</p><p class="mt-1 text-xs text-slate-500">{{ item.amount_label || item.benefit_description }}</p></td><td class="px-4 py-3 text-sm text-slate-700">{{ item.release_label }}</td><td class="px-4 py-3 text-sm text-slate-700">{{ item.method_label }}</td><td class="px-4 py-3"><span :class="['inline-flex border px-2 py-1 text-[10px] font-bold uppercase tracking-wide', statusClass(item.status)]">{{ item.status_label }}</span></td></tr></tbody></table></div></div>
                    </section>

                    <section v-else class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Recipient history</h2><p class="mt-1 text-sm text-slate-500">Agreement, monitoring, releases, and support outcomes in one timeline.</p></div></header><div v-if="!record.timeline?.length" class="px-6 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No recipient history has been recorded.</p></div><ol v-else class="divide-y divide-slate-200"><li v-for="(event, index) in record.timeline" :key="`${event.type}-${index}`" class="grid grid-cols-[10rem_1fr_12rem] gap-6 px-6 py-4"><p class="text-xs font-semibold text-slate-500">{{ event.occurred_label || 'Date unavailable' }}</p><div><p class="text-sm font-bold text-slate-900">{{ event.title }}</p><p class="mt-1 text-sm leading-6 text-slate-600">{{ event.description }}</p><a v-if="event.file" :href="event.file.view_url" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-2 text-xs font-bold text-slate-700 underline">Open {{ event.file.original_name }}<i class="fa-solid fa-arrow-up-right-from-square text-[9px]" aria-hidden="true"></i></a></div><p class="text-right text-xs font-bold text-slate-500">{{ event.status_label }}</p></li></ol>
                    </section>
                </template>
            </div>
        </section>

        <ConfirmationDialog :open="Boolean(confirmation)" :title="confirmation?.title" :message="confirmation?.message" :confirm-label="confirmation?.confirmLabel" :tone="confirmation?.tone" @confirm="confirmAction" @cancel="confirmation = null" />
        <DecisionReopenDialog :open="showReopenDialog" :decision="decisionReopen" :busy="actionBusy" @confirm="reopenDecision" @cancel="showReopenDialog = false" />
    </main>
</template>
