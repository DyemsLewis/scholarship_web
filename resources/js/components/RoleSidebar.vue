<script setup>
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
    <aside class="relative overflow-visible border-r border-slate-800 bg-slate-950 text-white lg:sticky lg:top-0 lg:h-screen">
        <div class="absolute inset-x-0 top-0 h-px bg-amber-300/70"></div>

        <div class="relative flex min-h-72 flex-col px-5 py-6 lg:h-full">
            <a :href="homeHref" class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-amber-300 text-sm font-black text-slate-950">
                    <i :class="icon"></i>
                </div>
                <div>
                    <p class="font-display text-xl font-bold text-white">
                        {{ title }}
                    </p>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                        {{ subtitle }}
                    </p>
                </div>
            </a>

            <nav class="mt-8 grid gap-1.5 lg:overflow-y-auto">
                <a
                    v-for="link in navLinks"
                    :key="link.href"
                    :href="link.href"
                    :class="[
                        'group relative rounded-md px-3 py-2.5 text-sm font-bold transition',
                        isActive(link)
                            ? 'bg-white text-slate-950'
                            : 'text-slate-400 hover:bg-white/5 hover:text-white',
                    ]"
                >
                    <span
                        v-if="isActive(link)"
                        class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-amber-500"
                    ></span>
                    <span class="flex items-center gap-2 pl-2">
                        <i :class="[link.icon, 'w-4 text-center text-xs']"></i>
                        {{ link.label }}
                    </span>
                </a>
            </nav>

            <div class="mt-6">
                <NotificationBell align="left" mode="sidebar" centered />
            </div>

            <EmailVerificationReminder class="mt-4" mode="dark" />

            <div class="mt-6 border-t border-white/10 pt-4 lg:mt-auto">
                <button
                    type="button"
                    class="w-full rounded-md border border-white/10 px-4 py-2.5 text-sm font-bold text-slate-300 transition hover:border-amber-300/40 hover:bg-white/5 hover:text-white"
                    @click="requestLogout"
                >
                    <i class="fa-solid fa-right-from-bracket mr-2"></i>
                    Logout
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
