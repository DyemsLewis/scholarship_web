<script setup>
const props = defineProps({
    active: {
        type: String,
        default: 'dashboard',
    },
});

const emit = defineEmits(['logout']);

const navLinks = [
    { key: 'dashboard', href: '/admin', label: 'Dashboard', detail: 'Important details' },
    { key: 'users', href: '/admin/manage-users', label: 'Manage Users', detail: 'Search and review accounts' },
    { key: 'reviews', href: '/admin/reviews', label: 'Reviews', detail: 'Review queues' },
    { key: 'logs', href: '/admin/logs', label: 'Logs', detail: 'System activity' },
];

function navLinkClass(link) {
    const isActive = props.active === link.key;

    return [
        'rounded-md border px-4 py-3 transition',
        isActive
            ? 'border-amber-200/30 bg-white/12 text-white'
            : 'border-white/10 bg-white/[0.04] text-slate-300 hover:border-white/20 hover:bg-white/10 hover:text-white',
    ];
}
</script>

<template>
    <aside class="relative overflow-hidden bg-[#081426] text-white lg:min-h-screen">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_16%_12%,_rgba(250,204,21,0.18),_transparent_30%),linear-gradient(180deg,_rgba(8,20,38,0.98),_rgba(15,23,42,0.96))]"></div>
        <div class="relative flex h-full flex-col gap-8 px-5 py-6">
            <div class="font-display text-2xl font-bold text-white">
                Scholarship Admin
            </div>

            <div>
                <p class="inline-flex rounded-md border border-amber-200/25 bg-amber-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.22em] text-amber-100">
                    Admin Desk
                </p>
                <h1 class="mt-5 font-display text-3xl leading-tight font-bold text-white">
                    Admin Control Panel
                </h1>
                <p class="mt-4 text-sm leading-6 text-slate-300">
                    Manage administrative tools from one focused workspace.
                </p>
            </div>

            <nav class="grid gap-2">
                <a
                    v-for="link in navLinks"
                    :key="link.href"
                    :href="link.href"
                    :class="navLinkClass(link)"
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
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-100/80">
                    Admin Access
                </p>
                <p class="mt-3 text-sm leading-6 text-slate-300">
                    Signed in as the scholarship administrator.
                </p>
                <button
                    type="button"
                    class="mt-4 w-full rounded-md border border-white/20 px-4 py-2.5 text-sm font-bold text-white transition hover:border-amber-200/50 hover:bg-white/10"
                    @click="emit('logout')"
                >
                    Logout
                </button>
            </div>
        </div>
    </aside>
</template>
