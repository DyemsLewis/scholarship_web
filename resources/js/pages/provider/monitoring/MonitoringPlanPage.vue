<script setup>
import WorkspaceTextSummary from '../../../components/workspace-dashboard/WorkspaceTextSummary.vue';
import ProviderVenueAddress from '../../../components/ProviderVenueAddress.vue';
import { computed, onMounted, ref, watch } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';
import { showPortalToast } from '../../../support/portalToast';
import { useModalAccessibility } from '../../../composables/useModalAccessibility';
import { confirmOutcome } from '../../../support/outcomeConfirmation.js';

const scholarshipId = document.getElementById('app')?.dataset.scholarshipId;
const scholarship = ref(null);
const savedPlan = ref(null);
const frequencies = ref([]);
const requirementTypes = ref([]);
const canEdit = ref(false);
const canPublishCheckIn = ref(false);
const appointmentRecipients = ref([]);
const rosterSearch = ref('');
const filteredRoster = computed(() => appointmentRecipients.value.filter(recipient => recipient.name.toLowerCase().includes(rosterSearch.value.trim().toLowerCase())));
const isLoading = ref(true);
const isSaving = ref(false);
const isPublishingCheckIn = ref(false);
const errorMessage = ref('');
const activeSection = ref('schedule');
const requirementEditor = ref(null);
const checkInEditor = ref(false);
const editingIndex = ref(-1);
const form = ref(defaultPlan());
const checkInForm = ref(defaultCheckIn());
const requirementDialog = ref(null);
const checkInDialog = ref(null);
const requirementError = ref('');
const { handleKeydown: handleRequirementKeydown } = useModalAccessibility(() => Boolean(requirementEditor.value), requirementDialog, closeRequirementEditor);
const { handleKeydown: handleCheckInKeydown } = useModalAccessibility(() => checkInEditor.value, checkInDialog, closeCheckInEditor);

watch(requirementError, (message) => {
    const body = requirementDialog.value?.querySelector('.monitoring-dialog-body');
    if (message && body) body.scrollTop = 0;
}, { flush: 'post' });

const sections = [
    { key: 'schedule', label: '1. Schedule', icon: 'fa-solid fa-calendar-days' },
    { key: 'requirements', label: '2. Requirements', icon: 'fa-solid fa-list-check' },
    { key: 'review', label: '3. Review', icon: 'fa-solid fa-clipboard-check' },
];
const activePlan = computed(() => savedPlan.value?.status === 'active');
const availableTypes = computed(() => requirementTypes.value.filter((type) => (
    type.value === 'custom'
    || !form.value.requirements.some((requirement) => requirement.type === type.value)
)));
const frequencyLabel = computed(() => frequencies.value.find((item) => item.value === form.value.frequency)?.label || 'Custom');
const dateRange = computed(() => {
    if (!form.value.starts_on && !form.value.ends_on) return 'Uses the program support period';
    return `${form.value.starts_on || 'No start date'} to ${form.value.ends_on || 'No end date'}`;
});

function defaultPlan() {
    return {
        frequency: 'semester',
        starts_on: '',
        ends_on: '',
        grace_period_days: 7,
        allow_exception_requests: true,
        instructions: '',
        requirements: [],
    };
}

function localDate() {
    const now = new Date();
    const local = new Date(now.getTime() - (now.getTimezoneOffset() * 60_000));
    return local.toISOString().slice(0, 10);
}

function defaultCheckIn() {
    return {
        title: '',
        period_label: '',
        school_year: '',
        handoff_at: '',
        handoff_location: '',
        address_details: null,
        instructions: '',
        recipient_ids: [],
    };
}

function hydrate(plan) {
    form.value = plan ? {
        frequency: plan.frequency,
        starts_on: plan.starts_on || '',
        ends_on: plan.ends_on || '',
        grace_period_days: Number(plan.grace_period_days ?? 7),
        allow_exception_requests: Boolean(plan.allow_exception_requests),
        instructions: plan.instructions || '',
        requirements: (plan.requirements ?? []).map((requirement) => ({ ...requirement })),
    } : {
        ...defaultPlan(),
        starts_on: scholarship.value?.support_starts_on || '',
        ends_on: scholarship.value?.support_ends_on || '',
    };
}

function firstError(error, fallback) {
    const errors = error.response?.data?.errors;
    return (errors && Object.values(errors).flat()[0]) || error.response?.data?.message || fallback;
}

async function loadPlan() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get(`/provider/scholarships/${scholarshipId}/monitoring-plan`);
        scholarship.value = response.data.scholarship;
        savedPlan.value = response.data.plan;
        frequencies.value = response.data.frequencies ?? [];
        requirementTypes.value = response.data.requirement_types ?? [];
        canEdit.value = Boolean(response.data.can_edit);
        canPublishCheckIn.value = Boolean(response.data.can_publish_check_in);
        appointmentRecipients.value = response.data.appointment_recipients ?? [];
        hydrate(savedPlan.value);
    } catch (error) {
        errorMessage.value = firstError(error, 'The monitoring plan could not be loaded.');
    } finally {
        isLoading.value = false;
    }
}

function openRequirement(index = -1) {
    requirementError.value = '';
    editingIndex.value = index;
    if (index >= 0) {
        requirementEditor.value = { ...form.value.requirements[index] };
        return;
    }

    const type = availableTypes.value[0] ?? requirementTypes.value.find((item) => item.value === 'custom');
    requirementEditor.value = requirementFromType(type);
}

function requirementFromType(type) {
    const academic = type?.value === 'academic_progress';
    return {
        id: null,
        type: type?.value || 'custom',
        title: type?.default_title || '',
        description: type?.description || '',
        evidence_description: type?.default_evidence || '',
        required: true,
        requires_file: Boolean(type?.requires_file ?? true),
        requires_original_verification: false,
        minimum_grade: academic ? (scholarship.value?.minimum_grade ?? 85) : null,
        grading_scale: academic ? (scholarship.value?.grading_scale || 'percentage') : null,
    };
}

function changeRequirementType() {
    const type = requirementTypes.value.find((item) => item.value === requirementEditor.value.type);
    const id = requirementEditor.value.id;
    requirementEditor.value = { ...requirementFromType(type), id };
}

function saveRequirement() {
    const requirement = requirementEditor.value;
    if (!requirement?.title?.trim()) {
        requirementError.value = 'Add a short title for the monitoring requirement.';
        return;
    }
    if (requirement.requires_file && !requirement.evidence_description?.trim()) {
        requirementError.value = 'Describe the evidence recipients need to submit.';
        return;
    }

    const normalized = {
        ...requirement,
        title: requirement.title.trim(),
        description: requirement.description?.trim() || '',
        evidence_description: requirement.evidence_description?.trim() || '',
        minimum_grade: requirement.type === 'academic_progress' ? Number(requirement.minimum_grade) : null,
        grading_scale: requirement.type === 'academic_progress' ? requirement.grading_scale : null,
    };

    if (editingIndex.value >= 0) form.value.requirements.splice(editingIndex.value, 1, normalized);
    else form.value.requirements.push(normalized);

    errorMessage.value = '';
    requirementError.value = '';
    requirementEditor.value = null;
    editingIndex.value = -1;
}

function removeRequirement(index) {
    form.value.requirements.splice(index, 1);
}

function closeRequirementEditor() {
    requirementEditor.value = null;
    requirementError.value = '';
}

function closeCheckInEditor() {
    if (isPublishingCheckIn.value) return;
    checkInEditor.value = false;
}

function goTo(section) {
    errorMessage.value = '';
    if (section === 'requirements' && form.value.starts_on && form.value.ends_on && form.value.ends_on < form.value.starts_on) {
        errorMessage.value = 'The monitoring end date must be on or after the start date.';
        return;
    }
    if (section === 'review' && !form.value.requirements.length) {
        activeSection.value = 'requirements';
        errorMessage.value = 'Add at least one requirement before reviewing the plan.';
        return;
    }
    activeSection.value = section;
}

async function savePlan(status) {
    if (!canEdit.value || isSaving.value) return;
    if (status === 'active' && !form.value.requirements.length) {
        goTo('requirements');
        return;
    }

    isSaving.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.put(`/provider/scholarships/${scholarshipId}/monitoring-plan`, {
            ...form.value,
            status,
        });
        savedPlan.value = response.data.plan;
        hydrate(savedPlan.value);
        showPortalToast({
            type: 'success',
            title: status === 'active' ? 'Monitoring plan active' : 'Draft saved',
            message: response.data.message,
        });
    } catch (error) {
        errorMessage.value = firstError(error, 'The monitoring plan could not be saved.');
    } finally {
        isSaving.value = false;
    }
}

function openCheckInEditor() {
    rosterSearch.value = '';
    checkInForm.value = {
        ...defaultCheckIn(),
        school_year: scholarship.value?.program_cycle || '',
    };
    checkInEditor.value = true;
}

async function publishCheckIn() {
    if (!activePlan.value || isPublishingCheckIn.value || !checkInForm.value.recipient_ids.length) return;
    const count = checkInForm.value.recipient_ids.length;
    if (!await confirmOutcome({
        record: checkInForm.value.title,
        outcome: 'Publish in-person check-in',
        consequence: `Only the ${count} selected recipient${count === 1 ? '' : 's'} will receive this appointment.`,
    }, () => checkInEditor.value && !isPublishingCheckIn.value)) return;

    isPublishingCheckIn.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.post(
            `/provider/scholarships/${scholarshipId}/monitoring-check-ins`,
            checkInForm.value,
        );
        checkInEditor.value = false;
        showPortalToast({
            type: 'success',
            title: 'Check-in published',
            message: response.data.message,
        });
    } catch (error) {
        showPortalToast({
            type: 'error',
            title: 'Check-in not published',
            message: firstError(error, 'The check-in could not be published.'),
        });
    } finally {
        isPublishingCheckIn.value = false;
    }
}

onMounted(loadPlan);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="monitoring-plan-title">
            <div class="provider-monitoring-workspace mx-auto max-w-[94rem]">
                <div v-if="isLoading" class="border border-slate-200 bg-white px-6 py-16 text-center">
                    <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-spinner fa-spin text-sm" aria-hidden="true"></i></span>
                    <p class="mt-3 text-sm font-semibold text-slate-500">Loading monitoring plan...</p>
                </div>

                <template v-else-if="scholarship">
                    <header class="border border-slate-200 border-t-2 border-t-slate-950 bg-white px-7 py-6 shadow-sm">
                        <div class="flex items-start justify-between gap-8">
                            <div class="flex min-w-0 items-start gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i></span>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-amber-700">Recipient monitoring</p>
                                    <h1 id="monitoring-plan-title" class="monitoring-page-title mt-1 font-display text-2xl font-bold tracking-tight text-slate-950">Monitoring plan</h1>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">Set the schedule and records required for {{ scholarship.title }}.</p>
                                </div>
                            </div>
                            <div class="monitoring-page-actions flex items-center gap-3">
                                <span :class="['border px-3 py-2 text-xs font-bold uppercase tracking-wide', activePlan ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-50 text-slate-600']">{{ savedPlan?.status_label || 'Not saved' }}</span>
                                <button v-if="activePlan && canPublishCheckIn" type="button" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800" @click="openCheckInEditor"><i class="fa-solid fa-calendar-plus text-[10px] text-amber-300" aria-hidden="true"></i>New check-in</button>
                                <a href="/provider/monitoring/plans" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-arrow-left text-[10px]" aria-hidden="true"></i> All plans</a>
                            </div>
                        </div>
                    </header>

                    <div v-if="errorMessage" class="mt-4 border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</div>
                    <div v-if="!canEdit" class="mt-4 border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">This plan is read-only for your role. A program manager can update it.</div>
                    <div v-else-if="activePlan && !canPublishCheckIn" class="mt-4 border border-slate-200 bg-slate-50 px-5 py-4 text-sm text-slate-700">The plan is active. A monitoring officer publishes each check-in period.</div>

                    <section class="mt-4 border border-slate-200 bg-white shadow-sm">
                        <nav class="grid grid-cols-3 border-b border-slate-200" aria-label="Monitoring plan sections">
                            <button v-for="section in sections" :key="section.key" type="button" :aria-current="activeSection === section.key ? 'step' : undefined" :class="['flex min-h-14 items-center gap-3 border-r border-slate-200 px-5 text-left text-sm font-bold last:border-r-0', activeSection === section.key ? 'bg-slate-950 text-white' : 'bg-white text-slate-600 hover:bg-slate-50']" @click="goTo(section.key)"><i :class="[section.icon, activeSection === section.key ? 'text-amber-300' : 'text-slate-400']" aria-hidden="true"></i>{{ section.label }}</button>
                        </nav>

                        <div v-if="activeSection === 'schedule'" class="p-7">
                            <div class="flex items-start gap-3 border-b border-slate-200 pb-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-calendar-days text-sm" aria-hidden="true"></i></span>
                                <div><h2 class="text-lg font-bold text-slate-950">Review timing</h2><p class="mt-1 text-sm text-slate-500">Choose when recipient check-ins normally happen.</p></div>
                            </div>
                            <div class="mt-6 grid grid-cols-4 gap-5">
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Frequency</span><select v-model="form.frequency" :disabled="!canEdit" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm disabled:bg-slate-100"><option v-for="frequency in frequencies" :key="frequency.value" :value="frequency.value">{{ frequency.label }}</option></select></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Grace period</span><div class="flex"><input v-model.number="form.grace_period_days" :disabled="!canEdit" type="number" min="0" max="60" class="h-11 min-w-0 flex-1 border border-slate-300 px-3 text-sm disabled:bg-slate-100"><span class="grid h-11 place-items-center border border-l-0 border-slate-300 bg-slate-50 px-3 text-xs text-slate-500">days</span></div></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Starts</span><input v-model="form.starts_on" :disabled="!canEdit" type="date" :max="form.ends_on || undefined" class="h-11 w-full border border-slate-300 px-3 text-sm disabled:bg-slate-100"></label>
                                <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Ends</span><input v-model="form.ends_on" :disabled="!canEdit" type="date" :min="form.starts_on || undefined" class="h-11 w-full border border-slate-300 px-3 text-sm disabled:bg-slate-100"></label>
                            </div>
                            <label class="mt-6 flex items-start gap-3 border border-slate-200 bg-slate-50 p-4"><input v-model="form.allow_exception_requests" :disabled="!canEdit" type="checkbox" class="mt-0.5 h-4 w-4"><span><strong class="block text-sm text-slate-900">Allow extension or exception requests</strong><span class="mt-1 block text-xs leading-5 text-slate-500">Recipients can explain a documented issue before a requirement is marked incomplete.</span></span></label>
                            <label class="mt-5 block"><span class="mb-2 block text-xs font-bold text-slate-700">Instructions <span class="font-normal text-slate-600">(optional)</span></span><textarea v-model="form.instructions" :disabled="!canEdit" rows="4" maxlength="2000" class="w-full resize-y border border-slate-300 px-3 py-3 text-sm leading-6 disabled:bg-slate-100" placeholder="Add instructions that apply to every check-in."></textarea></label>
                            <div class="mt-7 flex justify-end"><button type="button" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800" @click="goTo('requirements')">Continue<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></button></div>
                        </div>

                        <div v-else-if="activeSection === 'requirements'" class="p-7">
                            <div class="flex items-start justify-between gap-6 border-b border-slate-200 pb-4">
                                <div class="flex items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-list-check text-sm" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Recipient requirements</h2><p class="mt-1 text-sm text-slate-500">Only request records needed to continue support.</p></div></div>
                                <button v-if="canEdit" type="button" class="h-10 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800" @click="openRequirement()"><i class="fa-solid fa-plus mr-2 text-[10px]" aria-hidden="true"></i>Add requirement</button>
                            </div>
                            <div v-if="!form.requirements.length" class="py-16 text-center"><span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-list-check text-sm" aria-hidden="true"></i></span><p class="mt-3 text-sm font-bold text-slate-800">No requirements added</p><p class="mt-1 text-xs text-slate-500">Add the first record recipients must provide.</p></div>
                            <div v-else class="divide-y divide-slate-200">
                                <article v-for="(requirement, index) in form.requirements" :key="requirement.id || `${requirement.type}-${index}`" class="flex min-h-24 items-center justify-between gap-8 py-5">
                                    <div class="min-w-0"><div class="flex items-center gap-2"><h3 class="monitoring-text text-sm font-bold text-slate-950">{{ requirement.title }}</h3><span v-if="requirement.required" class="bg-slate-100 px-2 py-1 text-xs font-bold uppercase text-slate-600">Required</span></div><WorkspaceTextSummary class="mt-1 text-sm text-slate-500" :text="requirement.evidence_description || requirement.description || 'No file required'" label="details" /><p v-if="requirement.type === 'academic_progress'" class="mt-2 text-xs font-bold text-amber-700">Minimum {{ requirement.minimum_grade }} {{ requirement.grading_scale === 'grade_point' ? 'grade point' : '%' }}</p></div>
                                    <div v-if="canEdit" class="flex shrink-0 gap-2"><button type="button" class="inline-flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700" @click="openRequirement(index)"><i class="fa-solid fa-pen text-[9px]" aria-hidden="true"></i>Edit</button><button type="button" class="grid h-9 w-9 place-items-center border border-rose-200 bg-white text-rose-700" aria-label="Remove requirement" @click="removeRequirement(index)"><i class="fa-solid fa-trash text-xs" aria-hidden="true"></i></button></div>
                                </article>
                            </div>
                            <div class="mt-7 flex justify-between border-t border-slate-200 pt-5"><button type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700" @click="goTo('schedule')"><i class="fa-solid fa-arrow-left text-[10px]" aria-hidden="true"></i>Previous</button><button type="button" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white hover:bg-slate-800" @click="goTo('review')">Review plan<i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></button></div>
                        </div>

                        <div v-else class="p-7">
                            <div class="flex items-start gap-3 border-b border-slate-200 pb-4"><span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-600"><i class="fa-solid fa-clipboard-check text-sm" aria-hidden="true"></i></span><div><h2 class="text-lg font-bold text-slate-950">Confirm the plan</h2><p class="mt-1 text-sm text-slate-500">Review the essentials before saving.</p></div></div>
                            <dl class="mt-6 divide-y divide-slate-200 border border-slate-200">
                                <div class="grid grid-cols-[14rem_1fr] px-5 py-4"><dt class="text-xs font-bold uppercase tracking-wide text-slate-600">Schedule</dt><dd><p class="text-sm font-bold text-slate-950">{{ frequencyLabel }}</p><p class="mt-1 text-xs text-slate-500">{{ dateRange }} · {{ form.grace_period_days }}-day grace period</p></dd></div>
                                <div class="grid grid-cols-[14rem_1fr] px-5 py-4"><dt class="text-xs font-bold uppercase tracking-wide text-slate-600">Requirements</dt><dd><p class="text-sm font-bold text-slate-950">{{ form.requirements.length }} item{{ form.requirements.length === 1 ? '' : 's' }}</p><p class="mt-1 text-xs text-slate-500">{{ form.requirements.map((item) => item.title).join(' · ') }}</p></dd></div>
                                <div class="grid grid-cols-[14rem_1fr] px-5 py-4"><dt class="text-xs font-bold uppercase tracking-wide text-slate-600">Exceptions</dt><dd class="text-sm font-bold text-slate-950">{{ form.allow_exception_requests ? 'Allowed' : 'Not enabled' }}</dd></div>
                            </dl>
                            <div class="mt-7 flex items-center justify-between border-t border-slate-200 pt-5"><button type="button" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700" @click="goTo('requirements')"><i class="fa-solid fa-arrow-left text-[10px]" aria-hidden="true"></i>Previous</button><div v-if="canEdit" class="flex gap-2"><button type="button" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-50" @click="savePlan('draft')"><i class="fa-solid fa-floppy-disk text-[10px]" aria-hidden="true"></i>Save draft</button><button type="button" :disabled="isSaving" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-50" @click="savePlan('active')"><i :class="['fa-solid text-[10px]', isSaving ? 'fa-spinner fa-spin' : 'fa-circle-check']" aria-hidden="true"></i>{{ isSaving ? 'Saving...' : 'Activate plan' }}</button></div></div>
                        </div>
                    </section>
                </template>

                <div v-else class="border border-rose-200 bg-rose-50 px-6 py-8 text-sm font-semibold text-rose-800">{{ errorMessage || 'The monitoring plan is unavailable.' }}</div>
            </div>
        </section>

        <Teleport to="body">
        <div v-if="requirementEditor" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6" @click.self="closeRequirementEditor">
            <form ref="requirementDialog" class="provider-monitoring-workspace monitoring-dialog w-full max-w-2xl border border-slate-300 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="requirement-editor-title" tabindex="-1" @keydown="handleRequirementKeydown" @submit.prevent="saveRequirement">
                <header class="flex items-start justify-between border-b border-slate-200 px-6 py-5"><div><p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Monitoring record</p><h2 id="requirement-editor-title" class="mt-1 text-xl font-bold text-slate-950">{{ editingIndex >= 0 ? 'Edit requirement' : 'Add requirement' }}</h2></div><button type="button" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600" aria-label="Close" @click="closeRequirementEditor"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                <div class="monitoring-dialog-body grid grid-cols-2 gap-5 p-6">
                    <p v-if="requirementError" class="col-span-2 border-l-4 border-rose-500 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">{{ requirementError }}</p>
                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Type</span><select v-model="requirementEditor.type" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm" @change="changeRequirementType"><option v-for="type in requirementTypes" :key="type.value" :value="type.value">{{ type.label }}</option></select></label>
                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Title</span><input v-model="requirementEditor.title" required maxlength="120" class="h-11 w-full border border-slate-300 px-3 text-sm"></label>
                    <label class="col-span-2 block"><span class="mb-2 block text-xs font-bold text-slate-700">What should the recipient bring?</span><textarea v-model="requirementEditor.evidence_description" :required="requirementEditor.requires_file" rows="3" maxlength="1000" class="w-full resize-y border border-slate-300 px-3 py-3 text-sm"></textarea></label>
                    <template v-if="requirementEditor.type === 'academic_progress'"><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Minimum grade</span><input v-model.number="requirementEditor.minimum_grade" required type="number" step="0.01" class="h-11 w-full border border-slate-300 px-3 text-sm"></label><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Scale</span><select v-model="requirementEditor.grading_scale" class="h-11 w-full border border-slate-300 bg-white px-3 text-sm"><option value="percentage">Percentage</option><option value="grade_point">Grade point</option></select></label></template>
                    <div class="col-span-2 flex gap-6 border border-slate-200 bg-slate-50 p-4"><label class="flex items-center gap-2 text-sm font-semibold text-slate-700"><input v-model="requirementEditor.requires_file" type="checkbox"> Bring a document</label><label class="flex items-center gap-2 text-sm font-semibold text-slate-700"><input v-model="requirementEditor.requires_original_verification" type="checkbox"> Check the original</label></div>
                </div>
                <footer class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4"><button type="button" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700" @click="closeRequirementEditor">Cancel</button><button type="submit" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white"><i class="fa-solid fa-floppy-disk text-[10px]" aria-hidden="true"></i>Save requirement</button></footer>
            </form>
        </div>

        <div v-if="checkInEditor" class="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 p-6" @click.self="closeCheckInEditor">
            <form ref="checkInDialog" class="provider-monitoring-workspace monitoring-dialog w-full max-w-2xl border border-slate-300 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="check-in-editor-title" tabindex="-1" @keydown="handleCheckInKeydown" @submit.prevent="publishCheckIn">
                <header class="flex items-start justify-between border-b border-slate-200 px-6 py-5">
                    <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Document handoff</p><h2 id="check-in-editor-title" class="mt-1 text-xl font-bold text-slate-950">Schedule an in-person check-in</h2><p class="mt-1 text-sm text-slate-500">Set the appointment and choose who should attend.</p></div>
                    <button type="button" :disabled="isPublishingCheckIn" class="grid h-9 w-9 place-items-center border border-slate-300 text-slate-600 disabled:opacity-50" aria-label="Close" @click="closeCheckInEditor"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </header>
                <div class="monitoring-dialog-body grid grid-cols-2 gap-5 p-6">
                    <label class="col-span-2 block"><span class="mb-2 block text-xs font-bold text-slate-700">Check-in title</span><input v-model="checkInForm.title" required maxlength="120" class="h-11 w-full border border-slate-300 px-3 text-sm" placeholder="First semester progress check"></label>
                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Academic period <span class="font-normal text-slate-600">(optional)</span></span><input v-model="checkInForm.period_label" maxlength="80" class="h-11 w-full border border-slate-300 px-3 text-sm" placeholder="First semester"></label>
                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">School year <span class="font-normal text-slate-600">(optional)</span></span><input v-model="checkInForm.school_year" maxlength="30" class="h-11 w-full border border-slate-300 px-3 text-sm" placeholder="2026-2027"></label>
                    <label class="col-span-2 block"><span class="mb-2 block text-xs font-bold text-slate-700">Date and time (Philippine time)</span><input v-model="checkInForm.handoff_at" required type="datetime-local" :min="(savedPlan?.starts_on && savedPlan.starts_on > localDate() ? savedPlan.starts_on : localDate()) + 'T00:00'" :max="savedPlan?.ends_on ? savedPlan.ends_on + 'T23:59' : undefined" class="h-11 w-full border border-slate-300 px-3 text-sm"></label>
                    <ProviderVenueAddress v-model="checkInForm.address_details" v-model:address="checkInForm.handoff_location" class="col-span-2" />
                    <label class="col-span-2 block"><span class="mb-2 block text-xs font-bold text-slate-700">Arrival instructions <span class="font-normal text-slate-600">(optional)</span></span><textarea v-model="checkInForm.instructions" rows="3" maxlength="2000" class="w-full resize-y border border-slate-300 px-3 py-3 text-sm" placeholder="For example: check in at the reception desk."></textarea></label>
                    <fieldset class="col-span-2 min-w-0 border border-slate-200 px-4 pb-3">
                        <legend class="px-2 text-sm font-bold text-slate-900">Recipients to invite</legend>
                        <p v-if="!appointmentRecipients.length" class="pt-2 text-sm text-slate-600">No active recipients have accepted their agreement yet.</p>
                        <template v-else>
                            <div class="mb-2 flex items-center justify-between gap-3 pt-2">
                                <input v-model="rosterSearch" type="search" aria-label="Find recipient" placeholder="Find a recipient" class="h-9 min-w-0 flex-1 border border-slate-300 px-3 text-sm">
                                <button type="button" class="shrink-0 text-xs font-bold text-slate-700 underline underline-offset-4" @click="checkInForm.recipient_ids = appointmentRecipients.map(item => item.id)">Select all eligible</button>
                                <button v-if="checkInForm.recipient_ids.length" type="button" class="shrink-0 text-xs font-bold text-slate-700 underline underline-offset-4" @click="checkInForm.recipient_ids = []">Clear</button>
                            </div>
                            <div class="max-h-36 divide-y divide-slate-200 overflow-y-auto">
                                <label v-for="recipient in filteredRoster" :key="recipient.id" class="flex cursor-pointer items-center gap-2 px-1 py-2 text-sm text-slate-800"><input v-model="checkInForm.recipient_ids" type="checkbox" :value="recipient.id">{{ recipient.name }}</label>
                                <p v-if="!filteredRoster.length" class="py-2 text-sm text-slate-600">No matching recipients.</p>
                            </div>
                        </template>
                    </fieldset>
                </div>
                <footer class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-6 py-4">
                    <p class="text-xs font-bold text-slate-600" aria-live="polite">{{ checkInForm.recipient_ids.length }} selected</p>
                    <div class="flex shrink-0 gap-2"><button type="button" :disabled="isPublishingCheckIn" class="h-10 border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 disabled:opacity-50" @click="closeCheckInEditor">Cancel</button><button type="submit" :disabled="isPublishingCheckIn || !checkInForm.recipient_ids.length" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-5 text-sm font-bold text-white disabled:opacity-50"><i :class="['fa-solid text-[10px]', isPublishingCheckIn ? 'fa-spinner fa-spin' : 'fa-paper-plane']" aria-hidden="true"></i>{{ isPublishingCheckIn ? 'Publishing...' : 'Publish check-in' }}</button></div>
                </footer>
            </form>
        </div>
        </Teleport>
    </main>
</template>
