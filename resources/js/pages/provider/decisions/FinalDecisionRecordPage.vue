<script setup>
import ProviderNextStep from '../../../components/ProviderNextStep.vue';
import WorkspaceTextSummary from '../../../components/workspace-dashboard/WorkspaceTextSummary.vue';
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import DecisionReopenDialog from '../../../components/DecisionReopenDialog.vue';
import ApplicantProfileRecord from '../../../components/profile/ApplicantProfileRecord.vue';
import StaffTableIdentity from '../../../components/StaffTableIdentity.vue';
import { applicantProfileDetails } from '../../../support/applicantProfileDetails';
import { profileEducationSummary, profileVerificationLabel } from '../../../support/applicantProfileLabels';
import { decisionReasonOptions } from '../../../support/applicationDecisionReasons';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';
import { decisionAwardLabel, finalDecisionConsequence } from '../../../support/providerDecisionConfirmation.js';
import EligibilityReviewPanel from '../reviews/components/EligibilityReviewPanel.vue';

const applicationId = document.getElementById('app')?.dataset.applicationId;
const returnTo = new URLSearchParams(window.location.search).get('return_to');
const safeReturnUrl = returnTo?.startsWith('/provider/workspaces/decisions')
    ? returnTo
    : '/provider/workspaces/decisions/pending';
const sections = [
    { key: 'decision', label: 'Decision', icon: 'fa-solid fa-gavel' },
    { key: 'summary', label: 'Review summary', icon: 'fa-solid fa-clipboard-check' },
    { key: 'applicant', label: 'Applicant', icon: 'fa-solid fa-user' },
    { key: 'history', label: 'History', icon: 'fa-solid fa-clock-rotate-left' },
];
const requestedSection = new URLSearchParams(window.location.search).get('section');
const activeSection = ref(sections.some((section) => section.key === requestedSection) ? requestedSection : 'decision');
const application = ref(null);
const isLoading = ref(true);
const actionBusy = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const showReopenDialog = ref(false);
const reopenError = ref('');
const decisionForm = ref({ outcome: '', decision_reason: '', notes: '', awarded_amount: '' });

const applicant = computed(() => application.value?.applicant ?? {});
const scholarship = computed(() => application.value?.scholarship ?? {});
const workflow = computed(() => application.value?.workflow ?? {});
const decisionReopen = computed(() => application.value?.decision_reopen ?? {});
const readiness = computed(() => application.value?.document_readiness ?? {});
const rubric = computed(() => application.value?.rubric_review ?? {});
const currentOutcome = computed(() => application.value?.final_outcome ?? workflow.value.final_outcome ?? null);
const isPending = computed(() => workflow.value.current_stage === 'decision' && !currentOutcome.value);
const isWaitlisted = computed(() => currentOutcome.value === 'waitlisted');
const isRecorded = computed(() => ['selected', 'not_selected'].includes(currentOutcome.value));
const capacity = computed(() => {
    const total = scholarship.value.slots_available === null || scholarship.value.slots_available === undefined
        ? null
        : Number(scholarship.value.slots_available);
    const occupied = Number(scholarship.value.awarded_slots_count ?? 0);
    return { total, occupied, remaining: total === null ? null : Math.max(0, total - occupied) };
});
const slotsFull = computed(() => capacity.value.remaining === 0);
const correctionPending = computed(() => ['requested', 'submitted'].includes(application.value?.correction_status));
const canDecide = computed(() => (isPending.value || isWaitlisted.value) && !workflow.value.is_closed && !correctionPending.value);
const canSubmitOutcome = computed(() => canDecide.value && !actionBusy.value && Boolean(decisionForm.value.outcome)
    && !(slotsFull.value && decisionForm.value.outcome === 'selected')
    && !(decisionForm.value.outcome === 'not_selected' && !decisionForm.value.decision_reason));
const acceptedDocuments = computed(() => application.value?.documents?.filter((document) => document.status === 'accepted').length ?? 0);
const requiredDocuments = computed(() => readiness.value.required ?? application.value?.document_checklist?.length ?? 0);
const applicantProfileRecord = computed(() => ({
    name: applicant.value?.name || applicant.value?.username || 'Applicant record',
    photoUrl: applicant.value?.profile_photo_url,
    educationSummary: profileEducationSummary(applicant.value),
}));
const applicantProfileSections = computed(() => applicantProfileDetails(applicant.value, { omitUnavailable: true }));
const responseRows = computed(() => normalizeAnswers(application.value?.application_answers));
const rejectionReasons = decisionReasonOptions.filter((option) => [
    'funds_limited',
    'not_selected',
    'program_conditions_not_met',
    'procedure_not_followed',
    'other',
].includes(option.value));
const outcomeLabel = computed(() => ({
    selected: 'Selected',
    waitlisted: 'Waitlisted',
    not_selected: 'Not selected',
}[currentOutcome.value] ?? (workflow.value.is_closed
    ? workflow.value.application_state_label || 'Closed'
    : correctionPending.value ? 'Correction open' : isPending.value ? 'Decision required' : 'Not at final decision')));

function labelize(value) {
    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not provided';
}

function normalizeAnswers(answers) {
    if (!answers) return [];
    const valueText = (value) => {
        if (Array.isArray(value)) return value.join(', ');
        if (value && typeof value === 'object') return Object.values(value).filter(Boolean).join(', ');
        return value === null || value === undefined || value === '' ? 'No response' : String(value);
    };
    if (Array.isArray(answers)) {
        return answers.map((answer, index) => ({
            label: answer.question || answer.label || answer.title || `Response ${index + 1}`,
            value: valueText(answer.answer ?? answer.value ?? answer.response ?? answer),
        }));
    }
    return Object.entries(answers).map(([key, value]) => ({ label: labelize(key), value: valueText(value) }));
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) return first;
    }
    return error.response?.data?.message || fallback;
}

function resetForm() {
    decisionForm.value = {
        outcome: '',
        decision_reason: '',
        notes: '',
        awarded_amount: application.value?.awarded_amount ?? scholarship.value.award_amount ?? '',
    };
}

function syncApplication(nextApplication) {
    application.value = nextApplication;
    resetForm();
}

async function loadApplication() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/applications/${applicationId}/data`, { params: { workspace: 'decision' } });
        syncApplication(response.data.application);
    } catch (error) {
        errorMessage.value = firstError(error, 'The decision record could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

async function askToRecordOutcome() {
    if (!canSubmitOutcome.value) return;
    const previousOutcome = currentOutcome.value;
    const { outcome, decision_reason, notes, awarded_amount } = decisionForm.value;
    const amount = awarded_amount === '' ? null : awarded_amount;
    const rejectionReason = rejectionReasons.find((option) => option.value === decision_reason)?.label;
    const promoting = previousOutcome === 'waitlisted' && outcome === 'selected';
    if (!await confirmOutcome({
        title: 'Confirm final outcome',
        record: applicant.value.name,
        outcome: promoting ? 'Promote to selected' : outcome,
        details: [
            `Program: ${scholarship.value.title}`,
            outcome === 'selected' ? `Award: ${decisionAwardLabel(amount ?? scholarship.value.award_amount)}` : '',
            outcome === 'not_selected' ? `Reason: ${rejectionReason}` : '',
        ],
        consequence: finalDecisionConsequence(outcome),
        confirmLabel: promoting ? 'Promote applicant' : 'Record final outcome',
        danger: outcome === 'not_selected',
    }, () => canSubmitOutcome.value && currentOutcome.value === previousOutcome)) return;

    await recordOutcome({
        outcome,
        expected_outcome: previousOutcome,
        decision_reason: outcome === 'not_selected' ? decision_reason : null,
        notes: notes || null,
        ...(outcome === 'selected' ? { awarded_amount: amount } : {}),
    });
}

async function recordOutcome(payload) {
    actionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/final-outcome`, payload);
        syncApplication(response.data.application);
        successMessage.value = response.data.message;
    } catch (error) {
        errorMessage.value = firstError(error, 'The final outcome could not be recorded.');
    } finally {
        actionBusy.value = false;
    }
}

async function reopenDecision(payload) {
    actionBusy.value = true;
    reopenError.value = '';
    successMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/decision/reopen`, payload);
        if (decisionReopen.value.stage !== 'decision') {
            window.location.assign(response.data.redirect_url);
            return;
        }
        syncApplication(response.data.application);
        showReopenDialog.value = false;
        successMessage.value = response.data.message;
    } catch (error) {
        reopenError.value = firstError(error, 'The decision could not be reopened.');
    } finally {
        actionBusy.value = false;
    }
}

function updateSection(section) {
    activeSection.value = section;
    const url = new URL(window.location.href);
    url.searchParams.set('section', section);
    window.history.replaceState({}, '', url);
}

onMounted(loadApplication);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="final-decision-title">
            <div class="provider-application-workspace mx-auto max-w-[94rem]">
                <a :href="safeReturnUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to final decisions</a>

                <div v-if="errorMessage" class="mb-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mb-4 border-l-4 border-emerald-500 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</div>
                <div v-if="isLoading" class="border border-slate-200 bg-white px-6 py-16 text-center">
                    <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span>
                    <p class="mt-3 text-sm font-semibold text-slate-500">Loading final decision record...</p>
                </div>

                <template v-else-if="application">
                    <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                        <div class="flex items-center justify-between gap-8">
                            <div class="flex min-w-0 items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Final decisions</p><h1 id="final-decision-title" class="application-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Decide scholarship outcome</h1><p class="mt-2 text-sm leading-6 text-slate-600">Review the completed record, confirm capacity, and record one final outcome.</p></div></div>
                            <div class="flex items-center gap-3"><button v-if="decisionReopen.can_reopen" type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950" @click="showReopenDialog = true"><i class="fa-solid fa-rotate-left mr-2" aria-hidden="true"></i>Reopen {{ decisionReopen.stage_label }}</button><span :class="['border px-3 py-2 text-xs font-bold uppercase tracking-wide', currentOutcome === 'selected' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : currentOutcome === 'waitlisted' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-100 text-slate-700']">{{ outcomeLabel }}</span></div>
                        </div>
                    </header>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-label="Applicant decision record">
                        <div class="flex items-center gap-4 px-6 py-4">
                            <StaffTableIdentity :src="applicant.profile_photo_url" class="h-14 w-14" />
                            <div class="min-w-0 flex-1"><p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-600">Candidate</p><h2 class="mt-1 application-text text-xl font-bold text-slate-950">{{ applicant.name }}</h2><p class="mt-1 application-text text-sm text-slate-500">{{ scholarship.title }} · Submitted {{ application.submitted_at || 'date unavailable' }}</p></div>
                            <div class="border-l border-slate-200 pl-6 text-right"><p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Program capacity</p><p class="mt-1 text-sm font-bold text-slate-950">{{ capacity.total === null ? 'No slot limit' : `${capacity.occupied} of ${capacity.total} awarded` }}</p><p class="mt-1 text-xs text-slate-500">{{ capacity.remaining === null ? 'Selection is not capped' : `${capacity.remaining} slots remaining` }}</p></div>
                        </div>
                    </section>

                    <ProviderNextStep :step="application.provider_next_step" />

                    <nav class="mt-4 grid grid-cols-4 border border-slate-200 bg-white shadow-sm" aria-label="Final decision sections">
                        <button v-for="section in sections" :key="section.key" type="button" :class="['flex h-14 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', activeSection === section.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']" @click="updateSection(section.key)"><i :class="[section.icon, activeSection === section.key ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>{{ section.label }}</button>
                    </nav>

                    <section v-if="activeSection === 'decision'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start gap-3 border-b border-slate-200 px-7 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">{{ isRecorded ? 'Recorded outcome' : isWaitlisted ? 'Review waitlisted applicant' : 'Record final outcome' }}</h2><p class="mt-1 text-sm text-slate-500">{{ isRecorded ? 'This decision is retained as part of the application history.' : 'The applicant is notified after the outcome is saved.' }}</p></div></header>

                        <div v-if="isRecorded" class="px-7 py-8">
                            <dl class="grid grid-cols-4 border-y border-slate-200">
                                <div class="border-r border-slate-200 px-4 py-4"><dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Outcome</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ outcomeLabel }}</dd></div>
                                <div class="border-r border-slate-200 px-4 py-4"><dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Recorded</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ application.outcome_at || application.reviewed_at || 'Recorded' }}</dd></div>
                                <div class="border-r border-slate-200 px-4 py-4"><dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Reason</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ application.decision_reason ? labelize(application.decision_reason) : 'Not required' }}</dd></div>
                                <div class="px-4 py-4"><dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Award</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ application.awarded_amount ? `PHP ${Number(application.awarded_amount).toLocaleString()}` : 'Not recorded' }}</dd></div>
                            </dl>
                            <div v-if="application.outcome_notes" class="mt-5"><p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Decision note</p><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ application.outcome_notes }}</p></div>
                            <p v-if="decisionReopen.stage === 'decision' && decisionReopen.blocked_reason" class="mt-5 border-l-4 border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ decisionReopen.blocked_reason }}</p>
                        </div>

                        <form v-else-if="canDecide" class="px-7 py-7" @submit.prevent="askToRecordOutcome">
                            <div v-if="['required_mismatch', 'preferred_difference', 'manual_review', 'provisional'].includes(application.dss_explanation?.comparison?.state)" class="mb-5 flex items-start justify-between gap-6 border-l-4 border-amber-400 bg-amber-50 px-4 py-3">
                                <div><p class="text-sm font-bold text-amber-950">{{ application.dss_explanation.comparison.label }}</p><p class="mt-1 text-xs leading-5 text-amber-900">{{ application.dss_explanation.comparison.interpretation }}</p></div>
                                <button type="button" class="shrink-0 border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="activeSection = 'summary'">Review comparison</button>
                            </div>
                            <fieldset>
                                <legend class="mb-3 text-sm font-bold text-slate-950">Choose an outcome</legend>
                                <div :class="['grid gap-3', isWaitlisted ? 'grid-cols-2' : 'grid-cols-3']">
                                    <label :class="['flex items-start gap-3 border px-4 py-4', slotsFull ? 'cursor-not-allowed opacity-50' : 'cursor-pointer', decisionForm.outcome === 'selected' ? 'border-slate-950 bg-slate-50' : 'border-slate-300']"><input v-model="decisionForm.outcome" class="mt-1" type="radio" name="final-outcome" value="selected" :disabled="slotsFull"><span><span class="block text-sm font-bold text-slate-950">{{ isWaitlisted ? 'Promote to selected' : 'Selected' }}</span><span class="mt-1 block text-xs text-slate-600">Offer the scholarship award.</span></span></label>
                                    <label v-if="!isWaitlisted" :class="['flex cursor-pointer items-start gap-3 border px-4 py-4', decisionForm.outcome === 'waitlisted' ? 'border-slate-950 bg-slate-50' : 'border-slate-300']"><input v-model="decisionForm.outcome" class="mt-1" type="radio" name="final-outcome" value="waitlisted"><span><span class="block text-sm font-bold text-slate-950">Waitlisted</span><span class="mt-1 block text-xs text-slate-600">Keep in consideration without an award.</span></span></label>
                                    <label :class="['flex cursor-pointer items-start gap-3 border px-4 py-4', decisionForm.outcome === 'not_selected' ? 'border-slate-950 bg-slate-50' : 'border-slate-300']"><input v-model="decisionForm.outcome" class="mt-1" type="radio" name="final-outcome" value="not_selected"><span><span class="block text-sm font-bold text-slate-950">Not selected</span><span class="mt-1 block text-xs text-slate-600">Close without an award.</span></span></label>
                                </div>
                            </fieldset>

                            <p v-if="slotsFull" class="mt-4 border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">All configured slots are currently occupied. Selection becomes available when capacity opens.</p>
                            <label v-if="decisionForm.outcome === 'not_selected'" class="mt-5 block w-full text-sm font-semibold text-slate-700">Decision reason<select v-model="decisionForm.decision_reason" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950"><option value="" disabled>Select a reason</option><option v-for="reason in rejectionReasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option></select></label>
                            <label v-if="decisionForm.outcome === 'selected'" class="mt-5 block w-full text-sm font-semibold text-slate-700">Awarded amount <span class="font-normal text-slate-600">(optional)</span><div class="mt-2 flex"><span class="grid h-11 place-items-center border border-r-0 border-slate-300 bg-slate-50 px-3 text-sm font-bold text-slate-500">PHP</span><input v-model="decisionForm.awarded_amount" type="number" min="0" max="999999999.99" step="0.01" class="h-11 min-w-0 flex-1 border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></div><span class="mt-2 block text-xs font-normal text-slate-600">Leave blank to use the program's award terms.</span></label>
                            <label v-if="decisionForm.outcome" class="mt-5 block text-sm font-semibold text-slate-700">Decision note <span class="font-normal text-slate-600">(optional, visible to the applicant)</span><textarea v-model="decisionForm.notes" maxlength="2000" rows="3" class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950" placeholder="Add information that helps the applicant understand the outcome."></textarea></label>
                            <div class="mt-5 flex items-center gap-3"><button type="submit" class="inline-flex h-11 items-center gap-2 rounded-[5px] bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-60" :disabled="!canSubmitOutcome"><i :class="['fa-solid text-[10px]', actionBusy ? 'fa-spinner fa-spin' : 'fa-clipboard-check']" aria-hidden="true"></i>{{ actionBusy ? 'Saving...' : 'Review outcome' }}</button></div>
                        </form>
                        <p v-else class="px-7 py-7 text-sm text-slate-700">{{ correctionPending ? 'Resolve the open correction request before recording a final outcome.' : 'This application is not awaiting a final outcome.' }}</p>
                    </section>

                    <section v-else-if="activeSection === 'summary'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clipboard-check text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Verified review summary</h2><p class="mt-1 text-sm text-slate-500">Read-only results from the teams that completed earlier stages.</p></div></header>
                        <dl class="grid grid-cols-4 border-b border-slate-200 bg-slate-50">
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Eligibility comparison</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ application.dss_explanation?.comparison?.label || 'Comparison unavailable' }}</dd></div>
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Evidence</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ acceptedDocuments }} of {{ requiredDocuments }} accepted</dd></div>
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Profile</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ labelize(applicant.profile_verification_status) }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Review score</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ rubric.total_score === null || rubric.total_score === undefined ? 'Not scored' : `${rubric.total_score}%` }}</dd></div>
                        </dl>

                        <div class="px-6 py-5">
                            <EligibilityReviewPanel :breakdown="application.eligibility_breakdown ?? {}" :explanation="application.dss_explanation ?? {}" :score="application.dss_breakdown ?? {}" embedded compact class="mb-5 border-y border-slate-200" />
                            <h3 class="text-base font-bold text-slate-950">Completed selection path</h3>
                            <ol class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                                <li v-for="step in workflow.steps" :key="step.key" class="grid grid-cols-[11rem_8rem_minmax(0,1fr)_10rem] items-center gap-5 py-4"><p class="text-sm font-bold text-slate-900">{{ step.label }}</p><p class="text-xs font-bold uppercase tracking-wide text-slate-600">{{ labelize(step.status) }}</p><WorkspaceTextSummary class="text-sm text-slate-600" :text="step.notes || step.description" label="note" /><p class="text-right text-xs font-semibold text-slate-500">{{ step.completed_at || step.started_at || 'Pending' }}</p></li>
                            </ol>
                        </div>

                        <div class="border-t border-slate-200 px-6 py-5">
                            <div class="flex items-center justify-between"><div><h3 class="text-base font-bold text-slate-950">Supporting evidence</h3><p class="mt-1 text-sm text-slate-500">Documents are read-only in this workspace.</p></div><span class="text-xs font-bold text-slate-500">{{ application.documents?.length || 0 }} files</span></div>
                            <p v-if="!application.documents?.length" class="mt-4 border-y border-slate-200 py-5 text-sm text-slate-500">No supporting files are attached.</p>
                            <div v-else class="mt-4 overflow-x-auto border-y border-slate-200"><table class="w-full min-w-[48rem] text-left"><thead class="bg-slate-50 text-xs font-bold uppercase tracking-[0.14em] text-slate-600"><tr><th class="px-4 py-3">Document</th><th class="px-4 py-3">Review status</th><th class="px-4 py-3">Reviewed by</th><th class="px-4 py-3 text-right">File</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-for="document in application.documents" :key="document.id"><td class="px-4 py-3"><p class="text-sm font-bold text-slate-900">{{ document.document_name }}</p><p class="mt-1 text-xs text-slate-500">{{ document.original_name }}</p></td><td class="px-4 py-3 text-sm font-semibold text-slate-700">{{ labelize(document.status) }}</td><td class="px-4 py-3 text-sm text-slate-600">{{ document.reviewed_by || 'Not reviewed' }}</td><td class="px-4 py-3 text-right"><a :href="document.view_url" target="_blank" rel="noopener" class="inline-flex h-8 items-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:border-slate-950">Open<i class="fa-solid fa-arrow-up-right-from-square text-[9px]" aria-hidden="true"></i></a></td></tr></tbody></table></div>
                        </div>
                    </section>

                    <div v-else-if="activeSection === 'applicant'" class="mt-4 space-y-4">
                        <ApplicantProfileRecord
                            :record="applicantProfileRecord"
                            :sections="applicantProfileSections"
                            :verification-status="applicant.profile_verification_status"
                            :verification-label="profileVerificationLabel(applicant.profile_verification_status)"
                            :show-trust-notice="false"
                            show-data-source-notice
                        />
                        <section class="border border-slate-200 bg-white px-6 py-5 shadow-sm">
                            <h3 class="text-base font-bold text-slate-950">Application responses</h3>
                            <p class="mt-1 text-sm text-slate-500">Answers submitted specifically for this scholarship.</p>
                            <p v-if="!responseRows.length" class="mt-4 text-sm text-slate-500">No additional responses were collected.</p>
                            <dl v-else class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                                <div v-for="response in responseRows" :key="response.label" class="grid grid-cols-[18rem_1fr] gap-6 py-4">
                                    <dt class="text-sm font-semibold text-slate-500">{{ response.label }}</dt>
                                    <dd class="whitespace-pre-line text-sm font-semibold leading-6 text-slate-900">{{ response.value }}</dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <section v-else class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Decision history</h2><p class="mt-1 text-sm text-slate-500">Recorded application changes in chronological order.</p></div></header>
                        <div v-if="!application.timeline?.length" class="px-6 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No history has been recorded.</p></div>
                        <ol v-else class="divide-y divide-slate-200"><li v-for="entry in [...application.timeline].reverse()" :key="entry.id" class="grid grid-cols-[10rem_1fr_13rem] gap-6 px-6 py-4"><p class="text-xs font-semibold text-slate-500">{{ entry.changed_at || 'Date unavailable' }}</p><div><p class="text-sm font-bold text-slate-900">{{ labelize(entry.to_status) }}</p><p v-if="entry.review_notes" class="mt-1 text-sm leading-6 text-slate-600">{{ entry.review_notes }}</p></div><p class="text-right text-xs font-semibold text-slate-500">{{ entry.actor }}</p></li></ol>
                    </section>
                </template>
            </div>
        </section>

        <DecisionReopenDialog :open="showReopenDialog" :decision="decisionReopen" :record-name="applicant.name" :busy="actionBusy" :error="reopenError" @confirm="reopenDecision" @cancel="showReopenDialog = false; reopenError = ''" />
    </main>
</template>
