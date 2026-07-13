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
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 gap-4">
                <span class="student-hero-icon">
                    <i :class="[icon, 'text-base']"></i>
                </span>
                <span class="min-w-0">
                    <span class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-200">
                        {{ eyebrow }}
                    </span>
                    <span class="mt-2 block font-display text-2xl font-bold leading-tight text-white sm:text-3xl">
                        {{ title }}
                    </span>
                    <span v-if="description" class="mt-2 block max-w-2xl text-sm leading-6 text-slate-300 sm:line-clamp-2">
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
                        class="rounded-md border border-white/30 bg-white/5 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-white hover:text-slate-950"
                    >
                        {{ secondaryLabel }}
                    </a>
                    <a
                        v-if="actionHref && actionLabel"
                        :href="actionHref"
                        class="rounded-md bg-amber-300 px-4 py-2.5 text-center text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                    >
                        {{ actionLabel }}
                    </a>
                </slot>
            </div>
        </div>
    </header>
</template>
