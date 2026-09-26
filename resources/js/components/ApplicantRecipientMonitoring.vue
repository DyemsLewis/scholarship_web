<script setup>
import { computed, ref } from 'vue';
import FilePreviewModal from './FilePreviewModal.vue';
import { showPortalToast } from '../support/portalToast';

const props = defineProps({
    monitoring: { type: Object, required: true },
    applicationId: { type: [Number, String], required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['application-updated']);
const fileInput = ref(null);
const activeCycle = ref(null);
const uploadingCycleId = ref(null);
const savingManualCycleId = ref(null);
const acceptedTerms = ref({});
const manualForms = ref({});
const previewFile = ref(null);
const activePanel = ref('academic');
const monitoringTabs = computed(() => [
    {
        key: 'academic',
        label: 'Academic updates',
        icon: 'fa-solid fa-chart-line',
        count: props.monitoring.cycles?.length ?? 0,
    },
    {
        key: 'releases',
        label: 'Benefit releases',
        icon: 'fa-solid fa-hand-holding-heart',
        count: props.monitoring.benefit_releases?.length ?? 0,
    },
    {
        key: 'status',
        label: 'Support status',
        icon: 'fa-solid fa-flag-checkered',
        count: props.monitoring.support_decisions?.length ?? 0,
    },
]);

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
        showPortalToast({
            type: 'error',
            title: 'Confirmation required',
            message: 'Confirm that this grade record is yours and matches the listed academic period.',
        });
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
        const response = await window.axios.post(
            `/dashboard/applications/${props.applicationId}/monitoring-cycles/${cycle.id}/grade-record`,
            data,
        );
        emit('application-updated', response.data.application);
        showPortalToast({ type: 'success', title: 'Grade record saved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Upload not completed',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to upload this grade record.'),
        });
    } finally {
        uploadingCycleId.value = null;
        activeCycle.value = null;
    }
}

function manualForm(cycle) {
    if (!manualForms.value[cycle.id]) {
        manualForms.value[cycle.id] = {
            grade: cycle.submission?.grade ?? '',
            grading_scale: cycle.submission?.grading_scale ?? cycle.grading_scale ?? 'percentage',
        };
    }

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
        const response = await window.axios.patch(
            `/dashboard/applications/${props.applicationId}/monitoring-cycles/${cycle.id}/manual-grade`,
            form,
        );
        emit('application-updated', response.data.application);
        showPortalToast({ type: 'success', title: 'Result saved', message: response.data.message });
    } catch (error) {
        const errors = error.response?.data?.errors;
        showPortalToast({
            type: 'error',
            title: 'Result not saved',
            message: errors ? Object.values(errors).flat()[0] : (error.response?.data?.message ?? 'Unable to save this result.'),
        });
    } finally {
        savingManualCycleId.value = null;
    }
}
</script>

<template>
    <FilePreviewModal
        :file="previewFile"
        :title="previewFile?.original_name || 'Academic progress record'"
        :context="programTitle"
        @close="previewFile = null"
    />

    <section class="overflow-hidden rounded-md border border-slate-800 bg-white shadow-[0_12px_28px_rgba(8,20,38,0.09)]">
        <header class="flex flex-col gap-4 bg-slate-950 p-4 text-white sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded bg-amber-400 text-slate-950"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-300">Recipient monitoring</p>
                    <h3 class="mt-1 text-lg font-bold text-white">Your scholarship support</h3>
                </div>
            </div>
            <div class="shrink-0 sm:text-right">
                <p class="text-[9px] font-bold uppercase tracking-[0.14em] text-slate-400">Current support status</p>
                <span :class="['mt-1.5 inline-flex w-fit rounded border px-2.5 py-1 text-xs font-bold', supportStatusClass(monitoring.support_status)]">{{ monitoring.support_status_label }}</span>
            </div>
        </header>

        <div :class="['flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5', monitoring.pending_count ? 'bg-amber-50' : 'bg-slate-50']">
            <div class="flex min-w-0 items-start gap-3">
                <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded', monitoring.pending_count ? 'bg-amber-100 text-amber-800' : 'bg-slate-950 text-amber-300']">
                    <i :class="monitoring.pending_count ? 'fa-solid fa-arrow-up-from-bracket' : 'fa-solid fa-circle-check'" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-950">{{ monitoring.pending_count ? `${monitoring.pending_count} grade ${monitoring.pending_count === 1 ? 'record is' : 'records are'} due` : 'No upload is due' }}</p>
                    <p class="mt-0.5 text-xs text-slate-600">{{ monitoring.pending_count ? 'Open Academic updates to submit.' : 'Check releases or support status when needed.' }}</p>
                </div>
            </div>
            <button v-if="monitoring.pending_count && activePanel !== 'academic'" type="button" class="inline-flex shrink-0 items-center justify-center gap-2 rounded bg-slate-950 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800" @click="activePanel = 'academic'">
                Open academic updates
                <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
            </button>
        </div>

        <nav class="grid grid-cols-3 gap-px border-y border-slate-200 bg-slate-200" aria-label="Recipient monitoring sections">
            <button
                v-for="tab in monitoringTabs"
                :key="tab.key"
                type="button"
                :class="['relative min-w-0 bg-white px-2 py-3 text-left transition sm:px-4', activePanel === tab.key ? 'text-slate-950 shadow-[inset_0_-3px_0_#fbbf24]' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900']"
                @click="activePanel = tab.key"
            >
                <span class="flex items-center justify-center gap-2 sm:justify-start">
                    <i :class="[tab.icon, 'text-xs', activePanel === tab.key ? 'text-amber-600' : 'text-slate-400']" aria-hidden="true"></i>
                    <span class="truncate text-xs font-bold sm:text-sm">{{ tab.label }}</span>
                    <span :class="['rounded px-1.5 py-0.5 text-[10px] font-bold', activePanel === tab.key ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-500']">{{ tab.count }}</span>
                </span>
            </button>
        </nav>

        <input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadGradeRecord">

        <div v-if="activePanel === 'academic'">
            <div v-if="!monitoring.cycles?.length" class="px-5 py-8 text-center">
            <span class="mx-auto grid h-11 w-11 place-items-center rounded bg-slate-100 text-slate-500"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i></span>
            <p class="mt-3 font-bold text-slate-950">No grade record requested</p>
            <p class="mt-1 text-sm text-slate-500">New requests will appear here.</p>
            </div>

            <div v-else class="divide-y divide-slate-200">
                <details v-for="cycle in monitoring.cycles" :key="cycle.id" :open="cycle.can_submit || cycle.correction_requested" class="group">
                    <summary class="flex cursor-pointer list-none flex-col gap-3 px-4 py-4 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between sm:px-5 [&::-webkit-details-marker]:hidden">
                        <div class="flex min-w-0 items-start gap-3">
                            <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded', cycle.can_submit ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600']"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2"><h5 class="font-bold text-slate-950">{{ cycle.title }}</h5><span :class="['rounded px-2 py-1 text-[10px] font-bold uppercase', statusClass(cycle.status)]">{{ statusLabel(cycle.status) }}</span></div>
                                <p class="mt-1 text-xs text-slate-500">{{ cycle.academic_period || 'Academic progress period' }}<span v-if="cycle.school_year"> · {{ cycle.school_year }}</span></p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-4 pl-12 sm:pl-0">
                            <div class="sm:text-right"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400">Submit by</p><p class="mt-0.5 text-sm font-bold text-slate-800">{{ cycle.due_label }}</p></div>
                            <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                        </div>
                    </summary>

                    <div class="border-t border-slate-200 bg-slate-50/60">
                        <dl class="grid gap-px bg-slate-200 sm:grid-cols-2">
                            <div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Required result</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ cycle.requirement_label }}</dd></div>
                            <div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Accepted files</dt><dd class="mt-1 text-sm font-bold text-slate-950">PDF, JPG, JPEG, or PNG</dd></div>
                        </dl>
                        <p v-if="cycle.instructions" class="border-t border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-600 sm:px-5"><strong class="text-slate-900">Provider instruction:</strong> {{ cycle.instructions }}</p>

                        <div v-if="cycle.submission" class="border-t border-slate-200 p-4 sm:p-5">
                    <div class="flex flex-col gap-3 rounded border border-slate-200 bg-slate-50 px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-950">{{ cycle.submission.original_name }}</p><p class="mt-0.5 text-xs text-slate-500">Submitted {{ cycle.submission.submitted_at }}</p></div>
                        <div class="flex shrink-0 flex-wrap gap-2"><button type="button" class="rounded border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = cycle.submission">View record</button><button v-if="cycle.can_submit" type="button" :disabled="uploadingCycleId === cycle.id" :class="['rounded px-3 py-2 text-xs font-bold disabled:opacity-60', cycle.correction_requested ? 'bg-slate-950 text-white hover:bg-slate-800' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50']" @click="openFilePicker(cycle)">{{ cycle.correction_requested ? 'Upload replacement' : 'Replace file' }}</button></div>
                    </div>

                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        <div :class="['flex items-start gap-3 rounded border px-3 py-3', reviewClass(cycle.submission.review_status)]">
                            <i :class="[reviewIcon(cycle.submission.review_status), 'mt-0.5 shrink-0']" aria-hidden="true"></i>
                            <div class="min-w-0"><p class="text-xs font-bold uppercase tracking-[0.1em] opacity-75">Provider review</p><p class="mt-1 text-sm font-bold">{{ cycle.submission.review_status_label }}</p><p v-if="cycle.submission.review_notes" class="mt-1 text-xs leading-5">{{ cycle.submission.review_notes }}</p><p v-else-if="cycle.submission.review_status === 'pending'" class="mt-1 text-xs leading-5">Waiting for provider review.</p><p v-if="cycle.submission.reviewed_at" class="mt-1 text-[11px] opacity-70">Reviewed {{ cycle.submission.reviewed_at }}<span v-if="cycle.submission.reviewed_by"> by {{ cycle.submission.reviewed_by }}</span></p></div>
                        </div>
                        <div v-if="cycle.submission.grade_label" :class="['rounded border px-3 py-3', comparisonClass(cycle.submission.comparison?.status)]">
                            <p class="text-[10px] font-bold uppercase tracking-[0.1em] opacity-75">{{ cycle.submission.grade_source === 'ocr' ? 'Extracted result' : 'Applicant-entered result' }}</p>
                            <div class="mt-1 flex flex-wrap items-end justify-between gap-2"><p class="text-lg font-bold">{{ cycle.submission.grade_label }}</p><p class="text-xs font-bold uppercase">{{ cycle.submission.comparison?.status === 'pass' ? 'Meets listed requirement' : cycle.submission.comparison?.status === 'fail' ? 'Provider review needed' : 'Manual review' }}</p></div>
                        </div>
                        <div v-else class="rounded border border-amber-200 bg-amber-50 px-3 py-3 text-sm leading-6 text-amber-900"><p class="font-bold">The final result was not extracted</p><p class="mt-1 text-xs">{{ cycle.submission.ocr_message }}</p></div>
                    </div>

                    <form v-if="cycle.submission.manual_entry_allowed && cycle.can_submit" class="mt-3 rounded border border-slate-200 bg-white p-3" @submit.prevent="saveManualGrade(cycle)">
                        <p class="text-sm font-bold text-slate-950">Enter the result shown on the record</p>
                        <p class="mt-1 text-xs text-slate-500">Use this when scanning cannot read the result.</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                            <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Grading scale</span><select v-model="manualForm(cycle).grading_scale" class="w-full rounded border border-slate-300 px-3 py-2.5 text-sm"><option value="percentage">Percentage</option><option value="grade_point">GWA / GPA grade point</option></select></label>
                            <label><span class="mb-1.5 block text-xs font-bold text-slate-700">Final result</span><input v-model="manualForm(cycle).grade" type="number" step="0.01" required class="w-full rounded border border-slate-300 px-3 py-2.5 text-sm"></label>
                            <button type="submit" :disabled="savingManualCycleId === cycle.id" class="rounded bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ savingManualCycleId === cycle.id ? 'Saving...' : 'Save result' }}</button>
                        </div>
                    </form>
                        </div>

                        <div v-else-if="cycle.can_submit" class="border-t border-slate-200 p-4 sm:p-5">
                            <div class="rounded border border-slate-200 bg-white p-3">
                        <label class="flex cursor-pointer items-start gap-3 text-sm leading-5 text-slate-700"><input v-model="acceptedTerms[cycle.id]" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-slate-950"><span>I confirm this is my grade record for this period.</span></label>
                        <button type="button" :disabled="uploadingCycleId === cycle.id" class="mt-3 inline-flex items-center gap-2 rounded bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60" @click="openFilePicker(cycle)"><i class="fa-solid fa-arrow-up-from-bracket text-xs"></i>{{ uploadingCycleId === cycle.id ? 'Scanning...' : 'Upload grade record' }}</button>
                            </div>
                        </div>
                        <p v-else-if="!cycle.submission" class="border-t border-slate-200 px-4 py-3 text-sm font-semibold text-slate-600 sm:px-5">{{ cycle.locked_reason }}</p>
                    </div>
                </details>
            </div>
        </div>

        <div v-else-if="activePanel === 'releases'">
            <div v-if="!monitoring.benefit_releases?.length" class="px-5 py-8 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded bg-slate-100 text-slate-500"><i class="fa-solid fa-gift" aria-hidden="true"></i></span><p class="mt-3 font-bold text-slate-950">No release scheduled</p><p class="mt-1 text-sm text-slate-500">Release details will appear here.</p></div>
            <div v-else class="divide-y divide-slate-200">
                <details v-for="release in monitoring.benefit_releases" :key="release.record_id" :open="release.status === 'scheduled' || release.status === 'prepared'" class="group">
                    <summary class="flex cursor-pointer list-none flex-col gap-3 px-4 py-4 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between sm:px-5 [&::-webkit-details-marker]:hidden">
                        <div class="flex min-w-0 items-start gap-3"><span :class="['grid h-9 w-9 shrink-0 place-items-center rounded border', releaseStatusClass(release.status)]"><i :class="releaseStatusIcon(release.status)" aria-hidden="true"></i></span><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h5 class="font-bold text-slate-950">{{ release.title }}</h5><span :class="['rounded border px-2 py-1 text-[10px] font-bold uppercase', releaseStatusClass(release.status)]">{{ release.status_label }}</span></div><p class="mt-1 text-xs text-slate-500">{{ release.benefit_description }}<span v-if="release.amount_label"> · {{ release.amount_label }}</span></p></div></div>
                        <div class="flex items-center justify-between gap-4 pl-12 sm:pl-0"><div class="sm:text-right"><p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400">Release schedule</p><p class="mt-0.5 text-sm font-bold text-slate-800">{{ release.release_label }}</p></div><i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></div>
                    </summary>
                    <div class="border-t border-slate-200 bg-slate-50/60">
                        <dl class="grid gap-px bg-slate-200 sm:grid-cols-3"><div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Method</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.release_method_label }}</dd></div><div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Location</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.location || 'Provider-coordinated' }}</dd></div><div class="bg-white px-4 py-3"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Original records</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ release.requires_original_verification ? (release.originals_verified ? 'Verified' : 'Bring for verification') : 'Not required at release' }}</dd></div></dl>
                        <p v-if="release.instructions" class="border-t border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-600 sm:px-5"><strong class="text-slate-900">Provider instruction:</strong> {{ release.instructions }}</p>
                        <div class="border-t border-slate-200 p-4 sm:p-5"><div :class="['flex flex-col gap-3 rounded border px-3 py-3 sm:flex-row sm:items-start sm:justify-between', releaseStatusClass(release.status)]"><div class="flex min-w-0 items-start gap-3"><i :class="[releaseStatusIcon(release.status), 'mt-0.5 shrink-0']" aria-hidden="true"></i><div><p class="text-sm font-bold">{{ release.status_label }}</p><p v-if="release.notes" class="mt-1 text-xs leading-5">{{ release.notes }}</p><p v-else-if="release.status === 'scheduled'" class="mt-1 text-xs leading-5">Follow the schedule above.</p><p v-if="release.recorded_at" class="mt-1 text-[11px] opacity-70">Updated {{ release.recorded_at }}<span v-if="release.recorded_by"> by {{ release.recorded_by }}</span></p></div></div><button v-if="release.receipt" type="button" class="shrink-0 rounded border border-current/20 bg-white/70 px-3 py-2 text-xs font-bold" @click="previewFile = release.receipt">View receipt</button></div></div>
                    </div>
                </details>
            </div>
        </div>

        <div v-else>
            <div v-if="!monitoring.support_decisions?.length" class="px-5 py-8 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded bg-slate-100 text-slate-500"><i class="fa-regular fa-clock" aria-hidden="true"></i></span><p class="mt-3 font-bold text-slate-950">No status update</p><p class="mt-1 text-sm text-slate-500">Your current support status remains unchanged.</p></div>
            <div v-else class="divide-y divide-slate-200">
                <article v-for="decision in monitoring.support_decisions" :key="decision.id" class="px-4 py-4 sm:px-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="flex min-w-0 items-start gap-3"><span :class="['grid h-9 w-9 shrink-0 place-items-center rounded border', supportStatusClass(decision.decision)]"><i :class="supportStatusIcon(decision.decision)" aria-hidden="true"></i></span><div><p class="font-bold text-slate-950">{{ decision.decision_label }}</p><p class="mt-1 text-xs text-slate-500">Recorded {{ decision.decided_at }}<span v-if="decision.decided_by"> by {{ decision.decided_by }}</span></p></div></div>
                        <p class="shrink-0 text-xs font-bold text-slate-700">Effective {{ decision.effective_label }}</p>
                    </div>
                    <dl v-if="decision.support_ends_label || decision.next_review_label" class="mt-3 grid overflow-hidden rounded border border-slate-200 bg-slate-200 sm:grid-cols-2"><div v-if="decision.support_ends_label" class="bg-slate-50 px-3 py-2.5"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Support through</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ decision.support_ends_label }}</dd></div><div v-if="decision.next_review_label" class="bg-slate-50 px-3 py-2.5"><dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Next review</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ decision.next_review_label }}</dd></div></dl>
                    <div v-if="decision.next_period_terms || decision.reason" class="mt-3 border-l-2 border-amber-400 pl-3 text-sm leading-6 text-slate-600"><p v-if="decision.next_period_terms"><strong class="text-slate-900">Next-period terms:</strong> {{ decision.next_period_terms }}</p><p v-if="decision.reason" :class="decision.next_period_terms ? 'mt-1' : ''"><strong class="text-slate-900">Provider note:</strong> {{ decision.reason }}</p></div>
                </article>
            </div>
        </div>
    </section>
</template>
