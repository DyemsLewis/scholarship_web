<script setup>
import MonitoringCheckInRecord from './MonitoringCheckInRecord.vue';

defineProps({
    content: { type: Object, required: true },
    applicationId: { type: [Number, String], required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['refresh']);

function stateClass(state) {
    if (state === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (state === 'action_required') return 'bg-amber-100 text-amber-900';
    if (state === 'under_review') return 'bg-sky-100 text-sky-800';
    if (state === 'upcoming') return 'bg-slate-100 text-slate-700';
    if (state === 'cancelled') return 'bg-slate-100 text-slate-500';
    return 'bg-slate-100 text-slate-700';
}
</script>

<template>
    <MonitoringCheckInRecord
        v-if="content.selected"
        :record="content.selected"
        :application-id="applicationId"
        :program-title="programTitle"
        @refresh="emit('refresh')"
    />

    <div v-else data-monitoring-check-ins class="mt-4 space-y-4">
        <section v-if="content.empty" class="rounded-md border border-dashed border-slate-300 bg-white px-5 py-9 text-center shadow-sm">
            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-500">
                <i class="fa-solid fa-list-check" aria-hidden="true"></i>
            </span>
            <h3 class="mt-3 text-base font-bold text-slate-950">{{ content.empty.title }}</h3>
            <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-500">{{ content.empty.description }}</p>
        </section>

        <section v-if="content.directory.active.length" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="flex items-end justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Current period</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Open check-ins</h3>
                </div>
                <p class="text-xs font-bold text-slate-500">{{ content.directory.active.length }} open</p>
            </header>

            <div class="divide-y divide-slate-200">
                <article v-for="record in content.directory.active" :key="record.record_key" class="grid gap-4 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-amber-50 text-amber-800">
                            <i :class="record.state === 'under_review' ? 'fa-regular fa-clock' : 'fa-solid fa-list-check'" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="font-bold text-slate-950">{{ record.title }}</h4>
                                <span :class="['px-2 py-1 text-[10px] font-bold uppercase', stateClass(record.state)]">{{ record.state_label }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">{{ record.period_label || record.record_type_label }}<span v-if="record.due_label"> · Due {{ record.due_label }}</span></p>
                            <p class="mt-1 text-xs font-semibold text-slate-600">{{ record.progress_label }}</p>
                        </div>
                    </div>
                    <a :href="record.href" class="inline-flex min-h-10 items-center justify-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">
                        Open check-in
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </article>
            </div>
        </section>

        <section v-if="content.directory.upcoming.length" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="flex items-end justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Later</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Upcoming check-ins</h3>
                </div>
                <p class="text-xs font-bold text-slate-500">{{ content.directory.upcoming.length }} scheduled</p>
            </header>

            <div class="divide-y divide-slate-200">
                <article v-for="record in content.directory.upcoming" :key="record.record_key" class="grid gap-4 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><h4 class="font-bold text-slate-950">{{ record.title }}</h4><span :class="['px-2 py-1 text-[10px] font-bold uppercase', stateClass(record.state)]">{{ record.state_label }}</span></div>
                            <p class="mt-1 text-sm text-slate-500">{{ record.period_label || record.record_type_label }}<span v-if="record.due_label"> · Due {{ record.due_label }}</span></p>
                            <p class="mt-1 text-xs font-semibold text-slate-600">{{ record.progress_label }}</p>
                        </div>
                    </div>
                    <a :href="record.href" class="inline-flex min-h-10 items-center justify-center gap-2 border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">View details<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                </article>
            </div>
        </section>

        <details v-if="content.directory.completed.length" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Past periods</p>
                    <h3 class="mt-1 text-base font-bold text-slate-950">Completed check-ins</h3>
                </div>
                <div class="flex items-center gap-3 text-sm font-bold text-slate-600"><span>{{ content.directory.completed.length }}</span><i class="fa-solid fa-chevron-down text-xs transition group-open:rotate-180" aria-hidden="true"></i></div>
            </summary>
            <div class="divide-y divide-slate-200 border-t border-slate-200">
                <article v-for="record in content.directory.completed" :key="record.record_key" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div>
                        <div class="flex flex-wrap items-center gap-2"><h4 class="text-sm font-bold text-slate-950">{{ record.title }}</h4><span :class="['px-2 py-1 text-[10px] font-bold uppercase', stateClass(record.state)]">{{ record.state_label }}</span></div>
                        <p class="mt-1 text-xs text-slate-500">{{ record.period_label || record.record_type_label }}<span v-if="record.due_label"> · {{ record.due_label }}</span></p>
                    </div>
                    <a :href="record.href" class="inline-flex min-h-9 items-center justify-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50">Open record<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
                </article>
            </div>
        </details>
    </div>
</template>
