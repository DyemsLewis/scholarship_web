<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    initialCategory: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:modelValue']);
const isLoading = ref(false);
const isSubmitting = ref(false);
const errorMessage = ref('');
const categories = ref([]);
const privacyRequestTypes = ref([]);
const programs = ref([]);
const form = ref({
    category: '',
    privacyRequestType: '',
    scholarshipId: '',
    subject: '',
    description: '',
});

const isProgramConcern = computed(() => form.value.category === 'program');
const isPrivacyConcern = computed(() => form.value.category === 'privacy');
const destinationMessage = computed(() => (
    isProgramConcern.value
        ? 'This report will go to the selected program provider and platform administrators.'
        : isPrivacyConcern.value
            ? 'This privacy request will go only to authorized platform administrators, not scholarship providers.'
            : 'This report will go to platform administrators.'
));

function resetForm(category = '') {
    form.value = {
        category,
        privacyRequestType: '',
        scholarshipId: '',
        subject: '',
        description: '',
    };
    errorMessage.value = '';
}

function closeModal() {
    if (isSubmitting.value) {
        return;
    }

    emit('update:modelValue', false);
}

async function loadOptions() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/reports/data');

        categories.value = response.data.categories ?? [];
        privacyRequestTypes.value = response.data.privacy_request_types ?? [];
        programs.value = response.data.programs ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load the report form.';
    } finally {
        isLoading.value = false;
    }
}

async function submitReport() {
    errorMessage.value = '';
    isSubmitting.value = true;

    try {
        await window.axios.post('/dashboard/reports', {
            category: form.value.category,
            privacy_request_type: isPrivacyConcern.value ? form.value.privacyRequestType : null,
            scholarship_id: isProgramConcern.value ? form.value.scholarshipId : null,
            subject: form.value.subject,
            description: form.value.description,
        });

        resetForm();
        emit('update:modelValue', false);
    } catch (error) {
        const validationErrors = error.response?.data?.errors ?? {};

        errorMessage.value = Object.values(validationErrors).flat()[0]
            ?? error.response?.data?.message
            ?? 'Unable to send your report.';
    } finally {
        isSubmitting.value = false;
    }
}

watch(() => props.modelValue, (isOpen) => {
    document.body.classList.toggle('overflow-hidden', isOpen);

    if (isOpen) {
        resetForm(props.initialCategory);
        loadOptions();
    } else {
        resetForm();
    }
}, { immediate: true });

watch(() => props.initialCategory, (category) => {
    if (props.modelValue) {
        form.value.category = category;
    }
});

watch(() => form.value.category, (category) => {
    if (category !== 'program') {
        form.value.scholarshipId = '';
    }

    if (category !== 'privacy') {
        form.value.privacyRequestType = '';
    }
});

onUnmounted(() => {
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="modelValue"
            class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="applicant-report-title"
            tabindex="-1"
            @click.self="closeModal"
            @keydown.esc="closeModal"
        >
            <section class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white text-slate-900 shadow-2xl">
                <header class="flex items-start justify-between gap-4 bg-[#081426] px-5 py-5 text-white sm:px-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">Applicant support</p>
                        <h2 id="applicant-report-title" class="mt-1 font-display text-2xl font-bold">Report a problem</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-300">
                            Briefly tell us what happened so it reaches the correct team.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-white/20 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white hover:text-slate-950"
                        aria-label="Close report form"
                        @click="closeModal"
                    >
                        Close
                    </button>
                </header>

                <div v-if="isLoading" class="p-6 text-sm text-slate-500">Loading report form...</div>

                <form v-else class="p-5 sm:p-6" @submit.prevent="submitReport">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="modal-report-category" class="mb-2 block text-sm font-semibold text-slate-700">Concern type</label>
                            <select
                                id="modal-report-category"
                                v-model="form.category"
                                required
                                class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                            >
                                <option value="" disabled>Select a concern</option>
                                <option v-for="category in categories" :key="category.value" :value="category.value">
                                    {{ category.label }}
                                </option>
                            </select>
                        </div>

                        <div v-if="isProgramConcern">
                            <label for="modal-report-program" class="mb-2 block text-sm font-semibold text-slate-700">Related program</label>
                            <select
                                id="modal-report-program"
                                v-model="form.scholarshipId"
                                required
                                class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                            >
                                <option value="" disabled>Select a scholarship</option>
                                <option v-for="program in programs" :key="program.id" :value="program.id">
                                    {{ program.title }}
                                </option>
                            </select>
                        </div>

                        <div v-if="isPrivacyConcern">
                            <label for="modal-privacy-request-type" class="mb-2 block text-sm font-semibold text-slate-700">Privacy request</label>
                            <select
                                id="modal-privacy-request-type"
                                v-model="form.privacyRequestType"
                                required
                                class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                            >
                                <option value="" disabled>Select what you need</option>
                                <option v-for="requestType in privacyRequestTypes" :key="requestType.value" :value="requestType.value">
                                    {{ requestType.label }}
                                </option>
                            </select>
                        </div>

                        <div :class="isProgramConcern || isPrivacyConcern ? 'sm:col-span-2' : ''">
                            <label for="modal-report-subject" class="mb-2 block text-sm font-semibold text-slate-700">Short title</label>
                            <input
                                id="modal-report-subject"
                                v-model="form.subject"
                                type="text"
                                required
                                maxlength="150"
                                placeholder="Example: Application button is not working"
                                class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                            >
                        </div>

                        <div class="sm:col-span-2">
                            <label for="modal-report-description" class="mb-2 block text-sm font-semibold text-slate-700">What happened?</label>
                            <textarea
                                id="modal-report-description"
                                v-model="form.description"
                                required
                                rows="4"
                                maxlength="2000"
                                placeholder="Describe the problem and where you encountered it. Do not include passwords."
                                class="w-full resize-y rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                            ></textarea>
                        </div>
                    </div>

                    <p v-if="form.category" class="mt-4 rounded-md bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                        {{ destinationMessage }}
                    </p>
                    <p v-if="errorMessage" class="mt-3 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

                    <footer class="mt-5 flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                            @click="closeModal"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="isSubmitting || isLoading"
                            class="rounded-md bg-slate-950 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ isSubmitting ? 'Sending...' : 'Send report' }}
                        </button>
                    </footer>
                </form>
            </section>
        </div>
    </Teleport>
</template>
