<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import LocationMapLink from '../../../components/LocationMapLink.vue';

const appElement = document.getElementById('app');
const sessionId = appElement?.dataset.sessionId;
const programId = appElement?.dataset.programId;
const session = ref(null);
const activeSection = ref('overview');
const candidates = ref([]);
const selectedCandidates = ref([]);
const candidateSearch = ref('');
const showCandidates = ref(false);
const isLoading = ref(true);
const actionBusy = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const showActionDialog = ref(false);
const pendingAction = ref('');
const cancelReason = ref('');
const showMoveDialog = ref(false);
const movingAssignment = ref(null);
const replacementSessions = ref([]);
const targetSessionId = ref('');
const moveNotes = ref('');
const proofForm = ref({ category: 'attendance_sheet', event_date: '', note: '', visibility: 'provider_private', evidence_file: null });

const sections = [
    { key: 'overview', label: 'Overview', icon: 'fa-solid fa-calendar-day' },
    { key: 'roster', label: 'Roster', icon: 'fa-solid fa-users' },
    { key: 'proof', label: 'Proof', icon: 'fa-solid fa-file-shield' },
];
const activeAssignments = computed(() => (session.value?.assignments ?? []).filter((item) => ['assigned', 'acknowledged'].includes(item.status)));
const canAddApplicants = computed(() => ['draft', 'scheduled'].includes(session.value?.status));
const canRecordAttendance = computed(() => ['scheduled', 'ongoing'].includes(session.value?.status));
const currentEvidence = computed(() => (session.value?.evidence ?? []).filter((item) => item.is_current));
const pendingAttendance = computed(() => activeAssignments.value.filter((item) => (item.attendance?.status ?? 'pending') === 'pending').length);
const completionBlocked = computed(() => pendingAttendance.value > 0 || (session.value?.proof_required && currentEvidence.value.length === 0));

function labelize(value) {
    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not set';
}

function formatDate(value) {
    if (!value) return 'Not set';
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value));
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    return errors ? Object.values(errors).flat()[0] : error.response?.data?.message || fallback;
}

function statusClass(status) {
    return {
        draft: 'border-slate-300 bg-white text-slate-700', scheduled: 'border-sky-200 bg-sky-50 text-sky-800', ongoing: 'border-amber-200 bg-amber-50 text-amber-800', completed: 'border-emerald-200 bg-emerald-50 text-emerald-800', cancelled: 'border-rose-200 bg-rose-50 text-rose-800',
    }[status] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

async function loadSession({ quiet = false } = {}) {
    if (!quiet) isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/scholarships/${programId}/stage-sessions/${sessionId}`);
        session.value = response.data.session;
    } catch (error) {
        errorMessage.value = firstError(error, 'The session record could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

async function loadCandidates() {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/scholarships/${programId}/stage-sessions/${sessionId}/candidates`, { params: { search: candidateSearch.value.trim() || undefined } });
        candidates.value = response.data.candidates ?? [];
        selectedCandidates.value = [];
        showCandidates.value = true;
    } catch (error) {
        errorMessage.value = firstError(error, 'Available applicants could not be loaded.');
    } finally {
        actionBusy.value = false;
    }
}

async function assignCandidates() {
    if (!selectedCandidates.value.length) return;
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.post(`/provider/scholarships/${programId}/stage-sessions/${sessionId}/assignments`, { application_ids: selectedCandidates.value });
        successMessage.value = response.data.message;
        showCandidates.value = false;
        await loadSession({ quiet: true });
    } catch (error) {
        errorMessage.value = firstError(error, 'The applicants could not be assigned.');
    } finally {
        actionBusy.value = false;
    }
}

async function publishSession() {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.post(`/provider/scholarships/${programId}/stage-sessions/${sessionId}/publish`);
        successMessage.value = response.data.message;
        await loadSession({ quiet: true });
    } catch (error) {
        errorMessage.value = firstError(error, 'The session could not be published.');
    } finally {
        actionBusy.value = false;
    }
}

async function saveAttendance(assignment, status) {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/scholarships/${programId}/stage-sessions/${sessionId}/assignments/${assignment.id}/attendance`, { attendance_status: status });
        successMessage.value = response.data.message;
        await loadSession({ quiet: true });
    } catch (error) {
        errorMessage.value = firstError(error, 'Attendance could not be recorded.');
    } finally {
        actionBusy.value = false;
    }
}

async function openMoveDialog(assignment) {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/scholarships/${programId}/stage-sessions`, {
            params: { stage_key: session.value.stage_key },
        });
        replacementSessions.value = (response.data.sessions ?? []).filter((item) => (
            item.id !== session.value.id && ['draft', 'scheduled'].includes(item.status)
        ));
        movingAssignment.value = assignment;
        targetSessionId.value = '';
        moveNotes.value = '';
        showMoveDialog.value = true;
    } catch (error) {
        errorMessage.value = firstError(error, 'Replacement sessions could not be loaded.');
    } finally {
        actionBusy.value = false;
    }
}

async function moveAssignment() {
    if (!targetSessionId.value) return;
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(
            `/provider/scholarships/${programId}/stage-sessions/${sessionId}/assignments/${movingAssignment.value.id}/reschedule`,
            { target_session_id: Number(targetSessionId.value), notes: moveNotes.value.trim() || null },
        );
        successMessage.value = response.data.message;
        showMoveDialog.value = false;
        await loadSession({ quiet: true });
    } catch (error) {
        errorMessage.value = firstError(error, 'The applicant could not be moved.');
    } finally {
        actionBusy.value = false;
    }
}

async function uploadProof() {
    if (!proofForm.value.evidence_file) {
        errorMessage.value = 'Choose a proof file to upload.';
        return;
    }
    actionBusy.value = true;
    errorMessage.value = '';
    const payload = new FormData();
    Object.entries(proofForm.value).forEach(([key, value]) => {
        if (value !== '' && value !== null) payload.append(key, value);
    });
    try {
        const response = await window.axios.post(`/provider/scholarships/${programId}/stage-sessions/${sessionId}/evidence`, payload);
        successMessage.value = response.data.message;
        proofForm.value = { category: 'attendance_sheet', event_date: '', note: '', visibility: 'provider_private', evidence_file: null };
        const input = document.getElementById('session-proof-file');
        if (input) input.value = '';
        await loadSession({ quiet: true });
    } catch (error) {
        errorMessage.value = firstError(error, 'The proof could not be uploaded.');
    } finally {
        actionBusy.value = false;
    }
}

function requestSessionAction(action) {
    pendingAction.value = action;
    cancelReason.value = '';
    showActionDialog.value = true;
}

async function confirmSessionAction() {
    if (pendingAction.value === 'cancel' && cancelReason.value.trim().length < 5) {
        errorMessage.value = 'Give a short reason for cancelling this session.';
        return;
    }
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const endpoint = `/provider/scholarships/${programId}/stage-sessions/${sessionId}/${pendingAction.value}`;
        const payload = pendingAction.value === 'cancel' ? { reason: cancelReason.value.trim() } : {};
        const response = await window.axios.post(endpoint, payload);
        successMessage.value = response.data.message;
        showActionDialog.value = false;
        await loadSession({ quiet: true });
        if (pendingAction.value === 'complete') activeSection.value = 'roster';
    } catch (error) {
        errorMessage.value = firstError(error, `The session could not be ${pendingAction.value}d.`);
        showActionDialog.value = false;
    } finally {
        actionBusy.value = false;
    }
}

onMounted(loadSession);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page provider-page" aria-labelledby="session-record-title">
            <div class="mx-auto max-w-[94rem]">
                <a href="/provider/workspaces/selection/active" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to sessions</a>
                <div v-if="errorMessage" class="mb-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mb-4 border-l-4 border-emerald-500 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</div>
                <div v-if="isLoading" class="border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading session...</p></div>

                <template v-else-if="session">
                    <header class="provider-hero task-page-header border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-8">
                            <div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span><div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">{{ labelize(session.stage_key) }} session</p><h1 id="session-record-title" class="mt-1 truncate font-display text-2xl font-bold tracking-tight text-slate-950">{{ session.title }}</h1><p class="mt-1 truncate text-sm text-slate-600">{{ session.program?.title }} · {{ formatDate(session.starts_at) }}</p></div></div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span :class="['border px-3 py-2 text-[10px] font-bold uppercase tracking-wide', statusClass(session.status)]">{{ labelize(session.status) }}</span>
                                <a v-if="session.status === 'draft'" :href="`/provider/workspaces/selection/sessions/${session.id}/edit`" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950"><i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>Edit</a>
                                <button v-if="session.status === 'draft'" type="button" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="actionBusy" @click="publishSession"><i class="fa-solid fa-paper-plane text-[10px]" aria-hidden="true"></i>Publish</button>
                                <button v-if="['scheduled', 'ongoing'].includes(session.status)" type="button" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="actionBusy || completionBlocked" @click="requestSessionAction('complete')"><i class="fa-solid fa-check text-[10px]" aria-hidden="true"></i>Complete session</button>
                                <a v-if="session.status === 'completed'" href="/provider/workspaces/selection/results" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800"><i class="fa-solid fa-clipboard-check text-[10px]" aria-hidden="true"></i>Record outcomes</a>
                                <button v-if="!['completed', 'cancelled'].includes(session.status)" type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-rose-700 hover:border-rose-400" @click="requestSessionAction('cancel')"><i class="fa-solid fa-xmark text-[10px]" aria-hidden="true"></i>Cancel</button>
                            </div>
                        </div>
                    </header>

                    <nav class="mt-4 grid grid-cols-3 border border-slate-200 bg-white shadow-sm" aria-label="Session record sections">
                        <button v-for="section in sections" :key="section.key" type="button" :class="['flex min-h-14 items-center justify-center gap-2 border-r border-slate-200 text-sm font-bold last:border-r-0', activeSection === section.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50']" @click="activeSection = section.key"><i :class="[section.icon, activeSection === section.key ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>{{ section.label }}<span v-if="section.key === 'roster'" :class="['px-2 py-0.5 text-[10px]', activeSection === section.key ? 'bg-amber-300 text-slate-950' : 'bg-slate-100 text-slate-600']">{{ activeAssignments.length }}</span></button>
                    </nav>

                    <section v-if="activeSection === 'overview'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Session details</h2><p class="mt-1 text-sm text-slate-500">The shared schedule for everyone assigned to this session.</p></div></header>
                        <dl class="grid grid-cols-4 border-b border-slate-200">
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.13em] text-slate-500">Date and time</dt><dd class="mt-2 text-sm font-bold text-slate-950">{{ formatDate(session.starts_at) }}</dd></div>
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.13em] text-slate-500">Method</dt><dd class="mt-2 text-sm font-bold text-slate-950">{{ labelize(session.mode) }}</dd></div>
                            <div class="border-r border-slate-200 px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.13em] text-slate-500">Place or link</dt><dd class="mt-2 truncate text-sm font-bold text-slate-950">{{ session.venue || session.meeting_url || 'Provider managed' }}</dd><LocationMapLink v-if="['onsite', 'hybrid'].includes(session.mode)" :address="session.location_address || session.venue || ''" :latitude="session.latitude" :longitude="session.longitude" :title="session.title" /></div>
                            <div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.13em] text-slate-500">Capacity</dt><dd class="mt-2 text-sm font-bold text-slate-950">{{ session.capacity.assigned }}{{ session.capacity.limit ? ` of ${session.capacity.limit}` : '' }} assigned</dd></div>
                        </dl>
                        <div class="px-6 py-5"><p class="text-xs font-bold uppercase tracking-[0.13em] text-slate-500">Applicant instructions</p><p class="mt-2 max-w-5xl whitespace-pre-line text-sm leading-6 text-slate-700">{{ session.instructions || 'No additional instructions.' }}</p></div>
                    </section>

                    <section v-if="activeSection === 'roster'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-users" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Assigned applicants</h2><p class="mt-1 text-sm text-slate-500">Record participation here. Outcomes remain a separate review.</p></div></div><button v-if="canAddApplicants" type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950" :disabled="actionBusy" @click="loadCandidates"><i class="fa-solid fa-user-plus text-[10px]" aria-hidden="true"></i>Add applicants</button></header>
                        <div v-if="showCandidates" class="border-b border-slate-200 bg-slate-50 px-6 py-5">
                            <div class="flex items-end gap-3"><label class="min-w-0 flex-1 text-xs font-bold text-slate-700">Find applicants<input v-model="candidateSearch" type="search" class="mt-2 h-10 w-full border border-slate-300 bg-white px-3 text-sm font-normal outline-none focus:border-slate-950" placeholder="Applicant name or email" @keyup.enter="loadCandidates"></label><button type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" @click="loadCandidates">Search</button><button type="button" class="h-10 bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-50" :disabled="!selectedCandidates.length || actionBusy" @click="assignCandidates">Add selected</button><button type="button" class="h-10 px-3 text-xs font-bold text-slate-500" @click="showCandidates = false">Close</button></div>
                            <div v-if="candidates.length" class="mt-4 grid grid-cols-2 gap-2"><label v-for="candidate in candidates" :key="candidate.application_id" class="flex items-center gap-3 border border-slate-200 bg-white px-3 py-3"><input v-model="selectedCandidates" type="checkbox" :value="candidate.application_id" class="h-4 w-4 border-slate-300 text-slate-950 focus:ring-slate-950"><img v-if="candidate.profile_photo_url" :src="candidate.profile_photo_url" alt="" class="h-9 w-9 border border-slate-200 object-cover"><span v-else class="grid h-9 w-9 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-user text-xs" aria-hidden="true"></i></span><span class="min-w-0"><span class="block truncate text-sm font-bold text-slate-900">{{ candidate.name }}</span><span class="block truncate text-xs text-slate-500">{{ candidate.education || 'Education not provided' }}</span></span></label></div><p v-else class="mt-4 text-sm text-slate-500">No unassigned applicants match this session.</p>
                        </div>

                        <div class="staff-table-viewport">
                            <table class="w-full min-w-[68rem] table-fixed border-collapse text-left"><thead class="bg-slate-50"><tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><th class="w-[38%] px-5 py-3">Applicant</th><th class="w-[19%] px-5 py-3">Assignment</th><th class="w-[23%] px-5 py-3">Attendance</th><th class="w-[20%] px-5 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200">
                                <tr v-if="!activeAssignments.length"><td colspan="4" class="px-5 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span><p class="mt-3 font-semibold text-slate-800">No applicants assigned.</p><p class="mt-1 text-sm text-slate-500">Add applicants before publishing the session.</p></td></tr>
                                <tr v-for="assignment in activeAssignments" :key="assignment.id" class="align-middle hover:bg-slate-50/70"><td class="px-5 py-4"><div class="flex items-center gap-3"><img v-if="assignment.application?.applicant?.profile_photo_url" :src="assignment.application.applicant.profile_photo_url" alt="" class="h-11 w-11 border border-slate-200 object-cover"><span v-else class="grid h-11 w-11 place-items-center bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i></span><p class="text-sm font-bold text-slate-950">{{ assignment.application?.applicant?.name }}</p></div></td><td class="px-5 py-4"><p class="text-sm font-semibold text-slate-800">{{ labelize(assignment.status) }}</p><p class="mt-1 text-xs text-slate-500">{{ formatDate(assignment.assigned_at) }}</p></td><td class="px-5 py-4"><select v-if="canRecordAttendance" :value="assignment.attendance?.status || 'pending'" class="h-9 w-44 border border-slate-300 bg-white px-2 text-xs font-bold text-slate-700" :disabled="actionBusy" @change="saveAttendance(assignment, $event.target.value)"><option value="pending" disabled>Pending</option><option value="present">Present</option><option value="absent">Absent</option><option value="excused">Excused</option><option value="not_required">Not required</option></select><span v-else class="text-sm font-semibold text-slate-700">{{ labelize(assignment.attendance?.status || 'pending') }}</span></td><td class="px-5 py-4 text-right"><div class="flex justify-end gap-2"><button v-if="session.status !== 'cancelled'" type="button" class="inline-flex h-9 items-center border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:border-slate-950" :disabled="actionBusy" @click="openMoveDialog(assignment)">Move</button><a v-if="session.status === 'completed'" :href="assignment.application?.result_url" class="inline-flex h-9 items-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800">Review <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a></div></td></tr>
                            </tbody></table>
                        </div>
                    </section>

                    <section v-if="activeSection === 'proof'" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center gap-3 border-b border-slate-200 px-6 py-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Session proof</h2><p class="mt-1 text-sm text-slate-500">Upload one shared record for this session.</p></div></header>
                        <form v-if="session.status !== 'cancelled'" class="grid grid-cols-[13rem_12rem_1fr_auto] items-end gap-3 border-b border-slate-200 bg-slate-50 px-6 py-5" @submit.prevent="uploadProof"><label class="text-xs font-bold text-slate-700">Type<select v-model="proofForm.category" class="mt-2 h-10 w-full border border-slate-300 bg-white px-2 text-sm font-normal"><option value="attendance_sheet">Attendance sheet</option><option value="score_sheet">Score sheet</option><option value="evaluation">Evaluation</option><option value="verification_record">Verification record</option><option value="decision_record">Decision record</option><option value="signed_acknowledgment">Signed acknowledgment</option><option value="other">Other</option></select></label><label class="text-xs font-bold text-slate-700">Event date<input v-model="proofForm.event_date" type="date" class="mt-2 h-10 w-full border border-slate-300 bg-white px-2 text-sm font-normal"></label><label class="text-xs font-bold text-slate-700">Proof file<input id="session-proof-file" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.csv,.xls,.xlsx" class="mt-2 block h-10 w-full border border-slate-300 bg-white text-sm file:mr-3 file:h-full file:border-0 file:border-r file:border-slate-300 file:bg-slate-100 file:px-3 file:text-xs file:font-bold" @change="proofForm.evidence_file = $event.target.files?.[0] || null"></label><button type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-60" :disabled="actionBusy"><i class="fa-solid fa-cloud-arrow-up text-[10px]" aria-hidden="true"></i>Upload</button></form>
                        <div class="staff-table-viewport"><table class="w-full min-w-[58rem] table-fixed border-collapse text-left"><thead class="bg-slate-50"><tr class="border-b border-slate-200 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500"><th class="w-[38%] px-5 py-3">File</th><th class="w-[22%] px-5 py-3">Type</th><th class="w-[25%] px-5 py-3">Uploaded</th><th class="w-[15%] px-5 py-3 text-right">Preview</th></tr></thead><tbody class="divide-y divide-slate-200"><tr v-if="!currentEvidence.length"><td colspan="4" class="px-5 py-12 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i></span><p class="mt-3 font-semibold text-slate-800">No session proof uploaded.</p><p class="mt-1 text-sm text-slate-500">{{ session.proof_required ? 'Proof is required before this session can be completed.' : 'Proof is optional for this session.' }}</p></td></tr><tr v-for="evidence in currentEvidence" :key="evidence.id"><td class="px-5 py-4"><p class="truncate text-sm font-bold text-slate-950" data-file-name>{{ evidence.original_name }}</p><p v-if="evidence.note" class="mt-1 truncate text-xs text-slate-500">{{ evidence.note }}</p></td><td class="px-5 py-4 text-sm font-semibold text-slate-700">{{ labelize(evidence.category) }}</td><td class="px-5 py-4 text-sm text-slate-600">{{ formatDate(evidence.uploaded_at) }}</td><td class="px-5 py-4 text-right"><a :href="evidence.view_url" data-file-preview="true" :data-file-name="evidence.original_name" class="inline-flex h-9 w-9 items-center justify-center border border-slate-300 text-slate-700 hover:border-slate-950 hover:bg-slate-950 hover:text-white" aria-label="Preview proof"><i class="fa-solid fa-eye text-xs" aria-hidden="true"></i></a></td></tr></tbody></table></div>
                    </section>
                </template>
            </div>
        </section>

        <div v-if="showActionDialog" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6" role="dialog" aria-modal="true" aria-labelledby="session-action-title" @click.self="showActionDialog = false"><div class="w-full max-w-lg border border-slate-200 bg-white shadow-2xl"><header class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Confirm action</p><h2 id="session-action-title" class="mt-1 text-xl font-bold text-slate-950">{{ pendingAction === 'complete' ? 'Complete this session?' : 'Cancel this session?' }}</h2></header><div class="px-6 py-5"><p class="text-sm leading-6 text-slate-600">{{ pendingAction === 'complete' ? 'Applicants will move to the outcome review queue. Their results will not be recorded automatically.' : 'Assigned applicants will return to the unassigned queue.' }}</p><label v-if="pendingAction === 'cancel'" class="mt-4 block text-sm font-semibold text-slate-700">Reason<textarea v-model="cancelReason" rows="3" maxlength="1500" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal outline-none focus:border-slate-950"></textarea></label></div><footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" @click="showActionDialog = false">Keep session</button><button type="button" :class="['h-10 px-4 text-xs font-bold text-white disabled:opacity-60', pendingAction === 'cancel' ? 'bg-rose-700' : 'bg-slate-950']" :disabled="actionBusy" @click="confirmSessionAction">{{ actionBusy ? 'Saving...' : pendingAction === 'complete' ? 'Complete session' : 'Cancel session' }}</button></footer></div></div>

        <div v-if="showMoveDialog" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6" role="dialog" aria-modal="true" aria-labelledby="move-applicant-title" @click.self="showMoveDialog = false"><form class="w-full max-w-lg border border-slate-200 bg-white shadow-2xl" @submit.prevent="moveAssignment"><header class="border-b border-slate-200 px-6 py-5"><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Change session</p><h2 id="move-applicant-title" class="mt-1 text-xl font-bold text-slate-950">Move {{ movingAssignment?.application?.applicant?.name }}</h2></header><div class="px-6 py-5"><label class="block text-sm font-semibold text-slate-700">Replacement session<select v-model="targetSessionId" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950"><option value="" disabled>Select another date or location</option><option v-for="item in replacementSessions" :key="item.id" :value="String(item.id)">{{ item.title }} · {{ formatDate(item.starts_at) }}</option></select></label><p v-if="!replacementSessions.length" class="mt-3 border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">Create another {{ labelize(session.stage_key).toLowerCase() }} session before moving this applicant.</p><label class="mt-4 block text-sm font-semibold text-slate-700">Reason <span class="font-normal text-slate-400">(optional)</span><textarea v-model="moveNotes" rows="3" maxlength="1500" class="mt-2 w-full border border-slate-300 px-3 py-2 font-normal outline-none focus:border-slate-950"></textarea></label></div><footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700" @click="showMoveDialog = false">Cancel</button><button type="submit" class="h-10 bg-slate-950 px-4 text-xs font-bold text-white disabled:opacity-60" :disabled="!targetSessionId || actionBusy">{{ actionBusy ? 'Moving...' : 'Move applicant' }}</button></footer></form></div>
    </main>
</template>
