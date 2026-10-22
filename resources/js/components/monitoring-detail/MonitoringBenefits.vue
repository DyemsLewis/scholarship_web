<script setup>
import BenefitReleaseRecord from './BenefitReleaseRecord.vue';

defineProps({
    content: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['refresh']);

function statusClass(status) {
    if (['fulfilled', 'resolved'].includes(status)) return 'bg-emerald-100 text-emerald-800';
    if (status === 'confirmation_needed') return 'bg-amber-100 text-amber-900';
    if (status === 'issue_open') return 'bg-sky-100 text-sky-800';
    if (['missed', 'withheld'].includes(status)) return 'bg-rose-100 text-rose-800';
    return 'bg-slate-100 text-slate-700';
}
</script>

<template>
    <BenefitReleaseRecord
        v-if="content.selected"
        :release="content.selected"
        :program-title="programTitle"
        @refresh="emit('refresh')"
    />

    <div v-else data-monitoring-benefits class="mt-4 space-y-4">
        <section v-if="content.empty" class="rounded-md border border-dashed border-slate-300 bg-white px-5 py-9 text-center shadow-sm">
            <span class="mx-auto grid h-11 w-11 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i></span>
            <h3 class="mt-3 text-base font-bold text-slate-950">No benefit release recorded</h3>
            <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-500">Published release schedules and completed benefits will appear here.</p>
        </section>

        <section v-if="content.directory.action_required.length" class="overflow-hidden rounded-md border border-amber-300 bg-white shadow-sm">
            <header class="flex items-end justify-between gap-4 border-b border-amber-200 bg-amber-50 px-5 py-4 sm:px-6">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Your action</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Confirm a received benefit</h3>
                </div>
                <p class="text-xs font-bold text-amber-800">{{ content.directory.action_required.length }} waiting</p>
            </header>
            <div class="divide-y divide-slate-200">
                <article v-for="release in content.directory.action_required" :key="release.record_key" class="grid gap-4 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-amber-100 text-amber-800"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><h4 class="font-bold text-slate-950">{{ release.title }}</h4><span :class="['px-2 py-1 text-[10px] font-bold uppercase', statusClass(release.status)]">{{ release.status_label }}</span></div>
                            <p class="mt-1 text-sm text-slate-500">{{ release.release_label }}<span v-if="release.amount_label"> · {{ release.amount_label }}</span></p>
                            <p class="mt-1 text-xs font-semibold text-slate-600">{{ release.progress_label }}</p>
                        </div>
                    </div>
                    <a :href="release.href" class="inline-flex min-h-10 items-center justify-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800">Review release<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                </article>
            </div>
        </section>

        <section v-if="content.directory.upcoming.length" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="flex items-end justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Scheduled support</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Upcoming releases</h3>
                </div>
                <p class="text-xs font-bold text-slate-500">{{ content.directory.upcoming.length }} scheduled</p>
            </header>
            <div class="divide-y divide-slate-200">
                <article v-for="release in content.directory.upcoming" :key="release.record_key" class="grid gap-4 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-slate-100 text-slate-700"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><h4 class="font-bold text-slate-950">{{ release.title }}</h4><span :class="['px-2 py-1 text-[10px] font-bold uppercase', statusClass(release.status)]">{{ release.status_label }}</span></div>
                            <p class="mt-1 text-sm text-slate-500">{{ release.release_label }}<span v-if="release.amount_label"> · {{ release.amount_label }}</span></p>
                            <p class="mt-1 text-xs font-semibold text-slate-600">{{ release.method_label }}</p>
                        </div>
                    </div>
                    <a :href="release.href" class="inline-flex min-h-10 items-center justify-center gap-2 border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">View release<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                </article>
            </div>
        </section>

        <section v-if="content.directory.in_progress.length" class="overflow-hidden rounded-md border border-sky-200 bg-white shadow-sm">
            <header class="flex items-end justify-between gap-4 border-b border-sky-200 bg-sky-50 px-5 py-4 sm:px-6">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-sky-700">Provider follow-up</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Reported release issues</h3>
                </div>
                <p class="text-xs font-bold text-sky-800">{{ content.directory.in_progress.length }} under review</p>
            </header>
            <div class="divide-y divide-slate-200">
                <article v-for="release in content.directory.in_progress" :key="release.record_key" class="grid gap-4 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center bg-sky-100 text-sky-800"><i class="fa-regular fa-clock" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><h4 class="font-bold text-slate-950">{{ release.title }}</h4><span :class="['px-2 py-1 text-[10px] font-bold uppercase', statusClass(release.status)]">{{ release.status_label }}</span></div>
                            <p class="mt-1 text-sm text-slate-500">{{ release.release_label }}<span v-if="release.amount_label"> · {{ release.amount_label }}</span></p>
                            <p class="mt-1 text-xs font-semibold text-slate-600">{{ release.progress_label }}</p>
                        </div>
                    </div>
                    <a :href="release.href" class="inline-flex min-h-10 items-center justify-center gap-2 border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">View report<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                </article>
            </div>
        </section>

        <details v-if="content.directory.completed.length" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Release history</p>
                    <h3 class="mt-1 text-base font-bold text-slate-950">Completed releases</h3>
                </div>
                <div class="flex items-center gap-3 text-sm font-bold text-slate-600"><span>{{ content.directory.completed.length }}</span><i class="fa-solid fa-chevron-down text-xs transition group-open:rotate-180" aria-hidden="true"></i></div>
            </summary>
            <div class="divide-y divide-slate-200 border-t border-slate-200">
                <article v-for="release in content.directory.completed" :key="release.record_key" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6">
                    <div>
                        <div class="flex flex-wrap items-center gap-2"><h4 class="text-sm font-bold text-slate-950">{{ release.title }}</h4><span :class="['px-2 py-1 text-[10px] font-bold uppercase', statusClass(release.status)]">{{ release.status_label }}</span></div>
                        <p class="mt-1 text-xs text-slate-500">{{ release.release_label }}<span v-if="release.amount_label"> · {{ release.amount_label }}</span></p>
                    </div>
                    <a :href="release.href" class="inline-flex min-h-9 items-center justify-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50">Open record<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
                </article>
            </div>
        </details>
    </div>
</template>
