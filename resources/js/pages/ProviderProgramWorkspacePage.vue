<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderProgramNav from '../components/ProviderProgramNav.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';
import { labelFromKey } from '../support/display';

const scholarshipId = document.getElementById('app')?.dataset.scholarshipId;
const isUpdatesView = window.location.pathname.replace(/\/$/, '').endsWith('/updates')
    || window.location.hash === '#announcements';
const scholarship = ref(null);
const isLoading = ref(true);
const isDuplicating = ref(false);
const errorMessage = ref('');
const showAnnouncementComposer = ref(false);
const isPublishingAnnouncement = ref(false);
const announcementError = ref('');
const announcementTitleInput = ref(null);
const selectedAnnouncement = ref(null);
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

async function openAnnouncementComposer() {
    announcementError.value = '';
    showAnnouncementComposer.value = true;
    await nextTick();
    announcementTitleInput.value?.focus();
}

function closeAnnouncementComposer() {
    if (isPublishingAnnouncement.value) return;

    announcementError.value = '';
    showAnnouncementComposer.value = false;
}

function openAnnouncement(announcement) {
    selectedAnnouncement.value = announcement;
}

function closeAnnouncement() {
    selectedAnnouncement.value = null;
}
const selectedCount = computed(() => Number(scholarship.value?.awarded_slots_count ?? 0));
const slotCapacity = computed(() => Number(scholarship.value?.slots_available ?? 0));
const slotUsagePercent = computed(() => {
    if (slotCapacity.value <= 0) return 0;

    return Math.min(100, Math.round((selectedCount.value / slotCapacity.value) * 100));
});
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
        href: `${applicantWorkspaceUrl.value}/review`,
    },
    {
        key: 'waiting_activity',
        label: 'Waiting for activity',
        description: 'Publish or complete an exam or interview.',
        icon: 'fa-regular fa-calendar',
        count: Number(workflowCounts.value.waiting_activity ?? 0),
        href: `${applicantWorkspaceUrl.value}/activities`,
    },
    {
        key: 'ready_result',
        label: 'Ready for result',
        description: 'Record the completed stage result.',
        icon: 'fa-solid fa-clipboard-check',
        count: Number(workflowCounts.value.ready_result ?? 0),
        href: `${applicantWorkspaceUrl.value}/results`,
    },
    {
        key: 'final_decision',
        label: 'Final decision',
        description: 'Select, waitlist, or decline.',
        icon: 'fa-solid fa-gavel',
        count: Number(workflowCounts.value.final_decision ?? 0),
        href: `${applicantWorkspaceUrl.value}/decisions`,
    },
]);
const recommendedAction = computed(() => {
    const program = scholarship.value;

    if (!program) return null;

    if (['draft', 'rejected'].includes(program.status)) {
        return {
            eyebrow: program.status === 'rejected' ? 'Changes required' : 'Finish setup',
            title: program.status === 'rejected' ? 'Update and resubmit this program' : 'Complete the program details',
            description: statusGuidance(program.status),
            label: 'Return to programs',
            href: '/provider/programs?status=drafts',
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
                href: '/provider/profile/verification',
            }
            : {
                eyebrow: 'Program status',
                title: 'Applicant review is assigned to another team role',
                description: 'You can still maintain the program details available to your role.',
                label: 'View all programs',
                href: '/provider/programs',
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
            href: `${applicantWorkspaceUrl.value}/activities`,
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
        return { eyebrow: 'Activity scheduled', title: `${waitingQueue.count} applicant${waitingQueue.count === 1 ? '' : 's'} scheduled for an activity`, description: 'The shared schedule is published. Complete the activity, then record each applicant\'s result.', label: 'Manage activities', href: waitingQueue.href };
    }

    const recipientCount = Math.max(
        Number(workflowCounts.value.selected ?? 0),
        selectedCount.value,
    );
    if (recipientCount > 0) {
        return {
            eyebrow: 'Recipient records',
            title: `${recipientCount} selected recipient${recipientCount === 1 ? '' : 's'}`,
            description: 'Review award records before continuing to recipient monitoring.',
            label: 'View recipients',
            href: `${applicantWorkspaceUrl.value}/recipients`,
        };
    }

    const waitlistCount = Number(workflowCounts.value.waitlisted ?? 0);
    if (waitlistCount > 0) {
        return {
            eyebrow: 'Alternate records',
            title: `${waitlistCount} waitlisted applicant${waitlistCount === 1 ? '' : 's'}`,
            description: 'Review alternates who may be promoted if a slot becomes available.',
            label: 'View waitlist',
            href: `${applicantWorkspaceUrl.value}/waitlist`,
        };
    }

    if (Number(workflowCounts.value.all ?? 0) > 0) {
        return { eyebrow: 'Program records', title: 'No applicant action is due', description: 'Use the Applications menu to open the record type you need.', label: 'Review applicants', href: `${applicantWorkspaceUrl.value}/review` };
    }

    return {
        eyebrow: 'Program is ready',
        title: 'Waiting for the first applicant',
        description: statusGuidance(program.status),
        label: 'View all programs',
        href: '/provider/programs',
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
                    <a
                        v-if="isUpdatesView && scholarship"
                        :href="`/provider/programs/${scholarship.id}`"
                        class="max-w-72 truncate font-bold text-slate-600 transition hover:text-slate-950"
                    >
                        {{ scholarship.title }}
                    </a>
                    <span v-else class="truncate font-semibold text-slate-950">{{ scholarship?.title || 'Program workspace' }}</span>
                    <template v-if="isUpdatesView && scholarship">
                        <i class="fa-solid fa-chevron-right text-[9px] text-slate-400" aria-hidden="true"></i>
                        <span class="font-semibold text-slate-950">Updates</span>
                    </template>
                </nav>

                <div v-if="isLoading" class="mt-5 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading program workspace...
                </div>
                <div v-else-if="errorMessage" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <template v-else-if="scholarship">
                    <TaskPageHeader
                        v-if="isUpdatesView"
                        class="mt-5"
                        theme="provider"
                        eyebrow="Program communication"
                        title="Applicant updates"
                        description="Send stage-specific announcements and review what your team already published."
                        icon="fa-solid fa-bullhorn"
                    >
                        <template #meta>
                            <span>{{ scholarship.title }}</span>
                            <span>{{ announcements.length }} published</span>
                        </template>
                        <template v-if="canSendAnnouncements" #actions>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="openAnnouncementComposer"
                            >
                                <i class="fa-solid fa-plus text-xs" aria-hidden="true"></i>
                                New announcement
                            </button>
                        </template>
                    </TaskPageHeader>

                    <section v-if="!isUpdatesView" class="provider-panel mt-5 overflow-visible">
                        <header class="flex flex-col gap-4 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                            <div class="flex min-w-0 items-center gap-4">
                                <img :src="scholarship.image_url" :alt="scholarship.title" class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Program workspace</p>
                                        <span :class="['rounded-md px-2 py-1 text-[9px] font-bold uppercase', statusClass(scholarship.status)]">{{ statusLabel(scholarship.status) }}</span>
                                    </div>
                                    <h1 class="mt-1.5 truncate font-display text-xl font-bold leading-tight text-slate-950 sm:text-2xl">{{ scholarship.title }}</h1>
                                    <p class="mt-1 text-xs font-semibold text-slate-500 sm:text-sm">{{ scholarship.category || 'Scholarship program' }} <span class="mx-1 text-slate-300">/</span> {{ targetLabel(scholarship) }}</p>
                                </div>
                            </div>

                            <details class="group relative shrink-0">
                                <summary class="inline-flex min-h-10 cursor-pointer list-none items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
                                    More actions
                                    <i class="fa-solid fa-chevron-down text-[9px] text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                                </summary>
                                <div class="absolute right-0 z-50 mt-1 w-56 overflow-hidden rounded-md border border-slate-200 bg-white p-1 shadow-xl">
                                    <button v-if="canManagePrograms" type="button" :disabled="isDuplicating" class="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left text-sm font-bold text-slate-700 transition hover:bg-slate-50 hover:text-slate-950 disabled:opacity-60" @click="duplicateProgram">
                                        <i class="fa-regular fa-copy w-4 text-center text-xs text-slate-400" aria-hidden="true"></i>
                                        {{ isDuplicating ? 'Duplicating...' : 'Duplicate as draft' }}
                                    </button>
                                    <a href="/provider/programs" class="flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 hover:text-slate-950">
                                        <i class="fa-solid fa-arrow-left w-4 text-center text-xs text-slate-400" aria-hidden="true"></i>
                                        All programs
                                    </a>
                                </div>
                            </details>
                        </header>

                        <dl class="grid gap-px bg-slate-200 sm:grid-cols-3">
                            <div class="bg-white px-4 py-3">
                                <dt class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500"><i class="fa-regular fa-calendar text-amber-700" aria-hidden="true"></i>Deadline</dt>
                                <dd class="mt-1.5 text-sm font-bold text-slate-950">{{ dateLabel(scholarship.deadline) }}</dd>
                            </div>
                            <div class="bg-white px-4 py-3">
                                <dt class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500"><i :class="[canAccessApplicantWorkspace ? 'fa-solid fa-users' : 'fa-solid fa-eye', 'text-amber-700']" aria-hidden="true"></i>{{ canAccessApplicantWorkspace ? 'Applicants' : 'Visibility' }}</dt>
                                <dd class="mt-1.5 text-sm font-bold text-slate-950">{{ canAccessApplicantWorkspace ? `${scholarship.applications_count ?? 0} total` : statusLabel(scholarship.status) }}</dd>
                            </div>
                            <div class="bg-white px-4 py-3">
                                <dt class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500"><i :class="[canAccessApplicantWorkspace ? 'fa-solid fa-user-check' : 'fa-regular fa-clock', 'text-amber-700']" aria-hidden="true"></i>{{ canAccessApplicantWorkspace ? 'Selected' : 'Updated' }}</dt>
                                <dd class="mt-1.5 text-sm font-bold text-slate-950">{{ canAccessApplicantWorkspace ? `${selectedCount}${slotCapacity > 0 ? ` of ${slotCapacity}` : ''}` : (scholarship.updated_at || 'Recently') }}</dd>
                                <div v-if="canAccessApplicantWorkspace && slotCapacity > 0" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-amber-400" :style="{ width: `${slotUsagePercent}%` }"></div></div>
                            </div>
                        </dl>
                    </section>

                    <ProviderProgramNav
                        :program-id="scholarship.id"
                        :active="isUpdatesView ? 'announcements' : 'overview'"
                    />

                    <div v-if="!isUpdatesView" class="mt-4">
                        <section v-if="recommendedAction" class="provider-panel overflow-hidden border-l-4 border-l-amber-400">
                            <div class="flex flex-col justify-between gap-4 p-4 sm:flex-row sm:items-center sm:px-5">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">{{ recommendedAction.eyebrow }}</p>
                                        <h2 class="mt-1 text-base font-bold text-slate-950 sm:text-lg">{{ recommendedAction.title }}</h2>
                                        <p class="mt-1 max-w-2xl text-sm leading-5 text-slate-500">{{ recommendedAction.description }}</p>
                                    </div>
                                </div>
                                <a :href="recommendedAction.href" class="inline-flex w-fit shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800">
                                    {{ recommendedAction.label }}
                                    <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                                </a>
                            </div>
                        </section>
                    </div>

                    <section v-if="isUpdatesView" id="announcements" class="provider-panel mt-4 overflow-hidden">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5 sm:px-6">
                            <h2 class="text-base font-bold text-slate-950">Published announcements</h2>
                            <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{{ announcements.length }}</span>
                        </header>

                        <div v-if="announcements.length" class="divide-y divide-slate-200">
                            <article v-for="announcement in announcements" :key="announcement.id" class="grid gap-3 px-5 py-3.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                        <i class="fa-regular fa-bell text-xs" aria-hidden="true"></i>
                                    </span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="truncate text-sm font-bold text-slate-950">{{ announcement.title }}</h3>
                                            <span class="rounded-md bg-slate-100 px-2 py-1 text-[9px] font-bold uppercase text-slate-600">{{ announcement.audience_label }}</span>
                                        </div>
                                        <p class="mt-1 line-clamp-1 text-xs leading-5 text-slate-500">{{ announcement.message }}</p>
                                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-semibold text-slate-400">
                                            <span>{{ announcement.recipient_count }} recipient{{ announcement.recipient_count === 1 ? '' : 's' }}</span>
                                            <span>{{ announcement.published_at }}</span>
                                            <span v-if="announcement.publisher">By {{ announcement.publisher }}</span>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="ml-12 w-fit rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 sm:ml-0" @click="openAnnouncement(announcement)">View message</button>
                            </article>
                        </div>
                        <div v-else class="px-5 py-8 text-center sm:px-6">
                            <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500"><i class="fa-regular fa-bell" aria-hidden="true"></i></span>
                            <p class="mt-3 text-sm font-bold text-slate-800">No announcements yet</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Published updates will remain here for provider reference.</p>
                        </div>
                    </section>
                </template>

            </div>
        </section>

        <Teleport to="body">
            <div
                v-if="selectedAnnouncement"
                class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/60 p-4"
                role="presentation"
                @click.self="closeAnnouncement"
                @keydown.esc="closeAnnouncement"
            >
                <section class="flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="announcement-detail-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Published announcement</p>
                            <h2 id="announcement-detail-title" class="mt-1 text-xl font-bold text-slate-950">{{ selectedAnnouncement.title }}</h2>
                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs font-semibold text-slate-500">
                                <span>{{ selectedAnnouncement.audience_label }}</span>
                                <span>{{ selectedAnnouncement.recipient_count }} recipient{{ selectedAnnouncement.recipient_count === 1 ? '' : 's' }}</span>
                                <span>{{ selectedAnnouncement.published_at }}</span>
                            </div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Close announcement" @click="closeAnnouncement">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>
                    <div class="overflow-y-auto px-5 py-5 sm:px-6">
                        <p class="whitespace-pre-line text-sm leading-7 text-slate-700">{{ selectedAnnouncement.message }}</p>
                        <p v-if="selectedAnnouncement.publisher" class="mt-5 border-t border-slate-200 pt-4 text-xs font-semibold text-slate-500">Published by {{ selectedAnnouncement.publisher }}</p>
                    </div>
                    <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                        <button type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800" @click="closeAnnouncement">Close</button>
                    </footer>
                </section>
            </div>
        </Teleport>

        <Teleport to="body">
            <div
                v-if="showAnnouncementComposer"
                class="fixed inset-0 z-[2000] flex items-center justify-center bg-slate-950/60 p-4"
                role="presentation"
                @click.self="closeAnnouncementComposer"
                @keydown.esc="closeAnnouncementComposer"
            >
                <section
                    class="flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="announcement-modal-title"
                >
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                                <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Applicant update</p>
                                <h2 id="announcement-modal-title" class="mt-1 text-xl font-bold text-slate-950">New announcement</h2>
                                <p class="mt-1 text-sm leading-5 text-slate-600">Applicants in the selected group will receive this update.</p>
                            </div>
                        </div>
                        <button type="button" :disabled="isPublishingAnnouncement" class="grid h-9 w-9 shrink-0 place-items-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50" aria-label="Close announcement form" @click="closeAnnouncementComposer">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </header>

                    <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="publishAnnouncement">
                        <div class="overflow-y-auto px-5 py-5 sm:px-6">
                            <p v-if="announcementError" class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                                {{ announcementError }}
                            </p>
                            <div class="space-y-4">
                                <label class="block">
                                    <span class="mb-2 block text-xs font-bold text-slate-700">Announcement title</span>
                                    <input ref="announcementTitleInput" v-model="announcementForm.title" type="text" maxlength="120" required placeholder="Example: Interview schedule reminder" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-slate-600 focus:ring-3 focus:ring-slate-100">
                                </label>
                                <label class="block">
                                    <span class="mb-2 block text-xs font-bold text-slate-700">Send to</span>
                                    <select v-model="announcementForm.audience" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-slate-600 focus:ring-3 focus:ring-slate-100">
                                        <option v-for="audience in announcementAudiences" :key="audience.value" :value="audience.value">{{ audience.label }}</option>
                                    </select>
                                    <span class="mt-1.5 block text-xs leading-5 text-slate-500">{{ selectedAudienceHelp }}</span>
                                </label>
                                <label class="block">
                                    <span class="mb-2 flex items-center justify-between gap-3 text-xs font-bold text-slate-700">
                                        <span>Message</span>
                                        <span class="font-semibold text-slate-400">{{ announcementForm.message.length }}/2000</span>
                                    </span>
                                    <textarea v-model="announcementForm.message" rows="6" maxlength="2000" required placeholder="Write the update, instructions, or reminder applicants should receive." class="w-full resize-y rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 text-slate-900 outline-none focus:border-slate-600 focus:ring-3 focus:ring-slate-100"></textarea>
                                </label>
                            </div>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                            <button type="button" :disabled="isPublishingAnnouncement" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100 disabled:opacity-60" @click="closeAnnouncementComposer">
                                Cancel
                            </button>
                            <button type="submit" :disabled="isPublishingAnnouncement" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                                <i class="fa-solid fa-paper-plane text-xs" aria-hidden="true"></i>
                                {{ isPublishingAnnouncement ? 'Publishing...' : 'Publish announcement' }}
                            </button>
                        </footer>
                    </form>
                </section>
            </div>
        </Teleport>

    </main>
</template>
