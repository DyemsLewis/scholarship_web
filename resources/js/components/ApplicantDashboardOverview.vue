<script setup>
import { computed } from 'vue';
import ApplicantTextSummary from './ApplicantTextSummary.vue';

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
});

const primaryAction = computed(() => props.dashboard.primary_action ?? {
    kind: 'browse',
    tone: 'calm',
    title: 'Explore scholarship opportunities',
    description: 'Browse available programs when you are ready.',
    context: 'Scholarship finder',
    href: '/dashboard/scholarships',
    cta: 'Browse scholarships',
});
const secondaryActions = computed(() => props.dashboard.secondary_actions ?? []);
const applications = computed(() => props.dashboard.applications ?? []);
const upcoming = computed(() => props.dashboard.upcoming ?? []);
const onboarding = computed(() => props.dashboard.onboarding ?? []);
const recommendation = computed(() => props.dashboard.recommendation ?? null);
const dashboardVisual = computed(() => ({
    onboarding: {
        image: '/images/filipino-students-science-class.jpg',
        alt: 'Filipino students taking part in a science lesson in Quezon City',
        label: 'Prepare with confidence',
    },
    applying: {
        image: '/images/filipino-students-group-study.jpg',
        alt: 'Students working together during a class in the Philippines',
        label: 'Keep your application moving',
    },
    recipient: {
        image: '/images/filipino-students-science-experiment.jpg',
        alt: 'Filipino students reviewing a classroom science experiment',
        label: 'Stay on track with your support',
    },
    discovery: {
        image: '/images/filipino-students-reading-program.jpg',
        alt: 'Filipino students participating in a classroom reading program',
        label: 'Your next opportunity starts here',
    },
}[props.dashboard.mode] ?? {
    image: '/images/filipino-students-science-class.jpg',
    alt: 'Filipino students learning together in a Quezon City classroom',
    label: 'Your scholarship workspace',
}));
const summaryItems = computed(() => {
    const profile = props.dashboard.profile ?? {};
    const summary = props.dashboard.summary ?? {};
    const missingProfileCount = Number(profile.missing_count ?? 0);
    const applicationCount = Number(summary.applications ?? 0);
    const activeApplicationCount = Number(summary.active_applications ?? 0);
    const actionCount = Number(summary.actions ?? 0);
    const upcomingCount = Number(summary.upcoming ?? 0);

    return [
        {
            label: 'Profile readiness',
            value: `${Number(profile.percent ?? 0)}%`,
            detail: profile.complete
                ? 'Required details complete'
                : (missingProfileCount === 1 ? '1 detail still needed' : `${missingProfileCount} details still needed`),
            icon: 'fa-regular fa-id-card',
            href: '/dashboard/profile',
        },
        {
            label: 'Active applications',
            value: activeApplicationCount,
            detail: applicationCount === 1 ? '1 application submitted' : `${applicationCount} applications submitted`,
            icon: 'fa-regular fa-folder-open',
            href: '/dashboard/applications',
        },
        {
            label: 'Needs your action',
            value: actionCount,
            detail: actionCount ? 'Open task list' : 'Nothing waiting',
            icon: 'fa-solid fa-list-check',
            href: primaryAction.value.href,
        },
        {
            label: 'Coming up',
            value: upcomingCount,
            detail: upcomingCount === 1 ? '1 scheduled item' : `${upcomingCount} scheduled items`,
            icon: 'fa-regular fa-calendar',
            href: upcoming.value[0]?.href ?? '/dashboard/applications',
        },
    ];
});

const actionIcons = {
    correction: 'fa-solid fa-pen-to-square',
    document: 'fa-solid fa-file-circle-exclamation',
    agreement: 'fa-solid fa-file-signature',
    monitoring: 'fa-solid fa-list-check',
    monitoring_correction: 'fa-solid fa-rotate',
    benefit: 'fa-solid fa-hand-holding-heart',
    support_decision: 'fa-solid fa-clipboard-check',
    schedule: 'fa-solid fa-calendar-check',
    application: 'fa-solid fa-file-lines',
    profile: 'fa-solid fa-user-pen',
    waiting: 'fa-regular fa-clock',
    up_to_date: 'fa-solid fa-check',
    browse: 'fa-solid fa-magnifying-glass',
};

const upcomingIcons = {
    schedule: 'fa-regular fa-calendar-check',
    monitoring: 'fa-solid fa-list-check',
    benefit: 'fa-solid fa-hand-holding-heart',
};

function actionIcon(kind) {
    return actionIcons[kind] ?? 'fa-solid fa-arrow-right';
}

function upcomingIcon(kind) {
    return upcomingIcons[kind] ?? 'fa-regular fa-calendar';
}

function primaryLabel(action) {
    if (action.tone === 'calm') return 'Current status';
    if (action.tone === 'urgent') return 'Needs attention';

    return 'Your next action';
}

function statusClass(tone) {
    return {
        attention: 'student-badge-attention',
        positive: 'student-badge-positive',
    }[tone] ?? '';
}
</script>

<template>
    <div class="mt-4 space-y-5" data-applicant-dashboard>
        <section
            class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-[0_12px_32px_rgba(15,23,42,0.09)]"
            :class="primaryAction.tone === 'calm' ? 'border-t-4 border-t-slate-950' : 'border-t-4 border-t-amber-400'"
            aria-labelledby="dashboard-primary-action"
        >
            <div class="grid lg:min-h-[19rem] lg:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.65fr)]">
                <div class="flex min-w-0 flex-col justify-between p-5 sm:p-7 lg:p-8">
                    <div>
                        <div class="flex items-center gap-3">
                            <span
                                :class="[
                                    'grid h-11 w-11 shrink-0 place-items-center rounded-sm text-base',
                                    primaryAction.tone === 'calm'
                                        ? 'bg-slate-950 text-amber-300'
                                        : 'bg-amber-300 text-slate-950',
                                ]"
                            >
                                <i :class="[actionIcon(primaryAction.kind), 'leading-none']" aria-hidden="true"></i>
                            </span>
                            <p class="student-kicker">{{ primaryLabel(primaryAction) }}</p>
                        </div>

                        <h2 id="dashboard-primary-action" class="mt-5 max-w-3xl font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">
                            {{ primaryAction.title }}
                        </h2>
                        <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base">
                            {{ primaryAction.description }}
                        </p>

                        <div v-if="primaryAction.context || primaryAction.due_label" class="mt-4 flex flex-wrap gap-2">
                            <span v-if="primaryAction.context" class="student-badge">
                                <i class="fa-solid fa-award text-amber-700" aria-hidden="true"></i>
                                {{ primaryAction.context }}
                            </span>
                            <span v-if="primaryAction.due_label" class="student-badge student-badge-attention">
                                <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                                {{ primaryAction.due_label }}
                            </span>
                        </div>

                        <a
                            :href="primaryAction.href"
                            class="student-button student-button-primary mt-5 w-full sm:w-auto"
                        >
                            {{ primaryAction.cta }}
                            <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                        </a>
                    </div>

                    <div v-if="secondaryActions.length" class="mt-6 border-t border-slate-200 pt-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Also waiting</p>
                            <span class="text-xs font-semibold text-slate-500">{{ secondaryActions.length }} more</span>
                        </div>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <a v-for="action in secondaryActions" :key="action.key" :href="action.href" class="group flex min-w-0 items-center gap-3 border border-slate-200 bg-slate-50 px-3 py-3 transition hover:border-slate-400 hover:bg-white">
                                <span class="grid h-8 w-8 shrink-0 place-items-center bg-amber-100 text-xs text-amber-900">
                                    <i :class="actionIcon(action.kind)" aria-hidden="true"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block break-words text-sm font-bold text-slate-950">{{ action.title }}</span>
                                    <span class="mt-0.5 block break-words text-xs leading-5 text-slate-600">{{ action.context }}</span>
                                </span>
                                <i class="fa-solid fa-arrow-right text-[10px] text-slate-400 transition group-hover:text-slate-900" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <figure class="relative min-h-52 overflow-hidden border-t border-slate-200 lg:min-h-full lg:border-l lg:border-t-0">
                    <img :src="dashboardVisual.image" :alt="dashboardVisual.alt" class="absolute inset-0 h-full w-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/10 to-transparent lg:bg-gradient-to-r lg:from-slate-950/25 lg:via-transparent lg:to-slate-950/35"></div>
                    <figcaption class="absolute bottom-4 left-4 right-4 inline-flex w-fit max-w-[calc(100%-2rem)] items-center gap-2 bg-slate-950/85 px-3 py-2 text-xs font-bold text-white backdrop-blur-sm">
                        <i class="fa-solid fa-graduation-cap text-amber-300" aria-hidden="true"></i>
                        {{ dashboardVisual.label }}
                    </figcaption>
                </figure>
            </div>
        </section>

        <section class="grid grid-cols-2 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm xl:grid-cols-4" aria-label="Dashboard at a glance">
            <a
                v-for="item in summaryItems"
                :key="item.label"
                :href="item.href"
                class="group grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-center gap-2 border-b border-slate-200 p-3 odd:border-r hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-600 sm:flex sm:gap-3 sm:p-5 xl:border-b-0 xl:border-r xl:last:border-r-0"
            >
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-sm bg-slate-100 text-sm text-slate-700 transition group-hover:bg-slate-950 group-hover:text-amber-300 sm:h-10 sm:w-10">
                    <i :class="item.icon" aria-hidden="true"></i>
                </span>
                <span class="col-span-2 row-start-2 min-w-0 flex-1">
                    <span class="block text-xs font-bold leading-4 text-slate-700" data-dashboard-summary-label>{{ item.label }}</span>
                    <span class="mt-1 hidden text-xs leading-5 text-slate-600 sm:block">{{ item.detail }}</span>
                </span>
                <span data-dashboard-summary-value class="shrink-0 text-right col-start-2 row-start-1 text-xl font-bold leading-none text-slate-950">
                    {{ item.value }}
                </span>
            </a>
        </section>

        <section v-if="onboarding.length" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="dashboard-getting-started">
            <header class="flex flex-col gap-1 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <h2 id="dashboard-getting-started" class="text-base font-bold text-slate-950">Your application path</h2>
                    <p class="mt-1 text-xs text-slate-500">Finish one step at a time. You can return whenever you need.</p>
                </div>
                <span class="text-xs font-bold text-slate-500">Three steps</span>
            </header>

            <ol class="grid divide-y divide-slate-200 md:grid-cols-3 md:divide-x md:divide-y-0">
                <li v-for="(step, index) in onboarding" :key="step.label">
                    <a
                        :href="step.href"
                        :aria-current="step.status === 'current' ? 'step' : undefined"
                        :class="[
                            'group flex h-full items-start gap-3 px-5 py-5 transition hover:bg-slate-50',
                            step.status === 'current' ? 'bg-amber-50' : 'bg-white',
                        ]"
                    >
                        <span
                            :class="[
                                'grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs font-bold',
                                step.status === 'current'
                                    ? 'bg-amber-300 text-slate-950'
                                    : step.status === 'complete'
                                        ? 'bg-slate-950 text-white'
                                        : 'bg-slate-100 text-slate-500',
                            ]"
                        >
                            <i v-if="step.status === 'complete'" class="fa-solid fa-check" aria-hidden="true"></i>
                            <span v-else>{{ index + 1 }}</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold text-slate-950">{{ step.label }}</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">{{ step.description }}</span>
                        </span>
                        <i class="fa-solid fa-arrow-right mt-2 text-[10px] text-slate-400 transition group-hover:text-slate-900" aria-hidden="true"></i>
                    </a>
                </li>
            </ol>
        </section>

        <section v-if="applications.length" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="dashboard-applications">
            <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div>
                    <h2 id="dashboard-applications" class="text-base font-bold text-slate-950">Application progress</h2>
                    <p class="mt-1 text-xs text-slate-500">Your most relevant applications and their current stage.</p>
                </div>
                <a href="/dashboard/applications" class="student-button student-button-secondary student-button-compact shrink-0">
                    View all
                    <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                </a>
            </header>

            <div class="divide-y divide-slate-200">
                <a
                    v-for="application in applications"
                    :key="application.id"
                    :href="application.href"
                    class="group grid gap-4 px-5 py-4 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-600 sm:px-6 lg:grid-cols-[3.5rem_minmax(0,1fr)_minmax(12rem,0.5fr)_auto] lg:items-center"
                    data-dashboard-application
                >
                    <img
                        :src="application.image_url"
                        :alt="`${application.title} program logo`"
                        class="h-14 w-14 shrink-0 rounded-sm bg-white object-contain p-2 ring-1 ring-slate-200"
                    >
                    <span class="min-w-0">
                        <span class="block break-words text-base font-bold text-slate-950">{{ application.title }}</span>
                        <span class="mt-0.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                            <span class="break-words">{{ application.provider }}</span>
                            <span v-if="application.submitted_label" class="inline-flex items-center gap-1.5">
                                <i class="fa-regular fa-calendar text-slate-400" aria-hidden="true"></i>
                                Submitted {{ application.submitted_label }}
                            </span>
                        </span>
                        <span class="mt-1.5 block text-xs font-semibold leading-5 text-slate-700">
                            <span class="text-slate-600">Next:</span> {{ application.next_step }}
                        </span>
                    </span>
                    <span class="min-w-0">
                        <span class="flex items-center justify-between gap-3 text-xs font-bold text-slate-600">
                            <span>{{ application.progress_label }}</span>
                            <span>{{ application.progress_percent }}%</span>
                        </span>
                        <span class="mt-2 block h-1.5 overflow-hidden bg-slate-200">
                            <span class="block h-full bg-amber-400" :style="{ width: `${application.progress_percent}%` }"></span>
                        </span>
                    </span>
                    <span class="flex items-center justify-between gap-3 lg:justify-end">
                        <span :class="['student-badge', statusClass(application.status_tone)]">
                            {{ application.status_label }}
                        </span>
                        <span class="grid h-8 w-8 place-items-center border border-slate-200 text-slate-500 transition group-hover:border-slate-950 group-hover:bg-slate-950 group-hover:text-white">
                            <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                        </span>
                    </span>
                </a>
            </div>
        </section>

        <section v-if="upcoming.length" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="dashboard-upcoming">
            <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <h2 id="dashboard-upcoming" class="text-base font-bold text-slate-950">Coming up</h2>
                <p class="mt-1 text-xs text-slate-500">Scheduled activities, monitoring dates, and benefit releases.</p>
            </header>

            <div class="divide-y divide-slate-200">
                <a
                    v-for="item in upcoming"
                    :key="item.key"
                    :href="item.href"
                    class="group grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-4 gap-y-2 px-5 py-4 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-600 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:px-6"
                >
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-sm text-amber-300">
                        <i :class="upcomingIcon(item.kind)" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block break-words text-sm font-bold text-slate-950">{{ item.title }}</span>
                        <span class="mt-0.5 block text-xs leading-5 text-slate-600">{{ item.context }}<template v-if="item.context && item.detail"> | </template>{{ item.detail }}</span>
                    </span>
                    <span class="student-badge col-start-2 w-fit sm:col-start-auto">
                        {{ item.date_label }}
                    </span>
                </a>
            </div>
        </section>

        <section v-if="recommendation" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="dashboard-recommendation" data-dashboard-recommendation>
            <div class="grid sm:grid-cols-[8rem_minmax(0,1fr)_auto] sm:items-center">
                <a :href="recommendation.href" :aria-label="`View ${recommendation.title}`" class="grid min-h-32 place-items-center border-b border-slate-200 bg-gradient-to-br from-amber-50 to-slate-100 p-5 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-600 sm:h-full sm:border-b-0 sm:border-r">
                    <img :src="recommendation.image_url" :alt="`${recommendation.title} program logo`" class="h-20 w-20 bg-white object-contain p-2 shadow-sm ring-1 ring-slate-200">
                </a>
                <div class="min-w-0 p-5 sm:p-6">
                    <p class="student-kicker">Suggested scholarship</p>
                    <h2 id="dashboard-recommendation" class="mt-2 text-lg font-bold text-slate-950"><a :href="recommendation.href" class="break-words hover:underline focus-visible:outline-2 focus-visible:outline-amber-600">{{ recommendation.title }}</a></h2>
                    <p class="mt-1 text-sm text-slate-600">{{ recommendation.provider }}</p>
                    <ApplicantTextSummary v-if="recommendation.benefit_summary" :text="recommendation.benefit_summary" :label="`benefit summary for ${recommendation.title}`" class="mt-3" />
                    <span class="student-badge mt-3">{{ recommendation.deadline_label }}</span>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4 sm:flex-col sm:items-stretch sm:border-l sm:border-t-0 sm:px-6">
                    <span class="student-badge student-badge-attention justify-center">{{ recommendation.match_score }}% match</span>
                    <a :href="recommendation.href" class="student-button student-button-primary student-button-compact">
                        View scholarship
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </section>
    </div>
</template>
