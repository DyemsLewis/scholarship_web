<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'light',
        validator: (value) => ['light', 'dark'].includes(value),
    },
});

const currentYear = new Date().getFullYear();
const isDark = computed(() => props.variant === 'dark');

const links = [
    { href: '/', label: 'Home' },
    { href: '/login', label: 'Login' },
    { href: '/register', label: 'Register' },
    { href: '/provider/register', label: 'Provider Registration' },
];
</script>

<template>
    <footer
        :class="[
            'border-t',
            isDark
                ? 'border-white/10 bg-[#081426] text-slate-300'
                : 'border-slate-200 bg-white text-slate-500'
        ]"
    >
        <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
            <div>
                <p :class="['font-display text-lg font-bold', isDark ? 'text-white' : 'text-slate-950']">
                    Scholarship Portal
                </p>
                <p class="mt-2 max-w-xl text-sm leading-6">
                    Applicant and provider access for scholarship opportunities.
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <nav class="flex flex-wrap gap-3 text-sm font-semibold">
                    <a
                        v-for="link in links"
                        :key="link.href"
                        :href="link.href"
                        :class="isDark ? 'text-slate-300 transition hover:text-amber-200' : 'text-slate-600 transition hover:text-slate-950'"
                    >
                        {{ link.label }}
                    </a>
                </nav>

                <p :class="['text-sm', isDark ? 'text-slate-400' : 'text-slate-500']">
                    &copy; {{ currentYear }}
                </p>
            </div>
        </div>
    </footer>
</template>
