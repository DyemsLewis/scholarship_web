<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantDashboardOverview from '../components/ApplicantDashboardOverview.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const profileReadiness = ref({
    complete: false,
    completed: 0,
    total: 0,
    percent: 0,
    missing: [],
});
const scholarships = ref([]);
const applications = ref([]);
const actionAlerts = ref([]);
const nextSteps = ref([]);
const recommendationsReady = computed(() => profileReadiness.value.complete === true);

const recommendedScholarships = computed(() => {
    if (!recommendationsReady.value) {
        return [];
    }

    return scholarships.value
        .filter((scholarship) => scholarship.eligibility_match?.is_eligible === true)
        .sort((first, second) => {
            const scoreDifference = Number(second.eligibility_match?.score ?? 0)
                - Number(first.eligibility_match?.score ?? 0);

            if (scoreDifference !== 0) {
                return scoreDifference;
            }

            return Number(first.has_applied) - Number(second.has_applied);
        })
        .slice(0, 3);
});

const scheduledActivities = computed(() => applications.value
    .filter((application) => !isClosedApplication(application))
    .flatMap((application) => applicationSchedules(application)
        .filter((schedule) => schedule.status === 'scheduled')
        .map((schedule) => ({ application, schedule })))
    .sort((first, second) => scheduleTimestamp(first.schedule) - scheduleTimestamp(second.schedule)));

const nextScheduledActivity = computed(() => scheduledActivities.value[0] ?? null);
const activeApplication = computed(() => nextScheduledActivity.value?.application
    ?? applications.value.find((application) => !isClosedApplication(application))
    ?? null);
const activeApplicationCount = computed(() => applications.value.filter((application) => !isClosedApplication(application)).length);
const correctionApplication = computed(() => applications.value.find(
    (application) => application.correction_status === 'requested',
) ?? null);
const documentActionApplication = computed(() => applications.value.find(
    (application) => applicationDocumentIssues(application).length > 0,
) ?? null);

const visibleApplications = computed(() => [...applications.value]
    .sort((first, second) => applicationPriority(second) - applicationPriority(first))
    .slice(0, 3));

const urgentScholarships = computed(() => scholarships.value
    .map((scholarship) => ({ ...scholarship, days_left: deadlineDays(scholarship.deadline) }))
    .filter((scholarship) => scholarship.days_left !== null
        && scholarship.days_left >= 0
        && scholarship.days_left <= 14)
    .sort((first, second) => first.days_left - second.days_left));

const priorityAction = computed(() => {
    if (correctionApplication.value) {
        const application = correctionApplication.value;

        return {
            key: `correction-${application.id}`,
            eyebrow: 'Provider request',
            title: 'Update your application',
            detail: application.scholarship?.title || 'Scholarship application',
            prompt: application.correction_message || 'The provider requested corrected information or supporting files.',
            href: application.detail_url || `/dashboard/applications/${application.id}`,
            button: 'Review request',
            icon: 'fa-solid fa-pen-to-square',
            requiresAttention: true,
            meta: [
                { icon: 'fa-regular fa-clock', label: application.correction_requested_at || 'Action requested' },
            ],
        };
    }

    if (documentActionApplication.value) {
        const application = documentActionApplication.value;
        const issues = applicationDocumentIssues(application);

        return {
            key: `document-review-${application.id}`,
            eyebrow: 'Document review',
            title: issues.length === 1 ? 'One file needs your attention' : `${issues.length} files need your attention`,
            detail: application.scholarship?.title || 'Scholarship application',
            prompt: issues[0]?.review_notes || 'Review the provider note and upload a replacement when requested.',
            href: application.detail_url || `/dashboard/applications/${application.id}`,
            button: 'Review files',
            icon: 'fa-solid fa-file-circle-exclamation',
            requiresAttention: true,
            meta: [
                { icon: 'fa-solid fa-folder-open', label: `${issues.length} to update` },
            ],
        };
    }

    const entry = nextScheduledActivity.value;

    if (entry) {
        const { application, schedule } = entry;
        const scholarshipTitle = application.scholarship?.title || 'Scholarship application';
        const providerName = application.scholarship?.provider?.name || 'Scholarship provider';
        const meta = [
            { icon: 'fa-regular fa-calendar', label: schedule.scheduled_label || 'Date pending' },
            { icon: 'fa-solid fa-location-dot', label: scheduleModeLabel(schedule.mode) },
        ];

        if (schedule.venue) {
            meta.push({ icon: 'fa-solid fa-building', label: schedule.venue });
        }

        return {
            key: `schedule-${schedule.id}`,
            eyebrow: scheduleTypeLabel(schedule.type),
            title: schedule.title,
            detail: `${scholarshipTitle} from ${providerName}.`,
            prompt: 'Review the date, location, and provider instructions before the activity.',
            href: application.detail_url || `/dashboard/applications/${application.id}`,
            button: 'View schedule',
            icon: scheduleTypeIcon(schedule.type),
            requiresAttention: true,
            meta,
        };
    }

    if (!profileReadiness.value.complete) {
        const missingCount = profileReadiness.value.missing?.length ?? 0;

        return {
            key: 'profile',
            eyebrow: 'Profile setup',
            title: 'Complete your applicant profile',
            detail: 'A complete profile improves matching and is required before you submit an application.',
            prompt: missingCount > 0
                ? `Finish ${missingCount} remaining profile ${missingCount === 1 ? 'detail' : 'details'}.`
                : 'Review your saved information and finish the required fields.',
            href: '/dashboard/profile',
            button: 'Continue profile',
            icon: 'fa-solid fa-user-pen',
            requiresAttention: true,
            meta: [
                { icon: 'fa-solid fa-chart-simple', label: `${profileReadiness.value.percent}% complete` },
            ],
        };
    }

    const application = activeApplication.value;
    const missingDocuments = application?.document_readiness?.missing ?? [];

    if (application && missingDocuments.length > 0) {
        return {
            key: `documents-${application.id}`,
            eyebrow: 'Application files',
            title: 'Prepare the missing requirements',
            detail: application.scholarship?.title || 'Scholarship application',
            prompt: `${missingDocuments.length} required ${missingDocuments.length === 1 ? 'document is' : 'documents are'} still missing.`,
            href: application.detail_url || `/dashboard/applications/${application.id}`,
            button: 'Review documents',
            icon: 'fa-solid fa-folder-open',
            requiresAttention: true,
            meta: [
                { icon: 'fa-solid fa-file-circle-exclamation', label: `${missingDocuments.length} missing` },
            ],
        };
    }

    if (application) {
        return {
            key: `application-${application.id}`,
            eyebrow: 'Application update',
            title: application.scholarship?.title || 'Scholarship application',
            detail: applicationNextAction(application),
            prompt: 'Open the application to review the latest provider update and file status.',
            href: application.detail_url || `/dashboard/applications/${application.id}`,
            button: 'View application',
            icon: 'fa-solid fa-file-circle-check',
            requiresAttention: false,
            meta: [
                { icon: 'fa-solid fa-list-check', label: applicationStatusLabel(application) },
            ],
        };
    }

    return {
        key: 'browse',
        eyebrow: 'Scholarship finder',
        title: 'Find a program that fits you',
        detail: nextSteps.value[0] || 'Compare your profile with available scholarship requirements.',
        prompt: 'Start with your strongest matches and save programs you want to revisit.',
        href: '/dashboard/scholarships',
        button: 'Browse scholarships',
        icon: 'fa-solid fa-magnifying-glass',
        requiresAttention: false,
        meta: [],
    };
});

const readinessItems = computed(() => {
    const application = activeApplication.value;
    const applicationDocumentPercent = Number(application?.document_readiness?.percent ?? 0);
    const verificationStatus = user.value?.applicant_verification_status ?? 'unsubmitted';
    const verificationCopy = {
        approved: {
            status: 'Verified',
            statusClass: 'bg-emerald-100 text-emerald-800',
            detail: 'Your academic record has been verified.',
            action: 'Review',
        },
        pending: {
            status: 'In review',
            statusClass: 'bg-amber-100 text-amber-800',
            detail: 'Your academic proof is awaiting platform review.',
            action: 'View',
        },
        rejected: {
            status: 'Update needed',
            statusClass: 'bg-rose-100 text-rose-800',
            detail: 'Review the verification note and update your proof.',
            action: 'Update',
        },
        unsubmitted: {
            status: 'Not submitted',
            statusClass: 'bg-slate-100 text-slate-700',
            detail: 'Upload a recent academic record when you are ready.',
            action: 'Upload',
        },
    }[verificationStatus] ?? null;
    const recommendationFileTotals = recommendedScholarships.value.reduce((totals, scholarship) => {
        const required = Number(scholarship.prepared_documents?.required ?? 0);

        if (required <= 0) {
            return totals;
        }

        totals.required += required;
        totals.uploaded += Math.min(Number(scholarship.prepared_documents?.uploaded ?? 0), required);

        return totals;
    }, { required: 0, uploaded: 0 });
    const recommendationReadiness = recommendationFileTotals.required > 0
        ? Math.round((recommendationFileTotals.uploaded / recommendationFileTotals.required) * 100)
        : 0;

    return [
        {
            label: 'Applicant profile',
            percent: Number(profileReadiness.value.percent ?? 0),
            detail: profileReadiness.value.complete
                ? 'Ready for applications.'
                : `${profileReadiness.value.missing?.length ?? 0} details remaining.`,
            href: '/dashboard/profile',
            action: profileReadiness.value.complete ? 'Review' : 'Complete',
        },
        application
            ? {
                label: 'Application files',
                percent: applicationDocumentPercent,
                detail: application.scholarship?.title || 'Current application',
                href: application.detail_url || `/dashboard/applications/${application.id}`,
                action: 'Review',
            }
            : {
                label: 'Prepared files',
                percent: recommendationReadiness,
                detail: recommendationReadiness > 0
                    ? 'Across recommended programs that require files.'
                    : recommendationFileTotals.required > 0
                        ? 'No required files uploaded yet.'
                        : 'No files are currently required by your recommendations.',
                href: '/dashboard/documents',
                action: 'Prepare',
            },
        {
            label: 'Academic verification',
            percent: null,
            status: verificationCopy?.status || 'Not submitted',
            statusClass: verificationCopy?.statusClass || 'bg-slate-100 text-slate-700',
            detail: verificationCopy?.detail || 'Upload a recent academic record when you are ready.',
            href: '/dashboard/profile?section=verification',
            action: verificationCopy?.action || 'Upload',
        },
    ];
});

const reminders = computed(() => {
    const items = [];
    const currentScheduleId = nextScheduledActivity.value?.schedule.id;
    const priorityAlertTypes = priorityAction.value.key.startsWith('correction-')
        ? ['application_correction']
        : priorityAction.value.key.startsWith('document-review-')
            ? ['document_review']
            : priorityAction.value.key.startsWith('schedule-')
                ? ['application_schedule']
                : priorityAction.value.key.startsWith('application-')
                    ? ['application_status', 'application_outcome']
                    : [];

    actionAlerts.value.forEach((alert) => {
        if (priorityAlertTypes.includes(alert.type) && alert.action_url === priorityAction.value.href) {
            return;
        }

        items.push({
            key: `notification-${alert.id}`,
            notificationId: alert.id,
            title: alert.title,
            detail: alert.message,
            href: alert.action_url || '/dashboard',
            icon: actionAlertIcon(alert.type),
        });
    });

    scheduledActivities.value
        .filter((entry) => entry.schedule.id !== currentScheduleId)
        .slice(0, 2)
        .forEach((entry) => items.push({
            key: `schedule-${entry.schedule.id}`,
            title: `${scheduleTypeLabel(entry.schedule.type)} scheduled`,
            detail: [
                entry.schedule.scheduled_label,
                entry.application.scholarship?.title,
            ].filter(Boolean).join(' - ') || 'Open the application for details.',
            href: entry.application.detail_url || `/dashboard/applications/${entry.application.id}`,
            icon: scheduleTypeIcon(entry.schedule.type),
        }));

    if (!profileReadiness.value.complete && priorityAction.value.key !== 'profile') {
        items.push({
            key: 'profile',
            title: 'Profile is not complete',
            detail: `${profileReadiness.value.missing?.length ?? 0} details still need attention.`,
            href: '/dashboard/profile',
            icon: 'fa-solid fa-user-pen',
        });
    }

    const applicationWithMissingFiles = applications.value.find((application) => {
        const missing = application.document_readiness?.missing?.length ?? 0;

        return missing > 0 && priorityAction.value.key !== `documents-${application.id}`;
    });

    if (applicationWithMissingFiles) {
        const missing = applicationWithMissingFiles.document_readiness?.missing?.length ?? 0;

        items.push({
            key: `documents-${applicationWithMissingFiles.id}`,
            title: 'Application files are incomplete',
            detail: `${missing} ${missing === 1 ? 'requirement' : 'requirements'} missing.`,
            href: applicationWithMissingFiles.detail_url || `/dashboard/applications/${applicationWithMissingFiles.id}`,
            icon: 'fa-solid fa-file-circle-exclamation',
        });
    }

    const urgent = urgentScholarships.value[0];

    if (urgent) {
        items.push({
            key: `deadline-${urgent.id}`,
            title: urgentDeadlineLabel(urgent),
            detail: urgent.title,
            href: `/dashboard/scholarships/${urgent.id}`,
            icon: 'fa-solid fa-clock',
        });
    }

    if (items.length === 0) {
        items.push({
            key: 'clear',
            title: 'Nothing urgent right now',
            detail: 'Your profile, files, and schedules have no immediate action.',
            href: '/dashboard/scholarships',
            icon: 'fa-solid fa-circle-check',
        });
    }

    return items.slice(0, 4);
});

function actionAlertIcon(type) {
    return {
        program_announcement: 'fa-solid fa-bullhorn',
        application_correction: 'fa-solid fa-pen-to-square',
        document_review: 'fa-solid fa-file-circle-exclamation',
        application_status: 'fa-solid fa-arrows-rotate',
        application_outcome: 'fa-solid fa-award',
        application_schedule: 'fa-regular fa-calendar-check',
        applicant_profile_verification: 'fa-solid fa-user-check',
    }[type] ?? 'fa-solid fa-bell';
}

function applicationSchedules(application) {
    return Array.isArray(application?.schedules) ? application.schedules : [];
}

function applicationDocumentIssues(application) {
    return (application?.documents ?? []).filter((document) => (
        ['rejected', 'needs_replacement'].includes(document.status)
    ));
}

function scholarshipMatchReason(scholarship) {
    const labels = {
        academic: 'academic record',
        education_level: 'education level',
        course: 'track or course',
        school_type: 'school type',
        year_level: 'grade level',
        location: 'location',
        income: 'income bracket',
    };
    const matched = (scholarship?.eligibility_match?.criteria ?? [])
        .filter((criterion) => criterion.status === 'pass' && criterion.key !== 'documents')
        .map((criterion) => labels[criterion.key] || String(criterion.label || '').toLowerCase())
        .filter(Boolean)
        .slice(0, 3);

    if (!matched.length) {
        return scholarship?.eligibility_match?.summary || 'Eligible based on your saved profile.';
    }

    const reason = matched.length === 1
        ? matched[0]
        : `${matched.slice(0, -1).join(', ')} and ${matched.at(-1)}`;

    return `Matches your ${reason}.`;
}

function nextApplicationStageLabel(application) {
    const steps = application?.workflow?.steps ?? [];
    const currentIndex = steps.findIndex((step) => step.key === application?.workflow?.current_stage);

    if (currentIndex < 0) {
        return null;
    }

    return steps.slice(currentIndex + 1).find((step) => step.status === 'pending')?.label ?? null;
}

function latestApplicationUpdate(application) {
    const timeline = application?.timeline ?? [];

    return timeline.at(-1)?.changed_at || application?.submitted_at || 'Recently';
}

function activeSchedule(application) {
    if (isClosedApplication(application)) {
        return null;
    }

    return applicationSchedules(application)
        .filter((schedule) => schedule.status === 'scheduled')
        .sort((first, second) => scheduleTimestamp(first) - scheduleTimestamp(second))[0] ?? null;
}

function isClosedApplication(application) {
    return Boolean(application?.workflow?.is_closed)
        || ['rejected', 'not_awarded', 'awarded', 'withdrawn', 'exam_failed', 'interview_failed', 'benefits_terminated'].includes(application?.status);
}

function applicationPriority(application) {
    const schedule = activeSchedule(application);

    if (schedule) {
        return 90;
    }

    const stageRanks = {
        screening: 70,
        formal_application: 65,
        exam: 60,
        interview: 55,
        decision: 50,
        complete: 10,
    };

    return stageRanks[application?.workflow?.current_stage] ?? 0;
}

function applicationNextAction(application) {
    const schedule = activeSchedule(application);

    if (schedule) {
        return `Follow the ${scheduleTypeLabel(schedule.type).toLowerCase()} instructions for ${schedule.scheduled_label}.`;
    }

    const missingDocuments = application?.document_readiness?.missing?.length ?? 0;

    if (missingDocuments > 0) {
        return `${missingDocuments} required ${missingDocuments === 1 ? 'document is' : 'documents are'} still missing.`;
    }

    if (application?.workflow?.next_action?.label) {
        return application.workflow.next_action.label;
    }

    if (application?.status_progress?.next_action) {
        return application.status_progress.next_action;
    }

    return {
        submitted: 'Waiting for the provider to begin reviewing your submission.',
        under_review: 'The provider is checking your profile and documents.',
        qualified: 'You passed the initial requirements and remain under review.',
        shortlisted: 'You are shortlisted for closer provider review.',
        interview: 'Watch for interview instructions or a provider decision.',
        exam_qualified: 'Wait for the provider to publish the exam schedule.',
        exam_scheduled: 'Review the posted exam details.',
        exam_taken: 'Wait for the provider to record the exam result.',
        exam_passed: 'You passed the exam. Wait for the provider to finish pre-screening.',
        approved: 'You passed pre-screening. Review how to continue the formal application.',
        awarded: 'You were selected. Review the provider result and follow-up instructions.',
        distribution_scheduled: 'You were selected. Review the provider result and follow-up instructions.',
        disbursed: 'The provider recorded the scholarship reward as distributed.',
        renewed: 'Your scholarship support was renewed.',
        benefits_terminated: 'The provider stopped future scholarship benefits. Open the application to review the reason.',
        rejected: 'You did not qualify in pre-screening. Review the provider note.',
        not_awarded: 'The review finished without an award. Check the provider note.',
        exam_failed: 'Review the provider note for the exam result.',
        interview_failed: 'Review the provider note for the interview result.',
    }[application?.status] ?? 'Open the application for the latest provider update.';
}

function applicationStatusLabel(application) {
    if (application?.status === 'benefits_terminated') {
        return statusLabel(application.status);
    }

    return application?.workflow?.final_outcome_label
        ?? application?.workflow?.current_stage_label
        ?? statusLabel(application?.status);
}

function statusLabel(status) {
    const labels = {
        approved: 'Qualified for formal application',
        rejected: 'Not qualified',
        exam_qualified: 'Qualified for exam',
        exam_scheduled: 'Exam scheduled',
        exam_taken: 'Exam taken',
        exam_passed: 'Passed exam',
        exam_failed: 'Failed exam',
        interview_failed: 'Failed interview',
        distribution_scheduled: 'Selected',
        disbursed: 'Distributed',
        benefits_terminated: 'Benefits stopped',
    };

    if (labels[status]) {
        return labels[status];
    }

    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (['approved', 'awarded', 'disbursed', 'renewed', 'exam_passed'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (['rejected', 'not_awarded', 'exam_failed', 'interview_failed', 'benefits_terminated'].includes(status)) {
        return 'bg-rose-100 text-rose-800';
    }

    if (['under_review', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled', 'exam_taken', 'distribution_scheduled'].includes(status)) {
        return 'bg-slate-100 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function scheduleTimestamp(schedule) {
    const timestamp = Date.parse(schedule?.scheduled_at ?? '');

    return Number.isNaN(timestamp) ? Number.MAX_SAFE_INTEGER : timestamp;
}

function scheduleTypeLabel(type) {
    return {
        exam: 'Scholarship exam',
        interview: 'Interview',
    }[type] ?? 'Scheduled activity';
}

function scheduleTypeIcon(type) {
    return {
        exam: 'fa-solid fa-clipboard-check',
        interview: 'fa-solid fa-comments',
    }[type] ?? 'fa-solid fa-calendar-day';
}

function scheduleModeLabel(mode) {
    return {
        onsite: 'On-site',
        online: 'Online',
        hybrid: 'On-site and online',
        provider_managed: 'Provider-managed',
    }[mode] ?? 'Provider-managed';
}

function deadlineDays(value) {
    const parsed = Date.parse(value ?? '');

    if (Number.isNaN(parsed)) {
        return null;
    }

    const today = new Date();
    const startOfToday = new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime();

    return Math.ceil((parsed - startOfToday) / 86400000);
}

function urgentDeadlineLabel(scholarship) {
    if (scholarship.days_left === 0) {
        return 'Deadline is today';
    }

    if (scholarship.days_left === 1) {
        return 'Deadline is tomorrow';
    }

    return `Deadline in ${scholarship.days_left} days`;
}

async function openReminder(event, reminder) {
    if (!reminder.notificationId || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();

    try {
        await window.axios.patch(`/notifications/${reminder.notificationId}/read`);
        actionAlerts.value = actionAlerts.value.filter((alert) => alert.id !== reminder.notificationId);
    } catch {
        // The destination remains available even if the read receipt cannot be saved.
    } finally {
        window.location.assign(reminder.href || '/dashboard');
    }
}

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/data');

        user.value = response.data.user;
        profileReadiness.value = response.data.profile_readiness ?? profileReadiness.value;
        scholarships.value = response.data.scholarships ?? [];
        applications.value = response.data.applications ?? [];
        actionAlerts.value = response.data.action_alerts ?? [];
        nextSteps.value = response.data.next_steps ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applicant dashboard.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <ApplicantPageHeader
                    eyebrow="Dashboard"
                    :title="`Welcome, ${user?.first_name || 'Scholar'}`"
                    description="See what needs attention and continue where you left off."
                    icon="fa-solid fa-table-columns"
                    action-href="/dashboard/scholarships"
                    action-label="Browse scholarships"
                />

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading applicant dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <ApplicantDashboardOverview
                    v-else
                    :profile-readiness="profileReadiness"
                    :active-application-count="activeApplicationCount"
                    :priority-action="priorityAction"
                    :readiness-items="readinessItems"
                    :applications="visibleApplications"
                    :recommendations="recommendedScholarships"
                    :reminders="reminders"
                    @open-reminder="openReminder"
                />


            </div>
        </section>
    </main>
</template>
