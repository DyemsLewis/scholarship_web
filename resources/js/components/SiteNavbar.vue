<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'light',
        validator: (value) => ['light', 'transparent'].includes(value),
    },
});

const currentPath = window.location.pathname;
const links = [
    { href: '/', label: 'Home' },
    { href: '/admin', label: 'Admin' },
    { href: '/login', label: 'Login' },
    { href: '/register', label: 'Register' },
];

const isTransparent = computed(() => props.variant === 'transparent');

function isActive(href) {
    return href === '/' ? currentPath === '/' : currentPath.startsWith(href);
}
</script>

<template>
    <header
        :class="[
            'relative z-20 border-b',
            isTransparent ? 'border-white/15 text-white' : 'border-slate-200 bg-white text-slate-900 shadow-sm'
        ]"
    >
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="/" class="font-display text-xl font-bold tracking-normal">
                Scholarship Portal
            </a>

            <div class="hidden items-center gap-1 sm:flex">
                <a
                    v-for="link in links"
                    :key="link.href"
                    :href="link.href"
                    :class="[
                        'rounded-md px-3 py-2 text-sm font-semibold transition',
                        isTransparent
                            ? isActive(link.href) ? 'bg-white/15 text-white' : 'text-white/85 hover:bg-white/10 hover:text-white'
                            : isActive(link.href) ? 'bg-slate-100 text-slate-950' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'
                    ]"
                >
                    {{ link.label }}
                </a>
            </div>

            <div class="flex items-center gap-2 sm:hidden">
                <a
                    href="/login"
                    :class="[
                        'rounded-md px-3 py-2 text-sm font-semibold transition',
                        isTransparent ? 'text-white hover:bg-white/10' : 'text-slate-700 hover:bg-slate-100'
                    ]"
                >
                    Login
                </a>
                <a
                    href="/register"
                    :class="[
                        'rounded-md px-3 py-2 text-sm font-semibold transition',
                        isTransparent ? 'bg-amber-300 text-slate-950 hover:bg-amber-200' : 'bg-slate-900 text-white hover:bg-slate-800'
                    ]"
                >
                    Register
                </a>
            </div>
        </nav>
    </header>
</template>
