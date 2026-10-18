<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import TaskPageHeader from '../components/TaskPageHeader.vue';
import { showPortalToast } from '../support/portalToast';

const scholarshipId = document.getElementById('app')?.dataset.scholarshipId;
const scholarship = ref(null);
const savedPlan = ref(null);
const frequencies = ref([]);
const requirementTypes = ref([]);
const canEdit = ref(false);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const activeStep = ref(0);
const showRequirementModal = ref(false);
const editingRequirementIndex = ref(-1);
const requirementDraft = ref(null);
const requirementError = ref('');
const form = ref(defaultPlan());

const hasPermission = (permission) => Boolean(
    window.portalUser?.has_full_access || window.portalUser?.permissions?.includes(permission),
);
const providerApproved = Boolean(window.portalUser?.can_post_scholarships);
const canManageMonitoring = hasPermission('manage_monitoring') && providerApproved;
const canManageBenefits = hasPermission('manage_benefit_releases') && providerApproved;
const canManageRecipients = hasPermission('manage_recipients') && providerApproved;
const canAccessMonitoring = canManageMonitoring || canManageBenefits || canManageRecipients;
const monitoringBaseUrl = `/provider/monitoring/${scholarshipId}`;
const steps = [
    { label: 'Schedule', icon: 'fa-regular fa-calendar' },
    { label: 'Requirements', icon: 'fa-solid fa-list-check' },
    { label: 'Review', icon: 'fa-solid fa-circle-check' },
];
const navigationLinks = computed(() => [
    { label: 'Monitoring plan', href: `${monitoringBaseUrl}/plan`, active: true },
    ...(canAccessMonitoring ? [
        { label: 'Overview', href: monitoringBaseUrl },
        ...(canManageMonitoring ? [{ label: 'Check-ins', href: `${monitoringBaseUrl}/academic` }] : []),
        ...(canManageBenefits ? [{ label: 'Benefit releases', href: `${monitoringBaseUrl}/releases` }] : []),
        ...((canManageMonitoring || canManageRecipients) ? [{ label: 'Support outcomes', href: `${monitoringBaseUrl}/outcomes` }] : []),
    ] : []),
]);
const requirementTypeMap = computed(() => new Map(
    requirementTypes.value.map((type) => [type.value, type]),
));
const availableRequirementTypes = computed(() => requirementTypes.value.filter((type) => (
    type.value === 'custom'
    || !form.value.requirements.some((requirement) => requirement.type === type.value)
)));
const activePlan = computed(() => savedPlan.value?.status === 'active');
const scheduleSummary = computed(() => {
    const frequency = frequencies.value.find((item) => item.value === form.value.frequency)?.label || 'Custom';
    const dates = form.value.starts_on || form.value.ends_on
        ? `${form.value.starts_on || 'No start date'} to ${form.value.ends_on || 'No end date'}`
        : 'Uses the program support period';

    return `${frequency} checks | ${dates}`;
});

function defaultPlan() {
    return {
        frequency: 'semester',
        starts_on: '',
        ends_on: '',
        grace_period_days: 7,
        allow_exception_requests: true,
        instructions: '',
        status: 'draft',
        requirements: [],
    };
}

function hydratePlan(plan) {
    if (!plan) {
        form.value = {
            ...defaultPlan(),
            starts_on: scholarship.value?.support_starts_on || '',
            ends_on: scholarship.value?.support_ends_on || '',
        };
        return;
    }

    form.value = {
        frequency: plan.frequency,
        starts_on: plan.starts_on || '',
        ends_on: plan.ends_on || '',
        grace_period_days: Number(plan.grace_period_days ?? 7),
        allow_exception_requests: Boolean(plan.allow_exception_requests),
        instructions: plan.instructions || '',
        status: plan.status,
        requirements: (plan.requirements ?? []).map((requirement) => ({ ...requirement })),
    };
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
        hydratePlan(savedPlan.value);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the monitoring plan.';
    } finally {
        isLoading.value = false;
    }
}

function selectStep(index) {
    if (index <= activeStep.value || !canEdit.value) activeStep.value = index;
}

function continueStep() {
    errorMessage.value = '';

    if (activeStep.value === 0 && form.value.starts_on && form.value.ends_on && form.value.ends_on < form.value.starts_on) {
        errorMessage.value = 'The monitoring end date must be on or after the start date.';
        return;
    }

    if (activeStep.value === 1 && !form.value.requirements.length) {
        errorMessage.value = 'Add at least one monitoring requirement before continuing.';
        return;
    }

    activeStep.value = Math.min(activeStep.value + 1, steps.length - 1);
}

function openRequirementEditor(index = -1) {
    requirementError.value = '';
    editingRequirementIndex.value = index;

    if (index >= 0) {
        requirementDraft.value = { ...form.value.requirements[index] };
    } else {
        const type = availableRequirementTypes.value[0] ?? requirementTypes.value.find((item) => item.value === 'custom');
        requirementDraft.value = requirementFromType(type);
    }

    showRequirementModal.value = true;
}

function requirementFromType(type) {
    const isAcademic = type?.value === 'academic_progress';

    return {
        id: null,
        type: type?.value || 'custom',
        title: type?.default_title || '',
        description: type?.description || '',
        evidence_description: type?.default_evidence || '',
        required: true,
        requires_file: Boolean(type?.requires_file ?? true),
        requires_original_verification: false,
        minimum_grade: isAcademic ? (scholarship.value?.minimum_grade ?? 85) : null,
        grading_scale: isAcademic ? (scholarship.value?.grading_scale || 'percentage') : null,
    };
}

function changeRequirementType() {
    const type = requirementTypeMap.value.get(requirementDraft.value.type);
    const currentId = requirementDraft.value.id;
    requirementDraft.value = { ...requirementFromType(type), id: currentId };
}

function closeRequirementEditor() {
    showRequirementModal.value = false;
    editingRequirementIndex.value = -1;
    requirementDraft.value = null;
    requirementError.value = '';
}

function saveRequirement() {
    const requirement = requirementDraft.value;

    if (!requirement?.title?.trim()) {
        requirementError.value = 'Add a short title for this requirement.';
        return;
    }

    if (requirement.requires_file && !requirement.evidence_description?.trim()) {
        requirementError.value = 'Describe the evidence recipients should upload.';
        return;
    }

    if (requirement.type === 'academic_progress' && (requirement.minimum_grade === '' || requirement.minimum_grade === null)) {
        requirementError.value = 'Add the minimum academic result.';
        return;
    }

    const normalized = {
        ...requirement,
        title: requirement.title.trim(),
        description: requirement.description?.trim() || '',
        evidence_description: requirement.evidence_description?.trim() || '',
    };

    if (editingRequirementIndex.value >= 0) {
        form.value.requirements.splice(editingRequirementIndex.value, 1, normalized);
    } else {
        form.value.requirements.push(normalized);
    }

    closeRequirementEditor();
}

function removeRequirement(index) {
    if (!canEdit.value) return;
    form.value.requirements.splice(index, 1);
}

function moveRequirement(index, direction) {
    const targetIndex = index + direction;
    if (targetIndex < 0 || targetIndex >= form.value.requirements.length) return;
    const [requirement] = form.value.requirements.splice(index, 1);
    form.value.requirements.splice(targetIndex, 0, requirement);
}

function requirementDetail(requirement) {
    if (requirement.type !== 'academic_progress') return requirement.evidence_description || 'Provider verification';
    const suffix = requirement.grading_scale === 'grade_point' ? ' grade point' : '%';
    return `Minimum ${Number(requirement.minimum_grade).toFixed(2)}${suffix}`;
}

async function savePlan(status) {
    if (!canEdit.value || isSaving.value) return;
    if (status === 'active' && !form.value.requirements.length) {
        activeStep.value = 1;
        errorMessage.value = 'Add at least one requirement before activating the plan.';
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
        hydratePlan(savedPlan.value);
        showPortalToast({
            type: 'success',
            title: status === 'active' ? 'Monitoring plan active' : 'Draft saved',
            message: response.data.message,
        });
    } catch (error) {
        const errors = error.response?.data?.errors;
        errorMessage.value = errors
            ? Object.values(errors).flat()[0]
            : (error.response?.data?.message ?? 'Unable to save the monitoring plan.');
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadPlan);
</script>

<template>
    <main class="provider-shell">
        <ProviderSidebar />

        <section class="provider-page">
            <div class="provider-container">
                <div v-if="isLoading" class="provider-panel p-6 text-sm text-slate-500">Loading monitoring plan...</div>
                <div v-else-if="!scholarship" class="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

                <template v-else>
                    <TaskPageHeader
                        theme="provider"
                        eyebrow="Recipient monitoring"
                        :title="scholarship.title"
                        description="Define what recipients submit, when reviews happen, and how exceptions are handled."
                        icon="fa-solid fa-list-check"
                    >
                        <template #actions>
                            <a :href="canAccessMonitoring ? '/provider/monitoring' : '/provider/programs'" class="inline-flex items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                                {{ canAccessMonitoring ? 'All monitoring' : 'All programs' }}
                            </a>
                        </template>
                    </TaskPageHeader>

                    <nav class="provider-panel mt-3 overflow-x-auto p-1.5" aria-label="Recipient monitoring views">
                        <div class="grid min-w-[40rem] gap-1" :style="{ gridTemplateColumns: `repeat(${navigationLinks.length}, minmax(7rem, 1fr))` }">
                            <a v-for="link in navigationLinks" :key="link.label" :href="link.href" :aria-current="link.active ? 'page' : undefined" :class="['flex min-h-10 items-center justify-center rounded-md px-3 py-2 text-sm font-bold', link.active ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950']">{{ link.label }}</a>
                        </div>
                    </nav>

                    <p v-if="errorMessage" class="mt-3 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <section class="provider-panel mt-3 overflow-hidden">
                        <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Monitoring policy</p>
                                <h2 class="mt-1 text-lg font-bold text-slate-950">Plan setup</h2>
                                <p class="mt-1 text-sm text-slate-500">Complete one short section at a time.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span :class="['rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', activePlan ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600']">{{ savedPlan?.status_label || 'Not saved' }}</span>
                                <span v-if="savedPlan" class="text-xs font-semibold text-slate-500">Version {{ savedPlan.version }}</span>
                            </div>
                        </header>

                        <nav class="grid border-b border-slate-200 sm:grid-cols-3" aria-label="Monitoring plan steps">
                            <button v-for="(step, index) in steps" :key="step.label" type="button" :class="['flex items-center gap-3 border-b px-5 py-3 text-left sm:border-b-0 sm:border-r last:border-r-0', activeStep === index ? 'bg-amber-50 text-slate-950' : index < activeStep ? 'bg-white text-slate-700' : 'bg-slate-50 text-slate-400']" @click="selectStep(index)">
                                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs font-bold', activeStep === index ? 'bg-amber-300 text-slate-950' : index < activeStep ? 'bg-slate-950 text-white' : 'bg-slate-200 text-slate-500']">
                                    <i v-if="index < activeStep" class="fa-solid fa-check" aria-hidden="true"></i>
                                    <span v-else>{{ index + 1 }}</span>
                                </span>
                                <span><span class="block text-[10px] font-bold uppercase tracking-[0.12em] opacity-70">Step {{ index + 1 }}</span><span class="mt-0.5 block text-sm font-bold">{{ step.label }}</span></span>
                            </button>
                        </nav>

                        <div class="p-5 sm:p-6">
                            <section v-if="activeStep === 0" class="w-full">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span>
                                    <div><h3 class="font-bold text-slate-950">Monitoring schedule</h3><p class="mt-1 text-sm text-slate-500">Set the normal review frequency and support period.</p></div>
                                </div>

                                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Review frequency</span><select v-model="form.frequency" :disabled="!canEdit" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 disabled:bg-slate-100"><option v-for="frequency in frequencies" :key="frequency.value" :value="frequency.value">{{ frequency.label }}</option></select></label>
                                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Grace period</span><div class="flex items-center"><input v-model.number="form.grace_period_days" :disabled="!canEdit" type="number" min="0" max="60" class="w-full rounded-l-md border border-slate-300 px-3 py-2.5 text-sm text-slate-950 disabled:bg-slate-100"><span class="rounded-r-md border border-l-0 border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-500">days</span></div></label>
                                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Monitoring starts</span><input v-model="form.starts_on" :disabled="!canEdit" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm text-slate-950 disabled:bg-slate-100"></label>
                                    <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Monitoring ends</span><input v-model="form.ends_on" :disabled="!canEdit" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm text-slate-950 disabled:bg-slate-100"></label>
                                </div>

                                <label class="mt-5 flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 p-4">
                                    <input v-model="form.allow_exception_requests" :disabled="!canEdit" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950">
                                    <span><strong class="block text-sm text-slate-950">Allow exception or extension requests</strong><span class="mt-1 block text-xs leading-5 text-slate-500">Recipients can explain illness, transfer, family circumstances, or another documented issue before a requirement is treated as incomplete.</span></span>
                                </label>

                                <label class="mt-5 block"><span class="mb-2 block text-xs font-bold text-slate-700">General instructions <span class="font-normal text-slate-400">(optional)</span></span><textarea v-model="form.instructions" :disabled="!canEdit" rows="4" maxlength="2000" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6 text-slate-950 placeholder:text-slate-400 disabled:bg-slate-100" placeholder="Add instructions that apply to every monitoring period."></textarea></label>
                            </section>

                            <section v-else-if="activeStep === 1">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div><h3 class="font-bold text-slate-950">Recipient requirements</h3><p class="mt-1 text-sm text-slate-500">Add only records necessary for continuing support.</p></div>
                                    <button v-if="canEdit" type="button" :disabled="!availableRequirementTypes.length" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" @click="openRequirementEditor()"><i class="fa-solid fa-plus text-xs" aria-hidden="true"></i>Add requirement</button>
                                </div>

                                <div class="mt-5 overflow-hidden rounded-md border border-slate-200">
                                    <div v-if="!form.requirements.length" class="px-5 py-10 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span><p class="mt-3 font-bold text-slate-950">No requirements added</p><p class="mt-1 text-sm text-slate-500">Begin with the records recipients must provide during support.</p></div>
                                    <article v-for="(requirement, index) in form.requirements" :key="requirement.id || `${requirement.type}-${index}`" class="flex flex-col gap-4 border-b border-slate-200 p-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i :class="requirementTypeMap.get(requirement.type)?.icon || 'fa-solid fa-list-check'" aria-hidden="true"></i></span>
                                            <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><h4 class="font-bold text-slate-950">{{ requirement.title }}</h4><span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-600">{{ requirementTypeMap.get(requirement.type)?.label || 'Custom' }}</span></div><p class="mt-1 text-xs leading-5 text-slate-500">{{ requirementDetail(requirement) }}</p></div>
                                        </div>
                                        <div v-if="canEdit" class="flex shrink-0 items-center gap-2">
                                            <button type="button" :disabled="index === 0" class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 text-slate-600 disabled:opacity-30" aria-label="Move requirement up" @click="moveRequirement(index, -1)"><i class="fa-solid fa-arrow-up text-xs" aria-hidden="true"></i></button>
                                            <button type="button" :disabled="index === form.requirements.length - 1" class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 text-slate-600 disabled:opacity-30" aria-label="Move requirement down" @click="moveRequirement(index, 1)"><i class="fa-solid fa-arrow-down text-xs" aria-hidden="true"></i></button>
                                            <button type="button" class="rounded-md border border-slate-300 px-3 py-2 text-xs font-bold text-slate-700" @click="openRequirementEditor(index)">Edit</button>
                                            <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-rose-200 text-rose-700" aria-label="Remove requirement" @click="removeRequirement(index)"><i class="fa-solid fa-trash-can text-xs" aria-hidden="true"></i></button>
                                        </div>
                                    </article>
                                </div>
                            </section>

                            <section v-else class="w-full">
                                <div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-800"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span><div><h3 class="font-bold text-slate-950">Review the monitoring plan</h3><p class="mt-1 text-sm text-slate-500">Confirm the schedule and requirements before activation.</p></div></div>
                                <dl class="mt-5 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                    <div class="px-4 py-4 sm:px-5"><dt class="text-xs font-bold text-slate-500">Schedule</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ scheduleSummary }}</dd><p class="mt-1 text-xs text-slate-500">{{ form.grace_period_days }}-day grace period | Exceptions {{ form.allow_exception_requests ? 'allowed' : 'not enabled' }}</p></div>
                                    <div class="px-4 py-4 sm:px-5"><dt class="text-xs font-bold text-slate-500">Requirements</dt><dd class="mt-1 text-sm font-bold text-slate-950">{{ form.requirements.length }} requirement{{ form.requirements.length === 1 ? '' : 's' }}</dd><p class="mt-1 text-xs text-slate-500">{{ form.requirements.map((item) => item.title).join(' | ') || 'None added' }}</p></div>
                                    <div class="px-4 py-4 sm:px-5"><dt class="text-xs font-bold text-slate-500">Recipient instructions</dt><dd class="mt-1 text-sm leading-6 text-slate-700">{{ form.instructions || 'No additional instructions.' }}</dd></div>
                                </dl>
                                <div v-if="canEdit" class="mt-5 rounded-md border border-amber-200 bg-amber-50 p-4"><p class="text-sm font-bold text-slate-950">Choose how to save</p><p class="mt-1 text-xs leading-5 text-slate-600">A draft is private to the provider team. An active plan becomes the official basis for future monitoring periods.</p><div class="mt-4 flex flex-col gap-2 sm:flex-row"><button type="button" :disabled="isSaving" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 disabled:opacity-50" @click="savePlan('draft')">Save draft</button><button type="button" :disabled="isSaving || !form.requirements.length" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50" @click="savePlan('active')">{{ isSaving ? 'Saving...' : 'Activate plan' }}</button></div></div>
                                <div v-else class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">You can review this plan. A program manager must make changes.</div>
                            </section>
                        </div>

                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <button v-if="activeStep > 0" type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="activeStep--">Previous</button><span v-else></span>
                            <button v-if="activeStep < steps.length - 1" type="button" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white" @click="continueStep">Continue</button>
                        </footer>
                    </section>
                </template>
            </div>
        </section>

        <Teleport to="body">
            <div v-if="showRequirementModal && requirementDraft" class="fixed inset-0 z-[2200] flex items-center justify-center bg-slate-950/65 p-3 sm:p-5" @click.self="closeRequirementEditor">
                <section class="flex max-h-[94vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="requirement-editor-title">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6"><div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-amber-700">Monitoring requirement</p><h2 id="requirement-editor-title" class="mt-1 text-xl font-bold text-slate-950">{{ editingRequirementIndex >= 0 ? 'Edit requirement' : 'Add requirement' }}</h2></div><button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 text-slate-500" aria-label="Close requirement editor" @click="closeRequirementEditor"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
                    <form class="overflow-y-auto" @submit.prevent="saveRequirement">
                        <div class="space-y-4 p-5 sm:p-6">
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Requirement type</span><select v-model="requirementDraft.type" :disabled="editingRequirementIndex >= 0" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-950 disabled:bg-slate-100" @change="changeRequirementType"><option v-for="type in (editingRequirementIndex >= 0 ? requirementTypes : availableRequirementTypes)" :key="type.value" :value="type.value">{{ type.label }}</option></select></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Title</span><input v-model="requirementDraft.title" maxlength="120" required class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm text-slate-950"></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">What this checks</span><textarea v-model="requirementDraft.description" rows="3" maxlength="1000" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6 text-slate-950"></textarea></label>
                            <label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Accepted evidence</span><textarea v-model="requirementDraft.evidence_description" :required="requirementDraft.requires_file" rows="3" maxlength="1000" class="w-full resize-y rounded-md border border-slate-300 px-3 py-2.5 text-sm leading-6 text-slate-950"></textarea></label>
                            <div v-if="requirementDraft.type === 'academic_progress'" class="grid gap-4 rounded-md border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2"><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Grading scale</span><select v-model="requirementDraft.grading_scale" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm"><option value="percentage">Percentage</option><option value="grade_point">Grade point</option></select></label><label class="block"><span class="mb-2 block text-xs font-bold text-slate-700">Minimum result</span><input v-model.number="requirementDraft.minimum_grade" type="number" step="0.01" :min="requirementDraft.grading_scale === 'grade_point' ? 1 : 0" :max="requirementDraft.grading_scale === 'grade_point' ? 5 : 100" class="w-full rounded-md border border-slate-300 px-3 py-2.5 text-sm"></label></div>
                            <div class="divide-y divide-slate-200 rounded-md border border-slate-200">
                                <label class="flex items-start gap-3 p-4"><input v-model="requirementDraft.required" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span><strong class="block text-sm text-slate-950">Required for continuing support</strong><span class="mt-1 block text-xs text-slate-500">The provider must review this requirement or record an exception.</span></span></label>
                                <label class="flex items-start gap-3 p-4"><input v-model="requirementDraft.requires_file" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span><strong class="block text-sm text-slate-950">Recipient uploads evidence</strong><span class="mt-1 block text-xs text-slate-500">Turn this off when the provider records participation directly.</span></span></label>
                                <label class="flex items-start gap-3 p-4"><input v-model="requirementDraft.requires_original_verification" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-slate-950"><span><strong class="block text-sm text-slate-950">Original must be checked in person</strong><span class="mt-1 block text-xs text-slate-500">Online evidence is reviewed first; the original is verified later.</span></span></label>
                            </div>
                            <p v-if="requirementError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm font-semibold text-rose-700">{{ requirementError }}</p>
                        </div>
                        <footer class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700" @click="closeRequirementEditor">Cancel</button><button type="submit" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white">Save requirement</button></footer>
                    </form>
                </section>
            </div>
        </Teleport>
    </main>
</template>
