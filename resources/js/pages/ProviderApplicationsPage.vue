<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderProgramNav from '../components/ProviderProgramNav.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';

const appElement = document.getElementById('app');
const pageSearchParams = new URLSearchParams(window.location.search);
const initialScholarshipId = appElement?.dataset.scholarshipId ?? pageSearchParams.get('scholarship_id') ?? '';
const initialScholarshipTitle = appElement?.dataset.scholarshipTitle ?? '';
const requestedWorkspaceSection = pageSearchParams.get('workspace');
const requestedQueueFilter = pageSearchParams.get('filter');
const requestedQueueSort = pageSearchParams.get('sort');
const requestedApplicationPage = Number(pageSearchParams.get('page'));
const queueFilterValues = ['pending_review', 'document_issues', 'active_stages', 'formal_application', 'decided', 'all'];
const queueSortValues = ['priority', 'dss', 'documents', 'oldest'];
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
const selectedQueueSort = ref(queueSortValues.includes(requestedQueueSort) ? requestedQueueSort : 'priority');
const applicationSearch = ref(pageSearchParams.get('search') ?? '');
const applicationPage = ref(Number.isInteger(requestedApplicationPage) && requestedApplicationPage > 0 ? requestedApplicationPage : 1);
const selectedApplicationPreview = ref(null);
const applicationsPerPage = 10;
const activeWorkspaceSection = ref(['applications', 'schedule'].includes(requestedWorkspaceSection)
    ? requestedWorkspaceSection
    : 'applications');
const programEvents = ref([]);
const scheduleEditorType = ref('');
const scheduleSaving = ref(false);
const scheduleError = ref('');
const scheduleForm = ref(emptyScheduleForm());
const selectedBulkApplicationIds = ref([]);
const bulkAdvanceTarget = ref('pass_prescreening');
const bulkAdvancing = ref(false);
const bulkAdvanceError = ref('');
const showBulkActions = ref(false);
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
];
const scheduleModeOptions = [
    { value: 'onsite', label: 'On-site' },
    { value: 'online', label: 'Online' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'provider_managed', label: 'Provider managed' },
];
const selectedScholarshipId = computed(() => selectedScholarshipContext.value?.id || initialScholarshipId);
const hasProgramContext = computed(() => Boolean(selectedScholarshipId.value));
const canManagePrograms = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_programs'),
));
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
const pendingReviewCount = computed(() => applications.value.filter((application) => (
    workflowStage(application) === 'screening' && !workflowClosed(application)
)).length);
const waitingScheduleTypes = computed(() => {
    return configuredScheduleTypes.value.filter((type) => (
        scheduleEvent(type.value)?.status !== 'scheduled'
        && applications.value.some((application) => (
            workflowStage(application) === type.value
            && !applicationSchedule(application, type.value)
        ))
    ));
});
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

    return tasks;
});
const primaryWorkspaceTask = computed(() => workspaceTasks.value[0] ?? null);
const remainingWorkspaceTaskCount = computed(() => Math.max(workspaceTasks.value.length - 1, 0));
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
    ? 'Review pre-screening submissions, then manage shared activities and formal outcomes when needed.'
    : 'Find applicants needing attention and review their profile, eligibility, and supporting files.'));
const reviewFilterOptions = computed(() => [
    {
        value: 'pending_review',
        label: 'Needs review',
        count: applications.value.filter((application) => workflowStage(application) === 'screening' && !workflowClosed(application)).length,
    },
    {
        value: 'document_issues',
        label: 'Document issues',
        count: applications.value.filter((application) => documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100).length,
    },
    {
        value: 'active_stages',
        label: 'Active stages',
        count: applications.value.filter((application) => ['exam', 'interview'].includes(workflowStage(application)) && !workflowClosed(application)).length,
    },
    {
        value: 'formal_application',
        label: 'Formal application',
        count: applications.value.filter((application) => ['formal_application', 'decision'].includes(workflowStage(application)) && !workflowClosed(application)).length,
    },
    {
        value: 'decided',
        label: 'Decisions',
        count: applications.value.filter((application) => workflowClosed(application) || Boolean(application.workflow?.final_outcome)).length,
    },
    { value: 'all', label: 'All applicants', count: applications.value.length },
]);
const emptyQueueMessage = computed(() => ({
    pending_review: 'No applicants currently need an initial review.',
    document_issues: 'No applicants currently have missing or unresolved document issues.',
    active_stages: 'No applicants are currently in an exam or interview stage.',
    formal_application: 'No applicants are currently completing the provider formal application process.',
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
            return workflowStage(application) === 'screening' && !workflowClosed(application);
        }

        if (selectedQueueFilter.value === 'document_issues') {
            return documentIssueCount(application) > 0 || Number(application.document_readiness?.percent ?? 0) < 100;
        }

        if (selectedQueueFilter.value === 'active_stages') {
            return ['exam', 'interview'].includes(workflowStage(application)) && !workflowClosed(application);
        }

        if (selectedQueueFilter.value === 'formal_application') {
            return ['formal_application', 'decision'].includes(workflowStage(application)) && !workflowClosed(application);
        }

        if (selectedQueueFilter.value === 'decided') {
            return workflowClosed(application) || Boolean(application.workflow?.final_outcome);
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
    return application?.workflow?.final_outcome_label
        ?? application?.workflow?.current_stage_label
        ?? statusLabel(application?.status);
}

function applicationActionLabel(application) {
    if (workflowClosed(application)) {
        return 'View result';
    }

    if (workflowStage(application) === 'decision') {
        return 'Record outcome';
    }

    return 'Review stage';
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

    if (['withdrawn', 'rejected', 'not_awarded', 'exam_failed', 'interview_failed'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled', 'waitlisted'].includes(status)) {
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

function applicationDetailUrl(application) {
    const detailUrl = application.detail_url || `/provider/applications/${application.id}`;
    const url = new URL(detailUrl, window.location.origin);

    url.searchParams.set('return_to', `${window.location.pathname}${window.location.search}`);

    return `${url.pathname}${url.search}${url.hash}`;
}

function selectWorkspaceSection(section) {
    activeWorkspaceSection.value = section;
    scheduleError.value = '';

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
            interview: 'Bring a valid school ID and be ready to discuss your application and scholarship goals.',
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
            bulkAdvanceTarget.value = availableBulkAdvanceTargets.value[0]?.value ?? 'pass_prescreening';
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

watch([selectedQueueFilter, selectedQueueSort, applicationSearch, applicationPage], ([filter, sort, search, page]) => {
    const url = new URL(window.location.href);

    url.searchParams.set('filter', filter);
    url.searchParams.set('sort', sort);

    if (search.trim()) {
        url.searchParams.set('search', search.trim());
    } else {
        url.searchParams.delete('search');
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
                <nav v-if="hasProgramContext" class="mb-4 flex min-w-0 items-center gap-2 text-sm" aria-label="Breadcrumb">
                    <a href="/provider/programs" class="font-bold text-slate-600 transition hover:text-slate-950">Programs</a>
                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-400" aria-hidden="true"></i>
                    <a :href="`/provider/programs/${selectedScholarshipId}`" class="max-w-72 truncate font-bold text-slate-600 transition hover:text-slate-950">
                        {{ selectedScholarshipContext?.title || 'Program workspace' }}
                    </a>
                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-400" aria-hidden="true"></i>
                    <span class="font-semibold text-slate-950">{{ activeWorkspaceSection === 'schedule' ? 'Schedule' : 'Applicants' }}</span>
                </nav>

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
                    </div>
                </header>

                <ProviderProgramNav
                    v-if="hasProgramContext"
                    :program-id="selectedScholarshipId"
                    :active="activeWorkspaceSection === 'schedule' ? 'schedule' : 'applicants'"
                    :can-manage="canManagePrograms"
                />

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
                                    Publish confirmed exam or interview details after applicants reach that stage. Pre-screening and final decisions stay in applicant review.
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
                                    <span class="ml-1 text-[10px] text-slate-400">Required</span>
                                </label>
                                <input v-model="scheduleForm.onlineUrl" type="url" maxlength="2000" placeholder="https://..." required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-600">
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
                                        Only applicants ready for this exact workflow action are selectable.
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="bulkEligibleVisibleApplications.length === 0" @click="toggleVisibleBulkSelection">
                                        {{ allVisibleBulkSelected ? 'Clear page' : 'Select page' }}
                                    </button>
                                    <span class="text-xs font-bold text-slate-500">{{ selectedBulkApplicationIds.length }} selected</span>
                                    <button type="button" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="selectedBulkApplicationIds.length === 0 || bulkAdvancing" @click="applyBulkAdvance">
                                        {{ bulkAdvancing ? 'Saving...' : 'Apply to selected' }}
                                    </button>
                                </div>
                            </div>
                            <p v-if="bulkAdvanceError" class="mt-2 text-xs font-semibold text-rose-700">{{ bulkAdvanceError }}</p>
                        </div>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6">
                            <p class="text-sm font-bold text-slate-900">No applicants yet</p>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                {{ hasProgramContext
                                    ? 'Applicants for this program will appear here after students submit the portal pre-screening form.'
                                    : 'Applicants will appear after a published scholarship receives a pre-screening submission.' }}
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
                                            title="Verified academic record"
                                            aria-label="Verified academic record"
                                        ></i>
                                        <span :class="['hidden shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase sm:inline-flex', statusClass(application.status)]">
                                            {{ applicationQueueLabel(application) }}
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
                                        <span v-if="application.correction_status === 'requested'" class="text-amber-700">Correction requested</span>
                                        <span v-if="application.correction_status === 'submitted'" class="text-sky-700">Correction ready to review</span>
                                        <span v-if="application.status === 'waitlisted' && application.waitlist_position" class="text-sky-700">Alternate #{{ application.waitlist_position }}</span>
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
                                        {{ applicationActionLabel(application) }}
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
                                    {{ selectedApplicationPreview.dss_explanation?.score_interpretation || 'Compares the applicant profile with this program criteria. It guides review but does not decide approval.' }}
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
                        <p class="text-xs text-slate-500">Open the full review to check required files, the verified academic record, rubric scores, and record a decision.</p>
                        <div class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                @click="closeApplicationPreview"
                            >
                                Close
                            </button>
                            <a
                                :href="applicationDetailUrl(selectedApplicationPreview)"
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
