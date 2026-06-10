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

const publishedPrograms = computed(() => scholarships.value.filter((scholarship) => scholarship.status === 'published'));

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/profile');

        user.value = response.data.user;
        stats.value = response.data.stats;
        scholarships.value = response.data.scholarships;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider applications.';
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
            <div class="mx-auto max-w-7xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Application Review
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Applicant activity queue
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                This page is reserved for reviewing submissions once applicant application records are connected to scholarship programs.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Provider
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.provider_name || user?.name || 'Provider' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading application review page...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <div class="grid gap-4 md:grid-cols-3">
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Total Applications
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-emerald-700">
                                {{ stats.applications }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Published Programs
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-sky-700">
                                {{ publishedPrograms.length }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Review Status
                            </p>
                            <p class="mt-3 font-display text-2xl font-bold text-amber-600">
                                Pending Setup
                            </p>
                        </article>
                    </div>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                            Review Queue
                        </p>
                        <h3 class="mt-2 text-xl font-bold text-slate-950">
                            No applicant records yet
                        </h3>
                        <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                            The provider application review page is separate now. The next backend step is to add an applications table that connects applicants to scholarships.
                        </p>

                        <div class="mt-5 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm text-slate-500">
                            Once applications exist, this area can show applicant name, scholarship program, submitted documents, review status, and action buttons.
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                    Active Programs
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Programs ready for future applicants
                                </h3>
                            </div>
                            <a
                                href="/provider/programs"
                                class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                            >
                                Manage programs
                            </a>
                        </div>

                        <div v-if="publishedPrograms.length === 0" class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">
                            No published programs yet.
                        </div>

                        <div v-else class="mt-5 grid gap-3 md:grid-cols-2">
                            <article
                                v-for="program in publishedPrograms"
                                :key="program.id"
                                class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                            >
                                <p class="font-bold text-slate-950">
                                    {{ program.title }}
                                </p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">
                                    Deadline: {{ program.deadline || 'Not set' }}
                                </p>
                            </article>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
