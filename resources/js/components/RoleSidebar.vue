<script setup>
import { computed } from 'vue';
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

const currentPath = window.location.pathname.replace(/\/$/, '') || props.homeHref;
const portalUser = window.portalUser ?? {};
const navGroups = computed(() => props.navLinks.reduce((groups, link) => {
    const label = link.section ?? '';
    let group = groups.find((item) => item.label === label);

    if (!group) {
        group = { label, links: [] };
        groups.push(group);
    }

    group.links.push(link);

    return groups;
}, []));
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
const accountRole = computed(() => {
    if (portalUser.account_title) {
        return portalUser.account_title;
    }

    if (portalUser.is_managed_account) {
        return `${props.title} team member`;
    }

    return props.title === 'Admin' ? 'Platform administrator' : 'Provider representative';
});
const accountContext = computed(() => {
    if (props.title === 'Provider' && portalUser.provider_name && portalUser.provider_name !== accountName.value) {
        return portalUser.provider_name;
    }

    return portalUser.email || props.subtitle;
});
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

function isActive(link) {
    if (props.active) {
        return props.active === link.key;
    }

    if (link.exact) {
        return currentPath === link.href;
    }

    if (link.activePaths?.some((path) => currentPath === path || currentPath.startsWith(`${path}/`))) {
        return true;
    }

    return currentPath === link.href || currentPath.startsWith(`${link.href}/`);
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
        <div class="absolute inset-x-0 top-0 h-1 bg-amber-300"></div>
        <div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            <span class="absolute -right-14 top-24 h-36 w-36 rounded-full border border-white/[0.045]"></span>
            <span class="absolute -right-6 top-32 h-20 w-20 rounded-full border border-amber-300/[0.08]"></span>
            <span class="absolute bottom-32 left-5 h-2 w-2 rotate-45 border border-amber-300/20"></span>
        </div>

        <div class="relative flex min-h-72 flex-col px-5 pb-5 pt-7 lg:h-full lg:min-h-0">
            <header class="shrink-0 border-b border-white/10 pb-5">
                <a :href="homeHref" class="group flex items-center gap-3.5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-amber-300 text-base font-black text-slate-950 shadow-[0_8px_20px_rgba(0,0,0,0.22)] ring-4 ring-amber-300/10 transition group-hover:bg-amber-200">
                        <i :class="icon" aria-hidden="true"></i>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-display text-[1.35rem] font-bold leading-tight text-white">
                            {{ title }}
                        </span>
                        <span class="mt-1 inline-flex rounded border border-amber-300/20 bg-amber-300/[0.07] px-2 py-0.5 text-[9px] font-bold uppercase tracking-[0.18em] text-amber-200">
                            {{ subtitle }}
                        </span>
                    </span>
                </a>
            </header>

            <nav class="mt-5 grid content-start gap-4 pr-1 lg:min-h-0 lg:flex-1 lg:overflow-y-auto [scrollbar-color:rgba(148,163,184,0.25)_transparent] [scrollbar-width:thin]" aria-label="Portal navigation">
                <section v-for="group in navGroups" :key="group.label || 'main'">
                    <div v-if="group.label" class="mb-2.5 flex items-center gap-2 px-2">
                        <p class="shrink-0 text-[9px] font-bold uppercase tracking-[0.2em] text-slate-500">
                            {{ group.label }}
                        </p>
                        <span class="h-px flex-1 bg-white/[0.07]"></span>
                    </div>
                    <div class="grid gap-1">
                        <a
                            v-for="link in group.links"
                            :key="link.href"
                            :href="link.href"
                            :aria-current="isActive(link) ? 'page' : undefined"
                            :class="[
                                'group relative flex min-h-11 items-center gap-3 rounded-lg border px-2.5 py-2 text-sm font-bold transition duration-200',
                                isActive(link)
                                    ? 'border-white/15 bg-white text-slate-950 shadow-[0_8px_22px_rgba(0,0,0,0.16)]'
                                    : 'border-transparent text-slate-300 hover:translate-x-0.5 hover:border-white/10 hover:bg-white/[0.045] hover:text-white',
                            ]"
                        >
                            <span
                                v-if="isActive(link)"
                                class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-amber-400"
                            ></span>
                            <span class="flex items-center gap-2 pl-2">
                                <span
                                    :class="[
                                        'grid h-8 w-8 shrink-0 place-items-center rounded-md border text-xs transition',
                                        isActive(link)
                                            ? 'border-slate-950 bg-slate-950 text-amber-300'
                                            : 'border-white/10 bg-white/[0.04] text-slate-400 group-hover:border-amber-300/25 group-hover:text-amber-200',
                                    ]"
                                >
                                    <i :class="link.icon" aria-hidden="true"></i>
                                </span>
                                <span class="min-w-0 truncate">{{ link.label }}</span>
                            </span>
                            <span
                                v-if="link.step"
                                :class="[
                                    'ml-auto rounded px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wide',
                                    isActive(link) ? 'bg-amber-100 text-amber-800' : 'bg-white/[0.06] text-slate-500 group-hover:text-slate-300',
                                ]"
                            >
                                Step {{ link.step }}
                            </span>
                            <span v-else-if="isActive(link)" class="ml-auto h-1.5 w-1.5 rounded-full bg-amber-400" aria-hidden="true"></span>
                        </a>
                    </div>
                </section>
            </nav>

            <div class="mt-4 shrink-0 border-t border-white/10 pt-4">
                <div class="mb-3 rounded-lg border border-white/10 bg-[#0d1d34] p-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="relative grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white/10 text-xs font-black text-amber-200">
                            {{ accountInitials }}
                            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-[#0d1d34] bg-emerald-400" aria-hidden="true"></span>
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-bold text-white">{{ accountName }}</span>
                            <span class="block truncate text-[11px] font-semibold text-slate-400">{{ accountRole }}</span>
                        </span>
                    </div>
                    <p class="mt-2 truncate border-t border-white/[0.07] pt-2 text-[10px] text-slate-500" :title="accountContext">
                        {{ accountContext }}
                    </p>
                </div>

                <NotificationBell align="left" mode="sidebar" centered />
                <EmailVerificationReminder class="mt-3" mode="dark" />
                <button
                    type="button"
                    class="group mt-2.5 flex w-full items-center justify-between rounded-md border border-white/10 px-3 py-2.5 text-sm font-bold text-slate-300 transition hover:border-amber-300/35 hover:bg-white/[0.045] hover:text-white"
                    @click="requestLogout"
                >
                    <span class="flex items-center gap-2.5">
                        <span class="grid h-7 w-7 place-items-center rounded border border-white/10 bg-white/[0.04] text-xs text-slate-400 group-hover:text-amber-200">
                            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                        </span>
                        Logout
                    </span>
                    <i class="fa-solid fa-chevron-right text-[9px] text-slate-600 transition group-hover:translate-x-0.5 group-hover:text-amber-200" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </aside>

    <ConfirmationDialog
        v-bind="confirmation"
        @confirm="confirmConfirmation"
        @cancel="cancelConfirmation"
    />
</template>
