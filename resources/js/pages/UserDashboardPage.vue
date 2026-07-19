<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const stats = ref({
    available_scholarships: 0,
    applications: 0,
    saved: 0,
});
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

const readinessMessage = computed(() => {
    if (profileReadiness.value.complete) {
        return 'Your profile is ready for scholarship applications.';
    }

    const missing = profileReadiness.value.missing?.length ?? 0;

    return missing > 0
        ? `Finish ${missing} profile detail${missing === 1 ? '' : 's'} to improve your matches.`
        : 'Review your profile so matches can stay accurate.';
});
const highMatchCount = computed(() => scholarships.value
    .filter((scholarship) => Number(scholarship.eligibility_match?.score ?? 0) >= 80)
    .length);
const recommendedScholarships = computed(() => [...scholarships.value]
    .sort((first, second) => {
        const scoreDifference = Number(second.eligibility_match?.score ?? 0) - Number(first.eligibility_match?.score ?? 0);

        if (scoreDifference !== 0) {
            return scoreDifference;
        }

        return Number(first.has_applied) - Number(second.has_applied);
    })
    .slice(0, 3));
const scheduledActivities = computed(() => applications.value
    .flatMap((application) => (application.schedules ?? [])
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
const activeApplication = computed(() => nextScheduledActivity.value?.application ?? applications.value.find((application) => ![
    'rejected',
    'not_awarded',
    'disbursed',
    'renewed',
].includes(application.status)) ?? null);
const activeApplicationNextAction = computed(() => {
    const application = activeApplication.value;

    if (!application) {
        return '';
    }

    const schedule = application.schedules?.find((item) => item.status === 'scheduled');

    if (schedule) {
        return schedule.applicant_acknowledged
            ? `Follow the ${scheduleTypeLabel(schedule.type).toLowerCase()} instructions for ${schedule.scheduled_label}.`
            : `Review and confirm the ${scheduleTypeLabel(schedule.type).toLowerCase()} schedule.`;
    }

    if (application.status === 'distribution_scheduled') {
        return `Review the reward distribution instructions for ${application.distribution_scheduled_for || 'the scheduled date'}.`;
    }

    const missingDocuments = application.document_readiness?.missing?.length ?? 0;

    if (missingDocuments > 0) {
        return `Prepare ${missingDocuments} missing document${missingDocuments === 1 ? '' : 's'} for this application.`;
    }

    if (application.status === 'submitted') {
        return 'Your application was submitted and is waiting for provider review.';
    }

    if (application.status === 'interview') {
        return 'Open the application and check the provider note for interview details.';
    }

    return 'Open the application to review its latest status and provider feedback.';
});
const dashboardPrimaryAction = computed(() => {
    const entry = nextScheduledActivity.value;

    if (entry) {
        return {
            message: entry.schedule.applicant_acknowledged
                ? `Your ${scheduleTypeLabel(entry.schedule.type).toLowerCase()} is set for ${entry.schedule.scheduled_label}.`
                : `A ${scheduleTypeLabel(entry.schedule.type).toLowerCase()} was posted. Review the instructions and confirm that you saw them.`,
            href: entry.application.detail_url || `/dashboard/applications/${entry.application.id}`,
            label: entry.schedule.applicant_acknowledged ? 'View schedule' : 'Review and confirm',
        };
    }

    if (!profileReadiness.value.complete) {
        return {
            message: readinessMessage.value,
            href: '/dashboard/profile',
            label: 'Complete profile',
        };
    }

    return {
        message: nextSteps.value[0] || 'Browse scholarships and save programs that fit your profile.',
        href: '/dashboard/scholarships',
        label: 'Browse scholarships',
    };
});
const urgentScholarships = computed(() => scholarships.value
    .map((scholarship) => ({ ...scholarship, days_left: deadlineDays(scholarship.deadline) }))
    .filter((scholarship) => scholarship.days_left !== null && scholarship.days_left >= 0 && scholarship.days_left <= 14)
    .sort((first, second) => first.days_left - second.days_left)
    .slice(0, 3));
const documentGaps = computed(() => scholarships.value
    .filter((scholarship) => Number(scholarship.prepared_documents?.required ?? 0) > Number(scholarship.prepared_documents?.uploaded ?? 0))
    .sort((first, second) => Number(second.eligibility_match?.score ?? 0) - Number(first.eligibility_match?.score ?? 0))
    .slice(0, 3));
const dashboardStats = computed(() => [
    {
        label: 'Programs',
        value: stats.value.available_scholarships,
        detail: 'available now',
    },
    {
        label: 'Saved',
        value: stats.value.saved,
        detail: 'kept for later',
    },
    {
        label: 'Applications',
        value: stats.value.applications,
        detail: 'submitted',
    },
]);
const journeySteps = computed(() => [
    {
        label: 'Profile',
        detail: profileReadiness.value.complete ? 'Complete' : `${profileReadiness.value.percent}% ready`,
        href: '/dashboard/profile',
        icon: 'fa-solid fa-user-check',
        state: profileReadiness.value.complete ? 'done' : 'active',
    },
    {
        label: 'Find',
        detail: highMatchCount.value > 0
            ? `${highMatchCount.value} strong match${highMatchCount.value === 1 ? '' : 'es'}`
            : `${stats.value.available_scholarships} program${Number(stats.value.available_scholarships) === 1 ? '' : 's'}`,
        href: '/dashboard/scholarships',
        icon: 'fa-solid fa-magnifying-glass',
        state: highMatchCount.value > 0 ? 'done' : 'active',
    },
    {
        label: 'Documents',
        detail: documentGaps.value.length === 0
            ? 'No gaps found'
            : `${documentGaps.value.length} file gap${documentGaps.value.length === 1 ? '' : 's'}`,
        href: '/dashboard/documents',
        icon: 'fa-solid fa-folder-open',
        state: documentGaps.value.length === 0 ? 'done' : 'active',
    },
    {
        label: 'Apply',
        detail: Number(stats.value.applications ?? 0) > 0
            ? `${stats.value.applications} submitted`
            : 'Ready when you are',
        href: '/dashboard/applications',
        icon: 'fa-solid fa-paper-plane',
        state: Number(stats.value.applications ?? 0) > 0 ? 'done' : 'idle',
    },
]);
const analystSignals = computed(() => [
    {
        label: 'Profile',
        icon: 'fa-solid fa-user-check',
        tone: profileReadiness.value.complete ? 'good' : 'warn',
        detail: profileReadiness.value.complete
            ? 'Ready to apply.'
            : `${profileReadiness.value.missing?.length ?? 0} missing detail${(profileReadiness.value.missing?.length ?? 0) === 1 ? '' : 's'}.`,
        href: '/dashboard/profile',
        action: profileReadiness.value.complete ? 'Review profile' : 'Complete profile',
    },
    {
        label: 'Matches',
        icon: 'fa-solid fa-graduation-cap',
        tone: highMatchCount.value > 0 ? 'good' : 'info',
        detail: highMatchCount.value > 0
            ? `${highMatchCount.value} strong match${highMatchCount.value === 1 ? '' : 'es'}.`
            : 'No strong match yet.',
        href: '/dashboard/scholarships',
        action: 'Browse matches',
    },
    {
        label: 'Documents',
        icon: 'fa-solid fa-folder-open',
        tone: documentGaps.value.length === 0 ? 'good' : 'warn',
        detail: documentGaps.value.length === 0
            ? 'No gaps found.'
            : `${documentGaps.value.length} program${documentGaps.value.length === 1 ? '' : 's'} need files.`,
        href: '/dashboard/documents',
        action: 'Prepare documents',
    },
    {
        label: 'Deadlines',
        icon: 'fa-solid fa-calendar-days',
        tone: urgentScholarships.value.length === 0 ? 'good' : 'warn',
        detail: urgentScholarships.value.length === 0
            ? 'Nothing urgent.'
            : `${urgentScholarships.value.length} coming soon.`,
        href: '/dashboard/scholarships',
        action: 'Check deadlines',
    },
]);

function signalClass(tone) {
    if (tone === 'good') {
        return 'border-slate-200 bg-white text-slate-800';
    }

    if (tone === 'warn') {
        return 'border-slate-300 bg-white text-slate-900';
    }

    return 'border-slate-200 bg-white text-slate-800';
}

function signalAccentClass(tone) {
    if (tone === 'good') {
        return 'bg-slate-900 text-white';
    }

    if (tone === 'warn') {
        return 'bg-slate-700 text-white';
    }

    return 'bg-slate-100 text-slate-700';
}

function journeyStepClass(state) {
    if (state === 'done') {
        return 'border-slate-900 bg-slate-900 text-white';
    }

    if (state === 'active') {
        return 'border-slate-300 bg-white text-slate-900';
    }

    return 'border-slate-200 bg-white text-slate-500';
}

function journeyIconClass(state) {
    if (state === 'done') {
        return 'bg-white text-slate-900';
    }

    if (state === 'active') {
        return 'bg-slate-100 text-slate-800';
    }

    return 'bg-slate-100 text-slate-500';
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

function scheduleTimestamp(schedule) {
    const timestamp = Date.parse(schedule?.scheduled_at ?? '');

    return Number.isNaN(timestamp) ? Number.MAX_SAFE_INTEGER : timestamp;
}

function scheduleTypeLabel(type) {
    return {
        exam: 'Scholarship exam',
        interview: 'Interview',
        distribution: 'Award distribution',
    }[type] ?? 'Scheduled activity';
}

function scheduleTypeIcon(type) {
    return {
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

function urgentDeadlineLabel(scholarship) {
    if (scholarship.days_left === 0) {
        return 'Due today';
    }

    if (scholarship.days_left === 1) {
        return 'Due tomorrow';
    }

    return `${scholarship.days_left} days left`;
}

function statusLabel(status) {
    return String(status ?? 'submitted')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/data');

        user.value = response.data.user;
        stats.value = response.data.stats ?? stats.value;
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
                    description="A quick view of matches, files, and next actions."
                    icon="fa-solid fa-table-columns"
                    action-href="/dashboard/scholarships"
                    action-label="Browse scholarships"
                    secondary-href="/dashboard/profile"
                    secondary-label="Update profile"
                />

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading applicant dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="grid lg:grid-cols-[minmax(0,1fr)_18rem]">
                            <div class="p-5 sm:p-6">
                                <div>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                                        <div>
                                            <p class="student-kicker">
                                                Scholarship Readiness
                                            </p>
                                            <h3 class="mt-2 text-2xl font-bold leading-tight text-slate-950">
                                                {{ readinessMessage }}
                                            </h3>
                                        </div>
                                        <p class="text-3xl font-bold text-slate-950">
                                            {{ profileReadiness.percent }}<span class="text-base text-slate-400">%</span>
                                        </p>
                                    </div>

                                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            class="h-full rounded-full bg-amber-400 transition-all"
                                            :style="{ width: `${profileReadiness.percent}%` }"
                                        ></div>
                                    </div>

                                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                                        <div
                                            v-for="item in dashboardStats"
                                            :key="item.label"
                                            class="border-l-2 border-slate-200 pl-3"
                                        >
                                            <p class="text-xl font-bold text-slate-950">
                                                {{ item.value ?? 0 }}
                                            </p>
                                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">
                                                {{ item.label }}
                                            </p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ item.detail }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-6 grid gap-2 sm:grid-cols-4">
                                    <a
                                        v-for="step in journeySteps"
                                        :key="step.label"
                                        :href="step.href"
                                        :class="['rounded-lg border p-3 transition hover:border-slate-500 hover:shadow-sm', journeyStepClass(step.state)]"
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <span :class="['flex h-8 w-8 items-center justify-center rounded-md', journeyIconClass(step.state)]">
                                                <i :class="[step.icon, 'text-xs']"></i>
                                            </span>
                                            <i class="fa-solid fa-arrow-right text-xs opacity-60"></i>
                                        </div>
                                        <p class="mt-3 text-sm font-bold">
                                            {{ step.label }}
                                        </p>
                                        <p class="mt-1 text-xs leading-5 opacity-80">
                                            {{ step.detail }}
                                        </p>
                                    </a>
                                </div>
                            </div>

                            <aside class="border-t border-slate-200 bg-slate-950 p-5 text-white lg:border-l lg:border-t-0">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-200">
                                    Start Here
                                </p>
                                <h3 class="mt-2 text-xl font-bold">
                                    Your next best move
                                </h3>
                                <p class="mt-3 text-sm leading-6 text-slate-300">
                                    {{ dashboardPrimaryAction.message }}
                                </p>
                                <a
                                    :href="dashboardPrimaryAction.href"
                                    class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-md bg-white px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-slate-100"
                                >
                                    {{ dashboardPrimaryAction.label }}
                                    <i class="fa-solid fa-arrow-right text-xs"></i>
                                </a>
                            </aside>
                        </div>
                    </section>

                    <section class="student-card p-4">
                        <div class="student-section-head">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark">
                                    <i class="fa-solid fa-compass text-sm"></i>
                                </span>
                                <div>
                                    <p class="student-kicker">
                                        To Do
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Needs attention
                                    </h3>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-2 lg:grid-cols-4">
                            <a
                                v-for="signal in analystSignals"
                                :key="signal.label"
                                :href="signal.href"
                                :class="['rounded-lg border p-3 transition hover:border-slate-400 hover:shadow-sm', signalClass(signal.tone)]"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-bold">
                                        {{ signal.label }}
                                    </p>
                                    <span :class="['flex h-7 w-7 items-center justify-center rounded-md', signalAccentClass(signal.tone)]">
                                        <i :class="[signal.icon, 'text-xs']"></i>
                                    </span>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-slate-600">
                                    {{ signal.detail }}
                                </p>
                                <p class="mt-3 text-xs font-bold text-slate-900">
                                    {{ signal.action }}
                                </p>
                            </a>
                        </div>
                    </section>

                    <section class="student-card p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark">
                                    <i class="fa-solid fa-graduation-cap text-sm"></i>
                                </span>
                                <div>
                                    <p class="student-kicker">
                                        Scholarships
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Recommended for you
                                    </h3>
                                </div>
                            </div>
                            <a href="/dashboard/scholarships" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                                View all
                            </a>
                        </div>

                        <div v-if="recommendedScholarships.length" class="mt-4 grid gap-3 lg:grid-cols-3">
                            <article
                                v-for="scholarship in recommendedScholarships"
                                :key="scholarship.id"
                                class="flex h-full min-w-0 flex-col rounded-lg border border-slate-200 bg-slate-50 p-3"
                            >
                                <div class="flex min-w-0 items-start gap-3">
                                    <img
                                        :src="scholarship.image_url || '/uploads/scholarship-default.jpg'"
                                        :alt="scholarship.title"
                                        class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0 flex-1">
                                        <p class="line-clamp-2 min-h-10 text-sm font-bold leading-5 text-slate-950">
                                            {{ scholarship.title }}
                                        </p>
                                        <p class="mt-1 truncate text-xs text-slate-500">
                                            {{ scholarship.provider?.name || 'Scholarship provider' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-auto flex flex-wrap items-center gap-2 pt-3 text-xs font-bold">
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
                                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                </a>
                            </article>
                        </div>

                        <div v-else class="student-empty-state mt-4">
                            No published scholarships are available yet. Check again after providers publish new programs.
                        </div>
                    </section>

                    <section
                        v-if="nextScheduledActivity"
                        :class="[
                            'student-card overflow-hidden border-l-4 p-4',
                            nextScheduledActivity.schedule.applicant_acknowledged ? 'border-l-slate-900' : 'border-l-amber-500',
                        ]"
                    >
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center">
                            <span :class="['grid h-12 w-12 shrink-0 place-items-center rounded-md text-white', nextScheduledActivity.schedule.applicant_acknowledged ? 'bg-slate-900' : 'bg-amber-600']">
                                <i :class="scheduleTypeIcon(nextScheduledActivity.schedule.type)" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="student-kicker">{{ scheduleTypeLabel(nextScheduledActivity.schedule.type) }}</p>
                                    <span
                                        v-if="!nextScheduledActivity.schedule.applicant_acknowledged"
                                        class="rounded-md bg-amber-100 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-amber-900"
                                    >
                                        Confirmation needed
                                    </span>
                                    <span v-else class="rounded-md bg-emerald-50 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-emerald-800 ring-1 ring-emerald-200">
                                        Confirmed
                                    </span>
                                </div>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    {{ nextScheduledActivity.schedule.title }}
                                </h3>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ nextScheduledActivity.application.scholarship?.title || 'Scholarship application' }}
                                    <span class="px-1 text-slate-300">|</span>
                                    {{ nextScheduledActivity.application.scholarship?.provider?.name || 'Scholarship provider' }}
                                </p>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-700">
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1.5">
                                        {{ nextScheduledActivity.schedule.scheduled_label }}
                                    </span>
                                    <span class="rounded-md bg-slate-100 px-2.5 py-1.5">
                                        {{ scheduleModeLabel(nextScheduledActivity.schedule.mode) }}
                                    </span>
                                    <span v-if="nextScheduledActivity.schedule.venue" class="rounded-md bg-slate-100 px-2.5 py-1.5">
                                        {{ nextScheduledActivity.schedule.venue }}
                                    </span>
                                    <span v-if="nextScheduledActivity.schedule.type === 'distribution'" class="rounded-md bg-emerald-50 px-2.5 py-1.5 text-emerald-800 ring-1 ring-emerald-200">
                                        {{ formatAwardAmount(nextScheduledActivity.application.awarded_amount) }}
                                    </span>
                                </div>
                            </div>
                            <a
                                :href="nextScheduledActivity.application.detail_url || `/dashboard/applications/${nextScheduledActivity.application.id}`"
                                :class="[
                                    'shrink-0 rounded-md px-4 py-2.5 text-center text-sm font-bold transition',
                                    nextScheduledActivity.schedule.applicant_acknowledged
                                        ? 'border border-slate-300 bg-white text-slate-800 hover:border-slate-500'
                                        : 'bg-slate-900 text-white hover:bg-slate-800',
                                ]"
                            >
                                {{ nextScheduledActivity.schedule.applicant_acknowledged ? 'View schedule' : 'Review and confirm' }}
                            </a>
                        </div>
                    </section>

                    <section v-else-if="activeApplication" class="student-card p-4">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <img
                                    :src="activeApplication.scholarship?.image_url || '/uploads/scholarship-default.jpg'"
                                    :alt="activeApplication.scholarship?.title || 'Scholarship application'"
                                    class="h-14 w-14 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                >
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="student-kicker">Active Application</p>
                                        <span class="rounded-md bg-slate-900 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-white">
                                            {{ statusLabel(activeApplication.status) }}
                                        </span>
                                    </div>
                                    <h3 class="mt-1 line-clamp-2 text-lg font-bold text-slate-950">
                                        {{ activeApplication.scholarship?.title || 'Scholarship application' }}
                                    </h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        {{ activeApplicationNextAction }}
                                    </p>
                                </div>
                            </div>
                            <a
                                :href="activeApplication.detail_url || '/dashboard/applications'"
                                class="shrink-0 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-800 transition hover:border-slate-500"
                            >
                                View application
                            </a>
                        </div>
                    </section>

                    <section v-if="urgentScholarships.length" class="student-card p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark">
                                    <i class="fa-solid fa-clock text-sm"></i>
                                </span>
                                <div>
                                    <p class="student-kicker">
                                        Deadline Reminder
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Programs closing soon
                                    </h3>
                                </div>
                            </div>
                            <a href="/dashboard/scholarships" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                                View scholarships
                            </a>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            <a
                                v-for="scholarship in urgentScholarships"
                                :key="scholarship.id"
                                :href="`/dashboard/scholarships/${scholarship.id}`"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-3 transition hover:border-slate-300 hover:bg-white"
                            >
                                <p class="line-clamp-2 text-sm font-bold text-slate-950">
                                    {{ scholarship.title }}
                                </p>
                                <p class="mt-1 truncate text-xs font-semibold text-slate-500">
                                    {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                </p>
                                <div class="mt-3 flex items-center justify-between gap-2">
                                    <span class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200">
                                        {{ urgentDeadlineLabel(scholarship) }}
                                    </span>
                                    <span class="text-xs font-bold text-slate-500">
                                        {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                    </span>
                                </div>
                            </a>
                        </div>
                    </section>

                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
