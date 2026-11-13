<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';
import { checkInStatusClass } from '../support/checkInStatus';

const support = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');
const focus = computed(() => support.value?.focus ?? null);
const needsAction = computed(() => focus.value?.decision.tone === 'attention');
const otherPastUpdates = computed(() => (support.value?.past ?? []).filter(record => (
    record.href !== focus.value?.href && record.decision.tone === 'attention'
)).length);

function focusIcon(tone) {
    return {
        attention: 'fa-solid fa-arrow-right',
        scheduled: 'fa-regular fa-calendar-check',
        waiting: 'fa-regular fa-clock',
    }[tone] ?? 'fa-solid fa-check';
}

function logoFallback(event) {
    if (!event.currentTarget.src.endsWith('/uploads/scholarship-default.jpg')) {
        event.currentTarget.src = '/uploads/scholarship-default.jpg';
    }
}

async function loadSupport() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const response = await window.axios.get('/dashboard/monitoring/data', { portalToast: false });
        support.value = response.data.support;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'We could not load your support records. Please try again.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadSupport);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar />

        <section class="student-page">
            <div class="student-container">
                <ApplicantPageHeader
                    eyebrow="Recipient workspace"
                    title="Scholarship Support"
                    description="Check what needs your attention, what's coming up, and the support you've received."
                    icon="fa-solid fa-hand-holding-heart"
                />

                <section v-if="isLoading" role="status" aria-label="Loading scholarship support" class="mt-5 grid animate-pulse gap-4 motion-reduce:animate-none xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
                    <div class="h-56 rounded-md border border-slate-200 bg-white"></div>
                    <div class="h-56 rounded-md border border-slate-200 bg-white"></div>
                </section>

                <section v-else-if="errorMessage" class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-md border border-rose-200 bg-white px-5 py-4">
                    <p class="text-sm font-semibold text-rose-800" role="alert">{{ errorMessage }}</p>
                    <button type="button" class="student-button student-button-secondary" @click="loadSupport">Try again</button>
                </section>

                <section v-else-if="!support.active.length && !support.past.length" class="mt-5 rounded-md border border-slate-300 bg-white px-5 py-9 text-center shadow-sm" data-support-empty>
                    <span class="mx-auto grid h-12 w-12 place-items-center rounded-sm bg-amber-100 text-amber-800"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i></span>
                    <h2 class="mt-3 font-display text-xl font-bold text-slate-950">No scholarship support yet</h2>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-600">When a provider selects you for a scholarship, you'll find the award and its next steps here.</p>
                    <a href="/dashboard/applications" class="student-button student-button-primary mt-4">View applications <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                </section>

                <div v-else data-support-landing class="mt-5 space-y-5">
                    <div v-if="focus || support.schedule.length" class="grid items-stretch gap-4 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
                        <section v-if="focus" data-support-priority :class="['relative flex min-w-0 flex-col overflow-hidden rounded-md border bg-white shadow-sm', needsAction ? 'border-amber-300' : 'border-slate-300']" aria-labelledby="support-priority-title">
                            <span :class="['absolute inset-y-0 left-0 w-1.5', needsAction ? 'bg-amber-400' : 'bg-slate-800']" aria-hidden="true"></span>
                            <div class="flex flex-1 flex-col justify-center px-5 py-6 sm:px-7">
                                <div class="flex items-center gap-2.5">
                                    <span :class="['grid h-9 w-9 shrink-0 place-items-center rounded-sm', needsAction ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700']"><i :class="focusIcon(focus.decision.tone)" aria-hidden="true"></i></span>
                                    <p :class="['text-[11px] font-bold uppercase tracking-[0.16em]', needsAction ? 'text-amber-800' : 'text-slate-600']">{{ focus.decision.eyebrow }}</p>
                                </div>
                                <h2 id="support-priority-title" class="mt-3 font-display text-2xl font-bold leading-tight text-slate-950 sm:text-3xl">{{ focus.decision.title }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-6 text-slate-600">{{ focus.decision.description }}</p>
                                <div v-if="focus.decision.state !== 'no_action'" class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs font-semibold text-slate-700">
                                    <span class="inline-flex min-w-0 items-center gap-2"><i class="fa-solid fa-graduation-cap shrink-0 text-amber-700" aria-hidden="true"></i>{{ focus.title }}</span>
                                    <span v-if="focus.decision.due_label" class="inline-flex items-center gap-2"><i class="fa-regular fa-calendar text-slate-500" aria-hidden="true"></i>{{ focus.decision.due_label }}</span>
                                </div>
                            </div>
                            <footer v-if="focus.decision.action" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-7">
                                <span class="text-xs font-semibold text-slate-600">{{ focus.decision.actor === 'applicant' ? 'For you to do' : 'With the scholarship provider' }}</span>
                                <a :href="focus.decision.action.href" :class="['student-button', needsAction ? 'student-button-primary' : 'student-button-secondary']" data-support-priority-link>{{ focus.decision.action.label }} <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                            </footer>
                        </section>

                        <section data-support-schedule class="flex min-w-0 flex-col overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="support-schedule-title">
                            <header class="relative isolate flex min-h-28 items-end overflow-hidden bg-slate-900 px-5 py-4">
                                <img :src="'/images/filipino-students-group-study.jpg'" alt="" class="absolute inset-y-0 right-0 -z-10 h-full w-3/4 object-cover object-[50%_58%]">
                                <span class="absolute inset-0 -z-10 bg-gradient-to-r from-slate-950 via-slate-950/95 to-slate-950/25" aria-hidden="true"></span>
                                <h2 id="support-schedule-title" class="inline-flex items-center gap-2 font-display text-xl font-bold text-white"><i class="fa-regular fa-calendar text-sm text-amber-300" aria-hidden="true"></i> Coming up</h2>
                            </header>
                            <ul v-if="support.schedule.length" class="divide-y divide-slate-200">
                                <li v-for="item in support.schedule" :key="item.href">
                                    <a :href="item.href" class="flex items-center justify-between gap-3 px-5 py-3.5 transition hover:bg-slate-50">
                                        <span class="min-w-0">
                                            <span class="block text-[10px] font-bold uppercase tracking-widest text-amber-800">{{ item.kind }}</span>
                                            <span class="mt-0.5 block text-sm font-bold text-slate-950">{{ item.title }}</span>
                                            <span class="mt-0.5 block text-xs text-slate-600">{{ item.date_label }}</span>
                                            <span v-if="support.active.length > 1" class="mt-1 block text-xs text-slate-500">{{ item.program_title }}</span>
                                        </span>
                                        <i class="fa-solid fa-chevron-right shrink-0 text-xs text-slate-500" aria-hidden="true"></i>
                                    </a>
                                </li>
                            </ul>
                            <div v-else class="flex flex-1 flex-col justify-center px-5 py-5">
                                <p class="text-sm font-bold text-slate-950">{{ focus?.decision.tone === 'scheduled' ? 'No other dates scheduled' : 'No upcoming dates yet' }}</p>
                                <p class="mt-1 text-sm text-slate-600">New appointments and release dates will appear here.</p>
                            </div>
                        </section>
                    </div>

                    <section v-if="support.active.length" data-support-active class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="active-support-title">
                        <header class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50/70 px-5 py-4 sm:px-6">
                            <div>
                                <h2 id="active-support-title" class="font-display text-xl font-bold text-slate-950">Your scholarships</h2>
                                <p class="mt-1 text-sm text-slate-600">Open a support record for its check-ins, releases, and updates.</p>
                            </div>
                            <span class="shrink-0 text-xs font-bold text-slate-600">{{ support.active.length }} current</span>
                        </header>
                        <ul class="divide-y divide-slate-200">
                            <li v-for="record in support.active" :key="record.href" class="grid items-center gap-x-4 gap-y-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-6">
                                <div class="flex min-w-0 items-start gap-3 sm:items-center">
                                    <img :src="record.logo_url" alt="" class="h-12 w-12 shrink-0 rounded-sm border border-slate-200 bg-white object-contain p-1.5" @error="logoFallback">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                            <h3 class="break-words text-sm font-bold text-slate-950 sm:text-base">{{ record.title }}</h3>
                                            <span :class="['border px-2 py-0.5 text-[10px] font-bold', checkInStatusClass(record.status.tone)]">{{ record.status.label }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-slate-600">{{ record.provider_name }} <span class="mx-1 text-slate-400" aria-hidden="true">/</span> {{ record.status.mode_label }}</p>
                                        <a v-if="record.href !== focus?.href && record.decision.tone === 'attention' && record.decision.action" :href="record.decision.action.href" class="mt-1.5 inline-block text-xs font-bold text-amber-900 underline underline-offset-4">{{ record.decision.title }}</a>
                                    </div>
                                </div>
                                <a :href="record.href" class="student-button student-button-secondary">View support <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                            </li>
                        </ul>
                    </section>

                    <details v-if="support.past.length" data-support-past class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 sm:px-6 [&::-webkit-details-marker]:hidden">
                            <span class="flex min-w-0 items-center gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                                <span class="min-w-0">
                                    <span class="block text-base font-bold text-slate-950">Past support</span>
                                    <span class="mt-0.5 block text-xs text-slate-600">{{ otherPastUpdates ? 'Some records still have an update to review.' : 'Completed, ended, or declined awards.' }}</span>
                                </span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2.5 text-xs font-bold text-slate-600">{{ support.past.length }} {{ support.past.length === 1 ? 'record' : 'records' }} <i class="fa-solid fa-chevron-down transition group-open:rotate-180" aria-hidden="true"></i></span>
                        </summary>
                        <ul class="divide-y divide-slate-200 border-t border-slate-200">
                            <li v-for="record in support.past" :key="record.href" class="grid items-center gap-x-4 gap-y-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:px-6">
                                <div class="flex min-w-0 items-start gap-3">
                                    <img :src="record.logo_url" alt="" class="h-10 w-10 shrink-0 rounded-sm border border-slate-200 bg-white object-contain p-1" @error="logoFallback">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                            <h3 class="break-words text-sm font-bold text-slate-950">{{ record.title }}</h3>
                                            <span :class="['border px-2 py-0.5 text-[10px] font-bold', checkInStatusClass(record.status.tone)]">{{ record.status.label }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-slate-600">{{ record.provider_name }}</p>
                                        <a v-if="record.href !== focus?.href && record.decision.tone === 'attention' && record.decision.action" :href="record.decision.action.href" class="mt-1.5 inline-block text-xs font-bold text-amber-900 underline underline-offset-4">{{ record.decision.title }}</a>
                                    </div>
                                </div>
                                <a :href="record.history_href" class="student-button student-button-secondary">View history <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></a>
                            </li>
                        </ul>
                    </details>
                </div>
            </div>
        </section>
    </main>
</template>
