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
    return currentPath === href;
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
    <header class="sticky top-0 z-40 overflow-hidden border-b border-white/10 bg-[#081426] text-white shadow-lg shadow-slate-950/10">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_16%_0%,_rgba(56,189,248,0.18),_transparent_30%),radial-gradient(circle_at_86%_8%,_rgba(250,204,21,0.12),_transparent_24%),linear-gradient(90deg,_rgba(8,20,38,0.98),_rgba(15,23,42,0.96))]"></div>

        <div class="relative mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a href="/dashboard" class="group flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-md border border-sky-200/25 bg-sky-300/10 font-display text-lg font-bold text-amber-200">
                    S
                </span>
                <span>
                    <span class="block font-display text-xl font-bold leading-tight text-white">
                        Scholarship Portal
                    </span>
                    <span class="block text-xs font-semibold uppercase tracking-[0.18em] text-sky-100/75">
                        Student Workspace
                    </span>
                </span>
            </a>

            <nav class="hidden items-center gap-1 md:flex">
                <a
                    v-for="link in navLinks"
                    :key="link.href"
                    :href="link.href"
                    :class="[
                        'rounded-md px-3 py-2 text-sm font-bold transition hover:bg-white/10 hover:text-white lg:px-4',
                        isActive(link.href)
                            ? 'bg-sky-300/15 text-white ring-1 ring-sky-200/25'
                            : 'text-slate-300',
                    ]"
                >
                    {{ link.label }}
                </a>
            </nav>

            <div class="hidden items-center gap-3 md:flex">
                <span class="rounded-md border border-white/10 bg-white/[0.04] px-3 py-2 text-xs font-semibold uppercase tracking-[0.16em] text-slate-300">
                    Applicant
                </span>
                <button
                    type="button"
                    class="rounded-md border border-white/20 px-4 py-2 text-sm font-bold text-white transition hover:border-sky-200/50 hover:bg-white/10"
                    @click="logout"
                >
                    Logout
                </button>
            </div>

            <button
                type="button"
                class="rounded-md border border-white/20 px-3 py-2 text-sm font-bold text-white transition hover:border-sky-200/50 hover:bg-white/10 md:hidden"
                @click="isMenuOpen = true"
            >
                Menu
            </button>
        </div>
    </header>

    <div
        v-if="isMenuOpen"
        class="fixed inset-0 z-50 bg-slate-950/55 backdrop-blur-sm md:hidden"
        @click.self="closeMenu"
    >
        <aside class="relative h-full w-[min(21rem,86vw)] overflow-hidden bg-[#081426] text-white shadow-2xl">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_18%_10%,_rgba(56,189,248,0.18),_transparent_30%),radial-gradient(circle_at_88%_20%,_rgba(250,204,21,0.14),_transparent_28%),linear-gradient(180deg,_rgba(8,20,38,0.98),_rgba(15,23,42,0.96))]"></div>

            <div class="relative flex h-full flex-col gap-7 px-5 py-6">
                <div class="flex items-start justify-between gap-4">
                    <a href="/dashboard" class="font-display text-2xl font-bold text-white" @click="closeMenu">
                        Scholarship Portal
                    </a>
                    <button
                        type="button"
                        class="rounded-md border border-white/20 px-3 py-2 text-sm font-bold text-white transition hover:bg-white/10"
                        @click="closeMenu"
                    >
                        Close
                    </button>
                </div>

                <div>
                    <p class="inline-flex rounded-md border border-sky-200/25 bg-sky-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.22em] text-sky-100">
                        Applicant Access
                    </p>
                    <h1 class="mt-5 font-display text-3xl leading-tight font-bold text-white">
                        Student Workspace
                    </h1>
                    <p class="mt-4 text-sm leading-6 text-slate-300">
                        Scholarships, applications, uploads, and profile details in one focused student area.
                    </p>
                </div>

                <nav class="grid gap-2">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        :class="[
                            'rounded-md border px-4 py-3 transition hover:border-sky-200/30 hover:bg-white/10 hover:text-white',
                            isActive(link.href)
                                ? 'border-sky-200/40 bg-sky-300/10 text-white'
                                : 'border-white/10 bg-white/[0.04] text-slate-300',
                        ]"
                        @click="closeMenu"
                    >
                        <span class="block text-sm font-bold">
                            {{ link.label }}
                        </span>
                        <span class="mt-1 block text-xs text-slate-400">
                            {{ link.detail }}
                        </span>
                    </a>
                </nav>

                <div class="mt-auto rounded-lg border border-white/10 bg-white/[0.04] p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-100/80">
                        Applicant Account
                    </p>
                    <p class="mt-3 text-sm leading-6 text-slate-300">
                        Signed in to the applicant scholarship workspace.
                    </p>
                    <button
                        type="button"
                        class="mt-4 w-full rounded-md border border-white/20 px-4 py-2.5 text-sm font-bold text-white transition hover:border-sky-200/50 hover:bg-white/10"
                        @click="logout"
                    >
                        Logout
                    </button>
                </div>
            </div>
        </aside>
    </div>
</template>
