<script setup>
import { computed } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    monitoringCase: { type: Object, default: null },
    subject: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const history = computed(() => props.monitoringCase?.history ?? []);
const exceptionContext = computed(() => props.monitoringCase?.exception_context ?? null);

function toneClass(tone) {
    if (tone === 'emerald') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (tone === 'rose') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (tone === 'sky') return 'border-sky-200 bg-sky-50 text-sky-800';
    return 'border-amber-200 bg-amber-50 text-amber-800';
}

function historyIcon(kind) {
    if (kind === 'case_decision') return 'fa-solid fa-gavel';
    if (kind === 'adjustment_requested') return 'fa-regular fa-clock';
    if (kind === 'adjustment_decided') return 'fa-solid fa-scale-balanced';
    if (kind === 'follow_up_completed') return 'fa-solid fa-circle-check';
    return 'fa-solid fa-comments';
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[2200] grid place-items-center bg-slate-950/65 p-4" role="dialog" aria-modal="true" aria-labelledby="monitoring-history-title" @click.self="emit('close')" @keydown.esc="emit('close')">
            <section class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-md border border-slate-300 bg-white text-slate-950 shadow-2xl">
                <header class="flex items-start justify-between gap-5 border-b border-slate-200 px-5 py-4 sm:px-6">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Monitoring record</p>
                        <h2 id="monitoring-history-title" class="mt-1 text-xl font-bold">Check-in history</h2>
                        <p v-if="subject" class="mt-1 text-sm text-slate-500">{{ subject }}</p>
                    </div>
                    <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded border border-slate-300 text-slate-500 hover:text-slate-950" aria-label="Close history" @click="emit('close')"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>

                <div class="min-h-0 overflow-y-auto px-5 py-5 sm:px-6">
                    <section v-if="exceptionContext" class="border border-amber-200 bg-amber-50 px-4 py-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Adjustment context</p><h3 class="mt-1 font-bold text-amber-950">{{ exceptionContext.summary }}</h3></div>
                            <span class="w-fit border border-amber-300 bg-white px-2 py-1 text-[10px] font-bold uppercase text-amber-800">{{ exceptionContext.latest_status_label }}</span>
                        </div>
                        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                            <div v-if="exceptionContext.reason_label"><dt class="text-xs font-semibold text-amber-800">Reason</dt><dd class="mt-1 font-bold text-amber-950">{{ exceptionContext.reason_label }}</dd></div>
                            <div v-if="exceptionContext.approved_due_label"><dt class="text-xs font-semibold text-amber-800">Approved deadline</dt><dd class="mt-1 font-bold text-amber-950">{{ exceptionContext.approved_due_label }}</dd></div>
                        </dl>
                        <p v-if="exceptionContext.explanation" class="mt-3 text-sm leading-6 text-amber-950">{{ exceptionContext.explanation }}</p>
                        <p v-if="exceptionContext.decision_notes" class="mt-2 border-t border-amber-200 pt-2 text-xs leading-5 text-amber-900"><strong>Decision note:</strong> {{ exceptionContext.decision_notes }}</p>
                    </section>

                    <section :class="exceptionContext ? 'mt-6' : ''">
                        <div class="flex items-end justify-between gap-4"><div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Activity</p><h3 class="mt-1 font-bold text-slate-950">Recorded changes</h3></div><p class="text-xs font-semibold text-slate-500">{{ history.length }} shown</p></div>
                        <div v-if="history.length" class="mt-3 divide-y divide-slate-200 border-y border-slate-200">
                            <article v-for="item in history" :key="item.id" class="flex gap-3 py-4">
                                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded border', toneClass(item.tone)]"><i :class="historyIcon(item.kind)" class="text-xs" aria-hidden="true"></i></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-sm font-bold text-slate-950">{{ item.title }}</p><p v-if="item.detail" class="mt-0.5 text-xs font-semibold text-slate-600">{{ item.detail }}</p></div><p class="shrink-0 text-xs text-slate-400">{{ item.occurred_at }}</p></div>
                                    <p v-if="item.reason" class="mt-2 text-sm leading-6 text-slate-600">{{ item.reason }}</p>
                                    <p v-if="item.actor" class="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">Recorded by {{ item.actor }}</p>
                                </div>
                            </article>
                        </div>
                        <div v-else class="mt-3 border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">No decisions or adjustments have been recorded yet.</div>
                    </section>
                </div>

                <footer class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 sm:px-6"><button type="button" class="h-10 bg-slate-950 px-4 text-sm font-bold text-white" @click="emit('close')">Close</button></footer>
            </section>
        </div>
    </Teleport>
</template>
