<script setup>
import { computed, ref } from 'vue';
import SupportOutcomeRecord from './SupportOutcomeRecord.vue';
import { checkInStatusClass } from '../../support/checkInStatus.js';

const props = defineProps({
    content: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});

const emit = defineEmits(['refresh']);
const filter = ref('all');
const categories = [
    { key: 'check_in', label: 'Check-ins' },
    { key: 'benefit_release', label: 'Benefits' },
    { key: 'support_outcome', label: 'Decisions' },
];
const filters = computed(() => [
    { key: 'all', label: 'All updates' },
    ...categories.filter(({ key }) => props.content.timeline.some((item) => item.kind === key)),
]);
const activeFilter = computed(() => filters.value.some(({ key }) => key === filter.value) ? filter.value : 'all');

const visibleItems = computed(() => props.content.timeline.filter((item) => activeFilter.value === 'all' || item.kind === activeFilter.value));
const months = computed(() => {
    const groups = new Map();
    for (const item of visibleItems.value) {
        const key = item.month_key || 'undated';
        if (!groups.has(key)) groups.set(key, { key, label: item.month_label || 'Date not recorded', items: [] });
        groups.get(key).items.push(item);
    }
    return [...groups.values()];
});

function contextLabel(item) {
    return item.kind === 'check_in' ? item.period_label : item.description;
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
        <section v-if="content.action_required.length" aria-label="Decision awaiting your response" class="overflow-hidden rounded-md border border-amber-300 border-l-4 border-l-amber-400 bg-amber-50 shadow-sm">
            <article v-for="outcome in content.action_required" :key="outcome.record_key" data-history-action class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:gap-4 sm:px-6">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-900">
                    <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-900">Your response is needed</p>
                    <h2 class="mt-1 break-words text-lg font-bold text-slate-950">{{ outcome.decision_label }}</h2>
                    <p class="mt-1 text-sm text-slate-700">Review the decision and what it means for your scholarship.</p>
                    <p v-if="outcome.effective_label" class="mt-1 text-xs text-slate-600">Effective {{ outcome.effective_label }}</p>
                </div>
                <a :href="outcome.href" class="student-button student-button-primary shrink-0">Review decision <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
            </article>
        </section>

        <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="support-timeline-title">
            <header class="border-b border-slate-200 px-4 py-4 sm:px-6">
                <h2 id="support-timeline-title" class="font-display text-xl font-bold text-slate-950 sm:text-2xl">Support timeline</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">Past check-ins, benefit releases, and provider decisions. Newest first.</p>
                <div v-if="filters.length > 2" class="mt-3 flex flex-wrap gap-1.5" role="group" aria-label="Filter support history">
                    <button v-for="option in filters" :key="option.key" type="button" :aria-pressed="activeFilter === option.key"
                        :class="['min-h-9 rounded-sm border px-3 text-xs font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600', activeFilter === option.key ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50']"
                        @click="filter = option.key">{{ option.label }}</button>
                </div>
            </header>

            <p class="sr-only" role="status">{{ visibleItems.length }} {{ visibleItems.length === 1 ? 'update' : 'updates' }}</p>
            <div v-if="months.length" class="divide-y divide-slate-200">
                <section v-for="month in months" :key="month.key" data-history-month>
                    <h3 class="border-b border-slate-200 bg-slate-50 px-4 py-2.5 text-xs font-bold uppercase tracking-[0.12em] text-slate-700 sm:px-6">{{ month.label }}</h3>
                    <ol role="list" class="divide-y divide-slate-200">
                        <li v-for="item in month.items" :key="item.record_key" :data-history-kind="item.kind">
                            <a :href="item.href" class="group grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1 px-4 py-3.5 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-600 sm:grid-cols-[auto_minmax(0,1fr)_9rem_auto] sm:items-center sm:gap-x-4 sm:px-6">
                                <span class="row-span-2 grid h-9 w-9 place-items-center rounded-sm bg-slate-100 text-xs text-slate-600 sm:row-span-1" aria-hidden="true"><i :class="item.icon"></i></span>
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold text-slate-600">{{ item.kind_label }}<span v-if="contextLabel(item)"> &middot; {{ contextLabel(item) }}</span></p>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                        <p class="break-words font-bold text-slate-950">{{ item.title }}</p>
                                        <span v-if="item.status_label" :class="['rounded-sm border px-2 py-0.5 text-xs font-bold', checkInStatusClass(item.status_tone)]">{{ item.status_label }}</span>
                                    </div>
                                </div>
                                <p v-if="item.date_label" class="col-start-2 text-xs text-slate-600 sm:col-start-3 sm:text-right">{{ item.date_caption }} <time :datetime="item.sort_at?.slice(0, 10)" class="font-semibold text-slate-800">{{ item.date_label }}</time></p>
                                <i class="fa-solid fa-chevron-right col-start-3 row-start-1 self-center text-xs text-slate-500 group-hover:text-slate-950 sm:col-start-4" aria-hidden="true"></i>
                            </a>
                        </li>
                    </ol>
                </section>
            </div>

            <div v-else class="flex items-start gap-3 px-4 py-5 sm:px-6">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                <div>
                    <h3 class="font-bold text-slate-950">{{ content.empty ? 'No support history yet' : 'No earlier updates' }}</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ content.empty ? 'Past records will appear here as the provider and you complete steps.' : 'The decision awaiting your response is shown above.' }}</p>
                </div>
            </div>
        </section>
    </div>
</template>
