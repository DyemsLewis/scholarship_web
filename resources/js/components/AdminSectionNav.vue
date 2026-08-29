<script setup>
import { computed } from 'vue';

const props = defineProps({
    section: {
        type: String,
        required: true,
    },
});

const currentPath = window.location.pathname.replace(/\/$/, '') || '/admin';
const hasPermission = (permission) => Boolean(
    window.portalUser?.has_full_access
        || window.portalUser?.permissions?.includes(permission),
);
const catalog = {
    operations: [
        {
            href: '/admin/reports',
            label: 'Reported issues',
            description: 'Applicant concerns and resolutions',
            icon: 'fa-solid fa-circle-exclamation',
            permission: 'manage_reports',
        },
        {
            href: '/admin/billing',
            label: 'Service requests',
            description: 'Provider support and fulfillment',
            icon: 'fa-solid fa-headset',
            permission: 'manage_billing',
        },
        {
            href: '/admin/logs',
            label: 'Activity logs',
            description: 'Security and account activity',
            icon: 'fa-solid fa-clock-rotate-left',
            permission: 'view_logs',
        },
    ],
};
const links = computed(() => (catalog[props.section] ?? []).filter(
    (link) => !link.permission || hasPermission(link.permission),
));

function isActive(link) {
    return currentPath === link.href || currentPath.startsWith(`${link.href}/`);
}
</script>

<template>
    <nav
        v-if="links.length > 1"
        class="mt-5 grid gap-1 rounded-lg border border-slate-200 bg-white p-1.5 shadow-sm sm:grid-flow-col sm:auto-cols-fr"
        aria-label="Admin operations"
    >
        <a
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            :aria-current="isActive(link) ? 'page' : undefined"
            :class="[
                'flex items-center gap-3 rounded-md px-3 py-2.5 transition',
                isActive(link)
                    ? 'bg-slate-950 text-white'
                    : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950',
            ]"
        >
            <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs', isActive(link) ? 'bg-white/10 text-amber-300' : 'bg-slate-100 text-slate-700']">
                <i :class="link.icon" aria-hidden="true"></i>
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-bold">{{ link.label }}</span>
                <span :class="['mt-0.5 block truncate text-xs', isActive(link) ? 'text-slate-300' : 'text-slate-500']">{{ link.description }}</span>
            </span>
        </a>
    </nav>
</template>
