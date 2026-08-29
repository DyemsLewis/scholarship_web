<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    programId: {
        type: [Number, String],
        required: true,
    },
    active: {
        type: String,
        default: '',
    },
    canManage: {
        type: Boolean,
        default: false,
    },
});

const currentHash = ref(window.location.hash);
const canReviewApplications = computed(() => Boolean(
    window.portalUser?.can_post_scholarships
        && (
            window.portalUser?.has_full_access
            || window.portalUser?.permissions?.includes('review_applications')
        ),
));
const links = computed(() => [
    {
        key: 'overview',
        label: 'Overview',
        icon: 'fa-solid fa-table-columns',
        href: `/provider/programs/${props.programId}`,
    },
    ...(canReviewApplications.value ? [{
        key: 'applicants',
        label: 'Applicants',
        icon: 'fa-solid fa-user-check',
        href: `/provider/programs/${props.programId}/applications?workspace=applications`,
    }, {
        key: 'schedule',
        label: 'Schedule',
        icon: 'fa-regular fa-calendar',
        href: `/provider/programs/${props.programId}/applications?workspace=schedule`,
    }] : []),
    {
        key: 'announcements',
        label: 'Announcements',
        icon: 'fa-solid fa-bullhorn',
        href: `/provider/programs/${props.programId}#announcements`,
    },
    ...(props.canManage ? [{
        key: 'settings',
        label: 'Settings',
        icon: 'fa-solid fa-sliders',
        href: `/provider/programs/${props.programId}/edit`,
    }] : []),
]);

const activeKey = computed(() => {
    if (props.active) {
        return props.active;
    }

    const path = window.location.pathname.replace(/\/$/, '');

    if (path.endsWith('/edit')) {
        return 'settings';
    }

    if (path.endsWith('/applications')) {
        return new URLSearchParams(window.location.search).get('workspace') === 'schedule'
            ? 'schedule'
            : 'applicants';
    }

    return currentHash.value === '#announcements' ? 'announcements' : 'overview';
});

function updateHash() {
    currentHash.value = window.location.hash;
}

onMounted(() => window.addEventListener('hashchange', updateHash));
onBeforeUnmount(() => window.removeEventListener('hashchange', updateHash));
</script>

<template>
    <nav class="mt-5 flex gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1.5 shadow-sm" aria-label="Program workspace navigation">
        <a
            v-for="link in links"
            :key="link.key"
            :href="link.href"
            :aria-current="activeKey === link.key ? 'page' : undefined"
            :class="[
                'inline-flex min-w-fit flex-1 items-center justify-center gap-2 rounded-md px-3 py-2.5 text-sm font-bold transition',
                activeKey === link.key
                    ? 'bg-slate-950 text-white shadow-sm'
                    : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
            ]"
        >
            <i :class="[link.icon, activeKey === link.key ? 'text-amber-300' : 'text-slate-400', 'text-xs']" aria-hidden="true"></i>
            {{ link.label }}
        </a>
    </nav>
</template>
