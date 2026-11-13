<script setup>
import { computed } from 'vue';
import MonitoringCheckInRecord from './MonitoringCheckInRecord.vue';
import LocationMapLink from '../LocationMapLink.vue';
import { checkInStatusClass } from '../../support/checkInStatus';

const props = defineProps({
    content: { type: Object, required: true },
    applicationId: { type: [Number, String], required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['refresh']);
const appointment = computed(() => props.content.directory.next_appointment);
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
        <section v-if="appointment" data-next-check-in-appointment class="grid overflow-hidden rounded-md border border-amber-300 bg-white shadow-sm lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]" aria-labelledby="next-check-in-title">
            <div class="min-w-0 border-l-4 border-amber-400 px-5 py-5 sm:px-6">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-900"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span>
                    <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-amber-800">Next appointment</p>
                    <span :class="['border px-2 py-1 text-[10px] font-bold uppercase', checkInStatusClass(appointment.state_tone)]">{{ appointment.state_label }}</span>
                </div>
                <h3 id="next-check-in-title" class="mt-2 font-display text-2xl font-bold leading-tight text-slate-950">{{ appointment.title }}</h3>
                <p class="mt-3 flex items-start gap-2 text-sm font-bold text-slate-950">
                    <i class="fa-regular fa-clock mt-0.5 w-4 shrink-0 text-slate-500" aria-hidden="true"></i>{{ appointment.handoff.scheduled_label }}
                </p>
                <p class="mt-1.5 flex items-start gap-2 break-words text-sm text-slate-600">
                    <i class="fa-solid fa-location-dot mt-0.5 w-4 shrink-0 text-slate-500" aria-hidden="true"></i>{{ appointment.handoff.location }}
                </p>
                <LocationMapLink :address="appointment.handoff.location" :latitude="appointment.handoff.latitude" :longitude="appointment.handoff.longitude" :title="appointment.title || 'Check-in venue'" />
                <p v-if="appointment.state !== 'upcoming' && appointment.next_actor_label" class="mt-2 text-xs font-bold text-slate-700">Next step: {{ appointment.next_actor_label }}</p>
            </div>
            <div class="flex min-w-0 flex-col items-start justify-between gap-4 border-t border-slate-200 bg-slate-50 px-5 py-5 sm:px-6 lg:border-l lg:border-t-0">
                <div>
                    <h4 class="font-bold text-slate-950">What to bring</h4>
                    <ul v-if="appointment.preparation.document_count" class="mt-2 space-y-1 text-sm text-slate-600">
                        <li v-for="(title, index) in appointment.preparation.preview_titles" :key="index" class="flex items-start gap-2"><i class="fa-regular fa-file-lines mt-0.5 w-4 shrink-0 text-slate-500" aria-hidden="true"></i>{{ title }}</li>
                        <li v-if="appointment.preparation.document_count > appointment.preparation.preview_titles.length" class="text-xs font-semibold">+{{ appointment.preparation.document_count - appointment.preparation.preview_titles.length }} more</li>
                    </ul>
                    <p v-else class="mt-1 text-sm text-slate-600">No documents are listed for this visit.</p>
                </div>
                <a :href="appointment.href" class="student-button student-button-primary">View appointment <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
            </div>
        </section>

        <section v-if="content.empty" class="rounded-md border border-dashed border-slate-300 bg-white px-5 py-9 text-center shadow-sm">
            <span class="mx-auto grid h-11 w-11 place-items-center rounded-sm bg-slate-100 text-slate-500"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
            <h3 class="mt-3 text-base font-bold text-slate-950">{{ content.empty.title }}</h3>
            <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-600">{{ content.empty.description }}</p>
        </section>

        <section v-for="group in groups" :key="group.title" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50/70 px-5 py-4 sm:px-6">
                <h3 class="font-display text-lg font-bold text-slate-950">{{ group.title }}</h3>
                <span class="text-xs font-bold text-slate-600">{{ group.records.length }}</span>
            </header>
            <ul class="divide-y divide-slate-200">
                <li v-for="record in group.records" :key="record.record_key" class="grid items-center gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i :class="record.handoff ? 'fa-regular fa-calendar' : 'fa-solid fa-list-check'" aria-hidden="true"></i></span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="font-bold text-slate-950">{{ record.title }}</h4>
                                <span :class="['border px-2 py-1 text-[10px] font-bold uppercase', checkInStatusClass(record.state_tone)]">{{ record.state_label }}</span>
                            </div>
                            <p v-if="record.handoff || record.due_label" class="mt-1 text-xs text-slate-600">{{ record.handoff?.scheduled_label || 'Due ' + record.due_label }}<span v-if="record.handoff?.location"> &middot; {{ record.handoff.location }}</span></p>
                            <p v-if="record.state !== 'upcoming' && record.next_actor_label" class="mt-1 text-xs text-slate-600"><strong class="text-slate-800">Next step: {{ record.next_actor_label }}</strong><span v-if="record.progress_label"> &middot; {{ record.progress_label }}</span></p>
                        </div>
                    </div>
                    <a :href="record.href" class="student-button student-button-secondary">Open check-in <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                </li>
            </ul>
        </section>

        <details v-if="content.directory.completed.length" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                    <div>
                        <h3 class="text-base font-bold text-slate-950">Past check-ins</h3>
                        <p class="mt-0.5 text-xs text-slate-600">Review recorded outcomes.</p>
                    </div>
                </div>
                <span class="flex shrink-0 items-center gap-2.5 text-xs font-bold text-slate-600">{{ content.directory.completed.length }} {{ content.directory.completed.length === 1 ? 'record' : 'records' }} <i class="fa-solid fa-chevron-down transition group-open:rotate-180" aria-hidden="true"></i></span>
            </summary>
            <ul class="divide-y divide-slate-200 border-t border-slate-200">
                <li v-for="record in content.directory.completed" :key="record.record_key" class="grid items-center gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-6">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="text-sm font-bold text-slate-950">{{ record.title }}</h4>
                            <span :class="['border px-2 py-1 text-[10px] font-bold uppercase', checkInStatusClass(record.state_tone)]">{{ record.state_label }}</span>
                        </div>
                        <p v-if="record.closed_at || record.period_label" class="mt-1 text-xs text-slate-600">{{ record.closed_at ? 'Closed ' + record.closed_at : record.period_label }}</p>
                    </div>
                    <a :href="record.href" class="student-button student-button-secondary">Open record <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                </li>
            </ul>
        </details>
    </div>
</template>
