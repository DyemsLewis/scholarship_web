<script setup>
defineProps({ priority: { type: Object, required: true } });
const labels = { actionable: 'Start here', waiting: 'Waiting on another party', clear: 'Work queue clear', reference: 'Review at a glance' };
const icons = { actionable: 'fa-solid fa-arrow-right', waiting: 'fa-regular fa-clock', clear: 'fa-solid fa-check', reference: 'fa-solid fa-magnifying-glass' };
</script>

<template>
    <section class="border border-slate-200 border-l-4 border-l-amber-400 bg-white shadow-sm" data-admin-priority aria-labelledby="admin-priority-title">
        <div class="flex items-center gap-5 px-6 py-5">
            <span class="grid h-11 w-11 shrink-0 place-items-center bg-slate-100 text-slate-700" aria-hidden="true"><i :class="icons[priority.state]"></i></span>
            <div class="min-w-0 flex-1">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ labels[priority.state] }}</p>
                <h2 id="admin-priority-title" class="mt-1 text-lg font-black text-slate-950">{{ priority.title }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ priority.reason }}</p>
                <p v-if="priority.owner || priority.timing" class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                    <span v-if="priority.owner"><span class="font-semibold text-slate-700">Next responsible:</span> {{ priority.owner }}</span>
                    <span v-if="priority.timing">{{ priority.timing }}</span>
                </p>
            </div>
            <a v-if="priority.action" :href="priority.action.url" class="staff-row-action staff-row-action--primary shrink-0 px-4 py-3 text-sm">
                {{ priority.action.label }}<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
            </a>
        </div>
    </section>
</template>
