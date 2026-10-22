<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import LocationMapModal from '../components/LocationMapModal.vue';
import ApplicantApplicationShell from '../components/application-detail/ApplicantApplicationShell.vue';
import ApplicationActivityRecord from '../components/application-detail/ApplicationActivityRecord.vue';
import FormalApplicationHandoff from '../components/application-detail/FormalApplicationHandoff.vue';
import { showPortalToast } from '../support/portalToast';

const appElement = document.getElementById('app');
const applicationId = appElement?.dataset.applicationId;
const detail = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');
const acknowledgingId = ref(null);
const mapPreview = ref(null);

const activities = computed(() => detail.value?.content ?? {});
const primaryActivity = computed(() => activities.value.active?.[0] ?? null);
const additionalActivities = computed(() => activities.value.active?.slice(1) ?? []);
const formalApplicationIsCurrent = computed(() => detail.value?.progress?.current_stage === 'formal_application');
const shellDetail = computed(() => {
    if (!detail.value) {
        return null;
    }

    const sourceDecision = detail.value.decision ?? {};
    const decision = {
        ...sourceDecision,
        action: sourceDecision.action ? { ...sourceDecision.action } : null,
    };
    const currentPath = window.location.pathname;
    const actionPath = decision.action?.href
        ? new URL(decision.action.href, window.location.origin).pathname
        : '';

    if (actionPath === currentPath) {
        decision.action.href = activities.value.active?.length
            ? '#current-activity'
            : '#formal-application-handoff';
        decision.action.label = activities.value.active?.length ? 'View current activity' : 'View provider instructions';
    }

    return { ...detail.value, decision };
});

async function loadActivities() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/dashboard/applications/${applicationId}/data`, {
            params: { view: 'activities' },
        });
        detail.value = response.data.detail;
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            ?? 'We could not load the provider activities for this application.';
    } finally {
        isLoading.value = false;
    }
}

function openActivityMap(activity) {
    mapPreview.value = {
        eyebrow: activity.type_label,
        title: activity.venue || activity.title,
        address: activity.location_address || activity.venue || '',
        latitude: activity.latitude,
        longitude: activity.longitude,
        markerText: activity.venue || activity.title,
    };
}

function openHandoffMap(handoff) {
    mapPreview.value = {
        eyebrow: 'Provider location',
        title: handoff.location_name || detail.value.identity.provider.name,
        address: handoff.location_address || handoff.location_name || '',
        markerText: handoff.location_name || detail.value.identity.provider.name,
    };
}

async function acknowledgeActivity(activity) {
    if (!activity.acknowledge_endpoint || acknowledgingId.value !== null) {
        return;
    }

    acknowledgingId.value = `${activity.source}-${activity.id}`;

    try {
        const response = await window.axios.patch(activity.acknowledge_endpoint, {}, {
            portalToast: false,
        });
        showPortalToast({
            title: 'Schedule confirmed',
            message: response.data.message,
        });
        await loadActivities();
    } catch (error) {
        showPortalToast({
            type: 'error',
            title: 'Confirmation not saved',
            message: error.response?.data?.message ?? 'Please try again.',
        });
    } finally {
        acknowledgingId.value = null;
    }
}

function activityKey(activity) {
    return `${activity.source}-${activity.id}`;
}

onMounted(loadActivities);
</script>

<template>
    <main v-if="isLoading" class="student-shell">
        <ApplicantSidebar />
        <section class="student-page">
            <div class="mx-auto w-full max-w-[90rem]">
                <div class="h-5 w-36 animate-pulse rounded bg-slate-200"></div>
                <div class="mt-5 h-96 animate-pulse rounded-md border border-slate-200 bg-white"></div>
                <div class="mt-4 h-80 animate-pulse rounded-md border border-slate-200 bg-white"></div>
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
                    <h1 class="text-lg font-bold text-slate-950">Activities unavailable</h1>
                    <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-slate-600">{{ errorMessage }}</p>
                    <button type="button" class="mt-4 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white" @click="loadActivities">Try again</button>
                </section>
            </div>
        </section>
    </main>

    <ApplicantApplicationShell v-else-if="shellDetail" :detail="shellDetail">
        <LocationMapModal
            :open="Boolean(mapPreview)"
            :eyebrow="mapPreview?.eyebrow"
            :title="mapPreview?.title"
            :address="mapPreview?.address"
            :latitude="mapPreview?.latitude"
            :longitude="mapPreview?.longitude"
            :marker-text="mapPreview?.markerText"
            @close="mapPreview = null"
        />

        <div class="mt-4 space-y-4">
            <FormalApplicationHandoff
                v-if="activities.formal_application && formalApplicationIsCurrent"
                :handoff="activities.formal_application"
                @map="openHandoffMap"
            />

            <section v-if="primaryActivity" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                <header class="border-b border-slate-200 px-4 py-5 sm:px-6">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Your schedule</p>
                    <h2 class="mt-1 font-display text-xl font-bold text-slate-950">Next activity</h2>
                    <p class="mt-1 text-sm text-slate-600">Check the date, method, and instructions before attending.</p>
                </header>
                <ApplicationActivityRecord
                    :key="activityKey(primaryActivity)"
                    :activity="primaryActivity"
                    primary
                    :acknowledging="acknowledgingId === activityKey(primaryActivity)"
                    @acknowledge="acknowledgeActivity"
                    @map="openActivityMap"
                />
            </section>

            <details v-if="additionalActivities.length" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-4 hover:bg-slate-50 sm:px-5 [&::-webkit-details-marker]:hidden">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Later</p>
                        <h2 class="mt-1 text-base font-bold text-slate-950">Other scheduled activities ({{ additionalActivities.length }})</h2>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                </summary>
                <div class="divide-y divide-slate-200 border-t border-slate-200">
                    <ApplicationActivityRecord
                        v-for="activity in additionalActivities"
                        :key="activityKey(activity)"
                        :activity="activity"
                        :acknowledging="acknowledgingId === activityKey(activity)"
                        @acknowledge="acknowledgeActivity"
                        @map="openActivityMap"
                    />
                </div>
            </details>

            <FormalApplicationHandoff
                v-if="activities.formal_application && !formalApplicationIsCurrent"
                :handoff="activities.formal_application"
                @map="openHandoffMap"
            />

            <section v-if="!activities.active?.length && !activities.formal_application" class="rounded-md border border-slate-300 bg-white px-5 py-10 text-center shadow-sm">
                <span class="mx-auto grid h-11 w-11 place-items-center rounded-md bg-slate-100 text-slate-500">
                    <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                </span>
                <h2 class="mt-3 text-lg font-bold text-slate-950">{{ activities.empty_state?.title || 'No active provider activity' }}</h2>
                <p class="mx-auto mt-1 max-w-2xl text-sm leading-6 text-slate-600">{{ activities.empty_state?.description }}</p>
            </section>

            <details v-if="activities.past?.length" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-4 hover:bg-slate-50 sm:px-5 [&::-webkit-details-marker]:hidden">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Activity history</p>
                        <h2 class="mt-1 text-base font-bold text-slate-950">Past activities ({{ activities.past.length }})</h2>
                    </div>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
                </summary>
                <div class="divide-y divide-slate-200 border-t border-slate-200">
                    <div v-for="activity in activities.past" :key="activityKey(activity)" class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div>
                            <p class="text-sm font-bold text-slate-950">{{ activity.title }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ activity.type_label }} · {{ activity.scheduled_label }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-600">
                            <span>{{ activity.status_label }}</span>
                            <span v-if="activity.attendance_status && activity.attendance_status !== 'pending'" class="text-slate-400">·</span>
                            <span v-if="activity.attendance_status && activity.attendance_status !== 'pending'" class="capitalize">{{ activity.attendance_status.replaceAll('_', ' ') }}</span>
                        </div>
                    </div>
                </div>
            </details>
        </div>
    </ApplicantApplicationShell>
</template>
