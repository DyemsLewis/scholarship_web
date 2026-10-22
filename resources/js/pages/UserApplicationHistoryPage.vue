<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import ApplicantApplicationShell from '../components/application-detail/ApplicantApplicationShell.vue';
import ApplicationHistoryEventDialog from '../components/application-detail/ApplicationHistoryEventDialog.vue';
import ApplicationHistoryTimeline from '../components/application-detail/ApplicationHistoryTimeline.vue';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const detail = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');
const selectedEvent = ref(null);

const history = computed(() => detail.value?.content ?? { events: [], summary: {} });
const latestEvent = computed(() => history.value.events?.[0] ?? null);
const earlierEvents = computed(() => history.value.events?.slice(1) ?? []);

async function loadHistory() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/applications/${applicationId}/data`, {
            params: { view: 'history' },
        });
        detail.value = response.data.detail;
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            ?? 'We could not load the application history.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadHistory);
</script>

<template>
    <main v-if="isLoading" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <div class="h-5 w-36 animate-pulse rounded bg-slate-200"></div>
                <div class="mt-5 h-96 animate-pulse rounded-md border border-slate-200 bg-white"></div>
                <div class="mt-4 h-96 animate-pulse rounded-md border border-slate-200 bg-white"></div>
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
                    <h1 class="text-lg font-bold text-slate-950">History unavailable</h1>
                    <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-600">{{ errorMessage }}</p>
                    <button type="button" class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white" @click="loadHistory">Try again</button>
                </section>
            </div>
        </section>
    </main>

    <ApplicantApplicationShell v-else-if="detail" :detail="detail">
        <ApplicationHistoryEventDialog
            :open="Boolean(selectedEvent)"
            :event="selectedEvent"
            @close="selectedEvent = null"
        />

        <section class="mt-4 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="flex flex-col gap-3 border-b border-slate-200 px-4 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-6">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Application history</p>
                    <h2 class="mt-1 font-display text-xl font-bold text-slate-950">Application timeline</h2>
                    <p class="mt-1 text-sm text-slate-600">Latest milestone first. Open a record only when you need more detail.</p>
                </div>
                <p v-if="history.summary?.event_count" class="text-xs font-semibold text-slate-500">
                    {{ history.summary.event_count }} {{ history.summary.event_count === 1 ? 'milestone' : 'milestones' }}
                </p>
            </header>

            <ApplicationHistoryTimeline
                v-if="latestEvent"
                :events="[latestEvent]"
                featured
                @select="selectedEvent = $event"
            />

            <details v-if="earlierEvents.length" class="group border-t border-slate-200">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 bg-slate-50 px-4 py-3 hover:bg-slate-100 sm:px-5 [&::-webkit-details-marker]:hidden">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Earlier changes</p>
                        <h3 class="mt-0.5 text-sm font-bold text-slate-950">
                            {{ earlierEvents.length }} earlier {{ earlierEvents.length === 1 ? 'milestone' : 'milestones' }}
                        </h3>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                </summary>
                <ApplicationHistoryTimeline
                    :events="earlierEvents"
                    compact
                    @select="selectedEvent = $event"
                />
            </details>

            <div v-else-if="!latestEvent" class="px-5 py-10 text-center">
                <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500">
                    <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                </span>
                <h2 class="mt-3 text-base font-bold text-slate-950">No milestones recorded</h2>
                <p class="mt-1 text-sm text-slate-600">New application updates will appear here.</p>
            </div>

        </section>
    </ApplicantApplicationShell>
</template>
