<script setup>
import { computed } from 'vue';

const props = defineProps({
    programId: {
        type: [Number, String],
        required: true,
    },
    active: {
        type: String,
        default: '',
    },
});

const canReviewApplications = computed(() => Boolean(
    window.portalUser?.can_post_scholarships
        && (
            window.portalUser?.has_full_access
            || window.portalUser?.permissions?.includes('review_applications')
        ),
));
const programBase = computed(() => `/provider/programs/${props.programId}`);
const applicationLinks = computed(() => canReviewApplications.value ? [
    {
        key: 'applicants',
        label: 'Review',
        href: `${programBase.value}/applications/review`,
    },
    {
        key: 'schedule',
        label: 'Activities',
        href: `${programBase.value}/applications/activities`,
    },
    {
        key: 'results',
        label: 'Results',
        href: `${programBase.value}/applications/results`,
    },
    {
        key: 'decisions',
        label: 'Decisions',
        href: `${programBase.value}/applications/decisions`,
    },
] : []);
const recipientLinks = computed(() => canReviewApplications.value ? [
    {
        key: 'recipients',
        label: 'Selected',
        href: `${programBase.value}/applications/recipients`,
    },
    {
        key: 'waitlist',
        label: 'Waitlist',
        href: `${programBase.value}/applications/waitlist`,
    },
] : []);
const primaryLinks = computed(() => [
    {
        key: 'overview',
        label: 'Overview',
        icon: 'fa-solid fa-house',
        href: programBase.value,
    },
    ...(canReviewApplications.value ? [
        {
            key: 'applications',
            label: 'Applications',
            icon: 'fa-solid fa-user-check',
            href: applicationLinks.value[0].href,
        },
        {
            key: 'recipients-section',
            label: 'Recipients',
            icon: 'fa-solid fa-award',
            href: recipientLinks.value[0].href,
        },
        {
            key: 'monitoring',
            label: 'Monitoring',
            icon: 'fa-solid fa-chart-line',
            href: `${programBase.value}/monitoring`,
        },
        {
            key: 'announcements',
            label: 'Updates',
            icon: 'fa-solid fa-bullhorn',
            href: `${programBase.value}/updates`,
        },
    ] : []),
]);

const activeKey = computed(() => {
    const path = window.location.pathname.replace(/\/$/, '');

    if (path.endsWith('/applications/review')) return 'applicants';
    if (path.endsWith('/applications/activities')) return 'schedule';
    if (path.endsWith('/applications/results')) return 'results';
    if (path.endsWith('/applications/decisions')) return 'decisions';
    if (path.endsWith('/applications/recipients')) return 'recipients';
    if (path.endsWith('/applications/waitlist')) return 'waitlist';

    if (path.endsWith('/applications')) {
        const params = new URLSearchParams(window.location.search);
        const filter = params.get('filter');

        if (params.get('workspace') === 'schedule') return 'schedule';
        if (filter === 'ready_result') return 'results';
        if (filter === 'final_decision') return 'decisions';
        if (filter === 'selected') return 'recipients';
        if (filter === 'waitlisted') return 'waitlist';

        return 'applicants';
    }

    if (path.includes('/monitoring')) return 'monitoring';
    if (path.endsWith('/updates')) return 'announcements';

    return props.active || 'overview';
});

const activePrimaryKey = computed(() => {
    if (applicationLinks.value.some((item) => item.key === activeKey.value)) return 'applications';
    if (recipientLinks.value.some((item) => item.key === activeKey.value)) return 'recipients-section';

    return activeKey.value;
});
const secondaryLinks = computed(() => {
    if (activePrimaryKey.value === 'applications') return applicationLinks.value;
    if (activePrimaryKey.value === 'recipients-section') return recipientLinks.value;

    return [];
});
const secondaryLabel = computed(() => (
    activePrimaryKey.value === 'applications' ? 'Application workflow' : 'Recipient records'
));
</script>

<template>
    <nav class="mt-3 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" aria-label="Program workspace navigation">
        <div class="overflow-x-auto overscroll-x-contain p-1.5 [scrollbar-width:thin]">
            <div class="grid gap-1" :style="{ gridTemplateColumns: `repeat(${primaryLinks.length}, minmax(7rem, 1fr))`, minWidth: `${primaryLinks.length * 7}rem` }">
                <a
                    v-for="link in primaryLinks"
                    :key="link.key"
                    :href="link.href"
                    :aria-current="activeKey === link.key ? 'page' : undefined"
                    :class="[
                        'inline-flex min-h-10 min-w-0 items-center justify-center rounded-md px-2 py-2 text-xs font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-1 sm:text-sm',
                        activeKey === link.key
                            ? 'bg-slate-950 text-white shadow-sm'
                            : activePrimaryKey === link.key
                                ? 'text-slate-950'
                                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                    ]"
                >
                    <span class="truncate">{{ link.label }}</span>
                </a>
            </div>
        </div>

        <div v-if="secondaryLinks.length" class="overflow-x-auto overscroll-x-contain border-t border-slate-200 bg-slate-50 px-2 py-1.5 [scrollbar-width:thin]">
            <div class="grid gap-1" :style="{ gridTemplateColumns: `repeat(${secondaryLinks.length}, minmax(7rem, 1fr))`, minWidth: `${secondaryLinks.length * 7}rem` }" role="navigation" :aria-label="secondaryLabel">
                <a
                    v-for="link in secondaryLinks"
                    :key="link.key"
                    :href="link.href"
                    :aria-current="activeKey === link.key ? 'page' : undefined"
                    :class="[
                        'inline-flex min-h-9 min-w-0 items-center justify-center rounded-md px-2 py-1.5 text-xs font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-1',
                        activeKey === link.key
                            ? 'bg-slate-950 text-white shadow-sm'
                            : 'text-slate-600 hover:bg-white hover:text-slate-950',
                    ]"
                >
                    <span class="truncate">{{ link.label }}</span>
                </a>
            </div>
        </div>
    </nav>
</template>
