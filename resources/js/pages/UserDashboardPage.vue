<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
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

const recommendedScholarships = computed(() => scholarships.value
    .filter((scholarship) => scholarship.eligibility_match?.is_eligible === true)
    .sort((first, second) => {
        const scoreDifference = Number(second.eligibility_match?.score ?? 0)
            - Number(first.eligibility_match?.score ?? 0);

        if (scoreDifference !== 0) {
            return scoreDifference;
        }

        return Number(first.has_applied) - Number(second.has_applied);
    })
    .slice(0, 3));

const scheduledActivities = computed(() => applications.value
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
    const recommendationReadiness = recommendedScholarships.value.length > 0
        ? Math.round(recommendedScholarships.value.reduce(
            (total, scholarship) => total + Number(scholarship.prepared_documents?.percent ?? 0),
            0,
        ) / recommendedScholarships.value.length)
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
                    ? 'Across your recommended programs.'
                    : 'Start with common school files when you are ready.',
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
    return applicationSchedules(application)
        .filter((schedule) => schedule.status === 'scheduled')
        .sort((first, second) => scheduleTimestamp(first) - scheduleTimestamp(second))[0] ?? null;
}

function isClosedApplication(application) {
    return Boolean(application?.workflow?.is_closed)
        || ['rejected', 'not_awarded', 'awarded', 'withdrawn', 'exam_failed', 'interview_failed'].includes(application?.status);
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
        rejected: 'You did not qualify in pre-screening. Review the provider note.',
        not_awarded: 'The review finished without an award. Check the provider note.',
        exam_failed: 'Review the provider note for the exam result.',
        interview_failed: 'Review the provider note for the interview result.',
    }[application?.status] ?? 'Open the application for the latest provider update.';
}

function applicationStatusLabel(application) {
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

    if (['rejected', 'not_awarded', 'exam_failed', 'interview_failed'].includes(status)) {
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
                    description="Your next action, application progress, and strongest matches."
                    icon="fa-solid fa-table-columns"
                    action-href="/dashboard/scholarships"
                    action-label="Browse scholarships"
                    secondary-href="/dashboard/applications"
                    secondary-label="View applications"
                />

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading applicant dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-5">
                    <section class="overflow-hidden rounded-lg border border-slate-800 bg-slate-950 text-white shadow-sm">
                        <div class="grid lg:grid-cols-[minmax(0,1fr)_19rem]">
                            <div class="p-5 sm:p-6">
                                <div class="flex items-start gap-4">
                                    <span :class="['grid h-11 w-11 shrink-0 place-items-center rounded-md', priorityAction.requiresAttention ? 'bg-amber-400 text-slate-950' : 'bg-white/10 text-white']">
                                        <i :class="priorityAction.icon" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-200">
                                                {{ priorityAction.eyebrow }}
                                            </p>
                                            <span v-if="priorityAction.requiresAttention" class="rounded bg-amber-300 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-950">
                                                Needs attention
                                            </span>
                                            <span v-else class="rounded bg-white/10 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-200">
                                                Next up
                                            </span>
                                        </div>
                                        <h2 class="mt-2 text-2xl font-bold leading-tight sm:text-3xl">
                                            {{ priorityAction.title }}
                                        </h2>
                                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                                            {{ priorityAction.detail }}
                                        </p>
                                        <div v-if="priorityAction.meta.length" class="mt-4 flex flex-wrap gap-2">
                                            <span
                                                v-for="item in priorityAction.meta"
                                                :key="`${item.icon}-${item.label}`"
                                                class="inline-flex items-center gap-2 rounded-md bg-white/10 px-2.5 py-1.5 text-xs font-bold text-slate-100 ring-1 ring-white/10"
                                            >
                                                <i :class="item.icon" aria-hidden="true"></i>
                                                {{ item.label }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <aside class="border-t border-slate-200 bg-white p-5 text-slate-950 lg:border-l lg:border-t-0">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">Your next action</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    {{ priorityAction.prompt }}
                                </p>
                                <a
                                    :href="priorityAction.href"
                                    class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                >
                                    {{ priorityAction.button }}
                                    <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                </a>
                            </aside>
                        </div>
                    </section>

                    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_21rem] xl:items-stretch">
                        <div class="space-y-5 xl:contents xl:space-y-0">
                            <section class="student-card flex h-full flex-col overflow-hidden xl:col-start-1 xl:row-start-1">
                                <div class="flex min-h-24 flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="student-section-mark">
                                            <i class="fa-solid fa-award text-xs" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="student-kicker">Recommended for you</p>
                                            <h3 class="mt-1 text-lg font-bold text-slate-950">Eligible scholarships</h3>
                                            <p class="mt-1 text-sm text-slate-500">Ranked using your applicant profile.</p>
                                        </div>
                                    </div>
                                    <a href="/dashboard/scholarships" class="inline-flex w-fit shrink-0 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50 hover:text-slate-950">
                                        Browse all
                                        <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </div>

                                <div v-if="recommendedScholarships.length" class="flex flex-1 flex-col">
                                    <a
                                        v-for="(scholarship, index) in recommendedScholarships"
                                        :key="scholarship.id"
                                        :href="`/dashboard/scholarships/${scholarship.id}`"
                                        :class="[
                                            'group grid flex-1 gap-4 border-b border-slate-200 p-4 transition last:border-b-0 hover:bg-slate-50 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:p-5',
                                            index === 0 ? 'bg-amber-50/60' : 'bg-white',
                                        ]"
                                    >
                                        <div class="relative w-fit">
                                            <img
                                                :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                                :alt="scholarship.title"
                                                class="h-14 w-14 rounded-md bg-white object-contain p-2 ring-1 ring-slate-200"
                                            >
                                            <span v-if="index === 0" class="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-amber-400 text-[9px] text-slate-950 ring-2 ring-white">
                                                <i class="fa-solid fa-star" aria-hidden="true"></i>
                                            </span>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span v-if="index === 0" class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Best match</span>
                                                <span class="rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-900">
                                                    {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                                </span>
                                            </div>
                                            <h4 class="mt-1.5 line-clamp-2 text-sm font-bold leading-5 text-slate-950 sm:text-base">
                                                {{ scholarship.title }}
                                            </h4>
                                            <p class="mt-1 truncate text-xs text-slate-500">
                                                {{ scholarship.provider?.name || 'Scholarship provider' }}
                                            </p>
                                            <p class="mt-2 line-clamp-1 text-xs font-semibold text-slate-600">
                                                <i class="fa-solid fa-check mr-1 text-emerald-600" aria-hidden="true"></i>
                                                {{ scholarshipMatchReason(scholarship) }}
                                            </p>
                                        </div>

                                        <div class="flex items-center justify-between gap-4 sm:justify-end">
                                            <div class="sm:text-right">
                                                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Deadline</p>
                                                <p class="mt-1 text-xs font-bold text-slate-700">{{ scholarship.deadline || 'Open deadline' }}</p>
                                            </div>
                                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 bg-white text-xs text-slate-700 transition group-hover:border-slate-400 group-hover:bg-slate-950 group-hover:text-white">
                                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                                            </span>
                                        </div>
                                    </a>
                                </div>

                                <div v-else class="flex flex-1 p-5">
                                    <div class="student-empty-state w-full self-center">
                                        <p class="text-sm font-bold text-slate-900">No eligible scholarships yet</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-500">Complete or update your profile to improve matching.</p>
                                        <a href="/dashboard/profile" class="mt-3 inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-bold text-white transition hover:bg-slate-800">
                                            Review profile
                                        </a>
                                    </div>
                                </div>
                            </section>

                            <section class="student-card flex h-full flex-col overflow-hidden xl:col-start-1 xl:row-start-2">
                                <div class="flex min-h-24 flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="student-section-mark">
                                            <i class="fa-solid fa-file-lines text-xs" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="student-kicker">Your applications</p>
                                                <span v-if="activeApplicationCount" class="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">
                                                    {{ activeApplicationCount }} active
                                                </span>
                                            </div>
                                            <h3 class="mt-1 text-lg font-bold text-slate-950">Recent progress</h3>
                                            <p class="mt-1 text-sm text-slate-500">Latest status and next action.</p>
                                        </div>
                                    </div>
                                    <a href="/dashboard/applications" class="inline-flex w-fit shrink-0 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50 hover:text-slate-950">
                                        View all
                                        <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                    </a>
                                </div>

                                <div v-if="visibleApplications.length" class="flex flex-1 flex-col divide-y divide-slate-200">
                                    <a
                                        v-for="application in visibleApplications"
                                        :key="application.id"
                                        :href="application.detail_url || `/dashboard/applications/${application.id}`"
                                        class="group flex flex-1 items-center p-4 transition hover:bg-slate-50 sm:p-5"
                                    >
                                        <div class="flex w-full items-start gap-3">
                                            <img
                                                :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                                :alt="application.scholarship?.title || 'Scholarship application'"
                                                class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                            >
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                    <div class="min-w-0">
                                                        <h4 class="truncate text-sm font-bold text-slate-950">
                                                            {{ application.scholarship?.title || 'Scholarship application' }}
                                                        </h4>
                                                        <p class="mt-1 truncate text-xs text-slate-500">
                                                            {{ application.scholarship?.provider?.name || 'Scholarship provider' }}
                                                        </p>
                                                    </div>
                                                    <span :class="['w-fit shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(application.status)]">
                                                        {{ applicationStatusLabel(application) }}
                                                    </span>
                                                </div>

                                                <div class="mt-3 flex flex-wrap gap-1.5 text-[10px] font-bold text-slate-600">
                                                    <span class="rounded-md bg-slate-100 px-2 py-1">
                                                        Stage: {{ application.workflow?.current_stage_label || applicationStatusLabel(application) }}
                                                    </span>
                                                    <span v-if="nextApplicationStageLabel(application)" class="rounded-md bg-slate-100 px-2 py-1">
                                                        Next: {{ nextApplicationStageLabel(application) }}
                                                    </span>
                                                    <span class="rounded-md bg-slate-100 px-2 py-1">
                                                        <i class="fa-regular fa-clock mr-1" aria-hidden="true"></i>Updated {{ latestApplicationUpdate(application) }}
                                                    </span>
                                                </div>

                                                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <p class="text-sm leading-5 text-slate-600">
                                                        {{ applicationNextAction(application) }}
                                                    </p>
                                                    <span class="inline-flex shrink-0 items-center gap-2 text-xs font-bold text-slate-800">
                                                        Open
                                                        <i class="fa-solid fa-arrow-right text-[10px] transition group-hover:translate-x-0.5" aria-hidden="true"></i>
                                                    </span>
                                                </div>

                                                <div v-if="activeSchedule(application)" class="mt-3 flex items-center gap-2 text-xs font-semibold text-amber-800">
                                                    <i :class="scheduleTypeIcon(activeSchedule(application).type)" aria-hidden="true"></i>
                                                    <span>{{ scheduleTypeLabel(activeSchedule(application).type) }} · {{ activeSchedule(application).scheduled_label }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </div>

                                <div v-else class="flex flex-1 p-5">
                                    <div class="student-empty-state w-full self-center">
                                        <p class="text-sm font-bold text-slate-900">No applications yet</p>
                                        <p class="mt-1 text-sm leading-6 text-slate-500">Choose an eligible scholarship when you are ready to start pre-screening.</p>
                                        <a href="/dashboard/scholarships" class="mt-3 inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-bold text-white transition hover:bg-slate-800">
                                            Find scholarships
                                        </a>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <aside class="space-y-5 xl:contents xl:space-y-0">
                            <section class="student-card flex h-full flex-col overflow-hidden xl:col-start-2 xl:row-start-1">
                                <div class="flex min-h-24 items-center gap-3 border-b border-slate-200 p-4 sm:p-5">
                                    <span class="student-section-mark">
                                        <i class="fa-solid fa-list-check text-xs" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="student-kicker">Application readiness</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">Profile and files</h3>
                                        <p class="mt-1 text-sm text-slate-500">What is ready before you apply.</p>
                                    </div>
                                </div>

                                <div class="flex flex-1 flex-col divide-y divide-slate-200">
                                    <a
                                        v-for="item in readinessItems"
                                        :key="item.label"
                                        :href="item.href"
                                        class="group flex flex-1 flex-col justify-center p-4 transition hover:bg-slate-50 sm:p-5"
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-sm font-bold text-slate-900">{{ item.label }}</p>
                                            <span v-if="item.percent !== null" class="text-sm font-bold text-slate-700">{{ item.percent }}%</span>
                                            <span v-else :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', item.statusClass]">{{ item.status }}</span>
                                        </div>
                                        <div v-if="item.percent !== null" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-slate-900" :style="{ width: `${item.percent}%` }"></div>
                                        </div>
                                        <div class="mt-2 flex items-start justify-between gap-3">
                                            <p class="text-xs leading-5 text-slate-500">{{ item.detail }}</p>
                                            <span class="shrink-0 text-xs font-bold text-slate-900 group-hover:underline">{{ item.action }}</span>
                                        </div>
                                    </a>
                                </div>
                            </section>

                            <section class="student-card flex h-full flex-col overflow-hidden xl:col-start-2 xl:row-start-2">
                                <div class="flex min-h-24 items-center gap-3 border-b border-slate-200 p-4 sm:p-5">
                                    <span class="student-section-mark">
                                        <i class="fa-solid fa-bell text-xs" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <p class="student-kicker">Reminders</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">Important updates</h3>
                                        <p class="mt-1 text-sm text-slate-500">Items that may need your attention.</p>
                                    </div>
                                </div>

                                <div class="flex flex-1 flex-col divide-y divide-slate-200">
                                    <a
                                        v-for="reminder in reminders"
                                        :key="reminder.key"
                                        :href="reminder.href"
                                        class="group flex flex-1 items-center gap-3 p-4 transition hover:bg-slate-50 sm:p-5"
                                        @click="openReminder($event, reminder)"
                                    >
                                        <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs', reminder.key === 'clear' ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-800']">
                                            <i :class="reminder.icon" aria-hidden="true"></i>
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-bold text-slate-900">{{ reminder.title }}</span>
                                            <span class="mt-1 line-clamp-2 block text-xs leading-5 text-slate-500">{{ reminder.detail }}</span>
                                        </span>
                                        <i class="fa-solid fa-arrow-right mt-2 text-[10px] text-slate-300 transition group-hover:text-slate-600" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </section>
                        </aside>
                    </div>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
