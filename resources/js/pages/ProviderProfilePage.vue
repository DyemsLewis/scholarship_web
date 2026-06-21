<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);

const providerTypeLabels = {
    school: 'School / University',
    foundation: 'Foundation',
    government: 'Government Office',
    company: 'Company / Sponsor',
    non_profit: 'Non-profit Organization',
    other: 'Other Provider',
};
const contactPerson = computed(() => {
    if (!user.value?.first_name && !user.value?.last_name) {
        return 'Not provided';
    }

    const middle = user.value?.middle_initial ? `${user.value.middle_initial}.` : '';

    return [user.value.first_name, middle, user.value.last_name]
        .filter(Boolean)
        .join(' ');
});
const profileItems = computed(() => [
    { label: 'Organization', value: user.value?.provider_name || user.value?.name || 'Not provided' },
    { label: 'Provider type', value: providerTypeLabels[user.value?.provider_type] ?? 'Not provided' },
    { label: 'Contact person', value: contactPerson.value },
    { label: 'Email', value: user.value?.email || 'Not provided' },
    { label: 'Contact number', value: user.value?.contact_number || 'Not provided' },
    { label: 'Website', value: user.value?.provider_website || 'Not provided' },
    { label: 'Address', value: user.value?.provider_address || 'Not provided' },
]);

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

async function loadProviderProfile() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get('/provider/profile/data');

        user.value = response.data.user;
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider profile.';
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    await window.axios.post('/logout');
    window.location.href = '/';
}

onMounted(loadProviderProfile);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f1f6ff_0%,_#e7eef8_48%,_#f8fafc_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar @logout="logout" />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-5xl">
                <header class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-emerald-700">
                        Provider Profile
                    </p>
                    <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                        Account and organization details
                    </h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        Keep provider identity separate from dashboard metrics so the workspace stays easier to scan.
                    </p>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider profile...
                </div>

                <div v-else-if="errorMessage" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm">
                    {{ errorMessage }}
                </div>

                <div v-else class="mt-6 space-y-6">
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-slate-500">
                                    Organization
                                </p>
                                <h3 class="mt-2 font-display text-2xl font-bold text-slate-950">
                                    {{ user?.provider_name || user?.name || 'Provider' }}
                                </h3>
                                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                    {{ user?.provider_description || 'No provider description added yet.' }}
                                </p>
                            </div>
                            <span :class="['w-fit rounded-md px-3 py-1.5 text-xs font-bold uppercase', verificationClass(user?.verification_status)]">
                                {{ verificationLabel(user?.verification_status) }}
                            </span>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">
                            Profile Details
                        </p>

                        <div class="mt-5 grid gap-3 md:grid-cols-2">
                            <div
                                v-for="item in profileItems"
                                :key="item.label"
                                class="rounded-md border border-slate-200 bg-slate-50 p-4"
                            >
                                <p class="text-sm font-semibold text-slate-500">
                                    {{ item.label }}
                                </p>
                                <p class="mt-1 break-words text-sm font-bold text-slate-950">
                                    {{ item.value }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">
                            Access
                        </p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            {{ user?.can_post_scholarships ? 'This account is approved and can publish scholarships.' : 'This account needs admin approval before publishing scholarships.' }}
                        </p>
                        <p v-if="user?.verification_notes" class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm leading-6 text-slate-600">
                            {{ user.verification_notes }}
                        </p>
                    </section>
                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
