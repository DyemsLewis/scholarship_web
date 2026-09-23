<script setup>
import { computed, ref } from 'vue';
import ConfirmationDialog from './ConfirmationDialog.vue';
import EmailVerificationReminder from './EmailVerificationReminder.vue';
import NotificationBell from './NotificationBell.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';

const props = defineProps({
    active: {
        type: String,
        default: '',
    },
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        required: true,
    },
    icon: {
        type: String,
        required: true,
    },
    homeHref: {
        type: String,
        required: true,
    },
    navLinks: {
        type: Array,
        required: true,
    },
    logoutMessage: {
        type: String,
        required: true,
    },
});

const currentUrl = new URL(window.location.href);
const currentPath = currentUrl.pathname.replace(/\/$/, '') || props.homeHref;
const portalUser = window.portalUser ?? {};
const accountName = computed(() => (
    portalUser.display_name
    || portalUser.name
    || portalUser.username
    || props.title
));
const accountInitials = computed(() => {
    const parts = accountName.value
        .trim()
        .split(/\s+/)
        .filter(Boolean);

    if (parts.length === 0) {
        return props.title.slice(0, 1).toUpperCase();
    }

    return `${parts[0][0]}${parts.length > 1 ? parts.at(-1)[0] : ''}`.toUpperCase();
});
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

function linkPath(link) {
    return new URL(link.href, window.location.origin).pathname.replace(/\/$/, '') || '/';
}

function linkQueryMatches(link) {
    const targetUrl = new URL(link.href, window.location.origin);

    if ([...targetUrl.searchParams].length === 0) {
        return !link.queryless || [...currentUrl.searchParams].length === 0;
    }

    return [...targetUrl.searchParams].every(([key, value]) => currentUrl.searchParams.get(key) === value);
}

function linkHashMatches(link) {
    const targetUrl = new URL(link.href, window.location.origin);

    if (targetUrl.hash) {
        return currentUrl.hash === targetUrl.hash;
    }

    return !link.hashless || !currentUrl.hash;
}

function isActive(link) {
    if (props.active) {
        return props.active === link.key;
    }

    const targetPath = linkPath(link);

    if (link.exact) {
        return currentPath === targetPath && linkQueryMatches(link) && linkHashMatches(link);
    }

    if (link.activePaths?.some((path) => currentPath === path || currentPath.startsWith(`${path}/`))) {
        return true;
    }

    return (currentPath === targetPath || currentPath.startsWith(`${targetPath}/`))
        && linkQueryMatches(link)
        && linkHashMatches(link);
}

function isGroupActive(link) {
    const targetPath = linkPath(link);

    return currentPath === targetPath
        || currentPath.startsWith(`${targetPath}/`)
        || link.activePaths?.some((path) => currentPath === path || currentPath.startsWith(`${path}/`))
        || link.children?.some((child) => isActive(child));
}

function groupKey(link) {
    return link.key || link.href;
}

const expandedGroups = ref(new Set(
    props.navLinks
        .filter((link) => link.children?.length && isGroupActive(link))
        .map(groupKey),
));

function isGroupExpanded(link) {
    return expandedGroups.value.has(groupKey(link));
}

function toggleGroup(link) {
    const nextGroups = new Set(expandedGroups.value);
    const key = groupKey(link);

    if (nextGroups.has(key)) {
        nextGroups.delete(key);
    } else {
        nextGroups.add(key);
    }

    expandedGroups.value = nextGroups;
}

async function requestLogout() {
    const confirmed = await requestConfirmation({
        title: 'Log out of your account?',
        message: props.logoutMessage,
        confirmLabel: 'Log out',
        tone: 'danger',
    });

    if (!confirmed) {
        return;
    }

    await window.axios.post('/logout');
    window.location.href = '/';
}
</script>

<template>
    <aside class="relative overflow-visible border-r border-white/10 bg-[#081426] text-white lg:sticky lg:top-0 lg:h-screen">
        <div class="absolute inset-x-0 top-0 h-0.5 bg-amber-300"></div>

        <div class="relative flex min-h-64 flex-col px-4 pb-4 pt-5 lg:h-full lg:min-h-0">
            <header class="shrink-0 pb-4">
                <a :href="homeHref" class="group flex items-center gap-3 rounded-md px-1 py-1">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-amber-300 text-sm font-black text-slate-950 transition group-hover:bg-amber-200">
                        <i :class="icon" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-display text-lg font-bold leading-tight text-white">
                            {{ title }}
                        </span>
                        <span class="mt-0.5 block truncate text-[9px] font-bold uppercase tracking-[0.2em] text-slate-400">
                            {{ subtitle }}
                        </span>
                    </span>
                </a>
            </header>

            <nav class="grid content-start gap-1 border-t border-white/10 pt-4 sm:grid-cols-2 lg:min-h-0 lg:flex-1 lg:grid-cols-1 lg:overflow-y-auto [scrollbar-color:rgba(148,163,184,0.25)_transparent] [scrollbar-width:thin]" aria-label="Portal navigation">
                <div
                    v-for="link in navLinks"
                    :key="link.href"
                >
                    <button
                        v-if="link.children?.length"
                        type="button"
                        :class="[
                            'group relative flex min-h-10 w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-semibold transition',
                            isGroupActive(link)
                                ? 'bg-white/[0.09] text-white'
                                : 'text-slate-400 hover:bg-white/[0.05] hover:text-white',
                        ]"
                        :aria-expanded="isGroupExpanded(link)"
                        @click="toggleGroup(link)"
                    >
                        <span v-if="isGroupActive(link)" class="absolute inset-y-2 left-0 w-0.5 rounded-r-full bg-amber-300"></span>
                        <span :class="['grid h-6 w-6 shrink-0 place-items-center text-xs transition', isGroupActive(link) ? 'text-amber-300' : 'text-slate-500 group-hover:text-slate-300']">
                            <i :class="link.icon" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0 flex-1 truncate">{{ link.label }}</span>
                        <i :class="['fa-solid fa-chevron-down text-[9px] transition-transform', isGroupExpanded(link) ? 'rotate-180 text-slate-300' : 'text-slate-600']" aria-hidden="true"></i>
                    </button>

                    <div v-if="link.children?.length && isGroupExpanded(link)" class="ml-9 mt-1 grid gap-0.5 border-l border-white/10 pl-2">
                        <a
                            v-for="child in link.children"
                            :key="child.href"
                            :href="child.href"
                            :aria-current="isActive(child) ? 'page' : undefined"
                            :class="[
                                'rounded-md px-3 py-2 text-xs font-semibold transition',
                                isActive(child)
                                    ? 'bg-amber-300 text-slate-950'
                                    : 'text-slate-500 hover:bg-white/[0.05] hover:text-white',
                            ]"
                        >
                            {{ child.label }}
                        </a>
                    </div>

                    <a
                        v-if="!link.children?.length"
                        :href="link.href"
                        :aria-current="isActive(link) ? 'page' : undefined"
                        :class="[
                            'group relative flex min-h-10 items-center gap-3 rounded-md px-3 py-2 text-sm font-semibold transition',
                            isActive(link)
                                ? 'bg-white/[0.09] text-white'
                                : 'text-slate-400 hover:bg-white/[0.05] hover:text-white',
                        ]"
                    >
                        <span v-if="isActive(link)" class="absolute inset-y-2 left-0 w-0.5 rounded-r-full bg-amber-300"></span>
                        <span :class="['grid h-6 w-6 shrink-0 place-items-center text-xs transition', isActive(link) ? 'text-amber-300' : 'text-slate-500 group-hover:text-slate-300']">
                            <i :class="link.icon" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0 truncate">{{ link.label }}</span>
                    </a>
                </div>
            </nav>

            <div class="mt-4 shrink-0 border-t border-white/10 pt-3">
                <div class="flex min-w-0 items-center gap-2.5 px-2 py-1.5">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-white/[0.08] text-[11px] font-black text-amber-200">
                        {{ accountInitials }}
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-white">{{ accountName }}</span>
                        <span class="block text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-500">Signed in</span>
                    </span>
                </div>

                <div class="mt-2 grid gap-0.5 sm:grid-cols-2 lg:grid-cols-1">
                    <NotificationBell align="left" mode="sidebar-compact" centered />
                    <button
                        type="button"
                        class="group flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-semibold text-slate-400 transition hover:bg-white/[0.05] hover:text-white"
                        @click="requestLogout"
                    >
                        <span class="grid h-6 w-6 shrink-0 place-items-center text-xs text-slate-500 transition group-hover:text-slate-300">
                            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                        </span>
                        Logout
                    </button>
                </div>
                <EmailVerificationReminder class="mt-2" mode="dark" />
            </div>
        </div>
    </aside>

    <ConfirmationDialog
        v-bind="confirmation"
        @confirm="confirmConfirmation"
        @cancel="cancelConfirmation"
    />
</template>
