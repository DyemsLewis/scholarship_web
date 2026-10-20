<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ConfirmationDialog from '../../../components/ConfirmationDialog.vue';
import MonitoringCaseHistoryModal from '../../../components/MonitoringCaseHistoryModal.vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const cycleId = document.getElementById('app')?.dataset.cycleId;
const returnTo = new URLSearchParams(window.location.search).get('return_to');
const safeReturnUrl = returnTo?.startsWith('/provider/workspaces/monitoring')
    ? returnTo
    : '/provider/workspaces/monitoring/review';
const program = ref({});
const cycle = ref(null);
const isLoading = ref(true);
const actionBusy = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const recipientFilter = ref('action');
const selectedRecipientId = ref(null);
const selectedRequirementId = ref(null);
const actionMode = ref('');
const confirmation = ref(null);
const reviewForm = ref({ decision: 'met', notes: '' });
const adjustmentForm = ref({ decision: 'approved', decision_notes: '', approved_due_at: '' });
const providerResultForm = ref({ decision: 'met', notes: '' });
const followUpForm = ref({ type: 'reminder', summary: '', action_required: '', follow_up_on: '' });
const completionForm = ref({ completion_notes: '' });
const completionTarget = ref(null);
const caseDecisionMode = ref('');
const caseDecisionBusy = ref(false);
const caseDecisionForm = ref({ outcome: 'completed', reason: '' });
const historyOpen = ref(false);

const recipients = computed(() => cycle.value?.recipients ?? []);
const selectedRecipient = computed(() => recipients.value.find((recipient) => recipient.application_id === selectedRecipientId.value) ?? null);
const selectedRequirement = computed(() => selectedRecipient.value?.requirements?.find((requirement) => requirement.id === selectedRequirementId.value) ?? null);
const totalRequirements = computed(() => recipients.value.reduce((total, recipient) => total + recipient.requirements.length, 0));
const reviewedRequirements = computed(() => recipients.value.reduce((total, recipient) => total + recipient.requirements.filter((requirement) => requirement.submission?.reviewed_at).length, 0));
const receivedFiles = computed(() => recipients.value.reduce((total, recipient) => total + Number(recipient.submitted_item_count ?? 0), 0));

function requirementState(requirement) {
    if (requirement.adjustment_request?.status === 'pending') return 'request';
    if (requirement.interventions?.some((item) => item.status === 'open')) return 'followup';
    if (requirement.submission && !requirement.submission.reviewed_at) return 'review';
    if (['not_met', 'needs_correction'].includes(requirement.submission?.review_status)) return 'followup';
    if (!requirement.requires_file && !requirement.submission) return 'record';
    if (requirement.requires_file && !requirement.submission) return 'waiting';
    return 'complete';
}

function recipientState(recipient) {
    const monitoringCase = recipient.monitoring_case;
    if (!monitoringCase) {
        const states = recipient.requirements.map(requirementState);
        if (states.includes('request') || states.includes('review') || states.includes('record')) return 'action';
        if (states.includes('followup')) return 'followup';
        if (states.includes('waiting')) return 'waiting';
        return 'complete';
    }
    if (monitoringCase.status === 'follow_up') return 'followup';
    if (['ready_to_close', 'closed', 'waived', 'cancelled'].includes(monitoringCase.status)) return 'complete';
    if (monitoringCase.next_action?.role === 'provider') return 'action';
    return 'waiting';
}

function recipientStatusLabel(recipient) {
    return recipient.monitoring_case?.status_label ?? stateLabel(recipientState(recipient));
}

function primaryRequirement(recipient) {
    const action = recipient.monitoring_case?.next_action?.key;
    const predicates = {
        decide_adjustment: (requirement) => requirement.adjustment_request?.status === 'pending',
        review_requirements: (requirement) => requirement.submission && !requirement.submission.reviewed_at,
        record_requirement: (requirement) => !requirement.requires_file && !requirement.submission,
        manage_follow_up: (requirement) => requirement.interventions?.some((item) => item.status === 'open')
            || ['not_met', 'needs_correction'].includes(requirement.submission?.review_status),
    };
    const predicate = predicates[action];
    return (predicate ? recipient.requirements.find(predicate) : null)
        ?? recipient.requirements.find((item) => requirementState(item) !== 'complete')
        ?? recipient.requirements[0];
}

const recipientCounts = computed(() => recipients.value.reduce((counts, recipient) => {
    counts[recipientState(recipient)] += 1;
    return counts;
}, { action: 0, followup: 0, waiting: 0, complete: 0 }));
const filteredRecipients = computed(() => recipientFilter.value === 'all'
    ? recipients.value
    : recipients.value.filter((recipient) => recipientState(recipient) === recipientFilter.value));

function stateLabel(state) {
    return {
        request: 'Request to decide',
        review: 'Submission to review',
        record: 'Provider result needed',
        followup: 'Follow-up open',
        waiting: 'Awaiting recipient',
        complete: 'Reviewed',
        action: 'Needs decision',
    }[state] ?? 'Monitoring';
}

function stateClass(state) {
    if (['request', 'review', 'record', 'action'].includes(state)) return 'border-amber-200 bg-amber-50 text-amber-800';
    if (state === 'followup') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (state === 'complete') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    return 'border-slate-300 bg-slate-100 text-slate-700';
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    return errors ? Object.values(errors).flat()[0] : (error.response?.data?.message || fallback);
}

function isTerminalCase(recipient) {
    return ['closed', 'waived', 'cancelled'].includes(recipient?.monitoring_case?.status);
}

function canWaiveCase(recipient) {
    return recipient?.monitoring_case
        && !isTerminalCase(recipient)
        && !recipient.requirements?.some((requirement) => (
            requirement.adjustment_request?.status === 'pending'
            || requirement.interventions?.some((intervention) => intervention.status === 'open')
        ));
}

function hasCompletedFollowUp(recipient) {
    return recipient?.requirements?.some((requirement) => (
        requirement.interventions?.some((intervention) => intervention.status === 'completed')
    ));
}

function openCaseDecision(mode) {
    const monitoringCase = selectedRecipient.value?.monitoring_case;
    if (!monitoringCase) return;

    caseDecisionMode.value = mode;
    caseDecisionForm.value = {
        outcome: mode === 'waive'
            ? 'excused'
            : (monitoringCase.next_action?.key === 'resolve_overdue' ? 'incomplete' : 'completed'),
        reason: '',
    };
}

function closeCaseDecision() {
    if (caseDecisionBusy.value) return;
    caseDecisionMode.value = '';
    caseDecisionForm.value = { outcome: 'completed', reason: '' };
}

async function submitCaseDecision() {
    const recipient = selectedRecipient.value;
    const monitoringCase = recipient?.monitoring_case;
    const mode = caseDecisionMode.value;
    if (!monitoringCase || !mode || caseDecisionBusy.value) return;

    caseDecisionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const payload = mode === 'reopen'
            ? { reason: caseDecisionForm.value.reason }
            : caseDecisionForm.value;
        const response = await window.axios.patch(
            `/provider/monitoring-cases/${monitoringCase.id}/${mode}`,
            payload,
        );
        cycle.value = response.data.cycle;
        successMessage.value = response.data.message;
        caseDecisionMode.value = '';
        caseDecisionForm.value = { outcome: 'completed', reason: '' };
    } catch (error) {
        errorMessage.value = firstError(error, 'The check-in outcome could not be saved.');
    } finally {
        caseDecisionBusy.value = false;
    }
}

function selectRecipient(recipient) {
    selectedRecipientId.value = recipient.application_id;
    selectRequirement(primaryRequirement(recipient));
}

function selectRequirement(requirement) {
    if (!requirement) return;
    selectedRequirementId.value = requirement.id;
    const state = requirementState(requirement);
    actionMode.value = state === 'request' ? 'adjustment' : state === 'review' ? 'review' : state === 'record' ? 'record' : '';
    reviewForm.value = { decision: 'met', notes: '' };
    adjustmentForm.value = { decision: 'approved', decision_notes: '', approved_due_at: requirement.adjustment_request?.requested_due_at ?? '' };
    providerResultForm.value = { decision: 'met', notes: '' };
    followUpForm.value = { type: 'reminder', summary: '', action_required: '', follow_up_on: '' };
}

watch(filteredRecipients, (items) => {
    if (!items.length) {
        selectedRecipientId.value = null;
        selectedRequirementId.value = null;
        return;
    }
    if (!items.some((recipient) => recipient.application_id === selectedRecipientId.value)) selectRecipient(items[0]);
});

async function loadRecord() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/workspaces/monitoring/check-ins/${cycleId}/data`);
        program.value = response.data.program;
        cycle.value = response.data.cycle;
        const first = recipients.value.find((recipient) => recipientState(recipient) !== 'complete') ?? recipients.value[0];
        if (first) selectRecipient(first);
    } catch (error) {
        errorMessage.value = firstError(error, 'The monitoring check-in could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

function requestConfirmation(type, title, message, tone = 'warning') {
    confirmation.value = { type, title, message, tone, confirmLabel: 'Save decision' };
}

async function runAction(action, fallback) {
    actionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    try {
        const response = await action();
        cycle.value = response.data.cycle;
        successMessage.value = response.data.message;
        const refreshed = recipients.value.find((recipient) => recipient.application_id === selectedRecipientId.value);
        if (refreshed) selectRecipient(refreshed);
    } catch (error) {
        errorMessage.value = firstError(error, fallback);
    } finally {
        actionBusy.value = false;
    }
}

async function confirmAction() {
    const type = confirmation.value?.type;
    confirmation.value = null;
    const requirement = selectedRequirement.value;
    const recipient = selectedRecipient.value;
    if (!requirement || !recipient) return;

    if (type === 'review') {
        await runAction(() => window.axios.patch(`/provider/monitoring-submissions/${requirement.submission.id}/review`, reviewForm.value), 'The submission review could not be saved.');
    } else if (type === 'adjustment') {
        await runAction(() => window.axios.patch(`/provider/monitoring-adjustment-requests/${requirement.adjustment_request.id}/decision`, adjustmentForm.value), 'The recipient request could not be decided.');
    } else if (type === 'record') {
        await runAction(() => window.axios.post(`/provider/monitoring-requirements/${requirement.id}/applications/${recipient.application_id}/record`, providerResultForm.value), 'The provider result could not be saved.');
    } else if (type === 'followup') {
        await runAction(() => window.axios.post(`/provider/monitoring-requirements/${requirement.id}/applications/${recipient.application_id}/interventions`, followUpForm.value), 'The follow-up could not be created.');
    } else if (type === 'complete') {
        await runAction(() => window.axios.patch(`/provider/monitoring-interventions/${completionTarget.value.id}/complete`, completionForm.value), 'The follow-up could not be completed.');
    }
}

function completeIntervention(intervention) {
    completionTarget.value = intervention;
    completionForm.value = { completion_notes: '' };
    actionMode.value = 'complete';
}

onMounted(loadRecord);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="monitoring-record-title">
            <div class="mx-auto max-w-[94rem]">
                <a :href="safeReturnUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to monitoring</a>
                <div v-if="errorMessage" class="mb-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mb-4 border-l-4 border-emerald-500 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</div>
                <div v-if="isLoading" class="border border-slate-200 bg-white px-6 py-16 text-center">
                    <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span>
                    <p class="mt-3 text-sm font-semibold text-slate-500">Loading monitoring check-in...</p>
                </div>

                <template v-else-if="cycle">
                    <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                        <div class="flex items-start justify-between gap-8">
                            <div class="flex min-w-0 items-start gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Monitoring check-in</p>
                                    <h1 id="monitoring-record-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Review recipient progress</h1>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">Decide one submitted requirement or follow-up at a time.</p>
                                </div>
                            </div>
                            <span :class="['border px-3 py-2 text-[10px] font-bold uppercase tracking-wide', cycle.status === 'open' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-300 bg-slate-100 text-slate-700']">{{ cycle.status }}</span>
                        </div>
                    </header>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-8 border-b border-slate-200 px-6 py-5">
                            <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400">{{ program.title }}</p><h2 class="mt-1 truncate text-xl font-bold text-slate-950">{{ cycle.title }}</h2><p class="mt-1 text-sm text-slate-500">{{ [cycle.academic_period, cycle.school_year].filter(Boolean).join(' · ') || cycle.period_type }}</p></div>
                            <div class="text-right"><p class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Deadline</p><p class="mt-1 text-sm font-bold text-slate-950">{{ cycle.due_label || 'Not set' }}</p></div>
                        </div>
                        <dl class="grid grid-cols-4 bg-slate-50">
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Recipients</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ recipients.length }}</dd></div>
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Requirements</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ reviewedRequirements }} of {{ totalRequirements }} reviewed</dd></div>
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Files received</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ receivedFiles }} of {{ cycle.item_expected_count }}</dd></div>
                            <div class="px-5 py-4"><dt class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Open follow-ups</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ cycle.open_intervention_count }}</dd></div>
                        </dl>
                    </section>

                    <nav class="mt-4 flex border border-slate-200 bg-white shadow-sm" aria-label="Recipient filters">
                        <button v-for="filter in [{ key: 'action', label: 'Needs decision', count: recipientCounts.action }, { key: 'followup', label: 'Follow-up', count: recipientCounts.followup }, { key: 'waiting', label: 'Waiting', count: recipientCounts.waiting }, { key: 'complete', label: 'Complete', count: recipientCounts.complete }, { key: 'all', label: 'All recipients', count: recipients.length }]" :key="filter.key" type="button" :class="['flex h-14 flex-1 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', recipientFilter === filter.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50']" @click="recipientFilter = filter.key"><span>{{ filter.label }}</span><span :class="['px-2 py-0.5 text-xs', recipientFilter === filter.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-600']">{{ filter.count }}</span></button>
                    </nav>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-start gap-3 border-b border-slate-200 px-6 py-5"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-users-gear text-sm" aria-hidden="true"></i></span><div><h2 class="text-xl font-bold text-slate-950">Recipient checklist</h2><p class="mt-1 text-sm text-slate-500">Select a recipient, then open only the requirement you need to handle.</p></div></header>
                        <div v-if="!filteredRecipients.length" class="px-6 py-14 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-user-slash text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm text-slate-500">No recipients match this work state.</p></div>
                        <template v-else>
                            <div class="flex flex-wrap gap-2 border-b border-slate-200 bg-slate-50 px-5 py-3">
                                <button v-for="recipient in filteredRecipients" :key="recipient.application_id" type="button" :class="['flex min-w-[14rem] items-center gap-3 border px-3 py-2 text-left', selectedRecipientId === recipient.application_id ? 'border-slate-950 bg-white' : 'border-slate-200 bg-slate-50 hover:border-slate-400']" @click="selectRecipient(recipient)">
                                    <img v-if="recipient.profile_photo_url" :src="recipient.profile_photo_url" :alt="`${recipient.name} profile`" class="h-9 w-9 shrink-0 border border-slate-200 object-cover"><span v-else class="grid h-9 w-9 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user text-xs" aria-hidden="true"></i></span>
                                    <span class="min-w-0 flex-1"><span class="block truncate text-xs font-bold text-slate-950">{{ recipient.name }}</span><span class="mt-0.5 block text-[10px] font-semibold text-slate-500">{{ recipientStatusLabel(recipient) }}</span></span>
                                </button>
                            </div>

                            <div v-if="selectedRecipient" class="px-6 py-5">
                                <div class="flex items-start justify-between gap-8 border-b border-slate-200 pb-5">
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Selected recipient</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ selectedRecipient.name }}</h3>
                                        <p class="mt-1 text-sm text-slate-500">{{ selectedRecipient.monitoring_case?.status_reason || selectedRecipient.email }}</p>
                                        <p v-if="selectedRecipient.monitoring_case?.outcome_label" class="mt-2 text-xs font-bold text-slate-700">Outcome: {{ selectedRecipient.monitoring_case.outcome_label }}</p>
                                        <button v-if="selectedRecipient.monitoring_case?.history?.length || selectedRecipient.monitoring_case?.exception_context" type="button" class="mt-2 inline-flex items-center gap-2 text-xs font-bold text-slate-600 underline underline-offset-4 hover:text-slate-950" @click="historyOpen = true"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>View history</button>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <span :class="['border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', stateClass(recipientState(selectedRecipient))]">{{ recipientStatusLabel(selectedRecipient) }}</span>
                                        <p v-if="selectedRecipient.monitoring_case?.next_action" class="mt-2 max-w-xs text-xs font-bold text-slate-700">{{ selectedRecipient.monitoring_case.next_action.label }}</p>
                                        <p v-if="selectedRecipient.monitoring_case?.effective_due_label" class="mt-1 text-[10px] font-semibold text-slate-500">Due {{ selectedRecipient.monitoring_case.effective_due_label }}</p>
                                        <div class="mt-3 flex justify-end gap-2">
                                            <button v-if="selectedRecipient.monitoring_case?.status === 'ready_to_close'" type="button" class="inline-flex items-center gap-2 bg-slate-950 px-3 py-2 text-xs font-bold text-white" @click="openCaseDecision('close')"><i class="fa-solid fa-circle-check text-[9px]" aria-hidden="true"></i>Close check-in</button>
                                            <button v-else-if="selectedRecipient.monitoring_case?.next_action?.key === 'resolve_overdue'" type="button" class="inline-flex items-center gap-2 bg-slate-950 px-3 py-2 text-xs font-bold text-white" @click="openCaseDecision('close')"><i class="fa-solid fa-circle-check text-[9px]" aria-hidden="true"></i>Resolve overdue</button>
                                            <button v-if="canWaiveCase(selectedRecipient)" type="button" class="inline-flex items-center gap-2 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="openCaseDecision('waive')"><i class="fa-solid fa-ban text-[9px]" aria-hidden="true"></i>Waive</button>
                                            <button v-if="isTerminalCase(selectedRecipient) && selectedRecipient.monitoring_case?.status !== 'cancelled'" type="button" class="inline-flex items-center gap-2 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="openCaseDecision('reopen')"><i class="fa-solid fa-rotate-left text-[9px]" aria-hidden="true"></i>Reopen</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="divide-y divide-slate-200 border-b border-slate-200">
                                    <article v-for="requirement in selectedRecipient.requirements" :key="requirement.id">
                                        <button type="button" class="flex w-full items-center gap-5 px-2 py-4 text-left" @click="selectRequirement(requirement)">
                                            <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-500"><i :class="requirement.icon" aria-hidden="true"></i></span>
                                            <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-950">{{ requirement.title }}</span><span class="mt-1 block truncate text-xs text-slate-500">{{ requirement.submission?.original_name || requirement.evidence_description || requirement.description || 'Provider confirmation' }}</span></span>
                                            <span :class="['border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', stateClass(requirementState(requirement))]">{{ stateLabel(requirementState(requirement)) }}</span>
                                            <i :class="['fa-solid fa-chevron-down text-xs text-slate-400 transition', selectedRequirementId === requirement.id ? 'rotate-180' : '']" aria-hidden="true"></i>
                                        </button>

                                        <div v-if="selectedRequirementId === requirement.id" class="border-t border-slate-200 bg-slate-50 px-5 py-5">
                                            <div class="flex items-start justify-between gap-6"><div><p class="text-sm font-semibold leading-6 text-slate-700">{{ requirement.description || requirement.evidence_description || 'Review this requirement against the saved monitoring plan.' }}</p><p class="mt-1 text-xs text-slate-500">Effective deadline: {{ requirement.effective_due_label || cycle.due_label }}</p></div><a v-if="requirement.submission?.view_url" :href="requirement.submission.view_url" target="_blank" rel="noopener" class="shrink-0 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:border-slate-950">Open file <i class="fa-solid fa-arrow-up-right-from-square ml-1" aria-hidden="true"></i></a></div>

                                            <div v-if="requirement.submission" class="mt-4 grid grid-cols-4 border border-slate-200 bg-white"><div class="border-r border-slate-200 px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Submitted</p><p class="mt-1 text-xs font-bold text-slate-900">{{ requirement.submission.submitted_at }}</p></div><div class="border-r border-slate-200 px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Recorded result</p><p class="mt-1 text-xs font-bold text-slate-900">{{ requirement.submission.grade_label || 'Not applicable' }}</p></div><div class="border-r border-slate-200 px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Review</p><p class="mt-1 text-xs font-bold text-slate-900">{{ requirement.submission.review_status_label }}</p></div><div class="px-4 py-3"><p class="text-[9px] font-bold uppercase tracking-wide text-slate-400">Reviewed by</p><p class="mt-1 text-xs font-bold text-slate-900">{{ requirement.submission.reviewed_by || 'Pending' }}</p></div></div>

                                            <div v-if="requirement.adjustment_request?.status === 'pending'" class="mt-4 border-l-4 border-amber-400 bg-amber-50 px-4 py-3"><p class="text-[10px] font-bold uppercase tracking-wide text-amber-800">{{ requirement.adjustment_request.request_type_label }}</p><p class="mt-1 text-sm font-semibold text-amber-950">{{ requirement.adjustment_request.explanation }}</p><p v-if="requirement.adjustment_request.requested_due_label" class="mt-1 text-xs text-amber-800">Requested deadline: {{ requirement.adjustment_request.requested_due_label }}</p><a v-if="requirement.adjustment_request.attachment" :href="requirement.adjustment_request.attachment.view_url" target="_blank" rel="noopener" class="mt-2 inline-flex text-xs font-bold text-amber-950 underline">Open attachment</a></div>

                                            <div v-if="requirement.interventions?.length" class="mt-4 divide-y divide-slate-200 border-y border-slate-200 bg-white"><div v-for="intervention in requirement.interventions" :key="intervention.id" class="flex items-center gap-5 px-4 py-3"><div class="min-w-0 flex-1"><p class="text-xs font-bold text-slate-900">{{ intervention.type_label }} · {{ intervention.status_label }}</p><p class="mt-1 text-xs text-slate-600">{{ intervention.summary }}</p></div><button v-if="intervention.status === 'open'" type="button" class="border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700" @click="completeIntervention(intervention)">Complete</button></div></div>

                                            <form v-if="actionMode === 'adjustment' && requirement.adjustment_request?.status === 'pending'" class="mt-5" @submit.prevent="requestConfirmation('adjustment', 'Decide recipient request', 'This decision changes the recipient deadline or exception status.')"><div class="grid grid-cols-3 gap-4"><label class="text-sm font-semibold text-slate-700">Decision<select v-model="adjustmentForm.decision" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="approved">Approve</option><option value="declined">Decline</option></select></label><label v-if="adjustmentForm.decision === 'approved' && requirement.adjustment_request.request_type === 'extension'" class="text-sm font-semibold text-slate-700">Approved deadline<input v-model="adjustmentForm.approved_due_at" type="date" required :min="cycle.due_at" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal"></label><label :class="['text-sm font-semibold text-slate-700', adjustmentForm.decision === 'approved' && requirement.adjustment_request.request_type === 'extension' ? 'col-span-1' : 'col-span-2']">Decision note<textarea v-model="adjustmentForm.decision_notes" :required="adjustmentForm.decision === 'declined'" rows="2" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal"></textarea></label></div><button type="submit" class="mt-4 h-10 bg-slate-950 px-4 text-xs font-bold text-white">Save request decision</button></form>

                                            <form v-else-if="actionMode === 'review' && requirement.submission" class="mt-5" @submit.prevent="requestConfirmation('review', 'Save submission review', 'The recipient will be notified of this review result.')"><div class="grid grid-cols-3 gap-4"><label class="text-sm font-semibold text-slate-700">Result<select v-model="reviewForm.decision" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="met">Requirement met</option><option value="not_met">Requirement not met</option><option value="needs_correction">Request replacement</option><option value="excused">Approve exception</option></select></label><label class="col-span-2 text-sm font-semibold text-slate-700">Review note<textarea v-model="reviewForm.notes" :required="reviewForm.decision !== 'met'" rows="2" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal"></textarea></label></div><button type="submit" class="mt-4 h-10 bg-slate-950 px-4 text-xs font-bold text-white">Save review</button></form>

                                            <form v-else-if="actionMode === 'record' && !requirement.requires_file" class="mt-5" @submit.prevent="requestConfirmation('record', 'Record provider result', 'This result becomes part of the recipient monitoring record.')"><div class="grid grid-cols-3 gap-4"><label class="text-sm font-semibold text-slate-700">Result<select v-model="providerResultForm.decision" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="met">Requirement met</option><option value="not_met">Requirement not met</option><option value="excused">Excused</option></select></label><label class="col-span-2 text-sm font-semibold text-slate-700">Note<textarea v-model="providerResultForm.notes" :required="providerResultForm.decision !== 'met'" rows="2" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal"></textarea></label></div><button type="submit" class="mt-4 h-10 bg-slate-950 px-4 text-xs font-bold text-white">Record result</button></form>

                                            <form v-else-if="actionMode === 'followup'" class="mt-5" @submit.prevent="requestConfirmation('followup', 'Create recipient follow-up', 'This action and instruction will be shared with the recipient.')"><div class="grid grid-cols-3 gap-4"><label class="text-sm font-semibold text-slate-700">Follow-up type<select v-model="followUpForm.type" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal"><option value="reminder">Reminder</option><option value="consultation">Consultation</option><option value="support_plan">Support plan</option><option value="warning">Formal warning</option></select></label><label class="text-sm font-semibold text-slate-700">Follow-up date <span class="font-normal text-slate-400">(optional)</span><input v-model="followUpForm.follow_up_on" type="date" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal"></label><label class="text-sm font-semibold text-slate-700">Summary<input v-model="followUpForm.summary" required minlength="5" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal"></label><label class="col-span-3 text-sm font-semibold text-slate-700">Recipient action <span class="font-normal text-slate-400">(optional)</span><textarea v-model="followUpForm.action_required" rows="2" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal"></textarea></label></div><button type="submit" class="mt-4 h-10 bg-slate-950 px-4 text-xs font-bold text-white">Send follow-up</button></form>

                                            <form v-else-if="actionMode === 'complete' && completionTarget" class="mt-5" @submit.prevent="requestConfirmation('complete', 'Complete follow-up', 'The recipient will see that this follow-up is closed.')"><label class="text-sm font-semibold text-slate-700">Completion note <span class="font-normal text-slate-400">(optional)</span><textarea v-model="completionForm.completion_notes" rows="2" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal"></textarea></label><button type="submit" class="mt-4 h-10 bg-slate-950 px-4 text-xs font-bold text-white">Complete follow-up</button></form>

                                            <div v-else-if="requirementState(requirement) === 'waiting'" class="mt-5 border border-slate-200 bg-white px-4 py-4 text-sm text-slate-600">No provider action is required until the recipient submits this record.</div>
                                            <div v-else-if="!actionMode" class="mt-5 flex items-center justify-between border border-slate-200 bg-white px-4 py-3"><p class="text-sm text-slate-600">{{ requirementState(requirement) === 'complete' ? 'This requirement is complete.' : 'Add a follow-up if the recipient needs guidance.' }}</p><button v-if="requirementState(requirement) !== 'complete'" type="button" class="border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700" @click="actionMode = 'followup'">Add follow-up</button></div>

                                            <button v-if="['not_met', 'needs_correction'].includes(requirement.submission?.review_status) && actionMode !== 'followup'" type="button" class="mt-4 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="actionMode = 'followup'">Add recipient follow-up</button>
                                        </div>
                                    </article>
                                </div>
                            </div>
                        </template>
                    </section>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <details>
                            <summary class="flex cursor-pointer list-none items-center justify-between px-6 py-5"><span class="flex items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-circle-info text-sm" aria-hidden="true"></i></span><span><span class="block text-base font-bold text-slate-950">Check-in details</span><span class="mt-1 block text-sm text-slate-500">Instructions and saved requirements for this monitoring period.</span></span></span><i class="fa-solid fa-chevron-down text-xs text-slate-400" aria-hidden="true"></i></summary>
                            <div class="border-t border-slate-200 px-6 py-5"><p v-if="cycle.instructions" class="text-sm leading-6 text-slate-700">{{ cycle.instructions }}</p><dl class="mt-4 divide-y divide-slate-200 border-y border-slate-200"><div v-for="requirement in cycle.requirements" :key="requirement.id" class="grid grid-cols-[16rem_1fr] gap-6 py-4"><dt class="text-sm font-bold text-slate-900">{{ requirement.title }}</dt><dd class="text-sm leading-6 text-slate-600">{{ requirement.evidence_description || requirement.description || (requirement.requires_file ? 'Recipient file required.' : 'Provider confirmation required.') }}</dd></div></dl></div>
                        </details>
                    </section>
                </template>
            </div>
        </section>

        <Teleport to="body">
            <div v-if="caseDecisionMode" class="fixed inset-0 z-[120] grid place-items-center bg-slate-950/65 p-5" role="dialog" aria-modal="true" aria-labelledby="case-decision-title" @click.self="closeCaseDecision">
                <form class="w-full max-w-xl border border-slate-300 bg-white shadow-2xl" @submit.prevent="submitCaseDecision">
                    <header class="flex items-start justify-between gap-6 border-b border-slate-200 px-6 py-5">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Monitoring outcome</p>
                            <h2 id="case-decision-title" class="mt-1 text-xl font-bold text-slate-950">{{ caseDecisionMode === 'reopen' ? 'Reopen check-in' : caseDecisionMode === 'waive' ? 'Waive this check-in' : selectedRecipient?.monitoring_case?.next_action?.key === 'resolve_overdue' ? 'Resolve overdue check-in' : 'Close completed check-in' }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ selectedRecipient?.name }}</p>
                        </div>
                        <button type="button" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-500" aria-label="Close" @click="closeCaseDecision"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </header>
                    <div class="space-y-5 px-6 py-5">
                        <label v-if="caseDecisionMode === 'close' && selectedRecipient?.monitoring_case?.status === 'ready_to_close'" class="block text-sm font-bold text-slate-700">Outcome
                            <select v-model="caseDecisionForm.outcome" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal">
                                <option value="completed">Completed</option>
                                <option v-if="hasCompletedFollowUp(selectedRecipient)" value="completed_with_support">Completed with support</option>
                            </select>
                        </label>
                        <div v-else-if="caseDecisionMode === 'close'" class="border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950">This overdue check-in will be recorded as incomplete.</div>
                        <label v-if="caseDecisionMode === 'waive'" class="block text-sm font-bold text-slate-700">Outcome
                            <select v-model="caseDecisionForm.outcome" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal">
                                <option value="excused">Excused</option>
                                <option value="waived">Waived</option>
                            </select>
                        </label>
                        <label class="block text-sm font-bold text-slate-700">{{ caseDecisionMode === 'reopen' ? 'Reason for reopening' : caseDecisionForm.outcome === 'completed' ? 'Closing note (optional)' : 'Decision reason' }}
                            <textarea v-model="caseDecisionForm.reason" :required="caseDecisionMode === 'reopen' || caseDecisionForm.outcome !== 'completed'" :minlength="caseDecisionMode === 'waive' || caseDecisionMode === 'reopen' ? 10 : 5" rows="4" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal" :placeholder="caseDecisionMode === 'reopen' ? 'Explain why another review is needed.' : 'Record the basis for this outcome.'"></textarea>
                        </label>
                    </div>
                    <footer class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                        <button type="button" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700" :disabled="caseDecisionBusy" @click="closeCaseDecision">Cancel</button>
                        <button type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white disabled:opacity-60" :disabled="caseDecisionBusy"><i :class="['fa-solid text-[10px]', caseDecisionBusy ? 'fa-spinner fa-spin' : caseDecisionMode === 'reopen' ? 'fa-rotate-left' : 'fa-floppy-disk']" aria-hidden="true"></i>{{ caseDecisionBusy ? 'Saving...' : caseDecisionMode === 'reopen' ? 'Reopen check-in' : 'Save outcome' }}</button>
                    </footer>
                </form>
            </div>
        </Teleport>

        <MonitoringCaseHistoryModal :open="historyOpen" :monitoring-case="selectedRecipient?.monitoring_case" :subject="selectedRecipient?.name" @close="historyOpen = false" />

        <ConfirmationDialog :open="Boolean(confirmation)" :title="confirmation?.title" :message="confirmation?.message" :confirm-label="confirmation?.confirmLabel" :tone="confirmation?.tone" @confirm="confirmAction" @cancel="confirmation = null" />
    </main>
</template>
