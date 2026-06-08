<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const stats = ref({
    total_users: 0,
    admins: 0,
    applicants: 0,
    providers: 0,
    recent_signups: 0,
});

const reviewQueues = computed(() => [
    {
        title: 'Provider Accounts',
        count: stats.value.providers,
        label: 'registered providers',
        description: 'Review provider records before scholarship posting tools are connected.',
        status: 'Ready for account review',
        accent: 'bg-sky-600',
    },
    {
        title: 'Applicant Profiles',
        count: stats.value.applicants,
        label: 'registered applicants',
        description: 'Monitor applicant profiles and keep account information organized.',
        status: 'Ready for profile checks',
        accent: 'bg-emerald-600',
    },
    {
        title: 'Scholarship Listings',
        count: 0,
        label: 'pending listings',
        description: 'This queue will activate when scholarship program tables are added.',
        status: 'Waiting for scholarship module',
        accent: 'bg-amber-500',
    },
    {
        title: 'Applications',
        count: 0,
        label: 'pending applications',
        description: 'This queue will activate when applicant application records are added.',
        status: 'Waiting for application module',
        accent: 'bg-slate-600',
    },
]);

async function loadReviewData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/admin/users');

        stats.value = response.data.stats;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load review details.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadReviewData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="reviews" @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
                                Reviews
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Admin Review Queues
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Track the review areas that belong to the administrator workspace.
                            </p>
                        </div>

                        <a
                            href="/admin/manage-users"
                            class="rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                        >
                            Open Users
                        </a>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading review details...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 grid gap-4 md:grid-cols-2">
                    <article
                        v-for="queue in reviewQueues"
                        :key="queue.title"
                        class="relative overflow-hidden rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
                    >
                        <div :class="['absolute left-0 top-0 h-full w-1', queue.accent]"></div>
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">
                            {{ queue.status }}
                        </p>
                        <h3 class="mt-3 text-xl font-bold text-slate-950">
                            {{ queue.title }}
                        </h3>
                        <p class="mt-4 font-display text-4xl font-bold text-slate-950">
                            {{ queue.count }}
                        </p>
                        <p class="mt-1 text-sm font-semibold text-slate-500">
                            {{ queue.label }}
                        </p>
                        <p class="mt-4 text-sm leading-6 text-slate-600">
                            {{ queue.description }}
                        </p>
                    </article>
                </div>

                <div class="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                        Review Note
                    </p>
                    <h3 class="mt-2 text-xl font-bold text-slate-950">
                        Scholarship and application reviews need their own tables next
                    </h3>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        The page is ready in the admin panel. Once scholarship programs and applicant applications are added to the database, these queues can show real pending reviews and approval actions.
                    </p>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
