<script setup>
import { computed, ref } from 'vue';
import TermsModal from './TermsModal.vue';

const props = defineProps({
    context: {
        type: String,
        default: 'public',
        validator: (value) => ['public', 'applicant', 'provider', 'admin'].includes(value),
    },
    panelName: {
        type: String,
        default: 'Scholarship Portal',
    },
    message: {
        type: String,
        default: 'Scholarship discovery, matching, and application support in one place.',
    },
    variant: {
        type: String,
        default: 'dark',
        validator: (value) => ['light', 'dark'].includes(value),
    },
    standalone: {
        type: Boolean,
        default: false,
    },
    fullBleed: {
        type: Boolean,
        default: false,
    },
});

const currentYear = new Date().getFullYear();
const showTermsModal = ref(false);
const footerModalContext = ref('general');
const isDark = computed(() => props.variant === 'dark');
const footerLayoutClass = computed(() => {
    if (!props.fullBleed) {
        return props.standalone ? 'border-x-0 border-b-0' : 'mt-10 border-x-0 border-b-0';
    }

    if (props.context === 'applicant') {
        return 'relative left-1/2 -mb-7 mt-10 w-screen -translate-x-1/2 border-x-0 border-b-0 sm:-mb-8 lg:-mb-10';
    }

    return 'relative left-1/2 -mb-6 mt-10 w-screen -translate-x-1/2 border-x-0 border-b-0 lg:-mb-8 lg:w-[calc(100vw-18rem)]';
});

const contextDetails = computed(() => ({
    public: {
        label: 'Scholarship access',
        links: [
            { href: '/', label: 'Home' },
            { href: '/login', label: 'Applicant login' },
            { href: '/register', label: 'Create account' },
            { href: '/provider/register', label: 'Provider registration' },
        ],
    },
    applicant: {
        label: 'Applicant workspace',
        links: [
            { href: '/dashboard', label: 'Dashboard' },
            { href: '/dashboard/scholarships', label: 'Scholarships' },
            { href: '/dashboard/applications', label: 'Applications' },
            { href: '/dashboard/profile', label: 'Profile' },
        ],
    },
    provider: {
        label: 'Provider workspace',
        links: [
            { href: '/provider', label: 'Dashboard' },
            { href: '/provider/programs', label: 'Programs' },
            { href: '/provider/applications', label: 'Applications' },
            { href: '/provider/profile', label: 'Profile' },
        ],
    },
    admin: {
        label: 'Admin workspace',
        links: [
            { href: '/admin', label: 'Dashboard' },
            { href: '/admin/reviews', label: 'Reviews' },
            { href: '/admin/manage-users', label: 'Manage users' },
            { href: '/admin/logs', label: 'Activity logs' },
        ],
    },
})[props.context]);

function showFooterNotice(context) {
    footerModalContext.value = context;
    showTermsModal.value = true;
}
</script>

<template>
    <footer
        :class="[
            'border',
            footerLayoutClass,
            isDark
                ? 'border-white/10 bg-[#081426] text-slate-300'
                : 'border-slate-200 bg-white text-slate-600',
        ]"
    >
        <div :class="standalone || fullBleed ? 'mx-auto max-w-6xl px-4 sm:px-6 lg:px-8' : ''">
            <div class="grid gap-7 px-5 py-7 sm:px-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)] lg:items-start">
                <div class="flex items-start gap-4">
                    <span
                        :class="[
                            'flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-base',
                            isDark ? 'bg-amber-300 text-slate-950' : 'bg-slate-950 text-amber-200',
                        ]"
                    >
                        <i class="fa-solid fa-award" aria-hidden="true"></i>
                    </span>
                    <div>
                        <p :class="['text-xs font-bold uppercase tracking-[0.18em]', isDark ? 'text-amber-200' : 'text-amber-700']">
                            {{ contextDetails.label }}
                        </p>
                        <h2 :class="['mt-1 font-display text-xl font-bold', isDark ? 'text-white' : 'text-slate-950']">
                            {{ panelName }}
                        </h2>
                        <p :class="['mt-2 max-w-xl text-sm leading-6', isDark ? 'text-slate-400' : 'text-slate-600']">
                            {{ message }}
                        </p>
                    </div>
                </div>

                <div>
                    <p :class="['text-xs font-bold uppercase tracking-[0.18em]', isDark ? 'text-slate-400' : 'text-slate-500']">
                        Quick links
                    </p>
                    <nav class="mt-3 grid grid-cols-2 gap-x-5 gap-y-2 text-sm font-semibold" aria-label="Footer navigation">
                        <a
                            v-for="link in contextDetails.links"
                            :key="link.href"
                            :href="link.href"
                            :class="isDark
                                ? 'text-slate-300 transition hover:text-amber-200'
                                : 'text-slate-700 transition hover:text-slate-950'"
                        >
                            {{ link.label }}
                        </a>
                    </nav>
                </div>
            </div>

            <div
                :class="[
                    'flex flex-col gap-2 border-t px-5 py-4 text-xs sm:flex-row sm:items-center sm:justify-between sm:px-6',
                    isDark ? 'border-white/10 text-slate-400' : 'border-slate-200 text-slate-500',
                ]"
            >
                <p>&copy; {{ currentYear }} Scholarship Portal. All rights reserved.</p>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <button
                        type="button"
                        :class="isDark
                            ? 'w-fit font-semibold text-slate-300 transition hover:text-amber-200'
                            : 'w-fit font-semibold text-slate-700 transition hover:text-slate-950'"
                        @click="showFooterNotice('general')"
                    >
                        Terms
                    </button>
                    <button
                        type="button"
                        :class="isDark
                            ? 'w-fit font-semibold text-slate-300 transition hover:text-amber-200'
                            : 'w-fit font-semibold text-slate-700 transition hover:text-slate-950'"
                        @click="showFooterNotice('privacy')"
                    >
                        Privacy Notice
                    </button>
                </div>
            </div>
        </div>

        <TermsModal v-model="showTermsModal" :context="footerModalContext" />
    </footer>
</template>
