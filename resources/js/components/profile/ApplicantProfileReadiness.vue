<script setup>
defineProps({ state: { type: Object, required: true } });
defineEmits(['continue']);
</script>

<template>
    <div data-profile-readiness :data-state="state.key" role="status" aria-live="polite" class="border-b border-slate-200 bg-slate-50/80 px-5 py-4 sm:px-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-5">
            <div class="flex min-w-0 items-start gap-3">
                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-sm text-sm', state.action ? 'bg-amber-100 text-amber-800' : 'bg-slate-950 text-amber-300']"><i :class="[state.icon, 'leading-none']" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <p class="text-sm font-bold leading-5 text-slate-950">{{ state.title }}</p>
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ state.description }}</p>
                    <p v-if="state.key !== 'ready'" class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[11px] font-semibold text-slate-500">
                        <span>{{ state.percent }}% details complete</span>
                        <span v-if="state.required">{{ state.uploaded }} of {{ state.required }} required files uploaded</span>
                    </p>
                </div>
            </div>
            <button v-if="state.action" type="button" class="inline-flex min-h-9 shrink-0 items-center justify-center gap-2 rounded-sm border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:border-slate-500 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2" @click="$emit('continue', state.action)">
                {{ state.action.label }}<i class="fa-solid fa-arrow-right text-[10px] leading-none" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</template>
