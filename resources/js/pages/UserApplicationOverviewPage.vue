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
                <div class="mt-5 h-96 animate-pulse rounded-md border border-slate-200 bg-white"></div>
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

            <section v-if="overview.upcoming_activity && detail.decision?.state !== 'action_required'" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                <div class="flex flex-col gap-4 px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="grid min-h-16 w-16 shrink-0 place-items-center rounded-sm bg-slate-950 text-center text-white">
                            <span class="px-1 text-xs font-bold leading-4 text-amber-300">NEXT<br>DATE</span>
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
                    <a :href="detail.navigation.find((item) => item.key === 'activities')?.href" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-sm border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        View activity
                        <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                    </a>
                </div>
            </section>

            <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                <header class="flex flex-col gap-4 border-b border-slate-200 px-4 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6">
                    <div class="flex min-w-0 items-start gap-4">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-sm bg-amber-100 text-amber-800">
                            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Latest provider update</p>
                            <h2 class="mt-1 font-display text-lg font-bold text-slate-950">{{ overview.latest_update.title }}</h2>
                            <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-600">{{ overview.latest_update.description }}</p>
                        </div>
                    </div>
                    <span class="shrink-0 text-xs font-semibold text-slate-500">{{ overview.latest_update.occurred_at }}</span>
                </header>

                <dl class="grid bg-slate-50/70 sm:grid-cols-3">
                    <div
                        v-for="(row, index) in recordRows"
                        :key="row.label"
                        :class="['px-4 py-4 sm:px-6', index ? 'border-t border-slate-200 sm:border-l sm:border-t-0' : '']"
                    >
                        <dt class="text-xs font-semibold text-slate-500">{{ row.label }}</dt>
                        <dd class="mt-1 text-sm font-bold text-slate-950">{{ row.value }}</dd>
                    </div>
                </dl>

                <footer class="flex flex-col gap-2 border-t border-slate-200 bg-white px-4 py-3 sm:flex-row sm:items-center sm:px-6">
                    <a :href="overview.links.scholarship" class="inline-flex min-h-10 items-center gap-2 rounded-sm px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 hover:text-slate-950">
                        <i class="fa-solid fa-graduation-cap w-4 text-center text-amber-700" aria-hidden="true"></i>
                        Scholarship details
                    </a>
                    <a v-if="overview.links.provider" :href="overview.links.provider" class="inline-flex min-h-10 items-center gap-2 rounded-sm px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 hover:text-slate-950">
                        <i class="fa-solid fa-building w-4 text-center text-amber-700" aria-hidden="true"></i>
                        Provider profile
                    </a>
                </footer>
            </section>
        </div>
    </ApplicantApplicationShell>
</template>
