<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ApplicantApplicationShell from '../components/application-detail/ApplicantApplicationShell.vue';
import ApplicationProgressRecord from '../components/application-detail/ApplicationProgressRecord.vue';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const detail = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');

const overview = computed(() => detail.value?.content ?? {});
const recordRows = computed(() => (overview.value.record ?? []).filter((row) => row.label !== 'Submitted'));

async function loadOverview() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/applications/${applicationId}/data`, {
            params: { view: 'summary' },
        });
        detail.value = response.data.detail;
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            ?? 'We could not load this application record. Please try again.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadOverview);
</script>

<template>
    <main v-if="isLoading" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <div class="h-5 w-36 animate-pulse rounded bg-slate-200"></div>
                <div class="mt-5 h-44 animate-pulse rounded-md border border-slate-200 bg-white"></div>
                <div class="mt-4 h-28 animate-pulse rounded-md border border-slate-200 bg-white"></div>
                <div class="mt-4 h-72 animate-pulse rounded-md border border-slate-200 bg-white"></div>
            </div>
        </section>
    </main>

    <main v-else-if="errorMessage" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <a href="/dashboard/applications" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to applications
                </a>
                <section class="mt-5 rounded-md border border-rose-200 bg-white px-5 py-8 text-center shadow-sm">
                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-rose-50 text-rose-700">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    </span>
                    <h1 class="mt-3 text-lg font-bold text-slate-950">Application record unavailable</h1>
                    <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-600">{{ errorMessage }}</p>
                    <button type="button" class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800" @click="loadOverview">
                        Try again
                    </button>
                </section>
            </div>
        </section>
    </main>

    <ApplicantApplicationShell v-else-if="detail" :detail="detail">
        <div class="mt-4 space-y-4">
            <ApplicationProgressRecord :progress="detail.progress" />

            <section v-if="overview.upcoming_activity" class="rounded-md border border-slate-300 bg-white px-4 py-4 shadow-sm sm:px-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700">
                            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Upcoming activity</p>
                            <h2 class="mt-1 text-base font-bold text-slate-950">{{ overview.upcoming_activity.title }}</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ overview.upcoming_activity.scheduled_label }}
                                <span v-if="overview.upcoming_activity.mode_label"> · {{ overview.upcoming_activity.mode_label }}</span>
                            </p>
                        </div>
                    </div>
                    <a :href="detail.navigation.find((item) => item.key === 'activities')?.href" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        View activity
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </div>
            </section>

            <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                <header class="border-b border-slate-200 px-4 py-4 sm:px-5">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Application overview</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950">Record information</h2>
                    <p class="mt-1 text-sm text-slate-600">The essential details saved with this application.</p>
                </header>

                <dl class="grid sm:grid-cols-3">
                    <div
                        v-for="(row, index) in recordRows"
                        :key="row.label"
                        :class="['px-4 py-4 sm:px-5', index ? 'border-t border-slate-200 sm:border-l sm:border-t-0' : '']"
                    >
                        <dt class="text-xs font-semibold text-slate-500">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ row.value }}</dd>
                    </div>
                </dl>

                <div class="border-t border-slate-200 px-4 py-4 sm:px-5">
                    <div class="flex items-start gap-3">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                            <i class="fa-solid fa-clock-rotate-left text-sm" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <h3 class="text-sm font-bold text-slate-950">{{ overview.latest_update.title }}</h3>
                                <span class="text-xs text-slate-500">{{ overview.latest_update.occurred_at }}</span>
                            </div>
                            <p class="mt-1 text-sm leading-5 text-slate-600">{{ overview.latest_update.description }}</p>
                        </div>
                    </div>
                </div>

                <footer class="flex flex-col gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:px-5">
                    <a :href="overview.links.scholarship" class="inline-flex min-h-10 items-center gap-2 rounded px-3 py-2 text-sm font-bold text-slate-700 hover:bg-white hover:text-slate-950">
                        <i class="fa-solid fa-graduation-cap w-4 text-center text-amber-700" aria-hidden="true"></i>
                        Scholarship details
                    </a>
                    <a v-if="overview.links.provider" :href="overview.links.provider" class="inline-flex min-h-10 items-center gap-2 rounded px-3 py-2 text-sm font-bold text-slate-700 hover:bg-white hover:text-slate-950">
                        <i class="fa-solid fa-building w-4 text-center text-amber-700" aria-hidden="true"></i>
                        Provider profile
                    </a>
                </footer>
            </section>
        </div>
    </ApplicantApplicationShell>
</template>
