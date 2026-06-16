<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantSidebar from '../components/ApplicantSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const stats = ref({
    available_scholarships: 0,
    applications: 0,
    saved: 0,
});
const nextSteps = ref([]);
const notifications = ref([]);

const statCards = computed(() => [
    {
        label: 'Available Scholarships',
        value: stats.value.available_scholarships,
        description: 'Published programs currently visible to applicants.',
        href: '/dashboard/scholarships',
        className: 'text-sky-700',
        accent: 'bg-sky-600',
    },
    {
        label: 'My Applications',
        value: stats.value.applications,
        description: 'Applications will appear here once submissions are added.',
        href: '/dashboard/applications',
        className: 'text-emerald-700',
        accent: 'bg-emerald-600',
    },
    {
        label: 'Unread Updates',
        value: notifications.value.filter((notification) => !notification.read_at).length,
        description: 'Unread portal updates from providers and admins.',
        href: '/dashboard/scholarships',
        className: 'text-amber-600',
        accent: 'bg-amber-500',
    },
]);

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
        stats.value = response.data.stats;
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
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ApplicantSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr] lg:items-end">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
                                Applicant Dashboard
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Welcome back, {{ user?.first_name || 'Scholar' }}
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                This overview keeps only the important details. Scholarships, applications, and profile records now have their own pages.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Profile Readiness
                            </p>
                            <div class="mt-3 flex items-end justify-between gap-4">
                                <p class="font-display text-4xl font-bold text-slate-950">
                                    {{ profileCompletion }}%
                                </p>
                                <p class="pb-1 text-sm text-slate-500">
                                    {{ completedProfileFields }}/{{ profileFields.length }} details complete
                                </p>
                            </div>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-full rounded-full bg-sky-600 transition-all"
                                    :style="{ width: `${profileCompletion}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading applicant dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div class="grid gap-4 md:grid-cols-3">
                        <a
                            v-for="card in statCards"
                            :key="card.label"
                            :href="card.href"
                            class="group relative overflow-hidden rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md"
                        >
                            <div :class="['absolute left-0 top-0 h-full w-1', card.accent]"></div>
                            <p class="text-sm font-semibold text-slate-500">
                                {{ card.label }}
                            </p>
                            <p :class="['mt-3 font-display text-3xl font-bold', card.className]">
                                {{ card.value }}
                            </p>
                            <p class="mt-3 text-sm leading-5 text-slate-500">
                                {{ card.description }}
                            </p>
                            <p class="mt-4 text-sm font-bold text-slate-900 group-hover:text-sky-700">
                                Open {{ card.label.toLowerCase() }}
                            </p>
                        </a>
                    </div>

                    <section class="grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
                        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                Next Steps
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Continue your scholarship work
                            </h3>
                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="(step, index) in nextSteps"
                                    :key="step"
                                    class="flex gap-3 rounded-md border border-slate-200 bg-slate-50 p-3"
                                >
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-900 text-sm font-bold text-white">
                                        {{ index + 1 }}
                                    </span>
                                    <p class="text-sm leading-6 text-slate-600">
                                        {{ step }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                Notifications
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
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
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4 transition hover:border-sky-200 hover:bg-sky-50"
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
