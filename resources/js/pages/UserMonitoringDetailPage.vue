<script setup>
import { nextTick, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ApplicantMonitoringShell from '../components/monitoring-detail/ApplicantMonitoringShell.vue';
import MonitoringCheckIns from '../components/monitoring-detail/MonitoringCheckIns.vue';
import MonitoringBenefits from '../components/monitoring-detail/MonitoringBenefits.vue';
import MonitoringHistory from '../components/monitoring-detail/MonitoringHistory.vue';
import MonitoringOverviewRecord from '../components/monitoring-detail/MonitoringOverviewRecord.vue';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const requestedSection = appElement?.dataset.monitoringSection ?? 'overview';
const requestedRecord = appElement?.dataset.monitoringRecord || null;
const detail = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');

async function loadMonitoringRecord() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/monitoring/${applicationId}/data`, {
            params: { view: requestedSection, record: requestedRecord },
        });
        detail.value = response.data.detail;
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            ?? 'We could not load this support record. Please try again.';
    } finally {
        isLoading.value = false;
    }
}

async function refreshMonitoringDetail() {
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/monitoring/${applicationId}/data`, {
            params: { view: requestedSection, record: requestedRecord },
        });
        detail.value = response.data.detail;
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            ?? 'We could not refresh this support record. Please try again.';
    }
}

onMounted(async () => {
    await loadMonitoringRecord();
    await nextTick();
    if (window.location.hash === '#final-agreement') {
        const requirement = document.getElementById('final-agreement');
        requirement?.focus({ preventScroll: true });
        requirement?.scrollIntoView({ block: 'start' });
    }
});
</script>

<template>
    <main v-if="isLoading" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page" aria-busy="true" aria-label="Loading support record">
            <div class="mx-auto w-full max-w-[90rem]">
                <div class="h-5 w-36 animate-pulse rounded bg-slate-200"></div>
                    <div class="mt-5 h-28 animate-pulse rounded-md border border-slate-200 bg-white"></div>
                    <div class="mt-4 h-14 animate-pulse rounded-md border border-slate-200 bg-white"></div>
                    <div class="mt-4 h-60 animate-pulse rounded-md border border-slate-200 bg-white"></div>
            </div>
        </section>
    </main>

    <main v-else-if="errorMessage" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <a href="/dashboard/monitoring" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to Scholarship Support
                </a>
                <FeedbackToast :message="errorMessage" fallback />
            </div>
        </section>
    </main>

    <ApplicantMonitoringShell v-else-if="detail" :detail="detail">
        <MonitoringOverviewRecord
            v-if="detail.view === 'overview'"
            :content="detail.content"
        />

        <MonitoringCheckIns
            v-else-if="detail.view === 'check-ins'"
            :content="detail.content"
            :application-id="applicationId"
            :program-title="detail.identity.program.title"
            @refresh="refreshMonitoringDetail"
        />

        <MonitoringBenefits
            v-else-if="detail.view === 'benefits'"
            :application-id="applicationId"
            :content="detail.content"
            :program-title="detail.identity.program.title"
            @refresh="refreshMonitoringDetail"
        />

        <MonitoringHistory
            v-else-if="detail.view === 'history'"
            :content="detail.content"
            :program-title="detail.identity.program.title"
            @refresh="refreshMonitoringDetail"
        />

        <section v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-white px-5 py-8 text-center shadow-sm">
            <span class="mx-auto grid h-10 w-10 place-items-center rounded-sm bg-slate-100 text-slate-500">
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
            </span>
            <h2 class="mt-3 text-base font-bold text-slate-950">This section is not active</h2>
            <p class="mt-1 text-sm text-slate-500">The provider has not published information for this area.</p>
        </section>
    </ApplicantMonitoringShell>
</template>
