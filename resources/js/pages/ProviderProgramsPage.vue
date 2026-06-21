<script setup>
import { computed, onMounted, ref } from 'vue';
import LeafletMapPreview from '../components/LeafletMapPreview.vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const selectedMapScholarship = ref(null);

const canPostScholarships = computed(() => user.value?.can_post_scholarships);
const selectedMapAddress = computed(() => {
    const parts = [
        selectedMapScholarship.value?.location_address,
        selectedMapScholarship.value?.location_name,
    ].filter(Boolean);

    return parts.length ? [...parts, 'Philippines'].join(', ') : '';
});

function openMapModal(scholarship) {
    selectedMapScholarship.value = scholarship;
}

function closeMapModal() {
    selectedMapScholarship.value = null;
}

function hasScholarshipMapPreview(scholarship) {
    return Boolean(
        (scholarship.latitude && scholarship.longitude)
        || scholarship.location_address
        || scholarship.location_name,
    );
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
        const response = await window.axios.get('/provider/profile/data');

        user.value = response.data.user;
        scholarships.value = response.data.scholarships;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load scholarship programs.';
    } finally {
        isLoading.value = false;
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
                                Program directory
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Review saved scholarship programs and open a focused page when you need to create or edit details.
                            </p>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row">
                            <a
                                href="/provider/programs/create"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Create program
                            </a>
                            <a
                                href="/provider"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                            >
                                Back to Dashboard
                            </a>
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
                    <div
                        v-if="!canPostScholarships"
                        class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm"
                    >
                        <p class="font-bold">
                            Provider verification required
                        </p>
                        <p class="mt-1 leading-6">
                            Your provider account is currently {{ user?.verification_status || 'pending' }}. An admin must approve the provider account before scholarships can be created or updated.
                        </p>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
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

                            <a
                                href="/provider/programs/create"
                                class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                            >
                                New program
                            </a>
                        </div>

                        <div v-if="scholarships.length === 0" class="p-6 text-sm text-slate-500">
                            No scholarships yet. Create your first scholarship program from the Create program page.
                        </div>

                        <div v-else class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                            <article
                                v-for="scholarship in scholarships"
                                :key="scholarship.id"
                                class="flex flex-col rounded-lg border border-slate-200 bg-slate-50 p-3"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h4 class="truncate text-base font-bold text-slate-950">
                                            {{ scholarship.title }}
                                        </h4>
                                        <p class="mt-1 truncate text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                            {{ scholarship.category || 'Uncategorized' }}
                                        </p>
                                    </div>

                                    <span :class="['shrink-0 rounded-md px-2 py-1 text-[11px] font-bold uppercase', statusClass(scholarship.status)]">
                                        {{ statusLabel(scholarship.status) }}
                                    </span>
                                </div>

                                <p class="mt-3 line-clamp-2 text-sm leading-5 text-slate-600">
                                    {{ scholarship.description }}
                                </p>

                                <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-md bg-white px-3 py-2">
                                        <p class="font-semibold text-slate-500">
                                            Award
                                        </p>
                                        <p class="mt-1 truncate font-bold text-slate-950">
                                            {{ formatAmount(scholarship.award_amount) }}
                                        </p>
                                    </div>

                                    <div class="rounded-md bg-white px-3 py-2">
                                        <p class="font-semibold text-slate-500">
                                            Deadline
                                        </p>
                                        <p class="mt-1 truncate font-bold text-slate-950">
                                            {{ scholarship.deadline || 'Not set' }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-bold text-slate-600">
                                    <span class="rounded-md bg-white px-2.5 py-1">
                                        GWA {{ scholarship.minimum_gwa || 'Any' }}
                                    </span>
                                    <span class="rounded-md bg-white px-2.5 py-1">
                                        {{ scholarship.bookmarks_count || 0 }} saved
                                    </span>
                                </div>

                                <div
                                    v-if="scholarship.location_name || scholarship.location_address"
                                    class="mt-3 rounded-md border border-sky-100 bg-white px-3 py-2 text-xs text-slate-600"
                                >
                                    <p class="truncate font-bold text-slate-800">
                                        {{ scholarship.location_name || 'Map location' }}
                                    </p>
                                    <p class="mt-1 line-clamp-1">
                                        {{ scholarship.location_address || 'No address added yet.' }}
                                    </p>
                                </div>

                                <div :class="[
                                    'mt-auto grid gap-2 border-t border-slate-200 pt-3',
                                    hasScholarshipMapPreview(scholarship) ? 'grid-cols-2' : 'grid-cols-1',
                                ]">
                                    <a
                                        :href="`/provider/programs/${scholarship.id}/edit`"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                    >
                                        Edit
                                    </a>

                                    <button
                                        v-if="hasScholarshipMapPreview(scholarship)"
                                        type="button"
                                        class="rounded-md border border-sky-200 bg-white px-3 py-2 text-sm font-bold text-sky-700 transition hover:bg-sky-50"
                                        @click="openMapModal(scholarship)"
                                    >
                                        Map
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>

        <div
            v-if="selectedMapScholarship"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-6"
            @click.self="closeMapModal"
        >
            <section class="max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-lg bg-white shadow-2xl">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-700">
                            Provider Map Preview
                        </p>
                        <h3 class="mt-1 text-xl font-bold text-slate-950">
                            {{ selectedMapScholarship.location_name || selectedMapScholarship.title }}
                        </h3>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            {{ selectedMapScholarship.location_address || 'No map address added yet.' }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        @click="closeMapModal"
                    >
                        Close
                    </button>
                </div>

                <div class="bg-slate-100 p-4">
                    <LeafletMapPreview
                        :address="selectedMapAddress"
                        :latitude="selectedMapScholarship.latitude"
                        :longitude="selectedMapScholarship.longitude"
                        :title="selectedMapScholarship.location_name || selectedMapScholarship.title"
                        :marker-text="selectedMapScholarship.location_name || selectedMapScholarship.title"
                        height="55vh"
                        auto-geocode
                    />
                </div>

                <div class="flex flex-col gap-2 border-t border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-5 text-slate-500">
                        This Leaflet/OpenStreetMap preview is similar to what students will see when browsing scholarship locations.
                    </p>
                    <a
                        v-if="selectedMapScholarship.map_url"
                        :href="selectedMapScholarship.map_url"
                        target="_blank"
                        rel="noreferrer"
                        class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                    >
                        Open Full Map
                    </a>
                </div>
            </section>
        </div>
    </main>
</template>
