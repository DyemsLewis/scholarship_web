<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import DecisionReopenDialog from '../../../components/DecisionReopenDialog.vue';
import { decisionReasonOptions } from '../../../support/applicationDecisionReasons';

const applicationId = document.getElementById('app')?.dataset.applicationId;
const returnTo = new URLSearchParams(window.location.search).get('return_to');
const safeReturnUrl = returnTo?.startsWith('/provider/workspaces/selection') ? returnTo : '/provider/workspaces/selection/results';
const application = ref(null);
const readiness = ref(null);
const isLoading = ref(true);
const actionBusy = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const showReopenDialog = ref(false);
const resultForm = ref({ result: 'passed', decision_reason: '', notes: '' });

const applicant = computed(() => application.value?.applicant ?? {});
const scholarship = computed(() => application.value?.scholarship ?? {});
const workflow = computed(() => application.value?.workflow ?? {});
const decisionReopen = computed(() => application.value?.decision_reopen ?? {});
const currentStage = computed(() => application.value?.workflow_stage ?? workflow.value.current_stage ?? '');
const stageLabel = computed(() => workflow.value.current_stage_label || labelize(currentStage.value));
const isSelectionStage = computed(() => ['formal_application', 'exam', 'interview'].includes(currentStage.value));
const canRecordResult = computed(() => isSelectionStage.value && readiness.value?.can_record_result);
const rejectionReasons = computed(() => {
    const allowed = {
        formal_application: ['formal_application_not_completed', 'procedure_not_followed', 'other'],
        exam: ['failed_exam', 'procedure_not_followed', 'other'],
        interview: ['failed_interview', 'procedure_not_followed', 'other'],
    }[currentStage.value] ?? ['procedure_not_followed', 'other'];
    return decisionReasonOptions.filter((option) => allowed.includes(option.value));
});
const profileSummary = computed(() => [
    ['Education', [applicant.value.education_level, applicant.value.course_or_strand, applicant.value.year_level].filter(Boolean).join(' - ')],
    ['School', applicant.value.school],
    ['Academic result', applicant.value.gwa ? `${applicant.value.gwa} · ${labelize(applicant.value.grading_scale)}` : null],
].filter(([, value]) => value));

function labelize(value) {
    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not provided';
}

function formatDate(value) {
    if (!value) return 'Not recorded';
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value));
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    return errors ? Object.values(errors).flat()[0] : error.response?.data?.message || fallback;
}

async function loadRecord() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/applications/${applicationId}/data`, { params: { workspace: 'selection' } });
        application.value = response.data.application;
        if (['formal_application', 'exam', 'interview'].includes(application.value.workflow_stage)) {
            const readinessResponse = await window.axios.get(`/provider/applications/${applicationId}/stage-readiness`);
            readiness.value = readinessResponse.data.readiness;
        }
    } catch (error) {
        errorMessage.value = firstError(error, 'The outcome record could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

async function recordResult() {
    actionBusy.value = true;
    errorMessage.value = '';
    successMessage.value = '';
    const completedStage = stageLabel.value;
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/stages/${currentStage.value}/result`, {
            result: resultForm.value.result,
            decision_reason: resultForm.value.result === 'not_passed' ? resultForm.value.decision_reason : null,
            notes: resultForm.value.notes || null,
        });
        application.value = response.data.application;
        readiness.value = null;
        successMessage.value = `${completedStage} outcome recorded.`;
    } catch (error) {
        errorMessage.value = firstError(error, 'The applicant outcome could not be recorded.');
    } finally {
        actionBusy.value = false;
    }
}

async function reopenDecision(reason) {
    actionBusy.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.patch(`/provider/applications/${applicationId}/decision/reopen`, { reason });
        application.value = response.data.application;
        showReopenDialog.value = false;
        successMessage.value = response.data.message;
        await loadRecord();
    } catch (error) {
        errorMessage.value = firstError(error, 'The outcome could not be reopened.');
    } finally {
        actionBusy.value = false;
    }
}

onMounted(loadRecord);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />
        <section class="student-page provider-page" aria-labelledby="selection-outcome-title">
            <div class="mx-auto w-full max-w-[94rem]">
                <a :href="safeReturnUrl" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950"><i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>Back to results</a>
                <div v-if="errorMessage" class="mb-4 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                <div v-if="successMessage" class="mb-4 border-l-4 border-emerald-500 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800" role="status">{{ successMessage }}</div>
                <div v-if="isLoading" class="border border-slate-200 bg-white px-6 py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i></span><p class="mt-3 text-sm font-semibold text-slate-500">Loading outcome record...</p></div>

                <template v-else-if="application">
                    <header class="provider-hero task-page-header border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-8"><div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span><div><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Selection outcome</p><h1 id="selection-outcome-title" class="mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Review applicant result</h1><p class="mt-1 text-sm text-slate-600">Confirm the completed activity, then record one outcome.</p></div></div><button v-if="decisionReopen.can_reopen" type="button" class="h-10 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950" @click="showReopenDialog = true"><i class="fa-solid fa-rotate-left mr-2" aria-hidden="true"></i>Reopen {{ decisionReopen.stage_label }}</button></div>
                    </header>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm" aria-labelledby="applicant-summary-title">
                        <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-4">
                            <img v-if="applicant.profile_photo_url" :src="applicant.profile_photo_url" :alt="`${applicant.name} profile`" class="h-14 w-14 shrink-0 border border-slate-200 object-cover"><span v-else class="grid h-14 w-14 shrink-0 place-items-center border border-slate-200 bg-slate-100 text-slate-400"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                            <div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Applicant</p><h2 id="applicant-summary-title" class="mt-1 truncate text-xl font-bold text-slate-950">{{ applicant.name }}</h2><p class="mt-1 truncate text-sm text-slate-500">{{ scholarship.title }} · {{ stageLabel }}</p></div>
                        </div>
                        <dl class="grid grid-cols-3"><div v-for="field in profileSummary" :key="field[0]" class="border-r border-slate-200 px-5 py-4 last:border-r-0"><dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">{{ field[0] }}</dt><dd class="mt-1.5 truncate text-sm font-semibold text-slate-900">{{ field[1] }}</dd></div></dl>
                    </section>

                    <section v-if="readiness" class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <header class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><div class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Completed activity</h2><p class="mt-1 text-sm text-slate-500">{{ readiness.session?.title || readiness.stage_label }}</p></div></div><a v-if="readiness.session" :href="readiness.session.record_url" class="inline-flex h-9 items-center gap-2 border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:border-slate-950">Open session <i class="fa-solid fa-arrow-right text-[9px]" aria-hidden="true"></i></a></header>
                        <dl class="grid grid-cols-4 border-b border-slate-200"><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Session status</dt><dd class="mt-1.5 text-sm font-bold text-slate-950">{{ labelize(readiness.session?.status) }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Attendance</dt><dd class="mt-1.5 text-sm font-bold text-slate-950">{{ labelize(readiness.attendance_status) }}</dd></div><div class="border-r border-slate-200 px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Proof</dt><dd class="mt-1.5 text-sm font-bold text-slate-950">{{ readiness.proof_count }} file{{ readiness.proof_count === 1 ? '' : 's' }}</dd></div><div class="px-5 py-4"><dt class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Completed</dt><dd class="mt-1.5 text-sm font-bold text-slate-950">{{ formatDate(readiness.session?.completed_at) }}</dd></div></dl>

                        <form v-if="canRecordResult" class="px-6 py-6" @submit.prevent="recordResult">
                            <div class="flex w-full items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Record outcome</h2><p class="mt-1 text-sm text-slate-500">This advances or closes the applicant record.</p></div></div>
                            <div class="mt-5 grid grid-cols-2 gap-3"><label :class="['cursor-pointer border px-4 py-4', resultForm.result === 'passed' ? 'border-slate-950 bg-slate-50' : 'border-slate-200']"><input v-model="resultForm.result" type="radio" value="passed" class="mr-3 text-slate-950 focus:ring-slate-950"><span class="text-sm font-bold text-slate-950">Passed</span><span class="ml-2 text-xs text-slate-500">Continue to the next stage</span></label><label :class="['cursor-pointer border px-4 py-4', resultForm.result === 'not_passed' ? 'border-slate-950 bg-slate-50' : 'border-slate-200']"><input v-model="resultForm.result" type="radio" value="not_passed" class="mr-3 text-slate-950 focus:ring-slate-950"><span class="text-sm font-bold text-slate-950">Not passed</span><span class="ml-2 text-xs text-slate-500">Close this application</span></label></div>
                            <label v-if="resultForm.result === 'not_passed'" class="mt-4 block text-sm font-semibold text-slate-700">Reason<select v-model="resultForm.decision_reason" required class="mt-2 h-11 w-full border border-slate-300 bg-white px-3 font-normal outline-none focus:border-slate-950"><option value="" disabled>Select a reason</option><option v-for="option in rejectionReasons" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                            <label class="mt-4 block text-sm font-semibold text-slate-700">Internal note <span class="font-normal text-slate-400">(optional)</span><textarea v-model="resultForm.notes" rows="3" maxlength="1500" class="mt-2 w-full border border-slate-300 px-3 py-3 font-normal outline-none focus:border-slate-950" placeholder="Add only information needed to explain this outcome."></textarea></label>
                            <div class="mt-5 border-t border-slate-200 pt-5"><button type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-xs font-bold text-white hover:bg-slate-800 disabled:opacity-60" :disabled="actionBusy"><i :class="['fa-solid', actionBusy ? 'fa-spinner fa-spin' : 'fa-check']" aria-hidden="true"></i>{{ actionBusy ? 'Saving...' : 'Record outcome' }}</button></div>
                        </form>
                        <div v-else class="px-6 py-8 text-center"><p class="font-semibold text-slate-900">This activity is not ready for an outcome.</p><p class="mt-1 text-sm text-slate-500">Complete attendance and required proof in the session record first.</p></div>
                    </section>

                    <section v-else class="mt-4 border border-slate-200 bg-white px-6 py-10 text-center shadow-sm"><i class="fa-solid fa-check-circle text-3xl text-emerald-600" aria-hidden="true"></i><h2 class="mt-3 text-lg font-bold text-slate-950">This selection outcome is already recorded</h2><p class="mt-1 text-sm text-slate-500">The application is now at {{ stageLabel.toLowerCase() }}.</p><a :href="safeReturnUrl" class="mt-5 inline-flex h-10 items-center bg-slate-950 px-4 text-xs font-bold text-white">Return to results</a></section>
                </template>
            </div>
        </section>

        <DecisionReopenDialog v-if="showReopenDialog" :stage-label="decisionReopen.stage_label" :busy="actionBusy" @close="showReopenDialog = false" @confirm="reopenDecision" />
    </main>
</template>
