<script setup>
defineProps({
    items: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <details class="provider-panel group mt-3 overflow-hidden" aria-label="Section guide">
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 px-4 py-2.5 text-sm [&::-webkit-details-marker]:hidden sm:px-5">
            <span class="flex min-w-0 items-center gap-2.5">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-amber-100 text-[11px] text-amber-800">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                </span>
                <span class="min-w-0">
                    <strong class="block text-slate-900">About this section</strong>
                    <span class="hidden truncate text-xs text-slate-500 sm:block">Purpose, records shown, and the next action</span>
                </span>
            </span>
            <i class="fa-solid fa-chevron-down shrink-0 text-[10px] text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
        </summary>
        <dl class="grid gap-px border-t border-slate-200 bg-slate-200 md:grid-cols-3">
            <div v-for="item in items" :key="item.label" class="bg-white px-4 py-3.5 sm:px-5">
                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">{{ item.label }}</dt>
                <dd class="mt-1 text-sm leading-5 text-slate-600">{{ item.text }}</dd>
            </div>
        </dl>
    </details>
</template>
