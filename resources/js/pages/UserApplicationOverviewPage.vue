<script setup>
import { onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ApplicantApplicationShell from '../components/application-detail/ApplicantApplicationShell.vue';
import ApplicationOverviewRecord from '../components/application-detail/ApplicationOverviewRecord.vue';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const detail = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');

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
        <ApplicationOverviewRecord :detail="detail" class="mt-4" />
    </ApplicantApplicationShell>
</template>
