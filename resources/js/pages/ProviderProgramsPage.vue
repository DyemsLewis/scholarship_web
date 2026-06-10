<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const formMessage = ref('');
const formError = ref('');
const editingId = ref(null);
const user = ref(null);
const stats = ref({
    scholarships: 0,
    applications: 0,
    drafts: 0,
});
const scholarships = ref([]);
const scholarshipFormElement = ref(null);
const scholarshipForm = ref(emptyScholarshipForm());

const labelClass = 'mb-2 block text-sm font-semibold text-slate-700';
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-3 focus:ring-emerald-100';

const programStats = computed(() => [
    {
        label: 'Total Programs',
        value: stats.value.scholarships,
        className: 'text-sky-700',
    },
    {
        label: 'Drafts',
        value: stats.value.drafts,
        className: 'text-amber-600',
    },
    {
        label: 'Published',
        value: scholarships.value.filter((scholarship) => scholarship.status === 'published').length,
        className: 'text-emerald-700',
    },
]);

function emptyScholarshipForm() {
    return {
        title: '',
        description: '',
        eligibility: '',
        requirements: '',
        awardAmount: '',
        deadline: '',
        status: 'draft',
    };
}

function statusLabel(status) {
    return String(status ?? 'draft')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

function formatAmount(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return 'Not set';
    }

    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(Number(amount));
}

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/profile');

        user.value = response.data.user;
        stats.value = response.data.stats;
        scholarships.value = response.data.scholarships;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship programs.';
    } finally {
        isLoading.value = false;
    }
}

function resetScholarshipForm() {
    editingId.value = null;
    scholarshipForm.value = emptyScholarshipForm();
    formMessage.value = '';
    formError.value = '';
}

function editScholarship(scholarship) {
    editingId.value = scholarship.id;
    formMessage.value = '';
    formError.value = '';
    scholarshipForm.value = {
        title: scholarship.title ?? '',
        description: scholarship.description ?? '',
        eligibility: scholarship.eligibility ?? '',
        requirements: scholarship.requirements ?? '',
        awardAmount: scholarship.award_amount ?? '',
        deadline: scholarship.deadline ?? '',
        status: scholarship.status ?? 'draft',
    };

    scholarshipFormElement.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function saveScholarship() {
    formMessage.value = '';
    formError.value = '';

    if (!scholarshipFormElement.value?.reportValidity()) {
        return;
    }

    isSaving.value = true;

    const payload = {
        title: scholarshipForm.value.title,
        description: scholarshipForm.value.description,
        eligibility: scholarshipForm.value.eligibility,
        requirements: scholarshipForm.value.requirements,
        award_amount: scholarshipForm.value.awardAmount || null,
        deadline: scholarshipForm.value.deadline || null,
        status: scholarshipForm.value.status,
    };

    try {
        const response = editingId.value
            ? await window.axios.put(`/provider/scholarships/${editingId.value}`, payload)
            : await window.axios.post('/provider/scholarships', payload);

        const message = response.data.message ?? 'Scholarship saved successfully.';
        resetScholarshipForm();
        formMessage.value = message;
        await loadProviderData();
    } catch (error) {
        formError.value = error.response?.data?.message ?? 'Unable to save scholarship.';
    } finally {
        isSaving.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                                Scholarship Programs
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Create and edit programs
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Manage scholarship details, award amounts, deadlines, and publishing status from this page.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Provider
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.provider_name || user?.name || 'Provider' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading scholarship programs...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div class="grid gap-4 md:grid-cols-3">
                        <article
                            v-for="item in programStats"
                            :key="item.label"
                            class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
                        >
                            <p class="text-sm font-semibold text-slate-500">
                                {{ item.label }}
                            </p>
                            <p :class="['mt-3 font-display text-3xl font-bold', item.className]">
                                {{ item.value }}
                            </p>
                        </article>
                    </div>

                    <form
                        ref="scholarshipFormElement"
                        class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
                        @submit.prevent="saveScholarship"
                    >
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            {{ editingId ? 'Edit Scholarship' : 'Create Scholarship' }}
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            {{ editingId ? 'Update scholarship program' : 'Add scholarship program' }}
                        </h3>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            Save as draft while preparing details, publish when ready, or close when applications should stop.
                        </p>

                        <div class="mt-5 grid gap-4">
                            <div>
                                <label :class="labelClass" for="scholarship-title">
                                    Scholarship title
                                </label>
                                <input
                                    id="scholarship-title"
                                    v-model="scholarshipForm.title"
                                    type="text"
                                    required
                                    placeholder="Scholarship title"
                                    :class="inputClass"
                                >
                            </div>

                            <div class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label :class="labelClass" for="scholarship-amount">
                                        Award amount
                                    </label>
                                    <input
                                        id="scholarship-amount"
                                        v-model="scholarshipForm.awardAmount"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="0.00"
                                        :class="inputClass"
                                    >
                                </div>

                                <div>
                                    <label :class="labelClass" for="scholarship-deadline">
                                        Deadline
                                    </label>
                                    <input
                                        id="scholarship-deadline"
                                        v-model="scholarshipForm.deadline"
                                        type="date"
                                        :class="inputClass"
                                    >
                                </div>

                                <div>
                                    <label :class="labelClass" for="scholarship-status">
                                        Status
                                    </label>
                                    <select
                                        id="scholarship-status"
                                        v-model="scholarshipForm.status"
                                        required
                                        :class="inputClass"
                                    >
                                        <option value="draft">
                                            Draft
                                        </option>
                                        <option value="published">
                                            Published
                                        </option>
                                        <option value="closed">
                                            Closed
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label :class="labelClass" for="scholarship-description">
                                    Description
                                </label>
                                <textarea
                                    id="scholarship-description"
                                    v-model="scholarshipForm.description"
                                    required
                                    rows="4"
                                    placeholder="Describe the scholarship program"
                                    :class="inputClass"
                                ></textarea>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label :class="labelClass" for="scholarship-eligibility">
                                        Eligibility
                                    </label>
                                    <textarea
                                        id="scholarship-eligibility"
                                        v-model="scholarshipForm.eligibility"
                                        rows="4"
                                        placeholder="Who can apply?"
                                        :class="inputClass"
                                    ></textarea>
                                </div>

                                <div>
                                    <label :class="labelClass" for="scholarship-requirements">
                                        Requirements
                                    </label>
                                    <textarea
                                        id="scholarship-requirements"
                                        v-model="scholarshipForm.requirements"
                                        rows="4"
                                        placeholder="Documents or steps required"
                                        :class="inputClass"
                                    ></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-h-5">
                                <p v-if="formMessage" class="text-sm font-semibold text-emerald-700">
                                    {{ formMessage }}
                                </p>
                                <p v-if="formError" class="text-sm font-semibold text-rose-700">
                                    {{ formError }}
                                </p>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button
                                    type="button"
                                    class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    @click="resetScholarshipForm"
                                >
                                    {{ editingId ? 'Cancel edit' : 'Clear' }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="isSaving"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-80"
                                >
                                    {{ isSaving ? 'Saving...' : editingId ? 'Update scholarship' : 'Create scholarship' }}
                                </button>
                            </div>
                        </div>
                    </form>

                    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 p-4">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                Saved Programs
                            </p>
                            <h3 class="mt-1 text-lg font-bold text-slate-950">
                                Created scholarships
                            </h3>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ scholarships.length }} program{{ scholarships.length === 1 ? '' : 's' }} saved by this provider.
                            </p>
                        </div>

                        <div v-if="scholarships.length === 0" class="p-6 text-sm text-slate-500">
                            No scholarships yet. Create your first scholarship program above.
                        </div>

                        <div v-else class="grid gap-4 p-4 lg:grid-cols-2">
                            <article
                                v-for="scholarship in scholarships"
                                :key="scholarship.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <span :class="['inline-flex rounded-md px-2 py-1 text-xs font-bold uppercase', statusClass(scholarship.status)]">
                                            {{ statusLabel(scholarship.status) }}
                                        </span>
                                        <h4 class="mt-3 text-lg font-bold text-slate-950">
                                            {{ scholarship.title }}
                                        </h4>
                                    </div>

                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-white"
                                        @click="editScholarship(scholarship)"
                                    >
                                        Edit
                                    </button>
                                </div>

                                <p class="mt-3 line-clamp-3 text-sm leading-6 text-slate-600">
                                    {{ scholarship.description }}
                                </p>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Award
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ formatAmount(scholarship.award_amount) }}
                                        </p>
                                    </div>

                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">
                                            Deadline
                                        </p>
                                        <p class="mt-1 font-bold text-slate-950">
                                            {{ scholarship.deadline || 'Not set' }}
                                        </p>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
