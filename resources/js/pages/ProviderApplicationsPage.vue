<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';

const appElement = document.getElementById('app');
const pageSearchParams = new URLSearchParams(window.location.search);
const initialScholarshipId = appElement?.dataset.scholarshipId ?? pageSearchParams.get('scholarship_id') ?? '';
const initialScholarshipTitle = appElement?.dataset.scholarshipTitle ?? '';
const requestedWorkspaceSection = pageSearchParams.get('workspace');
const requestedQueueFilter = pageSearchParams.get('filter');
const queueFilterValues = ['pending_review', 'document_issues', 'active_stages', 'decided', 'all'];
const pendingReviewStatuses = ['submitted', 'under_review'];
const activeStageStatuses = [
    'qualified',
    'shortlisted',
    'interview',
    'exam_qualified',
    'exam_scheduled',
    'exam_taken',
    'exam_passed',
    'distribution_scheduled',
];
const decidedStatuses = [
    'approved',
    'awarded',
    'not_awarded',
    'disbursed',
    'renewed',
    'rejected',
    'exam_failed',
    'interview_failed',
];
const isLoading = ref(true);
const errorMessage = ref('');
const applications = ref([]);
const reviewers = ref([]);
const assigningReviewerApplicationId = ref(null);
const canAssignReviewers = computed(() => reviewers.value.length >= 2);
const selectedScholarshipContext = ref(initialScholarshipId ? {
    id: Number(initialScholarshipId),
    title: initialScholarshipTitle,
} : null);
const selectedQueueFilter = ref(queueFilterValues.includes(requestedQueueFilter) ? requestedQueueFilter : 'pending_review');
const selectedQueueSort = ref('priority');
const applicationSearch = ref('');
const applicationPage = ref(1);
const selectedApplicationPreview = ref(null);
const applicationsPerPage = 10;
const activeWorkspaceSection = ref(['applications', 'schedule', 'results'].includes(requestedWorkspaceSection)
    ? requestedWorkspaceSection
    : 'applications');
const programEvents = ref([]);
const scheduleEditorType = ref('');
const scheduleSaving = ref(false);
const scheduleError = ref('');
const scheduleForm = ref(emptyScheduleForm());
const attendanceEventType = ref('');
const attendanceSearch = ref('');
const attendancePage = ref(1);
const selectedAttendanceIds = ref([]);
const bulkAttendanceStatus = ref('');
const bulkAttendanceNotes = ref('');
const attendanceSaving = ref(false);
const completingEventId = ref(null);
const attendanceError = ref('');
const selectedBulkApplicationIds = ref([]);
const bulkAdvanceTarget = ref('exam');
const bulkAdvancing = ref(false);
const bulkAdvanceError = ref('');
const showBulkActions = ref(false);
const attendancePerPage = 25;
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();
const minimumScheduleDateTime = new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);

const scheduleTypeCatalog = [
    { value: 'exam', label: 'Exam', icon: 'fa-solid fa-clipboard-question', help: 'Provider-managed exam schedule' },
    { value: 'interview', label: 'Interview', icon: 'fa-solid fa-comments', help: 'Shared interview instructions' },
    { value: 'distribution', label: 'Distribution', icon: 'fa-solid fa-hand-holding-dollar', help: 'Award release announcement' },
];
const scheduleModeOptions = [
    { value: 'onsite', label: 'On-site' },
    { value: 'online', label: 'Online' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'provider_managed', label: 'Provider managed' },
];
const scheduleWaitingStatuses = {
    exam: ['exam_qualified'],
    interview: ['interview'],
    distribution: ['approved', 'awarded'],
};

const selectedScholarshipId = computed(() => selectedScholarshipContext.value?.id || initialScholarshipId);
const hasProgramContext = computed(() => Boolean(selectedScholarshipId.value));
const configuredScheduleTypes = computed(() => {
    const configured = selectedScholarshipContext.value?.selection_stages ?? ['screening', 'distribution'];

    return scheduleTypeCatalog.filter((type) => configured.includes(type.value));
});
const availableBulkAdvanceTargets = computed(() => {
    const stages = selectedScholarshipContext.value?.selection_stages ?? ['screening', 'distribution'];
    const targets = [];

    if (stages.includes('exam')) {
        targets.push({ value: 'exam', label: 'Approve for exam' });
    }

    if (stages.includes('distribution')) {
        targets.push({ value: 'distribution', label: 'Approve for distribution' });
    }

    return targets;
});
const attendanceEvents = computed(() => configuredScheduleTypes.value
    .map((type) => programEvents.value.find((event) => event.type === type.value))
    .filter(Boolean));
const activeAttendanceEvent = computed(() => attendanceEvents.value.find((event) => event.type === attendanceEventType.value)
    ?? attendanceEvents.value[0]
    ?? null);
const attendanceParticipants = computed(() => {
    const event = activeAttendanceEvent.value;

    if (!event) {
        return [];
    }

    return applications.value
        .map((application) => ({
            application,
            schedule: applicationSchedule(application, event.type),
        }))
        .filter((record) => record.schedule);
});
const applicantsWaitingForActiveSchedule = computed(() => {
    const event = activeAttendanceEvent.value;

    if (!event) {
        return [];
    }

    return applications.value.filter((application) => (
        (scheduleWaitingStatuses[event.type] ?? []).includes(application.status)
        && !applicationSchedule(application, event.type)
    ));
});
const filteredAttendanceParticipants = computed(() => {
    const query = attendanceSearch.value.trim().toLowerCase();

    if (!query) {
        return attendanceParticipants.value;
    }

    return attendanceParticipants.value.filter(({ application }) => [
        application.applicant?.name,
        application.applicant?.email,
    ].filter(Boolean).join(' ').toLowerCase().includes(query));
});
const totalAttendancePages = computed(() => Math.max(1, Math.ceil(filteredAttendanceParticipants.value.length / attendancePerPage)));
const visibleAttendanceParticipants = computed(() => {
    const start = (attendancePage.value - 1) * attendancePerPage;

    return filteredAttendanceParticipants.value.slice(start, start + attendancePerPage);
});
const attendanceRange = computed(() => {
    if (filteredAttendanceParticipants.value.length === 0) {
        return '0 applicants';
    }

    const start = (attendancePage.value - 1) * attendancePerPage + 1;
    const end = Math.min(attendancePage.value * attendancePerPage, filteredAttendanceParticipants.value.length);

    return `${start}-${end} of ${filteredAttendanceParticipants.value.length}`;
});
const selectableVisibleAttendanceParticipants = computed(() => visibleAttendanceParticipants.value.filter(({ schedule }) => (
    schedule.status === 'scheduled' && (schedule.attendance_status ?? 'pending') === 'pending'
)));
const allVisibleAttendanceSelected = computed(() => selectableVisibleAttendanceParticipants.value.length > 0
    && selectableVisibleAttendanceParticipants.value.every(({ application }) => selectedAttendanceIds.value.includes(application.id)));
const attendanceSummary = computed(() => attendanceParticipants.value.reduce((summary, { schedule }) => {
    const status = schedule.attendance_status ?? 'pending';

    summary[status] = (summary[status] ?? 0) + 1;

    return summary;
}, {}));
const pendingReviewCount = computed(() => applications.value.filter((application) => (
    ['submitted', 'under_review'].includes(application.status ?? 'submitted')
)).length);
const waitingScheduleTypes = computed(() => {
    return configuredScheduleTypes.value.filter((type) => (
        scheduleEvent(type.value)?.status !== 'scheduled'
        && applications.value.some((application) => (
            (scheduleWaitingStatuses[type.value] ?? []).includes(application.status)
            && !applicationSchedule(application, type.value)
        ))
    ));
});
const dueProgramEvents = computed(() => attendanceEvents.value.filter((event) => (
    event.status === 'scheduled' && canCompleteEvent(event)
)));
const pendingProgramResults = computed(() => attendanceEvents.value.reduce((count, event) => {
    if (event.status !== 'completed') {
        return count;
    }

    return count + applications.value.filter((application) => {
        const schedule = applicationSchedule(application, event.type);

        return schedule && (schedule.attendance_status ?? 'pending') === 'pending';
    }).length;
}, 0));
const workspaceTabs = computed(() => [
    {
        key: 'applications',
        label: 'Review',
        meta: pendingReviewCount.value > 0 ? `${pendingReviewCount.value} to review` : `${applications.value.length} total`,
        attention: pendingReviewCount.value > 0,
    },
    {
        key: 'schedule',
        label: 'Schedule',
        meta: `${programEvents.value.length} set`,
        attention: waitingScheduleTypes.value.length > 0,
    },
    {
        key: 'results',
        label: 'Results',
        meta: pendingProgramResults.value > 0 ? `${pendingProgramResults.value} pending` : `${attendanceEvents.value.length} activities`,
        attention: dueProgramEvents.value.length > 0 || pendingProgramResults.value > 0,
    },
]);
const workspaceTasks = computed(() => {
    const tasks = [];

    if (pendingReviewCount.value > 0) {
        tasks.push({
            section: 'applications',
            title: `${pendingReviewCount.value} application${pendingReviewCount.value === 1 ? '' : 's'} waiting for review`,
            description: 'Check eligibility, files, and applicant details before deciding.',
            action: 'Review applicants',
        });
    }

    if (waitingScheduleTypes.value.length > 0) {
        const labels = waitingScheduleTypes.value.map((type) => type.label).join(' and ');
        const isNextSchedule = waitingScheduleTypes.value.some((type) => scheduleEvent(type.value)?.status === 'completed');
        const scheduleLabel = `${labels.toLowerCase()} schedule${waitingScheduleTypes.value.length === 1 ? '' : 's'}`;
        tasks.push({
            section: 'schedule',
            title: `Publish ${isNextSchedule ? (waitingScheduleTypes.value.length === 1 ? 'a new ' : 'new ') : 'the '}${scheduleLabel}`,
            description: isNextSchedule
                ? 'New applicants reached this stage after the earlier activity closed.'
                : 'Applicants have reached this stage and are waiting for the shared details.',
            action: 'Set schedule',
        });
    }

    if (dueProgramEvents.value.length > 0) {
        tasks.push({
            section: 'results',
            title: `${dueProgramEvents.value.length} activit${dueProgramEvents.value.length === 1 ? 'y is' : 'ies are'} ready to close`,
            description: 'Mark the shared activity complete before recording participant results.',
            action: 'Update results',
        });
    } else if (pendingProgramResults.value > 0) {
        tasks.push({
            section: 'results',
            title: `${pendingProgramResults.value} participant result${pendingProgramResults.value === 1 ? '' : 's'} still pending`,
            description: 'Update several applicants together instead of opening each application.',
            action: 'Update results',
        });
    }

    return tasks;
});
const primaryWorkspaceTask = computed(() => workspaceTasks.value[0] ?? null);
const remainingWorkspaceTaskCount = computed(() => Math.max(workspaceTasks.value.length - 1, 0));
const bulkAttendanceOptions = computed(() => activeAttendanceEvent.value?.type === 'distribution'
    ? [
        { value: 'received', label: 'Received' },
        { value: 'not_required', label: 'Not required' },
    ]
    : [
        { value: 'attended', label: 'Attended' },
        { value: 'absent', label: 'Absent' },
        { value: 'excused', label: 'Excused' },
    ]);
const exportApplicationsUrl = computed(() => {
    if (!hasProgramContext.value) {
        return '/provider/export/applications';
    }

    return `/provider/export/applications?scholarship_id=${encodeURIComponent(selectedScholarshipId.value)}`;
});
const pageKicker = computed(() => (hasProgramContext.value ? 'Program Applicants' : 'Applicants'));
const pageTitle = computed(() => (hasProgramContext.value
    ? selectedScholarshipContext.value?.title || 'Scholarship program'
    : 'Review applicants'));
const pageDescription = computed(() => (hasProgramContext.value
    ? 'Review applicants first, then manage shared schedules and participant results when needed.'
    : 'Find applicants needing attention and open a guided review of their profile, eligibility, and files.'));
const reviewFilterOptions = computed(() => [
    {
        value: 'pending_review',
        label: 'Needs review',
        count: applications.value.filter((application) => pendingReviewStatuses.includes(application.status ?? 'submitted')).length,
    },
    {
        value: 'document_issues',
        label: 'Document issues',
        count: applications.value.filter((application) => documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100).length,
    },
    {
        value: 'active_stages',
        label: 'Active stages',
        count: applications.value.filter((application) => activeStageStatuses.includes(application.status)).length,
    },
    {
        value: 'decided',
        label: 'Decisions',
        count: applications.value.filter((application) => decidedStatuses.includes(application.status)).length,
    },
    { value: 'all', label: 'All applicants', count: applications.value.length },
]);
const emptyQueueMessage = computed(() => ({
    pending_review: 'No applicants currently need an initial review.',
    document_issues: 'No applicants currently have missing or unresolved document issues.',
    active_stages: 'No applicants are currently in an exam, interview, or distribution stage.',
    decided: 'No applicant decisions have been recorded yet.',
    all: 'No applicants match this search.',
}[selectedQueueFilter.value]));
const rankedApplications = computed(() => {
    const query = applicationSearch.value.trim().toLowerCase();
    const filteredApplications = applications.value.filter((application) => {
        const matchesSearch = !query || [
            application.applicant?.name,
            application.applicant?.email,
            application.scholarship?.title,
        ].filter(Boolean).join(' ').toLowerCase().includes(query);

        if (!matchesSearch) {
            return false;
        }

        if (selectedQueueFilter.value === 'pending_review') {
            return pendingReviewStatuses.includes(application.status ?? 'submitted');
        }

        if (selectedQueueFilter.value === 'document_issues') {
            return documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100;
        }

        if (selectedQueueFilter.value === 'active_stages') {
            return activeStageStatuses.includes(application.status);
        }

        if (selectedQueueFilter.value === 'decided') {
            return decidedStatuses.includes(application.status);
        }

        return true;
    });

    return [...filteredApplications].sort((first, second) => {
        if (selectedQueueSort.value === 'dss') {
            return Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
        }

        if (selectedQueueSort.value === 'documents') {
            return documentIssueCount(second) - documentIssueCount(first);
        }

        if (selectedQueueSort.value === 'oldest') {
            return Number(second.waiting_days ?? 0) - Number(first.waiting_days ?? 0);
        }

        return reviewPriorityScore(second) - reviewPriorityScore(first) || Number(second.dss_score ?? 0) - Number(first.dss_score ?? 0);
    });
});
const totalApplicationPages = computed(() => Math.max(1, Math.ceil(rankedApplications.value.length / applicationsPerPage)));
const visibleApplications = computed(() => {
    const start = (applicationPage.value - 1) * applicationsPerPage;

    return rankedApplications.value.slice(start, start + applicationsPerPage);
});
const visibleApplicationRange = computed(() => {
    if (rankedApplications.value.length === 0) {
        return '0 applications';
    }

    const start = (applicationPage.value - 1) * applicationsPerPage + 1;
    const end = Math.min(applicationPage.value * applicationsPerPage, rankedApplications.value.length);

    return `${start}-${end} of ${rankedApplications.value.length}`;
});
const bulkEligibleVisibleApplications = computed(() => visibleApplications.value.filter((application) => (
    canBulkAdvance(application)
)));
const allVisibleBulkSelected = computed(() => bulkEligibleVisibleApplications.value.length > 0
    && bulkEligibleVisibleApplications.value.every((application) => selectedBulkApplicationIds.value.includes(application.id)));
const customStatusLabels = {
    exam_qualified: 'Qualified for exam',
    exam_scheduled: 'Exam scheduled',
    exam_taken: 'Exam taken',
    exam_passed: 'Passed exam',
    exam_failed: 'Failed exam',
    interview_failed: 'Failed interview',
    distribution_scheduled: 'Distribution scheduled',
    disbursed: 'Distributed',
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

    const isDistribution = bulkAdvanceTarget.value === 'distribution';
    const confirmed = await requestConfirmation({
        title: isDistribution ? 'Approve selected applicants for distribution?' : 'Approve selected applicants for the exam?',
        message: isDistribution
            ? `Only the ${selectedBulkApplicationIds.value.length} selected applicants already marked Approved will advance for distribution.`
            : `${selectedBulkApplicationIds.value.length} selected applicants will advance to the configured exam stage.`,
        confirmLabel: isDistribution ? 'Approve for distribution' : 'Approve for exam',
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

    if (['rejected', 'not_awarded', 'exam_failed', 'interview_failed'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function recommendationClass(recommendation) {
    if (['highly_recommended', 'recommended'].includes(recommendation)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['low_priority', 'not_recommended'].includes(recommendation)) {
        return 'bg-rose-100 text-rose-800';
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

function programStatusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function documentIssueCount(application) {
    return (application.documents ?? []).filter((document) => ['pending', 'needs_replacement', 'rejected'].includes(document.status ?? 'pending')).length;
}

function showWaitingTime(application) {
    return Number(application.waiting_days ?? 0) > 0
        && !['rejected', 'not_awarded', 'exam_failed', 'interview_failed', 'disbursed', 'renewed'].includes(application.status);
}

function reviewPriorityScore(application) {
    const status = application.status ?? 'submitted';
    const readiness = Number(application.document_readiness?.percent ?? 0);
    const dssScore = Number(application.dss_score ?? 0);
    const eligibilityScore = Number(application.eligibility_score ?? 0);
    const issues = documentIssueCount(application);
    let score = 0;

    if (status === 'submitted') {
        score += 24;
    }

    if (status === 'under_review') {
        score += 16;
    }

    if (application.documents_changed_since_review) {
        score += 20;
    }

    if (['exam_qualified', 'exam_scheduled', 'exam_taken'].includes(status)) {
        score += 14;
    }

    if (status === 'exam_passed') {
        score += 10;
    }

    if (issues > 0) {
        score += Math.min(35, issues * 12);
    }

    if (readiness < 100) {
        score += readiness === 0 ? 22 : 14;
    }

    if (dssScore >= 80 || eligibilityScore >= 80) {
        score += 12;
    }

    if (application.dss_recommendation === 'needs_review') {
        score += 20;
    }

    if (application.dss_recommendation === 'not_recommended') {
        score += 10;
    }

    if (['approved', 'awarded'].includes(status) && !application.distribution_scheduled_for) {
        score += 18;
    }

    if (!application.review_notes && ['submitted', 'under_review'].includes(status)) {
        score += 5;
    }

    if (['not_awarded', 'disbursed', 'renewed', 'rejected', 'exam_failed', 'interview_failed'].includes(status)) {
        score -= 25;
    }

    return Math.max(0, score);
}

function openApplicationPreview(application) {
    selectedApplicationPreview.value = application;
}

function closeApplicationPreview() {
    selectedApplicationPreview.value = null;
}

function selectWorkspaceSection(section) {
    activeWorkspaceSection.value = section;
    scheduleError.value = '';
    attendanceError.value = '';

    const url = new URL(window.location.href);
    url.searchParams.set('workspace', section);
    window.history.replaceState({}, '', url);
}

function toggleBulkActions() {
    showBulkActions.value = !showBulkActions.value;

    if (!showBulkActions.value) {
        selectedBulkApplicationIds.value = [];
        bulkAdvanceError.value = '';
    }
}

function openNextSchedule(type) {
    selectWorkspaceSection('schedule');
    openScheduleEditor(type);
}

function emptyScheduleForm(type = '') {
    return {
        type,
        title: '',
        scheduledAt: '',
        mode: 'onsite',
        venue: '',
        locationAddress: '',
        latitude: '',
        longitude: '',
        onlineUrl: '',
        instructions: '',
    };
}

function scheduleTypeLabel(type) {
    return scheduleTypeCatalog.find((option) => option.value === type)?.label ?? type;
}

function scheduleEvent(type) {
    return programEvents.value.find((event) => event.type === type) ?? null;
}

function applicationSchedule(application, type) {
    return (application.schedules ?? []).find((schedule) => schedule.type === type) ?? null;
}

function eventStatusClass(status) {
    return status === 'completed'
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-800';
}

function attendanceStatusClass(status) {
    if (['attended', 'received'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'absent') {
        return 'bg-rose-100 text-rose-800';
    }

    if (['excused', 'not_required'].includes(status)) {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function canCompleteEvent(event) {
    return event?.scheduled_at && new Date(event.scheduled_at).getTime() <= Date.now();
}

function toggleVisibleAttendance() {
    const visibleIds = selectableVisibleAttendanceParticipants.value.map(({ application }) => application.id);

    if (allVisibleAttendanceSelected.value) {
        selectedAttendanceIds.value = selectedAttendanceIds.value.filter((id) => !visibleIds.includes(id));
        return;
    }

    selectedAttendanceIds.value = [...new Set([...selectedAttendanceIds.value, ...visibleIds])];
}

async function completeProgramEvent(event) {
    completingEventId.value = event.id;
    attendanceError.value = '';

    try {
        await window.axios.patch(`/provider/scholarships/${selectedScholarshipId.value}/events/${event.id}/complete`);
        await loadProviderData(false);
    } catch (error) {
        attendanceError.value = error.response?.data?.errors?.event?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to complete this event.';
    } finally {
        completingEventId.value = null;
    }
}

async function applyBulkAttendance() {
    if (!bulkAttendanceStatus.value || selectedAttendanceIds.value.length === 0) {
        attendanceError.value = 'Select applicants and choose an attendance result.';
        return;
    }

    attendanceSaving.value = true;
    attendanceError.value = '';

    try {
        await window.axios.patch(
            `/provider/scholarships/${selectedScholarshipId.value}/events/${activeAttendanceEvent.value.id}/attendance`,
            {
                application_ids: selectedAttendanceIds.value,
                attendance_status: bulkAttendanceStatus.value,
                attendance_notes: bulkAttendanceNotes.value || null,
            },
        );
        selectedAttendanceIds.value = [];
        bulkAttendanceStatus.value = '';
        bulkAttendanceNotes.value = '';
        await loadProviderData(false);
    } catch (error) {
        const validationErrors = error.response?.data?.errors ?? {};

        attendanceError.value = Object.values(validationErrors).flat()[0]
            ?? error.response?.data?.message
            ?? 'Unable to update attendance.';
    } finally {
        attendanceSaving.value = false;
    }
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
            screening: 'Keep your profile and submitted requirements complete while the provider reviews your application.',
            exam: 'Review the provider exam instructions and arrive or sign in at least 15 minutes before the scheduled time.',
            interview: 'Bring a valid school ID and be ready to discuss your application and scholarship goals.',
            distribution: 'Bring a valid school ID and any release documents required by the provider.',
        }[type] ?? '',
    };
}

function openScheduleEditor(type) {
    const existing = scheduleEvent(type);
    const defaults = defaultScheduleDetails(type);

    scheduleForm.value = existing
        ? {
            type: existing.type,
            title: existing.title ?? '',
            scheduledAt: existing.scheduled_at ?? '',
            mode: existing.mode ?? 'onsite',
            venue: existing.venue ?? '',
            locationAddress: existing.location_address ?? '',
            latitude: existing.latitude ?? '',
            longitude: existing.longitude ?? '',
            onlineUrl: existing.online_url ?? '',
            instructions: existing.instructions ?? '',
        }
        : { ...emptyScheduleForm(type), ...defaults, type };
    scheduleEditorType.value = type;
    scheduleError.value = '';
}

function closeScheduleEditor() {
    scheduleEditorType.value = '';
    scheduleForm.value = emptyScheduleForm();
}

function handleSchedulePinPicked(location) {
    scheduleForm.value.latitude = location.latitude;
    scheduleForm.value.longitude = location.longitude;

    if (location.displayName) {
        scheduleForm.value.locationAddress = location.displayName;
    }
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
            location_address: scheduleForm.value.locationAddress || null,
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

async function loadProviderData(showLoading = true) {
    isLoading.value = showLoading;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/applications/data', {
            params: hasProgramContext.value ? { scholarship_id: selectedScholarshipId.value } : {},
        });

        applications.value = response.data.applications;
        reviewers.value = Array.isArray(response.data.reviewers) ? response.data.reviewers : [];
        selectedScholarshipContext.value = response.data.selected_scholarship ?? selectedScholarshipContext.value;
        programEvents.value = response.data.program_events ?? [];

        if (!availableBulkAdvanceTargets.value.some((target) => target.value === bulkAdvanceTarget.value)) {
            bulkAdvanceTarget.value = availableBulkAdvanceTargets.value[0]?.value ?? 'distribution';
        }

        if (!programEvents.value.some((event) => event.type === attendanceEventType.value)) {
            attendanceEventType.value = programEvents.value[0]?.type ?? '';
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider applications.';
    } finally {
        isLoading.value = false;
    }
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

watch([selectedQueueFilter, selectedQueueSort, applicationSearch], () => {
    applicationPage.value = 1;
});

watch(selectedQueueFilter, (filter) => {
    const url = new URL(window.location.href);

    url.searchParams.set('filter', filter);
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

watch([attendanceEventType, attendanceSearch], () => {
    attendancePage.value = 1;
    selectedAttendanceIds.value = [];
    bulkAttendanceStatus.value = '';
    attendanceError.value = '';
});

watch(totalAttendancePages, (totalPages) => {
    if (attendancePage.value > totalPages) {
        attendancePage.value = totalPages;
    }
});

watch(selectedApplicationPreview, (application) => {
    document.body.classList.toggle('overflow-hidden', Boolean(application));
});

onUnmounted(() => {
    document.body.classList.remove('overflow-hidden');
});

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                {{ pageKicker }}
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                {{ pageTitle }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                {{ pageDescription }}
                            </p>
                        </div>
                        <div v-if="hasProgramContext" class="flex flex-wrap gap-2">
                            <a
                                :href="`/provider/programs/${selectedScholarshipId}/edit`"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Edit program
                            </a>
                            <a
                                href="/provider/programs"
                                class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Back to programs
                            </a>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading applicants...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 flex flex-col gap-6">
                    <section v-if="hasProgramContext" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 p-4 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 items-center gap-3">
                                <img
                                    :src="selectedScholarshipContext?.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="selectedScholarshipContext?.title || 'Scholarship program'"
                                    class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-sm font-bold text-slate-950">{{ selectedScholarshipContext?.title }}</p>
                                        <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', programStatusClass(selectedScholarshipContext?.status)]">
                                            {{ statusLabel(selectedScholarshipContext?.status) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ selectedScholarshipContext?.category || 'Scholarship program' }}
                                        <span class="mx-1 text-slate-300">/</span>
                                        Deadline {{ selectedScholarshipContext?.deadline || 'not set' }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-slate-500">
                                <span><strong class="text-sm text-slate-950">{{ applications.length }}</strong> applicants</span>
                                <span><strong class="text-sm text-slate-950">{{ selectedScholarshipContext?.awarded_slots_count ?? 0 }}</strong> selected</span>
                                <span v-if="Number(selectedScholarshipContext?.slots_available ?? 0) > 0">
                                    <strong class="text-sm text-slate-950">{{ selectedScholarshipContext.slots_available }}</strong> slots
                                </span>
                            </div>
                        </div>

                        <nav class="flex gap-1 overflow-x-auto border-t border-slate-200 bg-slate-50 p-2" aria-label="Program workspace sections">
                            <button
                                v-for="tab in workspaceTabs"
                                :key="tab.key"
                                type="button"
                                :aria-current="activeWorkspaceSection === tab.key ? 'page' : undefined"
                                :class="[
                                    'inline-flex min-w-fit flex-1 items-center justify-center gap-2 rounded-md px-3 py-2.5 text-sm font-bold transition',
                                    activeWorkspaceSection === tab.key
                                        ? 'bg-slate-950 text-white shadow-sm'
                                        : 'text-slate-600 hover:bg-white hover:text-slate-950',
                                ]"
                                @click="selectWorkspaceSection(tab.key)"
                            >
                                <span>{{ tab.label }}</span>
                                <span :class="activeWorkspaceSection === tab.key ? 'text-slate-300' : 'text-slate-400'" class="text-xs font-semibold">
                                    {{ tab.meta }}
                                </span>
                                <span v-if="tab.attention" class="h-2 w-2 shrink-0 rounded-full bg-amber-400" aria-label="Needs attention"></span>
                            </button>
                        </nav>
                    </section>

                    <section
                        v-if="hasProgramContext && primaryWorkspaceTask"
                        class="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-200 text-amber-900">
                                <i class="fa-solid fa-bell text-sm" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-800">Next task</p>
                                <p class="mt-1 text-sm font-bold text-slate-950">{{ primaryWorkspaceTask.title }}</p>
                                <p class="mt-0.5 text-xs leading-5 text-slate-600">
                                    {{ primaryWorkspaceTask.description }}
                                    <span v-if="remainingWorkspaceTaskCount" class="font-semibold"> {{ remainingWorkspaceTaskCount }} more task{{ remainingWorkspaceTaskCount === 1 ? '' : 's' }} are marked in the tabs above.</span>
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                            @click="selectWorkspaceSection(primaryWorkspaceTask.section)"
                        >
                            {{ primaryWorkspaceTask.action }}
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </button>
                    </section>

                    <section v-if="hasProgramContext && activeWorkspaceSection === 'schedule'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Program Schedule</p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">Schedule applicant activities</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
                                    Add dates for exams, interviews, or distribution. Screening stays in application review and does not need a schedule.
                                </p>
                            </div>
                            <a :href="`/provider/programs/${selectedScholarshipId}/edit`" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                Edit selection plan
                            </a>
                        </div>

                        <p v-if="scheduleError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ scheduleError }}</p>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <button
                                v-for="type in configuredScheduleTypes"
                                :key="type.value"
                                type="button"
                                :class="[
                                    'flex min-h-32 flex-col rounded-md border p-3 text-left transition',
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

                            <div v-if="['onsite', 'hybrid'].includes(scheduleForm.mode)" class="mt-4 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Event venue</label>
                                    <input v-model="scheduleForm.venue" type="text" maxlength="500" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                </div>
                                <div>
                                    <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Event address</label>
                                    <input v-model="scheduleForm.locationAddress" type="text" maxlength="1000" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                    <p class="mt-2 text-xs leading-5 text-slate-500">This can differ from the provider office or program address.</p>
                                </div>
                                <div class="overflow-hidden rounded-md md:col-span-2">
                                    <LeafletMapPreview
                                        :address="scheduleForm.locationAddress"
                                        :latitude="scheduleForm.latitude"
                                        :longitude="scheduleForm.longitude"
                                        :title="scheduleForm.venue || 'Program activity location'"
                                        :marker-text="scheduleForm.venue || scheduleForm.title"
                                        height="14rem"
                                        picker
                                        auto-geocode
                                        @picked="handleSchedulePinPicked"
                                    />
                                </div>
                            </div>

                            <div v-if="['online', 'hybrid'].includes(scheduleForm.mode)" class="mt-4">
                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                    Online link
                                    <span class="ml-1 text-[10px] text-slate-400">{{ scheduleForm.type === 'distribution' ? 'Optional' : 'Required' }}</span>
                                </label>
                                <input v-model="scheduleForm.onlineUrl" type="url" maxlength="2000" placeholder="https://..." :required="scheduleForm.type !== 'distribution'" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                <p v-if="scheduleForm.type === 'distribution'" class="mt-2 text-xs leading-5 text-slate-500">Use this only for a release portal or online briefing. Put transfer or release steps in the instructions.</p>
                            </div>

                            <div class="mt-4">
                                <label class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Applicant instructions</label>
                                <textarea v-model="scheduleForm.instructions" rows="3" maxlength="3000" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600"></textarea>
                            </div>

                            <div class="mt-4 flex justify-end">
                                <button type="submit" :disabled="scheduleSaving" class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                                    {{ scheduleSaving ? 'Publishing...' : 'Publish schedule' }}
                                </button>
                            </div>
                        </form>
                    </section>

                    <section v-if="hasProgramContext && activeWorkspaceSection === 'results'" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Activity results</p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">Complete the activity, then update participants</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
                                    After the scheduled activity happens, mark it complete once. Participant updates will then become available below.
                                </p>
                            </div>
                            <span v-if="activeAttendanceEvent" class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                {{ attendanceParticipants.length }} participants
                            </span>
                        </div>

                        <div v-if="attendanceEvents.length === 0" class="p-5">
                            <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-5">
                                <p class="text-sm font-bold text-slate-900">No activity is scheduled yet</p>
                                <p class="mt-1 text-sm text-slate-500">Publish an exam, interview, or distribution schedule above to manage its participants here.</p>
                            </div>
                        </div>

                        <template v-else>
                            <div class="flex gap-2 overflow-x-auto border-b border-slate-200 px-5 py-3">
                                <button
                                    v-for="event in attendanceEvents"
                                    :key="event.id"
                                    type="button"
                                    :class="[
                                        'shrink-0 rounded-md border px-3 py-2 text-sm font-bold transition',
                                        activeAttendanceEvent?.id === event.id
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="attendanceEventType = event.type"
                                >
                                    {{ scheduleTypeLabel(event.type) }}
                                </button>
                            </div>

                            <div v-if="activeAttendanceEvent" class="p-5">
                                <div class="flex flex-col gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="font-bold text-slate-950">{{ activeAttendanceEvent.title }}</h4>
                                            <span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', eventStatusClass(activeAttendanceEvent.status)]">
                                                {{ statusLabel(activeAttendanceEvent.status) }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-sm text-slate-600">{{ activeAttendanceEvent.scheduled_label }}</p>
                                        <p v-if="activeAttendanceEvent.status !== 'completed' && !canCompleteEvent(activeAttendanceEvent)" class="mt-1 text-xs font-semibold text-amber-700">
                                            This activity can be completed after its scheduled time.
                                        </p>
                                    </div>
                                    <button
                                        v-if="activeAttendanceEvent.status !== 'completed'"
                                        type="button"
                                        :disabled="completingEventId === activeAttendanceEvent.id || !canCompleteEvent(activeAttendanceEvent)"
                                        class="shrink-0 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                        @click="completeProgramEvent(activeAttendanceEvent)"
                                    >
                                        {{ completingEventId === activeAttendanceEvent.id ? 'Updating...' : 'Mark activity complete' }}
                                    </button>
                                </div>

                                <p v-if="attendanceError" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">
                                    {{ attendanceError }}
                                </p>

                                <div
                                    v-if="applicantsWaitingForActiveSchedule.length"
                                    class="mt-4 flex flex-col gap-3 rounded-md border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <p class="text-sm font-bold text-amber-950">
                                            {{ applicantsWaitingForActiveSchedule.length }} applicant{{ applicantsWaitingForActiveSchedule.length === 1 ? '' : 's' }} waiting for a new {{ scheduleTypeLabel(activeAttendanceEvent.type).toLowerCase() }} schedule
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-amber-800">
                                            {{ activeAttendanceEvent.status === 'completed'
                                                ? 'They reached this stage after the current activity closed, so they are not part of the participant table below.'
                                                : 'They are not assigned to the current activity yet. Review and republish the shared schedule before tracking participation.' }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-md bg-slate-950 px-3 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                        @click="openNextSchedule(activeAttendanceEvent.type)"
                                    >
                                        Set new schedule
                                    </button>
                                </div>

                                <div v-if="activeAttendanceEvent.status === 'completed'" class="mt-5">
                                    <div class="flex flex-wrap gap-2 text-xs font-bold">
                                        <span class="rounded-md bg-amber-100 px-2.5 py-1 text-amber-800">{{ attendanceSummary.pending ?? 0 }} pending</span>
                                        <template v-if="activeAttendanceEvent.type === 'distribution'">
                                            <span class="rounded-md bg-emerald-100 px-2.5 py-1 text-emerald-800">{{ attendanceSummary.received ?? 0 }} received</span>
                                            <span class="rounded-md bg-slate-200 px-2.5 py-1 text-slate-700">{{ attendanceSummary.not_required ?? 0 }} not required</span>
                                        </template>
                                        <template v-else>
                                            <span class="rounded-md bg-emerald-100 px-2.5 py-1 text-emerald-800">{{ attendanceSummary.attended ?? 0 }} attended</span>
                                            <span class="rounded-md bg-rose-100 px-2.5 py-1 text-rose-800">{{ attendanceSummary.absent ?? 0 }} absent</span>
                                            <span class="rounded-md bg-slate-200 px-2.5 py-1 text-slate-700">{{ attendanceSummary.excused ?? 0 }} excused</span>
                                        </template>
                                    </div>

                                    <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(13rem,1fr)_13rem_minmax(13rem,1fr)_auto]">
                                        <label class="relative block">
                                            <span class="sr-only">Search participants</span>
                                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                            <input v-model="attendanceSearch" type="search" placeholder="Search applicant" class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm outline-none focus:border-slate-600">
                                        </label>
                                        <select v-model="bulkAttendanceStatus" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                            <option value="">{{ activeAttendanceEvent.type === 'distribution' ? 'Choose release result' : 'Choose attendance' }}</option>
                                            <option v-for="option in bulkAttendanceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                        </select>
                                        <input v-model="bulkAttendanceNotes" type="text" maxlength="1500" placeholder="Optional note for selected applicants" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
                                        <button
                                            type="button"
                                            :disabled="attendanceSaving || selectedAttendanceIds.length === 0 || !bulkAttendanceStatus"
                                            class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                            @click="applyBulkAttendance"
                                        >
                                            {{ attendanceSaving ? 'Applying...' : `Apply to ${selectedAttendanceIds.length}` }}
                                        </button>
                                    </div>

                                    <div v-if="attendanceParticipants.length === 0" class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-500">
                                        No applicants are assigned to this activity yet.
                                    </div>

                                    <div v-else-if="filteredAttendanceParticipants.length === 0" class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-500">
                                        No applicants match this search.
                                    </div>

                                    <div v-else class="mt-4 overflow-hidden rounded-lg border border-slate-200">
                                        <div class="hidden grid-cols-[2.5rem_minmax(0,1fr)_9rem] items-center gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2.5 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500 sm:grid">
                                            <button type="button" class="text-left" @click="toggleVisibleAttendance">
                                                <i :class="allVisibleAttendanceSelected ? 'fa-solid fa-square-check text-slate-900' : 'fa-regular fa-square text-slate-400'" aria-hidden="true"></i>
                                                <span class="sr-only">Select displayed applicants</span>
                                            </button>
                                            <span>Applicant</span>
                                            <span>Status</span>
                                        </div>

                                        <label
                                            v-for="record in visibleAttendanceParticipants"
                                            :key="record.application.id"
                                            :class="[
                                                'grid grid-cols-[2rem_minmax(0,1fr)_auto] items-center gap-3 border-b border-slate-200 px-4 py-3 last:border-b-0',
                                                record.schedule.status === 'scheduled' && (record.schedule.attendance_status ?? 'pending') === 'pending'
                                                    ? 'cursor-pointer hover:bg-slate-50'
                                                    : 'bg-slate-50/70',
                                            ]"
                                        >
                                            <input
                                                v-model="selectedAttendanceIds"
                                                type="checkbox"
                                                :value="record.application.id"
                                                :disabled="record.schedule.status !== 'scheduled' || (record.schedule.attendance_status ?? 'pending') !== 'pending'"
                                                class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-500 disabled:cursor-not-allowed disabled:opacity-40"
                                            >
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm font-bold text-slate-900">{{ record.application.applicant?.name || 'Applicant' }}</span>
                                                <span class="block truncate text-xs text-slate-500">{{ record.application.applicant?.email }}</span>
                                            </span>
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', attendanceStatusClass(record.schedule.attendance_status)]">
                                                {{ statusLabel(record.schedule.attendance_status || 'pending') }}
                                            </span>
                                        </label>
                                    </div>

                                    <div v-if="filteredAttendanceParticipants.length > attendancePerPage" class="mt-4 flex flex-wrap items-center justify-between gap-3">
                                        <p class="text-xs font-semibold text-slate-500">{{ attendanceRange }}</p>
                                        <div class="flex gap-2">
                                            <button type="button" :disabled="attendancePage === 1" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-40" @click="attendancePage -= 1">Previous</button>
                                            <button type="button" :disabled="attendancePage === totalAttendancePages" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 disabled:opacity-40" @click="attendancePage += 1">Next</button>
                                        </div>
                                    </div>
                                </div>

                                <div v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-500">
                                    Participant updates become available after the shared activity is marked complete.
                                </div>
                            </div>
                        </template>
                    </section>

                    <section v-if="!hasProgramContext || activeWorkspaceSection === 'applications'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div>
                            <h3 class="text-xl font-bold text-slate-950">Review queue</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">
                                {{ hasProgramContext
                                    ? 'Open an applicant to review the profile, eligibility, documents, and decision in order.'
                                    : 'Filter the list by the work you need to complete, then open an applicant review.' }}
                            </p>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button
                                    v-for="filter in reviewFilterOptions"
                                    :key="filter.value"
                                    type="button"
                                    :class="[
                                        'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                        selectedQueueFilter === filter.value
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="selectedQueueFilter = filter.value"
                                >
                                    {{ filter.label }} ({{ filter.count }})
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <label class="relative w-full lg:max-w-md">
                                <span class="sr-only">Search applicants</span>
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                                <input
                                    v-model="applicationSearch"
                                    type="search"
                                    placeholder="Search applicant, email, or program"
                                    class="w-full rounded-md border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none transition focus:border-slate-500"
                                >
                            </label>
                            <div class="flex flex-col gap-2 sm:flex-row">
                                <label>
                                    <span class="sr-only">Sort applications</span>
                                    <select
                                        v-model="selectedQueueSort"
                                        class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none transition focus:border-slate-500 sm:w-44"
                                    >
                                        <option value="priority">Priority first</option>
                                        <option value="oldest">Oldest first</option>
                                        <option value="dss">Highest match</option>
                                        <option value="documents">Document issues</option>
                                    </select>
                                </label>
                                <button
                                    v-if="hasProgramContext && availableBulkAdvanceTargets.length"
                                    type="button"
                                    :class="[
                                        'rounded-md border px-4 py-2.5 text-center text-sm font-bold transition',
                                        showBulkActions
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
                                    ]"
                                    @click="toggleBulkActions"
                                >
                                    <i class="fa-solid fa-check-double mr-1.5" aria-hidden="true"></i>
                                    Bulk actions
                                </button>
                                <a
                                    :href="exportApplicationsUrl"
                                    class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Export applicants
                                </a>
                            </div>
                        </div>

                        <div v-if="hasProgramContext && availableBulkAdvanceTargets.length && showBulkActions" class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                                    <span class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Bulk approval</span>
                                    <label class="shrink-0">
                                        <span class="sr-only">Bulk approval action</span>
                                        <select v-model="bulkAdvanceTarget" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-800 outline-none focus:border-slate-500 sm:w-52">
                                            <option v-for="target in availableBulkAdvanceTargets" :key="target.value" :value="target.value">{{ target.label }}</option>
                                        </select>
                                    </label>
                                    <p class="text-xs leading-5 text-slate-500">
                                        {{ bulkAdvanceTarget === 'distribution'
                                            ? 'Only applicants already marked Approved are selectable.'
                                            : 'Only applicants with accepted required files who can enter the exam are selectable.' }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="bulkEligibleVisibleApplications.length === 0" @click="toggleVisibleBulkSelection">
                                        {{ allVisibleBulkSelected ? 'Clear page' : 'Select page' }}
                                    </button>
                                    <span class="text-xs font-bold text-slate-500">{{ selectedBulkApplicationIds.length }} selected</span>
                                    <button type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="selectedBulkApplicationIds.length === 0 || bulkAdvancing" @click="applyBulkAdvance">
                                        {{ bulkAdvancing ? 'Approving...' : 'Approve selected' }}
                                    </button>
                                </div>
                            </div>
                            <p v-if="bulkAdvanceError" class="mt-2 text-xs font-semibold text-rose-700">{{ bulkAdvanceError }}</p>
                        </div>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No applicants yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                {{ hasProgramContext
                                    ? 'Applicants for this program will appear here after eligible students submit the application wizard.'
                                    : 'Applicants will appear after a published scholarship receives a completed application.' }}
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="/provider/programs" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800">Check programs</a>
                                <a href="/provider/programs/create" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100">Create scholarship</a>
                            </div>
                        </div>

                        <div v-else-if="rankedApplications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            {{ emptyQueueMessage }}
                        </div>

                        <div v-else class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                            <article
                                v-for="application in visibleApplications"
                                :key="application.id"
                                class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-3 py-3 transition last:border-b-0 hover:bg-slate-50 sm:px-4"
                            >
                                <label v-if="hasProgramContext" :title="canBulkAdvance(application) ? 'Select applicant' : 'This applicant is not ready for the selected bulk action.'" class="grid h-8 w-8 shrink-0 place-items-center">
                                    <input v-model="selectedBulkApplicationIds" type="checkbox" :value="application.id" :disabled="!canBulkAdvance(application)" class="h-4 w-4 rounded border-slate-300 text-slate-950 focus:ring-amber-400 disabled:cursor-not-allowed disabled:opacity-30">
                                    <span class="sr-only">Select {{ application.applicant?.name || 'applicant' }}</span>
                                </label>
                                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-slate-950 text-xs font-bold tracking-[0.08em] text-white ring-1 ring-slate-200">
                                    {{ applicantInitials(application) }}
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <h4 class="truncate text-sm font-bold text-slate-950 sm:text-base">
                                            {{ application.applicant?.name || 'Applicant' }}
                                        </h4>
                                        <i
                                            v-if="application.applicant?.profile_verification_status === 'approved'"
                                            class="fa-solid fa-circle-check text-xs text-emerald-600"
                                            title="Verified applicant"
                                            aria-label="Verified applicant"
                                        ></i>
                                        <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(application.status)]">
                                            {{ statusLabel(application.status) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">
                                        {{ application.scholarship?.title || 'Scholarship' }} - {{ application.applicant?.email || 'No email provided' }}
                                    </p>
                                    <div class="mt-1 hidden flex-wrap items-center gap-x-3 gap-y-1 text-[11px] font-semibold text-slate-500 sm:flex">
                                        <span>Submitted {{ application.submitted_at || 'recently' }}</span>
                                        <span v-if="showWaitingTime(application)">Waiting {{ application.waiting_days }}d</span>
                                        <span>Match {{ application.dss_score ?? 0 }}%</span>
                                        <span>Files {{ application.document_readiness?.percent ?? 0 }}%</span>
                                        <span v-if="documentIssueCount(application)" class="text-amber-700">
                                            {{ documentIssueCount(application) }} file issue{{ documentIssueCount(application) === 1 ? '' : 's' }}
                                        </span>
                                        <span v-if="application.documents_changed_since_review" class="text-amber-700">Files updated</span>
                                    </div>
                                </div>

                                <div :class="['flex w-full shrink-0 gap-2 sm:w-auto sm:pl-0', hasProgramContext ? 'pl-[6.25rem]' : 'pl-14']">
                                    <label v-if="canAssignReviewers" class="min-w-0 flex-1 sm:w-44 sm:flex-none">
                                        <span class="sr-only">Assigned reviewer for {{ application.applicant?.name || 'applicant' }}</span>
                                        <select
                                            :value="application.assigned_reviewer?.id ?? ''"
                                            :disabled="assigningReviewerApplicationId === application.id"
                                            class="w-full rounded-md border border-slate-300 bg-white px-2.5 py-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-slate-500 disabled:cursor-wait disabled:opacity-60"
                                            @change="assignReviewer(application, $event)"
                                        >
                                            <option value="">Unassigned</option>
                                            <option v-for="reviewer in reviewers" :key="reviewer.id" :value="reviewer.id">
                                                {{ reviewer.name }} - {{ reviewer.role_label }}
                                            </option>
                                        </select>
                                    </label>
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800"
                                        @click="openApplicationPreview(application)"
                                    >
                                        Review
                                    </button>
                                </div>
                            </article>

                            <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
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

                <ProviderFooter />
            </div>
        </section>

        <Teleport to="body">
            <div
                v-if="selectedApplicationPreview"
                class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/70 p-3 sm:p-5"
                role="dialog"
                aria-modal="true"
                aria-labelledby="provider-application-preview-title"
                tabindex="-1"
                @click.self="closeApplicationPreview"
                @keydown.esc="closeApplicationPreview"
            >
                <section class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
                    <header class="flex items-start gap-3 border-b border-slate-200 px-4 py-4 sm:px-5">
                        <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-md bg-slate-950 text-xs font-bold tracking-[0.08em] text-white">
                            <img
                                v-if="selectedApplicationPreview.applicant?.profile_photo_url"
                                :src="selectedApplicationPreview.applicant.profile_photo_url"
                                :alt="`${selectedApplicationPreview.applicant?.name || 'Applicant'} photo`"
                                class="h-full w-full object-cover"
                            >
                            <span v-else>{{ applicantInitials(selectedApplicationPreview) }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Review summary</p>
                                <span :class="['rounded-md px-2 py-1 text-[9px] font-bold uppercase', statusClass(selectedApplicationPreview.status)]">
                                    {{ statusLabel(selectedApplicationPreview.status) }}
                                </span>
                            </div>
                            <h2 id="provider-application-preview-title" class="mt-1 truncate text-lg font-bold text-slate-950 sm:text-xl">
                                {{ selectedApplicationPreview.applicant?.name || 'Applicant' }}
                            </h2>
                            <p class="mt-1 truncate text-xs text-slate-500">
                                {{ selectedApplicationPreview.applicant?.email || 'No email provided' }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 transition hover:bg-slate-50"
                            aria-label="Close application overview"
                            @click="closeApplicationPreview"
                        >
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
                        <section class="rounded-md border border-slate-200 bg-slate-50 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Scholarship program</p>
                            <p class="mt-1 text-base font-bold text-slate-950">
                                {{ selectedApplicationPreview.scholarship?.title || 'Scholarship program' }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                <span>Submitted {{ selectedApplicationPreview.submitted_at || 'recently' }}</span>
                                <span v-if="showWaitingTime(selectedApplicationPreview)">Waiting {{ selectedApplicationPreview.waiting_days }} days</span>
                                <span v-if="canAssignReviewers">
                                    {{ selectedApplicationPreview.assigned_reviewer?.name ? `Reviewer: ${selectedApplicationPreview.assigned_reviewer.name}` : 'Reviewer unassigned' }}
                                </span>
                            </div>
                        </section>

                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <article class="rounded-md border border-slate-200 bg-white p-3">
                                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">DSS guidance</p>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <p class="text-xl font-bold text-slate-950">{{ selectedApplicationPreview.dss_score ?? 0 }}%</p>
                                    <span :class="['rounded-md px-2 py-1 text-[9px] font-bold uppercase', recommendationClass(selectedApplicationPreview.dss_recommendation)]">
                                        {{ statusLabel(selectedApplicationPreview.dss_recommendation || 'needs_review') }}
                                    </span>
                                </div>
                                <p class="mt-2 text-[11px] leading-4 text-slate-500">
                                    Compares the applicant profile with this program's criteria. It guides review but does not decide approval.
                                </p>
                            </article>

                            <article class="rounded-md border border-slate-200 bg-white p-3">
                                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Required files</p>
                                <p class="mt-2 text-xl font-bold text-slate-950">
                                    {{ selectedApplicationPreview.document_readiness?.uploaded ?? 0 }}/{{ selectedApplicationPreview.document_readiness?.required ?? 0 }}
                                </p>
                                <p :class="['mt-1 text-xs font-semibold', documentIssueCount(selectedApplicationPreview) ? 'text-amber-700' : 'text-slate-500']">
                                    {{ documentIssueCount(selectedApplicationPreview) ? `${documentIssueCount(selectedApplicationPreview)} need review` : 'No file issues shown' }}
                                </p>
                            </article>

                            <article class="rounded-md border border-slate-200 bg-white p-3">
                                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Applicant profile</p>
                                <div class="mt-2 flex items-center gap-2">
                                    <i
                                        :class="selectedApplicationPreview.applicant?.profile_verification_status === 'approved' ? 'fa-solid fa-circle-check text-emerald-600' : 'fa-regular fa-circle text-amber-600'"
                                        aria-hidden="true"
                                    ></i>
                                    <p class="text-sm font-bold text-slate-950">
                                        {{ selectedApplicationPreview.applicant?.profile_verification_status === 'approved' ? 'Verified' : statusLabel(selectedApplicationPreview.applicant?.profile_verification_status || 'pending') }}
                                    </p>
                                </div>
                                <p class="mt-2 line-clamp-1 text-xs text-slate-500">
                                    {{ selectedApplicationPreview.applicant?.education_level || selectedApplicationPreview.applicant?.school || 'Profile available in full review' }}
                                </p>
                            </article>
                        </div>

                        <section class="mt-4 rounded-md border border-slate-200 bg-white p-4">
                            <div class="flex items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                    <i class="fa-solid fa-list-check text-xs" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-slate-950">Review focus</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        {{ selectedApplicationPreview.dss_explanation?.next_action || 'Check eligibility, submitted files, and the applicant profile before recording a decision.' }}
                                    </p>
                                    <p v-if="selectedApplicationPreview.documents_changed_since_review" class="mt-2 text-xs font-bold text-amber-700">
                                        The applicant uploaded newer files after the last review.
                                    </p>
                                </div>
                            </div>
                        </section>
                    </div>

                    <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <p class="text-xs text-slate-500">Open the full review to check files, profile proofs, rubric scores, and record a decision.</p>
                        <div class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                @click="closeApplicationPreview"
                            >
                                Close
                            </button>
                            <a
                                :href="selectedApplicationPreview.detail_url || `/provider/applications/${selectedApplicationPreview.id}`"
                                class="rounded-md bg-slate-950 px-3 py-2 text-center text-xs font-bold text-white transition hover:bg-slate-800"
                            >
                                Continue review
                            </a>
                        </div>
                    </footer>
                </section>
            </div>
        </Teleport>
    </main>

    <ConfirmationDialog v-bind="confirmation" @confirm="confirmConfirmation" @cancel="cancelConfirmation" />
</template>
