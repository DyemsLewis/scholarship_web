<script setup>
defineProps({ step: { type: Object, default: null } });
defineEmits(['open-section']);
</script>

<template>
    <aside v-if="step" aria-label="Next step" class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-3 border border-l-4 bg-white px-5 py-4 shadow-sm" :class="step.state === 'blocked' ? 'border-amber-200 border-l-amber-500' : 'border-slate-200 border-l-slate-700'">
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-[4px]" :class="step.state === 'blocked' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'">
                <i :class="['fa-solid text-sm', step.state === 'waiting' ? 'fa-clock' : step.state === 'blocked' ? 'fa-circle-exclamation' : 'fa-arrow-right']" aria-hidden="true"></i>
            </span>
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-600">Next step</p>
                <p class="mt-0.5 text-sm font-bold text-slate-950">{{ step.title }}</p>
                <p class="mt-0.5 text-sm text-slate-600">{{ step.detail }}</p>
            </div>
        </div>
        <div v-if="step.owner" class="shrink-0 border-l border-slate-200 pl-4">
            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-600">{{ step.state === 'waiting' ? 'Waiting on' : 'Responsible role' }}</p>
            <p class="mt-0.5 text-sm font-semibold text-slate-800">{{ step.owner }}</p>
        </div>
        <a v-if="step.action?.url" :href="step.action.url" class="inline-flex min-h-9 shrink-0 items-center gap-2 rounded-[4px] border border-slate-300 bg-white px-3 text-xs font-bold text-slate-800 hover:border-slate-950">
            {{ step.action.label }} <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
        </a>
        <button v-else-if="step.action?.section" type="button" class="inline-flex min-h-9 shrink-0 items-center gap-2 rounded-[4px] border border-slate-300 bg-white px-3 text-xs font-bold text-slate-800 hover:border-slate-950" @click="$emit('open-section', step.action.section)">
            {{ step.action.label }} <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i>
        </button>
    </aside>
</template>
