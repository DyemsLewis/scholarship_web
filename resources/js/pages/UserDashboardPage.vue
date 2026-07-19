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
const nextSteps = ref([]);

const recommendedScholarships = computed(() => [...scholarships.value]
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
    .sort((first, second) => {
        const firstNeedsConfirmation = Number(!first.schedule.applicant_acknowledged);
        const secondNeedsConfirmation = Number(!second.schedule.applicant_acknowledged);

        if (firstNeedsConfirmation !== secondNeedsConfirmation) {
            return secondNeedsConfirmation - firstNeedsConfirmation;
        }

        return scheduleTimestamp(first.schedule) - scheduleTimestamp(second.schedule);
    }));

const nextScheduledActivity = computed(() => scheduledActivities.value[0] ?? null);
const activeApplication = computed(() => nextScheduledActivity.value?.application
    ?? applications.value.find((application) => !isClosedApplication(application))
    ?? null);

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
    const entry = nextScheduledActivity.value;

    if (entry) {
        const { application, schedule } = entry;
        const scholarshipTitle = application.scholarship?.title || 'Scholarship application';
        const providerName = application.scholarship?.provider?.name || 'Scholarship provider';
        const requiresAttention = !schedule.applicant_acknowledged;
        const meta = [
            { icon: 'fa-regular fa-calendar', label: schedule.scheduled_label || 'Date pending' },
            { icon: 'fa-solid fa-location-dot', label: scheduleModeLabel(schedule.mode) },
        ];

        if (schedule.venue) {
            meta.push({ icon: 'fa-solid fa-building', label: schedule.venue });
        }

        if (schedule.type === 'distribution') {
            meta.push({ icon: 'fa-solid fa-peso-sign', label: formatAwardAmount(application.awarded_amount) });
        }

        return {
            key: `schedule-${schedule.id}`,
            eyebrow: scheduleTypeLabel(schedule.type),
            title: schedule.title,
            detail: `${scholarshipTitle} from ${providerName}.`,
            prompt: requiresAttention
                ? 'Read the instructions and confirm that you saw the schedule.'
                : 'Your confirmation is recorded. Keep the schedule details available.',
            href: application.detail_url || `/dashboard/applications/${application.id}`,
            button: requiresAttention ? 'Review and confirm' : 'View schedule',
            icon: scheduleTypeIcon(schedule.type),
            requiresAttention,
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
                { icon: 'fa-solid fa-list-check', label: statusLabel(application.status) },
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
                detail: 'Across your recommended programs.',
                href: '/dashboard/documents',
                action: 'Prepare',
            },
    ];
});

const reminders = computed(() => {
    const items = [];
    const currentScheduleId = nextScheduledActivity.value?.schedule.id;

    scheduledActivities.value
        .filter((entry) => !entry.schedule.applicant_acknowledged && entry.schedule.id !== currentScheduleId)
        .slice(0, 2)
        .forEach((entry) => items.push({
            key: `schedule-${entry.schedule.id}`,
            title: `${scheduleTypeLabel(entry.schedule.type)} needs confirmation`,
            detail: entry.schedule.scheduled_label || 'Open the application for details.',
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

function applicationSchedules(application) {
    return Array.isArray(application?.schedules) ? application.schedules : [];
}

function activeSchedule(application) {
    const schedules = applicationSchedules(application);
    const unacknowledged = schedules.find(
        (schedule) => schedule.status === 'scheduled' && !schedule.applicant_acknowledged,
    );

    return unacknowledged ?? schedules.find((schedule) => schedule.status === 'scheduled') ?? null;
}

function isClosedApplication(application) {
    return ['rejected', 'not_awarded', 'disbursed', 'renewed', 'exam_failed'].includes(application?.status);
}

function applicationPriority(application) {
    const schedule = activeSchedule(application);

    if (schedule && !schedule.applicant_acknowledged) {
        return 100;
    }

    if (schedule) {
        return 90;
    }

    const ranks = {
        exam_passed: 80,
        exam_taken: 75,
        exam_qualified: 70,
        interview: 68,
        shortlisted: 65,
        qualified: 60,
        under_review: 55,
        submitted: 50,
        approved: 45,
        awarded: 40,
        distribution_scheduled: 38,
        disbursed: 20,
        renewed: 15,
        exam_failed: 10,
        rejected: 5,
        not_awarded: 5,
    };

    return ranks[application?.status] ?? 0;
}

function applicationNextAction(application) {
    const schedule = activeSchedule(application);

    if (schedule) {
        return schedule.applicant_acknowledged
            ? `Follow the ${scheduleTypeLabel(schedule.type).toLowerCase()} instructions for ${schedule.scheduled_label}.`
            : `Review and confirm the ${scheduleTypeLabel(schedule.type).toLowerCase()} schedule.`;
    }

    const missingDocuments = application?.document_readiness?.missing?.length ?? 0;

    if (missingDocuments > 0) {
        return `${missingDocuments} required ${missingDocuments === 1 ? 'document is' : 'documents are'} still missing.`;
    }

    return {
        submitted: 'Waiting for the provider to begin reviewing your application.',
        under_review: 'The provider is checking your profile and documents.',
        qualified: 'You passed the initial requirements and remain under review.',
        shortlisted: 'You are shortlisted for closer provider review.',
        interview: 'Watch for interview instructions or a provider decision.',
        exam_qualified: 'Wait for the provider to publish the exam schedule.',
        exam_scheduled: 'Review the posted exam details.',
        exam_taken: 'Wait for the provider to record the exam result.',
        exam_passed: 'You passed the exam and remain under final award review.',
        approved: 'Your application is approved. Wait for the award update.',
        awarded: 'Your award is recorded. Wait for distribution details.',
        distribution_scheduled: 'Review the distribution schedule and instructions.',
        disbursed: 'The provider recorded the scholarship reward as distributed.',
        renewed: 'Your scholarship support was renewed.',
        rejected: 'Review the provider note for the decision.',
        not_awarded: 'The review finished without an award. Check the provider note.',
        exam_failed: 'Review the provider note for the exam result.',
    }[application?.status] ?? 'Open the application for the latest provider update.';
}

function statusLabel(status) {
    const labels = {
        exam_qualified: 'Qualified for exam',
        exam_scheduled: 'Exam scheduled',
        exam_taken: 'Exam taken',
        exam_passed: 'Passed exam',
        exam_failed: 'Failed exam',
        distribution_scheduled: 'Distribution scheduled',
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

    if (['rejected', 'not_awarded', 'exam_failed'].includes(status)) {
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
        screening: 'Application screening',
        exam: 'Scholarship exam',
        interview: 'Interview',
        distribution: 'Award distribution',
    }[type] ?? 'Scheduled activity';
}

function scheduleTypeIcon(type) {
    return {
        screening: 'fa-solid fa-list-check',
        exam: 'fa-solid fa-clipboard-check',
        interview: 'fa-solid fa-comments',
        distribution: 'fa-solid fa-hand-holding-heart',
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

function formatAwardAmount(value) {
    if (value === null || value === undefined || value === '') {
        return 'Amount not listed';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(value));
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

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/data');

        user.value = response.data.user;
        profileReadiness.value = response.data.profile_readiness ?? profileReadiness.value;
        scholarships.value = response.data.scholarships ?? [];
        applications.value = response.data.applications ?? [];
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
                    :title="`Welcome back, ${user?.first_name || 'Scholar'}`"
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

                    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
                        <div class="space-y-5 xl:contents xl:space-y-0">
                            <section class="student-card p-4 sm:p-5 xl:col-start-1 xl:row-start-1">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Your applications</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">Recent progress</h3>
                                    </div>
                                    <a href="/dashboard/applications" class="w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-slate-500">
                                        View all
                                    </a>
                                </div>

                                <div v-if="visibleApplications.length" class="mt-4 grid gap-3">
                                    <article
                                        v-for="application in visibleApplications"
                                        :key="application.id"
                                        class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                                    >
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                            <div class="flex min-w-0 flex-1 gap-3">
                                                <img
                                                    :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                                    :alt="application.scholarship?.title || 'Scholarship application'"
                                                    class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                                >
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                                        <div class="min-w-0">
                                                            <h4 class="truncate text-sm font-bold text-slate-950">
                                                                {{ application.scholarship?.title || 'Scholarship application' }}
                                                            </h4>
                                                            <p class="mt-1 truncate text-xs text-slate-500">
                                                                {{ application.scholarship?.provider?.name || 'Scholarship provider' }}
                                                            </p>
                                                        </div>
                                                        <span :class="['w-fit rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(application.status)]">
                                                            {{ statusLabel(application.status) }}
                                                        </span>
                                                    </div>
                                                    <p class="mt-2 text-sm leading-5 text-slate-600">
                                                        {{ applicationNextAction(application) }}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <div
                                            v-if="activeSchedule(application)"
                                            :class="[
                                                'mt-3 flex flex-col gap-2 rounded-md border px-3 py-2.5 text-xs sm:flex-row sm:items-center sm:justify-between',
                                                activeSchedule(application).applicant_acknowledged
                                                    ? 'border-slate-200 bg-white text-slate-700'
                                                    : 'border-amber-200 bg-amber-50 text-amber-900',
                                            ]"
                                        >
                                            <div class="flex min-w-0 items-center gap-2">
                                                <i :class="scheduleTypeIcon(activeSchedule(application).type)" aria-hidden="true"></i>
                                                <span class="truncate font-bold">
                                                    {{ scheduleTypeLabel(activeSchedule(application).type) }}: {{ activeSchedule(application).scheduled_label }}
                                                </span>
                                            </div>
                                            <span class="shrink-0 font-bold">
                                                {{ activeSchedule(application).applicant_acknowledged ? 'Confirmed' : 'Confirmation needed' }}
                                            </span>
                                        </div>

                                        <div class="mt-3 flex flex-col gap-3 border-t border-slate-200 pt-3 sm:flex-row sm:items-center">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center justify-between gap-3 text-xs font-semibold text-slate-500">
                                                    <span>Application files</span>
                                                    <span>{{ application.document_readiness?.percent ?? 0 }}%</span>
                                                </div>
                                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                                    <div class="h-full rounded-full bg-slate-900" :style="{ width: `${application.document_readiness?.percent ?? 0}%` }"></div>
                                                </div>
                                            </div>
                                            <a
                                                :href="application.detail_url || `/dashboard/applications/${application.id}`"
                                                class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-xs font-bold text-slate-700 transition hover:border-slate-500"
                                            >
                                                Open application
                                            </a>
                                        </div>
                                    </article>
                                </div>

                                <div v-else class="mt-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-5">
                                    <p class="text-sm font-bold text-slate-900">No submitted applications yet</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">Start with a recommended scholarship when your profile is ready.</p>
                                    <a href="/dashboard/scholarships" class="mt-3 inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-bold text-white">
                                        Find scholarships
                                    </a>
                                </div>
                            </section>

                            <section class="student-card p-4 sm:p-5 xl:col-start-1 xl:row-start-2">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="student-kicker">Recommended</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">Strong matches for you</h3>
                                    </div>
                                    <a href="/dashboard/scholarships" class="w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-slate-500">
                                        Browse all
                                    </a>
                                </div>

                                <div v-if="recommendedScholarships.length" class="mt-4 grid gap-3 lg:grid-cols-3">
                                    <article
                                        v-for="scholarship in recommendedScholarships"
                                        :key="scholarship.id"
                                        class="flex min-w-0 flex-col rounded-lg border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <div class="flex min-w-0 items-start gap-3">
                                            <img
                                                :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                                :alt="scholarship.title"
                                                class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                            >
                                            <div class="min-w-0 flex-1">
                                                <h4 class="line-clamp-2 min-h-10 text-sm font-bold leading-5 text-slate-950">
                                                    {{ scholarship.title }}
                                                </h4>
                                                <p class="mt-1 truncate text-xs text-slate-500">
                                                    {{ scholarship.provider?.name || 'Scholarship provider' }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="mt-auto flex flex-wrap gap-2 pt-3 text-xs font-bold">
                                            <span class="rounded-md bg-slate-900 px-2.5 py-1 text-white">
                                                {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                            </span>
                                            <span class="rounded-md bg-white px-2.5 py-1 text-slate-600 ring-1 ring-slate-200">
                                                {{ scholarship.deadline || 'Open deadline' }}
                                            </span>
                                        </div>

                                        <a
                                            :href="`/dashboard/scholarships/${scholarship.id}`"
                                            class="mt-3 inline-flex items-center justify-between rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-800 transition hover:border-slate-500"
                                        >
                                            View scholarship
                                            <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                                        </a>
                                    </article>
                                </div>

                                <div v-else class="mt-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-500">
                                    No published scholarships are available yet.
                                </div>
                            </section>
                        </div>

                        <aside class="space-y-4 xl:contents xl:space-y-0">
                            <section class="student-card p-4 xl:col-start-2 xl:row-start-1">
                                <p class="student-kicker">Readiness</p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">Profile and files</h3>

                                <div class="mt-4 grid gap-4">
                                    <div v-for="item in readinessItems" :key="item.label">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-sm font-bold text-slate-900">{{ item.label }}</p>
                                            <span class="text-sm font-bold text-slate-700">{{ item.percent }}%</span>
                                        </div>
                                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-slate-900" :style="{ width: `${item.percent}%` }"></div>
                                        </div>
                                        <div class="mt-2 flex items-start justify-between gap-3">
                                            <p class="text-xs leading-5 text-slate-500">{{ item.detail }}</p>
                                            <a :href="item.href" class="shrink-0 text-xs font-bold text-slate-900 hover:underline">
                                                {{ item.action }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="student-card p-4 xl:col-start-2 xl:row-start-2">
                                <div class="flex items-center gap-3">
                                    <span class="student-section-mark">
                                        <i class="fa-solid fa-bell text-xs" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <p class="student-kicker">Reminders</p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-950">Important updates</h3>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-2">
                                    <a
                                        v-for="reminder in reminders"
                                        :key="reminder.key"
                                        :href="reminder.href"
                                        class="group flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 transition hover:border-slate-400 hover:bg-white"
                                    >
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-slate-900 text-xs text-white">
                                            <i :class="reminder.icon" aria-hidden="true"></i>
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-bold text-slate-900">{{ reminder.title }}</span>
                                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ reminder.detail }}</span>
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
