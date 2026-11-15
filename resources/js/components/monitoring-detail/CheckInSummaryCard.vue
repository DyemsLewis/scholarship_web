<script setup>
import { computed } from 'vue';
import { checkInCardFacts, checkInCardGuidance } from '../../support/checkInPresentation.js';
import { checkInStatusClass } from '../../support/checkInStatus.js';

const props = defineProps({ record: { type: Object, required: true }, past: Boolean });
const facts = computed(() => checkInCardFacts(props.record, props.past));
</script>

<template>
    <article data-check-in-card :data-record-key="record.record_key" class="overflow-hidden border-t border-slate-200 bg-white">
        <div class="px-4 py-4 sm:px-5 sm:py-5">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-portal-navy text-amber-300"><i :class="[record.handoff ? 'fa-regular fa-calendar-check' : 'fa-solid fa-list-check', 'text-sm leading-none']" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <span :class="['inline-flex rounded-sm border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', checkInStatusClass(record.state_tone)]">{{ record.state_label }}</span>
                    <h4 class="mt-1.5 break-words text-base font-bold leading-6 text-slate-950">{{ record.title }}</h4>
                    <p v-if="record.period_label" class="mt-1 break-words text-xs text-slate-500">{{ record.period_label }}</p>
                </div>
            </div>
            <dl class="mt-4 grid gap-3 rounded-sm border border-slate-200 bg-slate-50 px-3 py-3 sm:grid-cols-2 sm:gap-5 sm:px-4">
                <div class="min-w-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ facts.dateCaption }}</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-800">{{ facts.dateLabel }}</dd></div>
                <div class="min-w-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ facts.contextCaption }}</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-800">{{ facts.contextLabel }}</dd></div>
            </dl>
        </div>
        <footer class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-5">
            <p class="min-w-0 text-xs leading-5 text-slate-600">{{ checkInCardGuidance(record, past) }}</p>
            <a :href="record.href" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 sm:min-w-44">{{ past ? 'Open record' : 'Open check-in' }}<i class="fa-solid fa-arrow-right block w-3 text-center text-xs leading-none" aria-hidden="true"></i></a>
        </footer>
    </article>
</template>
