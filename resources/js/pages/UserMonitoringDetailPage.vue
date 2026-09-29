<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantRecipientMonitoring from '../components/ApplicantRecipientMonitoring.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const applicationId = document.getElementById('app')?.dataset.applicationId;
const isLoading = ref(true);
const errorMessage = ref('');
const application = ref(null);

const monitoring = computed(() => application.value?.recipient_monitoring ?? null);
const releaseCount = computed(() => monitoring.value?.benefit_releases?.length ?? 0);
const monitoringCount = computed(() => (
    (monitoring.value?.check_ins?.length ?? 0) + (monitoring.value?.cycles?.length ?? 0)
));

function supportStatusClass(status) {
    if (status === 'active') return 'bg-emerald-100 text-emerald-800';
    if (status === 'renewed') return 'bg-sky-100 text-sky-800';
    if (status === 'terminated') return 'bg-rose-100 text-rose-800';
    if (status === 'completed') return 'bg-slate-200 text-slate-700';
    return 'bg-amber-100 text-amber-800';
}

function applyApplicationUpdate(updatedApplication) {
    application.value = updatedApplication;
}

async function loadMonitoringRecord() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/applications/${applicationId}/data`);
        application.value = response.data.application;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load this monitoring record.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadMonitoringRecord);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container max-w-6xl">
                <a href="/dashboard/monitoring" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 transition hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to monitoring
                </a>

                <div v-if="isLoading" class="student-card mt-5 rounded-md border-slate-300 p-6 text-sm text-slate-500">Loading monitoring record...</div>

                <div v-else-if="errorMessage" class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</div>

                <template v-else-if="application && monitoring?.eligible">
                    <ApplicantPageHeader
                        class="mt-5"
                        eyebrow="Recipient monitoring"
                        :title="application.scholarship?.title || 'Monitoring record'"
                        :description="application.scholarship?.provider?.name || 'Scholarship provider'"
                        icon="fa-solid fa-heart-pulse"
                        :secondary-href="`/dashboard/applications/${application.id}`"
                        secondary-label="Application record"
                    />

                    <section class="mt-4 grid overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm sm:grid-cols-3">
                        <div class="border-b border-slate-200 px-4 py-3 sm:border-b-0 sm:border-r">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Support status</p>
                            <span :class="['mt-1 inline-flex rounded px-2 py-1 text-xs font-bold', supportStatusClass(monitoring.support_status)]">{{ monitoring.support_status_label }}</span>
                        </div>
                        <div class="border-b border-slate-200 px-4 py-3 sm:border-b-0 sm:border-r">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Requirements</p>
                            <p class="mt-1 text-sm font-bold text-slate-950">{{ monitoring.pending_count }} due | {{ monitoringCount }} check-in{{ monitoringCount === 1 ? '' : 's' }}</p>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Benefit releases</p>
                            <p class="mt-1 text-sm font-bold text-slate-950">{{ releaseCount }} recorded</p>
                        </div>
                    </section>

                    <ApplicantRecipientMonitoring
                        class="mt-4"
                        :monitoring="monitoring"
                        :application-id="application.id"
                        :program-title="application.scholarship?.title"
                        :show-header="false"
                        @application-updated="applyApplicationUpdate"
                    />
                </template>

                <section v-else class="student-card mt-5 rounded-md border-slate-300 p-6 text-center sm:p-8">
                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                    <h1 class="mt-3 text-lg font-bold text-slate-950">Monitoring is not active</h1>
                    <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-500">This application does not currently have an active recipient monitoring record.</p>
                    <a :href="`/dashboard/applications/${application?.id || applicationId}`" class="mt-4 inline-flex rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Open application</a>
                </section>
            </div>
        </section>
    </main>
</template>
