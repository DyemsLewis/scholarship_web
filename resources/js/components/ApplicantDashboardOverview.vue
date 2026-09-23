<script setup>
import { computed } from 'vue';

const props = defineProps({
    profileReadiness: { type: Object, required: true },
    activeApplicationCount: { type: Number, default: 0 },
    priorityAction: { type: Object, required: true },
    readinessItems: { type: Array, default: () => [] },
    applications: { type: Array, default: () => [] },
    recommendations: { type: Array, default: () => [] },
    reminders: { type: Array, default: () => [] },
});

const emit = defineEmits(['open-reminder']);

const allAttentionItems = computed(() => props.reminders.filter((item) => item.key !== 'clear'));
const attentionItems = computed(() => allAttentionItems.value.slice(0, 2));
const recentApplications = computed(() => props.applications.slice(0, 2));
const topRecommendations = computed(() => props.recommendations.slice(0, 1));
const pendingReadinessItems = computed(() => props.readinessItems
    .filter((item) => item.percent !== 100 && item.status !== 'Verified')
    .slice(0, 2));
const dashboardStats = computed(() => [
    {
        label: 'Active applications',
        value: props.activeApplicationCount,
        detail: props.activeApplicationCount === 1 ? 'Application in progress' : 'Applications in progress',
        href: '/dashboard/applications',
        icon: 'fa-solid fa-file-signature',
    },
    {
        label: 'Needs your action',
        value: allAttentionItems.value.length,
        detail: allAttentionItems.value.length ? 'Open tasks and reminders' : 'Nothing urgent right now',
        href: props.priorityAction.href,
        icon: 'fa-solid fa-circle-exclamation',
    },
    {
        label: 'Profile readiness',
        value: `${Number(props.profileReadiness.percent ?? 0)}%`,
        detail: props.profileReadiness.complete ? 'Ready for matching' : 'Complete missing information',
        href: '/dashboard/profile',
        icon: 'fa-solid fa-id-card',
    },
]);

function applicationStatus(application) {
    return application.workflow?.final_outcome_label
        || application.workflow?.current_stage_label
        || String(application.status ?? 'Submitted').replace(/_/g, ' ');
}

function applicationStatusClass(application) {
    if (application.workflow?.is_closed) return 'bg-slate-200 text-slate-700';
    if (application.correction_status === 'requested') return 'bg-amber-100 text-amber-800';

    return 'bg-sky-100 text-sky-800';
}

function reminderClicked(event, reminder) {
    emit('open-reminder', event, reminder);
}
</script>

<template>
    <div class="mt-6 space-y-5">
        <section class="student-card grid overflow-hidden sm:grid-cols-3" aria-label="Dashboard summary">
            <a
                v-for="stat in dashboardStats"
                :key="stat.label"
                :href="stat.href"
                class="group flex items-center gap-3 border-b border-slate-200 p-4 transition hover:bg-slate-50 sm:[&:nth-child(odd)]:border-r xl:border-b-0 xl:border-r xl:last:border-r-0"
            >
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-sm text-slate-700 transition group-hover:bg-slate-950 group-hover:text-amber-300">
                    <i :class="stat.icon" aria-hidden="true"></i>
                </span>
                <span class="min-w-0">
                    <span class="block text-lg font-bold leading-none text-slate-950">{{ stat.value }}</span>
                    <span class="mt-1 block text-xs font-bold text-slate-600">{{ stat.label }}</span>
                </span>
            </a>
        </section>

        <section class="student-card overflow-hidden" aria-labelledby="dashboard-next-steps">
            <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-4 sm:px-5">
                <div>
                    <p class="student-kicker">Task list</p>
                    <h2 id="dashboard-next-steps" class="mt-1 text-lg font-bold text-slate-950">Your next steps</h2>
                </div>
            </header>

            <a :href="priorityAction.href" class="group grid gap-4 border-b border-amber-200 bg-amber-50 p-4 transition hover:bg-amber-100/70 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:px-5">
                <span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md', priorityAction.requiresAttention ? 'bg-amber-300 text-slate-950' : 'bg-slate-950 text-amber-300']">
                    <i :class="priorityAction.icon" aria-hidden="true"></i>
                </span>
                <span class="min-w-0">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-bold text-slate-950">{{ priorityAction.title }}</span>
                        <span class="rounded bg-white/80 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-900 ring-1 ring-amber-200">
                            {{ priorityAction.requiresAttention ? 'Action required' : 'Next step' }}
                        </span>
                    </span>
                    <span class="mt-1 block text-xs leading-5 text-slate-600">{{ priorityAction.prompt }}</span>
                    <span v-if="priorityAction.detail" class="mt-1 block truncate text-xs font-semibold text-slate-500">{{ priorityAction.detail }}</span>
                </span>
                <span class="inline-flex w-fit items-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white transition group-hover:bg-slate-800">
                    {{ priorityAction.button }}
                    <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                </span>
            </a>

            <div v-if="pendingReadinessItems.length" class="divide-y divide-slate-200">
                <a v-for="item in pendingReadinessItems" :key="item.label" :href="item.href" class="group grid gap-3 p-4 transition hover:bg-slate-50 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:px-5">
                    <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-full text-xs', item.percent === 100 || item.status === 'Verified' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">
                        <i :class="item.percent === 100 || item.status === 'Verified' ? 'fa-solid fa-check' : 'fa-solid fa-minus'" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-slate-900">{{ item.label }}</span>
                        <span class="mt-0.5 block truncate text-xs text-slate-500">{{ item.detail }}</span>
                    </span>
                    <span class="flex items-center gap-3 sm:justify-end">
                        <span v-if="item.percent !== null" class="text-xs font-bold text-slate-600">{{ item.percent }}% complete</span>
                        <span v-else :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', item.statusClass]">{{ item.status }}</span>
                        <span class="text-xs font-bold text-slate-900 group-hover:underline">{{ item.action }}</span>
                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300" aria-hidden="true"></i>
                    </span>
                </a>
            </div>
        </section>

        <section v-if="attentionItems.length" class="student-card overflow-hidden">
            <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5">
                <div>
                    <p class="student-kicker">Attention queue</p>
                    <h2 class="mt-1 text-base font-bold text-slate-950">Updates you should review</h2>
                </div>
                <a href="/dashboard/applications?view=action" class="text-xs font-bold text-slate-700 hover:text-slate-950">View action list</a>
            </header>
            <div class="grid divide-y divide-slate-200 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                <a
                    v-for="reminder in attentionItems"
                    :key="reminder.key"
                    :href="reminder.href"
                    class="group flex items-start gap-3 p-4 transition hover:bg-slate-50 sm:px-5"
                    @click="reminderClicked($event, reminder)"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-amber-100 text-xs text-amber-800">
                        <i :class="reminder.icon" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-bold text-slate-900">{{ reminder.title }}</span>
                        <span class="mt-1 line-clamp-2 block text-xs leading-5 text-slate-500">{{ reminder.detail }}</span>
                    </span>
                    <i class="fa-solid fa-arrow-right mt-2 text-[10px] text-slate-300 group-hover:text-slate-600" aria-hidden="true"></i>
                </a>
            </div>
        </section>

        <section class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(19rem,0.65fr)]">
            <article class="student-card overflow-hidden">
                <header class="flex items-center justify-between gap-4 border-b border-slate-200 p-4 sm:p-5">
                    <div>
                        <p class="student-kicker">Recent activity</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950">Application progress</h2>
                    </div>
                    <a href="/dashboard/applications" class="inline-flex items-center gap-2 text-xs font-bold text-slate-700 hover:text-slate-950">View all <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
                </header>

                <div v-if="recentApplications.length" class="divide-y divide-slate-200">
                    <a v-for="application in recentApplications" :key="application.id" :href="application.detail_url || `/dashboard/applications/${application.id}`" class="group grid gap-3 p-4 transition hover:bg-slate-50 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:px-5">
                        <img :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'" :alt="application.scholarship?.title || 'Scholarship application'" class="h-11 w-11 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-bold text-slate-950">{{ application.scholarship?.title || 'Scholarship application' }}</span>
                            <span class="mt-1 block truncate text-xs text-slate-500">{{ application.scholarship?.provider?.name || 'Scholarship provider' }}</span>
                        </span>
                        <span class="flex items-center gap-3 sm:justify-end">
                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', applicationStatusClass(application)]">{{ applicationStatus(application) }}</span>
                            <i class="fa-solid fa-arrow-right text-[10px] text-slate-300 group-hover:text-slate-700" aria-hidden="true"></i>
                        </span>
                    </a>
                </div>
                <div v-else class="p-5">
                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-5 text-center">
                        <p class="text-sm font-bold text-slate-900">No applications yet</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Your latest applications and status changes will appear here.</p>
                        <a href="/dashboard/scholarships" class="mt-3 inline-flex rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white">Find scholarships</a>
                    </div>
                </div>
            </article>

            <article class="student-card overflow-hidden">
                <header class="flex items-center justify-between gap-4 border-b border-slate-200 p-4 sm:p-5">
                    <div>
                        <p class="student-kicker">Opportunities</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950">Strong matches</h2>
                    </div>
                    <a href="/dashboard/scholarships" class="text-xs font-bold text-slate-700 hover:text-slate-950">Browse all</a>
                </header>
                <div v-if="topRecommendations.length" class="divide-y divide-slate-200">
                    <a v-for="scholarship in topRecommendations" :key="scholarship.id" :href="`/dashboard/scholarships/${scholarship.id}`" class="group block p-4 transition hover:bg-slate-50 sm:px-5">
                        <div class="flex items-start gap-3">
                            <img :src="scholarship.image_url || '/uploads/scholarship-default.jpg'" :alt="scholarship.title" class="h-11 w-11 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="line-clamp-2 text-sm font-bold leading-5 text-slate-950">{{ scholarship.title }}</p>
                                    <span class="shrink-0 rounded-md bg-emerald-100 px-2 py-1 text-[10px] font-bold text-emerald-800">{{ scholarship.eligibility_match?.score ?? 0 }}%</span>
                                </div>
                                <p class="mt-1 truncate text-xs text-slate-500">{{ scholarship.provider?.name || 'Scholarship provider' }}</p>
                                <p class="mt-2 text-[11px] font-semibold text-slate-600">Deadline: {{ scholarship.deadline || 'Open deadline' }}</p>
                            </div>
                        </div>
                    </a>
                </div>
                <div v-else class="p-5 text-center">
                    <p class="text-sm font-bold text-slate-900">No matches to show yet</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Complete your profile so the portal can compare published requirements.</p>
                    <a href="/dashboard/profile" class="mt-3 inline-flex rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700">Complete profile</a>
                </div>
            </article>
        </section>
    </div>
</template>
