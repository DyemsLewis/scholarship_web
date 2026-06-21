<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const updatingId = ref(null);
const errorMessage = ref('');
const statusMessage = ref('');
const selectedStatus = ref('pending');
const providerNotes = ref({});
const hiddenProviderIds = ref([]);
const stats = ref({
    providers: 0,
    pending_providers: 0,
    approved_providers: 0,
    rejected_providers: 0,
    recent_applications: 0,
    average_match_score: 0,
    average_dss_score: 0,
    pending_documents: 0,
});
const providers = ref([]);
const applications = ref([]);

const filteredProviders = computed(() => {
    const visibleProviders = providers.value.filter((provider) => !hiddenProviderIds.value.includes(provider.id));

    if (selectedStatus.value === 'all') {
        return visibleProviders;
    }

    return visibleProviders.filter((provider) => provider.verification_status === selectedStatus.value);
});
const statusFilters = computed(() => [
    { value: 'pending', label: 'Pending', count: stats.value.pending_providers },
    { value: 'approved', label: 'Approved', count: stats.value.approved_providers },
    { value: 'rejected', label: 'Rejected', count: stats.value.rejected_providers },
    { value: 'all', label: 'All providers', count: stats.value.providers },
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

function documentStatusClass(status) {
    if (status === 'accepted') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'needs_replacement') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-sky-100 text-sky-800';
}

function statusLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatFileSize(size) {
    if (!size) {
        return '0 KB';
    }

    return `${Math.max(1, Math.round(Number(size) / 1024))} KB`;
}

function providerActionOptions(provider) {
    const status = provider.verification_status ?? 'pending';
    const actions = [];

    if (status !== 'approved') {
        actions.push({
            status: 'approved',
            label: 'Approve provider',
            className: 'bg-emerald-700 text-white hover:bg-emerald-800',
        });
    }

    if (status !== 'rejected') {
        actions.push({
            status: 'rejected',
            label: 'Reject',
            className: 'bg-rose-700 text-white hover:bg-rose-800',
        });
    }

    if (status !== 'pending') {
        actions.push({
            status: 'pending',
            label: 'Move to pending',
            className: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
        });
    }

    return actions;
}

async function loadReviewData(resetHiddenProviders = true) {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/reviews/data');

        if (resetHiddenProviders) {
            hiddenProviderIds.value = [];
        }

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
    if ((provider.verification_status ?? 'pending') === verificationStatus) {
        return;
    }

    updatingId.value = provider.id;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        const response = await window.axios.patch(`/admin/providers/${provider.id}/verification`, {
            verification_status: verificationStatus,
            verification_notes: providerNotes.value[provider.id] ?? '',
        });

        providers.value = providers.value.map((item) => (item.id === provider.id ? response.data.provider : item));
        hiddenProviderIds.value = [...new Set([...hiddenProviderIds.value, provider.id])];
        statusMessage.value = response.data.message ?? `Provider marked as ${statusLabel(verificationStatus)}.`;
        await loadReviewData(false);
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
                                        'rounded-md border px-3 py-2 text-xs font-bold uppercase tracking-[0.08em] transition',
                                        selectedStatus === filter.value
                                            ? 'border-slate-900 bg-slate-900 text-white'
                                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                                    ]"
                                    @click="selectedStatus = filter.value"
                                >
                                    {{ filter.label }}
                                </button>
                            </div>
                        </div>

                        <div v-if="filteredProviders.length === 0" class="p-6 text-sm text-slate-500">
                            No providers for this filter. Updated providers are hidden until you refresh.
                        </div>

                        <div v-else class="grid gap-3 p-3 md:grid-cols-2 xl:grid-cols-3">
                            <article
                                v-for="provider in filteredProviders"
                                :key="provider.id"
                                class="rounded-md border border-slate-200 bg-slate-50 p-3"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-bold uppercase tracking-[0.14em] text-sky-700">
                                            {{ provider.provider_type || 'provider' }}
                                        </p>
                                        <h4 class="mt-1 truncate text-base font-bold text-slate-950">
                                            {{ provider.provider_name || provider.name }}
                                        </h4>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">
                                            {{ provider.email }}
                                        </p>
                                    </div>
                                    <span :class="['shrink-0 rounded-md px-2 py-1 text-[10px] font-bold uppercase', statusClass(provider.verification_status)]">
                                        {{ statusLabel(provider.verification_status) }}
                                    </span>
                                </div>

                                <div class="mt-3 space-y-1.5 text-xs text-slate-600">
                                    <p class="truncate">
                                        <span class="font-semibold text-slate-500">Website:</span>
                                        {{ provider.provider_website || 'Not provided' }}
                                    </p>
                                    <p class="line-clamp-1">
                                        <span class="font-semibold text-slate-500">Address:</span>
                                        {{ provider.provider_address || 'Not provided' }}
                                    </p>
                                </div>

                                <div class="mt-3 rounded-md border border-slate-200 bg-white p-2.5">
                                    <label class="block text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">
                                        Admin note
                                    </label>
                                    <textarea
                                        v-model="providerNotes[provider.id]"
                                        rows="1"
                                        maxlength="1500"
                                        placeholder="Optional note for this provider."
                                        class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-sky-600 focus:ring-3 focus:ring-sky-100"
                                    ></textarea>
                                </div>

                                <div class="mt-3 border-t border-slate-200 pt-3">
                                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-500">
                                        Verification action
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button
                                            v-for="action in providerActionOptions(provider)"
                                            :key="action.status"
                                            type="button"
                                            :disabled="updatingId === provider.id"
                                            :class="[
                                                'rounded-md px-3 py-2 text-xs font-bold transition disabled:cursor-not-allowed disabled:opacity-70',
                                                action.className,
                                            ]"
                                            @click="updateProvider(provider, action.status)"
                                        >
                                            {{ updatingId === provider.id ? 'Saving...' : action.label }}
                                        </button>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Application Review Snapshot
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Recent submitted applications
                        </h3>

                        <div v-if="applications.length === 0" class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            No applications yet.
                        </div>

                        <div v-else class="mt-4 grid gap-2 md:grid-cols-2">
                            <div
                                v-for="application in applications"
                                :key="application.id"
                                class="grid gap-2 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm"
                            >
                                <div class="flex gap-3">
                                    <img
                                        :src="application.scholarship_image_url || '/uploads/scholarship-default.jpg'"
                                        :alt="application.scholarship || 'Scholarship'"
                                        class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200"
                                    >
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-slate-950">{{ application.scholarship }}</p>
                                        <p class="mt-1 truncate text-xs text-slate-500">
                                            {{ application.applicant }} - {{ application.provider }}
                                        </p>
                                        <p v-if="application.review_notes" class="mt-1 line-clamp-1 text-xs text-slate-500">
                                            Note: {{ application.review_notes }}
                                        </p>
                                        <p v-if="application.decision_reason" class="mt-1 truncate text-xs text-slate-500">
                                            Reason: {{ statusLabel(application.decision_reason) }}
                                        </p>
                                        <p class="mt-2 inline-flex w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-indigo-700 ring-1 ring-indigo-100">
                                            DSS: {{ application.dss_score ?? 0 }}% - {{ statusLabel(application.dss_recommendation || 'needs_review') }}
                                        </p>
                                    </div>
                                </div>
                                <span :class="['h-fit w-fit rounded-md px-2.5 py-1 text-[11px] font-bold uppercase', statusClass(application.status)]">
                                    {{ statusLabel(application.status) }}
                                </span>
                                <p class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                    {{ application.eligibility_score ?? 0 }}% match
                                </p>
                                <p class="w-fit rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                                    {{ application.documents_uploaded }} files
                                    <span v-if="application.documents_pending">({{ application.documents_pending }} pending)</span>
                                </p>
                                <div v-if="application.documents?.length" class="mt-1 grid gap-2">
                                    <div
                                        v-for="document in application.documents"
                                        :key="document.id"
                                        class="flex flex-col gap-2 rounded-md border border-slate-200 bg-white p-2.5 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-bold text-slate-950">
                                                {{ document.document_name }}
                                            </p>
                                            <p class="mt-0.5 truncate text-[11px] text-slate-500">
                                                {{ document.original_name }} - {{ formatFileSize(document.size) }}
                                            </p>
                                            <p v-if="document.review_notes" class="mt-1 line-clamp-1 text-[11px] font-semibold text-amber-700">
                                                Note: {{ document.review_notes }}
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                                            <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', documentStatusClass(document.status)]">
                                                {{ statusLabel(document.status || 'pending') }}
                                            </span>
                                            <a
                                                :href="document.download_url"
                                                class="rounded-md border border-slate-300 px-2.5 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-100"
                                            >
                                                Download
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="mt-1 rounded-md border border-dashed border-slate-300 bg-white p-2.5 text-xs text-slate-500">
                                    No uploaded documents yet.
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
