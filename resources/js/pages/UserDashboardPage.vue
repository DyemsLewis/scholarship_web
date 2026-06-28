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
const profileReadiness = ref({
    complete: false,
    completed: 0,
    total: 0,
    percent: 0,
    missing: [],
});
const scholarships = ref([]);
const applications = ref([]);
const nextSteps = ref([]);
const notifications = ref([]);

const profileCompletion = computed(() => profileReadiness.value.percent ?? 0);
const topMatches = computed(() => [...scholarships.value]
    .sort((first, second) => Number(second.eligibility_match?.score ?? 0) - Number(first.eligibility_match?.score ?? 0))
    .slice(0, 3));
const highMatchCount = computed(() => scholarships.value
    .filter((scholarship) => Number(scholarship.eligibility_match?.score ?? 0) >= 80)
    .length);
const urgentScholarships = computed(() => scholarships.value
    .map((scholarship) => ({ ...scholarship, days_left: deadlineDays(scholarship.deadline) }))
    .filter((scholarship) => scholarship.days_left !== null && scholarship.days_left >= 0 && scholarship.days_left <= 14)
    .sort((first, second) => first.days_left - second.days_left)
    .slice(0, 3));
const documentGaps = computed(() => scholarships.value
    .filter((scholarship) => Number(scholarship.prepared_documents?.required ?? 0) > Number(scholarship.prepared_documents?.uploaded ?? 0))
    .sort((first, second) => Number(second.eligibility_match?.score ?? 0) - Number(first.eligibility_match?.score ?? 0))
    .slice(0, 3));
const applicationWatchlist = computed(() => applications.value
    .filter((application) => Number(application.document_readiness?.accepted_percent ?? 0) < 100 || ['submitted', 'under_review'].includes(application.status))
    .slice(0, 3));
const analystSignals = computed(() => [
    {
        label: 'Profile',
        icon: 'fa-solid fa-user-check',
        tone: profileReadiness.value.complete ? 'good' : 'warn',
        detail: profileReadiness.value.complete
            ? 'Ready to apply.'
            : `${profileReadiness.value.missing?.length ?? 0} missing detail${(profileReadiness.value.missing?.length ?? 0) === 1 ? '' : 's'}.`,
        href: '/dashboard/profile',
        action: profileReadiness.value.complete ? 'Review profile' : 'Complete profile',
    },
    {
        label: 'Matches',
        icon: 'fa-solid fa-graduation-cap',
        tone: highMatchCount.value > 0 ? 'good' : 'info',
        detail: highMatchCount.value > 0
            ? `${highMatchCount.value} strong match${highMatchCount.value === 1 ? '' : 'es'}.`
            : 'No strong match yet.',
        href: '/dashboard/scholarships',
        action: 'Browse matches',
    },
    {
        label: 'Documents',
        icon: 'fa-solid fa-folder-open',
        tone: documentGaps.value.length === 0 ? 'good' : 'warn',
        detail: documentGaps.value.length === 0
            ? 'No gaps found.'
            : `${documentGaps.value.length} program${documentGaps.value.length === 1 ? '' : 's'} need files.`,
        href: '/dashboard/documents',
        action: 'Prepare documents',
    },
    {
        label: 'Deadlines',
        icon: 'fa-solid fa-calendar-days',
        tone: urgentScholarships.value.length === 0 ? 'good' : 'warn',
        detail: urgentScholarships.value.length === 0
            ? 'Nothing urgent.'
            : `${urgentScholarships.value.length} coming soon.`,
        href: '/dashboard/scholarships',
        action: 'Check deadlines',
    },
]);

function signalClass(tone) {
    if (tone === 'good') {
        return 'border-emerald-100 bg-emerald-50 text-emerald-800';
    }

    if (tone === 'warn') {
        return 'border-amber-100 bg-amber-50 text-amber-900';
    }

    return 'border-sky-100 bg-sky-50 text-sky-800';
}

function deadlineDays(value) {
    const parsed = Date.parse(value ?? '');

    if (Number.isNaN(parsed)) {
        return null;
    }

    const today = new Date();
    const startOfToday = new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime();

    return Math.ceil((parsed - startOfToday) / 86400000);
}

async function loadDashboard() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/dashboard/data');

        user.value = response.data.user;
        stats.value = response.data.stats ?? stats.value;
        profileReadiness.value = response.data.profile_readiness ?? profileReadiness.value;
        scholarships.value = response.data.scholarships ?? [];
        applications.value = response.data.applications ?? [];
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
                        </div>

                        <div class="student-soft-card w-full p-4 lg:max-w-sm">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        Profile readiness
                                    </p>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ profileReadiness.completed }}/{{ profileReadiness.total }} details complete
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
                    <section class="student-card p-5">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="student-kicker">
                                    To Do
                                </p>
                                <h3 class="mt-2 text-lg font-bold text-slate-950">
                                    What needs attention
                                </h3>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-3 lg:grid-cols-4">
                            <a
                                v-for="signal in analystSignals"
                                :key="signal.label"
                                :href="signal.href"
                                :class="['rounded-lg border p-4 transition hover:bg-white', signalClass(signal.tone)]"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-md bg-white/70 shadow-sm">
                                        <i :class="[signal.icon, 'text-sm']"></i>
                                    </span>
                                    <p class="text-sm font-bold">
                                        {{ signal.label }}
                                    </p>
                                </div>
                                <p class="mt-2 text-sm leading-5 opacity-85">
                                    {{ signal.detail }}
                                </p>
                                <p class="mt-4 text-sm font-bold">
                                    {{ signal.action }}
                                </p>
                            </a>
                        </div>
                    </section>

                    <section class="grid gap-6 xl:grid-cols-[1fr_0.9fr]">
                        <article class="student-card p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="student-kicker">
                                        Top Matches
                                    </p>
                                    <h3 class="mt-2 text-lg font-bold text-slate-950">
                                        Programs worth checking
                                    </h3>
                                </div>
                                <a href="/dashboard/scholarships" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                    View all
                                </a>
                            </div>

                            <div class="mt-5 grid gap-3">
                                <a
                                    v-for="scholarship in topMatches"
                                    :key="scholarship.id"
                                    :href="`/dashboard/scholarships/${scholarship.id}`"
                                    class="rounded-md border border-slate-200 bg-[#f6faf8] p-3 transition hover:bg-white"
                                >
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="font-bold text-slate-950">
                                                {{ scholarship.title }}
                                            </p>
                                            <p class="mt-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                                                {{ scholarship.provider?.name || 'Scholarship Provider' }}
                                            </p>
                                        </div>
                                        <span class="w-fit rounded-md bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800">
                                            {{ scholarship.eligibility_match?.score ?? 0 }}% match
                                        </span>
                                    </div>
                                    <p class="mt-2 text-sm leading-6 text-slate-600">
                                        {{ scholarship.eligibility_match?.summary || 'Open the program to review eligibility.' }}
                                    </p>
                                </a>

                                <div v-if="topMatches.length === 0" class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                    No published scholarship matches are available yet.
                                </div>
                            </div>
                        </article>

                        <article class="student-card p-5">
                            <p class="student-kicker">
                                Watchlist
                            </p>
                            <h3 class="mt-2 text-lg font-bold text-slate-950">
                                Risks to clear before applying
                            </h3>

                            <div class="mt-5 grid gap-3">
                                <div v-if="urgentScholarships.length" class="rounded-md border border-amber-100 bg-amber-50 p-3">
                                    <p class="text-sm font-bold text-amber-900">
                                        Upcoming deadlines
                                    </p>
                                    <p
                                        v-for="scholarship in urgentScholarships"
                                        :key="scholarship.id"
                                        class="mt-2 text-sm text-amber-900"
                                    >
                                        {{ scholarship.title }}: {{ scholarship.days_left }} day{{ scholarship.days_left === 1 ? '' : 's' }} left
                                    </p>
                                </div>

                                <div v-if="documentGaps.length" class="rounded-md border border-sky-100 bg-sky-50 p-3">
                                    <p class="text-sm font-bold text-sky-900">
                                        Document gaps
                                    </p>
                                    <p
                                        v-for="scholarship in documentGaps"
                                        :key="scholarship.id"
                                        class="mt-2 text-sm text-sky-900"
                                    >
                                        {{ scholarship.title }}: {{ scholarship.prepared_documents.uploaded }} of {{ scholarship.prepared_documents.required }} ready
                                    </p>
                                </div>

                                <div v-if="applicationWatchlist.length" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="text-sm font-bold text-slate-900">
                                        Application follow-up
                                    </p>
                                    <p
                                        v-for="application in applicationWatchlist"
                                        :key="application.id"
                                        class="mt-2 text-sm text-slate-600"
                                    >
                                        {{ application.scholarship?.title || 'Application' }}: {{ application.status?.replaceAll('_', ' ') || 'submitted' }}
                                    </p>
                                </div>

                                <div v-if="!urgentScholarships.length && !documentGaps.length && !applicationWatchlist.length" class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                    No urgent risks found from the current dashboard data.
                                </div>
                            </div>
                        </article>
                    </section>

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
