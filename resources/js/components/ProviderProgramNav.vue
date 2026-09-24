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
const directLinks = computed(() => [
    {
        key: 'overview',
        label: 'Home',
        icon: 'fa-solid fa-house',
        href: programBase.value,
    },
    ...(canReviewApplications.value ? [{
        key: 'announcements',
        label: 'Updates',
        icon: 'fa-solid fa-bullhorn',
        href: `${programBase.value}/updates`,
    }] : []),
]);
const groups = computed(() => canReviewApplications.value ? [
    {
        key: 'applications-group',
        label: 'Applications',
        icon: 'fa-solid fa-user-check',
        items: [
            {
                key: 'applicants',
                label: 'Review applicants',
                icon: 'fa-solid fa-file-circle-check',
                href: `${programBase.value}/applications/review`,
            },
            {
                key: 'schedule',
                label: 'Activities',
                icon: 'fa-regular fa-calendar',
                href: `${programBase.value}/applications/activities`,
            },
            {
                key: 'results',
                label: 'Record results',
                icon: 'fa-solid fa-clipboard-check',
                href: `${programBase.value}/applications/results`,
            },
            {
                key: 'decisions',
                label: 'Final decisions',
                icon: 'fa-solid fa-gavel',
                href: `${programBase.value}/applications/decisions`,
            },
        ],
    },
    {
        key: 'recipients-group',
        label: 'Recipients',
        icon: 'fa-solid fa-award',
        items: [
            {
                key: 'recipients',
                label: 'Selected recipients',
                icon: 'fa-solid fa-user-graduate',
                href: `${programBase.value}/applications/recipients`,
            },
            {
                key: 'waitlist',
                label: 'Waitlist',
                icon: 'fa-solid fa-list-ol',
                href: `${programBase.value}/applications/waitlist`,
            },
            {
                key: 'monitoring',
                label: 'Monitoring',
                icon: 'fa-solid fa-chart-line',
                href: `${programBase.value}/monitoring`,
            },
        ],
    },
] : []);

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

const allLinks = computed(() => [
    ...directLinks.value,
    ...groups.value.flatMap((group) => group.items),
]);
const activeLink = computed(() => (
    allLinks.value.find((item) => item.key === activeKey.value)
        ?? directLinks.value[0]
));

function groupIsActive(group) {
    return group.items.some((item) => item.key === activeKey.value);
}
</script>

<template>
    <nav class="mt-4 rounded-lg border border-slate-200 bg-white p-1.5 shadow-sm" aria-label="Program workspace navigation">
        <details class="group sm:hidden">
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-md bg-slate-950 px-3 py-2 text-sm font-bold text-white [&::-webkit-details-marker]:hidden">
                <span class="flex min-w-0 items-center gap-2.5">
                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-white/10 text-xs text-amber-300">
                        <i :class="activeLink.icon" aria-hidden="true"></i>
                    </span>
                    <span class="truncate">{{ activeLink.label }}</span>
                </span>
                <span class="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-300">
                    Change
                    <i class="fa-solid fa-chevron-down text-[9px] transition group-open:rotate-180" aria-hidden="true"></i>
                </span>
            </summary>

            <div class="mt-1.5 space-y-1 rounded-md bg-slate-50 p-1.5">
                <a
                    v-for="link in directLinks.slice(0, 1)"
                    :key="link.key"
                    :href="link.href"
                    :aria-current="activeKey === link.key ? 'page' : undefined"
                    :class="[
                        'flex min-h-10 items-center gap-3 rounded-md px-3 py-2 text-sm font-bold transition',
                        activeKey === link.key
                            ? 'bg-amber-100 text-slate-950'
                            : 'text-slate-600 hover:bg-white hover:text-slate-950',
                    ]"
                >
                    <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-md text-[11px]', activeKey === link.key ? 'bg-amber-200 text-amber-900' : 'bg-white text-slate-500 ring-1 ring-slate-200']">
                        <i :class="link.icon" aria-hidden="true"></i>
                    </span>
                    {{ link.label }}
                </a>

                <section v-for="group in groups" :key="group.key" class="pt-1">
                    <p class="px-3 pb-1 pt-1 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{{ group.label }}</p>
                    <a
                        v-for="item in group.items"
                        :key="item.key"
                        :href="item.href"
                        :aria-current="activeKey === item.key ? 'page' : undefined"
                        :class="[
                            'flex min-h-10 items-center gap-3 rounded-md px-3 py-2 text-sm font-bold transition',
                            activeKey === item.key
                                ? 'bg-amber-100 text-slate-950'
                                : 'text-slate-600 hover:bg-white hover:text-slate-950',
                        ]"
                    >
                        <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-md text-[11px]', activeKey === item.key ? 'bg-amber-200 text-amber-900' : 'bg-white text-slate-500 ring-1 ring-slate-200']">
                            <i :class="item.icon" aria-hidden="true"></i>
                        </span>
                        {{ item.label }}
                    </a>
                </section>

                <section v-if="directLinks.length > 1" class="border-t border-slate-200 pt-1">
                    <a
                        v-for="link in directLinks.slice(1)"
                        :key="link.key"
                        :href="link.href"
                        :aria-current="activeKey === link.key ? 'page' : undefined"
                        :class="[
                            'flex min-h-10 items-center gap-3 rounded-md px-3 py-2 text-sm font-bold transition',
                            activeKey === link.key
                                ? 'bg-amber-100 text-slate-950'
                                : 'text-slate-600 hover:bg-white hover:text-slate-950',
                        ]"
                    >
                        <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-md text-[11px]', activeKey === link.key ? 'bg-amber-200 text-amber-900' : 'bg-white text-slate-500 ring-1 ring-slate-200']">
                            <i :class="link.icon" aria-hidden="true"></i>
                        </span>
                        {{ link.label }}
                    </a>
                </section>
            </div>
        </details>

        <div class="hidden flex-wrap items-center gap-1 sm:flex">
            <a
                v-for="link in directLinks.slice(0, 1)"
                :key="link.key"
                :href="link.href"
                :aria-current="activeKey === link.key ? 'page' : undefined"
                :class="[
                    'inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-bold transition sm:flex-none',
                    activeKey === link.key
                        ? 'bg-slate-950 text-white shadow-sm'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                ]"
            >
                <i :class="[link.icon, activeKey === link.key ? 'text-amber-300' : 'text-slate-400', 'text-xs']" aria-hidden="true"></i>
                {{ link.label }}
            </a>

            <details
                v-for="group in groups"
                :key="group.key"
                class="group relative flex-1 sm:flex-none"
            >
                <summary
                    :class="[
                        'flex min-h-10 cursor-pointer list-none items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-bold transition [&::-webkit-details-marker]:hidden',
                        groupIsActive(group)
                            ? 'bg-slate-950 text-white shadow-sm'
                            : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                    ]"
                >
                    <i :class="[group.icon, groupIsActive(group) ? 'text-amber-300' : 'text-slate-400', 'text-xs']" aria-hidden="true"></i>
                    <span>{{ group.label }}</span>
                    <i class="fa-solid fa-chevron-down ml-1 text-[9px] opacity-60 transition group-open:rotate-180" aria-hidden="true"></i>
                </summary>

                <div class="z-40 mt-1 min-w-full overflow-hidden rounded-md border border-slate-200 bg-white p-1 shadow-xl sm:absolute sm:left-0 sm:w-60">
                    <a
                        v-for="item in group.items"
                        :key="item.key"
                        :href="item.href"
                        :aria-current="activeKey === item.key ? 'page' : undefined"
                        :class="[
                            'flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-bold transition',
                            activeKey === item.key
                                ? 'bg-amber-50 text-slate-950'
                                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                        ]"
                    >
                        <span :class="['grid h-7 w-7 shrink-0 place-items-center rounded-md text-[11px]', activeKey === item.key ? 'bg-amber-200 text-amber-900' : 'bg-slate-100 text-slate-500']">
                            <i :class="item.icon" aria-hidden="true"></i>
                        </span>
                        {{ item.label }}
                    </a>
                </div>
            </details>

            <a
                v-for="link in directLinks.slice(1)"
                :key="link.key"
                :href="link.href"
                :aria-current="activeKey === link.key ? 'page' : undefined"
                :class="[
                    'inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-bold transition sm:flex-none',
                    activeKey === link.key
                        ? 'bg-slate-950 text-white shadow-sm'
                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
                ]"
            >
                <i :class="[link.icon, activeKey === link.key ? 'text-amber-300' : 'text-slate-400', 'text-xs']" aria-hidden="true"></i>
                {{ link.label }}
            </a>
        </div>
    </nav>
</template>
