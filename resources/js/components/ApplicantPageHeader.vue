<script setup>
defineProps({
    eyebrow: {
        type: String,
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    icon: {
        type: String,
        default: 'fa-solid fa-award',
    },
    actionHref: {
        type: String,
        default: '',
    },
    actionLabel: {
        type: String,
        default: '',
    },
    secondaryHref: {
        type: String,
        default: '',
    },
    secondaryLabel: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <header class="student-hero">
        <div class="pointer-events-none absolute right-6 top-4 hidden items-center gap-2 opacity-70 sm:flex">
            <span class="h-2 w-8 rounded-full bg-amber-300"></span>
            <span class="h-2 w-2 rounded-full bg-slate-900"></span>
            <span class="h-2 w-2 rounded-full bg-slate-300"></span>
        </div>

        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 gap-4">
                <span class="student-hero-icon">
                    <i :class="[icon, 'text-base']"></i>
                </span>
                <span class="min-w-0">
                    <span class="student-kicker">
                        {{ eyebrow }}
                    </span>
                    <span class="mt-2 block font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">
                        {{ title }}
                    </span>
                    <span v-if="description" class="mt-3 block max-w-2xl text-sm leading-6 text-slate-600 sm:line-clamp-2">
                        {{ description }}
                    </span>
                </span>
            </div>

            <div
                v-if="$slots.actions || actionHref || secondaryHref"
                class="flex shrink-0 flex-col gap-2 sm:flex-row lg:items-center"
            >
                <slot name="actions">
                    <a
                        v-if="secondaryHref && secondaryLabel"
                        :href="secondaryHref"
                        class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                    >
                        {{ secondaryLabel }}
                    </a>
                    <a
                        v-if="actionHref && actionLabel"
                        :href="actionHref"
                        class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                    >
                        {{ actionLabel }}
                    </a>
                </slot>
            </div>
        </div>
    </header>
</template>
