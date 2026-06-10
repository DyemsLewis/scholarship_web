<script setup>
const emit = defineEmits(['logout']);
const currentPath = window.location.pathname.replace(/\/$/, '') || '/provider';

const navLinks = [
    { href: '/provider', label: 'Dashboard', detail: 'Provider overview' },
    { href: '/provider/programs', label: 'Programs', detail: 'Scholarship workspace' },
    { href: '/provider/applications', label: 'Applications', detail: 'Applicant activity' },
];

function isActive(href) {
    return currentPath === href;
}
</script>

<template>
    <aside class="relative overflow-hidden bg-[#081426] text-white lg:min-h-screen">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_16%_12%,_rgba(45,212,191,0.18),_transparent_30%),radial-gradient(circle_at_92%_16%,_rgba(250,204,21,0.14),_transparent_26%),linear-gradient(180deg,_rgba(8,20,38,0.98),_rgba(15,23,42,0.96))]"></div>
        <div class="relative flex h-full flex-col gap-8 px-5 py-6">
            <div class="font-display text-2xl font-bold text-white">
                Provider Panel
            </div>

            <div>
                <p class="inline-flex rounded-md border border-emerald-200/25 bg-emerald-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.22em] text-emerald-100">
                    Scholarship Provider
                </p>
                <h1 class="mt-5 font-display text-3xl leading-tight font-bold text-white">
                    Program Workspace
                </h1>
                <p class="mt-4 text-sm leading-6 text-slate-300">
                    Manage provider tools from a workspace separate from the admin panel.
                </p>
            </div>

            <nav class="grid gap-2">
                <a
                    v-for="link in navLinks"
                    :key="link.href"
                    :href="link.href"
                    :class="[
                        'rounded-md border px-4 py-3 transition hover:border-emerald-200/30 hover:bg-white/10 hover:text-white',
                        isActive(link.href)
                            ? 'border-emerald-200/40 bg-emerald-300/10 text-white'
                            : 'border-white/10 bg-white/[0.04] text-slate-300',
                    ]"
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
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-100/80">
                    Provider Access
                </p>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    Signed in to the scholarship provider workspace.
                </p>
                <button
                    type="button"
                    class="mt-4 w-full rounded-md border border-white/20 px-4 py-2.5 text-sm font-bold text-white transition hover:border-emerald-200/50 hover:bg-white/10"
                    @click="emit('logout')"
                >
                    Logout
                </button>
            </div>
        </div>
    </aside>
</template>
