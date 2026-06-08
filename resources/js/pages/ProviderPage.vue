<script setup>
import { onMounted, ref } from 'vue';
import SiteFooter from '../components/SiteFooter.vue';
import SiteNavbar from '../components/SiteNavbar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const stats = ref({
    scholarships: 0,
    applications: 0,
    drafts: 0,
});

async function loadProviderData() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/profile');

        user.value = response.data.user;
        stats.value = response.data.stats;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider dashboard.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/login';
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-slate-50 text-slate-900">
        <SiteNavbar />

        <section class="border-b border-slate-200 bg-white px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto flex max-w-6xl flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">
                        Provider Panel
                    </p>
                    <h1 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Scholarship Provider Workspace
                    </h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Manage scholarship programs and review applicant activity from one provider account.
                    </p>
                </div>

                <button
                    type="button"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
                    @click="logout"
                >
                    Logout
                </button>
            </div>
        </section>

        <section class="px-4 py-8 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl">
                <div v-if="isLoading" class="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider dashboard...
                </div>

                <div v-else-if="errorMessage" class="rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">
                    {{ errorMessage }}
                </div>

                <div v-else class="space-y-6">
                    <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-semibold text-slate-500">
                            Signed in as
                        </p>
                        <h2 class="mt-2 text-xl font-bold text-slate-950">
                            {{ user?.name }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ user?.email }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Scholarships
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-sky-700">
                                {{ stats.scholarships }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Applications
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-emerald-700">
                                {{ stats.applications }}
                            </p>
                        </article>

                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <p class="text-sm font-semibold text-slate-500">
                                Drafts
                            </p>
                            <p class="mt-3 font-display text-3xl font-bold text-amber-600">
                                {{ stats.drafts }}
                            </p>
                        </article>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-lg font-bold text-slate-950">
                            Scholarship Management
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Scholarship posting and applicant review tools can be added here once the scholarship tables are created.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <SiteFooter />
    </main>
</template>
