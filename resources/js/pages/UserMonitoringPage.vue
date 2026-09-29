<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const applications = ref([]);

const monitoringApplications = computed(() => applications.value.filter((application) => (
    application.recipient_monitoring?.eligible
)));
const pendingRequirementCount = computed(() => monitoringApplications.value.reduce(
    (total, application) => total + Number(application.recipient_monitoring?.pending_count ?? 0),
    0,
));
const upcomingReleaseCount = computed(() => monitoringApplications.value.reduce((total, application) => (
    total + (application.recipient_monitoring?.benefit_releases ?? [])
        .filter((release) => ['scheduled', 'prepared'].includes(release.status)).length
), 0));

function supportStatusClass(status) {
    if (status === 'active') return 'bg-emerald-100 text-emerald-800';
    if (status === 'renewed') return 'bg-sky-100 text-sky-800';
    if (status === 'terminated') return 'bg-rose-100 text-rose-800';
    if (status === 'completed') return 'bg-slate-200 text-slate-700';
    return 'bg-amber-100 text-amber-800';
}

function monitoringNextStep(application) {
    const monitoring = application.recipient_monitoring ?? {};
    const pendingCount = Number(monitoring.pending_count ?? 0);

    if (pendingCount > 0) {
        return {
            label: 'Action needed',
            title: `${pendingCount} requirement${pendingCount === 1 ? '' : 's'} ready`,
            className: 'text-amber-800',
            icon: 'fa-solid fa-arrow-up-from-bracket',
        };
    }

    const pendingCheckInReview = (monitoring.check_ins ?? []).find((checkIn) => (
        checkIn.requirements.some((requirement) => requirement.submission?.review_status === 'pending')
    ));
    const pendingReview = pendingCheckInReview
        ?? (monitoring.cycles ?? []).find((cycle) => cycle.submission?.review_status === 'pending');
    if (pendingReview) {
        return {
            label: 'Provider review',
            title: `${pendingReview.title} is being reviewed`,
            className: 'text-slate-700',
            icon: 'fa-regular fa-clock',
        };
    }

    const release = (monitoring.benefit_releases ?? []).find((item) => ['scheduled', 'prepared'].includes(item.status));
    if (release) {
        return {
            label: release.status === 'prepared' ? 'Benefit ready' : 'Upcoming release',
            title: release.release_label || release.title,
            className: 'text-sky-800',
            icon: release.status === 'prepared' ? 'fa-solid fa-box-open' : 'fa-regular fa-calendar-check',
        };
    }

    return {
        label: 'Up to date',
        title: 'No action needed right now',
        className: 'text-emerald-800',
        icon: 'fa-solid fa-circle-check',
    };
}

async function loadMonitoring() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/applications/data');
        applications.value = response.data.applications ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load recipient monitoring.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadMonitoring);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container max-w-6xl">
                <ApplicantPageHeader
                    eyebrow="Recipient monitoring"
                    title="Keep your scholarship on track"
                    description="Submit ongoing requirements and review benefit releases in one place."
                    icon="fa-solid fa-heart-pulse"
                />

                <div v-if="errorMessage" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
                    {{ errorMessage }}
                </div>

                <section v-if="!isLoading && monitoringApplications.length" class="mt-4 grid overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm sm:grid-cols-3">
                    <div class="border-b border-slate-200 px-4 py-3 sm:border-b-0 sm:border-r">
                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Scholarships monitored</p>
                        <p class="mt-1 text-xl font-bold text-slate-950">{{ monitoringApplications.length }}</p>
                    </div>
                    <div class="border-b border-slate-200 px-4 py-3 sm:border-b-0 sm:border-r">
                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Requirements due</p>
                        <p :class="['mt-1 text-xl font-bold', pendingRequirementCount ? 'text-amber-800' : 'text-slate-950']">{{ pendingRequirementCount }}</p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Upcoming releases</p>
                        <p class="mt-1 text-xl font-bold text-slate-950">{{ upcomingReleaseCount }}</p>
                    </div>
                </section>

                <section class="student-card mt-4 overflow-hidden rounded-md border-slate-300">
                    <header class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                        <div>
                            <h2 class="text-base font-bold text-slate-950">Your monitoring records</h2>
                            <p class="mt-1 text-xs text-slate-500">Open a scholarship to manage its current requirements and releases.</p>
                        </div>
                        <span v-if="!isLoading" class="shrink-0 rounded bg-white px-2.5 py-1 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                            {{ monitoringApplications.length }} record{{ monitoringApplications.length === 1 ? '' : 's' }}
                        </span>
                    </header>

                    <div v-if="isLoading" class="p-6 text-sm text-slate-500">Loading monitoring records...</div>

                    <div v-else-if="!monitoringApplications.length" class="p-6 text-center sm:p-8">
                        <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500">
                            <i class="fa-solid fa-heart-pulse" aria-hidden="true"></i>
                        </span>
                        <h3 class="mt-3 font-bold text-slate-950">No monitoring record yet</h3>
                        <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-500">Monitoring becomes available after you are selected and the recipient agreement is active.</p>
                        <a href="/dashboard/applications" class="mt-4 inline-flex rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">View applications</a>
                    </div>

                    <div v-else class="divide-y divide-slate-200">
                        <article v-for="application in monitoringApplications" :key="application.id" class="grid gap-4 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(14rem,0.55fr)_auto] sm:items-center sm:p-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <img :src="application.scholarship?.image_url || '/uploads/scholarship-default.jpg'" :alt="application.scholarship?.title || 'Scholarship'" class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1.5 ring-1 ring-slate-200">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="line-clamp-2 text-sm font-bold leading-5 text-slate-950">{{ application.scholarship?.title || 'Scholarship' }}</h3>
                                        <span :class="['rounded px-2 py-1 text-[9px] font-bold uppercase', supportStatusClass(application.recipient_monitoring?.support_status)]">{{ application.recipient_monitoring?.support_status_label }}</span>
                                    </div>
                                    <p class="mt-1 truncate text-xs text-slate-500">{{ application.scholarship?.provider?.name || 'Scholarship provider' }}</p>
                                </div>
                            </div>

                            <div class="flex min-w-0 items-start gap-2.5">
                                <i :class="[monitoringNextStep(application).icon, monitoringNextStep(application).className, 'mt-0.5 shrink-0 text-xs']" aria-hidden="true"></i>
                                <div class="min-w-0">
                                    <p :class="['text-[10px] font-bold uppercase tracking-[0.1em]', monitoringNextStep(application).className]">{{ monitoringNextStep(application).label }}</p>
                                    <p class="mt-1 line-clamp-2 text-sm font-semibold leading-5 text-slate-700">{{ monitoringNextStep(application).title }}</p>
                                </div>
                            </div>

                            <a :href="`/dashboard/monitoring/${application.id}`" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 sm:w-auto">
                                Open monitoring
                                <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i>
                            </a>
                        </article>
                    </div>
                </section>
            </div>
        </section>
    </main>
</template>
