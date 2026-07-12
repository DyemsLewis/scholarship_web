<script setup>
import { computed, onMounted, ref } from 'vue';
import ConfirmationDialog from '../components/ConfirmationDialog.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';
import { useConfirmationDialog } from '../composables/useConfirmationDialog';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');
const user = ref(null);
const assessments = ref([]);
const form = ref(emptyForm());
const {
    confirmation,
    requestConfirmation,
    confirmConfirmation,
    cancelConfirmation,
} = useConfirmationDialog();

const assessment = computed(() => assessments.value[0] ?? null);

function emptyForm() {
    return {
        title: '',
        assessmentType: 'qualifying_exam',
        description: '',
        durationMinutes: 60,
        passingScore: 70,
        deliveryMode: 'provider_managed',
        venue: '',
        instructions: '',
        isActive: true,
    };
}

function applyAssessment(value) {
    if (!value) {
        form.value = emptyForm();
        return;
    }

    form.value = {
        title: value.title ?? '',
        assessmentType: value.assessment_type ?? 'qualifying_exam',
        description: value.description ?? '',
        durationMinutes: value.duration_minutes ?? 60,
        passingScore: value.passing_score ?? 70,
        deliveryMode: value.delivery_mode ?? 'provider_managed',
        venue: value.venue ?? '',
        instructions: value.instructions ?? '',
        isActive: value.status === 'active',
    };
}

function labelFromKey(value) {
    return String(value ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadAssessments() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/exams/data');
        user.value = response.data.user;
        assessments.value = response.data.assessments ?? [];
        applyAssessment(assessments.value[0]);
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load assessment details.';
    } finally {
        isLoading.value = false;
    }
}

async function saveAssessment() {
    if (!assessment.value) {
        return;
    }

    if (assessment.value.status === 'active' && !form.value.isActive) {
        const confirmed = await requestConfirmation({
            title: 'Deactivate this assessment?',
            message: 'Applicants entering the exam workflow will no longer see these assessment details until it is activated again.',
            confirmLabel: 'Deactivate assessment',
            tone: 'danger',
        });

        if (!confirmed) {
            return;
        }
    }

    isSaving.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.patch(`/provider/exams/${assessment.value.id}`, {
            title: form.value.title,
            assessment_type: form.value.assessmentType,
            description: form.value.description || null,
            duration_minutes: form.value.durationMinutes || null,
            passing_score: form.value.passingScore === '' ? null : form.value.passingScore,
            delivery_mode: form.value.deliveryMode,
            venue: form.value.venue || null,
            instructions: form.value.instructions || null,
            status: form.value.isActive ? 'active' : 'inactive',
        });

        assessments.value = [response.data.assessment];
        applyAssessment(response.data.assessment);
        statusMessage.value = response.data.message ?? 'Assessment details updated.';
    } catch (error) {
        const validationMessage = Object.values(error.response?.data?.errors ?? {})[0]?.[0];
        errorMessage.value = validationMessage ?? error.response?.data?.message ?? 'Unable to update assessment details.';
    } finally {
        isSaving.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadAssessments);
</script>

<template>
    <main class="min-h-screen bg-slate-100 text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <ConfirmationDialog
            v-bind="confirmation"
            @confirm="confirmConfirmation"
            @cancel="cancelConfirmation"
        />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="provider-hero">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Assessment</p>
                    <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h1 class="font-display text-3xl font-bold text-slate-950">Exams</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ user?.provider_name || user?.name || 'Scholarship provider' }}</p>
                        </div>
                        <span v-if="assessment" :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', assessment.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700']">
                            {{ assessment.status }}
                        </span>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading assessment...
                </div>

                <div v-else class="mt-6 space-y-4">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">{{ errorMessage }}</p>
                    <p v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">{{ statusMessage }}</p>

                    <section v-if="assessment" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="grid lg:grid-cols-[21rem_minmax(0,1fr)]">
                            <div class="border-b border-slate-200 bg-slate-50 p-5 lg:border-b-0 lg:border-r">
                                <div class="aspect-[3/2] overflow-hidden rounded-md border border-slate-200 bg-white">
                                    <img :src="assessment.image_url" :alt="assessment.title" class="h-full w-full object-contain p-5">
                                </div>
                                <h2 class="mt-4 text-xl font-bold leading-7 text-slate-950">{{ assessment.title }}</h2>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ assessment.description || 'No assessment description.' }}</p>

                                <dl class="mt-5 divide-y divide-slate-200 border-y border-slate-200 text-sm">
                                    <div class="flex items-center justify-between gap-3 py-3">
                                        <dt class="font-semibold text-slate-500">Type</dt>
                                        <dd class="text-right font-bold text-slate-900">{{ labelFromKey(assessment.assessment_type) }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 py-3">
                                        <dt class="font-semibold text-slate-500">Duration</dt>
                                        <dd class="font-bold text-slate-900">{{ assessment.duration_minutes ? `${assessment.duration_minutes} minutes` : 'Not set' }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 py-3">
                                        <dt class="font-semibold text-slate-500">Passing score</dt>
                                        <dd class="font-bold text-slate-900">{{ assessment.passing_score !== null ? `${Number(assessment.passing_score)}%` : 'Not set' }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 py-3">
                                        <dt class="font-semibold text-slate-500">Delivery</dt>
                                        <dd class="text-right font-bold text-slate-900">{{ labelFromKey(assessment.delivery_mode) }}</dd>
                                    </div>
                                </dl>
                            </div>

                            <form class="p-5" @submit.prevent="saveAssessment">
                                <div class="flex items-center justify-between gap-4 border-b border-slate-200 pb-4">
                                    <div>
                                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-amber-700">Configuration</p>
                                        <h2 class="mt-1 text-xl font-bold text-slate-950">Assessment details</h2>
                                    </div>
                                    <label class="flex items-center gap-2 text-sm font-bold text-slate-700">
                                        <input v-model="form.isActive" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                                        Active
                                    </label>
                                </div>

                                <div class="mt-5 grid gap-4 md:grid-cols-2">
                                    <div class="md:col-span-2">
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Title</label>
                                        <input v-model="form.title" type="text" maxlength="255" required class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Assessment type</label>
                                        <select v-model="form.assessmentType" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100">
                                            <option value="qualifying_exam">Qualifying exam</option>
                                            <option value="screening_assessment">Screening assessment</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Delivery mode</label>
                                        <select v-model="form.deliveryMode" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100">
                                            <option value="provider_managed">Provider managed</option>
                                            <option value="onsite">On-site</option>
                                            <option value="online">Online</option>
                                            <option value="hybrid">Hybrid</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Duration (minutes)</label>
                                        <input v-model.number="form.durationMinutes" type="number" min="15" max="480" step="5" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Passing score (%)</label>
                                        <input v-model.number="form.passingScore" type="number" min="0" max="100" step="0.01" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Description</label>
                                        <textarea v-model="form.description" rows="3" maxlength="2000" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100"></textarea>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Venue or access method</label>
                                        <input v-model="form.venue" type="text" maxlength="500" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="mb-2 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Applicant instructions</label>
                                        <textarea v-model="form.instructions" rows="5" maxlength="3000" class="w-full rounded-md border border-slate-300 bg-white px-3 py-2.5 text-sm leading-6 outline-none focus:border-slate-700 focus:ring-3 focus:ring-slate-100"></textarea>
                                    </div>
                                </div>

                                <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
                                    <button type="button" class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="applyAssessment(assessment)">
                                        <i class="fa-solid fa-arrow-rotate-left"></i>
                                        Reset
                                    </button>
                                    <button type="submit" :disabled="isSaving" class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:opacity-60">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                        {{ isSaving ? 'Saving...' : 'Save assessment' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <section v-else class="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center shadow-sm">
                        <i class="fa-solid fa-clipboard-question text-2xl text-slate-400"></i>
                        <h2 class="mt-3 text-lg font-bold text-slate-900">No assessment assigned</h2>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
