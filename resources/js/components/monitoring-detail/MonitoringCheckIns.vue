<script setup>
import { computed } from 'vue';
import MonitoringCheckInRecord from './MonitoringCheckInRecord.vue';
import CheckInSummaryCard from './CheckInSummaryCard.vue';
import LocationMapLink from '../LocationMapLink.vue';
import { checkInStatusClass } from '../../support/checkInStatus';

const props = defineProps({
    content: { type: Object, required: true },
    applicationId: { type: [Number, String], required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['refresh']);
const appointment = computed(() => props.content.directory.next_appointment);
const summary = computed(() => [
    { key: 'active', title: 'Open check-ins', count: props.content.directory.active.length },
    { key: 'upcoming', title: 'Upcoming check-ins', count: props.content.directory.upcoming.length },
    { key: 'completed', title: 'Past check-ins', count: props.content.directory.completed.length },
]);
const groups = computed(() => [
    {
        title: 'Open check-ins',
        records: props.content.directory.active.filter(record => record.record_key !== appointment.value?.record_key),
    },
    {
        title: 'Upcoming check-ins',
        records: props.content.directory.upcoming.filter(record => record.record_key !== appointment.value?.record_key),
    },
].filter(group => group.records.length));
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
        <section data-check-in-directory-header class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="relative isolate overflow-hidden bg-portal-navy px-5 py-5 text-white sm:px-6">
                <div class="relative z-10 max-w-2xl"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-300">Your scholarship commitments</p><h2 class="mt-1 font-display text-xl font-bold sm:text-2xl">Check-ins</h2><p class="mt-2 max-w-xl text-sm leading-6 text-slate-200">Keep your appointments, required records, and provider updates in view.</p></div>
                <div aria-hidden="true" class="pointer-events-none absolute inset-y-0 right-0 hidden w-2/5 md:block"><img :src="'/images/filipino-students-group-study.jpg'" alt="" class="h-full w-full object-cover opacity-40"><div class="absolute inset-0 bg-linear-to-r from-portal-navy via-portal-navy/50 to-portal-navy/20"></div></div>
            </header>
            <dl v-if="!content.empty" data-check-in-summary class="grid grid-cols-3 divide-x divide-slate-200"><div v-for="item in summary" :key="item.key" class="px-3 py-3 sm:px-5 sm:py-4"><dt class="text-[10px] font-bold leading-4 text-slate-500 sm:text-xs">{{ item.title }}</dt><dd class="mt-1 text-xl font-bold tabular-nums text-slate-950">{{ item.count }}</dd></div></dl>
        </section>

        <section v-if="appointment" data-next-check-in-appointment class="overflow-hidden rounded-md border border-amber-300 bg-white shadow-sm" aria-labelledby="next-check-in-title">
            <header class="border-b border-amber-200 bg-amber-50/60 px-4 py-4 sm:px-5">
                <div class="flex flex-wrap items-center gap-2.5"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800">Next appointment</p><span :class="['rounded-sm border px-2 py-0.5 text-[10px] font-bold uppercase', checkInStatusClass(appointment.state_tone)]">{{ appointment.state_label }}</span></div>
                <h3 id="next-check-in-title" class="mt-2 break-words font-display text-lg font-bold leading-snug text-slate-950 sm:text-xl">{{ appointment.title }}</h3>
            </header>
            <dl class="grid gap-4 px-4 py-5 sm:grid-cols-2 sm:gap-6 sm:px-5">
                <div class="min-w-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ appointment.handoff.rescheduled ? 'New date and time' : 'Date and time' }}</dt><dd class="mt-1 break-words text-base font-bold text-slate-950">{{ appointment.handoff.scheduled_label }}</dd></div>
                <div class="min-w-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Venue</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-800">{{ appointment.handoff.location || 'Awaiting provider details' }}</dd><LocationMapLink :address="appointment.handoff.location" :latitude="appointment.handoff.latitude" :longitude="appointment.handoff.longitude" :title="appointment.title || 'Check-in venue'" /></div>
            </dl>
            <footer class="flex flex-col gap-4 border-t border-slate-200 bg-slate-50/60 px-4 py-4 sm:px-5 lg:flex-row lg:items-center lg:justify-between lg:gap-6">
                <div class="min-w-0">
                    <h4 class="text-sm font-bold text-slate-950">What to bring</h4>
                    <ul v-if="appointment.preparation.document_count" class="mt-2 flex flex-wrap gap-x-5 gap-y-1.5 text-sm text-slate-600"><li v-for="(title, index) in appointment.preparation.preview_titles" :key="index" class="flex min-w-0 items-start gap-2"><i class="fa-regular fa-file-lines mt-0.5 shrink-0 text-xs leading-none" aria-hidden="true"></i><span class="break-words">{{ title }}</span></li><li v-if="appointment.preparation.document_count > appointment.preparation.preview_titles.length" class="text-xs font-semibold">+{{ appointment.preparation.document_count - appointment.preparation.preview_titles.length }} more in the appointment</li></ul>
                    <p v-else class="mt-1 text-sm text-slate-600">No documents are listed for this visit.</p>
                    <p v-if="appointment.state !== 'upcoming' && appointment.next_actor_label" class="mt-2 text-xs font-semibold text-slate-600">Next step: {{ appointment.next_actor_label }}</p>
                </div>
                <a :href="appointment.href" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md border border-slate-950 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 sm:min-w-44">View appointment <i class="fa-solid fa-arrow-right block w-3 text-center text-xs leading-none" aria-hidden="true"></i></a>
            </footer>
        </section>

        <section v-if="content.empty" class="rounded-md border border-dashed border-slate-300 bg-white px-5 py-9 text-center shadow-sm">
            <span class="mx-auto grid h-11 w-11 place-items-center rounded-sm bg-slate-100 text-slate-500"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
            <h3 class="mt-3 text-base font-bold text-slate-950">{{ content.empty.title }}</h3>
            <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-600">{{ content.empty.description }}</p>
        </section>

        <section v-for="group in groups" :key="group.title" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="flex items-center justify-between gap-4 bg-slate-50 px-4 py-4 sm:px-5">
                <h3 class="text-base font-bold text-slate-950">{{ group.title }}</h3>
                <span class="text-xs font-bold text-slate-600">{{ group.records.length }}</span>
            </header>
            <CheckInSummaryCard v-for="record in group.records" :key="record.record_key" :record="record" />
        </section>

        <details v-if="content.directory.completed.length" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 bg-slate-50 px-4 py-4 sm:px-5 [&::-webkit-details-marker]:hidden">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                    <div>
                        <h3 class="text-base font-bold text-slate-950">Past check-ins</h3>
                        <p class="mt-0.5 text-xs text-slate-600">Review recorded outcomes.</p>
                    </div>
                </div>
                <span class="flex shrink-0 items-center gap-2.5 text-xs font-bold text-slate-600">{{ content.directory.completed.length }} {{ content.directory.completed.length === 1 ? 'record' : 'records' }} <i class="fa-solid fa-chevron-down transition group-open:rotate-180" aria-hidden="true"></i></span>
            </summary>
            <CheckInSummaryCard v-for="record in content.directory.completed" :key="record.record_key" :record="record" past />
        </details>
    </div>
</template>
