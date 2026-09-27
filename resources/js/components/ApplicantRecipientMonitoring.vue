<script setup>
import { computed, ref } from 'vue';
import FilePreviewModal from './FilePreviewModal.vue';
import { showPortalToast } from '../support/portalToast';

const props = defineProps({
    monitoring: { type: Object, required: true },
    applicationId: { type: [Number, String], required: true },
    programTitle: { type: String, default: 'Scholarship program' },
    showHeader: { type: Boolean, default: true },
});
const emit = defineEmits(['application-updated']);
const fileInput = ref(null);
const activeCycle = ref(null);
const uploadingCycleId = ref(null);
const savingManualCycleId = ref(null);
const acceptedTerms = ref({});
const manualForms = ref({});
const previewFile = ref(null);
const activePanel = ref('requirements');

const cycles = computed(() => props.monitoring.cycles ?? []);
const releases = computed(() => props.monitoring.benefit_releases ?? []);
const decisions = computed(() => props.monitoring.support_decisions ?? []);
const actionCycles = computed(() => cycles.value.filter((cycle) => cycle.correction_requested || (cycle.can_submit && !cycle.submission)));
const submittedCycles = computed(() => cycles.value.filter((cycle) => cycle.submission && !cycle.correction_requested));
const laterCycles = computed(() => cycles.value.filter((cycle) => !cycle.submission && !actionCycles.value.some((item) => item.id === cycle.id)));
const activeReleases = computed(() => releases.value.filter((release) => ['scheduled', 'prepared'].includes(release.status)));
const releaseHistory = computed(() => releases.value.filter((release) => !['scheduled', 'prepared'].includes(release.status)));
const latestDecision = computed(() => decisions.value[0] ?? null);
const previousDecisions = computed(() => decisions.value.slice(1));
const monitoringTabs = [
    { key: 'requirements', label: 'Requirements', icon: 'fa-solid fa-graduation-cap' },
    { key: 'benefits', label: 'Benefit releases', icon: 'fa-solid fa-hand-holding-heart' },
    { key: 'status', label: 'Support history', icon: 'fa-solid fa-clock-rotate-left' },
];

const nextStep = computed(() => {
    const correction = actionCycles.value.find((cycle) => cycle.correction_requested);
    if (correction) return { panel: 'requirements', icon: 'fa-solid fa-rotate', tone: 'amber', label: 'Action needed', title: `Replace the record for ${correction.title}`, detail: correction.due_label ? `Submit by ${correction.due_label}` : 'Upload the corrected grade record.' };
    const requirement = actionCycles.value[0];
    if (requirement) return { panel: 'requirements', icon: 'fa-solid fa-arrow-up-from-bracket', tone: 'amber', label: 'Next requirement', title: `Upload ${requirement.title}`, detail: requirement.due_label ? `Submit by ${requirement.due_label}` : 'Submit your grade record.' };
    const pending = submittedCycles.value.find((cycle) => cycle.submission?.review_status === 'pending');
    if (pending) return { panel: 'requirements', icon: 'fa-regular fa-clock', tone: 'slate', label: 'Waiting for review', title: `${pending.title} was submitted`, detail: 'The provider will post the result here.' };
    const release = activeReleases.value[0];
    if (release) return { panel: 'benefits', icon: releaseStatusIcon(release.status), tone: 'sky', label: release.status === 'prepared' ? 'Benefit ready' : 'Upcoming release', title: release.title, detail: release.release_label || 'Open the release details for instructions.' };
    return { panel: 'requirements', icon: 'fa-solid fa-circle-check', tone: 'emerald', label: 'No action needed', title: 'Your monitoring record is up to date', detail: 'New requirements and release schedules will appear here.' };
});

function nextStepClass(tone) {
    if (tone === 'amber') return 'border-amber-200 bg-amber-50 text-amber-900';
    if (tone === 'sky') return 'border-sky-200 bg-sky-50 text-sky-900';
    if (tone === 'emerald') return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    return 'border-slate-200 bg-slate-50 text-slate-800';
}

function statusClass(status) {
    if (status === 'open') return 'bg-emerald-100 text-emerald-800';
    if (status === 'upcoming') return 'bg-sky-100 text-sky-800';
    if (status === 'action_needed') return 'bg-amber-100 text-amber-800';
    return 'bg-slate-100 text-slate-600';
}

function statusLabel(status) {
    if (status === 'action_needed') return 'Action needed';
    if (status === 'open') return 'Open';
    if (status === 'upcoming') return 'Upcoming';
    return String(status || 'Not started').replaceAll('_', ' ');
}

function comparisonClass(status) {
    if (status === 'pass') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (status === 'fail') return 'border-rose-200 bg-rose-50 text-rose-700';
    return 'border-amber-200 bg-amber-50 text-amber-800';
}

function reviewClass(status) {
    if (status === 'met') return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    if (status === 'not_met') return 'border-rose-200 bg-rose-50 text-rose-800';
    if (status === 'needs_correction') return 'border-amber-200 bg-amber-50 text-amber-900';
    if (status === 'excused') return 'border-sky-200 bg-sky-50 text-sky-900';
    return 'border-slate-200 bg-slate-50 text-slate-700';
}

function reviewIcon(status) {
    if (status === 'met') return 'fa-solid fa-circle-check';
    if (status === 'not_met') return 'fa-solid fa-circle-xmark';
    if (status === 'needs_correction') return 'fa-solid fa-rotate';
    if (status === 'excused') return 'fa-solid fa-shield-heart';
    return 'fa-regular fa-clock';
}

function releaseStatusClass(status) {
    if (status === 'released') return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    if (status === 'prepared') return 'border-sky-200 bg-sky-50 text-sky-900';
    if (status === 'missed' || status === 'withheld') return 'border-rose-200 bg-rose-50 text-rose-800';
    return 'border-amber-200 bg-amber-50 text-amber-900';
}

function releaseStatusIcon(status) {
    if (status === 'released') return 'fa-solid fa-circle-check';
    if (status === 'prepared') return 'fa-solid fa-box-open';
    if (status === 'missed') return 'fa-solid fa-calendar-xmark';
    if (status === 'withheld') return 'fa-solid fa-circle-pause';
    return 'fa-regular fa-calendar-check';
}

function supportStatusClass(status) {
    if (status === 'renewed') return 'border-sky-200 bg-sky-50 text-sky-900';
    if (status === 'completed') return 'border-emerald-200 bg-emerald-50 text-emerald-900';
    if (status === 'terminated') return 'border-rose-200 bg-rose-50 text-rose-800';
    return 'border-amber-200 bg-amber-50 text-amber-900';
}

function supportStatusIcon(status) {
    if (status === 'renewed') return 'fa-solid fa-rotate';
    if (status === 'completed') return 'fa-solid fa-graduation-cap';
    if (status === 'terminated') return 'fa-solid fa-circle-stop';
    return 'fa-solid fa-heart-pulse';
}

function openFilePicker(cycle) {
    if (!cycle.submission && !acceptedTerms.value[cycle.id]) {
        showPortalToast({ type: 'error', title: 'Confirmation required', message: 'Confirm that this grade record is yours and matches the listed academic period.' });
        return;
    }
    activeCycle.value = cycle;
    if (fileInput.value) {
        fileInput.value.value = '';
        fileInput.value.click();
    }
}

async function uploadGradeRecord(event) {
    const file = event.target.files?.[0];
    const cycle = activeCycle.value;
    if (!file || !cycle) return;
    uploadingCycleId.value = cycle.id;
    const data = new FormData();
    data.append('grade_record', file);
    data.append('terms_accepted', '1');
    try {
        const response = await window.axios.post(`/dashboard/applications/${props.applicationId}/monitoring-cycles/${cycle.id}/grade-record`, data);
        emit('application-updated', response.data.application);
        showPortalToast({ type: 'success', title: 'Grade record saved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({ type: 'error', title: 'Upload not completed', message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to upload this grade record.') });
    } finally {
        uploadingCycleId.value = null;
        activeCycle.value = null;
    }
}

function manualForm(cycle) {
    if (!manualForms.value[cycle.id]) manualForms.value[cycle.id] = { grade: cycle.submission?.grade ?? '', grading_scale: cycle.submission?.grading_scale ?? cycle.grading_scale ?? 'percentage' };
    return manualForms.value[cycle.id];
}

async function saveManualGrade(cycle) {
    const form = manualForm(cycle);
    if (!form.grade) {
        showPortalToast({ type: 'error', title: 'Result required', message: 'Enter the final result shown on the uploaded grade record.' });
        return;
    }
    savingManualCycleId.value = cycle.id;
    try {
        const response = await window.axios.patch(`/dashboard/applications/${props.applicationId}/monitoring-cycles/${cycle.id}/manual-grade`, form);
        emit('application-updated', response.data.application);
        showPortalToast({ type: 'success', title: 'Result saved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({ type: 'error', title: 'Result not saved', message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to save this result.') });
    } finally {
        savingManualCycleId.value = null;
    }
}
</script>

<template>
    <FilePreviewModal :file="previewFile" :title="previewFile?.original_name || 'Academic progress record'" :context="programTitle" @close="previewFile = null" />

    <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-[0_6px_18px_rgba(15,23,42,0.06)]">
        <header v-if="showHeader" class="flex flex-col gap-4 border-b border-slate-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Recipient monitoring</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Keep your scholarship on track</h3>
                    <p class="mt-1 text-sm text-slate-500">Submit requirements and check benefit releases.</p>
                </div>
            </div>
            <span :class="['inline-flex w-fit shrink-0 items-center gap-2 rounded-md border px-3 py-2 text-xs font-bold', supportStatusClass(monitoring.support_status)]"><i :class="supportStatusIcon(monitoring.support_status)" aria-hidden="true"></i>{{ monitoring.support_status_label }}</span>
        </header>

        <div class="p-4 sm:p-5">
            <div :class="['flex flex-col gap-3 rounded-md border p-4 sm:flex-row sm:items-center sm:justify-between', nextStepClass(nextStep.tone)]">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white/80"><i :class="nextStep.icon" aria-hidden="true"></i></span>
                    <div class="min-w-0"><p class="text-[10px] font-bold uppercase tracking-[0.14em] opacity-70">{{ nextStep.label }}</p><p class="mt-1 font-bold">{{ nextStep.title }}</p><p class="mt-1 text-xs opacity-80">{{ nextStep.detail }}</p></div>
                </div>
                <button v-if="activePanel !== nextStep.panel" type="button" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-xs font-bold text-white hover:bg-slate-800" @click="activePanel = nextStep.panel">Open<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></button>
            </div>
        </div>

        <nav class="grid grid-cols-3 border-y border-slate-200 bg-slate-50 p-1" aria-label="Recipient monitoring sections">
            <button v-for="tab in monitoringTabs" :key="tab.key" type="button" :class="['flex min-w-0 items-center justify-center gap-2 rounded px-2 py-2.5 text-xs font-bold transition sm:text-sm', activePanel === tab.key ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-white hover:text-slate-950']" @click="activePanel = tab.key"><i :class="[tab.icon, 'text-xs']" aria-hidden="true"></i><span class="truncate">{{ tab.label }}</span></button>
        </nav>

        <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadGradeRecord">

        <div v-if="activePanel === 'requirements'" class="p-4 sm:p-5">
            <div v-if="!cycles.length" class="rounded-md border border-dashed border-slate-300 px-5 py-8 text-center">
                <span class="mx-auto grid h-10 w-10 place-items-center rounded-md bg-slate-100 text-slate-500"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span><p class="mt-3 font-bold text-slate-950">No grade record requested</p><p class="mt-1 text-sm text-slate-500">A request will appear here when it is ready.</p>
            </div>

            <div v-else class="space-y-5">
                <section v-if="actionCycles.length">
                    <div class="mb-3"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">What you need to do</p><h4 class="mt-1 text-base font-bold text-slate-950">Submit the open requirement</h4></div>
                    <div class="space-y-3">
                        <article v-for="cycle in actionCycles" :key="cycle.id" class="overflow-hidden rounded-md border border-amber-200 bg-white">
                            <div class="flex flex-col gap-3 bg-amber-50 px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
                                <div><div class="flex flex-wrap items-center gap-2"><h5 class="font-bold text-slate-950">{{ cycle.title }}</h5><span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', statusClass(cycle.status)]">{{ statusLabel(cycle.status) }}</span></div><p class="mt-1 text-xs text-slate-600">{{ cycle.academic_period || 'Academic period' }}<span v-if="cycle.school_year"> · {{ cycle.school_year }}</span></p></div>
                                <p class="text-xs font-bold text-slate-800">Due {{ cycle.due_label }}</p>
                            </div>
                            <dl class="grid gap-px border-y border-slate-200 bg-slate-200 sm:grid-cols-2"><div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Required result</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ cycle.requirement_label }}</dd></div><div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Upload</dt><dd class="mt-1 text-sm font-bold text-slate-950">PDF or image</dd></div></dl>
                            <p v-if="cycle.instructions" class="border-b border-slate-200 px-4 py-3 text-sm leading-6 text-slate-600"><strong class="text-slate-900">Provider note:</strong> {{ cycle.instructions }}</p>

                            <div v-if="cycle.submission" class="p-4">
                                <div :class="['flex items-start gap-3 rounded-md border p-3', reviewClass(cycle.submission.review_status)]"><i :class="[reviewIcon(cycle.submission.review_status), 'mt-0.5 shrink-0']" aria-hidden="true"></i><div class="min-w-0 flex-1"><p class="text-sm font-bold">{{ cycle.submission.review_status_label }}</p><p v-if="cycle.submission.review_notes" class="mt-1 text-xs leading-5">{{ cycle.submission.review_notes }}</p></div></div>
                                <div class="mt-3 flex flex-col gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ cycle.submission.original_name }}</p><p class="mt-0.5 text-xs text-slate-500">Submitted {{ cycle.submission.submitted_at }}</p></div><div class="flex shrink-0 flex-wrap gap-2"><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = cycle.submission">View record</button><button type="button" :disabled="uploadingCycleId === cycle.id" class="rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-60" @click="openFilePicker(cycle)">{{ uploadingCycleId === cycle.id ? 'Uploading...' : 'Upload replacement' }}</button></div></div>
                            </div>
                            <div v-else class="p-4">
                                <label class="flex cursor-pointer items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-5 text-slate-700"><input v-model="acceptedTerms[cycle.id]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-950"><span>I confirm this is my grade record for this period.</span></label>
                                <button type="button" :disabled="uploadingCycleId === cycle.id" class="mt-3 inline-flex items-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60" @click="openFilePicker(cycle)"><i class="fa-solid fa-arrow-up-from-bracket text-xs" aria-hidden="true"></i>{{ uploadingCycleId === cycle.id ? 'Uploading...' : 'Upload grade record' }}</button>
                            </div>
                        </article>
                    </div>
                </section>

                <section v-if="submittedCycles.length">
                    <div class="mb-3"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Your records</p><h4 class="mt-1 text-base font-bold text-slate-950">Submitted requirements</h4></div>
                    <div class="divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                        <details v-for="cycle in submittedCycles" :key="cycle.id" :open="cycle.submission.review_status === 'pending'" class="group bg-white">
                            <summary class="flex cursor-pointer list-none flex-col gap-3 px-4 py-4 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between [&::-webkit-details-marker]:hidden">
                                <div class="flex min-w-0 items-start gap-3"><span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md border', reviewClass(cycle.submission.review_status)]"><i :class="reviewIcon(cycle.submission.review_status)" aria-hidden="true"></i></span><div class="min-w-0"><p class="font-bold text-slate-950">{{ cycle.title }}</p><p class="mt-1 text-xs text-slate-500">{{ cycle.submission.review_status_label }} · Submitted {{ cycle.submission.submitted_at }}</p></div></div>
                                <div class="flex items-center justify-between gap-4 pl-12 sm:pl-0"><p v-if="cycle.submission.grade_label" class="text-sm font-bold text-slate-800">{{ cycle.submission.grade_label }}</p><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></div>
                            </summary>
                            <div class="border-t border-slate-200 bg-slate-50 p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ cycle.submission.original_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ cycle.academic_period || 'Academic record' }}<span v-if="cycle.school_year"> · {{ cycle.school_year }}</span></p></div><div class="flex shrink-0 flex-wrap gap-2"><button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = cycle.submission">View record</button><button v-if="cycle.can_submit" type="button" :disabled="uploadingCycleId === cycle.id" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-60" @click="openFilePicker(cycle)">Replace file</button></div></div>
                                <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                    <div :class="['rounded-md border p-3', reviewClass(cycle.submission.review_status)]"><p class="text-[10px] font-bold uppercase tracking-[0.1em] opacity-70">Provider review</p><p class="mt-1 text-sm font-bold">{{ cycle.submission.review_status_label }}</p><p v-if="cycle.submission.review_notes" class="mt-1 text-xs leading-5">{{ cycle.submission.review_notes }}</p><p v-else-if="cycle.submission.review_status === 'pending'" class="mt-1 text-xs">Waiting for the provider.</p></div>
                                    <div v-if="cycle.submission.grade_label" :class="['rounded-md border p-3', comparisonClass(cycle.submission.comparison?.status)]"><p class="text-[10px] font-bold uppercase tracking-[0.1em] opacity-70">Detected grade</p><div class="mt-1 flex items-end justify-between gap-2"><p class="text-lg font-bold">{{ cycle.submission.grade_label }}</p><p class="text-xs font-bold">{{ cycle.submission.comparison?.status === 'pass' ? 'Meets requirement' : cycle.submission.comparison?.status === 'fail' ? 'Needs review' : 'Check needed' }}</p></div></div>
                                    <div v-else class="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900"><p class="text-sm font-bold">Grade could not be read</p><p class="mt-1 text-xs">Enter the grade shown on your record below.</p></div>
                                </div>
                                <form v-if="cycle.submission.manual_entry_allowed && cycle.can_submit" class="mt-3 rounded-md border border-slate-200 bg-white p-3" @submit.prevent="saveManualGrade(cycle)">
                                    <p class="text-sm font-bold text-slate-950">Enter the grade shown on the record</p>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"><label><span class="mb-1.5 block text-xs font-bold text-slate-700">Grading scale</span><select v-model="manualForm(cycle).grading_scale" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950"><option value="percentage">Percentage</option><option value="grade_point">GWA / GPA grade point</option></select></label><label><span class="mb-1.5 block text-xs font-bold text-slate-700">Final grade</span><input v-model="manualForm(cycle).grade" type="number" step="0.01" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950"></label><button type="submit" :disabled="savingManualCycleId === cycle.id" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ savingManualCycleId === cycle.id ? 'Saving...' : 'Save grade' }}</button></div>
                                </form>
                            </div>
                        </details>
                    </div>
                </section>

                <details v-if="laterCycles.length" class="group overflow-hidden rounded-md border border-slate-200 bg-white">
                    <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-4 [&::-webkit-details-marker]:hidden"><div><p class="font-bold text-slate-950">Later requirements</p><p class="mt-1 text-xs text-slate-500">{{ laterCycles.length }} not open for upload</p></div><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary>
                    <div class="divide-y divide-slate-200 border-t border-slate-200"><div v-for="cycle in laterCycles" :key="cycle.id" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><div><p class="text-sm font-bold text-slate-950">{{ cycle.title }}</p><p class="mt-1 text-xs text-slate-500">{{ cycle.requirement_label }}</p></div><div class="sm:text-right"><span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', statusClass(cycle.status)]">{{ statusLabel(cycle.status) }}</span><p class="mt-1 text-xs text-slate-500">{{ cycle.due_label || cycle.locked_reason }}</p></div></div></div>
                </details>
            </div>
        </div>

        <div v-else-if="activePanel === 'benefits'" class="p-4 sm:p-5">
            <section v-if="activeReleases.length">
                <div class="mb-3"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-amber-700">Next release</p><h4 class="mt-1 text-base font-bold text-slate-950">Benefit schedule</h4></div>
                <div class="space-y-3">
                    <article v-for="release in activeReleases" :key="release.record_id" class="overflow-hidden rounded-md border border-slate-200 bg-white">
                        <div class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:justify-between"><div class="flex min-w-0 items-start gap-3"><span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-md border', releaseStatusClass(release.status)]"><i :class="releaseStatusIcon(release.status)" aria-hidden="true"></i></span><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h5 class="font-bold text-slate-950">{{ release.title }}</h5><span :class="['rounded border px-2 py-1 text-[10px] font-bold uppercase', releaseStatusClass(release.status)]">{{ release.status_label }}</span></div><p class="mt-1 text-sm text-slate-600">{{ release.benefit_description }}<span v-if="release.amount_label"> · {{ release.amount_label }}</span></p></div></div><p class="shrink-0 text-sm font-bold text-slate-800">{{ release.release_label }}</p></div>
                        <dl class="grid gap-px border-t border-slate-200 bg-slate-200 sm:grid-cols-3"><div class="bg-slate-50 px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Method</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.release_method_label }}</dd></div><div class="bg-slate-50 px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Location</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.location || 'Provider-coordinated' }}</dd></div><div class="bg-slate-50 px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Original records</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.requires_original_verification ? (release.originals_verified ? 'Verified' : 'Bring to release') : 'Not required' }}</dd></div></dl>
                        <div v-if="release.instructions || release.notes || release.receipt" class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm leading-6 text-slate-600">{{ release.notes || release.instructions }}</p><button v-if="release.receipt" type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = release.receipt">View receipt</button></div>
                    </article>
                </div>
            </section>
            <div v-else class="rounded-md border border-dashed border-slate-300 px-5 py-7 text-center"><span class="mx-auto grid h-10 w-10 place-items-center rounded-md bg-slate-100 text-slate-500"><i class="fa-solid fa-gift" aria-hidden="true"></i></span><p class="mt-3 font-bold text-slate-950">No upcoming release</p><p class="mt-1 text-sm text-slate-500">The provider will post the next schedule here.</p></div>

            <details v-if="releaseHistory.length" class="group mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-4 [&::-webkit-details-marker]:hidden"><div><p class="font-bold text-slate-950">Previous releases</p><p class="mt-1 text-xs text-slate-500">{{ releaseHistory.length }} recorded</p></div><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary>
                <div class="divide-y divide-slate-200 border-t border-slate-200"><div v-for="release in releaseHistory" :key="release.record_id" class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><div class="flex min-w-0 items-start gap-3"><span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md border', releaseStatusClass(release.status)]"><i :class="releaseStatusIcon(release.status)" class="text-xs" aria-hidden="true"></i></span><div><p class="text-sm font-bold text-slate-950">{{ release.title }}</p><p class="mt-1 text-xs text-slate-500">{{ release.status_label }} · {{ release.release_label }}</p></div></div><button v-if="release.receipt" type="button" class="self-start rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 sm:self-auto" @click="previewFile = release.receipt">View receipt</button></div></div>
            </details>
        </div>

        <div v-else class="p-4 sm:p-5">
            <article class="rounded-md border border-slate-200 bg-slate-50 p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div class="flex min-w-0 items-start gap-3"><span :class="['grid h-10 w-10 shrink-0 place-items-center rounded-md border', supportStatusClass(monitoring.support_status)]"><i :class="supportStatusIcon(monitoring.support_status)" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Current status</p><h4 class="mt-1 font-bold text-slate-950">{{ monitoring.support_status_label }}</h4><p class="mt-1 text-sm text-slate-600">{{ latestDecision?.reason || 'Continue following the posted requirements and release schedules.' }}</p></div></div><p v-if="latestDecision?.effective_label" class="shrink-0 text-xs font-bold text-slate-600">Effective {{ latestDecision.effective_label }}</p></div>
                <dl v-if="latestDecision?.support_ends_label || latestDecision?.next_review_label" class="mt-4 grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-2"><div v-if="latestDecision.support_ends_label" class="bg-white px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Support through</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ latestDecision.support_ends_label }}</dd></div><div v-if="latestDecision.next_review_label" class="bg-white px-3 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Next review</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ latestDecision.next_review_label }}</dd></div></dl>
                <p v-if="latestDecision?.next_period_terms" class="mt-3 text-sm leading-6 text-slate-600"><strong class="text-slate-900">Next period:</strong> {{ latestDecision.next_period_terms }}</p>
            </article>

            <details v-if="previousDecisions.length" class="group mt-5 overflow-hidden rounded-md border border-slate-200 bg-white">
                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-4 [&::-webkit-details-marker]:hidden"><div><p class="font-bold text-slate-950">Earlier status changes</p><p class="mt-1 text-xs text-slate-500">{{ previousDecisions.length }} recorded</p></div><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary>
                <div class="divide-y divide-slate-200 border-t border-slate-200"><article v-for="decision in previousDecisions" :key="decision.id" class="px-4 py-3"><div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><p class="text-sm font-bold text-slate-950">{{ decision.decision_label }}</p><p v-if="decision.reason" class="mt-1 text-xs leading-5 text-slate-600">{{ decision.reason }}</p></div><p class="shrink-0 text-xs text-slate-500">{{ decision.decided_at }}</p></div></article></div>
            </details>
        </div>
    </section>
</template>
