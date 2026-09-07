<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { labelFromKey } from '../support/display';

const scholarshipId = document.getElementById('app')?.dataset.scholarshipId;
const scholarship = ref(null);
const isLoading = ref(true);
const isDuplicating = ref(false);
const errorMessage = ref('');
const showMap = ref(false);
const showAnnouncementComposer = ref(false);
const isPublishingAnnouncement = ref(false);
const announcementError = ref('');
const announcementForm = ref({
    audience: 'active_applicants',
    title: '',
    message: '',
});
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const canManagePrograms = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('manage_programs'),
));
const canReviewApplications = computed(() => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes('review_applications'),
));
const providerIsApproved = computed(() => Boolean(window.portalUser?.can_post_scholarships));
const canAccessApplicantWorkspace = computed(() => (
    canReviewApplications.value && providerIsApproved.value
));
const canSendAnnouncements = computed(() => (
    canReviewApplications.value && providerIsApproved.value
));
const announcements = computed(() => scholarship.value?.announcements ?? []);
const announcementAudiences = [
    { value: 'active_applicants', label: 'All active applicants', help: 'Everyone whose application is still active.' },
    { value: 'under_review', label: 'Applicants under review', help: 'Applicants still completing pre-screening.' },
    { value: 'qualified_applicants', label: 'Qualified and waitlisted', help: 'Applicants who passed pre-screening, including alternates.' },
    { value: 'selected_recipients', label: 'Selected recipients', help: 'Applicants already recorded as scholarship recipients.' },
];
const selectedAudienceHelp = computed(() => announcementAudiences.find(
    (audience) => audience.value === announcementForm.value.audience,
)?.help ?? '');
const mapAddress = computed(() => [
    scholarship.value?.location_address,
    scholarship.value?.location_name,
    'Philippines',
].filter(Boolean).join(', '));
const hasMap = computed(() => Boolean(
    scholarship.value?.latitude
        || scholarship.value?.longitude
        || scholarship.value?.location_address
        || scholarship.value?.location_name,
));
const selectedCount = computed(() => Number(scholarship.value?.awarded_slots_count ?? 0));
const slotCapacity = computed(() => Number(scholarship.value?.slots_available ?? 0));
const slotUsagePercent = computed(() => {
    if (slotCapacity.value <= 0) return 0;

    return Math.min(100, Math.round((selectedCount.value / slotCapacity.value) * 100));
});
const locationLabel = computed(() => scholarship.value?.location_name
    || scholarship.value?.location_address
    || 'Location not listed');
const workflowCounts = computed(() => scholarship.value?.workflow_counts ?? {});
const activityStatuses = computed(() => scholarship.value?.activity_statuses ?? []);
const applicantWorkspaceUrl = computed(() => `/provider/programs/${scholarshipId}/applications`);
const workflowQueues = computed(() => [
    {
        key: 'needs_review',
        label: 'Needs review',
        description: 'Check profile, eligibility, and files.',
        icon: 'fa-solid fa-file-circle-check',
        count: Number(workflowCounts.value.needs_review ?? 0),
        href: `${applicantWorkspaceUrl.value}?filter=needs_review`,
    },
    {
        key: 'waiting_activity',
        label: 'Waiting for activity',
        description: 'Exam or interview is pending.',
        icon: 'fa-regular fa-calendar',
        count: Number(workflowCounts.value.waiting_activity ?? 0),
        href: `${applicantWorkspaceUrl.value}?filter=waiting_activity&workspace=schedule`,
    },
    {
        key: 'ready_result',
        label: 'Ready for result',
        description: 'Record the completed stage result.',
        icon: 'fa-solid fa-clipboard-check',
        count: Number(workflowCounts.value.ready_result ?? 0),
        href: `${applicantWorkspaceUrl.value}?filter=ready_result`,
    },
    {
        key: 'final_decision',
        label: 'Final decision',
        description: 'Select, waitlist, or decline.',
        icon: 'fa-solid fa-gavel',
        count: Number(workflowCounts.value.final_decision ?? 0),
        href: `${applicantWorkspaceUrl.value}?filter=final_decision`,
    },
]);
const openTaskCount = computed(() => workflowQueues.value.reduce((total, queue) => total + queue.count, 0));
const recommendedAction = computed(() => {
    const program = scholarship.value;

    if (!program) return null;

    if (['draft', 'rejected'].includes(program.status)) {
        return {
            eyebrow: program.status === 'rejected' ? 'Changes required' : 'Finish setup',
            title: program.status === 'rejected' ? 'Update and resubmit this program' : 'Complete the program details',
            description: statusGuidance(program.status),
            label: 'Open program setup',
            href: `/provider/programs/${program.id}/edit`,
        };
    }

    if (program.status === 'pending_review') {
        return {
            eyebrow: 'Admin review',
            title: 'Program submission is being reviewed',
            description: statusGuidance(program.status),
            label: 'View all programs',
            href: '/provider/programs?status=pending_review',
        };
    }

    if (!canAccessApplicantWorkspace.value) {
        return !providerIsApproved.value
            ? {
                eyebrow: 'Provider verification',
                title: 'Complete organization verification',
                description: 'Applicant records become available after the provider account is approved.',
                label: 'View verification',
                href: '/provider/profile#verification-documents',
            }
            : {
                eyebrow: 'Program status',
                title: 'Applicant review is assigned to another team role',
                description: 'You can still maintain the program details available to your role.',
                label: canManagePrograms.value ? 'Open program setup' : 'View all programs',
                href: canManagePrograms.value ? `/provider/programs/${program.id}/edit` : '/provider/programs',
            };
    }

    const reviewQueue = workflowQueues.value.find((queue) => queue.key === 'needs_review' && queue.count > 0);
    if (reviewQueue) {
        return { eyebrow: 'Recommended next', title: `Review ${reviewQueue.count} applicant${reviewQueue.count === 1 ? '' : 's'}`, description: reviewQueue.description, label: 'Start reviewing', href: reviewQueue.href };
    }

    const missingActivity = activityStatuses.value.find((activity) => (
        Number(activity.waiting_applicants ?? 0) > 0 && activity.event?.status !== 'scheduled'
    ));
    if (missingActivity) {
        return {
            eyebrow: 'Schedule needed',
            title: `Publish the ${String(missingActivity.label).toLowerCase()} details`,
            description: `${missingActivity.waiting_applicants} applicant${missingActivity.waiting_applicants === 1 ? '' : 's'} reached this stage and need the shared activity information.`,
            label: 'Set activity',
            href: `${applicantWorkspaceUrl.value}?workspace=schedule&filter=waiting_activity`,
        };
    }

    const resultQueue = workflowQueues.value.find((queue) => queue.key === 'ready_result' && queue.count > 0);
    if (resultQueue) {
        return { eyebrow: 'Recommended next', title: `Record ${resultQueue.count} stage result${resultQueue.count === 1 ? '' : 's'}`, description: resultQueue.description, label: 'Review results', href: resultQueue.href };
    }

    const decisionQueue = workflowQueues.value.find((queue) => queue.key === 'final_decision' && queue.count > 0);
    if (decisionQueue) {
        return { eyebrow: 'Recommended next', title: `Complete ${decisionQueue.count} final decision${decisionQueue.count === 1 ? '' : 's'}`, description: decisionQueue.description, label: 'Record decisions', href: decisionQueue.href };
    }

    const waitingQueue = workflowQueues.value.find((queue) => queue.key === 'waiting_activity' && queue.count > 0);
    if (waitingQueue) {
        return { eyebrow: 'Activity in progress', title: `${waitingQueue.count} applicant${waitingQueue.count === 1 ? '' : 's'} waiting for an activity`, description: 'The shared schedule is published. Return after the activity to record results.', label: 'View activities', href: waitingQueue.href };
    }

    if (Number(workflowCounts.value.all ?? 0) > 0) {
        return { eyebrow: 'Program records', title: 'No applicant action is due', description: 'Review completed and active records whenever you need them.', label: 'View all records', href: `${applicantWorkspaceUrl.value}?filter=all` };
    }

    return {
        eyebrow: 'Program is ready',
        title: 'Waiting for the first applicant',
        description: statusGuidance(program.status),
        label: canManagePrograms.value ? 'Review public details' : 'View program status',
        href: canManagePrograms.value ? `/provider/programs/${program.id}/edit` : '/provider/programs',
    };
});

function statusLabel(status) {
    return {
        draft: 'Draft',
        pending_review: 'In admin review',
        published: 'Published',
        rejected: 'Needs changes',
        closed: 'Closed',
    }[status] ?? labelFromKey(status || 'draft');
}

function statusClass(status) {
    if (status === 'published') return 'bg-emerald-100 text-emerald-800';
    if (status === 'pending_review') return 'bg-sky-100 text-sky-800';
    if (status === 'rejected') return 'bg-rose-100 text-rose-800';
    if (status === 'closed') return 'bg-slate-200 text-slate-700';

    return 'bg-amber-100 text-amber-800';
}

function statusGuidance(status) {
    return {
        draft: 'Complete the setup and submit this program for administrator review.',
        pending_review: 'An administrator is reviewing this program before it can be published.',
        published: 'This program is visible to applicants and can receive applications.',
        rejected: 'Review the administrator feedback, update the program, and submit it again.',
        closed: 'This program is no longer receiving new applications.',
    }[status] ?? 'Review the program setup and applicant activity.';
}

function dateLabel(value) {
    if (!value) return 'No deadline';

    const parsed = new Date(`${value}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return value;

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(parsed);
}

function targetLabel(program) {
    const levels = String(program?.eligible_education_levels ?? '')
        .split(/\r?\n|,/)
        .map((item) => item.trim())
        .filter(Boolean);

    if (!levels.length || levels.length >= 7) return 'All learners';

    return levels.slice(0, 2).map(labelFromKey).join(', ')
        + (levels.length > 2 ? ` +${levels.length - 2}` : '');
}

function activityState(activity) {
    if (!activity.event) return 'Not scheduled';
    if (activity.event.status === 'completed') return 'Completed';

    return activity.event.scheduled_label || 'Scheduled';
}

function activityStateClass(activity) {
    if (!activity.event) return 'bg-amber-100 text-amber-800';
    if (activity.event.status === 'completed') return 'bg-emerald-100 text-emerald-800';

    return 'bg-sky-100 text-sky-800';
}

async function loadProgram() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/scholarships/${scholarshipId}`);
        scholarship.value = response.data.scholarship;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load this program.';
    } finally {
        isLoading.value = false;
    }

    if (scholarship.value && window.location.hash) {
        await nextTick();
        document.getElementById(window.location.hash.slice(1))?.scrollIntoView({ block: 'start' });
    }
}

async function duplicateProgram() {
    const confirmed = await requestConfirmation({
        title: 'Duplicate this program?',
        message: `A new draft copy of ${scholarship.value.title} will be added to your program list.`,
        confirmLabel: 'Duplicate program',
    });

    if (!confirmed) return;

    isDuplicating.value = true;

    try {
        const response = await window.axios.post(`/provider/scholarships/${scholarshipId}/duplicate`);
        window.location.assign(`/provider/programs/${response.data.scholarship.id}/edit`);
    } finally {
        isDuplicating.value = false;
    }
}

async function publishAnnouncement() {
    if (isPublishingAnnouncement.value) return;

    announcementError.value = '';
    isPublishingAnnouncement.value = true;

    try {
        const response = await window.axios.post(`/provider/scholarships/${scholarshipId}/announcements`, {
            audience: announcementForm.value.audience,
            title: announcementForm.value.title.trim(),
            message: announcementForm.value.message.trim(),
        });

        scholarship.value.announcements = [
            response.data.announcement,
            ...announcements.value,
        ];
        announcementForm.value = {
            audience: 'active_applicants',
            title: '',
            message: '',
        };
        showAnnouncementComposer.value = false;
    } catch (error) {
        announcementError.value = error.response?.data?.errors?.audience?.[0]
            ?? error.response?.data?.errors?.title?.[0]
            ?? error.response?.data?.errors?.message?.[0]
            ?? error.response?.data?.message
            ?? 'Unable to publish this announcement.';
    } finally {
        isPublishingAnnouncement.value = false;
    }
}

onMounted(loadProgram);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />
        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="provider-page">
            <div class="provider-container">
                <nav class="flex min-w-0 items-center gap-2 text-sm" aria-label="Breadcrumb">
                    <a href="/provider/programs" class="font-bold text-slate-600 transition hover:text-slate-950">Programs</a>
                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-400" aria-hidden="true"></i>
                    <span class="truncate font-semibold text-slate-950">{{ scholarship?.title || 'Program workspace' }}</span>
                </nav>

                <div v-if="isLoading" class="mt-5 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading program workspace...
                </div>
                <div v-else-if="errorMessage" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <template v-else-if="scholarship">
                    <section class="provider-panel mt-5 overflow-hidden">
                        <header class="relative overflow-hidden bg-[#081426] px-5 py-5 text-white sm:px-6">
                            <div class="pointer-events-none absolute -right-16 -top-24 h-64 w-64 rounded-full border-[42px] border-amber-300/10"></div>
                            <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex min-w-0 items-center gap-4">
                                    <img :src="scholarship.image_url" :alt="scholarship.title" class="h-14 w-14 shrink-0 rounded-md bg-white object-contain p-2 shadow-sm ring-1 ring-white/20">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-300">Program control center</p>
                                            <span :class="['rounded-md px-2 py-1 text-[9px] font-bold uppercase', statusClass(scholarship.status)]">{{ statusLabel(scholarship.status) }}</span>
                                        </div>
                                        <h1 class="mt-1.5 font-display text-2xl font-bold leading-tight sm:text-3xl">{{ scholarship.title }}</h1>
                                        <p class="mt-1 text-sm font-semibold text-slate-300">{{ scholarship.category || 'Scholarship program' }} · {{ targetLabel(scholarship) }}</p>
                                    </div>
                                </div>
                                <a v-if="canManagePrograms" :href="`/provider/programs/${scholarship.id}/edit`" class="inline-flex shrink-0 items-center justify-center rounded-md border border-white/20 bg-white px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-300">
                                    <i class="fa-solid fa-pen mr-2 text-xs" aria-hidden="true"></i>
                                    Edit program
                                </a>
                            </div>
                        </header>

                        <dl class="grid grid-cols-2 gap-px bg-slate-200 lg:grid-cols-4">
                            <div class="bg-white px-5 py-4">
                                <dt class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500"><i class="fa-regular fa-calendar text-amber-700" aria-hidden="true"></i>Deadline</dt>
                                <dd class="mt-1.5 text-sm font-bold text-slate-950">{{ dateLabel(scholarship.deadline) }}</dd>
                            </div>
                            <div class="bg-white px-5 py-4">
                                <dt class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500"><i :class="[canAccessApplicantWorkspace ? 'fa-solid fa-users' : 'fa-solid fa-eye', 'text-amber-700']" aria-hidden="true"></i>{{ canAccessApplicantWorkspace ? 'Applicants' : 'Visibility' }}</dt>
                                <dd class="mt-1.5 text-sm font-bold text-slate-950">{{ canAccessApplicantWorkspace ? `${scholarship.applications_count ?? 0} total` : statusLabel(scholarship.status) }}</dd>
                            </div>
                            <div class="bg-white px-5 py-4">
                                <dt class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500"><i :class="[canAccessApplicantWorkspace ? 'fa-solid fa-list-check' : 'fa-solid fa-user-lock', 'text-amber-700']" aria-hidden="true"></i>{{ canAccessApplicantWorkspace ? 'Open tasks' : 'Your access' }}</dt>
                                <dd class="mt-1.5 text-sm font-bold text-slate-950">{{ canAccessApplicantWorkspace ? `${openTaskCount} applicant actions` : (canManagePrograms ? 'Program setup' : 'View program') }}</dd>
                            </div>
                            <div class="bg-white px-5 py-4">
                                <dt class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500"><i :class="[canAccessApplicantWorkspace ? 'fa-solid fa-user-check' : 'fa-regular fa-clock', 'text-amber-700']" aria-hidden="true"></i>{{ canAccessApplicantWorkspace ? 'Selected' : 'Updated' }}</dt>
                                <dd class="mt-1.5 text-sm font-bold text-slate-950">{{ canAccessApplicantWorkspace ? `${selectedCount}${slotCapacity > 0 ? ` of ${slotCapacity}` : ''}` : (scholarship.updated_at || 'Recently') }}</dd>
                                <div v-if="canAccessApplicantWorkspace && slotCapacity > 0" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-amber-400" :style="{ width: `${slotUsagePercent}%` }"></div></div>
                            </div>
                        </dl>
                    </section>

                    <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                        <section v-if="recommendedAction" class="overflow-hidden rounded-lg border border-amber-200 bg-amber-50 shadow-sm">
                            <div class="flex h-full flex-col justify-between gap-5 p-5 sm:flex-row sm:items-center">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-200 text-amber-900"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">{{ recommendedAction.eyebrow }}</p>
                                        <h2 class="mt-1 text-lg font-bold text-slate-950">{{ recommendedAction.title }}</h2>
                                        <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">{{ recommendedAction.description }}</p>
                                    </div>
                                </div>
                                <a :href="recommendedAction.href" class="inline-flex w-fit shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                                    {{ recommendedAction.label }}
                                    <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                </a>
                            </div>
                        </section>

                        <section class="provider-panel p-5">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Quick actions</p>
                            <div class="mt-3 grid gap-2">
                                <a v-if="canAccessApplicantWorkspace" :href="`${applicantWorkspaceUrl}?filter=all`" class="flex items-center justify-between rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-800 transition hover:border-slate-400 hover:bg-white">
                                    All applicant records <i class="fa-solid fa-arrow-right text-xs text-slate-400" aria-hidden="true"></i>
                                </a>
                                <a href="#announcements" class="flex items-center justify-between rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-800 transition hover:border-slate-400 hover:bg-white">
                                    Applicant updates <i class="fa-solid fa-bullhorn text-xs text-slate-400" aria-hidden="true"></i>
                                </a>
                                <button v-if="canManagePrograms" type="button" :disabled="isDuplicating" class="flex items-center justify-between rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-left text-sm font-bold text-slate-800 transition hover:border-slate-400 hover:bg-white disabled:opacity-60" @click="duplicateProgram">
                                    {{ isDuplicating ? 'Duplicating...' : 'Duplicate as draft' }} <i class="fa-regular fa-copy text-xs text-slate-400" aria-hidden="true"></i>
                                </button>
                            </div>
                        </section>
                    </div>

                    <section v-if="canAccessApplicantWorkspace || !providerIsApproved" class="provider-panel mt-4 overflow-hidden">
                        <header class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between sm:px-6">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Applicant workflow</p>
                                <h2 class="mt-1 text-xl font-bold text-slate-950">Work by next action</h2>
                                <p class="mt-1 text-sm text-slate-600">Open only the applicants who need the same task.</p>
                            </div>
                            <a v-if="canAccessApplicantWorkspace" :href="`${applicantWorkspaceUrl}?filter=all`" class="text-xs font-bold text-slate-600 transition hover:text-slate-950">View all records <i class="fa-solid fa-arrow-right ml-1" aria-hidden="true"></i></a>
                        </header>

                        <div v-if="canAccessApplicantWorkspace" class="grid gap-px bg-slate-200 sm:grid-cols-2 xl:grid-cols-4">
                            <a v-for="queue in workflowQueues" :key="queue.key" :href="queue.href" class="group flex min-h-32 flex-col bg-white p-4 transition hover:bg-slate-50">
                                <span class="flex items-start justify-between gap-3">
                                    <span class="grid h-9 w-9 place-items-center rounded-md bg-slate-100 text-slate-700"><i :class="queue.icon" aria-hidden="true"></i></span>
                                    <span :class="['rounded-md px-2.5 py-1 text-sm font-bold', queue.count > 0 ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-500']">{{ queue.count }}</span>
                                </span>
                                <span class="mt-3 text-sm font-bold text-slate-950">{{ queue.label }}</span>
                                <span class="mt-1 text-xs leading-5 text-slate-500">{{ queue.description }}</span>
                                <span class="mt-auto pt-3 text-xs font-bold text-slate-700 group-hover:text-slate-950">Open queue <i class="fa-solid fa-arrow-right ml-1 text-[10px]" aria-hidden="true"></i></span>
                            </a>
                        </div>
                        <div v-else class="px-5 py-5 text-sm text-slate-600 sm:px-6">
                            <p v-if="!providerIsApproved" class="font-semibold text-amber-800">Provider verification is required before applicant records become available. <a href="/provider/profile#verification-documents" class="font-bold text-slate-900 hover:underline">View verification</a></p>
                        </div>
                    </section>

                    <section v-if="canAccessApplicantWorkspace && activityStatuses.length" class="provider-panel mt-4 overflow-hidden">
                        <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Program activities</p>
                            <h2 class="mt-1 text-xl font-bold text-slate-950">Exam and interview status</h2>
                            <p class="mt-1 text-sm text-slate-600">Publish one shared schedule, complete the activity, then record individual results.</p>
                        </header>
                        <div class="divide-y divide-slate-200">
                            <article v-for="activity in activityStatuses" :key="activity.type" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700"><i :class="activity.type === 'exam' ? 'fa-solid fa-clipboard-question' : 'fa-solid fa-comments'" aria-hidden="true"></i></span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-bold text-slate-950">{{ activity.label }}</h3>
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', activityStateClass(activity)]">{{ activityState(activity) }}</span>
                                        </div>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            {{ activity.active_applicants }} currently at this stage
                                            <span v-if="activity.waiting_applicants"> · {{ activity.waiting_applicants }} waiting for completion</span>
                                        </p>
                                    </div>
                                </div>
                                <a v-if="canAccessApplicantWorkspace" :href="`${applicantWorkspaceUrl}?workspace=schedule&filter=waiting_activity`" class="inline-flex w-fit shrink-0 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50">Manage activity <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
                            </article>
                        </div>
                    </section>

                    <section class="provider-panel mt-4 overflow-hidden">
                        <div class="grid md:grid-cols-2">
                            <div class="px-5 py-5 sm:px-6 md:border-r md:border-slate-200">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Program summary</p>
                                <h2 class="mt-1 text-base font-bold text-slate-950">About the program</h2>
                                <p class="mt-2 line-clamp-3 text-sm leading-6 text-slate-600">{{ scholarship.description || 'No description has been added.' }}</p>
                            </div>
                            <div class="border-t border-slate-200 px-5 py-5 sm:px-6 md:border-l-0 md:border-t-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Applicant support</p>
                                <h2 class="mt-1 text-base font-bold text-slate-950">Support package</h2>
                                <p class="mt-2 line-clamp-3 text-sm font-semibold leading-6 text-slate-700">{{ scholarship.benefit_summary || 'No benefit summary has been added.' }}</p>
                            </div>
                        </div>
                        <div v-if="hasMap" class="border-t border-slate-200 px-5 py-4 sm:px-6">
                            <button v-if="hasMap" type="button" class="flex min-w-0 items-center gap-3 text-left" @click="showMap = true">
                                <i class="fa-solid fa-location-dot shrink-0 text-amber-700" aria-hidden="true"></i>
                                <span class="min-w-0">
                                    <span class="block text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Program location</span>
                                    <span class="mt-0.5 block truncate text-sm font-bold text-slate-900">{{ locationLabel }}</span>
                                </span>
                                <span class="hidden text-xs font-bold text-slate-500 sm:inline">View map <i class="fa-solid fa-arrow-right ml-1" aria-hidden="true"></i></span>
                            </button>
                        </div>
                    </section>

                    <section id="announcements" class="provider-panel mt-4 scroll-mt-5 overflow-hidden">
                        <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Program communication</p>
                                <h2 class="mt-1 text-xl font-bold text-slate-950">Applicant announcements</h2>
                                <p class="mt-1 text-sm text-slate-600">Send one update to applicants in the selected program stage.</p>
                            </div>
                            <button
                                v-if="canSendAnnouncements && !showAnnouncementComposer"
                                type="button"
                                class="inline-flex w-fit items-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="showAnnouncementComposer = true"
                            >
                                <i class="fa-solid fa-bullhorn text-xs" aria-hidden="true"></i>
                                New announcement
                            </button>
                        </header>

                        <form v-if="showAnnouncementComposer" class="border-b border-slate-200 bg-slate-50 p-5 sm:p-6" @submit.prevent="publishAnnouncement">
                            <p v-if="announcementError" class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                                {{ announcementError }}
                            </p>
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                                <label class="block">
                                    <span class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Announcement title</span>
                                    <input v-model="announcementForm.title" type="text" maxlength="120" required placeholder="Example: Interview schedule reminder" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-slate-600 focus:ring-3 focus:ring-slate-100">
                                </label>
                                <label class="block">
                                    <span class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Send to</span>
                                    <select v-model="announcementForm.audience" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-slate-600 focus:ring-3 focus:ring-slate-100">
                                        <option v-for="audience in announcementAudiences" :key="audience.value" :value="audience.value">{{ audience.label }}</option>
                                    </select>
                                    <span class="mt-1.5 block text-xs leading-5 text-slate-500">{{ selectedAudienceHelp }}</span>
                                </label>
                                <label class="block lg:col-span-2">
                                    <span class="mb-2 block text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Message</span>
                                    <textarea v-model="announcementForm.message" rows="4" maxlength="2000" required placeholder="Write the update, instructions, or reminder applicants should receive." class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 text-slate-900 outline-none focus:border-slate-600 focus:ring-3 focus:ring-slate-100"></textarea>
                                </label>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="submit" :disabled="isPublishingAnnouncement" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                                    {{ isPublishingAnnouncement ? 'Publishing...' : 'Publish announcement' }}
                                </button>
                                <button type="button" :disabled="isPublishingAnnouncement" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60" @click="showAnnouncementComposer = false; announcementError = ''">
                                    Cancel
                                </button>
                            </div>
                        </form>

                        <div v-if="announcements.length" class="divide-y divide-slate-200">
                            <article v-for="announcement in announcements" :key="announcement.id" class="px-5 py-4 sm:px-6">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-bold text-slate-950">{{ announcement.title }}</h3>
                                            <span class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">{{ announcement.audience_label }}</span>
                                        </div>
                                        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ announcement.message }}</p>
                                    </div>
                                    <div class="shrink-0 text-xs font-semibold text-slate-500 sm:text-right">
                                        <p>{{ announcement.recipient_count }} recipient{{ announcement.recipient_count === 1 ? '' : 's' }}</p>
                                        <p class="mt-1">{{ announcement.published_at }}</p>
                                        <p v-if="announcement.publisher" class="mt-1">By {{ announcement.publisher }}</p>
                                    </div>
                                </div>
                            </article>
                        </div>
                        <div v-else class="px-5 py-8 text-center sm:px-6">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500"><i class="fa-regular fa-bell" aria-hidden="true"></i></span>
                            <p class="mt-3 text-sm font-bold text-slate-800">No announcements yet</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Published updates will remain here for provider reference.</p>
                        </div>
                    </section>
                </template>

                <ProviderFooter />
            </div>
        </section>

        <div v-if="showMap && scholarship" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6" @click.self="showMap = false">
            <section class="max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-lg bg-white shadow-2xl">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Program location</p>
                        <h2 class="mt-1 text-xl font-bold text-slate-950">{{ scholarship.location_name || scholarship.title }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ scholarship.location_address || 'No address listed.' }}</p>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-300 text-slate-600 hover:bg-slate-100" aria-label="Close map" @click="showMap = false">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="bg-slate-100 p-4">
                    <LeafletMapPreview :address="mapAddress" :latitude="scholarship.latitude" :longitude="scholarship.longitude" :title="scholarship.location_name || scholarship.title" :marker-text="scholarship.location_name || scholarship.title" height="55vh" auto-geocode />
                </div>
            </section>
        </div>
    </main>
</template>
