<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import ProviderProgramHeader from '../components/ProviderProgramHeader.vue';
import ProviderProgramNav from '../components/ProviderProgramNav.vue';
import ProviderSectionGuide from '../components/ProviderSectionGuide.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import {
    citiesForLocation,
    findLocationOption,
    findPhilippineRegion,
    philippineRegionOptions,
    provincesForRegion,
} from '../support/philippineLocations';

const appElement = document.getElementById('app');
const pageSearchParams = new URLSearchParams(window.location.search);
const currentApplicationsPath = window.location.pathname.replace(/\/$/, '');
const currentApplicationsSection = currentApplicationsPath.split('/').at(-1);
const applicationWorkspaceMode = ['review', 'activities', 'results', 'decisions', 'recipients', 'waitlist']
    .includes(currentApplicationsSection)
    ? currentApplicationsSection
    : 'all';
const initialScholarshipId = appElement?.dataset.scholarshipId ?? pageSearchParams.get('scholarship_id') ?? '';
const initialScholarshipTitle = appElement?.dataset.scholarshipTitle ?? '';
const requestedQueueFilter = pageSearchParams.get('filter');
const requestedQueueSort = pageSearchParams.get('sort');
const requestedApplicationPage = Number(pageSearchParams.get('page'));
const legacyQueueAliases = {
    pending_review: 'needs_review',
    document_issues: 'needs_review',
    active_stages: 'waiting_activity',
    formal_application: 'ready_result',
    decided: 'all',
};
const normalizedRequestedQueueFilter = legacyQueueAliases[requestedQueueFilter] ?? requestedQueueFilter;
const queueFilterValues = ['needs_review', 'waiting_activity', 'ready_result', 'final_decision', 'selected', 'waitlisted', 'all'];
const defaultQueueFilter = {
    review: 'needs_review',
    activities: 'waiting_activity',
    results: 'ready_result',
    decisions: 'final_decision',
    recipients: 'selected',
    waitlist: 'waitlisted',
}[applicationWorkspaceMode] ?? 'needs_review';
const queueSortValues = ['priority', 'dss', 'documents', 'oldest'];
const defaultQueueSort = ['recipients', 'waitlist'].includes(applicationWorkspaceMode) ? 'dss' : 'priority';
const allowedInitialQueueSorts = {
    activities: ['priority', 'dss', 'oldest'],
    results: ['priority', 'dss', 'oldest'],
    decisions: ['priority', 'dss', 'oldest'],
    recipients: ['dss', 'oldest'],
    waitlist: ['dss', 'oldest'],
}[applicationWorkspaceMode] ?? queueSortValues;
const isLoading = ref(true);
const errorMessage = ref('');
const applications = ref([]);
const programOptions = ref([]);
const reviewers = ref([]);
const assigningReviewerApplicationId = ref(null);
const canAssignReviewers = computed(() => reviewers.value.length > 0);
const selectedScholarshipContext = ref(initialScholarshipId ? {
    id: Number(initialScholarshipId),
    title: initialScholarshipTitle,
} : null);
const selectedQueueFilter = ref(
    applicationWorkspaceMode === 'all' && queueFilterValues.includes(normalizedRequestedQueueFilter)
        ? normalizedRequestedQueueFilter
        : defaultQueueFilter,
);
const selectedQueueSort = ref(allowedInitialQueueSorts.includes(requestedQueueSort) ? requestedQueueSort : defaultQueueSort);
const applicationSearch = ref(pageSearchParams.get('search') ?? '');
const selectedProgramFilter = ref(initialScholarshipId ? '' : (pageSearchParams.get('program_id') ?? ''));
const applicationPage = ref(Number.isInteger(requestedApplicationPage) && requestedApplicationPage > 0 ? requestedApplicationPage : 1);
const applicationPagination = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null });
const queueFilterCounts = ref({ needs_review: 0, waiting_activity: 0, ready_result: 0, final_decision: 0, selected: 0, waitlisted: 0, all: 0 });
const totalProviderApplications = ref(0);
const applicationsPerPage = 10;
const activeWorkspaceSection = ref(applicationWorkspaceMode === 'activities' && initialScholarshipId
    ? 'schedule'
    : 'applications');
const programEvents = ref([]);
const scheduleEditorType = ref('');
const scheduleSaving = ref(false);
const completingScheduleId = ref(null);
const scheduleError = ref('');
const scheduleForm = ref(emptyScheduleForm());
const scheduleProvinceOptions = ref([]);
const scheduleCityOptions = ref([]);
const scheduleLocationError = ref('');
const showScheduleMapModal = ref(false);
const isLoadingScheduleProvinces = ref(false);
const isLoadingScheduleCities = ref(false);
const selectedBulkApplicationIds = ref([]);
const bulkAdvanceTarget = ref('pass_prescreening');
const bulkAdvancing = ref(false);
const bulkAdvanceError = ref('');
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();
const minimumScheduleDateTime = new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
let queueReloadTimer = null;
let providerLoadRequestId = 0;
let scheduleLocationRequestId = 0;

const scheduleTypeCatalog = [
    { value: 'exam', label: 'Exam', icon: 'fa-solid fa-clipboard-question', help: 'Provider-managed exam schedule' },
    { value: 'interview', label: 'Interview', icon: 'fa-solid fa-comments', help: 'Shared interview instructions' },
];
const scheduleModeOptions = [
    { value: 'onsite', label: 'On-site' },
    { value: 'online', label: 'Online' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'provider_managed', label: 'Provider managed' },
];
const selectedScholarshipId = computed(() => selectedScholarshipContext.value?.id || initialScholarshipId);
const hasProgramContext = computed(() => Boolean(selectedScholarshipId.value));
const scheduleMapAddress = computed(() => {
    const structuredAddress = composeScheduleAddress(scheduleForm.value);

    return structuredAddress || scheduleForm.value.locationAddress;
});
const configuredScheduleTypes = computed(() => {
    const configured = selectedScholarshipContext.value?.selection_stages ?? ['screening'];

    return scheduleTypeCatalog.filter((type) => configured.includes(type.value));
});
const availableBulkAdvanceTargets = computed(() => {
    const catalog = [
        { value: 'pass_prescreening', label: 'Pass pre-screening' },
        { value: 'pass_stage', label: 'Pass current stage' },
        { value: 'selected', label: 'Mark as selected' },
    ];

    return catalog.filter((target) => applications.value.some((application) => (
        (application.bulk_advance_targets ?? []).includes(target.value)
    )));
});
const selectedBulkAdvanceLabel = computed(() => (
    availableBulkAdvanceTargets.value.find((target) => target.value === bulkAdvanceTarget.value)?.label
        ?? 'Apply workflow action'
));
const exportApplicationsUrl = computed(() => {
    if (!hasProgramContext.value) {
        return selectedProgramFilter.value
            ? `/provider/export/applications?scholarship_id=${encodeURIComponent(selectedProgramFilter.value)}`
            : '/provider/export/applications';
    }

    return `/provider/export/applications?scholarship_id=${encodeURIComponent(selectedScholarshipId.value)}`;
});
const workspaceCopy = {
    review: {
        kicker: 'Applicant review',
        title: 'Review submitted applications',
        description: 'Check applicant profiles, eligibility, and submitted files.',
        listTitle: 'Applications ready for review',
        listDescription: 'Open a record to verify its details and record the pre-screening decision.',
    },
    activities: {
        kicker: 'Activity schedules',
        title: 'Manage activity schedules',
        description: 'Find applicants waiting for an exam or interview schedule.',
        listTitle: 'Applicants waiting for an activity',
        listDescription: 'Open a program workspace to publish or review its shared schedule.',
    },
    results: {
        kicker: 'Activity results',
        title: 'Record completed results',
        description: 'Find applicants whose completed stage needs a result.',
        listTitle: 'Results ready to record',
        listDescription: 'Open an applicant record and record the completed stage outcome.',
    },
    decisions: {
        kicker: 'Final decisions',
        title: 'Complete award decisions',
        description: 'Review applicants who are ready to be selected, waitlisted, or declined.',
        listTitle: 'Applicants awaiting a decision',
        listDescription: 'Open a completed application before recording its final outcome.',
    },
    recipients: {
        kicker: 'Recipient records',
        title: 'Selected recipients',
        description: 'Review applicants selected for scholarship support.',
        listTitle: 'Selected recipient records',
        listDescription: 'Open a recipient record to review the award outcome and support status.',
    },
    waitlist: {
        kicker: 'Waitlist',
        title: 'Waitlisted applicants',
        description: 'Review qualified alternates separately from selected recipients.',
        listTitle: 'Waitlist records',
        listDescription: 'Open an applicant record to review or update the alternate decision.',
    },
};
const focusedWorkspaceCopy = computed(() => workspaceCopy[applicationWorkspaceMode] ?? null);
const workspaceGuides = {
    review: [
        { label: 'Purpose', text: 'Confirm the applicant profile, eligibility, and required files.' },
        { label: 'Records shown', text: 'New submissions and returned corrections that need review.' },
        { label: 'Next action', text: 'Open an applicant and record the pre-screening result.' },
    ],
    activities: [
        { label: 'Purpose', text: 'Coordinate an exam or interview included in the selection plan.' },
        { label: 'Records shown', text: 'Applicants who passed the previous stage and are waiting.' },
        { label: 'Next action', text: 'Publish one shared schedule, then mark the activity complete.' },
    ],
    results: [
        { label: 'Purpose', text: 'Record the result of a completed exam, interview, or handoff.' },
        { label: 'Records shown', text: 'Applicants whose current activity is already complete.' },
        { label: 'Next action', text: 'Review the record and decide who proceeds to the next stage.' },
    ],
    decisions: [
        { label: 'Purpose', text: 'Complete the provider decision after all required stages.' },
        { label: 'Records shown', text: 'Applicants ready to be selected, waitlisted, or declined.' },
        { label: 'Next action', text: 'Open each record and save the final award outcome.' },
    ],
    recipients: [
        { label: 'Purpose', text: 'Keep selected applicant records separate from active reviews.' },
        { label: 'Records shown', text: 'Applicants selected to receive the scholarship support.' },
        { label: 'Next action', text: 'Open a recipient record or continue to monitoring after acceptance.' },
    ],
    waitlist: [
        { label: 'Purpose', text: 'Manage qualified alternates without mixing them with recipients.' },
        { label: 'Records shown', text: 'Applicants saved as possible replacements for open slots.' },
        { label: 'Next action', text: 'Review the alternate record before changing its final decision.' },
    ],
};
const activeWorkspaceGuide = computed(() => workspaceGuides[applicationWorkspaceMode] ?? []);
const focusedWorkflowModes = ['review', 'activities', 'results', 'decisions'];
const focusedOutcomeModes = ['recipients', 'waitlist'];
const isFocusedWorkflowWorkspace = computed(() => (
    focusedWorkflowModes.includes(applicationWorkspaceMode)
));
const isFocusedOutcomeWorkspace = computed(() => (
    focusedOutcomeModes.includes(applicationWorkspaceMode)
));
const isDedicatedQueueWorkspace = computed(() => (
    isFocusedWorkflowWorkspace.value || isFocusedOutcomeWorkspace.value
));
const recordStatusColumnLabel = computed(() => ({
    recipients: 'Recipient status',
    waitlist: 'Waitlist status',
}[applicationWorkspaceMode] ?? 'Current task'));
const pageIcon = computed(() => ({
    review: 'fa-solid fa-file-circle-check',
    activities: 'fa-solid fa-calendar-check',
    results: 'fa-solid fa-clipboard-check',
    decisions: 'fa-solid fa-gavel',
    recipients: 'fa-solid fa-award',
    waitlist: 'fa-solid fa-list-ol',
}[applicationWorkspaceMode] ?? (activeWorkspaceSection.value === 'schedule'
    ? 'fa-solid fa-calendar-check'
    : 'fa-solid fa-users-viewfinder')));
const pageKicker = computed(() => focusedWorkspaceCopy.value?.kicker || (hasProgramContext.value ? 'Program applicants' : 'Applicants'));
const pageTitle = computed(() => focusedWorkspaceCopy.value?.title || (hasProgramContext.value
    ? selectedScholarshipContext.value?.title || 'Scholarship program'
    : 'Applicant workflow'));
const pageDescription = computed(() => focusedWorkspaceCopy.value?.description || (hasProgramContext.value
    ? 'Review applicants, publish activities, and record outcomes.'
    : 'Find applicants who need review or a recorded result.'));
const reviewFilterOptions = computed(() => [
    {
        value: 'needs_review',
        label: 'Needs review',
        description: 'Check profile, eligibility, and files.',
        icon: 'fa-solid fa-file-circle-check',
        count: Number(queueFilterCounts.value.needs_review ?? 0),
    },
    {
        value: 'waiting_activity',
        label: 'Waiting for activity',
        description: 'Applicants awaiting an exam or interview.',
        icon: 'fa-regular fa-calendar',
        count: Number(queueFilterCounts.value.waiting_activity ?? 0),
    },
    {
        value: 'ready_result',
        label: 'Ready for result',
        description: 'Record a completed stage result.',
        icon: 'fa-solid fa-clipboard-check',
        count: Number(queueFilterCounts.value.ready_result ?? 0),
    },
    {
        value: 'final_decision',
        label: 'Final decision',
        description: 'Select, waitlist, or decline.',
        icon: 'fa-solid fa-gavel',
        count: Number(queueFilterCounts.value.final_decision ?? 0),
    },
]);
const visibleReviewFilterOptions = computed(() => {
    if (applicationWorkspaceMode === 'all') {
        return reviewFilterOptions.value;
    }

    const visibleValues = {
        review: ['needs_review'],
        activities: ['waiting_activity'],
        results: ['ready_result'],
        decisions: ['final_decision'],
        recipients: ['selected'],
        waitlist: ['waitlisted'],
    }[applicationWorkspaceMode] ?? [];

    return reviewFilterOptions.value.filter((option) => visibleValues.includes(option.value));
});
const showOutcomeNavigation = computed(() => (
    applicationWorkspaceMode === 'all' && !hasProgramContext.value
));
const listTitle = computed(() => focusedWorkspaceCopy.value?.listTitle || 'What needs attention');
const listDescription = computed(() => focusedWorkspaceCopy.value?.listDescription || (
    hasProgramContext.value
        ? 'Choose a task. Saved results move applicants forward automatically.'
        : 'Choose a queue to view applicants needing that action.'
));
const focusedQueueCount = computed(() => Number(queueFilterCounts.value[selectedQueueFilter.value] ?? 0));
const queueSortOptions = computed(() => {
    if (isFocusedOutcomeWorkspace.value) {
        return [
            { value: 'dss', label: 'Highest match' },
            { value: 'oldest', label: 'Oldest application' },
        ];
    }

    if (isFocusedWorkflowWorkspace.value && applicationWorkspaceMode !== 'review') {
        return [
            { value: 'priority', label: 'Priority first' },
            { value: 'oldest', label: 'Oldest first' },
            { value: 'dss', label: 'Highest match' },
        ];
    }

    return [
        { value: 'priority', label: 'Priority first' },
        { value: 'oldest', label: 'Oldest first' },
        { value: 'dss', label: 'Highest match' },
        { value: 'documents', label: 'Document issues' },
    ];
});
const showReviewerAssignment = computed(() => (
    canAssignReviewers.value
    && selectedQueueFilter.value === 'needs_review'
));
const outcomeFilterOptions = computed(() => [
    {
        value: 'selected',
        label: 'Selected recipients',
        description: 'Applicants confirmed for available scholarship slots.',
        icon: 'fa-solid fa-award',
        action: 'View recipients',
        count: Number(queueFilterCounts.value.selected ?? 0),
    },
    {
        value: 'waitlisted',
        label: 'Waitlisted applicants',
        description: 'Qualified alternates who may be promoted when a slot opens.',
        icon: 'fa-solid fa-list-ol',
        action: 'View waitlist',
        count: Number(queueFilterCounts.value.waitlisted ?? 0),
    },
]);
const emptyQueueMessage = computed(() => ({
    needs_review: 'No applicants currently need pre-screening review.',
    waiting_activity: 'No applicants are waiting for an exam or interview.',
    ready_result: 'No completed activities or formal handoffs need a result.',
    final_decision: 'No applicants are waiting for a final decision.',
    selected: 'No applicants have been selected yet.',
    waitlisted: 'No applicants are currently waitlisted.',
    all: 'No applicants match this search.',
}[selectedQueueFilter.value]));
const rankedApplications = computed(() => {
    return applications.value;
});
const totalApplicationPages = computed(() => Math.max(1, Number(applicationPagination.value.last_page ?? 1)));
const visibleApplications = computed(() => rankedApplications.value);
const visibleApplicationRange = computed(() => {
    if (Number(applicationPagination.value.total ?? 0) === 0) {
        return '0 applications';
    }

    const start = Number(applicationPagination.value.from ?? 0);
    const end = Number(applicationPagination.value.to ?? 0);

    return `${start}-${end} of ${applicationPagination.value.total}`;
});
const bulkEligibleVisibleApplications = computed(() => visibleApplications.value.filter((application) => (
    canBulkAdvance(application)
)));
const allVisibleBulkSelected = computed(() => bulkEligibleVisibleApplications.value.length > 0
    && bulkEligibleVisibleApplications.value.every((application) => selectedBulkApplicationIds.value.includes(application.id)));
const customStatusLabels = {
    approved: 'Qualified for formal application',
    waitlisted: 'Waitlisted alternate',
    withdrawn: 'Withdrawn',
    rejected: 'Not qualified',
    exam_qualified: 'Qualified for exam',
    exam_scheduled: 'Exam scheduled',
    exam_taken: 'Exam taken',
    exam_passed: 'Passed exam',
    exam_failed: 'Failed exam',
    interview_failed: 'Failed interview',
    distribution_scheduled: 'Award release scheduled',
    disbursed: 'Distributed',
    benefits_terminated: 'Benefits stopped',
    for_exam: 'Meets exam eligibility',
    exam_completed: 'Exam completed',
    passed_exam: 'Passed exam',
    failed_exam: 'Failed exam',
    failed_interview: 'Failed interview',
};
function statusLabel(status) {
    if (customStatusLabels[status]) {
        return customStatusLabels[status];
    }

    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function workflowStage(application) {
    return application?.workflow?.current_stage ?? 'screening';
}

function workflowClosed(application) {
    return Boolean(application?.workflow?.is_closed);
}

function applicationQueueLabel(application) {
    if (application?.status === 'benefits_terminated') {
        return statusLabel(application.status);
    }

    return application?.workflow?.final_outcome_label
        ?? application?.workflow?.current_stage_label
        ?? statusLabel(application?.status);
}

function applicationActionLabel(application) {
    if (applicationWorkspaceMode === 'recipients') {
        return 'Open recipient';
    }

    if (applicationWorkspaceMode === 'waitlist') {
        return 'Review alternate';
    }

    if (applicationWorkspaceMode === 'activities') {
        return 'Manage schedule';
    }

    if (workflowClosed(application)) {
        return 'View record';
    }

    if (applicationWaitingForActivity(application)) {
        return 'View applicant';
    }

    if (workflowStage(application) === 'decision') {
        return 'Record outcome';
    }

    if (workflowStage(application) === 'screening') {
        return 'Review applicant';
    }

    return 'Record result';
}

function applicationPrimaryActionUrl(application) {
    if (applicationWorkspaceMode === 'activities' && application.scholarship?.id) {
        return `/provider/programs/${application.scholarship.id}/applications/activities`;
    }

    return applicationDetailUrl(application);
}

function providerNextAction(application) {
    return application?.workflow?.provider_action?.label ?? 'Review application';
}

function applicantSecondaryLabel(application) {
    const email = application.applicant?.email || 'No email provided';

    if (hasProgramContext.value) return email;

    return `${application.scholarship?.title || 'Scholarship'} · ${email}`;
}

function applicationPrioritySignal(application) {
    if (application.correction_status === 'submitted') {
        return { label: 'Correction ready to review', tone: 'text-sky-700' };
    }

    const issueCount = documentIssueCount(application);
    if (issueCount > 0) {
        return {
            label: `${issueCount} file issue${issueCount === 1 ? '' : 's'}`,
            tone: 'text-amber-700',
        };
    }

    if (application.documents_changed_since_review) {
        return { label: 'Files updated since review', tone: 'text-amber-700' };
    }

    if (application.correction_status === 'requested') {
        return { label: 'Correction requested', tone: 'text-amber-700' };
    }

    if (application.status === 'waitlisted' && application.waitlist_position) {
        return { label: `Alternate #${application.waitlist_position}`, tone: 'text-sky-700' };
    }

    if (applicationWorkspaceMode === 'recipients' && application.outcome_at) {
        return { label: `Selected ${application.outcome_at}`, tone: 'text-emerald-700' };
    }

    if (showWaitingTime(application)) {
        return { label: `Waiting ${application.waiting_days}d`, tone: 'text-amber-700' };
    }

    return { label: `Next: ${providerNextAction(application)}`, tone: 'text-slate-600' };
}

function outcomeRecordStatus(application) {
    if (applicationWorkspaceMode === 'waitlist') {
        return application.waitlist_position
            ? `Alternate #${application.waitlist_position}`
            : 'Waitlisted alternate';
    }

    if (application.status === 'benefits_terminated') {
        return 'Support ended';
    }

    return application.recipient_agreement?.status_label
        ?? application.workflow?.final_outcome_label
        ?? 'Selected recipient';
}

function outcomeRecordDetail(application) {
    if (applicationWorkspaceMode === 'waitlist') {
        return application.waitlisted_at
            ? `Waitlisted ${application.waitlisted_at}`
            : 'Qualified alternate record';
    }

    if (application.outcome_at) {
        return `Selected ${application.outcome_at}`;
    }

    return application.requires_student_response
        ? 'Waiting for the recipient agreement response'
        : 'Award outcome recorded';
}

function outcomeRecordStatusClass(application) {
    if (applicationWorkspaceMode === 'waitlist') return 'bg-sky-100 text-sky-800';
    if (application.status === 'benefits_terminated') return 'bg-rose-100 text-rose-800';
    if (application.recipient_agreement?.status === 'accepted') return 'bg-emerald-100 text-emerald-800';
    if (application.requires_student_response) return 'bg-amber-100 text-amber-800';

    return 'bg-emerald-100 text-emerald-800';
}

function canBulkAdvance(application) {
    return (application.bulk_advance_targets ?? []).includes(bulkAdvanceTarget.value);
}

function toggleVisibleBulkSelection() {
    const visibleIds = bulkEligibleVisibleApplications.value.map((application) => application.id);

    if (allVisibleBulkSelected.value) {
        selectedBulkApplicationIds.value = selectedBulkApplicationIds.value.filter((id) => !visibleIds.includes(id));
        return;
    }

    selectedBulkApplicationIds.value = [...new Set([...selectedBulkApplicationIds.value, ...visibleIds])];
}

async function applyBulkAdvance() {
    if (!selectedScholarshipId.value || selectedBulkApplicationIds.value.length === 0 || bulkAdvancing.value) {
        return;
    }

    const target = availableBulkAdvanceTargets.value.find((option) => option.value === bulkAdvanceTarget.value);
    const confirmed = await requestConfirmation({
        title: `${target?.label ?? 'Apply bulk result'}?`,
        message: `${selectedBulkApplicationIds.value.length} selected applicant(s) will move according to their current configured workflow stage.`,
        confirmLabel: target?.label ?? 'Apply result',
    });

    if (!confirmed) {
        return;
    }

    bulkAdvancing.value = true;
    bulkAdvanceError.value = '';

    try {
        await window.axios.patch(`/provider/scholarships/${selectedScholarshipId.value}/applications/bulk-advance`, {
            application_ids: selectedBulkApplicationIds.value,
            target_stage: bulkAdvanceTarget.value,
        });
        selectedBulkApplicationIds.value = [];
        await loadProviderData(false);
    } catch (error) {
        bulkAdvanceError.value = error.response?.data?.errors?.application_ids?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to advance the selected applicants.';
    } finally {
        bulkAdvancing.value = false;
    }
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'exam_passed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['withdrawn', 'rejected', 'not_awarded', 'exam_failed', 'interview_failed', 'benefits_terminated'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled', 'waitlisted'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function applicantInitials(application) {
    return String(application.applicant?.name || application.applicant?.email || 'Applicant')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase();
}

function documentIssueCount(application) {
    return (application.documents ?? []).filter((document) => ['pending', 'needs_replacement', 'rejected'].includes(document.status ?? 'pending')).length;
}

function showWaitingTime(application) {
    return Number(application.waiting_days ?? 0) > 0
        && !['rejected', 'not_awarded', 'exam_failed', 'interview_failed', 'benefits_terminated', 'disbursed', 'renewed'].includes(application.status);
}

function applicationWaitingForActivity(application) {
    const stage = workflowStage(application);

    if (workflowClosed(application) || !['exam', 'interview'].includes(stage)) {
        return false;
    }

    return !(application.schedules ?? []).some((schedule) => (
        schedule.type === stage && schedule.status === 'completed'
    ));
}

function applicationDetailUrl(application) {
    const detailUrl = application.detail_url || `/provider/applications/${application.id}`;
    const url = new URL(detailUrl, window.location.origin);

    url.searchParams.set('return_to', `${window.location.pathname}${window.location.search}`);
    url.searchParams.set('section', workflowClosed(application)
        ? 'history'
        : (applicationWaitingForActivity(application) || workflowStage(application) === 'screening' ? 'applicant' : 'decision'));

    return `${url.pathname}${url.search}${url.hash}`;
}

function emptyScheduleForm(type = '') {
    return {
        type,
        title: '',
        scheduledAt: '',
        mode: 'onsite',
        venue: '',
        locationAddress: '',
        addressLine: '',
        barangay: '',
        city: '',
        province: '',
        region: '',
        latitude: '',
        longitude: '',
        onlineUrl: '',
        instructions: '',
    };
}

function composeScheduleAddress(form) {
    return [form.addressLine, form.barangay, form.city, form.province, form.region]
        .map((part) => String(part ?? '').trim())
        .filter(Boolean)
        .join(', ');
}

function splitScheduleAddress(value) {
    const parts = String(value ?? '')
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean);
    const fields = {
        addressLine: '',
        barangay: '',
        city: '',
        province: '',
        region: '',
    };
    const region = findPhilippineRegion(parts.at(-1));

    if (region) {
        fields.region = region.value;
        parts.pop();
    }

    if (parts.length >= 2) {
        fields.province = parts.pop() ?? '';
        fields.city = parts.pop() ?? '';
    }

    if (parts.length && /^(barangay|brgy\.?)(\s|$)/i.test(parts.at(-1))) {
        fields.barangay = parts.pop() ?? '';
    }

    fields.addressLine = parts.join(', ');

    return fields;
}

function syncScheduleAddress({ clearCoordinates = true } = {}) {
    scheduleForm.value.locationAddress = composeScheduleAddress(scheduleForm.value);

    if (clearCoordinates) {
        scheduleForm.value.latitude = '';
        scheduleForm.value.longitude = '';
    }
}

async function loadScheduleCities(requestId = scheduleLocationRequestId) {
    const region = findPhilippineRegion(scheduleForm.value.region);
    const province = findLocationOption(scheduleProvinceOptions.value, scheduleForm.value.province);

    scheduleCityOptions.value = [];

    if (!region || !province) {
        return;
    }

    isLoadingScheduleCities.value = true;

    try {
        const cities = await citiesForLocation(region.code, province.code);

        if (requestId === scheduleLocationRequestId) {
            scheduleCityOptions.value = cities;
        }
    } catch (error) {
        if (requestId === scheduleLocationRequestId) {
            scheduleLocationError.value = 'City and municipality options could not be loaded. Check your connection and try again.';
        }
    } finally {
        if (requestId === scheduleLocationRequestId) {
            isLoadingScheduleCities.value = false;
        }
    }
}

async function loadScheduleLocationHierarchy({ resetProvince = false, resetCity = false } = {}) {
    const requestId = ++scheduleLocationRequestId;
    const region = findPhilippineRegion(scheduleForm.value.region);

    scheduleLocationError.value = '';
    scheduleProvinceOptions.value = [];
    scheduleCityOptions.value = [];

    if (resetProvince) {
        scheduleForm.value.province = '';
    }

    if (resetProvince || resetCity) {
        scheduleForm.value.city = '';
    }

    if (!region) {
        syncScheduleAddress({ clearCoordinates: false });
        return;
    }

    scheduleForm.value.region = region.value;
    isLoadingScheduleProvinces.value = true;

    try {
        const provinces = await provincesForRegion(region.code);

        if (requestId !== scheduleLocationRequestId) {
            return;
        }

        scheduleProvinceOptions.value = provinces;

        if (region.value === 'NCR') {
            scheduleForm.value.province = 'Metro Manila';
        }

        await loadScheduleCities(requestId);
    } catch (error) {
        if (requestId === scheduleLocationRequestId) {
            scheduleLocationError.value = 'Province options could not be loaded. Check your connection and select the region again.';
        }
    } finally {
        if (requestId === scheduleLocationRequestId) {
            isLoadingScheduleProvinces.value = false;
            syncScheduleAddress({ clearCoordinates: false });
        }
    }
}

function handleScheduleRegionChange() {
    scheduleForm.value.latitude = '';
    scheduleForm.value.longitude = '';
    loadScheduleLocationHierarchy({ resetProvince: true });
}

function handleScheduleProvinceChange() {
    const requestId = ++scheduleLocationRequestId;

    scheduleForm.value.city = '';
    scheduleLocationError.value = '';
    syncScheduleAddress();
    loadScheduleCities(requestId);
}

function handleScheduleAddressChange() {
    syncScheduleAddress();
}

function scheduleTypeLabel(type) {
    return scheduleTypeCatalog.find((option) => option.value === type)?.label ?? type;
}

function scheduleEvent(type) {
    return programEvents.value.find((event) => event.type === type) ?? null;
}

function eventStatusClass(status) {
    return status === 'completed'
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-800';
}

function defaultScheduleDetails(type) {
    const scholarship = selectedScholarshipContext.value ?? {};

    return {
        title: `${scheduleTypeLabel(type)} schedule`,
        mode: 'onsite',
        venue: scholarship.location_name ?? '',
        locationAddress: scholarship.location_address ?? '',
        latitude: scholarship.latitude ?? '',
        longitude: scholarship.longitude ?? '',
        instructions: {
            exam: 'Review the provider exam instructions and arrive or sign in at least 15 minutes before the scheduled time.',
            interview: 'Bring your recent school ID and be ready to discuss your application and scholarship goals.',
        }[type] ?? '',
    };
}

function openScheduleEditor(type) {
    const existing = scheduleEvent(type);
    const defaults = defaultScheduleDetails(type);
    const locationAddress = existing?.location_address ?? defaults.locationAddress ?? '';
    const addressFields = splitScheduleAddress(locationAddress);

    scheduleForm.value = existing
        ? {
            type: existing.type,
            title: existing.title ?? '',
            scheduledAt: existing.scheduled_at ?? '',
            mode: existing.mode ?? 'onsite',
            venue: existing.venue ?? '',
            locationAddress,
            ...addressFields,
            latitude: existing.latitude ?? '',
            longitude: existing.longitude ?? '',
            onlineUrl: existing.online_url ?? '',
            instructions: existing.instructions ?? '',
        }
        : { ...emptyScheduleForm(type), ...defaults, ...addressFields, type };
    scheduleEditorType.value = type;
    scheduleError.value = '';
    loadScheduleLocationHierarchy();
}

function closeScheduleEditor() {
    showScheduleMapModal.value = false;
    scheduleEditorType.value = '';
    scheduleForm.value = emptyScheduleForm();
    scheduleProvinceOptions.value = [];
    scheduleCityOptions.value = [];
    scheduleLocationError.value = '';
}

function handleSchedulePinResolved(location) {
    scheduleForm.value.latitude = Number(location.latitude).toFixed(7);
    scheduleForm.value.longitude = Number(location.longitude).toFixed(7);
}

function handleSchedulePinPicked(location) {
    const address = location.address ?? {};
    const resolvedRegion = findPhilippineRegion(address.region || address.state || scheduleForm.value.region);

    scheduleForm.value.latitude = location.latitude;
    scheduleForm.value.longitude = location.longitude;
    scheduleForm.value.addressLine = [address.house_number, address.road]
        .filter(Boolean)
        .join(' ') || address.building || scheduleForm.value.addressLine;
    scheduleForm.value.barangay = address.neighbourhood
        || address.suburb
        || address.quarter
        || address.village
        || scheduleForm.value.barangay;
    scheduleForm.value.city = address.city
        || address.municipality
        || address.town
        || address.city_district
        || scheduleForm.value.city;
    scheduleForm.value.province = address.province
        || address.state_district
        || address.county
        || scheduleForm.value.province;
    scheduleForm.value.region = resolvedRegion?.value || scheduleForm.value.region;
    scheduleForm.value.locationAddress = composeScheduleAddress(scheduleForm.value)
        || location.displayName
        || scheduleForm.value.locationAddress;
    loadScheduleLocationHierarchy();
}

async function saveProgramSchedule() {
    if (!scheduleForm.value.scheduledAt || !scheduleForm.value.instructions.trim()) {
        scheduleError.value = 'Add the date, time, and applicant instructions.';
        return;
    }

    scheduleSaving.value = true;
    scheduleError.value = '';

    try {
        const response = await window.axios.post(`/provider/scholarships/${selectedScholarshipId.value}/events`, {
            type: scheduleForm.value.type,
            title: scheduleForm.value.title || null,
            scheduled_at: scheduleForm.value.scheduledAt,
            mode: scheduleForm.value.mode,
            venue: scheduleForm.value.venue || null,
            location_address: scheduleMapAddress.value || null,
            latitude: scheduleForm.value.latitude || null,
            longitude: scheduleForm.value.longitude || null,
            online_url: scheduleForm.value.onlineUrl || null,
            instructions: scheduleForm.value.instructions,
        });
        const eventIndex = programEvents.value.findIndex((event) => event.type === response.data.event.type);

        if (eventIndex >= 0) {
            programEvents.value.splice(eventIndex, 1, response.data.event);
        } else {
            programEvents.value.push(response.data.event);
        }

        closeScheduleEditor();
        await loadProviderData(false);
    } catch (handledError) {
        void handledError;
    } finally {
        scheduleSaving.value = false;
    }
}

async function completeProgramSchedule(event) {
    if (!event || event.status === 'completed' || completingScheduleId.value) {
        return;
    }

    const confirmed = await requestConfirmation({
        title: `Complete ${scheduleTypeLabel(event.type)} activity?`,
        message: 'This unlocks pass or fail decisions for applicants currently at this stage.',
        confirmLabel: 'Mark complete',
    });

    if (!confirmed) {
        return;
    }

    completingScheduleId.value = event.id;
    scheduleError.value = '';

    try {
        const response = await window.axios.patch(`/provider/scholarships/${selectedScholarshipId.value}/events/${event.id}/complete`);
        const eventIndex = programEvents.value.findIndex((programEvent) => programEvent.id === event.id);

        if (eventIndex >= 0) {
            programEvents.value.splice(eventIndex, 1, response.data.event);
        }

        closeScheduleEditor();
        await loadProviderData(false);
    } catch (error) {
        scheduleError.value = error.response?.data?.errors?.event?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to complete this activity.';
    } finally {
        completingScheduleId.value = null;
    }
}

async function loadProviderData(showLoading = true) {
    const requestId = ++providerLoadRequestId;
    isLoading.value = showLoading;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/applications/data', {
            params: {
                ...(hasProgramContext.value ? { scholarship_id: selectedScholarshipId.value } : {}),
                ...(!hasProgramContext.value && selectedProgramFilter.value ? { program_id: selectedProgramFilter.value } : {}),
                filter: selectedQueueFilter.value,
                sort: selectedQueueSort.value,
                search: applicationSearch.value.trim() || undefined,
                page: applicationPage.value,
                per_page: applicationsPerPage,
            },
        });

        if (requestId !== providerLoadRequestId) {
            return;
        }

        applications.value = response.data.applications;
        programOptions.value = Array.isArray(response.data.scholarships) ? response.data.scholarships : [];
        reviewers.value = Array.isArray(response.data.reviewers) ? response.data.reviewers : [];
        selectedScholarshipContext.value = response.data.selected_scholarship ?? selectedScholarshipContext.value;
        programEvents.value = response.data.program_events ?? [];
        applicationPagination.value = response.data.pagination ?? applicationPagination.value;
        queueFilterCounts.value = response.data.filter_counts ?? queueFilterCounts.value;
        totalProviderApplications.value = Number(response.data.stats?.applications ?? 0);

        if (!availableBulkAdvanceTargets.value.some((target) => target.value === bulkAdvanceTarget.value)) {
            bulkAdvanceTarget.value = availableBulkAdvanceTargets.value[0]?.value ?? 'pass_prescreening';
        }

    } catch (error) {
        if (requestId === providerLoadRequestId) {
            errorMessage.value = error.response?.data?.message ?? 'Unable to load provider applications.';
        }
    } finally {
        if (requestId === providerLoadRequestId) {
            isLoading.value = false;
        }
    }
}

function scheduleProviderDataLoad(delay = 0) {
    window.clearTimeout(queueReloadTimer);
    queueReloadTimer = window.setTimeout(() => loadProviderData(false), delay);
}

function reviewersForApplication(application) {
    const programId = Number(application.scholarship?.id);

    return reviewers.value.filter((reviewer) => (
        reviewer.program_access_mode !== 'selected'
        || (reviewer.assigned_program_ids ?? []).map(Number).includes(programId)
    ));
}

async function assignReviewer(application, event) {
    const previousReviewerId = application.assigned_reviewer?.id ?? '';
    const selectedReviewerId = event.target.value ? Number(event.target.value) : null;

    assigningReviewerApplicationId.value = application.id;

    try {
        const response = await window.axios.patch(`/provider/applications/${application.id}/reviewer`, {
            assigned_reviewer_id: selectedReviewerId,
        });
        const applicationIndex = applications.value.findIndex((item) => item.id === application.id);

        if (applicationIndex >= 0) {
            applications.value.splice(applicationIndex, 1, response.data.application);
        }
    } catch (handledError) {
        event.target.value = previousReviewerId;
        void handledError;
    } finally {
        assigningReviewerApplicationId.value = null;
    }
}

watch([selectedQueueFilter, selectedQueueSort, applicationSearch, selectedProgramFilter], ([, , search], [, , previousSearch]) => {
    if (applicationPage.value !== 1) {
        applicationPage.value = 1;

        return;
    }

    scheduleProviderDataLoad(search !== previousSearch ? 300 : 0);
});

watch(applicationPage, () => scheduleProviderDataLoad());

watch([selectedQueueFilter, selectedQueueSort, applicationSearch, selectedProgramFilter, applicationPage], ([filter, sort, search, programId, page]) => {
    const url = new URL(window.location.href);

    url.searchParams.set('filter', filter);
    url.searchParams.set('sort', sort);

    if (search.trim()) {
        url.searchParams.set('search', search.trim());
    } else {
        url.searchParams.delete('search');
    }

    if (!hasProgramContext.value && programId) {
        url.searchParams.set('program_id', programId);
    } else {
        url.searchParams.delete('program_id');
    }

    if (page > 1) {
        url.searchParams.set('page', String(page));
    } else {
        url.searchParams.delete('page');
    }

    window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
});

watch(bulkAdvanceTarget, () => {
    selectedBulkApplicationIds.value = [];
    bulkAdvanceError.value = '';
});

watch(totalApplicationPages, (totalPages) => {
    if (applicationPage.value > totalPages) {
        applicationPage.value = totalPages;
    }
});

onUnmounted(() => {
    window.clearTimeout(queueReloadTimer);
});

onMounted(loadProviderData);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <ProviderProgramHeader
                    v-if="hasProgramContext"
                    :program-id="selectedScholarshipId"
                    :title="selectedScholarshipContext?.title || 'Scholarship program'"
                    :status="selectedScholarshipContext?.status"
                    :section="pageTitle"
                >
                    <template v-if="isDedicatedQueueWorkspace" #meta>
                        <span>{{ focusedQueueCount }} in queue</span>
                        <span>{{ visibleApplicationRange }}</span>
                    </template>
                    <template v-if="applicationWorkspaceMode === 'recipients'" #actions>
                        <a :href="`/provider/programs/${selectedScholarshipId}/monitoring`" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                            Open monitoring
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </a>
                    </template>
                </ProviderProgramHeader>

                <TaskPageHeader
                    v-else
                    theme="provider"
                    :eyebrow="pageKicker"
                    :title="pageTitle"
                    :description="pageDescription"
                    :icon="pageIcon"
                >
                    <template v-if="isDedicatedQueueWorkspace" #meta>
                        <span>{{ focusedQueueCount }} in this queue</span>
                        <span>{{ visibleApplicationRange }}</span>
                    </template>
                </TaskPageHeader>

                <ProviderProgramNav
                    v-if="hasProgramContext"
                    :program-id="selectedScholarshipId"
                    :active="activeWorkspaceSection === 'schedule' ? 'schedule' : 'applicants'"
                />

                <ProviderSectionGuide v-if="hasProgramContext && activeWorkspaceGuide.length" :items="activeWorkspaceGuide" />

                <div v-if="isLoading" class="provider-panel mt-3 p-6 text-sm text-slate-500">
                    Loading applicants...
                </div>

                <div v-else-if="errorMessage" class="mt-3 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-3 flex flex-col gap-3">
                    <section v-if="hasProgramContext && activeWorkspaceSection === 'schedule'" class="provider-panel p-4 sm:p-5">
                        <p v-if="scheduleError" class="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ scheduleError }}</p>

                        <div
                            v-if="configuredScheduleTypes.length === 0"
                            :class="['rounded-md border border-dashed border-slate-300 bg-slate-50 p-5', scheduleError ? 'mt-4' : '']"
                        >
                            <p class="text-sm font-bold text-slate-900">No scheduled activities are used in this program</p>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">This selection flow does not include an exam or interview, so there is nothing to publish here.</p>
                        </div>

                        <div v-else :class="['grid gap-3 sm:grid-cols-2', scheduleError ? 'mt-4' : '']">
                            <button
                                v-for="type in configuredScheduleTypes"
                                :key="type.value"
                                type="button"
                                :class="[
                                    'flex min-h-24 flex-col rounded-md border p-3 text-left transition',
                                    scheduleEditorType === type.value
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-200 bg-slate-50 hover:border-slate-300 hover:bg-white',
                                ]"
                                @click="openScheduleEditor(type.value)"
                            >
                                <span class="flex items-start justify-between gap-3">
                                    <span :class="['grid h-9 w-9 place-items-center rounded-md', scheduleEditorType === type.value ? 'bg-white/10' : 'bg-white text-slate-700 ring-1 ring-slate-200']">
                                        <i :class="type.icon" aria-hidden="true"></i>
                                    </span>
                                    <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', scheduleEvent(type.value) ? eventStatusClass(scheduleEvent(type.value).status) : (scheduleEditorType === type.value ? 'bg-white/10 text-white' : 'bg-slate-200 text-slate-600')]">
                                        {{ scheduleEvent(type.value) ? statusLabel(scheduleEvent(type.value).status) : 'Not set' }}
                                    </span>
                                </span>
                                <span class="mt-3 font-bold">{{ type.label }}</span>
                                <span :class="['mt-1 text-xs leading-5', scheduleEditorType === type.value ? 'text-slate-300' : 'text-slate-500']">
                                    {{ scheduleEvent(type.value)?.scheduled_label || type.help }}
                                </span>
                            </button>
                        </div>

                        <form v-if="scheduleEditorType" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4" @submit.prevent="saveProgramSchedule">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ scheduleTypeLabel(scheduleForm.type) }}</p>
                                    <h4 class="mt-1 font-bold text-slate-950">Publish shared instructions</h4>
                                </div>
                                <button type="button" class="text-sm font-bold text-slate-500 hover:text-slate-900" @click="closeScheduleEditor">Close</button>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Title</label>
                                    <input v-model="scheduleForm.title" type="text" maxlength="255" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Date and time</label>
                                    <input v-model="scheduleForm.scheduledAt" type="datetime-local" :min="minimumScheduleDateTime" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Mode</label>
                                    <select v-model="scheduleForm.mode" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                        <option v-for="mode in scheduleModeOptions" :key="mode.value" :value="mode.value">{{ mode.label }}</option>
                                    </select>
                                </div>
                            </div>

                            <div v-if="['onsite', 'hybrid'].includes(scheduleForm.mode)" class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <div class="md:col-span-2 xl:col-span-4">
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Event venue</label>
                                    <input v-model="scheduleForm.venue" type="text" maxlength="500" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Region</label>
                                    <select v-model="scheduleForm.region" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600" @change="handleScheduleRegionChange">
                                        <option value="">Select region</option>
                                        <option v-if="scheduleForm.region && !findPhilippineRegion(scheduleForm.region)" :value="scheduleForm.region">{{ scheduleForm.region }}</option>
                                        <option v-for="region in philippineRegionOptions" :key="region.code" :value="region.value">{{ region.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Province</label>
                                    <select v-model="scheduleForm.province" :disabled="!scheduleForm.region || isLoadingScheduleProvinces" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600 disabled:bg-slate-100" @change="handleScheduleProvinceChange">
                                        <option value="">{{ isLoadingScheduleProvinces ? 'Loading provinces...' : 'Select province' }}</option>
                                        <option v-if="scheduleForm.province && !findLocationOption(scheduleProvinceOptions, scheduleForm.province)" :value="scheduleForm.province">{{ scheduleForm.province }}</option>
                                        <option v-for="province in scheduleProvinceOptions" :key="province.code" :value="province.value">{{ province.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">City / municipality</label>
                                    <select v-model="scheduleForm.city" :disabled="!scheduleForm.province || isLoadingScheduleCities" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600 disabled:bg-slate-100" @change="handleScheduleAddressChange">
                                        <option value="">{{ isLoadingScheduleCities ? 'Loading cities...' : 'Select city or municipality' }}</option>
                                        <option v-if="scheduleForm.city && !findLocationOption(scheduleCityOptions, scheduleForm.city)" :value="scheduleForm.city">{{ scheduleForm.city }}</option>
                                        <option v-for="city in scheduleCityOptions" :key="city.code" :value="city.value">{{ city.label }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Barangay</label>
                                    <input v-model="scheduleForm.barangay" type="text" maxlength="255" placeholder="Barangay" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600" @input="handleScheduleAddressChange">
                                </div>
                                <div class="md:col-span-2 xl:col-span-4">
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Street / building address</label>
                                    <input v-model="scheduleForm.addressLine" type="text" maxlength="500" placeholder="Building, house number, and street" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600" @input="handleScheduleAddressChange">
                                    <p class="mt-2 text-xs leading-5 text-slate-500">Use the exact activity location. It can differ from the provider office or program address.</p>
                                </div>
                                <p v-if="scheduleLocationError" class="text-xs font-semibold text-rose-600 md:col-span-2 xl:col-span-4">{{ scheduleLocationError }}</p>
                                <div class="flex flex-col gap-3 rounded-md border border-slate-200 bg-white p-3 md:col-span-2 xl:col-span-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div><p class="text-sm font-bold text-slate-950">Activity map pin</p><p class="mt-1 text-xs leading-5 text-slate-500">{{ scheduleForm.latitude ? 'A location pin is set for this activity.' : 'Open the map to confirm the activity address and place its pin.' }}</p></div>
                                    <button type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="showScheduleMapModal = true"><i class="fa-solid fa-map-location-dot mr-1.5 text-amber-700" aria-hidden="true"></i>{{ scheduleForm.latitude ? 'Review map pin' : 'Set map pin' }}</button>
                                </div>
                            </div>

                            <div v-if="['online', 'hybrid'].includes(scheduleForm.mode)" class="mt-4">
                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                    Online link
                                    <span class="ml-1 text-[10px] text-slate-400">Required</span>
                                </label>
                                <input v-model="scheduleForm.onlineUrl" type="url" maxlength="2000" placeholder="https://..." required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                            </div>

                            <div class="mt-4">
                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Applicant instructions</label>
                                <textarea v-model="scheduleForm.instructions" rows="3" maxlength="3000" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600"></textarea>
                            </div>

                            <div class="mt-4 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <button
                                    v-if="scheduleEvent(scheduleForm.type)?.status === 'scheduled'"
                                    type="button"
                                    :disabled="Boolean(completingScheduleId) || scheduleSaving"
                                    class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-800 transition hover:border-slate-500 hover:bg-slate-100 disabled:opacity-60"
                                    @click="completeProgramSchedule(scheduleEvent(scheduleForm.type))"
                                >
                                    {{ completingScheduleId === scheduleEvent(scheduleForm.type)?.id ? 'Completing...' : 'Mark activity complete' }}
                                </button>
                                <span v-else></span>
                                <button type="submit" :disabled="scheduleSaving" class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                                    {{ scheduleSaving ? 'Publishing...' : 'Publish schedule' }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section v-if="showOutcomeNavigation" class="provider-panel overflow-hidden">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">After final decision</p>
                            <h3 class="mt-1 text-xl font-bold text-slate-950">Recipients and waitlist</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-500">Review completed selections separately.</p>
                        </div>
                        <div class="grid gap-3 p-4 sm:grid-cols-2 sm:p-5">
                            <button
                                v-for="outcome in outcomeFilterOptions"
                                :key="outcome.value"
                                type="button"
                                :class="[
                                    'group flex items-center gap-3 rounded-md border p-3 text-left transition',
                                    selectedQueueFilter === outcome.value
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-slate-200 bg-slate-50 text-slate-900 hover:border-slate-400 hover:bg-white',
                                ]"
                                @click="selectedQueueFilter = outcome.value"
                            >
                                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md', selectedQueueFilter === outcome.value ? 'bg-white/10 text-amber-300' : 'bg-white text-slate-700 ring-1 ring-slate-200']">
                                    <i :class="outcome.icon" aria-hidden="true"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-3">
                                        <span class="text-sm font-bold">{{ outcome.label }}</span>
                                        <span :class="['rounded px-2 py-0.5 text-xs font-bold', selectedQueueFilter === outcome.value ? 'bg-white/10 text-white' : 'bg-white text-slate-800 ring-1 ring-slate-200']">{{ outcome.count }}</span>
                                    </span>
                                    <span :class="['mt-1 block text-xs leading-5', selectedQueueFilter === outcome.value ? 'text-slate-300' : 'text-slate-500']">{{ outcome.description }}</span>
                                    <span :class="['mt-2 inline-flex items-center gap-2 text-xs font-bold', selectedQueueFilter === outcome.value ? 'text-amber-300' : 'text-slate-800']">
                                        {{ outcome.action }}
                                        <i class="fa-solid fa-arrow-right text-[9px] transition group-hover:translate-x-0.5" aria-hidden="true"></i>
                                    </span>
                                </span>
                            </button>
                        </div>
                    </section>

                    <section v-if="!hasProgramContext || activeWorkspaceSection === 'applications' || applicationWorkspaceMode === 'activities'" class="provider-panel p-4 sm:p-5">
                        <div v-if="hasProgramContext && applicationWorkspaceMode === 'activities'" class="mb-4 border-b border-slate-200 pb-4">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Activity queue</p>
                            <h2 class="mt-1 text-lg font-bold text-slate-950">Applicants waiting for this stage</h2>
                            <p class="mt-1 text-sm leading-5 text-slate-500">Open a record when you need to confirm who is included before completing the activity.</p>
                        </div>
                        <div v-if="!isDedicatedQueueWorkspace">
                            <h3 class="text-xl font-bold text-slate-950">{{ listTitle }}</h3>
                            <p v-if="applicationWorkspaceMode === 'all' || hasProgramContext" class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                {{ listDescription }}
                            </p>

                            <div v-if="visibleReviewFilterOptions.length > 1" class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                <button
                                    v-for="filter in visibleReviewFilterOptions"
                                    :key="filter.value"
                                    type="button"
                                    :class="[
                                        'flex min-h-20 items-start gap-3 rounded-md border p-3 text-left transition',
                                        selectedQueueFilter === filter.value
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-200 bg-slate-50 text-slate-900 hover:border-slate-400 hover:bg-white',
                                    ]"
                                    @click="selectedQueueFilter = filter.value"
                                >
                                    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md', selectedQueueFilter === filter.value ? 'bg-white/10 text-amber-300' : 'bg-white text-slate-600 ring-1 ring-slate-200']">
                                        <i :class="filter.icon" aria-hidden="true"></i>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center justify-between gap-2">
                                            <span class="text-sm font-bold">{{ filter.label }}</span>
                                            <span :class="['shrink-0 rounded px-2 py-0.5 text-xs font-bold', selectedQueueFilter === filter.value ? 'bg-white/10 text-white' : 'bg-white text-slate-800 ring-1 ring-slate-200']">{{ filter.count }}</span>
                                        </span>
                                        <span :class="['mt-1 block text-xs leading-5', selectedQueueFilter === filter.value ? 'text-slate-300' : 'text-slate-500']">{{ filter.description }}</span>
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div v-if="!hasProgramContext || rankedApplications.length > 0 || applicationSearch" :class="['grid gap-3 lg:grid-cols-[minmax(18rem,1fr)_auto] lg:items-center', isDedicatedQueueWorkspace ? '' : 'mt-4']">
                            <label class="relative w-full">
                                <span class="sr-only">Search applicants</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="applicationSearch"
                                    type="search"
                                    placeholder="Search applicant, email, or program"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                >
                            </label>
                            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                <label v-if="!hasProgramContext">
                                    <span class="sr-only">Filter applicants by program</span>
                                    <select v-model="selectedProgramFilter" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none transition focus:border-slate-500 sm:w-56">
                                        <option value="">All programs</option>
                                        <option v-for="program in programOptions" :key="program.id" :value="String(program.id)">{{ program.title }}</option>
                                    </select>
                                </label>
                                <button
                                    v-if="applicationWorkspaceMode === 'all'"
                                    type="button"
                                    :class="[
                                        'rounded-md border px-3 py-2.5 text-sm font-bold transition',
                                        selectedQueueFilter === 'all'
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
                                    ]"
                                    @click="selectedQueueFilter = 'all'"
                                >
                                    All records ({{ queueFilterCounts.all ?? 0 }})
                                </button>
                                <label>
                                    <span class="sr-only">Sort applications</span>
                                    <select
                                        v-model="selectedQueueSort"
                                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none transition focus:border-slate-500 sm:w-44"
                                    >
                                        <option v-for="option in queueSortOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                    </select>
                                </label>
                                <label v-if="hasProgramContext && availableBulkAdvanceTargets.length > 1">
                                    <span class="sr-only">Bulk workflow action</span>
                                    <select v-model="bulkAdvanceTarget" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none transition focus:border-slate-500 sm:w-48">
                                        <option v-for="target in availableBulkAdvanceTargets" :key="target.value" :value="target.value">Bulk: {{ target.label }}</option>
                                    </select>
                                </label>
                                <details class="group relative">
                                    <summary class="inline-flex min-h-10 w-full cursor-pointer list-none items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100 [&::-webkit-details-marker]:hidden">
                                        More
                                        <i class="fa-solid fa-chevron-down text-[9px] text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                    </summary>
                                    <div class="absolute right-0 z-30 mt-1 w-48 overflow-hidden rounded-md border border-slate-200 bg-white p-1 shadow-xl">
                                        <a :href="exportApplicationsUrl" class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 hover:text-slate-950">
                                            <i class="fa-solid fa-file-excel w-4 text-center text-xs text-slate-400" aria-hidden="true"></i>
                                            Export Excel
                                        </a>
                                    </div>
                                </details>
                            </div>
                        </div>

                        <div v-if="hasProgramContext && availableBulkAdvanceTargets.length && selectedBulkApplicationIds.length" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Bulk action</p>
                                    <p class="mt-1 text-sm font-bold text-slate-900">{{ selectedBulkAdvanceLabel }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="bulkEligibleVisibleApplications.length === 0" @click="toggleVisibleBulkSelection">
                                        {{ allVisibleBulkSelected ? 'Clear page' : 'Select page' }}
                                    </button>
                                    <span class="text-xs font-bold text-slate-500">{{ selectedBulkApplicationIds.length }} selected</span>
                                    <button type="button" class="px-2 py-2 text-xs font-bold text-slate-500 hover:text-slate-900" @click="selectedBulkApplicationIds = []">Clear</button>
                                    <button type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="selectedBulkApplicationIds.length === 0 || bulkAdvancing" @click="applyBulkAdvance">
                                        {{ bulkAdvancing ? 'Saving...' : 'Apply to selected' }}
                                    </button>
                                </div>
                            </div>
                            <p v-if="bulkAdvanceError" class="mt-2 text-xs font-semibold text-rose-700">{{ bulkAdvanceError }}</p>
                        </div>

                        <div class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <div class="portal-record-head hidden gap-4 lg:grid lg:grid-cols-[minmax(0,1.2fr)_minmax(12rem,0.8fr)_auto]">
                                <span>Applicant</span>
                                <span>{{ recordStatusColumnLabel }}</span>
                                <span class="text-right">Action</span>
                            </div>

                            <div v-if="rankedApplications.length === 0" class="portal-table-empty">
                                <p class="portal-table-empty-title">
                                    {{ totalProviderApplications === 0 ? 'No applicants yet' : 'No applicants in this queue' }}
                                </p>
                                <p class="portal-table-empty-copy mx-auto max-w-2xl">
                                    {{ totalProviderApplications === 0
                                        ? (hasProgramContext
                                            ? 'Applicants will appear here after they submit the program pre-screening form.'
                                            : 'Applicants will appear after a published scholarship receives a submission.')
                                        : emptyQueueMessage }}
                                </p>
                            </div>

                            <article
                                v-for="application in visibleApplications"
                                :key="application.id"
                                :class="[
                                    'portal-record-row grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(12rem,0.8fr)_auto] lg:items-center',
                                ]"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <label v-if="hasProgramContext && availableBulkAdvanceTargets.length" :title="canBulkAdvance(application) ? 'Select applicant' : 'This applicant is not ready for the selected bulk action.'" class="grid h-8 w-8 shrink-0 place-items-center">
                                        <input v-model="selectedBulkApplicationIds" type="checkbox" :value="application.id" :disabled="!canBulkAdvance(application)" class="h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-30">
                                        <span class="sr-only">Select {{ application.applicant?.name || 'applicant' }}</span>
                                    </label>
                                    <img
                                        v-if="application.applicant?.profile_photo_url"
                                        :src="application.applicant.profile_photo_url"
                                        :alt="`${application.applicant?.name || 'Applicant'} profile photo`"
                                        class="h-10 w-10 shrink-0 rounded-md bg-slate-100 object-cover ring-1 ring-slate-200"
                                    >
                                    <div v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-[11px] font-bold tracking-[0.08em] text-white ring-1 ring-slate-200">
                                        {{ applicantInitials(application) }}
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex min-w-0 items-start gap-2">
                                            <h4 class="min-w-0 flex-1 line-clamp-2 text-sm font-bold leading-5 text-slate-950">
                                                {{ application.applicant?.name || 'Applicant' }}
                                            </h4>
                                            <i
                                                v-if="application.applicant?.profile_verification_status === 'approved'"
                                                class="fa-solid fa-circle-check mt-1 text-xs text-emerald-600"
                                                title="Verified academic record"
                                                aria-label="Verified academic record"
                                            ></i>
                                        </div>
                                        <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">
                                            {{ applicantSecondaryLabel(application) }}
                                        </p>
                                    </div>
                                </div>

                                <div v-if="isFocusedOutcomeWorkspace" :class="['min-w-0', hasProgramContext && availableBulkAdvanceTargets.length ? 'pl-24 lg:pl-0' : 'pl-14 lg:pl-0']">
                                    <span :class="['inline-flex rounded-md px-2 py-1 text-[9px] font-bold uppercase', outcomeRecordStatusClass(application)]">{{ outcomeRecordStatus(application) }}</span>
                                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ outcomeRecordDetail(application) }}</p>
                                </div>
                                <div v-else :class="['flex min-w-0 flex-wrap items-center gap-2', hasProgramContext && availableBulkAdvanceTargets.length ? 'pl-24 lg:pl-0' : 'pl-14 lg:pl-0']">
                                    <span :class="['inline-flex rounded-md px-2 py-1 text-[9px] font-bold uppercase', statusClass(application.status)]">{{ applicationQueueLabel(application) }}</span>
                                    <span :class="['text-xs font-semibold', applicationPrioritySignal(application).tone]">{{ applicationPrioritySignal(application).label }}</span>
                                </div>

                                <div :class="[
                                    'portal-record-actions w-full shrink-0 lg:justify-end',
                                    showReviewerAssignment ? 'lg:w-72' : 'lg:w-auto',
                                    hasProgramContext && availableBulkAdvanceTargets.length ? 'pl-24 lg:pl-0' : 'pl-14 lg:pl-0',
                                ]">
                                    <label v-if="showReviewerAssignment && reviewersForApplication(application).length" class="min-w-0 flex-1 lg:w-44 lg:flex-none">
                                        <span class="sr-only">Assigned reviewer for {{ application.applicant?.name || 'applicant' }}</span>
                                        <select
                                            :value="application.assigned_reviewer?.id ?? ''"
                                            :disabled="assigningReviewerApplicationId === application.id"
                                            class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 outline-none transition focus:border-slate-500 disabled:cursor-wait disabled:opacity-60"
                                            @change="assignReviewer(application, $event)"
                                        >
                                            <option value="">Unassigned</option>
                                            <option v-for="reviewer in reviewersForApplication(application)" :key="reviewer.id" :value="reviewer.id">
                                                {{ reviewer.name }} - {{ reviewer.role_label }}
                                            </option>
                                        </select>
                                    </label>
                                    <a
                                        :href="applicationPrimaryActionUrl(application)"
                                        class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-slate-800"
                                    >
                                        {{ applicationActionLabel(application) }}
                                        <i class="fa-solid fa-arrow-right ml-2 text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </article>

                            <div v-if="visibleApplications.length" class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs font-semibold text-slate-500">Showing {{ visibleApplicationRange }}</p>
                                <div v-if="totalApplicationPages > 1" class="flex gap-2">
                                    <button
                                        type="button"
                                        :disabled="applicationPage === 1"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                                        @click="applicationPage -= 1"
                                    >
                                        Previous
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="applicationPage === totalApplicationPages"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                                        @click="applicationPage += 1"
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

            </div>
        </section>

    </main>

    <LocationMapModal
        :open="showScheduleMapModal"
        eyebrow="Activity location"
        :title="scheduleForm.venue || scheduleForm.title || 'Set the activity map pin'"
        :address="scheduleMapAddress"
        :latitude="scheduleForm.latitude"
        :longitude="scheduleForm.longitude"
        :marker-text="scheduleForm.venue || scheduleForm.title"
        :location-message="scheduleLocationError"
        picker
        @resolved="handleSchedulePinResolved"
        @picked="handleSchedulePinPicked"
        @error="scheduleLocationError = $event"
        @close="showScheduleMapModal = false"
    />
    <ConfirmationDialog v-bind="confirmation" @confirm="confirmConfirmation" @cancel="cancelConfirmation" />
</template>
