<script setup>
import { computed } from 'vue';

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
                ? 'Ready for applications'
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
        attention: 'bg-amber-100 text-amber-900',
        positive: 'bg-slate-950 text-white',
        muted: 'bg-slate-100 text-slate-600',
        neutral: 'bg-slate-100 text-slate-700',
    }[tone] ?? 'bg-slate-100 text-slate-700';
}
</script>

<template>
    <div class="mt-5 space-y-4">
        <section
            class="student-card overflow-hidden border-l-4"
            :class="primaryAction.tone === 'calm' ? 'border-l-slate-950' : 'border-l-amber-400'"
            aria-labelledby="dashboard-primary-action"
        >
            <div class="p-5 sm:p-6">
                <div class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-4 gap-y-5 md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center">
                    <span
                        :class="[
                            'grid h-11 w-11 shrink-0 place-items-center rounded-md text-base',
                            primaryAction.tone === 'calm'
                                ? 'bg-slate-950 text-amber-300'
                                : 'bg-amber-300 text-slate-950',
                        ]"
                    >
                        <i :class="actionIcon(primaryAction.kind)" aria-hidden="true"></i>
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="student-kicker">{{ primaryLabel(primaryAction) }}</p>
                        <h2 id="dashboard-primary-action" class="mt-1 text-xl font-bold leading-tight text-slate-950">
                            {{ primaryAction.title }}
                        </h2>
                        <p class="mt-1.5 max-w-3xl text-sm leading-6 text-slate-600">
                            {{ primaryAction.description }}
                        </p>
                        <div
                            v-if="primaryAction.context || primaryAction.due_label"
                            class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500"
                        >
                            <span v-if="primaryAction.context" class="inline-flex items-center gap-1.5">
                                <i class="fa-solid fa-award text-slate-400" aria-hidden="true"></i>
                                {{ primaryAction.context }}
                            </span>
                            <span v-if="primaryAction.due_label" class="inline-flex items-center gap-1.5 text-slate-700">
                                <i class="fa-regular fa-calendar text-slate-400" aria-hidden="true"></i>
                                {{ primaryAction.due_label }}
                            </span>
                        </div>
                    </div>

                    <a
                        :href="primaryAction.href"
                        class="col-span-2 inline-flex w-full shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-3 text-sm font-bold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 md:col-span-1 md:w-auto"
                    >
                        {{ primaryAction.cta }}
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </div>
            </div>

            <div v-if="secondaryActions.length" class="border-t border-slate-200 bg-slate-50/70">
                <div class="flex items-center justify-between gap-4 px-5 pb-1 pt-4 sm:px-6">
                    <h3 class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">After this</h3>
                    <span class="text-xs font-semibold text-slate-500">
                        {{ secondaryActions.length }} more {{ secondaryActions.length === 1 ? 'task' : 'tasks' }}
                    </span>
                </div>

                <div class="divide-y divide-slate-200">
                    <a
                        v-for="action in secondaryActions"
                        :key="action.key"
                        :href="action.href"
                        class="group flex items-center gap-3 px-5 py-3.5 transition hover:bg-white sm:px-6"
                    >
                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-amber-100 text-xs text-amber-900">
                            <i :class="actionIcon(action.kind)" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold text-slate-950">{{ action.title }}</span>
                            <span class="mt-0.5 block truncate text-xs text-slate-500">
                                {{ action.context }}<template v-if="action.due_label"> | {{ action.due_label }}</template>
                            </span>
                        </span>
                        <span class="hidden items-center gap-2 text-xs font-bold text-slate-700 group-hover:text-slate-950 sm:inline-flex">
                            {{ action.cta }}
                            <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                        </span>
                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-400 sm:hidden" aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        </section>

        <section class="student-card grid grid-cols-2 overflow-hidden lg:grid-cols-4" aria-label="Dashboard at a glance">
            <a
                v-for="item in summaryItems"
                :key="item.label"
                :href="item.href"
                class="group flex min-w-0 items-start gap-3 border-b border-slate-200 p-4 odd:border-r hover:bg-slate-50 lg:border-b-0 lg:border-r lg:last:border-r-0"
            >
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-sm text-slate-700 transition group-hover:bg-slate-950 group-hover:text-amber-300">
                    <i :class="item.icon" aria-hidden="true"></i>
                </span>
                <span class="min-w-0">
                    <span class="block text-lg font-bold leading-none text-slate-950">{{ item.value }}</span>
                    <span class="mt-1.5 block text-xs font-bold text-slate-700">{{ item.label }}</span>
                    <span class="mt-1 hidden truncate text-[11px] text-slate-500 sm:block">{{ item.detail }}</span>
                </span>
            </a>
        </section>

        <section v-if="onboarding.length" class="student-card overflow-hidden" aria-labelledby="dashboard-getting-started">
            <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <h2 id="dashboard-getting-started" class="text-base font-bold text-slate-950">Your application path</h2>
                <span class="text-xs font-semibold text-slate-500">Three simple steps</span>
            </header>

            <ol class="divide-y divide-slate-200">
                <li v-for="(step, index) in onboarding" :key="step.label">
                    <a
                        :href="step.href"
                        :aria-current="step.status === 'current' ? 'step' : undefined"
                        :class="[
                            'group flex items-start gap-3 px-5 py-3.5 transition hover:bg-slate-50',
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
                            <span v-if="step.status === 'current'" class="mt-0.5 block text-xs leading-5 text-slate-500">
                                {{ step.description }}
                            </span>
                        </span>
                        <span v-if="step.status === 'current'" class="mt-1 text-xs font-bold text-slate-800 group-hover:underline">
                            Continue
                        </span>
                    </a>
                </li>
            </ol>
        </section>

        <section v-if="applications.length" class="student-card overflow-hidden" aria-labelledby="dashboard-applications">
            <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <h2 id="dashboard-applications" class="text-base font-bold text-slate-950">Your applications</h2>
                <a href="/dashboard/applications" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-slate-950">
                    View all
                    <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                </a>
            </header>

            <div class="divide-y divide-slate-200">
                <a
                    v-for="application in applications"
                    :key="application.id"
                    :href="application.href"
                    class="group flex items-center gap-3 px-5 py-4 transition hover:bg-slate-50"
                >
                    <img
                        :src="application.image_url"
                        alt=""
                        class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-bold text-slate-950">{{ application.title }}</span>
                        <span class="mt-0.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                            <span class="truncate">{{ application.provider }}</span>
                            <span v-if="application.submitted_label" class="inline-flex items-center gap-1.5">
                                <i class="fa-regular fa-calendar text-slate-400" aria-hidden="true"></i>
                                Submitted {{ application.submitted_label }}
                            </span>
                        </span>
                        <span class="mt-1.5 block truncate text-xs font-semibold text-slate-700">
                            <span class="text-slate-400">Next:</span> {{ application.next_step }}
                        </span>
                        <span :class="['mt-2 inline-flex rounded-md px-2 py-1 text-[9px] font-bold uppercase tracking-wide sm:hidden', statusClass(application.status_tone)]">
                            {{ application.status_label }}
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <span :class="['hidden rounded-md px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-wide sm:inline-flex', statusClass(application.status_tone)]">
                            {{ application.status_label }}
                        </span>
                        <span class="grid h-8 w-8 place-items-center rounded-md border border-slate-200 text-slate-500 transition group-hover:border-slate-950 group-hover:bg-slate-950 group-hover:text-white">
                            <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                        </span>
                    </span>
                </a>
            </div>
        </section>

        <section v-if="upcoming.length" class="student-card overflow-hidden" aria-labelledby="dashboard-upcoming">
            <header class="border-b border-slate-200 px-5 py-4">
                <h2 id="dashboard-upcoming" class="text-base font-bold text-slate-950">Coming up</h2>
            </header>

            <div class="divide-y divide-slate-200">
                <a
                    v-for="item in upcoming"
                    :key="item.key"
                    :href="item.href"
                    class="group grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-2 px-5 py-4 transition hover:bg-slate-50 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-sm text-slate-700">
                        <i :class="upcomingIcon(item.kind)" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-bold text-slate-950">{{ item.title }}</span>
                        <span class="mt-0.5 block truncate text-xs text-slate-500">{{ item.context }} | {{ item.detail }}</span>
                    </span>
                    <span class="col-start-2 w-fit shrink-0 rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-700 sm:col-start-auto">
                        {{ item.date_label }}
                    </span>
                </a>
            </div>
        </section>

        <section v-if="recommendation" class="student-card overflow-hidden" aria-labelledby="dashboard-recommendation">
            <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <h2 id="dashboard-recommendation" class="text-base font-bold text-slate-950">Suggested scholarship</h2>
                <a href="/dashboard/scholarships" class="text-xs font-bold text-slate-600 hover:text-slate-950">Browse all</a>
            </header>

            <a :href="recommendation.href" class="group flex items-center gap-4 p-5 transition hover:bg-slate-50">
                <img
                    :src="recommendation.image_url"
                    alt=""
                    class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                >
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-bold text-slate-950">{{ recommendation.title }}</span>
                    <span class="mt-0.5 block truncate text-xs text-slate-500">{{ recommendation.provider }}</span>
                    <span class="mt-1.5 block truncate text-xs font-semibold text-slate-700">
                        {{ recommendation.benefit_summary }} | {{ recommendation.deadline_label }}
                    </span>
                </span>
                <span class="hidden shrink-0 items-center gap-3 sm:flex">
                    <span class="rounded-md bg-amber-100 px-2.5 py-1.5 text-xs font-bold text-amber-900">
                        {{ recommendation.match_score }}% match
                    </span>
                    <span class="grid h-8 w-8 place-items-center rounded-md bg-slate-950 text-white">
                        <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                    </span>
                </span>
                <i class="fa-solid fa-chevron-right shrink-0 text-xs text-slate-400 sm:hidden" aria-hidden="true"></i>
            </a>
        </section>
    </div>
</template>
