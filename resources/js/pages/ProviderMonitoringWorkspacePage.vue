<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import ProviderPagination from '../components/ProviderPagination.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { labelFromKey } from '../support/display';
import { showPortalToast } from '../support/portalToast';
import {
    adjustmentRequestStatusClass as adjustmentStatusClass,
    benefitReleaseStatusClass as releaseStatusClass,
    monitoringReviewStatusClass as reviewStatusClass,
    receiptResponseStatusClass,
    recipientRecordStatusClass as recordStatusClass,
    recipientSupportStatusClass as supportStatusClass,
    requirementComparisonStatusClass as comparisonClass,
} from '../support/providerStatusStyles';

const scholarshipId = document.getElementById('app')?.dataset.scholarshipId;
const scholarship = ref(null);
const monitoringPlan = ref(null);
const cycles = ref([]);
const benefitReleases = ref([]);
const releaseCandidates = ref([]);
const supportRecipients = ref([]);
const programSummary = ref(null);
const monitoringViews = ['summary', 'monitoring', 'releases', 'outcomes'];
const monitoringBaseUrl = `/provider/monitoring/${scholarshipId}`;
const monitoringPathSection = window.location.pathname.replace(/\/$/, '').split('/').at(-1);
const routeMonitoringView = {
    academic: 'monitoring',
    releases: 'releases',
    outcomes: 'outcomes',
}[monitoringPathSection];
const requestedMonitoringView = new URLSearchParams(window.location.search).get('view');
const activeTab = ref(routeMonitoringView
    ?? (monitoringViews.includes(requestedMonitoringView) ? requestedMonitoringView : 'summary'));
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const showCycleForm = ref(false);
const titleInput = ref(null);
const previewFile = ref(null);
const cycleDetailsTarget = ref(null);
const checklistTarget = ref(null);
const reviewTarget = ref(null);
const reviewNotes = ref('');
const isReviewing = ref(false);
const adjustmentReviewTarget = ref(null);
const adjustmentDecisionForm = ref({ decision: 'approved', decision_notes: '', approved_due_at: '' });
const isDecidingAdjustment = ref(false);
const interventionTarget = ref(null);
const interventionForm = ref({ type: 'reminder', summary: '', action_required: '', follow_up_on: '', completion_notes: '' });
const isSavingIntervention = ref(false);
const openCycles = ref(new Set());
const today = new Date().toISOString().slice(0, 10);
const form = ref(defaultForm());
const showReleaseForm = ref(false);
const isSavingRelease = ref(false);
const releaseForm = ref(defaultReleaseForm());
const openReleases = ref(new Set());
const releaseDetailsTarget = ref(null);
const releaseTarget = ref(null);
const isRecordingRelease = ref(false);
const releaseResultForm = ref(defaultReleaseResultForm());
const receiptIssueTarget = ref(null);
const receiptResolutionForm = ref(defaultReceiptResolutionForm());
const isResolvingReceiptIssue = ref(false);
const supportTarget = ref(null);
const supportForm = ref(defaultSupportForm());
const isSavingSupport = ref(false);
const supportError = ref('');
const supportResponseTarget = ref(null);
const supportResolutionForm = ref(defaultSupportResolutionForm());
const isResolvingSupportResponse = ref(false);
const supportResolutionError = ref('');
const recipientRecordTarget = ref(null);
const recipientRecord = ref(null);
const recipientRecordError = ref('');
const isLoadingRecipientRecord = ref(false);
const tablePages = ref({});
const tablePageSize = 5;

const eligibleCandidates = computed(() => releaseCandidates.value.filter((candidate) => candidate.eligible));
const activeSupportCount = computed(() => supportRecipients.value.filter((recipient) => !recipient.is_closed).length);
const renewalReadyCount = computed(() => supportRecipients.value.filter((recipient) => recipient.renewal_eligible && !recipient.is_closed).length);
const hasActiveMonitoringPlan = computed(() => monitoringPlan.value?.status === 'active');
const reviewSubmission = computed(() => reviewTarget.value?.submission ?? null);
const reviewRequirement = computed(() => reviewTarget.value?.requirement ?? null);
const viewTabs = computed(() => [
    {
        value: 'plan',
        label: 'Monitoring plan',
        shortLabel: 'Plan',
        count: 0,
        href: `${monitoringBaseUrl}/plan`,
    },
    {
        value: 'summary',
        label: 'Overview',
        shortLabel: 'Overview',
        count: Number(programSummary.value?.attention_count ?? 0),
        href: monitoringBaseUrl,
    },
    {
        value: 'monitoring',
        label: 'Check-ins',
        shortLabel: 'Check-ins',
        count: cycles.value.reduce((total, cycle) => total + Number(cycle.action_needed_count ?? 0), 0),
        href: `${monitoringBaseUrl}/academic`,
    },
    {
        value: 'releases',
        label: 'Benefit releases',
        shortLabel: 'Releases',
        count: benefitReleases.value.reduce((total, release) => total
            + Number(release.pending_count ?? 0)
            + Number(release.open_issue_count ?? 0), 0),
        href: `${monitoringBaseUrl}/releases`,
    },
    {
        value: 'outcomes',
        label: 'Support outcomes',
        shortLabel: 'Outcomes',
        count: renewalReadyCount.value + supportRecipients.value.filter((recipient) => recipient.latest_decision?.response_status === 'open').length,
        href: `${monitoringBaseUrl}/outcomes`,
    },
]);
const activeViewTitle = computed(() => ({
    summary: 'Monitoring overview',
    monitoring: 'Check-ins',
    releases: 'Benefit releases',
    outcomes: 'Support outcomes',
}[activeTab.value]));
const overviewWorkItems = computed(() => [
    ...(programSummary.value?.attention ?? []).map((item, index) => ({
        ...item,
        row_key: `attention-${item.type}-${index}`,
        queue_label: 'Needs attention',
        timing_label: 'Action needed',
        tone: 'bg-amber-100 text-amber-800',
    })),
    ...(programSummary.value?.upcoming ?? []).map((item, index) => ({
        ...item,
        row_key: `upcoming-${item.type}-${index}`,
        queue_label: 'Upcoming',
        timing_label: item.date_label || 'Scheduled',
        tone: 'bg-sky-100 text-sky-800',
    })),
]);
const pagedOverviewWorkItems = computed(() => pagedRows(overviewWorkItems.value, 'overview'));
const overviewPagination = computed(() => paginationFor(overviewWorkItems.value, 'overview'));
const pagedSupportRecipients = computed(() => pagedRows(supportRecipients.value, 'outcomes'));
const supportPagination = computed(() => paginationFor(supportRecipients.value, 'outcomes'));
const checkInOverview = computed(() => {
    const recipientStates = cycles.value.flatMap((cycle) => (
        (cycle.recipients ?? []).map((recipient) => recipientCheckInState(cycle, recipient).key)
    ));

    return {
        periods: cycles.value.length,
        awaiting: recipientStates.filter((state) => state === 'awaiting').length,
        review: recipientStates.filter((state) => state === 'review').length,
        followUp: recipientStates.filter((state) => ['request', 'follow_up'].includes(state)).length,
    };
});

function defaultForm() {
    return {
        title: '',
        period_type: 'semester',
        academic_period: '',
        school_year: '',
        opens_at: today,
        due_at: '',
        minimum_grade: '85',
        grading_scale: 'percentage',
        instructions: '',
    };
}

function currentTablePage(key) {
    return Math.max(1, Number(tablePages.value[key] ?? 1));
}

function pagedRows(rows, key) {
    const items = Array.isArray(rows) ? rows : [];
    const lastPage = Math.max(1, Math.ceil(items.length / tablePageSize));
    const page = Math.min(currentTablePage(key), lastPage);
    const offset = (page - 1) * tablePageSize;

    return items.slice(offset, offset + tablePageSize);
}

function paginationFor(rows, key) {
    const items = Array.isArray(rows) ? rows : [];
    const total = items.length;
    const lastPage = Math.max(1, Math.ceil(total / tablePageSize));
    const currentPage = Math.min(currentTablePage(key), lastPage);
    const from = total ? ((currentPage - 1) * tablePageSize) + 1 : 0;

    return {
        current_page: currentPage,
        last_page: lastPage,
        from,
        to: total ? Math.min(currentPage * tablePageSize, total) : 0,
        total,
    };
}

function changeTablePage(key, page) {
    tablePages.value = { ...tablePages.value, [key]: page };
}

function pagedCycleRecipients(cycle) {
    return pagedRows(orderedCycleRecipients(cycle), `cycle-${cycle.id}`);
}

function cyclePagination(cycle) {
    return paginationFor(orderedCycleRecipients(cycle), `cycle-${cycle.id}`);
}

function pagedReleaseRecords(release) {
    return pagedRows(release.records, `release-${release.id}`);
}

function releasePagination(release) {
    return paginationFor(release.records, `release-${release.id}`);
}

function defaultReleaseForm() {
    return {
        title: '',
        release_at: '',
        benefit_description: '',
        amount: '',
        release_method: 'in_person',
        location: '',
        instructions: '',
        requires_original_verification: true,
        recipient_ids: [],
    };
}

function defaultReleaseResultForm() {
    return { status: 'prepared', originals_verified: false, notes: '', receipt_proof: null };
}

function defaultReceiptResolutionForm() {
    return { resolution_outcome: 'corrected_release', resolution_notes: '', resolution_proof: null };
}

function defaultSupportForm() {
    return {
        decision: 'renewed',
        reason_category: 'requirements_met',
        effective_on: today,
        support_ends_on: '',
        next_review_on: '',
        notice_given_on: today,
        reason: '',
        next_period_terms: '',
        decision_document: null,
        confirmed: false,
    };
}

function defaultSupportResolutionForm() {
    return {
        resolution_outcome: 'decision_upheld',
        resolution_notes: '',
        resolution_proof: null,
        support_ends_on: '',
        next_review_on: '',
        next_period_terms: '',
    };
}

function supportReasonOptions(decision) {
    if (decision === 'renewed') return [
        { value: 'requirements_met', label: 'Requirements met' },
        { value: 'support_period_extended', label: 'Support period extended' },
        { value: 'continued_funding', label: 'Continued funding approved' },
    ];
    if (decision === 'completed') return [
        { value: 'program_completed', label: 'Program completed' },
        { value: 'support_period_ended', label: 'Support period ended' },
        { value: 'recipient_withdrew', label: 'Recipient withdrew' },
    ];
    return [
        { value: 'requirement_not_met', label: 'Requirement not met' },
        { value: 'document_noncompliance', label: 'Required records not provided' },
        { value: 'misrepresentation', label: 'Information misrepresentation' },
        { value: 'recipient_withdrew', label: 'Recipient withdrew' },
        { value: 'provider_funding_ended', label: 'Provider funding ended' },
        { value: 'other', label: 'Other documented reason' },
    ];
}

function changeSupportDecision() {
    supportForm.value.reason_category = supportReasonOptions(supportForm.value.decision)[0].value;
}

function personInitials(person) {
    return String(person?.name || person?.email || 'Applicant')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();
}

function recordEventIcon(type) {
    if (type === 'agreement') return 'fa-file-signature';
    if (type === 'monitoring') return 'fa-graduation-cap';
    if (type === 'release') return 'fa-hand-holding-heart';
    return 'fa-flag-checkered';
}

function openSummaryItem(item) {
    if (item.application_url) {
        window.location.href = item.application_url;
        return;
    }

    setActiveTab(item.type === 'release' ? 'releases' : 'monitoring');
}

function setActiveTab(view) {
    if (!monitoringViews.includes(view)) return;

    const target = viewTabs.value.find((item) => item.value === view)?.href;

    if (target) {
        window.location.assign(target);
    }
}

async function openRecipientRecord(recipient) {
    recipientRecordTarget.value = recipient;
    recipientRecord.value = null;
    recipientRecordError.value = '';
    isLoadingRecipientRecord.value = true;

    try {
        const response = await window.axios.get(`/provider/applications/${recipient.application_id}/recipient-record`);
        recipientRecord.value = response.data.record;
    } catch (error) {
        recipientRecordError.value = error.response?.data?.message ?? 'Unable to load this recipient record.';
    } finally {
        isLoadingRecipientRecord.value = false;
    }
}

function closeRecipientRecord() {
    recipientRecordTarget.value = null;
    recipientRecord.value = null;
    recipientRecordError.value = '';
}

function openSupportDecision(recipient = null) {
    supportTarget.value = recipient ?? supportRecipients.value.find((item) => !item.is_closed) ?? null;
    supportForm.value = defaultSupportForm();
    supportError.value = '';
}

function closeSupportDecision() {
    if (isSavingSupport.value) return;
    supportTarget.value = null;
    supportError.value = '';
}

function openSupportResponse(recipient) {
    supportResponseTarget.value = { recipient, decision: recipient.latest_decision };
    supportResolutionForm.value = defaultSupportResolutionForm();
    supportResolutionError.value = '';
}

function closeSupportResponse() {
    if (isResolvingSupportResponse.value) return;
    supportResponseTarget.value = null;
    supportResolutionForm.value = defaultSupportResolutionForm();
    supportResolutionError.value = '';
}

function openComposer() {
    form.value = defaultForm();
    errorMessage.value = '';
    showCycleForm.value = true;
    nextTick(() => titleInput.value?.focus());
}

function closeComposer() {
    if (isSaving.value) return;
    showCycleForm.value = false;
}

function openReleaseComposer() {
    releaseForm.value = {
        ...defaultReleaseForm(),
        recipient_ids: eligibleCandidates.value.map((candidate) => candidate.application_id),
    };
    errorMessage.value = '';
    showReleaseForm.value = true;
}

function closeReleaseComposer() {
    if (isSavingRelease.value) return;
    showReleaseForm.value = false;
}

function toggleRelease(releaseId) {
    const next = new Set(openReleases.value);
    next.has(releaseId) ? next.delete(releaseId) : next.add(releaseId);
    openReleases.value = next;
}

function releaseIsOpen(releaseId) {
    return openReleases.value.has(releaseId);
}

function openReleaseDetails(release) {
    releaseDetailsTarget.value = release;
}

function closeReleaseDetails() {
    releaseDetailsTarget.value = null;
}

function openReleaseResult(release, record) {
    releaseTarget.value = { release, record };
    releaseResultForm.value = {
        status: record.status === 'scheduled' ? 'prepared' : record.status,
        originals_verified: Boolean(record.originals_verified),
        notes: record.notes ?? '',
        receipt_proof: null,
    };
}

function closeReleaseResult() {
    if (isRecordingRelease.value) return;
    releaseTarget.value = null;
    releaseResultForm.value = defaultReleaseResultForm();
}

function openReceiptIssue(release, record) {
    receiptIssueTarget.value = { release, record, response: record.receipt_response };
    receiptResolutionForm.value = defaultReceiptResolutionForm();
}

function closeReceiptIssue() {
    if (isResolvingReceiptIssue.value) return;
    receiptIssueTarget.value = null;
    receiptResolutionForm.value = defaultReceiptResolutionForm();
}

function toggleCycle(cycleId) {
    const next = new Set(openCycles.value);
    next.has(cycleId) ? next.delete(cycleId) : next.add(cycleId);
    openCycles.value = next;
}

function cycleIsOpen(cycleId) {
    return openCycles.value.has(cycleId);
}

function openCycleDetails(cycle) {
    cycleDetailsTarget.value = cycle;
}

function closeCycleDetails() {
    cycleDetailsTarget.value = null;
}

function comparisonLabel(submission) {
    if (!submission?.grade) return 'Result needs review';
    if (submission.comparison?.status === 'pass') return 'Meets requirement';
    if (submission.comparison?.status === 'fail') return 'Below requirement';
    return 'Manual review';
}

function recipientCheckInState(cycle, recipient) {
    if (!cycle.is_plan_check_in) {
        if (!recipient.submission) {
            return {
                key: 'awaiting',
                label: 'Awaiting upload',
                detail: 'No academic record submitted',
                className: 'bg-slate-100 text-slate-700',
                priority: 3,
            };
        }
        if (!recipient.submission.reviewed_at) {
            return {
                key: 'review',
                label: 'Needs review',
                detail: 'Submission is ready for review',
                className: 'bg-amber-100 text-amber-800',
                priority: 1,
            };
        }
        if (['not_met', 'needs_correction'].includes(recipient.submission.review_status)) {
            return {
                key: 'follow_up',
                label: 'Follow-up needed',
                detail: recipient.submission.review_status_label,
                className: 'bg-rose-100 text-rose-700',
                priority: 2,
            };
        }

        return {
            key: 'complete',
            label: 'Reviewed',
            detail: recipient.submission.review_status_label,
            className: 'bg-emerald-100 text-emerald-800',
            priority: 4,
        };
    }

    const requirements = recipient.requirements ?? [];
    const adjustmentCount = requirements.filter((requirement) => requirement.adjustment_request?.status === 'pending').length;
    const followUpCount = requirements.filter((requirement) => ['not_met', 'needs_correction'].includes(requirement.submission?.review_status)).length;
    const reviewCount = requirements.filter((requirement) => (
        requirement.required
        && !requirement.submission?.reviewed_at
        && (!requirement.requires_file || requirement.submission?.id)
    )).length;
    const uploadCount = Math.max(0, Number(recipient.required_upload_count ?? 0) - Number(recipient.submitted_item_count ?? 0));

    if (adjustmentCount) {
        return {
            key: 'request',
            label: 'Request to review',
            detail: `${adjustmentCount} adjustment request${adjustmentCount === 1 ? '' : 's'}`,
            className: 'bg-amber-100 text-amber-800',
            priority: 0,
        };
    }
    if (reviewCount) {
        return {
            key: 'review',
            label: 'Needs review',
            detail: `${reviewCount} submitted item${reviewCount === 1 ? '' : 's'}`,
            className: 'bg-amber-100 text-amber-800',
            priority: 1,
        };
    }
    if (followUpCount) {
        return {
            key: 'follow_up',
            label: 'Follow-up needed',
            detail: `${followUpCount} item${followUpCount === 1 ? '' : 's'} need attention`,
            className: 'bg-rose-100 text-rose-700',
            priority: 2,
        };
    }
    if (uploadCount) {
        return {
            key: 'awaiting',
            label: 'Awaiting upload',
            detail: `${uploadCount} file${uploadCount === 1 ? '' : 's'} remaining`,
            className: 'bg-slate-100 text-slate-700',
            priority: 3,
        };
    }

    return {
        key: 'complete',
        label: 'Complete',
        detail: 'Checklist is up to date',
        className: 'bg-emerald-100 text-emerald-800',
        priority: 4,
    };
}

function orderedCycleRecipients(cycle) {
    return [...(cycle.recipients ?? [])].sort((first, second) => {
        const priorityDifference = recipientCheckInState(cycle, first).priority
            - recipientCheckInState(cycle, second).priority;
        return priorityDifference || String(first.name).localeCompare(String(second.name));
    });
}

function cycleAttentionCount(cycle) {
    return (cycle.recipients ?? []).filter((recipient) => recipientCheckInState(cycle, recipient).key !== 'complete').length;
}

function cycleCompleteCount(cycle) {
    return Math.max(0, Number(cycle.recipient_count ?? cycle.recipients?.length ?? 0) - cycleAttentionCount(cycle));
}

function cycleRequiredReviewCount(cycle) {
    if (!cycle.is_plan_check_in) return Number(cycle.recipient_count ?? cycle.recipients?.length ?? 0);
    return (cycle.recipients ?? []).reduce((total, recipient) => total + Number(recipient.required_review_count ?? 0), 0);
}

function dateOffset(date, days) {
    if (!date) return '';
    const value = new Date(`${date}T00:00:00`);
    value.setDate(value.getDate() + days);
    return value.toISOString().slice(0, 10);
}

function openChecklist(cycle, recipient) {
    checklistTarget.value = { cycle, recipient };
}

function closeChecklist() {
    if (isReviewing.value) return;
    checklistTarget.value = null;
}

function applyUpdatedCycle(updatedCycle) {
    const cycleIndex = cycles.value.findIndex((cycle) => cycle.id === updatedCycle.id);
    if (cycleIndex >= 0) cycles.value.splice(cycleIndex, 1, updatedCycle);

    if (checklistTarget.value?.cycle.id === updatedCycle.id) {
        const recipient = updatedCycle.recipients.find(
            (item) => item.application_id === checklistTarget.value.recipient.application_id,
        );
        if (recipient) checklistTarget.value = { cycle: updatedCycle, recipient };
    }
}

function openReview(cycle, recipient, requirement = null) {
    const submission = requirement?.submission ?? recipient.submission ?? null;
    if (!submission && (!requirement || requirement.requires_file)) return;

    reviewTarget.value = { cycle, recipient, requirement, submission };
    reviewNotes.value = submission?.review_notes ?? '';
}

function openAdjustmentReview(cycle, recipient, requirement) {
    const adjustment = requirement.adjustment_request;
    if (!adjustment) return;
    adjustmentReviewTarget.value = { cycle, recipient, requirement, adjustment };
    adjustmentDecisionForm.value = {
        decision: 'approved',
        decision_notes: adjustment.decision_notes ?? '',
        approved_due_at: adjustment.requested_due_at ?? '',
    };
}

function closeAdjustmentReview() {
    if (isDecidingAdjustment.value) return;
    adjustmentReviewTarget.value = null;
}

async function submitAdjustmentDecision() {
    if (!adjustmentReviewTarget.value || isDecidingAdjustment.value) return;
    if (adjustmentDecisionForm.value.decision === 'declined' && adjustmentDecisionForm.value.decision_notes.trim().length < 5) {
        showPortalToast({ type: 'error', title: 'Decision note required', message: 'Explain why the request cannot be approved.' });
        return;
    }

    isDecidingAdjustment.value = true;
    try {
        const target = adjustmentReviewTarget.value;
        const response = await window.axios.patch(
            `/provider/monitoring-adjustment-requests/${target.adjustment.id}/decision`,
            {
                decision: adjustmentDecisionForm.value.decision,
                decision_notes: adjustmentDecisionForm.value.decision_notes.trim() || null,
                approved_due_at: target.adjustment.request_type === 'extension'
                    && adjustmentDecisionForm.value.decision === 'approved'
                        ? adjustmentDecisionForm.value.approved_due_at
                        : null,
            },
        );
        applyUpdatedCycle(response.data.cycle);
        adjustmentReviewTarget.value = null;
        showPortalToast({ type: 'success', title: 'Request decided', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({ type: 'error', title: 'Decision not saved', message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to decide this request.') });
    } finally {
        isDecidingAdjustment.value = false;
    }
}

function openIntervention(cycle, recipient, requirement, intervention = null) {
    interventionTarget.value = { cycle, recipient, requirement, intervention, mode: intervention ? 'complete' : 'create' };
    interventionForm.value = intervention
        ? { type: intervention.type, summary: intervention.summary, action_required: intervention.action_required ?? '', follow_up_on: intervention.follow_up_on ?? '', completion_notes: '' }
        : { type: 'reminder', summary: '', action_required: '', follow_up_on: '', completion_notes: '' };
}

function closeIntervention() {
    if (isSavingIntervention.value) return;
    interventionTarget.value = null;
}

async function submitIntervention() {
    if (!interventionTarget.value || isSavingIntervention.value) return;
    isSavingIntervention.value = true;
    try {
        const target = interventionTarget.value;
        const response = target.mode === 'complete'
            ? await window.axios.patch(`/provider/monitoring-interventions/${target.intervention.id}/complete`, {
                completion_notes: interventionForm.value.completion_notes.trim() || null,
            })
            : await window.axios.post(
                `/provider/monitoring-requirements/${target.requirement.id}/applications/${target.recipient.application_id}/interventions`,
                {
                    type: interventionForm.value.type,
                    summary: interventionForm.value.summary.trim(),
                    action_required: interventionForm.value.action_required.trim() || null,
                    follow_up_on: interventionForm.value.follow_up_on || null,
                },
            );
        applyUpdatedCycle(response.data.cycle);
        interventionTarget.value = null;
        showPortalToast({ type: 'success', title: target.mode === 'complete' ? 'Follow-up completed' : 'Follow-up recorded', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({ type: 'error', title: 'Follow-up not saved', message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to save this follow-up.') });
    } finally {
        isSavingIntervention.value = false;
    }
}

function closeReview() {
    if (isReviewing.value) return;
    reviewTarget.value = null;
    reviewNotes.value = '';
}

async function submitReview(decision) {
    if (!reviewTarget.value || isReviewing.value) return;

    if (['not_met', 'needs_correction', 'excused'].includes(decision) && reviewNotes.value.trim().length < 5) {
        showPortalToast({
            type: 'error',
            title: 'Review note required',
            message: 'Add a short explanation before recording this decision.',
        });
        return;
    }

    isReviewing.value = true;
    try {
        const target = reviewTarget.value;
        const payload = { decision, notes: reviewNotes.value.trim() || null };
        const response = target.requirement && !target.requirement.requires_file
            ? await window.axios.post(
                `/provider/monitoring-requirements/${target.requirement.id}/applications/${target.recipient.application_id}/record`,
                payload,
            )
            : await window.axios.patch(`/provider/monitoring-submissions/${target.submission.id}/review`, payload);
        applyUpdatedCycle(response.data.cycle);
        reviewTarget.value = null;
        reviewNotes.value = '';
        showPortalToast({ type: 'success', title: 'Review recorded', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Review not saved',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to save this review.'),
        });
    } finally {
        isReviewing.value = false;
    }
}

async function loadMonitoring() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/scholarships/${scholarshipId}/monitoring-cycles`);
        scholarship.value = response.data.scholarship;
        monitoringPlan.value = response.data.monitoring_plan ?? null;
        cycles.value = response.data.cycles ?? [];
        benefitReleases.value = response.data.benefit_releases ?? [];
        releaseCandidates.value = response.data.release_candidates ?? [];
        supportRecipients.value = response.data.support_recipients ?? [];
        programSummary.value = response.data.program_summary ?? null;
        if (cycles.value.length) openCycles.value = new Set([cycles.value[0].id]);
        if (benefitReleases.value.length) openReleases.value = new Set([benefitReleases.value[0].id]);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load recipient monitoring.';
    } finally {
        isLoading.value = false;
    }
}

async function submitSupportDecision() {
    if (!supportTarget.value || isSavingSupport.value) return;

    isSavingSupport.value = true;
    supportError.value = '';
    const data = new FormData();
    Object.entries(supportForm.value).forEach(([key, value]) => {
        const decision = supportForm.value.decision;
        const renewalOnly = ['support_ends_on', 'next_review_on', 'next_period_terms'];

        if (renewalOnly.includes(key) && decision !== 'renewed') return;
        if (key === 'notice_given_on' && decision !== 'terminated') return;
        if (key === 'reason' && decision === 'renewed') return;
        if (key === 'decision_document') {
            if (value) data.append(key, value);
            return;
        }
        if (typeof value === 'boolean') {
            data.append(key, value ? '1' : '0');
            return;
        }
        if (value !== '' && value !== null) data.append(key, value);
    });
    try {
        const response = await window.axios.post(
            `/provider/applications/${supportTarget.value.application_id}/support-decision`,
            data,
        );
        const index = supportRecipients.value.findIndex((recipient) => recipient.application_id === response.data.recipient.application_id);
        if (index >= 0) supportRecipients.value.splice(index, 1, response.data.recipient);
        supportTarget.value = null;
        await loadMonitoring();
        showPortalToast({ type: 'success', title: 'Support outcome recorded', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        supportError.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to record this support outcome.');
    } finally {
        isSavingSupport.value = false;
    }
}

async function submitSupportResponseResolution() {
    if (!supportResponseTarget.value || isResolvingSupportResponse.value) return;

    isResolvingSupportResponse.value = true;
    supportResolutionError.value = '';
    const data = new FormData();
    Object.entries(supportResolutionForm.value).forEach(([key, value]) => {
        if (key === 'resolution_proof') {
            if (value) data.append(key, value);
            return;
        }
        if (value !== '' && value !== null) data.append(key, value);
    });

    try {
        const response = await window.axios.post(
            `/provider/support-decisions/${supportResponseTarget.value.decision.id}/resolve`,
            data,
        );
        const index = supportRecipients.value.findIndex((recipient) => recipient.application_id === response.data.recipient.application_id);
        if (index >= 0) supportRecipients.value.splice(index, 1, response.data.recipient);
        supportResponseTarget.value = null;
        supportResolutionForm.value = defaultSupportResolutionForm();
        await loadMonitoring();
        showPortalToast({ type: 'success', title: 'Request resolved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        supportResolutionError.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to resolve this request.');
    } finally {
        isResolvingSupportResponse.value = false;
    }
}

async function publishRelease() {
    if (isSavingRelease.value) return;
    if (!releaseForm.value.recipient_ids.length) {
        errorMessage.value = 'Select at least one eligible recipient.';
        return;
    }

    isSavingRelease.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.post(`/provider/scholarships/${scholarshipId}/benefit-releases`, releaseForm.value);
        benefitReleases.value = [response.data.release, ...benefitReleases.value];
        openReleases.value = new Set([response.data.release.id]);
        showReleaseForm.value = false;
        showPortalToast({ type: 'success', title: 'Release scheduled', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        errorMessage.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to schedule this benefit release.');
    } finally {
        isSavingRelease.value = false;
    }
}

async function submitReleaseResult() {
    if (!releaseTarget.value || isRecordingRelease.value) return;

    isRecordingRelease.value = true;
    const data = new FormData();
    data.append('status', releaseResultForm.value.status);
    data.append('originals_verified', releaseResultForm.value.originals_verified ? '1' : '0');
    if (releaseResultForm.value.notes) data.append('notes', releaseResultForm.value.notes);
    if (releaseResultForm.value.receipt_proof) data.append('receipt_proof', releaseResultForm.value.receipt_proof);

    try {
        const response = await window.axios.post(
            `/provider/benefit-release-records/${releaseTarget.value.record.id}/result`,
            data,
        );
        const index = benefitReleases.value.findIndex((release) => release.id === response.data.release.id);
        if (index >= 0) benefitReleases.value.splice(index, 1, response.data.release);
        releaseTarget.value = null;
        releaseResultForm.value = defaultReleaseResultForm();
        showPortalToast({ type: 'success', title: 'Release record updated', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Release record not saved',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to save this release record.'),
        });
    } finally {
        isRecordingRelease.value = false;
    }
}

async function submitReceiptIssueResolution() {
    if (!receiptIssueTarget.value || isResolvingReceiptIssue.value) return;

    isResolvingReceiptIssue.value = true;
    const data = new FormData();
    data.append('resolution_outcome', receiptResolutionForm.value.resolution_outcome);
    data.append('resolution_notes', receiptResolutionForm.value.resolution_notes);
    if (receiptResolutionForm.value.resolution_proof) data.append('resolution_proof', receiptResolutionForm.value.resolution_proof);

    try {
        const response = await window.axios.post(
            `/provider/benefit-receipt-responses/${receiptIssueTarget.value.response.id}/resolve`,
            data,
        );
        const index = benefitReleases.value.findIndex((release) => release.id === response.data.release.id);
        if (index >= 0) benefitReleases.value.splice(index, 1, response.data.release);
        receiptIssueTarget.value = null;
        receiptResolutionForm.value = defaultReceiptResolutionForm();
        showPortalToast({ type: 'success', title: 'Issue resolved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Resolution not saved',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to resolve this receipt issue.'),
        });
    } finally {
        isResolvingReceiptIssue.value = false;
    }
}

async function publishCycle() {
    if (isSaving.value) return;
    isSaving.value = true;
    errorMessage.value = '';

    try {
        const endpoint = hasActiveMonitoringPlan.value
            ? `/provider/scholarships/${scholarshipId}/monitoring-check-ins`
            : `/provider/scholarships/${scholarshipId}/monitoring-cycles`;
        const payload = hasActiveMonitoringPlan.value
            ? {
                title: form.value.title,
                period_label: form.value.academic_period,
                school_year: form.value.school_year,
                opens_at: form.value.opens_at,
                due_at: form.value.due_at,
                instructions: form.value.instructions,
            }
            : form.value;
        const response = await window.axios.post(endpoint, payload);
        cycles.value = [response.data.cycle, ...cycles.value];
        openCycles.value = new Set([response.data.cycle.id]);
        showCycleForm.value = false;
        showPortalToast({ type: 'success', title: hasActiveMonitoringPlan.value ? 'Check-in published' : 'Monitoring period published', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        errorMessage.value = errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to publish this monitoring period.');
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadMonitoring);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />
        <FilePreviewModal
            :file="previewFile"
            :title="previewFile?.original_name || 'Academic progress record'"
            :context="scholarship?.title || 'Recipient monitoring'"
            @close="previewFile = null"
        />

        <section class="provider-page">
            <div class="provider-container">
                <div v-if="isLoading" class="provider-panel p-6 text-sm text-slate-500">Loading recipient monitoring...</div>
                <div v-else-if="errorMessage && !scholarship" class="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <template v-else-if="scholarship">
                    <TaskPageHeader
                        theme="provider"
                        eyebrow="Recipient monitoring"
                        :title="scholarship.title"
                        description="Manage continuing requirements, benefit releases, and support outcomes after selection."
                        icon="fa-solid fa-heart-pulse"
                    >
                        <template #meta>
                            <span>{{ activeViewTitle }}</span>
                            <span>{{ scholarship.selected_recipients_count }} selected recipient{{ Number(scholarship.selected_recipients_count) === 1 ? '' : 's' }}</span>
                            <span v-if="activeTab === 'summary' || activeTab === 'outcomes'">{{ activeSupportCount }} active support record{{ activeSupportCount === 1 ? '' : 's' }}</span>
                        </template>
                        <template #actions>
                            <a v-if="activeTab === 'summary'" :href="`/provider/scholarships/${scholarshipId}/monitoring/export`" class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                                <i class="fa-solid fa-download text-xs" aria-hidden="true"></i>
                                Export report
                            </a>
                            <a href="/provider/monitoring" class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                                <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                                All monitoring
                            </a>
                            <button v-if="activeTab === 'monitoring'" type="button" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800" @click="openComposer">
                                <i class="fa-solid fa-plus text-xs" aria-hidden="true"></i>
                                New check-in
                            </button>
                            <button v-else-if="activeTab === 'releases'" type="button" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800" @click="openReleaseComposer">
                                <i class="fa-solid fa-plus text-xs" aria-hidden="true"></i>
                                Schedule release
                            </button>
                            <button v-else-if="activeTab === 'outcomes'" type="button" :disabled="!activeSupportCount" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" @click="openSupportDecision()">
                                <i class="fa-solid fa-flag-checkered text-xs" aria-hidden="true"></i>
                                Record outcome
                            </button>
                        </template>
                    </TaskPageHeader>

                    <p v-if="errorMessage" class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <template v-if="activeTab === 'summary'">
                        <section class="provider-panel mt-3 overflow-hidden">
                            <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Recipient status</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Support snapshot</h2>
                            </header>
                            <dl class="grid gap-px bg-slate-200 sm:grid-cols-2 lg:grid-cols-4">
                                <div class="bg-white px-5 py-4"><dt class="text-xs font-bold text-slate-500">Active</dt><dd class="mt-1 text-xl font-bold text-slate-950">{{ programSummary?.outcomes?.active || 0 }}</dd></div>
                                <div class="bg-white px-5 py-4"><dt class="text-xs font-bold text-slate-500">Renewed</dt><dd class="mt-1 text-xl font-bold text-sky-800">{{ programSummary?.outcomes?.renewed || 0 }}</dd></div>
                                <div class="bg-white px-5 py-4"><dt class="text-xs font-bold text-slate-500">Completed</dt><dd class="mt-1 text-xl font-bold text-emerald-800">{{ programSummary?.outcomes?.completed || 0 }}</dd></div>
                                <div class="bg-white px-5 py-4"><dt class="text-xs font-bold text-slate-500">Ended early</dt><dd class="mt-1 text-xl font-bold text-rose-700">{{ programSummary?.outcomes?.terminated || 0 }}</dd></div>
                            </dl>
                        </section>

                        <section class="provider-panel mt-3 overflow-hidden">
                            <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:px-6">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Work queue</p>
                                    <h2 class="mt-1 text-lg font-bold text-slate-950">Monitoring tasks</h2>
                                </div>
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{{ overviewWorkItems.length }} item{{ overviewWorkItems.length === 1 ? '' : 's' }}</span>
                            </header>
                            <div class="portal-table-scroll">
                                <table class="portal-data-table min-w-[760px]">
                                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">
                                        <tr>
                                            <th class="px-5 py-3">Work item</th>
                                            <th class="px-4 py-3">Type</th>
                                            <th class="px-4 py-3">Timing</th>
                                            <th class="px-5 py-3 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 bg-white">
                                        <tr v-if="!overviewWorkItems.length">
                                            <td colspan="4" class="px-5 py-8 text-center">
                                                <p class="font-bold text-slate-900">No monitoring work is due</p>
                                                <p class="mt-1 text-sm text-slate-500">Academic checks, releases, and support outcomes are currently up to date.</p>
                                            </td>
                                        </tr>
                                        <tr v-for="item in pagedOverviewWorkItems" :key="item.row_key">
                                            <td class="px-5 py-3.5">
                                                <p class="font-bold text-slate-950">{{ item.title }}</p>
                                                <p class="mt-0.5 line-clamp-1 text-xs text-slate-500">{{ item.detail }}</p>
                                            </td>
                                            <td class="px-4 py-3.5"><span class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">{{ item.type_label }}</span></td>
                                            <td class="px-4 py-3.5"><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', item.tone]">{{ item.timing_label }}</span></td>
                                            <td class="px-5 py-3.5 text-right"><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openSummaryItem(item)">Open</button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <ProviderPagination :pagination="overviewPagination" item-label="tasks" @change="changeTablePage('overview', $event)" />
                        </section>
                    </template>

                    <template v-else-if="activeTab === 'monitoring'">
                        <section v-if="!cycles.length" class="provider-panel mt-3 px-6 py-10 text-center">
                            <span class="mx-auto grid h-12 w-12 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
                            <h2 class="mt-4 text-lg font-bold text-slate-950">No check-ins yet</h2>
                            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">Create a check-in when recipients need to submit a monitoring requirement.</p>
                            <button type="button" class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="openComposer">Create first check-in</button>
                        </section>

                        <section v-else class="provider-panel mt-3 overflow-hidden">
                            <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div>
                                    <h2 class="text-lg font-bold text-slate-950">Check-in periods</h2>
                                    <p class="mt-1 text-sm text-slate-500">Open a period to review recipient progress.</p>
                                </div>
                                <span class="self-start rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 sm:self-auto">{{ checkInOverview.periods }} period{{ checkInOverview.periods === 1 ? '' : 's' }}</span>
                            </header>

                            <dl class="grid border-b border-slate-200 bg-slate-50 sm:grid-cols-3">
                                <div class="px-5 py-3 sm:border-r sm:border-slate-200">
                                    <dt class="text-xs font-semibold text-slate-500">Waiting on recipients</dt>
                                    <dd class="mt-0.5 text-lg font-bold text-slate-950">{{ checkInOverview.awaiting }}</dd>
                                </div>
                                <div class="border-t border-slate-200 px-5 py-3 sm:border-r sm:border-t-0">
                                    <dt class="text-xs font-semibold text-slate-500">Ready for review</dt>
                                    <dd class="mt-0.5 text-lg font-bold text-amber-800">{{ checkInOverview.review }}</dd>
                                </div>
                                <div class="border-t border-slate-200 px-5 py-3 sm:border-t-0">
                                    <dt class="text-xs font-semibold text-slate-500">Follow-up</dt>
                                    <dd class="mt-0.5 text-lg font-bold text-rose-700">{{ checkInOverview.followUp }}</dd>
                                </div>
                            </dl>

                            <div class="divide-y divide-slate-200">
                                <article v-for="cycle in cycles" :key="cycle.id">
                                    <div class="flex flex-col gap-3 px-5 py-4 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                        <button type="button" class="flex min-w-0 flex-1 items-start gap-3 text-left" :aria-expanded="cycleIsOpen(cycle.id)" @click="toggleCycle(cycle.id)">
                                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i :class="cycle.is_plan_check_in ? 'fa-solid fa-list-check' : 'fa-solid fa-graduation-cap'" aria-hidden="true"></i></span>
                                            <span class="min-w-0">
                                                <span class="flex flex-wrap items-center gap-2">
                                                    <span class="font-bold text-slate-950">{{ cycle.title }}</span>
                                                    <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', cycle.status === 'open' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">{{ cycle.status }}</span>
                                                </span>
                                                <span class="mt-1 block text-xs text-slate-500">{{ cycle.academic_period || labelFromKey(cycle.period_type) }}<span v-if="cycle.school_year"> · {{ cycle.school_year }}</span> · Due {{ cycle.due_label }}</span>
                                            </span>
                                        </button>
                                        <div class="flex flex-wrap items-center justify-between gap-3 sm:flex-nowrap sm:justify-end">
                                            <div class="mr-auto sm:mr-1 sm:text-right">
                                                <span v-if="cycleAttentionCount(cycle)" class="inline-flex rounded-md bg-amber-100 px-2 py-1 text-[10px] font-bold uppercase text-amber-800">{{ cycleAttentionCount(cycle) }} need attention</span>
                                                <span v-else class="inline-flex rounded-md bg-emerald-100 px-2 py-1 text-[10px] font-bold uppercase text-emerald-800">Up to date</span>
                                                <p class="mt-1 text-xs text-slate-500">{{ cycleCompleteCount(cycle) }} of {{ cycle.recipient_count }} recipients complete</p>
                                            </div>
                                            <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 bg-white text-slate-600 hover:bg-slate-100" aria-label="View check-in details" title="Check-in details" @click="openCycleDetails(cycle)">
                                                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 bg-white text-slate-500 hover:bg-slate-100" :aria-label="cycleIsOpen(cycle.id) ? 'Collapse recipient checklists' : 'Expand recipient checklists'" :aria-expanded="cycleIsOpen(cycle.id)" @click="toggleCycle(cycle.id)">
                                                <i :class="['fa-solid fa-chevron-down text-xs transition', cycleIsOpen(cycle.id) ? 'rotate-180' : '']" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div v-if="cycleIsOpen(cycle.id)" class="border-t border-slate-200">
                                        <div class="portal-table-scroll">
                                            <table class="portal-data-table min-w-[820px]">
                                                <colgroup>
                                                    <col class="w-[32%]">
                                                    <col class="w-[22%]">
                                                    <col class="w-[26%]">
                                                    <col class="w-[20%]">
                                                </colgroup>
                                                <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">
                                                    <tr>
                                                        <th class="px-5 py-3">Recipient</th>
                                                        <th class="px-4 py-3">Submission</th>
                                                        <th class="px-4 py-3">Current status</th>
                                                        <th class="px-5 py-3 text-right">Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-200 bg-white">
                                                    <tr v-if="!cycle.recipients.length">
                                                        <td colspan="4" class="px-5 py-8 text-center text-sm text-slate-500">No recipients are included in this check-in.</td>
                                                    </tr>
                                                    <tr v-for="recipient in pagedCycleRecipients(cycle)" :key="recipient.application_id" class="transition hover:bg-slate-50/70">
                                                        <td class="align-middle px-5 py-3.5">
                                                            <div class="flex items-center gap-3">
                                                                <img v-if="recipient.profile_photo_url" :src="recipient.profile_photo_url" :alt="`${recipient.name} profile photo`" class="h-10 w-10 shrink-0 rounded-md bg-slate-100 object-cover ring-1 ring-slate-200">
                                                                <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-[11px] font-bold text-white">{{ personInitials(recipient) }}</span>
                                                                <div class="min-w-0">
                                                                    <p class="font-bold leading-5 text-slate-950">{{ recipient.name }}</p>
                                                                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ recipient.email }}</p>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="align-middle px-4 py-3.5">
                                                            <template v-if="cycle.is_plan_check_in">
                                                                <p class="text-sm font-bold text-slate-950">{{ recipient.submitted_item_count }} of {{ recipient.required_upload_count }} files</p>
                                                                <p class="mt-0.5 text-xs text-slate-500">{{ recipient.reviewed_item_count }} of {{ recipient.required_review_count }} reviewed</p>
                                                            </template>
                                                            <template v-else>
                                                                <p class="text-sm font-bold text-slate-950">{{ recipient.submission?.grade_label || (recipient.submission ? 'Submitted' : 'Not submitted') }}</p>
                                                                <p v-if="recipient.submission" class="mt-0.5 text-xs text-slate-500">{{ recipient.submission.grade_source === 'ocr' ? 'OCR extracted' : recipient.submission.grade_source === 'applicant_manual' ? 'Applicant entered' : 'Manual entry' }}</p>
                                                            </template>
                                                        </td>
                                                        <td class="align-middle px-4 py-3.5">
                                                            <span :class="['inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', recipientCheckInState(cycle, recipient).className]">{{ recipientCheckInState(cycle, recipient).label }}</span>
                                                            <p class="mt-1 text-xs text-slate-500">{{ recipientCheckInState(cycle, recipient).detail }}</p>
                                                        </td>
                                                        <td class="align-middle px-5 py-3.5 text-right">
                                                            <button v-if="cycle.is_plan_check_in" type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openChecklist(cycle, recipient)">Open checklist</button>
                                                            <button v-else-if="recipient.submission" type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openReview(cycle, recipient)">{{ recipient.submission.review_status === 'pending' ? 'Review record' : 'View review' }}</button>
                                                            <a v-else :href="recipient.application_url" class="inline-flex rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">View recipient</a>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <ProviderPagination :pagination="cyclePagination(cycle)" item-label="recipients" @change="changeTablePage(`cycle-${cycle.id}`, $event)" />
                                    </div>
                                </article>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="activeTab === 'releases'">
                        <section v-if="!benefitReleases.length" class="provider-panel mt-3 overflow-hidden">
                            <div class="portal-table-scroll">
                                <table class="portal-data-table min-w-[760px]">
                                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">
                                        <tr>
                                            <th class="px-5 py-3">Benefit release</th>
                                            <th class="px-4 py-3">Schedule</th>
                                            <th class="px-4 py-3">Recipients</th>
                                            <th class="px-4 py-3">Release status</th>
                                            <th class="px-5 py-3 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white">
                                        <tr>
                                            <td colspan="5" class="px-5 py-8 text-center">
                                                <p class="font-bold text-slate-900">No benefit releases scheduled</p>
                                                <p class="mt-1 text-sm text-slate-500">Schedule a release after recipient requirements are confirmed.</p>
                                                <button type="button" class="mt-3 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openReleaseComposer">Schedule first release</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section v-else class="mt-3 space-y-3">
                            <article v-for="release in benefitReleases" :key="release.id" class="provider-panel overflow-hidden">
                                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                    <button type="button" class="flex min-w-0 flex-1 items-start gap-3 text-left" :aria-expanded="releaseIsOpen(release.id)" @click="toggleRelease(release.id)">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>
                                        <span class="min-w-0">
                                            <span class="flex flex-wrap items-center gap-2"><span class="font-bold text-slate-950">{{ release.title }}</span><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', releaseStatusClass(release.status)]">{{ release.status_label }}</span></span>
                                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ release.release_label }} · {{ release.release_method_label }}<span v-if="release.location"> · {{ release.location }}</span></span>
                                        </span>
                                    </button>
                                    <div class="flex flex-wrap items-center justify-between gap-3 sm:flex-nowrap sm:justify-end sm:text-right">
                                        <div class="mr-auto sm:mr-1"><p class="text-sm font-bold text-slate-950">{{ release.released_count }} of {{ release.recipient_count }} released</p><p class="text-xs text-slate-500">{{ release.pending_count }} pending<span v-if="release.exception_count"> · {{ release.exception_count }} exceptions</span></p></div>
                                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 bg-white text-slate-600 hover:bg-slate-100" aria-label="View release details" title="Release details" @click="openReleaseDetails(release)">
                                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                        </button>
                                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 bg-white text-slate-500 hover:bg-slate-100" :aria-label="releaseIsOpen(release.id) ? 'Collapse recipient releases' : 'Expand recipient releases'" :aria-expanded="releaseIsOpen(release.id)" @click="toggleRelease(release.id)">
                                            <i :class="['fa-solid fa-chevron-down text-xs transition', releaseIsOpen(release.id) ? 'rotate-180' : '']" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>

                                <div v-if="releaseIsOpen(release.id)" class="border-t border-slate-200">
                                    <div class="portal-table-scroll">
                                        <table class="portal-data-table min-w-[1040px]">
                                            <colgroup>
                                                <col class="w-[24%]">
                                                <col class="w-[16%]">
                                                <col class="w-[22%]">
                                                <col class="w-[21%]">
                                                <col class="w-[17%]">
                                            </colgroup>
                                            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">
                                                <tr>
                                                    <th class="px-5 py-3">Recipient</th>
                                                    <th class="px-4 py-3">Release status</th>
                                                    <th class="px-4 py-3">Verification</th>
                                                    <th class="px-4 py-3">Recipient response</th>
                                                    <th class="px-5 py-3 text-right">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200 bg-white">
                                                <tr v-if="!release.records.length">
                                                    <td colspan="5" class="px-5 py-8 text-center text-sm text-slate-500">No recipients are included in this benefit release.</td>
                                                </tr>
                                                <tr v-for="record in pagedReleaseRecords(release)" :key="record.id" class="transition hover:bg-slate-50/70">
                                                    <td class="align-middle px-5 py-3.5">
                                                        <div class="flex items-center gap-3">
                                                            <img v-if="record.profile_photo_url" :src="record.profile_photo_url" :alt="`${record.name} profile photo`" class="h-10 w-10 shrink-0 rounded-md bg-slate-100 object-cover ring-1 ring-slate-200">
                                                            <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-[11px] font-bold text-white">{{ personInitials(record) }}</span>
                                                            <div class="min-w-0">
                                                                <p class="font-bold leading-5 text-slate-950">{{ record.name }}</p>
                                                                <p class="mt-0.5 text-xs text-slate-500">{{ record.email }}</p>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="align-middle px-4 py-3.5">
                                                        <div class="flex min-h-6 items-center"><span :class="['inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', releaseStatusClass(record.status)]">{{ record.status_label }}</span></div>
                                                        <p class="mt-0.5 text-xs text-slate-500">{{ record.recorded_at ? `Updated ${record.recorded_at}` : 'Waiting for release result' }}</p>
                                                    </td>
                                                    <td class="align-middle px-4 py-3.5">
                                                        <div class="flex min-h-6 items-center">
                                                            <p :class="['text-xs font-bold', record.originals_verified ? 'text-emerald-700' : 'text-slate-700']">
                                                                {{ release.requires_original_verification ? (record.originals_verified ? 'Original records verified' : 'Original verification pending') : 'Original records not required' }}
                                                            </p>
                                                        </div>
                                                        <button v-if="record.receipt" type="button" class="mt-0.5 text-xs font-bold text-slate-700 underline decoration-slate-300 underline-offset-4" @click="previewFile = record.receipt">View receipt evidence</button>
                                                        <p v-else-if="record.notes" class="mt-0.5 text-xs text-slate-500">Release documented by provider note</p>
                                                        <p v-else class="mt-0.5 text-xs text-slate-400">No receipt evidence recorded</p>
                                                    </td>
                                                    <td class="align-middle px-4 py-3.5">
                                                        <span :class="['inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', receiptResponseStatusClass(record.receipt_response)]">{{ record.receipt_response?.status_label || (record.status === 'released' ? 'Waiting for recipient' : 'Not available') }}</span>
                                                        <p v-if="record.receipt_response?.issue_type_label" class="mt-1 text-xs font-bold text-rose-700">{{ record.receipt_response.issue_type_label }}</p>
                                                        <p v-else-if="record.receipt_response?.received_label" class="mt-1 text-xs text-slate-500">Received {{ record.receipt_response.received_label }}</p>
                                                        <button v-if="record.receipt_response?.evidence" type="button" class="mt-1 text-xs font-bold text-slate-700 underline decoration-slate-300 underline-offset-4" @click="previewFile = record.receipt_response.evidence">View recipient evidence</button>
                                                    </td>
                                                    <td class="align-middle px-5 py-3.5">
                                                        <div class="flex min-h-9 items-center justify-end gap-2">
                                                            <button v-if="record.receipt_response?.status === 'open'" type="button" class="rounded-md bg-rose-700 px-3 py-2 text-xs font-bold text-white hover:bg-rose-800" @click="openReceiptIssue(release, record)">Resolve issue</button>
                                                            <button v-else-if="record.status !== 'released'" type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openReleaseResult(release, record)">Record result</button>
                                                            <a :href="record.application_url" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">Applicant</a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <ProviderPagination :pagination="releasePagination(release)" item-label="recipients" @change="changeTablePage(`release-${release.id}`, $event)" />
                                </div>
                            </article>
                        </section>
                    </template>

                    <template v-else>
                        <section class="provider-panel mt-3 overflow-hidden">
                            <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">End-of-cycle decision</p><h2 class="mt-1 text-lg font-bold text-slate-950">Renew or close recipient support</h2></div>
                                <span class="w-fit rounded-md bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">{{ renewalReadyCount }} ready for renewal</span>
                            </header>

                            <div class="portal-table-scroll">
                                <table class="portal-data-table min-w-[1080px]">
                                    <colgroup>
                                        <col class="w-[24%]">
                                        <col class="w-[20%]">
                                        <col class="w-[29%]">
                                        <col class="w-[27%]">
                                    </colgroup>
                                    <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">
                                        <tr>
                                            <th class="px-5 py-3">Recipient</th>
                                            <th class="px-4 py-3">Program progress</th>
                                            <th class="px-4 py-3">Support status</th>
                                            <th class="px-5 py-3 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 bg-white">
                                        <tr v-if="!supportRecipients.length">
                                            <td colspan="4" class="px-5 py-8 text-center">
                                                <p class="font-bold text-slate-900">No recipient outcomes yet</p>
                                                <p class="mt-1 text-sm text-slate-500">Recipients appear here after accepting their scholarship offer.</p>
                                            </td>
                                        </tr>
                                        <tr v-for="recipient in pagedSupportRecipients" :key="recipient.application_id">
                                            <td class="align-top px-5 py-3.5">
                                                <div class="flex items-start gap-3">
                                                    <img v-if="recipient.profile_photo_url" :src="recipient.profile_photo_url" :alt="`${recipient.name} profile photo`" class="h-10 w-10 shrink-0 rounded-md bg-slate-100 object-cover ring-1 ring-slate-200">
                                                    <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-[11px] font-bold text-white">{{ personInitials(recipient) }}</span>
                                                    <div class="min-w-0">
                                                        <p class="font-bold leading-5 text-slate-950">{{ recipient.name }}</p>
                                                        <p class="mt-0.5 text-xs text-slate-500">{{ recipient.email }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="align-top px-4 py-3.5">
                                                <div class="flex min-h-6 items-center"><p class="font-bold text-slate-950">{{ recipient.requirements_met }} of {{ recipient.requirements_total }} checks confirmed</p></div>
                                                <p class="mt-0.5 text-xs text-slate-500">{{ recipient.released_count }} of {{ recipient.release_count }} releases received</p>
                                            </td>
                                            <td class="align-top px-4 py-3.5">
                                                <div class="flex min-h-6 flex-wrap items-center gap-2">
                                                    <span :class="['inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', supportStatusClass(recipient.support_status)]">{{ recipient.support_status_label }}</span>
                                                    <span :class="['text-xs font-bold', recipient.renewal_eligible ? 'text-emerald-700' : 'text-slate-500']">{{ recipient.renewal_eligibility_label }}</span>
                                                </div>
                                                <p class="mt-0.5 line-clamp-2 text-xs leading-5 text-slate-500">
                                                    {{ recipient.latest_decision ? `${recipient.latest_decision.decision_label} effective ${recipient.latest_decision.effective_label}${recipient.latest_decision.decided_by ? ` by ${recipient.latest_decision.decided_by}` : ''}` : recipient.renewal_eligibility_reason }}
                                                </p>
                                                <span v-if="recipient.latest_decision" :class="['mt-1.5 inline-flex rounded px-2 py-1 text-[10px] font-bold uppercase', recipient.latest_decision.response_status === 'open' ? 'bg-rose-100 text-rose-700' : recipient.latest_decision.response_status === 'resolved' || recipient.latest_decision.response_status === 'acknowledged' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">{{ recipient.latest_decision.response_status_label }}</span>
                                            </td>
                                            <td class="align-top px-5 py-3.5">
                                                <div class="flex min-h-9 flex-wrap items-start justify-end gap-2">
                                                    <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openRecipientRecord(recipient)">Recipient record</button>
                                                    <button v-if="recipient.latest_decision?.response_status === 'open'" type="button" class="rounded-md bg-rose-700 px-3 py-2 text-xs font-bold text-white hover:bg-rose-800" @click="openSupportResponse(recipient)">Review request</button>
                                                    <button v-if="!recipient.is_closed" type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openSupportDecision(recipient)">Record outcome</button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <ProviderPagination :pagination="supportPagination" item-label="recipients" @change="changeTablePage('outcomes', $event)" />
                        </section>
                    </template>
                </template>

            </div>
        </section>

        <Teleport to="body">
            <div v-if="cycleDetailsTarget" class="fixed inset-0 z-[2050] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeCycleDetails" @keydown.esc="closeCycleDetails">
                <section class="monitoring-modal flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="check-in-details-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Check-in details</p>
                                <h2 id="check-in-details-title" class="mt-1 text-xl font-bold text-slate-950">{{ cycleDetailsTarget.title }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ cycleDetailsTarget.academic_period || labelFromKey(cycleDetailsTarget.period_type) }}<span v-if="cycleDetailsTarget.school_year"> · {{ cycleDetailsTarget.school_year }}</span></p>
                            </div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close check-in details" @click="closeCycleDetails"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <dl class="grid bg-slate-50 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="border-b border-slate-200 px-5 py-4 sm:border-r lg:border-b-0">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Requirements</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-950">{{ cycleDetailsTarget.is_plan_check_in ? `${cycleDetailsTarget.requirements.length} checklist item${cycleDetailsTarget.requirements.length === 1 ? '' : 's'}` : cycleDetailsTarget.requirement_label }}</dd>
                            </div>
                            <div class="border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Received</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-950">{{ cycleDetailsTarget.is_plan_check_in ? `${cycleDetailsTarget.item_submitted_count} of ${cycleDetailsTarget.item_expected_count} files` : `${cycleDetailsTarget.submitted_count} of ${cycleDetailsTarget.recipient_count} records` }}</dd>
                            </div>
                            <div class="border-b border-slate-200 px-5 py-4 sm:border-b-0 sm:border-r">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Reviewed</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-950">{{ cycleDetailsTarget.reviewed_count }} of {{ cycleRequiredReviewCount(cycleDetailsTarget) }}</dd>
                            </div>
                            <div class="px-5 py-4">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Deadline</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-950">{{ cycleDetailsTarget.due_label }}</dd>
                            </div>
                        </dl>

                        <section class="border-t border-slate-200 px-5 py-5 sm:px-6">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Recipient instructions</p>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ cycleDetailsTarget.instructions || 'No additional instructions were added for this check-in.' }}</p>
                        </section>
                    </div>

                    <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                        <button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="closeCycleDetails">Close</button>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="releaseDetailsTarget" class="fixed inset-0 z-[2050] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeReleaseDetails" @keydown.esc="closeReleaseDetails">
                <section class="monitoring-modal flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="release-details-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Release details</p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <h2 id="release-details-title" class="text-xl font-bold text-slate-950">{{ releaseDetailsTarget.title }}</h2>
                                    <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', releaseStatusClass(releaseDetailsTarget.status)]">{{ releaseDetailsTarget.status_label }}</span>
                                </div>
                                <p class="mt-1 text-sm text-slate-500">{{ releaseDetailsTarget.release_label }}</p>
                            </div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close release details" @click="closeReleaseDetails"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <dl class="grid bg-slate-50 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="border-b border-slate-200 px-5 py-4 sm:border-r lg:border-b-0">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Benefit</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-950">{{ releaseDetailsTarget.benefit_description }}</dd>
                                <p v-if="releaseDetailsTarget.amount_label" class="mt-1 text-xs text-slate-500">{{ releaseDetailsTarget.amount_label }}</p>
                            </div>
                            <div class="border-b border-slate-200 px-5 py-4 lg:border-b-0 lg:border-r">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Arrangement</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-950">{{ releaseDetailsTarget.release_method_label }}</dd>
                                <p class="mt-1 text-xs text-slate-500">{{ releaseDetailsTarget.location || 'Provider-coordinated destination' }}</p>
                            </div>
                            <div class="border-b border-slate-200 px-5 py-4 sm:border-b-0 sm:border-r">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Document check</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-950">{{ releaseDetailsTarget.requires_original_verification ? 'Originals required' : 'Not required' }}</dd>
                                <p class="mt-1 text-xs text-slate-500">Receipt evidence is recorded per recipient.</p>
                            </div>
                            <div class="px-5 py-4">
                                <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Recipients</dt>
                                <dd class="mt-1 text-sm font-bold text-slate-950">{{ releaseDetailsTarget.released_count }} of {{ releaseDetailsTarget.recipient_count }} released</dd>
                                <p class="mt-1 text-xs text-slate-500">{{ releaseDetailsTarget.pending_count }} pending</p>
                            </div>
                        </dl>

                        <section class="border-t border-slate-200 px-5 py-5 sm:px-6">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Recipient instructions</p>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ releaseDetailsTarget.instructions || 'No additional instructions were added for this release.' }}</p>
                        </section>
                    </div>

                    <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                        <button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="closeReleaseDetails">Close</button>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="recipientRecordTarget" class="fixed inset-0 z-[2050] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeRecipientRecord" @keydown.esc="closeRecipientRecord">
                <section class="monitoring-modal flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="recipient-record-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Consolidated history</p>
                                <h2 id="recipient-record-title" class="mt-1 truncate text-xl font-bold text-slate-950">{{ recipientRecord?.recipient?.name || recipientRecordTarget.name }}</h2>
                                <p class="mt-1 truncate text-sm text-slate-500">{{ recipientRecord?.program?.title || scholarship?.title }}</p>
                            </div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close recipient record" @click="closeRecipientRecord"><i class="fa-solid fa-xmark"></i></button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-6">
                        <div v-if="isLoadingRecipientRecord" class="rounded-md border border-slate-200 bg-white px-5 py-12 text-center text-sm font-semibold text-slate-500">Loading recipient history...</div>
                        <div v-else-if="recipientRecordError" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ recipientRecordError }}</div>
                        <template v-else-if="recipientRecord">
                            <section class="overflow-hidden rounded-md border border-slate-200 bg-white">
                                <div class="flex flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <img v-if="recipientRecord.recipient.profile_photo_url" :src="recipientRecord.recipient.profile_photo_url" :alt="recipientRecord.recipient.name" class="h-12 w-12 shrink-0 rounded-md border border-slate-200 object-cover">
                                        <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-bold text-white">{{ recipientRecord.recipient.name.split(' ').map((part) => part[0]).slice(0, 2).join('') }}</span>
                                        <div class="min-w-0"><h3 class="truncate font-bold text-slate-950">{{ recipientRecord.recipient.name }}</h3><p class="mt-0.5 truncate text-xs text-slate-500">{{ recipientRecord.recipient.email }}</p><p class="mt-1 text-xs text-slate-500">{{ recipientRecord.program.provider_name }}</p></div>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1.5 text-[10px] font-bold uppercase', supportStatusClass(recipientRecord.support.support_status)]">{{ recipientRecord.support.support_status_label }}</span>
                                </div>
                                <dl class="grid gap-px border-t border-slate-200 bg-slate-200 sm:grid-cols-3">
                                    <div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Academic checks</dt><dd class="mt-1 text-base font-bold text-slate-950">{{ recipientRecord.summary.monitoring_confirmed }} of {{ recipientRecord.summary.monitoring_total }}</dd><p class="mt-0.5 text-xs text-slate-500">confirmed by the provider</p></div>
                                    <div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Benefit releases</dt><dd class="mt-1 text-base font-bold text-slate-950">{{ recipientRecord.summary.released_total }} of {{ recipientRecord.summary.release_total }}</dd><p class="mt-0.5 text-xs text-slate-500">recorded as received</p></div>
                                    <div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Lifecycle decisions</dt><dd class="mt-1 text-base font-bold text-slate-950">{{ recipientRecord.summary.decision_total }}</dd><p class="mt-0.5 text-xs text-slate-500">renewal or closing records</p></div>
                                </dl>
                            </section>

                            <section v-if="recipientRecord.agreement" class="mt-4 rounded-md border border-slate-200 bg-white px-4 py-4 sm:px-5">
                                <div class="flex flex-wrap items-center justify-between gap-2"><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Recipient agreement</p><h3 class="mt-1 font-bold text-slate-950">Terms recorded at selection</h3></div><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', recordStatusClass(recipientRecord.agreement.status)]">{{ recipientRecord.agreement.status_label }}</span></div>
                                <div class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                                    <div><p class="text-[10px] font-bold uppercase tracking-[0.08em] text-slate-500">Commitment</p><p class="mt-1 font-semibold leading-5 text-slate-800">{{ labelFromKey(recipientRecord.agreement.snapshot?.recipient_expectation?.commitment_type || 'provider briefing') }}</p></div>
                                    <div><p class="text-[10px] font-bold uppercase tracking-[0.08em] text-slate-500">Duration or frequency</p><p class="mt-1 leading-5 text-slate-600">{{ recipientRecord.agreement.snapshot?.recipient_expectation?.duration || 'Explained by the provider' }}</p></div>
                                    <div><p class="text-[10px] font-bold uppercase tracking-[0.08em] text-slate-500">Response recorded</p><p class="mt-1 leading-5 text-slate-600">{{ recipientRecord.agreement.responded_at || 'Awaiting recipient response' }}</p></div>
                                </div>
                            </section>

                            <section v-if="recipientRecord.support.latest_decision" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-4 sm:px-5">
                                <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Latest outcome</p><h3 class="mt-1 font-bold text-slate-950">{{ recipientRecord.support.latest_decision.decision_label }}</h3></div><p class="text-xs font-semibold text-slate-600">Effective {{ recipientRecord.support.latest_decision.effective_label }}</p></div>
                                <p v-if="recipientRecord.support.latest_decision.next_period_terms" class="mt-2 text-sm leading-6 text-slate-700">{{ recipientRecord.support.latest_decision.next_period_terms }}</p>
                                <p v-if="recipientRecord.support.latest_decision.reason" class="mt-2 text-sm leading-6 text-slate-700">{{ recipientRecord.support.latest_decision.reason }}</p>
                            </section>

                            <section class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white">
                                <header class="border-b border-slate-200 px-4 py-3.5 sm:px-5"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Full record</p><h3 class="mt-1 font-bold text-slate-950">Recipient history</h3><p class="mt-1 text-xs leading-5 text-slate-500">Newest activity appears first. Uploaded evidence remains available from its related entry.</p></header>
                                <div v-if="!recipientRecord.timeline.length" class="px-5 py-8 text-center text-sm text-slate-500">No recipient history has been recorded yet.</div>
                                <div v-else class="divide-y divide-slate-200">
                                    <article v-for="(event, index) in recipientRecord.timeline" :key="`${event.type}-${index}-${event.occurred_label}`" class="flex gap-3 px-4 py-4 sm:px-5">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i :class="['fa-solid text-xs', recordEventIcon(event.type)]" aria-hidden="true"></i></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-start justify-between gap-2"><div><h4 class="text-sm font-bold text-slate-950">{{ event.title }}</h4><p v-if="event.occurred_label" class="mt-0.5 text-xs text-slate-500">{{ event.occurred_label }}</p></div><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', recordStatusClass(event.status)]">{{ event.status_label }}</span></div>
                                            <p v-if="event.description" class="mt-2 text-sm leading-6 text-slate-600">{{ event.description }}</p>
                                            <button v-if="event.file" type="button" class="mt-2 inline-flex items-center gap-1.5 text-xs font-bold text-slate-800 underline decoration-slate-300 underline-offset-4" @click="previewFile = event.file"><i class="fa-regular fa-file-lines" aria-hidden="true"></i>View {{ event.type === 'release' ? 'receipt' : 'grade record' }}</button>
                                        </div>
                                    </article>
                                </div>
                            </section>
                        </template>
                    </div>

                    <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:justify-end sm:px-6">
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="closeRecipientRecord">Close</button>
                        <a v-if="recipientRecord?.recipient?.application_url" :href="recipientRecord.recipient.application_url" class="rounded-md bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-slate-800">Open applicant review</a>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showCycleForm" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/60 p-4" @click.self="closeComposer" @keydown.esc="closeComposer">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="monitoring-cycle-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Recipient monitoring</p><h2 id="monitoring-cycle-title" class="mt-1 text-xl font-bold text-slate-950">{{ hasActiveMonitoringPlan ? 'New check-in' : 'New academic period' }}</h2><p class="mt-1 text-sm text-slate-600">{{ hasActiveMonitoringPlan ? 'The active plan checklist will be sent to every selected recipient.' : 'One grade record request will be sent to every selected recipient.' }}</p></div>
                        <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="closeComposer"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="publishCycle">
                        <div class="space-y-4 px-5 py-5 sm:px-6">
                            <p v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Check-in title</span><input ref="titleInput" v-model="form.title" required maxlength="120" placeholder="Example: First semester check-in" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100"></label>
                            <div v-if="hasActiveMonitoringPlan" class="rounded-md border border-amber-200 bg-amber-50 p-4"><p class="text-sm font-bold text-slate-950">{{ monitoringPlan.requirement_count }} requirement{{ monitoringPlan.requirement_count === 1 ? '' : 's' }} from the active plan</p><p class="mt-1 text-xs leading-5 text-slate-600">{{ monitoringPlan.requirements.map((item) => item.title).join(' | ') }}</p></div>
                            <div :class="['grid gap-4', hasActiveMonitoringPlan ? '' : 'sm:grid-cols-2']">
                                <label v-if="!hasActiveMonitoringPlan" class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Period type</span><select v-model="form.period_type" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="semester">Semester</option><option value="quarter">Quarter</option><option value="monthly">Monthly</option><option value="custom">Custom period</option></select></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">School year</span><input v-model="form.school_year" maxlength="30" placeholder="2026-2027" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            </div>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Period label</span><input v-model="form.academic_period" maxlength="80" placeholder="Example: First semester or Quarter 2" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <div class="grid gap-4 sm:grid-cols-2"><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Opens on</span><input v-model="form.opens_at" type="date" :min="today" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Due date</span><input v-model="form.due_at" type="date" :min="form.opens_at || today" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label></div>
                            <div v-if="!hasActiveMonitoringPlan" class="grid gap-4 sm:grid-cols-2"><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Grading scale</span><select v-model="form.grading_scale" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm" @change="form.minimum_grade = form.grading_scale === 'grade_point' ? '2.00' : '85'"><option value="percentage">Percentage / general average</option><option value="grade_point">GWA / GPA grade point</option></select></label><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">{{ form.grading_scale === 'grade_point' ? 'Maximum grade point' : 'Minimum average' }}</span><input v-model="form.minimum_grade" type="number" step="0.01" required :min="form.grading_scale === 'grade_point' ? 1 : 0" :max="form.grading_scale === 'grade_point' ? 5 : 100" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label></div>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Recipient instructions</span><textarea v-model="form.instructions" rows="4" maxlength="2000" placeholder="State which report card or grade record to upload and any reminder about bringing the original." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" :disabled="isSaving" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeComposer">Cancel</button><button type="submit" :disabled="isSaving" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isSaving ? 'Publishing...' : (hasActiveMonitoringPlan ? 'Publish check-in' : 'Publish period') }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="showReleaseForm" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/60 p-4" @click.self="closeReleaseComposer" @keydown.esc="closeReleaseComposer">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="benefit-release-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Benefit release</p><h2 id="benefit-release-title" class="mt-1 text-xl font-bold text-slate-950">Schedule recipient release</h2><p class="mt-1 text-sm text-slate-600">Only recipients with confirmed requirements can be included.</p></div>
                        <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="closeReleaseComposer"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="publishRelease">
                        <div class="space-y-4 px-5 py-5 sm:px-6">
                            <p v-if="errorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Release title</span><input v-model="releaseForm.title" required maxlength="120" placeholder="Example: First semester allowance release" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Date and time</span><input v-model="releaseForm.release_at" type="datetime-local" :min="`${today}T00:00`" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Release method</span><select v-model="releaseForm.release_method" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="in_person">In person</option><option value="bank_transfer">Bank transfer</option><option value="e_wallet">E-wallet</option><option value="other">Other arrangement</option></select></label>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-[1fr_180px]">
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Benefit being released</span><input v-model="releaseForm.benefit_description" required maxlength="255" placeholder="Example: Semester allowance and school supplies" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Amount, if cash</span><input v-model="releaseForm.amount" type="number" min="0" step="0.01" placeholder="Optional" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            </div>
                            <label v-if="releaseForm.release_method === 'in_person'" class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Release location</span><input v-model="releaseForm.location" required maxlength="255" placeholder="Office, school, or release venue" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Recipient instructions</span><textarea v-model="releaseForm.instructions" rows="3" maxlength="2000" placeholder="What to bring, where to report, and who to contact." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700"><input v-model="releaseForm.requires_original_verification" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span><strong class="text-slate-950">Confirm original documents before release.</strong><br>The provider must record that originals were checked before marking the benefit released.</span></label>

                            <fieldset>
                                <legend class="text-xs font-bold text-slate-700">Recipients</legend>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Eligibility is based on accepted recipient terms and monitoring requirements currently due.</p>
                                <div class="mt-2 max-h-64 divide-y divide-slate-200 overflow-y-auto rounded-md border border-slate-200">
                                    <label v-for="candidate in releaseCandidates" :key="candidate.application_id" :class="['flex items-start gap-3 px-3 py-3', candidate.eligible ? 'cursor-pointer bg-white' : 'bg-slate-50 opacity-70']">
                                        <input v-model="releaseForm.recipient_ids" type="checkbox" :value="candidate.application_id" :disabled="!candidate.eligible" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950">
                                        <span class="min-w-0 flex-1"><span class="flex flex-wrap items-center justify-between gap-2"><strong class="text-sm text-slate-950">{{ candidate.name }}</strong><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', candidate.eligible ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">{{ candidate.eligibility_label }}</span></span><span class="mt-1 block text-xs leading-5 text-slate-500">{{ candidate.eligibility_reason }}</span></span>
                                    </label>
                                </div>
                            </fieldset>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" :disabled="isSavingRelease" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeReleaseComposer">Cancel</button><button type="submit" :disabled="isSavingRelease || !releaseForm.recipient_ids.length" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isSavingRelease ? 'Scheduling...' : 'Schedule release' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="releaseTarget" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeReleaseResult" @keydown.esc="closeReleaseResult">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="release-result-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Recipient release record</p><h2 id="release-result-title" class="mt-1 text-xl font-bold text-slate-950">{{ releaseTarget.record.name }}</h2><p class="mt-1 text-sm text-slate-500">{{ releaseTarget.release.title }} · {{ releaseTarget.release.release_label }}</p></div>
                        <button type="button" :disabled="isRecordingRelease" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="closeReleaseResult"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="submitReleaseResult">
                        <div class="space-y-4 px-5 py-5">
                            <div class="grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-2"><div class="bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Benefit</p><p class="mt-1 text-sm font-bold text-slate-950">{{ releaseTarget.release.benefit_description }}</p></div><div class="bg-slate-50 p-3"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Current status</p><p class="mt-1 text-sm font-bold text-slate-950">{{ releaseTarget.record.status_label }}</p></div></div>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Outcome</span><select v-model="releaseResultForm.status" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="prepared">Prepared for release</option><option value="released">Released to recipient</option><option value="missed">Recipient missed schedule</option><option value="withheld">Release withheld</option></select></label>
                            <label v-if="releaseTarget.release.requires_original_verification" class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700"><input v-model="releaseResultForm.originals_verified" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span><strong class="text-slate-950">Original documents checked</strong><br>Required before this record can be marked released.</span></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Provider note</span><textarea v-model="releaseResultForm.notes" rows="4" maxlength="1500" :placeholder="releaseResultForm.status === 'released' ? 'Optional if receipt proof is uploaded; otherwise describe how receipt was acknowledged.' : 'Required for a missed or withheld release.'" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Signed receipt or acknowledgement proof</span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-md border border-slate-300 bg-white p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white" @change="releaseResultForm.receipt_proof = $event.target.files?.[0] || null"><span class="mt-1.5 block text-xs text-slate-500">PDF, JPG, JPEG, or PNG up to 5 MB. A written acknowledgement note may be used instead.</span></label>
                            <button v-if="releaseTarget.record.receipt" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="previewFile = releaseTarget.record.receipt">View existing receipt</button>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end"><button type="button" :disabled="isRecordingRelease" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeReleaseResult">Cancel</button><button type="submit" :disabled="isRecordingRelease" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isRecordingRelease ? 'Saving...' : 'Save release result' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="receiptIssueTarget" class="fixed inset-0 z-[2100] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeReceiptIssue" @keydown.esc="closeReceiptIssue">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="receipt-issue-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rose-700">Recipient receipt issue</p><h2 id="receipt-issue-title" class="mt-1 text-xl font-bold">Resolve reported problem</h2><p class="mt-1 text-sm text-slate-500">{{ receiptIssueTarget.record.name }} · {{ receiptIssueTarget.release.title }}</p></div>
                        <button type="button" :disabled="isResolvingReceiptIssue" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500" aria-label="Close" @click="closeReceiptIssue"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="submitReceiptIssueResolution">
                        <div class="space-y-4 p-5">
                            <div class="rounded-md border border-rose-200 bg-rose-50 p-4"><div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-bold text-rose-900">{{ receiptIssueTarget.response.issue_type_label }}</p><span class="text-xs text-rose-700">{{ receiptIssueTarget.response.responded_at }}</span></div><p class="mt-2 text-sm leading-6 text-rose-900">{{ receiptIssueTarget.response.issue_details }}</p><button v-if="receiptIssueTarget.response.evidence" type="button" class="mt-3 rounded-md border border-rose-300 bg-white px-3 py-2 text-xs font-bold text-rose-800" @click="previewFile = receiptIssueTarget.response.evidence">View recipient evidence</button></div>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Resolution</span><select v-model="receiptResolutionForm.resolution_outcome" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="corrected_release">Release corrected</option><option value="replacement_scheduled">Replacement scheduled</option><option value="delivery_confirmed">Delivery confirmed</option><option value="no_change">No change required</option></select></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Resolution note</span><textarea v-model="receiptResolutionForm.resolution_notes" required minlength="5" maxlength="1500" rows="4" placeholder="State what was checked and how the issue was resolved." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Resolution proof <span class="font-normal text-slate-400">(optional)</span></span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-md border border-slate-300 bg-white p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white" @change="receiptResolutionForm.resolution_proof = $event.target.files?.[0] || null"><span class="mt-1.5 block text-xs text-slate-500">This is stored separately from the original release proof.</span></label>
                        </div>
                        <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4"><button type="button" :disabled="isResolvingReceiptIssue" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeReceiptIssue">Cancel</button><button type="submit" :disabled="isResolvingReceiptIssue" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isResolvingReceiptIssue ? 'Saving...' : 'Mark resolved' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="supportTarget" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeSupportDecision" @keydown.esc="closeSupportDecision">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="support-decision-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Recipient lifecycle</p><h2 id="support-decision-title" class="mt-1 text-xl font-bold text-slate-950">Record support outcome</h2><p class="mt-1 text-sm text-slate-500">Renew support or close the recipient record with an auditable reason.</p></div>
                        <button type="button" :disabled="isSavingSupport" class="grid h-9 w-9 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close" @click="closeSupportDecision"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="submitSupportDecision">
                        <div class="space-y-4 px-5 py-5 sm:px-6">
                            <p v-if="supportError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm font-semibold text-rose-700">{{ supportError }}</p>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Recipient</span><select v-model="supportTarget" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option v-for="recipient in supportRecipients.filter((item) => !item.is_closed)" :key="recipient.application_id" :value="recipient">{{ recipient.name }} · {{ recipient.support_status_label }}</option></select></label>

                            <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-bold text-slate-950">{{ supportTarget.renewal_eligibility_label }}</p><span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', supportTarget.renewal_eligible ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800']">{{ supportTarget.renewal_eligible ? 'Renewal ready' : 'Review needed' }}</span></div>
                                <p class="mt-1 text-xs leading-5 text-slate-600">{{ supportTarget.renewal_eligibility_reason }}</p>
                            </div>

                            <fieldset><legend class="text-xs font-bold text-slate-700">Outcome</legend><div class="mt-2 grid gap-2 sm:grid-cols-3">
                                <label :class="['cursor-pointer rounded-md border p-3', supportForm.decision === 'renewed' ? 'border-slate-950 bg-slate-50' : 'border-slate-200']"><input v-model="supportForm.decision" type="radio" value="renewed" class="sr-only" @change="changeSupportDecision"><span class="block text-sm font-bold text-slate-950">Renew support</span><span class="mt-1 block text-xs leading-5 text-slate-500">Continue for another period.</span></label>
                                <label :class="['cursor-pointer rounded-md border p-3', supportForm.decision === 'completed' ? 'border-slate-950 bg-slate-50' : 'border-slate-200']"><input v-model="supportForm.decision" type="radio" value="completed" class="sr-only" @change="changeSupportDecision"><span class="block text-sm font-bold text-slate-950">Complete program</span><span class="mt-1 block text-xs leading-5 text-slate-500">Close after normal completion.</span></label>
                                <label :class="['cursor-pointer rounded-md border p-3', supportForm.decision === 'terminated' ? 'border-rose-500 bg-rose-50' : 'border-slate-200']"><input v-model="supportForm.decision" type="radio" value="terminated" class="sr-only" @change="changeSupportDecision"><span class="block text-sm font-bold text-slate-950">End early</span><span class="mt-1 block text-xs leading-5 text-slate-500">Stop future support with reason.</span></label>
                            </div></fieldset>

                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Decision category</span><select v-model="supportForm.reason_category" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option v-for="option in supportReasonOptions(supportForm.decision)" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Effective date</span><input v-model="supportForm.effective_on" type="date" :max="today" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <template v-if="supportForm.decision === 'renewed'">
                                <div class="grid gap-4 sm:grid-cols-2"><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">New support end date</span><input v-model="supportForm.support_ends_on" type="date" :min="supportForm.effective_on" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Next review date</span><input v-model="supportForm.next_review_on" type="date" :min="supportForm.effective_on" :max="supportForm.support_ends_on || undefined" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label></div>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Next-period terms</span><textarea v-model="supportForm.next_period_terms" required minlength="10" maxlength="2000" rows="4" placeholder="State the continuing requirement, review period, and support covered by this renewal." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            </template>
                            <template v-else>
                                <label v-if="supportForm.decision === 'terminated'" class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Notice given on</span><input v-model="supportForm.notice_given_on" type="date" :max="supportForm.effective_on || today" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">{{ supportForm.decision === 'completed' ? 'Completion summary' : 'Reason for ending support' }}</span><textarea v-model="supportForm.reason" required minlength="10" maxlength="2000" rows="4" :placeholder="supportForm.decision === 'completed' ? 'Summarize how the recipient completed the support period.' : 'Explain the requirement, records reviewed, and prior notice.'" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            </template>

                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Decision document <span class="font-normal text-slate-400">(optional)</span></span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-md border border-slate-300 bg-white p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white" @change="supportForm.decision_document = $event.target.files?.[0] || null"></label>

                            <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-700"><input v-model="supportForm.confirmed" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span>I reviewed the recipient's monitoring, release records, and applicable program terms before recording this outcome.</span></label>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" :disabled="isSavingSupport" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeSupportDecision">Cancel</button><button type="submit" :disabled="isSavingSupport || !supportForm.confirmed || (supportForm.decision === 'renewed' && !supportTarget.renewal_eligible)" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isSavingSupport ? 'Saving...' : 'Confirm outcome' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="supportResponseTarget" class="fixed inset-0 z-[2100] flex items-center justify-center bg-slate-950/65 p-4" @click.self="closeSupportResponse" @keydown.esc="closeSupportResponse">
                <section class="monitoring-modal flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="support-response-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rose-700">Recipient request</p><h2 id="support-response-title" class="mt-1 text-xl font-bold">Review support outcome request</h2><p class="mt-1 text-sm text-slate-500">{{ supportResponseTarget.recipient.name }} · {{ supportResponseTarget.decision.decision_label }}</p></div>
                        <button type="button" :disabled="isResolvingSupportResponse" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500" aria-label="Close" @click="closeSupportResponse"><i class="fa-solid fa-xmark"></i></button>
                    </header>
                    <form class="min-h-0 overflow-y-auto" @submit.prevent="submitSupportResponseResolution">
                        <div class="space-y-4 p-5">
                            <p v-if="supportResolutionError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm font-semibold text-rose-700">{{ supportResolutionError }}</p>
                            <div class="rounded-md border border-rose-200 bg-rose-50 p-4"><div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-bold text-rose-900">{{ supportResponseTarget.decision.applicant_response_label }}</p><span class="text-xs text-rose-700">{{ supportResponseTarget.decision.applicant_responded_at }}</span></div><p class="mt-2 text-sm leading-6 text-rose-900">{{ supportResponseTarget.decision.applicant_response_message }}</p><button v-if="supportResponseTarget.decision.applicant_response_file" type="button" class="mt-3 rounded-md border border-rose-300 bg-white px-3 py-2 text-xs font-bold text-rose-800" @click="previewFile = supportResponseTarget.decision.applicant_response_file">View attachment</button></div>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Resolution</span><select v-model="supportResolutionForm.resolution_outcome" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"><option value="clarified">Clarification provided</option><option value="decision_upheld">Decision upheld</option><option v-if="supportResponseTarget.decision.decision === 'terminated'" value="support_reinstated">Reinstate support</option></select></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Resolution note</span><textarea v-model="supportResolutionForm.resolution_notes" required minlength="5" maxlength="1500" rows="4" placeholder="Explain the review and final response." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            <template v-if="supportResolutionForm.resolution_outcome === 'support_reinstated'">
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">New support end date</span><input v-model="supportResolutionForm.support_ends_on" type="date" :min="today" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Next review date <span class="font-normal text-slate-400">(optional)</span></span><input v-model="supportResolutionForm.next_review_on" type="date" :min="today" :max="supportResolutionForm.support_ends_on || undefined" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Reinstated terms</span><textarea v-model="supportResolutionForm.next_period_terms" required minlength="10" maxlength="2000" rows="3" placeholder="State the requirements and support period after reinstatement." class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6"></textarea></label>
                            </template>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Resolution proof <span class="font-normal text-slate-400">(optional)</span></span><input type="file" accept=".pdf,.jpg,.jpeg,.png" class="block w-full rounded-md border border-slate-300 bg-white p-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-950 file:px-3 file:py-2 file:text-xs file:font-bold file:text-white" @change="supportResolutionForm.resolution_proof = $event.target.files?.[0] || null"></label>
                        </div>
                        <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4"><button type="button" :disabled="isResolvingSupportResponse" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeSupportResponse">Cancel</button><button type="submit" :disabled="isResolvingSupportResponse" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{{ isResolvingSupportResponse ? 'Saving...' : 'Resolve request' }}</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="checklistTarget" class="fixed inset-0 z-[1900] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeChecklist" @keydown.esc="closeChecklist">
                <section class="monitoring-modal flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="monitoring-checklist-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Recipient checklist</p>
                                <h2 id="monitoring-checklist-title" class="mt-1 text-xl font-bold text-slate-950">{{ checklistTarget.recipient.name }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ checklistTarget.cycle.title }}</p>
                            </div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100" aria-label="Close checklist" @click="closeChecklist"><i class="fa-solid fa-xmark"></i></button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-5">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md border border-slate-200 bg-white px-4 py-3">
                            <p class="text-sm font-bold text-slate-950">{{ checklistTarget.recipient.reviewed_item_count }} of {{ checklistTarget.recipient.required_review_count }} required items reviewed</p>
                            <span class="text-xs text-slate-500">Due {{ checklistTarget.cycle.due_label }}</span>
                        </div>

                        <div class="overflow-hidden rounded-md border border-slate-200 bg-white">
                            <section v-for="requirement in checklistTarget.recipient.requirements" :key="requirement.id" class="border-b border-slate-200 p-4 last:border-b-0">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i :class="requirement.icon" aria-hidden="true"></i></span>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="font-bold text-slate-950">{{ requirement.title }}</h3>
                                                <span v-if="!requirement.required" class="rounded bg-slate-100 px-2 py-0.5 text-[9px] font-bold uppercase text-slate-500">Optional</span>
                                            </div>
                                            <p class="mt-1 text-xs text-slate-500">{{ requirement.requires_file ? (requirement.submission ? 'Record received' : 'Waiting for recipient upload') : 'Recorded by provider' }}</p>
                                            <p v-if="requirement.effective_due_label && requirement.effective_due_label !== checklistTarget.cycle.due_label" class="mt-1 text-xs font-bold text-emerald-700">Extended to {{ requirement.effective_due_label }}</p>
                                            <p v-if="requirement.submission?.grade_label" class="mt-1 text-xs font-bold text-slate-700">Detected result: {{ requirement.submission.grade_label }}</p>
                                            <p v-if="requirement.submission?.review_notes" class="mt-1 text-xs leading-5 text-slate-600">Latest note: {{ requirement.submission.review_notes }}</p>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                                        <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', reviewStatusClass(requirement.submission?.review_status)]">{{ requirement.submission?.review_status_label || (requirement.requires_file ? 'Not submitted' : 'Not recorded') }}</span>
                                        <button v-if="requirement.submission?.has_file" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = requirement.submission">View file</button>
                                        <button v-if="requirement.submission || !requirement.requires_file" type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="openReview(checklistTarget.cycle, checklistTarget.recipient, requirement)">{{ requirement.submission?.reviewed_at ? 'Update result' : (requirement.requires_file ? 'Review item' : 'Record result') }}</button>
                                        <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="openIntervention(checklistTarget.cycle, checklistTarget.recipient, requirement)">Add follow-up</button>
                                    </div>
                                </div>

                                <div v-if="requirement.adjustment_request" class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div><p class="text-sm font-bold text-slate-950">{{ requirement.adjustment_request.request_type_label }}</p><p class="mt-1 text-xs text-slate-600">{{ requirement.adjustment_request.reason_label }} · Submitted {{ requirement.adjustment_request.submitted_at }}</p></div>
                                        <div class="flex items-center gap-2"><span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', adjustmentStatusClass(requirement.adjustment_request.status)]">{{ requirement.adjustment_request.status_label }}</span><button v-if="requirement.adjustment_request.status === 'pending'" type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white" @click="openAdjustmentReview(checklistTarget.cycle, checklistTarget.recipient, requirement)">Review request</button></div>
                                    </div>
                                    <p v-if="requirement.adjustment_request.decision_notes" class="mt-2 text-xs leading-5 text-slate-600">Decision note: {{ requirement.adjustment_request.decision_notes }}</p>
                                </div>

                                <div v-if="requirement.interventions?.length" class="mt-3 rounded-md border border-sky-200 bg-sky-50 p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div><p class="text-sm font-bold text-slate-950">{{ requirement.interventions[0].type_label }}</p><p class="mt-1 text-xs leading-5 text-slate-600">{{ requirement.interventions[0].summary }}</p></div>
                                        <div class="flex items-center gap-2"><span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', requirement.interventions[0].status === 'completed' ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800']">{{ requirement.interventions[0].status_label }}</span><button v-if="requirement.interventions[0].status === 'open'" type="button" class="rounded-md border border-sky-300 bg-white px-3 py-2 text-xs font-bold text-sky-800" @click="openIntervention(checklistTarget.cycle, checklistTarget.recipient, requirement, requirement.interventions[0])">Complete</button></div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </div>

                    <footer class="flex items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <a :href="checklistTarget.recipient.application_url" class="text-xs font-bold text-slate-600 hover:text-slate-950">Open recipient record</a>
                        <button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white" @click="closeChecklist">Close</button>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="adjustmentReviewTarget" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeAdjustmentReview" @keydown.esc="closeAdjustmentReview">
                <form class="monitoring-modal flex max-h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" @submit.prevent="submitAdjustmentDecision">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-5">
                        <div class="flex min-w-0 items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Adjustment request</p><h2 class="mt-1 text-xl font-bold">{{ adjustmentReviewTarget.adjustment.request_type_label }}</h2><p class="mt-1 text-sm text-slate-500">{{ adjustmentReviewTarget.recipient.name }} · {{ adjustmentReviewTarget.requirement.title }}</p></div></div>
                        <button type="button" :disabled="isDecidingAdjustment" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500" aria-label="Close request" @click="closeAdjustmentReview"><i class="fa-solid fa-xmark"></i></button>
                    </header>

                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-4 sm:p-5">
                        <div class="rounded-md border border-slate-200 bg-white p-4"><div class="flex flex-wrap items-center justify-between gap-2"><p class="text-sm font-bold">{{ adjustmentReviewTarget.adjustment.reason_label }}</p><span class="text-xs text-slate-500">{{ adjustmentReviewTarget.adjustment.submitted_at }}</span></div><p class="mt-2 text-sm leading-6 text-slate-600">{{ adjustmentReviewTarget.adjustment.explanation }}</p><p v-if="adjustmentReviewTarget.adjustment.requested_due_label" class="mt-2 text-xs font-bold text-slate-700">Requested deadline: {{ adjustmentReviewTarget.adjustment.requested_due_label }}</p><button v-if="adjustmentReviewTarget.adjustment.attachment" type="button" class="mt-3 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="previewFile = adjustmentReviewTarget.adjustment.attachment">View supporting record</button></div>

                        <fieldset><legend class="text-xs font-bold text-slate-700">Decision</legend><div class="mt-2 grid gap-2 sm:grid-cols-2"><label :class="['cursor-pointer rounded-md border bg-white p-3', adjustmentDecisionForm.decision === 'approved' ? 'border-emerald-600 ring-1 ring-emerald-600' : 'border-slate-200']"><input v-model="adjustmentDecisionForm.decision" type="radio" value="approved" class="sr-only"><span class="text-sm font-bold">Approve</span></label><label :class="['cursor-pointer rounded-md border bg-white p-3', adjustmentDecisionForm.decision === 'declined' ? 'border-rose-500 ring-1 ring-rose-500' : 'border-slate-200']"><input v-model="adjustmentDecisionForm.decision" type="radio" value="declined" class="sr-only"><span class="text-sm font-bold">Decline</span></label></div></fieldset>
                        <label v-if="adjustmentReviewTarget.adjustment.request_type === 'extension' && adjustmentDecisionForm.decision === 'approved'" class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Approved deadline</span><input v-model="adjustmentDecisionForm.approved_due_at" type="date" required :min="dateOffset(adjustmentReviewTarget.cycle.due_at, 1)" :max="dateOffset(adjustmentReviewTarget.cycle.due_at, 60)" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"></label>
                        <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Decision note</span><textarea v-model="adjustmentDecisionForm.decision_notes" :required="adjustmentDecisionForm.decision === 'declined'" minlength="5" maxlength="1500" rows="4" placeholder="Explain the arrangement or why the request cannot be approved." class="w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea></label>
                    </div>

                    <footer class="flex justify-end gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:px-5"><button type="button" :disabled="isDecidingAdjustment" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeAdjustmentReview">Cancel</button><button type="submit" :disabled="isDecidingAdjustment" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isDecidingAdjustment ? 'Saving...' : 'Record decision' }}</button></footer>
                </form>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="interventionTarget" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeIntervention" @keydown.esc="closeIntervention">
                <form class="monitoring-modal flex max-h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" @submit.prevent="submitIntervention">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-5"><div class="flex min-w-0 items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Provider follow-up</p><h2 class="mt-1 text-xl font-bold">{{ interventionTarget.mode === 'complete' ? 'Complete follow-up' : 'Add follow-up' }}</h2><p class="mt-1 text-sm text-slate-500">{{ interventionTarget.recipient.name }} · {{ interventionTarget.requirement.title }}</p></div></div><button type="button" :disabled="isSavingIntervention" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500" aria-label="Close follow-up" @click="closeIntervention"><i class="fa-solid fa-xmark"></i></button></header>

                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 p-4 sm:p-5">
                        <template v-if="interventionTarget.mode === 'create'">
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Follow-up type</span><select v-model="interventionForm.type" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="reminder">Reminder</option><option value="consultation">Consultation</option><option value="support_plan">Support plan</option><option value="warning">Formal warning</option></select></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Summary</span><textarea v-model="interventionForm.summary" required minlength="5" maxlength="1000" rows="3" placeholder="State what was discussed or offered." class="w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Recipient action <span class="font-normal text-slate-400">(optional)</span></span><textarea v-model="interventionForm.action_required" maxlength="1000" rows="3" placeholder="State one clear next action, if needed." class="w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Follow-up date <span class="font-normal text-slate-400">(optional)</span></span><input v-model="interventionForm.follow_up_on" type="date" :min="today" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"></label>
                        </template>
                        <template v-else><div class="rounded-md border border-slate-200 bg-white p-4"><p class="text-sm font-bold">{{ interventionTarget.intervention.type_label }}</p><p class="mt-2 text-sm leading-6 text-slate-600">{{ interventionTarget.intervention.summary }}</p><p v-if="interventionTarget.intervention.action_required" class="mt-2 text-xs font-bold text-slate-700">Recipient action: {{ interventionTarget.intervention.action_required }}</p></div><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Completion note <span class="font-normal text-slate-400">(optional)</span></span><textarea v-model="interventionForm.completion_notes" maxlength="1000" rows="4" placeholder="Record what was completed or discussed." class="w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6"></textarea></label></template>
                    </div>

                    <footer class="flex justify-end gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:px-5"><button type="button" :disabled="isSavingIntervention" class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeIntervention">Cancel</button><button type="submit" :disabled="isSavingIntervention" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ isSavingIntervention ? 'Saving...' : (interventionTarget.mode === 'complete' ? 'Complete follow-up' : 'Share follow-up') }}</button></footer>
                </form>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="reviewTarget" class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeReview" @keydown.esc="closeReview">
                <section class="monitoring-modal flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white text-slate-950 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="monitoring-review-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
                            <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Requirement review</p><h2 id="monitoring-review-title" class="mt-1 text-xl font-bold text-slate-950">{{ reviewRequirement?.title || reviewTarget.cycle.title }}</h2><p class="mt-1 text-sm text-slate-500">{{ reviewTarget.recipient.name }}</p></div>
                        </div>
                        <button type="button" :disabled="isReviewing" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-100 disabled:opacity-50" aria-label="Close review" @click="closeReview"><i class="fa-solid fa-xmark"></i></button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto bg-slate-50 p-4 sm:p-5">
                        <div v-if="reviewRequirement?.description || reviewRequirement?.evidence_description" class="rounded-md border border-slate-200 bg-white p-3">
                            <p class="text-sm leading-6 text-slate-600">{{ reviewRequirement.evidence_description || reviewRequirement.description }}</p>
                        </div>

                        <div v-if="reviewSubmission?.grade_label" class="mt-3 rounded-md border border-slate-200 bg-white p-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Submitted result</p><p class="mt-1 text-base font-bold text-slate-950">{{ reviewSubmission.grade_label }}</p></div>
                                <div class="text-right"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">System comparison</p><span :class="['mt-1 inline-flex rounded-md px-2 py-1 text-[10px] font-bold uppercase', comparisonClass(reviewSubmission.comparison?.status)]">{{ comparisonLabel(reviewSubmission) }}</span></div>
                            </div>
                        </div>

                        <div v-if="reviewSubmission?.has_file" class="mt-3 flex flex-col gap-3 rounded-md border border-slate-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ reviewSubmission.original_name }}</p><p class="mt-1 text-xs text-slate-500">Submitted {{ reviewSubmission.submitted_at }}</p></div>
                            <button type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = reviewSubmission">View record</button>
                        </div>
                        <div v-else class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3">
                            <p class="text-sm font-bold text-slate-950">Provider-recorded item</p>
                            <p class="mt-1 text-xs leading-5 text-slate-600">Confirm this using the provider's attendance or activity record.</p>
                        </div>

                        <label class="mt-4 block"><span class="text-xs font-bold uppercase tracking-[0.1em] text-slate-600">Review note</span><textarea v-model="reviewNotes" rows="4" maxlength="1500" placeholder="Optional when confirming the requirement; required for other decisions." class="mt-2 w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100"></textarea></label>

                        <div v-if="reviewSubmission?.reviews?.length" class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <div class="border-b border-slate-200 px-3 py-2.5"><p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-600">Review history</p></div>
                            <div class="divide-y divide-slate-200">
                                <div v-for="review in reviewSubmission.reviews" :key="review.id" class="px-3 py-3 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2"><span class="font-bold text-slate-950">{{ review.decision_label }}</span><span class="text-xs text-slate-500">{{ review.decided_at }}<span v-if="review.reviewed_by"> · {{ review.reviewed_by }}</span></span></div>
                                    <p v-if="review.notes" class="mt-1 leading-5 text-slate-600">{{ review.notes }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <footer class="border-t border-slate-200 bg-white px-4 py-3 sm:px-5">
                        <p class="mb-3 text-xs leading-5 text-slate-500">Record the result supported by the submitted file or provider record.</p>
                        <div class="flex flex-wrap justify-end gap-2">
                            <button type="button" :disabled="isReviewing" class="rounded-md bg-emerald-700 px-3 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60" @click="submitReview('met')">Requirement met</button>
                            <button type="button" :disabled="isReviewing" class="rounded-md border border-rose-200 bg-white px-3 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-60" @click="submitReview('not_met')">Not met</button>
                            <button v-if="reviewRequirement?.requires_file !== false && reviewSubmission?.has_file" type="button" :disabled="isReviewing" class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm font-bold text-amber-900 hover:bg-amber-100 disabled:opacity-60" @click="submitReview('needs_correction')">Request replacement</button>
                            <button type="button" :disabled="isReviewing" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2.5 text-sm font-bold text-sky-800 hover:bg-sky-100 disabled:opacity-60" @click="submitReview('excused')">Approve exception</button>
                        </div>
                    </footer>
                </section>
            </div>
        </Teleport>
    </main>
</template>

<style scoped>
.monitoring-modal :is(input:not([type='checkbox']):not([type='radio']), select, textarea) {
    background-color: #fff;
    color: #0f172a;
}

.monitoring-modal :is(input, textarea)::placeholder {
    color: #94a3b8;
    opacity: 1;
}

.monitoring-modal select option {
    background-color: #fff;
    color: #0f172a;
}
</style>
