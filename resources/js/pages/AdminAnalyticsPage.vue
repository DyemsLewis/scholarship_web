<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const stats = ref({});
const monthlyApplications = ref([]);
const providerPerformance = ref([]);
const coverageSummary = ref([]);
const dssRecommendations = ref({});
const decisionReasons = ref([]);
const dssAudit = ref({
    average_score: 0,
    provider_decisions: {},
});

const dssItems = computed(() => [
    { label: 'Highly recommended', value: dssRecommendations.value.highly_recommended ?? 0 },
    { label: 'Recommended', value: dssRecommendations.value.recommended ?? 0 },
    { label: 'Needs review', value: dssRecommendations.value.needs_review ?? 0 },
    { label: 'Not recommended', value: dssRecommendations.value.not_recommended ?? 0 },
]);
const maxMonthlyApplications = computed(() => Math.max(1, ...monthlyApplications.value.map((month) => Number(month.total ?? 0))));
const maxCoveragePrograms = computed(() => Math.max(1, ...coverageSummary.value.map((coverage) => Number(coverage.programs ?? 0))));

function barWidth(value, max) {
    const numericValue = Number(value ?? 0);

    if (numericValue === 0) {
        return '0%';
    }

    return `${Math.max(8, Math.round((numericValue / max) * 100))}%`;
}

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

async function loadAnalytics() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/analytics');

        stats.value = response.data.stats;
        monthlyApplications.value = response.data.monthly_applications ?? [];
        providerPerformance.value = response.data.provider_performance ?? [];
        coverageSummary.value = response.data.coverage_summary ?? [];
        dssRecommendations.value = response.data.dss_recommendations ?? {};
        decisionReasons.value = response.data.decision_reasons ?? [];
        dssAudit.value = response.data.dss_audit ?? dssAudit.value;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load platform analytics.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadAnalytics);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="analytics" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                        Platform Analytics
                    </p>
                    <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Reports for admin decisions
                    </h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Use this page for deeper reports: provider performance, location coverage, application trends, and DSS audit signals.
                    </p>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading analytics...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <section class="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                Application Trend
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Applications over time
                            </h3>
                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="month in monthlyApplications"
                                    :key="month.label"
                                    class="grid gap-2"
                                >
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-semibold text-slate-600">{{ month.label }}</span>
                                        <span class="font-bold text-slate-950">{{ month.total }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-slate-900" :style="{ width: barWidth(month.total, maxMonthlyApplications) }"></div>
                                    </div>
                                </div>
                            </div>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                Provider Performance
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Activity by provider
                            </h3>
                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="provider in providerPerformance.slice(0, 6)"
                                    :key="provider.id"
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4"
                                >
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="font-bold text-slate-950">{{ provider.name }}</p>
                                            <p class="mt-1 text-sm text-slate-500">
                                                {{ provider.programs }} programs - {{ provider.applications }} applications - DSS {{ provider.average_dss_score || 0 }}%
                                            </p>
                                        </div>
                                        <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(provider.status)]">
                                            {{ provider.status }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </section>

                    <section class="grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Scholarship Coverage
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Published programs by location
                            </h3>
                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="coverage in coverageSummary.slice(0, 8)"
                                    :key="coverage.location"
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4"
                                >
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-bold text-slate-950">{{ coverage.location }}</span>
                                        <span class="font-display text-xl font-bold text-slate-950">{{ coverage.programs }}</span>
                                    </div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-white">
                                        <div class="h-full rounded-full bg-amber-400" :style="{ width: barWidth(coverage.programs, maxCoveragePrograms) }"></div>
                                    </div>
                                    <p class="mt-2 text-xs text-slate-500">
                                        {{ coverage.applications }} applications, {{ coverage.saved_count }} saved programs
                                    </p>
                                </div>
                            </div>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-700">
                                DSS Audit
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Recommendation distribution
                            </h3>
                            <p class="mt-3 text-sm leading-6 text-slate-600">
                                Average DSS score: <span class="font-bold text-slate-950">{{ dssAudit.average_score || 0 }}%</span>
                            </p>
                            <div class="mt-5 flex flex-wrap gap-2">
                                <div
                                    v-for="item in dssItems"
                                    :key="item.label"
                                    class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm"
                                >
                                    <span class="font-semibold text-slate-600">{{ item.label }}:</span>
                                    <span class="font-bold text-slate-950">{{ item.value }}</span>
                                </div>
                            </div>

                            <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
                                <p class="text-sm font-bold text-slate-950">
                                    Common decision reasons
                                </p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span
                                        v-for="reason in decisionReasons.slice(0, 5)"
                                        :key="reason.reason"
                                        class="rounded-md bg-white px-2.5 py-1 text-xs font-bold text-slate-700 ring-1 ring-slate-200"
                                    >
                                        {{ reason.label }}: {{ reason.total }}
                                    </span>
                                    <span v-if="decisionReasons.length === 0" class="text-sm text-slate-500">
                                        No decision reasons recorded yet.
                                    </span>
                                </div>
                            </div>
                        </article>
                    </section>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
