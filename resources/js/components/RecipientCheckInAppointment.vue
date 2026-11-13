<script setup>
import { computed, ref } from 'vue';
import { useModalAccessibility } from '../composables/useModalAccessibility';
import { confirmOutcome } from '../support/outcomeConfirmation.js';
import ProviderVenueAddress from './ProviderVenueAddress.vue';
import LocationMapLink from './LocationMapLink.vue';

const props = defineProps({
    recipient: { type: Object, required: true },
    editable: Boolean,
});
const emit = defineEmits(['updated']);
const dialog = ref(null);
const mode = ref('');
const error = ref('');
const saving = ref(false);
const form = ref({});
const appointment = computed(() => props.recipient.handoff);
const attendance = computed(() => props.recipient.monitoring_case?.attendance_status);
const { handleKeydown } = useModalAccessibility(() => Boolean(mode.value), dialog, close);

function open(action) {
    error.value = '';
    form.value = {
        appointment_at: '',
        appointment_location: appointment.value.location,
        address_details: appointment.value.address_details ? { ...appointment.value.address_details } : null,
        attendance_status: attendance.value || '',
        reason: '',
    };
    mode.value = action;
}

function close() {
    if (!saving.value) mode.value = '';
}

async function save() {
    const action = mode.value;
    const recipient = props.recipient;
    if (saving.value || !action) return;
    const reschedule = action === 'reschedule';
    const attendanceLabel = form.value.attendance_status === 'attended' ? 'Attended' : 'Missed appointment';
    if (!await confirmOutcome({
        record: recipient.name,
        outcome: reschedule ? 'Reschedule appointment' : attendanceLabel,
        danger: !reschedule && form.value.attendance_status === 'no_show',
        consequence: reschedule
            ? 'Only this recipient’s appointment will change. The recipient will be notified.'
            : 'This saves attendance only. Checklist results and scholarship support are recorded separately.',
    }, () => !saving.value && mode.value === action && props.recipient.application_id === recipient.application_id)) return;

    saving.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.patch(
            `/provider/monitoring-cases/${recipient.monitoring_case.id}/appointment`,
            reschedule
                ? { action, appointment_at: form.value.appointment_at, appointment_location: form.value.appointment_location, address_details: form.value.address_details, reason: form.value.reason }
                : { action, attendance_status: form.value.attendance_status, reason: form.value.reason || null },
            { portalToast: false },
        );
        mode.value = '';
        emit('updated', data);
    } catch (failure) {
        error.value = Object.values(failure.response?.data?.errors ?? {}).flat()[0]
            ?? failure.response?.data?.message ?? 'The appointment could not be updated.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section v-if="appointment" class="my-4 flex items-center justify-between gap-5 border border-slate-200 bg-slate-50 p-4" aria-label="Recipient appointment">
        <div class="flex min-w-0 items-start gap-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center border border-slate-200 bg-white text-slate-600"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h4 class="text-sm font-bold text-slate-950">Recipient appointment</h4>
                    <span v-if="appointment.rescheduled" class="border border-slate-200 bg-white px-2 py-0.5 text-[11px] font-bold text-slate-700">Rescheduled</span>
                </div>
                <p class="mt-1 text-sm font-semibold text-slate-800">{{ appointment.scheduled_label }}</p>
                <p class="mt-0.5 break-words text-xs text-slate-600">{{ appointment.location }}</p>
                <LocationMapLink :address="appointment.location || ''" :latitude="appointment.latitude" :longitude="appointment.longitude" title="Recipient appointment" />
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <span :class="['mr-1 border px-2.5 py-1 text-xs font-bold', attendance === 'attended' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : attendance === 'no_show' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-slate-200 bg-white text-slate-600']">{{ attendance || appointment.started ? recipient.monitoring_case.attendance_label : 'Scheduled' }}</span>
            <button v-if="editable && attendance !== 'attended'" type="button" class="inline-flex items-center gap-2 rounded border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="open('reschedule')"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i>Reschedule</button>
            <button v-if="editable && appointment.started" type="button" class="rounded bg-slate-950 px-3 py-2 text-xs font-bold text-white" @click="open('attendance')">{{ attendance ? 'Change attendance' : 'Record attendance' }}</button>
        </div>
    </section>

    <Teleport to="body">
        <div v-if="mode" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6" @click.self="close">
            <form ref="dialog" class="provider-monitoring-workspace monitoring-dialog w-full max-w-xl border border-slate-300 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="check-in-appointment-title" tabindex="-1" @keydown="handleKeydown" @submit.prevent="save">
                <header class="flex items-start justify-between gap-5 border-b border-slate-200 px-6 py-5">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-amber-700">Recipient appointment</p>
                        <h2 id="check-in-appointment-title" class="mt-1 text-xl font-bold text-slate-950">{{ mode === 'reschedule' ? 'Reschedule appointment' : 'Record attendance' }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ recipient.name }} <span class="text-slate-400">|</span> {{ appointment.scheduled_label }}</p>
                    </div>
                    <button type="button" :disabled="saving" class="grid h-9 w-9 shrink-0 place-items-center rounded border border-slate-300 text-slate-600" aria-label="Close" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div class="monitoring-dialog-body space-y-4 p-6">
                    <p v-if="error" role="alert" class="border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">{{ error }}</p>
                    <template v-if="mode === 'reschedule'">
                        <label class="block"><span class="mb-1.5 block text-xs font-bold text-slate-700">New date and time (Philippine time)</span><input v-model="form.appointment_at" type="datetime-local" required class="h-11 w-full rounded border border-slate-300 px-3 text-sm"></label>
                        <ProviderVenueAddress v-model="form.address_details" v-model:address="form.appointment_location" />
                    </template>
                    <label v-else class="block"><span class="mb-1.5 block text-xs font-bold text-slate-700">Attendance</span><select v-model="form.attendance_status" required class="h-11 w-full rounded border border-slate-300 bg-white px-3 text-sm"><option disabled value="">Select attendance</option><option value="attended">Attended</option><option value="no_show">Missed appointment</option></select></label>
                    <label v-if="mode === 'reschedule' || attendance" class="block"><span class="mb-1.5 block text-xs font-bold text-slate-700">Reason for the change <span class="font-normal">(internal)</span></span><textarea v-model="form.reason" required minlength="10" maxlength="1500" rows="2" class="w-full rounded border border-slate-300 px-3 py-2 text-sm"></textarea></label>
                    <p class="text-xs text-slate-600">{{ mode === 'reschedule' ? 'The new time and venue are shared with this recipient only.' : 'Mark the recipient as attended before recording document results.' }}</p>
                </div>
                <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" :disabled="saving" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700" @click="close">Cancel</button><button type="submit" :disabled="saving || (mode === 'attendance' && form.attendance_status === attendance)" class="rounded bg-slate-950 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{{ saving ? 'Saving...' : mode === 'reschedule' ? 'Save appointment' : 'Save attendance' }}</button></footer>
            </form>
        </div>
    </Teleport>
</template>
