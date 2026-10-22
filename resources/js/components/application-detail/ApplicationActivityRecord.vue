<script setup>
import { computed } from 'vue';

const props = defineProps({
    activity: {
        type: Object,
        required: true,
    },
    acknowledging: {
        type: Boolean,
        default: false,
    },
    primary: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['acknowledge', 'map']);

const dateParts = computed(() => {
    if (!props.activity.scheduled_at) {
        return { month: 'TBA', day: '--' };
    }

    const date = new Date(props.activity.scheduled_at);

    if (Number.isNaN(date.getTime())) {
        return { month: 'TBA', day: '--' };
    }

    return {
        month: new Intl.DateTimeFormat('en', { month: 'short' }).format(date),
        day: new Intl.DateTimeFormat('en', { day: '2-digit' }).format(date),
    };
});

const canShowMap = computed(() => ['onsite', 'hybrid'].includes(props.activity.mode)
    && Boolean(
        props.activity.location_address
        || props.activity.venue
        || (props.activity.latitude && props.activity.longitude),
    ));

const statusClass = computed(() => ({
    scheduled: 'bg-sky-100 text-sky-800',
    ongoing: 'bg-amber-100 text-amber-800',
    completed: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-rose-100 text-rose-800',
    rescheduled: 'bg-slate-100 text-slate-700',
}[props.activity.status] ?? 'bg-slate-100 text-slate-700'));

function attendanceLabel(status) {
    return {
        attended: 'Attended',
        absent: 'Absent',
        excused: 'Excused',
        pending: 'Not recorded',
    }[status] ?? String(status || '').replaceAll('_', ' ');
}
</script>

<template>
    <article :id="primary ? 'current-activity' : undefined" class="scroll-mt-4 bg-white">
        <div class="flex flex-col gap-4 px-4 py-5 sm:flex-row sm:items-center sm:px-6">
            <div class="flex min-w-0 flex-1 items-start gap-4">
                <time class="grid h-16 w-16 shrink-0 place-items-center rounded-sm bg-slate-950 text-center text-white">
                    <span class="self-end text-[10px] font-bold uppercase tracking-wide text-amber-300">{{ dateParts.month }}</span>
                    <span class="self-start text-2xl font-bold leading-none">{{ dateParts.day }}</span>
                </time>

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">{{ activity.type_label }}</p>
                        <span :class="['rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', statusClass]">
                            {{ activity.status_label }}
                        </span>
                        <span v-if="activity.applicant_acknowledged" class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700">
                            <i class="fa-solid fa-check" aria-hidden="true"></i>
                            Confirmed
                        </span>
                    </div>
                    <h3 class="mt-1 font-display text-lg font-bold text-slate-950">{{ activity.title }}</h3>
                    <p class="mt-1 text-sm font-semibold text-slate-600">{{ activity.scheduled_label }}</p>
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2">
                <a
                    v-if="activity.online_url"
                    :href="activity.online_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800"
                >
                    <i class="fa-solid fa-video text-xs" aria-hidden="true"></i>
                    Open meeting
                </a>
                <button
                    v-if="canShowMap"
                    type="button"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50"
                    @click="emit('map', activity)"
                >
                    <i class="fa-solid fa-map-location-dot text-xs text-amber-700" aria-hidden="true"></i>
                    View map
                </button>
            </div>
        </div>

        <dl class="grid border-t border-slate-200 bg-slate-50/80 sm:grid-cols-2 xl:grid-cols-4">
            <div class="px-4 py-3 sm:px-5">
                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Method</dt>
                <dd class="mt-1 text-sm font-bold text-slate-950">{{ activity.mode_label || 'Provider will confirm' }}</dd>
            </div>
            <div class="border-t border-slate-200 px-4 py-3 sm:border-l sm:border-t-0 sm:px-5">
                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Location</dt>
                <dd class="mt-1 text-sm font-bold text-slate-950">{{ activity.venue || activity.location_address || (activity.mode === 'online' ? 'Online meeting' : 'To be announced') }}</dd>
            </div>
            <div class="border-t border-slate-200 px-4 py-3 sm:border-t sm:px-5 xl:border-l xl:border-t-0">
                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Attendance</dt>
                <dd class="mt-1 text-sm font-bold capitalize text-slate-950">{{ attendanceLabel(activity.attendance_status || 'pending') }}</dd>
            </div>
            <div class="border-t border-slate-200 px-4 py-3 sm:border-l sm:px-5 xl:border-t-0">
                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Verification</dt>
                <dd class="mt-1 text-sm font-bold text-slate-950">{{ activity.verification_label || 'No provider proof required' }}</dd>
            </div>
        </dl>

        <div v-if="activity.location_address && activity.location_address !== activity.venue" class="border-t border-slate-200 px-4 py-3 text-sm text-slate-600 sm:px-5">
            <i class="fa-solid fa-location-dot mr-2 text-amber-700" aria-hidden="true"></i>
            {{ activity.location_address }}
        </div>

        <div v-if="activity.instructions" class="border-t border-slate-200 px-4 py-4 sm:px-5">
            <p class="text-xs font-bold text-slate-500">Before you attend</p>
            <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ activity.instructions }}</p>
        </div>

        <div v-if="activity.requires_applicant_acknowledgment" class="flex flex-col gap-3 border-t border-slate-200 bg-amber-50/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <p class="text-sm text-slate-700">Confirm that you received this schedule and its instructions.</p>
            <button
                type="button"
                :disabled="acknowledging"
                class="shrink-0 rounded-sm bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50"
                @click="emit('acknowledge', activity)"
            >
                {{ acknowledging ? 'Confirming...' : 'Confirm schedule' }}
            </button>
        </div>
    </article>
</template>
