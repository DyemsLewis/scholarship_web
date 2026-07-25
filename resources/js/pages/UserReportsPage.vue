<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const isSubmitting = ref(false);
const errorMessage = ref('');
const formError = ref('');
const categories = ref([]);
const programs = ref([]);
const reports = ref([]);
const pagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
});
const form = ref({
    category: '',
    scholarshipId: '',
    subject: '',
    description: '',
});

const isProgramConcern = computed(() => form.value.category === 'program');
const reportDestination = computed(() => (
    isProgramConcern.value ? 'This will be sent to the selected program provider.' : 'This will be sent to platform administrators.'
));

function statusClass(status) {
    return status === 'resolved'
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-800';
}

async function loadReports(page = 1) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/reports/data', { params: { page } });

        categories.value = response.data.categories ?? [];
        programs.value = response.data.programs ?? [];
        reports.value = response.data.reports ?? [];
        pagination.value = response.data.pagination ?? pagination.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load your reports.';
    } finally {
        isLoading.value = false;
    }
}

async function submitReport() {
    formError.value = '';
    isSubmitting.value = true;

    try {
        await window.axios.post('/dashboard/reports', {
            category: form.value.category,
            scholarship_id: isProgramConcern.value ? form.value.scholarshipId : null,
            subject: form.value.subject,
            description: form.value.description,
        });

        form.value = {
            category: '',
            scholarshipId: '',
            subject: '',
            description: '',
        };
        await loadReports(1);
    } catch (error) {
        const validationErrors = error.response?.data?.errors ?? {};

        formError.value = Object.values(validationErrors).flat()[0]
            ?? error.response?.data?.message
            ?? 'Unable to send your report.';
    } finally {
        isSubmitting.value = false;
    }
}

onMounted(() => loadReports());
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <ApplicantPageHeader
                    eyebrow="Support"
                    title="Report a problem"
                    description="Tell the right team when something in the portal or a scholarship program is not working as expected."
                    icon="fa-solid fa-circle-exclamation"
                />

                <div v-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 grid items-start gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                    <form class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" @submit.prevent="submitReport">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">New report</p>
                            <h2 class="mt-1 text-xl font-bold text-slate-950">What went wrong?</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">
                                Keep it brief and do not include passwords or other sensitive information.
                            </p>
                        </div>

                        <div class="mt-5 grid gap-4">
                            <div>
                                <label for="report-category" class="mb-2 block text-sm font-semibold text-slate-700">Concern type</label>
                                <select
                                    id="report-category"
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
                                <label for="report-program" class="mb-2 block text-sm font-semibold text-slate-700">Related program</label>
                                <select
                                    id="report-program"
                                    v-model="form.scholarshipId"
                                    required
                                    class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                >
                                    <option value="" disabled>Select a scholarship program</option>
                                    <option v-for="program in programs" :key="program.id" :value="program.id">
                                        {{ program.title }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label for="report-subject" class="mb-2 block text-sm font-semibold text-slate-700">Short title</label>
                                <input
                                    id="report-subject"
                                    v-model="form.subject"
                                    type="text"
                                    required
                                    maxlength="150"
                                    placeholder="Example: Application button is not working"
                                    class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                >
                            </div>

                            <div>
                                <label for="report-description" class="mb-2 block text-sm font-semibold text-slate-700">What happened?</label>
                                <textarea
                                    id="report-description"
                                    v-model="form.description"
                                    required
                                    rows="5"
                                    maxlength="2000"
                                    placeholder="Briefly describe the problem and where you encountered it."
                                    class="w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                                ></textarea>
                            </div>
                        </div>

                        <p class="mt-4 rounded-md bg-slate-50 px-3 py-2 text-xs font-semibold leading-5 text-slate-600">
                            {{ form.category ? reportDestination : 'Choose a concern type so the report goes to the correct team.' }}
                        </p>
                        <p v-if="formError" class="mt-3 text-sm font-semibold text-rose-700">{{ formError }}</p>

                        <button
                            type="submit"
                            :disabled="isSubmitting"
                            class="mt-4 w-full rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {{ isSubmitting ? 'Sending...' : 'Send report' }}
                        </button>
                    </form>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 p-5">
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Your reports</p>
                            <h2 class="mt-1 text-xl font-bold text-slate-950">Submitted concerns</h2>
                            <p class="mt-2 text-sm text-slate-500">{{ pagination.total }} total reports</p>
                        </div>

                        <div v-if="isLoading" class="p-6 text-sm text-slate-500">Loading reports...</div>

                        <div v-else-if="reports.length" class="divide-y divide-slate-100">
                            <article v-for="report in reports" :key="report.id" class="p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{{ report.category_label }}</p>
                                        <h3 class="mt-1 font-bold text-slate-950">{{ report.subject }}</h3>
                                    </div>
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', statusClass(report.status)]">
                                        {{ report.status_label }}
                                    </span>
                                </div>

                                <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-600">{{ report.description }}</p>
                                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                    <span>{{ report.program?.title || report.sent_to }}</span>
                                    <span>{{ report.created_at }}</span>
                                </div>
                            </article>
                        </div>

                        <div v-else class="p-8 text-center">
                            <p class="font-bold text-slate-900">No reports submitted</p>
                            <p class="mt-1 text-sm text-slate-500">Your submitted concerns will appear here.</p>
                        </div>

                        <div v-if="pagination.last_page > 1" class="flex items-center justify-between border-t border-slate-200 p-4">
                            <button
                                type="button"
                                :disabled="pagination.current_page <= 1"
                                class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-40"
                                @click="loadReports(pagination.current_page - 1)"
                            >
                                Previous
                            </button>
                            <span class="text-xs font-semibold text-slate-500">
                                Page {{ pagination.current_page }} of {{ pagination.last_page }}
                            </span>
                            <button
                                type="button"
                                :disabled="pagination.current_page >= pagination.last_page"
                                class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold disabled:opacity-40"
                                @click="loadReports(pagination.current_page + 1)"
                            >
                                Next
                            </button>
                        </div>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
