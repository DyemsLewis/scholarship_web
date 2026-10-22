<script setup>
defineProps({
    events: {
        type: Array,
        default: () => [],
    },
    featured: {
        type: Boolean,
        default: false,
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['select']);

function toneClasses(tone) {
    return {
        positive: 'border-emerald-200 bg-emerald-100 text-emerald-700',
        negative: 'border-rose-200 bg-rose-100 text-rose-700',
        attention: 'border-amber-300 bg-amber-100 text-amber-800',
        neutral: 'border-slate-200 bg-slate-100 text-slate-600',
    }[tone] ?? 'border-slate-200 bg-slate-100 text-slate-600';
}
</script>

<template>
    <ol v-if="events.length" class="divide-y divide-slate-200">
        <li v-for="(event, index) in events" :key="event.key" :class="['relative px-4 sm:px-5', featured ? 'py-5' : 'py-3.5']">
            <div class="grid grid-cols-[2.5rem_minmax(0,1fr)_auto] items-start gap-3">
                <div class="relative flex justify-center">
                    <span
                        v-if="index < events.length - 1"
                        class="absolute left-1/2 top-10 h-[calc(100%+1rem)] w-px -translate-x-1/2 bg-slate-200"
                        aria-hidden="true"
                    ></span>
                    <span :class="['relative z-10 grid place-items-center rounded-md border', featured ? 'h-10 w-10' : 'h-9 w-9', toneClasses(event.tone)]">
                        <i :class="[event.icon, 'text-xs']" aria-hidden="true"></i>
                    </span>
                </div>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <h3 class="text-sm font-bold text-slate-950 sm:text-base">{{ event.title }}</h3>
                        <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">{{ event.category_label }}</span>
                    </div>
                    <p v-if="!compact" class="mt-1 max-w-4xl text-sm leading-5 text-slate-600">{{ event.description }}</p>
                    <p class="mt-2 text-xs text-slate-500">
                        {{ event.occurred_label }}
                        <span class="mx-1 text-slate-300">·</span>
                        {{ event.actor_label }}
                    </p>
                </div>

                <button
                    type="button"
                    class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 text-slate-500 transition hover:bg-slate-50 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-300"
                    :aria-label="`View details for ${event.title}`"
                    title="View event details"
                    @click="emit('select', event)"
                >
                    <i class="fa-solid fa-chevron-right text-xs" aria-hidden="true"></i>
                </button>
            </div>
        </li>
    </ol>
</template>
