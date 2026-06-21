<script setup>
import { ref } from 'vue';

const emit = defineEmits(['logout']);
const currentPath = window.location.pathname.replace(/\/$/, '') || '/dashboard';
const isMenuOpen = ref(false);

const navLinks = [
    { href: '/dashboard', label: 'Dashboard', detail: 'Applicant overview' },
    { href: '/dashboard/scholarships', label: 'Scholarships', detail: 'Available programs' },
    { href: '/dashboard/applications', label: 'Applications', detail: 'Submission status' },
    { href: '/dashboard/profile', label: 'Profile', detail: 'Applicant record' },
];

function isActive(href) {
    if (href === '/dashboard') {
        return currentPath === href;
    }

    return currentPath === href || currentPath.startsWith(`${href}/`);
}

function closeMenu() {
    isMenuOpen.value = false;
}

function logout() {
    closeMenu();
    emit('logout');
}
</script>

<template>
    <header class="sticky top-0 z-40 border-b border-white/10 bg-[#081426]/95 text-white shadow-[0_12px_36px_rgba(8,20,38,0.22)] backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a href="/dashboard" class="group flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-amber-300 font-display text-sm font-bold text-slate-950">
                    S
                </span>
                <span>
                    <span class="block font-display text-lg font-bold leading-tight text-white">
                        Scholarship Portal
                    </span>
                    <span class="block text-xs font-semibold text-slate-300">
                        Student workspace
                    </span>
                </span>
            </a>

            <nav class="hidden items-center gap-1 md:flex">
                <a
                    v-for="link in navLinks"
                    :key="link.href"
                    :href="link.href"
                    :class="[
                        'rounded-md px-3 py-2 text-sm font-semibold transition lg:px-4',
                        isActive(link.href)
                            ? 'bg-white text-slate-950 shadow-sm'
                            : 'text-slate-200 hover:bg-white/10 hover:text-white',
                    ]"
                >
                    {{ link.label }}
                </a>
            </nav>

            <div class="hidden items-center gap-3 md:flex">
                <span class="rounded-md bg-white/10 px-3 py-2 text-xs font-semibold text-slate-200 ring-1 ring-white/10">
                    Applicant
                </span>
                <button
                    type="button"
                    class="rounded-md border border-white/20 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:bg-white hover:text-slate-950"
                    @click="logout"
                >
                    Logout
                </button>
            </div>

            <button
                type="button"
                class="rounded-md border border-white/20 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white hover:text-slate-950 md:hidden"
                @click="isMenuOpen = true"
            >
                Menu
            </button>
        </div>
    </header>

    <div
        v-if="isMenuOpen"
        class="fixed inset-0 z-50 bg-slate-950/40 backdrop-blur-sm md:hidden"
        @click.self="closeMenu"
    >
        <aside class="h-full w-[min(21rem,86vw)] bg-[#081426] text-white shadow-2xl">
            <div class="flex h-full flex-col gap-6 px-5 py-6">
                <div class="flex items-start justify-between gap-4">
                    <a href="/dashboard" class="font-display text-xl font-bold text-white" @click="closeMenu">
                        Scholarship Portal
                    </a>
                    <button
                        type="button"
                        class="rounded-md border border-white/20 px-3 py-2 text-sm font-semibold text-slate-100 transition hover:bg-white hover:text-slate-950"
                        @click="closeMenu"
                    >
                        Close
                    </button>
                </div>

                <div>
                    <p class="inline-flex rounded-md bg-amber-300/15 px-3 py-1.5 text-xs font-semibold text-amber-100 ring-1 ring-amber-200/20">
                        Applicant Access
                    </p>
                    <h1 class="mt-4 font-display text-2xl leading-tight font-bold text-white">
                        Student Workspace
                    </h1>
                    <p class="mt-2 text-sm leading-6 text-slate-300">
                        Manage scholarships, applications, and profile details.
                    </p>
                </div>

                <nav class="grid gap-2">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        :class="[
                            'rounded-md border px-4 py-3 transition hover:bg-slate-50',
                            isActive(link.href)
                                ? 'border-white bg-white text-slate-950'
                                : 'border-white/10 bg-white/5 text-slate-200 hover:text-slate-950',
                        ]"
                        @click="closeMenu"
                    >
                        <span class="block text-sm font-bold">
                            {{ link.label }}
                        </span>
                        <span :class="['mt-1 block text-xs', isActive(link.href) ? 'text-slate-600' : 'text-slate-400']">
                            {{ link.detail }}
                        </span>
                    </a>
                </nav>

                <div class="mt-auto rounded-lg border border-white/10 bg-white/5 p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">
                        Applicant Account
                    </p>
                    <p class="mt-2 text-sm leading-6 text-slate-300">
                        Signed in to the applicant scholarship workspace.
                    </p>
                    <button
                        type="button"
                        class="mt-4 w-full rounded-md border border-white/20 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white hover:text-slate-950"
                        @click="logout"
                    >
                        Logout
                    </button>
                </div>
            </div>
        </aside>
    </div>
</template>
