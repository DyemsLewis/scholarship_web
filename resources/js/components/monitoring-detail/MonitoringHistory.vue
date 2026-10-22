<script setup>
import SupportOutcomeRecord from './SupportOutcomeRecord.vue';

defineProps({
    content: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});

const emit = defineEmits(['refresh']);

function iconClass(tone) {
    return {
        positive: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        critical: 'border-rose-200 bg-rose-50 text-rose-700',
        neutral: 'border-slate-200 bg-slate-100 text-slate-600',
    }[tone] ?? 'border-slate-200 bg-slate-100 text-slate-600';
}
</script>

<template>
    <SupportOutcomeRecord
        v-if="content.selected"
        :outcome="content.selected"
        :program-title="programTitle"
        @refresh="emit('refresh')"
    />

    <div v-else data-monitoring-history class="mt-4 space-y-4">
        <section v-if="content.action_required.length" class="overflow-hidden rounded-md border border-amber-300 bg-white shadow-sm">
            <header class="border-b border-amber-200 bg-amber-50 px-5 py-4 sm:px-6">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Your action</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">Review the latest support outcome</h3>
            </header>
            <article v-for="outcome in content.action_required" :key="outcome.record_key" class="grid gap-4 px-5 py-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:px-6">
                <span class="grid h-10 w-10 place-items-center border border-amber-200 bg-amber-50 text-amber-700">
                    <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <p class="font-bold text-slate-950">{{ outcome.decision_label }}</p>
                    <p class="mt-1 text-sm text-slate-600">Acknowledge the outcome or ask the provider for clarification.</p>
                    <p v-if="outcome.effective_label" class="mt-1 text-xs text-slate-500">Effective {{ outcome.effective_label }}</p>
                </div>
                <a :href="outcome.href" class="inline-flex min-h-10 items-center justify-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">
                    Review outcome
                    <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                </a>
            </article>
        </section>

        <section v-if="content.timeline.length" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="flex items-end justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Recorded activity</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Monitoring timeline</h3>
                    <p class="mt-1 text-sm text-slate-500">Completed reviews, released benefits, and support decisions.</p>
                </div>
                <p class="shrink-0 text-xs font-bold text-slate-500">{{ content.timeline.length }} records</p>
            </header>

            <div class="divide-y divide-slate-200">
                <article v-for="item in content.timeline" :key="`${item.kind}-${item.record_key}`" class="grid gap-4 px-5 py-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <span :class="['grid h-10 w-10 place-items-center border', iconClass(item.tone)]">
                        <i :class="item.icon" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <p class="font-bold text-slate-950">{{ item.title }}</p>
                            <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">{{ item.kind_label }}</span>
                        </div>
                        <p v-if="item.description" class="mt-1 text-sm text-slate-600">{{ item.description }}</p>
                        <p v-if="item.date_label" class="mt-1 text-xs text-slate-500">{{ item.date_label }}</p>
                    </div>
                    <a :href="item.href" class="inline-flex h-10 w-10 items-center justify-center border border-slate-300 text-slate-600 hover:bg-slate-50 hover:text-slate-950" :aria-label="`Open ${item.title}`">
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </article>
            </div>
        </section>

        <section v-if="content.empty" class="rounded-md border border-dashed border-slate-300 bg-white px-5 py-9 text-center shadow-sm">
            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-500">
                <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
            </span>
            <h3 class="mt-3 text-base font-bold text-slate-950">No support history yet</h3>
            <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-500">Completed monitoring activity and provider outcomes will appear here.</p>
        </section>
    </div>
</template>
