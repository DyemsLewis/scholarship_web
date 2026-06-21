<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const nextSteps = ref([]);
const notifications = ref([]);

const profileFields = computed(() => [
    { label: 'First name', value: user.value?.first_name },
    { label: 'Last name', value: user.value?.last_name },
    { label: 'Middle initial', value: user.value?.middle_initial },
    { label: 'Email', value: user.value?.email },
    { label: 'Username', value: user.value?.username },
    { label: 'Contact number', value: user.value?.contact_number },
]);

const completedProfileFields = computed(() => profileFields.value.filter((field) => Boolean(field.value)).length);
const profileCompletion = computed(() => Math.round((completedProfileFields.value / profileFields.value.length) * 100));

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/data');

        user.value = response.data.user;
        nextSteps.value = response.data.next_steps;
        notifications.value = response.data.notifications ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load applicant dashboard.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadDashboard);
</script>

<template>
    <main class="student-shell">
        <ApplicantSidebar @logout="logout" />

        <section class="student-page">
            <div class="student-container">
                <header class="student-hero">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-2xl">
                            <p class="student-kicker">
                                Dashboard
                            </p>
                            <h2 class="mt-2 font-display text-2xl font-bold text-slate-950 sm:text-3xl">
                                Welcome back, {{ user?.first_name || 'Scholar' }}
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">
                                Quick overview only. Use the tabs above when you need full scholarship, application, or profile details.
                            </p>
                        </div>

                        <div class="student-soft-card w-full p-4 lg:max-w-sm">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        Profile readiness
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ completedProfileFields }}/{{ profileFields.length }} details complete
                                    </p>
                                </div>
                                <p class="font-display text-3xl font-bold text-slate-950">
                                    {{ profileCompletion }}%
                                </p>
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-slate-900 transition-all" :style="{ width: `${profileCompletion}%` }"></div>
                            </div>
                            <a href="/dashboard/profile" class="mt-3 inline-flex text-sm font-semibold text-slate-900 hover:text-sky-700">
                                Update profile
                            </a>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading applicant dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <section class="grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
                        <div class="student-card p-5">
                            <p class="student-kicker">
                                Next Steps
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                Continue your scholarship work
                            </h3>
                            <div class="mt-4 grid gap-2">
                                <div
                                    v-for="(step, index) in nextSteps"
                                    :key="step"
                                    class="flex gap-3 rounded-md bg-[#f6faf8] p-3 ring-1 ring-slate-200/70"
                                >
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-white text-sm font-bold text-slate-700 ring-1 ring-slate-200">
                                        {{ index + 1 }}
                                    </span>
                                    <p class="text-sm leading-6 text-slate-600">
                                        {{ step }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="student-card p-5">
                            <p class="student-kicker">
                                Notifications
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                Recent portal updates
                            </h3>
                            <div v-if="notifications.length === 0" class="mt-5 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                No notifications yet.
                            </div>
                            <div v-else class="mt-5 grid gap-3">
                                <a
                                    v-for="notification in notifications"
                                    :key="notification.id"
                                    :href="notification.action_url || '/dashboard/applications'"
                                    class="rounded-md border border-slate-200/80 bg-[#f6faf8] p-4 transition hover:bg-white"
                                >
                                    <p class="font-bold text-slate-950">
                                        {{ notification.title }}
                                    </p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        {{ notification.message }}
                                    </p>
                                    <p class="mt-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">
                                        {{ notification.created_at }}
                                    </p>
                                </a>
                            </div>
                        </div>
                    </section>
                </div>

                <ApplicantFooter />
            </div>
        </section>
    </main>
</template>
