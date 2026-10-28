<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import LocationPinField from '../../../components/LocationPinField.vue';
import { sessionScheduleError } from '../../../support/selectionSessionValidation';

const appElement = document.getElementById('app');
const sessionId = appElement?.dataset.sessionId || null;
const query = new URLSearchParams(window.location.search);
const programs = ref([]);
const existingSession = ref(null);
const step = ref(1);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const form = ref({
    program_id: query.get('program_id') ?? '',
    stage_key: query.get('stage_key') ?? '',
    title: '',
    starts_at: defaultDateTime(1, 9),
    ends_at: '',
    timezone: 'Asia/Manila',
    mode: 'onsite',
    venue: '',
    location_address: '',
    latitude: null,
    longitude: null,
    meeting_url: '',
    capacity: '',
    instructions: '',
    proof_required: false,
});

const selectedProgram = computed(() => programs.value.find((program) => String(program.id) === String(form.value.program_id)) ?? null);
const availableStages = computed(() => selectedProgram.value?.stages ?? []);
const selectedStage = computed(() => availableStages.value.find((stage) => stage.key === form.value.stage_key) ?? null);
const pageTitle = computed(() => sessionId ? 'Edit session' : 'Create session');
const usesVenue = computed(() => ['onsite', 'hybrid'].includes(form.value.mode));
const usesMeetingLink = computed(() => ['online', 'hybrid'].includes(form.value.mode));

function defaultDateTime(daysAhead, hour) {
    const date = new Date();
    date.setDate(date.getDate() + daysAhead);
    date.setHours(hour, 0, 0, 0);
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function toLocalInput(value) {
    if (!value) return '';
    const date = new Date(value);
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function labelize(value) {
    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not set';
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    return errors ? Object.values(errors).flat()[0] : error.response?.data?.message || fallback;
}

function continueToDelivery() {
    errorMessage.value = '';
    if (!form.value.program_id || !form.value.stage_key || !form.value.title.trim() || !form.value.starts_at) {
        errorMessage.value = 'Complete the program, stage, title, and start time before continuing.';
        return;
    }
    const scheduleError = sessionScheduleError(form.value, existingSession.value?.capacity?.assigned ?? 0);
    if (scheduleError) {
        errorMessage.value = scheduleError;
        return;
    }
    step.value = 2;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function sessionPayload() {
    return {
        stage_key: form.value.stage_key,
        title: form.value.title.trim(),
        starts_at: new Date(form.value.starts_at).toISOString(),
        ends_at: form.value.ends_at ? new Date(form.value.ends_at).toISOString() : null,
        timezone: form.value.timezone,
        mode: form.value.mode,
        venue: usesVenue.value ? form.value.venue.trim() : null,
        location_address: usesVenue.value ? form.value.location_address.trim() || null : null,
        latitude: usesVenue.value ? form.value.latitude : null,
        longitude: usesVenue.value ? form.value.longitude : null,
        meeting_url: usesMeetingLink.value ? form.value.meeting_url.trim() : null,
        capacity: form.value.capacity === '' ? null : Number(form.value.capacity),
        instructions: form.value.instructions.trim() || null,
        proof_required: form.value.proof_required,
    };
}

async function loadForm() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const endpoint = sessionId
            ? `/provider/workspaces/selection/sessions/${sessionId}/edit/data`
            : '/provider/workspaces/selection/sessions/create/data';
        const response = await window.axios.get(endpoint);
        programs.value = response.data.programs ?? [];
        existingSession.value = response.data.session;
        const session = existingSession.value;
        if (session) {
            form.value = {
                program_id: String(session.program.id),
                stage_key: session.stage_key,
                title: session.title,
                starts_at: toLocalInput(session.starts_at),
                ends_at: toLocalInput(session.ends_at),
                timezone: session.timezone || 'Asia/Manila',
                mode: session.mode,
                venue: session.venue || '',
                location_address: session.location_address || '',
                latitude: session.latitude ?? null,
                longitude: session.longitude ?? null,
                meeting_url: session.meeting_url || '',
                capacity: session.capacity.limit ?? '',
                instructions: session.instructions || '',
                proof_required: session.proof_required,
            };
        } else if (selectedStage.value) {
            form.value.proof_required = selectedStage.value.proof_required;
        }
    } catch (error) {
        errorMessage.value = firstError(error, 'The session form could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

async function saveSession() {
    errorMessage.value = '';
    const scheduleError = sessionScheduleError(form.value, existingSession.value?.capacity?.assigned ?? 0);
    if (scheduleError) {
        step.value = 1;
        errorMessage.value = scheduleError;
        return;
    }
    if (usesVenue.value && !form.value.venue.trim()) {
        errorMessage.value = 'Add the venue for an on-site or hybrid session.';
        return;
    }
    if (usesMeetingLink.value && !form.value.meeting_url.trim()) {
        errorMessage.value = 'Add the meeting link for an online or hybrid session.';
        return;
    }
    isSaving.value = true;
    try {
        const programId = form.value.program_id;
        const response = sessionId
            ? await window.axios.patch(`/provider/scholarships/${programId}/stage-sessions/${sessionId}`, sessionPayload())
            : await window.axios.post(`/provider/scholarships/${programId}/stage-sessions`, sessionPayload());
        const savedSession = response.data.session;
        const applicationId = query.get('application_id');
        if (!sessionId && applicationId) {
            await window.axios.post(`/provider/scholarships/${programId}/stage-sessions/${savedSession.id}/assignments`, { application_ids: [Number(applicationId)] });
        }
        window.location.href = `/provider/workspaces/selection/sessions/${savedSession.id}`;
    } catch (error) {
        errorMessage.value = firstError(error, 'The session could not be saved.');
    } finally {
        isSaving.value = false;
    }
}

function cancelForm() {
    if (window.history.length > 1) {
        window.history.back();
        return;
    }
    window.location.replace('/provider/workspaces/selection/unassigned');
}

watch(() => form.value.program_id, () => {
    if (sessionId) return;
    if (!availableStages.value.some((stage) => stage.key === form.value.stage_key)) form.value.stage_key = '';
});
watch(() => form.value.stage_key, () => {
    if (!sessionId && selectedStage.value) form.value.proof_required = selectedStage.value.proof_required;
});
onMounted(loadForm);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page provider-page" aria-labelledby="session-form-title">
            <div class="mx-auto w-full max-w-[94rem]">
                <a href="/provider/workspaces/selection/active" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to sessions</a>

                <header class="provider-hero task-page-header border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                    <div class="flex items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-calendar-plus" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Session setup</p><h1 id="session-form-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">{{ pageTitle }}</h1><p class="mt-1 text-sm text-slate-600">Set the schedule first, then add the attendance instructions.</p></div></div>
                </header>

                <div v-if="errorMessage" class="mt-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="isLoading" class="mt-4 border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading session setup...</p></div>

                <template v-else>
                    <nav class="mt-4 grid grid-cols-2 border border-slate-200 bg-white shadow-sm" aria-label="Session setup steps">
                        <button type="button" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-5 text-left', step === 1 ? 'bg-slate-950 text-white' : 'text-slate-700']" @click="step = 1"><span :class="['grid h-8 w-8 place-items-center text-xs font-bold', step === 1 ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-600']">1</span><span><span class="block text-[9px] font-bold uppercase tracking-[0.14em] opacity-70">Step 1</span><span class="text-sm font-bold">Schedule</span></span></button>
                        <button type="button" :class="['flex min-h-14 items-center gap-3 px-5 text-left', step === 2 ? 'bg-slate-950 text-white' : 'text-slate-700']" @click="continueToDelivery"><span :class="['grid h-8 w-8 place-items-center text-xs font-bold', step === 2 ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-600']">2</span><span><span class="block text-[9px] font-bold uppercase tracking-[0.14em] opacity-70">Step 2</span><span class="text-sm font-bold">Delivery</span></span></button>
                    </nav>

                    <form class="mt-4 border border-slate-200 bg-white shadow-sm" @submit.prevent="saveSession">
                        <section v-if="step === 1" class="px-7 py-6">
                            <div class="flex items-center gap-3 border-b border-slate-200 pb-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Session schedule</h2><p class="mt-1 text-sm text-slate-500">Choose one program and one stage for this group.</p></div></div>
                            <div class="mt-5 grid grid-cols-2 gap-x-5 gap-y-4">
                                <label class="text-sm font-semibold text-slate-700">Program<select v-model="form.program_id" required :disabled="Boolean(sessionId)" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950 disabled:bg-slate-100"><option value="" disabled>Select a program</option><option v-for="program in programs" :key="program.id" :value="String(program.id)">{{ program.title }}</option></select></label>
                                <label class="text-sm font-semibold text-slate-700">Stage<select v-model="form.stage_key" required :disabled="Boolean(sessionId) || !form.program_id" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950 disabled:bg-slate-100"><option value="" disabled>Select a stage</option><option v-for="stage in availableStages" :key="stage.key" :value="stage.key">{{ stage.label }}</option></select></label>
                                <label class="col-span-2 text-sm font-semibold text-slate-700">Session title<input v-model="form.title" type="text" required maxlength="255" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="Example: Morning examination group"></label>
                                <label class="text-sm font-semibold text-slate-700">Starts<input v-model="form.starts_at" type="datetime-local" required class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label>
                                <label class="text-sm font-semibold text-slate-700">Ends <span class="font-normal text-slate-400">(optional)</span><input v-model="form.ends_at" type="datetime-local" :min="form.starts_at || undefined" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label>
                                <label class="text-sm font-semibold text-slate-700">Capacity <span class="font-normal text-slate-400">(optional)</span><input v-model="form.capacity" type="number" :min="Math.max(1, existingSession?.capacity?.assigned || 0)" max="10000" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="No limit"></label>
                                <div class="border border-slate-200 bg-slate-50 px-4 py-3"><p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Current stage</p><p class="mt-1 text-sm font-semibold text-slate-900">{{ selectedStage?.label || labelize(form.stage_key) }}</p></div>
                            </div>
                        </section>

                        <section v-else class="px-7 py-6">
                            <div class="flex items-center gap-3 border-b border-slate-200 pb-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Delivery details</h2><p class="mt-1 text-sm text-slate-500">Only add information applicants need to participate.</p></div></div>
                            <div class="mt-5 grid grid-cols-2 gap-x-5 gap-y-4">
                                <label class="text-sm font-semibold text-slate-700">Method<select v-model="form.mode" class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950"><option value="onsite">On-site</option><option value="online">Online</option><option value="hybrid">Hybrid</option><option value="provider_managed">Managed outside the portal</option></select></label>
                                <label v-if="usesVenue" class="text-sm font-semibold text-slate-700">Venue<input v-model="form.venue" type="text" maxlength="500" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="Building or room"></label>
                                <label v-if="usesVenue" class="col-span-2 text-sm font-semibold text-slate-700">Address <span class="font-normal text-slate-400">(optional)</span><input v-model="form.location_address" type="text" maxlength="1000" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950"></label>
                                <LocationPinField v-if="usesVenue" v-model:latitude="form.latitude" v-model:longitude="form.longitude" class="col-span-2" :address="form.location_address || form.venue" title="Session venue" />
                                <label v-if="usesMeetingLink" class="col-span-2 text-sm font-semibold text-slate-700">Meeting link<input v-model="form.meeting_url" type="url" maxlength="2000" class="mt-2 h-11 w-full border border-slate-300 px-3 font-normal outline-none focus:border-slate-950" placeholder="https://"></label>
                                <label class="col-span-2 text-sm font-semibold text-slate-700">Applicant instructions <span class="font-normal text-slate-400">(optional)</span><textarea v-model="form.instructions" rows="4" maxlength="3000" class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950" placeholder="What to bring or how to prepare"></textarea></label>
                                <label class="col-span-2 flex items-start gap-3 border border-slate-200 bg-slate-50 px-4 py-3"><input v-model="form.proof_required" type="checkbox" class="mt-1 h-4 w-4 border-slate-300 text-slate-950 focus:ring-slate-950"><span><span class="block text-sm font-bold text-slate-900">Require provider proof before completion</span><span class="mt-1 block text-xs leading-5 text-slate-500">Useful for attendance sheets, score sheets, and interview evaluation records.</span></span></label>
                            </div>
                        </section>

                        <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-7 py-4">
                            <button v-if="step === 2" type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950" @click="step = 1"><i class="fa-solid fa-arrow-left text-[10px]" aria-hidden="true"></i>Previous</button><button v-else type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950" @click="cancelForm"><i class="fa-solid fa-xmark text-[10px]" aria-hidden="true"></i>Cancel</button>
                            <button v-if="step === 1" type="button" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-xs font-bold text-white hover:bg-slate-800" @click="continueToDelivery">Continue<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></button><button v-else type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="isSaving"><i :class="['fa-solid', isSaving ? 'fa-spinner fa-spin' : 'fa-floppy-disk']" aria-hidden="true"></i>{{ isSaving ? 'Saving...' : sessionId ? 'Save changes' : 'Save and open roster' }}</button>
                        </footer>
                    </form>
                </template>
            </div>
        </section>
    </main>
</template>
