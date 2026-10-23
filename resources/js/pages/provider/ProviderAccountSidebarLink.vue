<script setup>
const isActive = window.location.pathname.replace(/\/$/, '') === '/provider/account';
</script>

<template>
    <a
        href="/provider/account"
        :aria-current="isActive ? 'page' : undefined"
        :class="[
            'group flex w-full items-center gap-3 px-3 py-2 text-sm font-semibold transition',
            isActive ? 'bg-white/[0.09] text-white' : 'text-slate-400 hover:bg-white/[0.05] hover:text-white',
        ]"
    >
        <span :class="['grid h-6 w-6 shrink-0 place-items-center text-xs transition', isActive ? 'text-amber-300' : 'text-slate-500 group-hover:text-slate-300']">
            <i class="fa-solid fa-user-gear" aria-hidden="true"></i>
        </span>
        My account
    </a>
</template>
