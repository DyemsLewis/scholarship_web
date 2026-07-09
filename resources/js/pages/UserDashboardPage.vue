<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicantFooter from '../components/ApplicantFooter.vue';
import ApplicantPageHeader from '../components/ApplicantPageHeader.vue';
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
const nextSteps = ref([]);
const notifications = ref([]);
const quickTools = [
    {
        title: 'Recommended programs',
        text: 'Best-fit scholarships.',
        href: '/dashboard/scholarships',
        icon: 'fa-solid fa-wand-magic-sparkles',
    },
    {
        title: 'Prepared documents',
        text: 'Reusable files.',
        href: '/dashboard/documents',
        icon: 'fa-solid fa-list-check',
    },
    {
        title: 'Application wizard',
        text: 'Apply step by step.',
        href: '/dashboard/applications',
        icon: 'fa-solid fa-route',
    },
];

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
        return 'border-slate-200 bg-white text-slate-800';
    }

    if (tone === 'warn') {
        return 'border-slate-300 bg-white text-slate-900';
    }

    return 'border-slate-200 bg-white text-slate-800';
}

function signalAccentClass(tone) {
    if (tone === 'good') {
        return 'bg-slate-900 text-white';
    }

    if (tone === 'warn') {
        return 'bg-slate-700 text-white';
    }

    return 'bg-slate-100 text-slate-700';
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
                <ApplicantPageHeader
                    eyebrow="Dashboard"
                    :title="`Welcome back, ${user?.first_name || 'Scholar'}`"
                    description="A quick view of matches, files, and next actions."
                    icon="fa-solid fa-table-columns"
                    action-href="/dashboard/scholarships"
                    action-label="Browse scholarships"
                    secondary-href="/dashboard/profile"
                    secondary-label="Update profile"
                />

                <div v-if="isLoading" class="student-card mt-6 p-6 text-sm text-slate-500">
                    Loading applicant dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <section class="student-card p-4">
                        <div class="student-section-head">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark">
                                    <i class="fa-solid fa-compass text-sm"></i>
                                </span>
                                <div>
                                    <p class="student-kicker">
                                        To Do
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Needs attention
                                    </h3>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-2 lg:grid-cols-4">
                            <a
                                v-for="signal in analystSignals"
                                :key="signal.label"
                                :href="signal.href"
                                :class="['rounded-lg border p-3 transition hover:border-slate-400 hover:shadow-sm', signalClass(signal.tone)]"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-bold">
                                        {{ signal.label }}
                                    </p>
                                    <span :class="['flex h-7 w-7 items-center justify-center rounded-md', signalAccentClass(signal.tone)]">
                                        <i :class="[signal.icon, 'text-xs']"></i>
                                    </span>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-slate-600">
                                    {{ signal.detail }}
                                </p>
                                <p class="mt-3 text-xs font-bold text-slate-900">
                                    {{ signal.action }}
                                </p>
                            </a>
                        </div>
                    </section>

                    <section class="grid gap-3 md:grid-cols-3">
                        <a
                            v-for="tool in quickTools"
                            :key="tool.title"
                            :href="tool.href"
                            class="student-action-card flex items-start gap-3"
                        >
                            <span class="student-icon-badge">
                                <i :class="[tool.icon, 'text-sm']"></i>
                            </span>
                            <span class="relative">
                                <span class="block text-sm font-bold text-slate-950">
                                    {{ tool.title }}
                                </span>
                                <span class="mt-1 block text-xs leading-5 text-slate-600">
                                    {{ tool.text }}
                                </span>
                            </span>
                        </a>
                    </section>

                    <section class="grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
                        <div class="student-card p-5">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark">
                                    <i class="fa-solid fa-shoe-prints text-sm"></i>
                                </span>
                                <div>
                                    <p class="student-kicker">
                                        Next
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Continue here
                                    </h3>
                                </div>
                            </div>
                            <div v-if="nextSteps.length === 0" class="student-empty-state mt-4">
                                Nothing urgent right now.
                            </div>
                            <div v-else class="mt-4 grid gap-2">
                                <div
                                    v-for="(step, index) in nextSteps.slice(0, 4)"
                                    :key="step"
                                    class="flex gap-3 rounded-md bg-slate-50 p-3 ring-1 ring-slate-200/70"
                                >
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-950 text-xs font-bold text-amber-200">
                                        {{ index + 1 }}
                                    </span>
                                    <p class="line-clamp-2 text-sm leading-6 text-slate-600">
                                        {{ step }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="student-card p-5">
                            <div class="flex items-center gap-3">
                                <span class="student-section-mark">
                                    <i class="fa-solid fa-bell text-sm"></i>
                                </span>
                                <div>
                                    <p class="student-kicker">
                                        Updates
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
                                        Recent notices
                                    </h3>
                                </div>
                            </div>
                            <div v-if="notifications.length === 0" class="mt-5 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                No notifications yet.
                            </div>
                            <div v-else class="mt-5 grid gap-3">
                                <a
                                    v-for="notification in notifications"
                                    :key="notification.id"
                                    :href="notification.action_url || '/dashboard/applications'"
                                    class="rounded-md border border-slate-200/80 bg-slate-50 p-4 transition hover:bg-white"
                                >
                                    <div class="flex gap-3">
                                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-white text-slate-700 ring-1 ring-slate-200">
                                            <i class="fa-solid fa-envelope-open-text text-xs"></i>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-bold text-slate-950">
                                                {{ notification.title }}
                                            </span>
                                            <span class="mt-1 block line-clamp-2 text-sm leading-6 text-slate-600">
                                                {{ notification.message }}
                                            </span>
                                        </span>
                                    </div>
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
