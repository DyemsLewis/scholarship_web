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

const providerTypeLabels = {
    school: 'School / University',
    foundation: 'Foundation',
    government: 'Government Office',
    company: 'Company / Sponsor',
    non_profit: 'Non-profit Organization',
    other: 'Other Provider',
};

const statCards = computed(() => [
    {
        label: 'Programs',
        value: stats.value.scholarships,
        description: 'Scholarship programs prepared by this provider.',
        href: '/provider/programs',
        className: 'text-sky-700',
        accent: 'bg-sky-600',
    },
    {
        label: 'Applications',
        value: stats.value.applications,
        description: 'Applicant records connected to provider programs.',
        href: '/provider/applications',
        className: 'text-emerald-700',
        accent: 'bg-emerald-600',
    },
    {
        label: 'Drafts',
        value: stats.value.drafts,
        description: 'Programs waiting to be completed.',
        href: '/provider/programs',
        className: 'text-amber-600',
        accent: 'bg-amber-500',
    },
]);

const contactPerson = computed(() => {
    if (!user.value?.first_name && !user.value?.last_name) {
        return 'Not provided';
    }

    const middle = user.value?.middle_initial ? `${user.value.middle_initial}.` : '';

    return [user.value.first_name, middle, user.value.last_name]
        .filter(Boolean)
        .join(' ');
});

function providerTypeLabel(type) {
    return providerTypeLabels[type] ?? 'Not provided';
}

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
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                                Provider Dashboard
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Overview and account details
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Use this dashboard for quick provider status only. Programs and applications now have their own pages.
                            </p>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                Signed in as
                            </p>
                            <p class="mt-1 text-sm font-bold text-slate-950">
                                {{ user?.name ?? 'Provider' }}
                            </p>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider dashboard...
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

                    <section class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                                Provider Profile
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Account details
                            </h3>
                            <div class="mt-5 grid gap-3 text-sm">
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Organization
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ user?.provider_name || user?.name }}
                                    </p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Provider type
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ providerTypeLabel(user?.provider_type) }}
                                    </p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Contact person
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ contactPerson }}
                                    </p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Email
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ user?.email }}
                                    </p>
                                </div>
                                <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p class="font-semibold text-slate-500">
                                        Contact number
                                    </p>
                                    <p class="mt-1 font-bold text-slate-950">
                                        {{ user?.contact_number || 'Not provided' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                                Quick Actions
                            </p>
                            <h3 class="mt-2 text-xl font-bold text-slate-950">
                                Continue provider work
                            </h3>
                            <div class="mt-5 grid gap-3">
                                <a
                                    href="/provider/programs"
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4 transition hover:border-emerald-200 hover:bg-emerald-50"
                                >
                                    <p class="font-bold text-slate-950">
                                        Create or edit scholarship programs
                                    </p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        Manage titles, requirements, deadlines, and publication status.
                                    </p>
                                </a>
                                <a
                                    href="/provider/applications"
                                    class="rounded-md border border-slate-200 bg-slate-50 p-4 transition hover:border-sky-200 hover:bg-sky-50"
                                >
                                    <p class="font-bold text-slate-950">
                                        Review applicant activity
                                    </p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">
                                        This area is prepared for application records once applicant submissions are connected.
                                    </p>
                                </a>
                            </div>
                        </div>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
