<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const stats = ref({
    scholarships: 0,
    applications: 0,
    drafts: 0,
});
const scholarships = ref([]);

const publishedCount = computed(() => scholarships.value.filter((scholarship) => scholarship.status === 'published').length);
const focusItems = computed(() => [
    {
        label: 'Draft programs',
        detail: stats.value.drafts > 0 ? 'Finish drafts before publishing.' : 'No drafts waiting.',
        href: '/provider/programs',
        action: stats.value.drafts > 0 ? 'Open programs' : 'Create program',
    },
    {
        label: 'Applications to review',
        detail: stats.value.applications > 0 ? 'Check applicant submissions.' : 'No submissions yet.',
        href: '/provider/applications',
        action: 'Review queue',
    },
    {
        label: 'Published programs',
        detail: publishedCount.value > 0 ? 'Visible to student applicants.' : 'Publish a program to appear in search.',
        href: '/provider/programs',
        action: 'Manage programs',
    },
]);
const recentPrograms = computed(() => scholarships.value.slice(0, 3));

function verificationLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function verificationClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function statusClass(status) {
    if (status === 'published') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'closed') {
        return 'bg-slate-200 text-slate-700';
    }

    return 'bg-amber-100 text-amber-800';
}

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/profile/data');

        user.value = response.data.user;
        stats.value = response.data.stats;
        scholarships.value = response.data.scholarships;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider dashboard.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-6xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                                Provider Dashboard
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Quick overview
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                A lighter dashboard for provider actions, review tasks, and recent scholarship records.
                            </p>
                        </div>

                        <a
                            href="/provider/profile"
                            class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        >
                            View profile
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider dashboard...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Focus Today
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            Provider tasks that may need attention
                        </h3>

                        <div class="mt-5 grid gap-3 lg:grid-cols-3">
                            <a
                                v-for="item in focusItems"
                                :key="item.label"
                                :href="item.href"
                                class="rounded-md border border-slate-200 bg-slate-50 p-4 transition hover:border-slate-300 hover:bg-white"
                            >
                                <p class="text-sm font-bold text-slate-950">
                                    {{ item.label }}
                                </p>
                                <p class="mt-1 text-sm leading-5 text-slate-500">
                                    {{ item.detail }}
                                </p>
                                <p class="mt-4 text-sm font-bold text-slate-700">
                                    {{ item.action }}
                                </p>
                            </a>
                        </div>
                    </section>

                    <section class="grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                Provider Status
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                {{ user?.provider_name || user?.name || 'Provider account' }}
                            </h3>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <span :class="['rounded-md px-2.5 py-1 text-xs font-bold uppercase', verificationClass(user?.verification_status)]">
                                    {{ verificationLabel(user?.verification_status) }}
                                </span>
                            </div>
                            <p class="mt-4 text-sm leading-6 text-slate-600">
                                {{ user?.can_post_scholarships ? 'This provider can publish and manage scholarship programs.' : 'Admin approval is needed before this provider can publish scholarships.' }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                        Recent Programs
                                    </p>
                                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                                        Latest scholarship records
                                    </h3>
                                </div>
                                <a
                                    href="/provider/programs"
                                    class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                                >
                                    Manage programs
                                </a>
                            </div>

                            <div class="mt-5 grid gap-3">
                                <div
                                    v-for="program in recentPrograms"
                                    :key="program.id"
                                    class="flex flex-col gap-2 rounded-md border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-950">
                                            {{ program.title }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Updated {{ program.updated_at || 'recently' }}
                                        </p>
                                    </div>
                                    <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold uppercase', statusClass(program.status)]">
                                        {{ program.status }}
                                    </span>
                                </div>

                                <div v-if="recentPrograms.length === 0" class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                    No programs yet.
                                </div>
                            </div>
                        </article>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">
                            Shortcuts
                        </p>
                        <div class="mt-4 grid gap-3 sm:grid-cols-3">
                            <a href="/provider/programs" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                Create or edit programs
                            </a>
                            <a href="/provider/applications" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                Review applications
                            </a>
                            <a href="/provider/profile" class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100">
                                Check profile
                            </a>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
