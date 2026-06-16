<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const updatingId = ref(null);
const errorMessage = ref('');
const statusMessage = ref('');
const selectedStatus = ref('all');
const providerNotes = ref({});
const stats = ref({
    providers: 0,
    pending_providers: 0,
    approved_providers: 0,
    rejected_providers: 0,
    recent_applications: 0,
});
const providers = ref([]);
const applications = ref([]);

const filteredProviders = computed(() => {
    if (selectedStatus.value === 'all') {
        return providers.value;
    }

    return providers.value.filter((provider) => provider.verification_status === selectedStatus.value);
});
const statusFilters = computed(() => [
    { value: 'all', label: 'All providers', count: stats.value.providers },
    { value: 'pending', label: 'Pending', count: stats.value.pending_providers },
    { value: 'approved', label: 'Approved', count: stats.value.approved_providers },
    { value: 'rejected', label: 'Rejected', count: stats.value.rejected_providers },
]);

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function statusLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

async function loadReviewData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/reviews/data');

        stats.value = response.data.stats;
        providers.value = response.data.providers;
        applications.value = response.data.applications;
        providerNotes.value = Object.fromEntries(
            providers.value.map((provider) => [provider.id, provider.verification_notes ?? '']),
        );
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load review details.';
    } finally {
        isLoading.value = false;
    }
}

async function updateProvider(provider, verificationStatus) {
    updatingId.value = provider.id;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.patch(`/admin/providers/${provider.id}/verification`, {
            verification_status: verificationStatus,
            verification_notes: providerNotes.value[provider.id] ?? '',
        });

        providers.value = providers.value.map((item) => (item.id === provider.id ? response.data.provider : item));
        statusMessage.value = response.data.message ?? 'Provider verification updated.';
        await loadReviewData();
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to update provider verification.';
    } finally {
        updatingId.value = null;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadReviewData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="reviews" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
                                Reviews
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Provider and application review
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Approve scholarship providers before they publish programs and monitor application review activity.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                            @click="loadReviewData"
                        >
                            Refresh Reviews
                        </button>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading review details...
                </div>

                <div v-else class="mt-6 space-y-6">
                    <p v-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 shadow-sm">
                        {{ errorMessage }}
                    </p>
                    <p v-if="statusMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-700 shadow-sm">
                        {{ statusMessage }}
                    </p>

                    <div class="grid gap-4 md:grid-cols-4">
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">Providers</p>
                            <p class="mt-3 font-display text-3xl font-bold text-slate-950">{{ stats.providers }}</p>
                        </article>
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">Pending</p>
                            <p class="mt-3 font-display text-3xl font-bold text-amber-600">{{ stats.pending_providers }}</p>
                        </article>
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">Approved</p>
                            <p class="mt-3 font-display text-3xl font-bold text-emerald-700">{{ stats.approved_providers }}</p>
                        </article>
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">Recent Apps</p>
                            <p class="mt-3 font-display text-3xl font-bold text-sky-700">{{ stats.recent_applications }}</p>
                        </article>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 p-5">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Provider Verification
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Approve scholarship providers
                            </h3>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button
                                    v-for="filter in statusFilters"
                                    :key="filter.value"
                                    type="button"
                                    :class="[
                                        'rounded-md border px-3 py-2 text-sm font-semibold transition',
                                        selectedStatus === filter.value
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                                    ]"
                                    @click="selectedStatus = filter.value"
                                >
                                    {{ filter.label }} ({{ filter.count }})
                                </button>
                            </div>
                        </div>

                        <div v-if="filteredProviders.length === 0" class="p-6 text-sm text-slate-500">
                            No providers for this filter.
                        </div>

                        <div v-else class="grid gap-4 p-4 xl:grid-cols-2">
                            <article
                                v-for="provider in filteredProviders"
                                :key="provider.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-700">
                                            {{ provider.provider_type || 'provider' }}
                                        </p>
                                        <h4 class="mt-2 text-lg font-bold text-slate-950">
                                            {{ provider.provider_name || provider.name }}
                                        </h4>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ provider.email }}
                                        </p>
                                    </div>
                                    <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(provider.verification_status)]">
                                        {{ statusLabel(provider.verification_status) }}
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">Website</p>
                                        <p class="mt-1 break-words text-slate-700">{{ provider.provider_website || 'Not provided' }}</p>
                                    </div>
                                    <div class="rounded-md bg-white p-3">
                                        <p class="font-semibold text-slate-500">Address</p>
                                        <p class="mt-1 text-slate-700">{{ provider.provider_address || 'Not provided' }}</p>
                                    </div>
                                </div>

                                <label class="mt-4 block text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                                    Admin note
                                </label>
                                <textarea
                                    v-model="providerNotes[provider.id]"
                                    rows="3"
                                    maxlength="1500"
                                    placeholder="Optional note for provider verification."
                                    class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                ></textarea>

                                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                                    <button
                                        type="button"
                                        :disabled="updatingId === provider.id"
                                        class="rounded-md bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-800 disabled:opacity-70"
                                        @click="updateProvider(provider, 'approved')"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="updatingId === provider.id"
                                        class="rounded-md bg-rose-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-rose-800 disabled:opacity-70"
                                        @click="updateProvider(provider, 'rejected')"
                                    >
                                        Reject
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="updatingId === provider.id"
                                        class="rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-white disabled:opacity-70"
                                        @click="updateProvider(provider, 'pending')"
                                    >
                                        Mark pending
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Application Review Snapshot
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Recent submitted applications
                        </h3>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applications yet.
                        </div>

                        <div v-else class="mt-5 grid gap-3">
                            <div
                                v-for="application in applications"
                                :key="application.id"
                                class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 text-sm lg:grid-cols-[1fr_10rem_8rem]"
                            >
                                <div>
                                    <p class="font-bold text-slate-950">{{ application.scholarship }}</p>
                                    <p class="mt-1 text-slate-500">
                                        {{ application.applicant }} · {{ application.provider }}
                                    </p>
                                    <p v-if="application.review_notes" class="mt-2 text-slate-600">
                                        Note: {{ application.review_notes }}
                                    </p>
                                </div>
                                <span :class="['h-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(application.status)]">
                                    {{ statusLabel(application.status) }}
                                </span>
                                <p class="text-slate-500 lg:text-right">
                                    {{ application.documents_uploaded }} files
                                </p>
                            </div>
                        </div>
                    </section>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
