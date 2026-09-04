<script setup>
const props = defineProps({
    active: {
        type: String,
        default: '',
    },
    states: {
        type: Object,
        default: () => ({}),
    },
    showHeading: {
        type: Boolean,
        default: false,
    },
});

const stages = [
    {
        key: 'organization',
        label: 'Organization',
        description: 'Complete and verify your profile',
        href: '/provider/profile',
    },
    {
        key: 'programs',
        label: 'Programs',
        description: 'Create, submit, and publish',
        href: '/provider/programs',
    },
    {
        key: 'screening',
        label: 'Pre-screening',
        description: 'Check eligibility and files',
        href: '/provider/applications?filter=pending_review',
        requiresReviewAccess: true,
    },
    {
        key: 'stages',
        label: 'Next stages',
        description: 'Manage formal steps and activities',
        href: '/provider/applications?filter=active_stages',
        requiresReviewAccess: true,
    },
    {
        key: 'outcomes',
        label: 'Outcomes',
        description: 'Record decisions and close the cycle',
        href: '/provider/applications?filter=decided',
        requiresReviewAccess: true,
    },
];

const canReviewApplications = Boolean(
    window.portalUser?.can_post_scholarships
        && (
            window.portalUser?.has_full_access
            || window.portalUser?.permissions?.includes('review_applications')
        ),
);

function isLocked(stage) {
    return stage.requiresReviewAccess && !canReviewApplications;
}

function stageState(stage) {
    if (isLocked(stage)) {
        return 'locked';
    }

    return props.states[stage.key] ?? 'pending';
}

function stageClass(stage) {
    if (props.active === stage.key) {
        return 'border-slate-950 bg-slate-950 text-white shadow-sm';
    }

    if (stageState(stage) === 'attention') {
        return 'border-amber-300 bg-amber-50 text-slate-950 hover:border-amber-400';
    }

    if (stageState(stage) === 'locked') {
        return 'cursor-not-allowed border-slate-200 bg-slate-50 text-slate-400';
    }

    return 'border-slate-200 bg-white text-slate-950 hover:border-slate-400 hover:bg-slate-50';
}

function markerClass(stage) {
    if (props.active === stage.key) {
        return 'bg-white/10 text-amber-300';
    }

    if (stageState(stage) === 'complete') {
        return 'bg-emerald-100 text-emerald-700';
    }

    if (stageState(stage) === 'attention') {
        return 'bg-amber-200 text-amber-900';
    }

    return 'bg-slate-100 text-slate-500';
}
</script>

<template>
    <section class="provider-panel overflow-hidden">
        <header v-if="showHeading" class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">From setup to outcome</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Provider workflow</h3>
            </div>
            <p class="max-w-xl text-xs leading-5 text-slate-500">Follow the same path for every scholarship while the portal keeps applicant records together.</p>
        </header>

        <nav class="grid gap-2 p-2 sm:grid-cols-2 xl:grid-cols-5" aria-label="Provider workflow">
            <component
                v-for="(stage, index) in stages"
                :key="stage.key"
                :is="isLocked(stage) ? 'span' : 'a'"
                :href="isLocked(stage) ? undefined : stage.href"
                :aria-current="active === stage.key ? 'step' : undefined"
                :aria-disabled="isLocked(stage) ? 'true' : undefined"
                :class="['group flex min-w-0 items-center gap-3 rounded-md border px-3 py-3 transition', stageClass(stage)]"
            >
                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md text-xs font-bold', markerClass(stage)]">
                    <i v-if="stageState(stage) === 'complete' && active !== stage.key" class="fa-solid fa-check" aria-hidden="true"></i>
                    <i v-else-if="stageState(stage) === 'locked'" class="fa-solid fa-lock text-[10px]" aria-hidden="true"></i>
                    <span v-else>{{ index + 1 }}</span>
                </span>
                <span class="min-w-0">
                    <span class="flex items-center gap-2">
                        <span class="truncate text-sm font-bold">{{ stage.label }}</span>
                        <i v-if="stageState(stage) === 'attention' && active !== stage.key" class="fa-solid fa-circle text-[6px] text-amber-600" aria-label="Action needed"></i>
                    </span>
                    <span :class="['mt-0.5 block truncate text-[11px]', active === stage.key ? 'text-slate-300' : 'text-slate-500']">
                        {{ isLocked(stage) ? 'Available after provider approval' : stage.description }}
                    </span>
                </span>
            </component>
        </nav>
    </section>
</template>
