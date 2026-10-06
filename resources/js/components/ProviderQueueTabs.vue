<script setup>
defineProps({
    tabs: {
        type: Array,
        required: true,
    },
    activeKey: {
        type: String,
        required: true,
    },
    busy: {
        type: Boolean,
        default: false,
    },
    ariaLabel: {
        type: String,
        required: true,
    },
});

defineEmits(['select']);
</script>

<template>
    <nav class="flex items-center gap-2 overflow-x-auto border-b border-slate-200 px-4 sm:px-5" :aria-label="ariaLabel">
        <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            :aria-pressed="activeKey === tab.key"
            :class="[
                '-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-1 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-700 focus-visible:ring-offset-2',
                activeKey === tab.key
                    ? 'border-slate-950 text-slate-950'
                    : 'border-transparent text-slate-500 hover:text-slate-800',
            ]"
            @click="$emit('select', tab.key)"
        >
            <span>{{ tab.label }}</span>
            <span
                :class="[
                    'min-w-6 rounded-full px-1.5 py-0.5 text-center text-[0.68rem] font-black',
                    activeKey === tab.key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-500',
                ]"
            >
                {{ tab.count }}
            </span>
        </button>
        <span v-if="busy" class="ml-auto inline-flex shrink-0 items-center text-xs font-semibold text-slate-500" role="status" aria-live="polite">
            <i class="fa-solid fa-circle-notch mr-1.5 animate-spin" aria-hidden="true"></i>Updating
        </span>
    </nav>
</template>
