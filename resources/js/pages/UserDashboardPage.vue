<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantDashboardOverview from '../components/ApplicantDashboardOverview.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const dashboard = ref({
    mode: 'onboarding',
    profile: { complete: false, percent: 0, missing_count: 0 },
    summary: { applications: 0, active_applications: 0, actions: 0, upcoming: 0 },
    primary_action: null,
    secondary_actions: [],
    applications: [],
    upcoming: [],
    recommendation: null,
    onboarding: [],
});

const firstName = computed(() => user.value?.first_name || 'Scholar');
const headerDescription = computed(() => ({
    onboarding: 'Complete the next step below to prepare your first application.',
    applying: 'See what needs your attention and what the provider is handling.',
    recipient: 'Keep track of your scholarship requirements and upcoming support.',
    discovery: 'You are up to date. Explore another opportunity when you are ready.',
}[dashboard.value.mode] || 'Continue from the most important next step below.'));

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/overview-data');

        user.value = response.data.user;
        dashboard.value = {
            ...dashboard.value,
            ...(response.data.dashboard ?? {}),
        };
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load your dashboard.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <ApplicantPageHeader
                    eyebrow="Applicant dashboard"
                    :title="`Welcome back, ${firstName}`"
                    :description="headerDescription"
                    icon="fa-solid fa-compass"
                />

                <div v-if="isLoading" class="mt-5 space-y-4" aria-live="polite" aria-label="Loading dashboard">
                    <div class="student-card animate-pulse p-5">
                        <div class="h-3 w-28 rounded bg-slate-200"></div>
                        <div class="mt-4 h-6 w-2/3 rounded bg-slate-200"></div>
                        <div class="mt-3 h-4 w-full max-w-2xl rounded bg-slate-100"></div>
                        <div class="mt-5 h-10 w-36 rounded bg-slate-200"></div>
                    </div>
                    <div class="student-card animate-pulse p-5">
                        <div class="h-5 w-44 rounded bg-slate-200"></div>
                        <div class="mt-5 h-14 rounded bg-slate-100"></div>
                        <div class="mt-3 h-14 rounded bg-slate-100"></div>
                    </div>
                </div>

                <section v-else-if="errorMessage" class="student-card mt-5 border-rose-200 p-5" role="alert">
                    <p class="text-sm font-bold text-slate-950">Your dashboard could not be loaded</p>
                    <p class="mt-1 text-sm text-slate-600">{{ errorMessage }}</p>
                    <button
                        type="button"
                        class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                        @click="loadDashboard"
                    >
                        Try again
                    </button>
                </section>

                <ApplicantDashboardOverview v-else :dashboard="dashboard" />
            </div>
        </section>
    </main>
</template>
