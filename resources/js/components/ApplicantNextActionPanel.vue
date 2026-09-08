<script setup>
defineProps({
    actor: {
        type: String,
        default: 'Check application',
    },
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    closed: {
        type: Boolean,
        default: false,
    },
    compact: {
        type: Boolean,
        default: false,
    },
    actionAvailable: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <section
        :class="[
            'flex flex-col gap-3 border-l-4 border-l-slate-950 sm:flex-row sm:items-center sm:justify-between',
            compact
                ? 'rounded-md border border-slate-200 bg-slate-50 px-3 py-3'
                : 'student-card p-4 sm:p-5',
        ]"
    >
        <div class="flex min-w-0 items-start gap-3">
            <span :class="['grid shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300', compact ? 'h-8 w-8 text-xs' : 'h-10 w-10']">
                <i :class="closed ? 'fa-solid fa-flag-checkered' : 'fa-solid fa-arrow-right'" aria-hidden="true"></i>
            </span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p :class="compact ? 'text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500' : 'student-kicker'">
                        {{ closed ? 'Final update' : 'Next action' }}
                    </p>
                    <span class="rounded bg-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600 ring-1 ring-slate-200">
                        {{ actor }}
                    </span>
                </div>
                <h3 :class="['mt-1 font-bold text-slate-950', compact ? 'text-sm' : 'text-base leading-6']">{{ title }}</h3>
                <p v-if="description" :class="['mt-1 text-slate-600', compact ? 'text-xs leading-5' : 'max-w-3xl text-sm leading-6']">
                    {{ description }}
                </p>
            </div>
        </div>

        <div v-if="actionAvailable" class="shrink-0">
            <slot name="action"></slot>
        </div>
        <span v-else class="w-fit shrink-0 rounded-md bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600">
            {{ closed ? 'Process complete' : 'No action needed now' }}
        </span>
    </section>
</template>
