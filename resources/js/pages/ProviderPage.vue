<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderFooter from '../components/ProviderFooter.vue';
import ProviderSidebar from '../components/ProviderSidebar.vue';

const isLoading = ref(true);
const errorMessage = ref('');
const user = ref(null);
const scholarships = ref([]);
const reviewQueue = ref([]);

const recentPrograms = computed(() => scholarships.value.slice(0, 3));
const verificationDocumentCount = computed(() => Number(user.value?.verification_documents_count ?? 0));
const verificationPrompt = computed(() => {
    if (!user.value?.email_verified) {
        return {
            title: 'Verify your email to continue',
            description: verificationDocumentCount.value
                ? 'Your proof is saved. Verify your email so an admin can complete the provider review.'
                : 'Verify your email, then upload organization proof for admin review.',
            action: verificationDocumentCount.value ? 'View verification' : 'Upload proof',
        };
    }

    if (user.value?.verification_status === 'rejected') {
        return {
            title: 'Update your verification proof',
            description: 'Review the admin feedback and upload a replacement document to return your account for review.',
            action: 'Upload replacement proof',
        };
    }

    if (verificationDocumentCount.value === 0) {
        return {
            title: 'Verify your provider account',
            description: 'Upload organization registration, an authorization letter, or another valid proof for admin review.',
            action: 'Upload proof',
        };
    }

    return {
        title: 'Verification is under review',
        description: 'Your proof has been submitted. You can create programs after an admin approves the provider account.',
        action: 'View verification status',
    };
});
const programHealthSignals = computed(() => {
    const draftPrograms = scholarships.value.filter((scholarship) => scholarship.status === 'draft');
    const missingDocuments = scholarships.value.filter((scholarship) => !hasText(scholarship.requirements));
    const missingLocations = scholarships.value.filter((scholarship) => !hasText(scholarship.location_address) || !hasText(scholarship.latitude) || !hasText(scholarship.longitude));
    const expiredPublished = scholarships.value.filter((scholarship) => scholarship.status === 'published' && deadlineDays(scholarship.deadline) !== null && deadlineDays(scholarship.deadline) < 0);

    return [
        {
            label: 'Draft completion',
            icon: 'fa-solid fa-pen-ruler',
            tone: draftPrograms.length ? 'warn' : 'good',
            detail: draftPrograms.length ? `${draftPrograms.length} draft${draftPrograms.length === 1 ? '' : 's'} waiting.` : 'Clear.',
            href: '/provider/programs',
            action: 'Open programs',
        },
        {
            label: 'Document quality',
            icon: 'fa-solid fa-list-check',
            tone: missingDocuments.length ? 'warn' : 'good',
            detail: missingDocuments.length ? `${missingDocuments.length} missing docs.` : 'Clear.',
            href: '/provider/programs',
            action: 'Review requirements',
        },
        {
            label: 'Location coverage',
            icon: 'fa-solid fa-location-dot',
            tone: missingLocations.length ? 'info' : 'good',
            detail: missingLocations.length ? `${missingLocations.length} need map pins.` : 'Clear.',
            href: '/provider/programs',
            action: 'Check maps',
        },
        {
            label: 'Deadline risk',
            icon: 'fa-solid fa-calendar-day',
            tone: expiredPublished.length ? 'warn' : 'good',
            detail: expiredPublished.length ? `${expiredPublished.length} expired.` : 'Clear.',
            href: '/provider/programs',
            action: 'Update deadlines',
        },
    ];
});

function hasText(value) {
    return value !== null && value !== undefined && String(value).trim() !== '';
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

function signalClass(tone) {
    if (tone === 'good') {
        return 'bg-slate-100 text-slate-600';
    }

    if (tone === 'warn') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-200 text-slate-700';
}

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
        const response = await window.axios.get('/provider/dashboard/data');

        user.value = response.data.user;
        scholarships.value = response.data.scholarships;
        reviewQueue.value = response.data.review_queue ?? [];
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Unable to load provider dashboard.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadProviderData);
</script>

<template>
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <ProviderSidebar />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-6xl">
                <header class="provider-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">
                                Provider Dashboard
                            </p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
                                Program workspace
                            </h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Manage scholarship programs and applicant reviews from one focused view.
                            </p>
                        </div>

                        <a
                            href="/provider/programs"
                            class="rounded-md border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                        >
                            Manage programs
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
                    <section
                        :class="[
                            'flex flex-col gap-4 rounded-lg border p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between',
                            user?.can_post_scholarships
                                ? 'border-slate-200 bg-white'
                                : 'border-amber-200 bg-amber-50',
                        ]"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                :class="[
                                    'flex h-11 w-11 shrink-0 items-center justify-center rounded-md',
                                    user?.can_post_scholarships
                                        ? 'bg-slate-950 text-amber-200'
                                        : 'bg-amber-200 text-amber-900',
                                ]"
                            >
                                <i :class="[user?.can_post_scholarships ? 'fa-solid fa-building-columns' : 'fa-solid fa-shield-halved', 'text-sm']"></i>
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="truncate text-lg font-bold text-slate-950">
                                        {{ user?.provider_name || user?.name || 'Provider account' }}
                                    </h3>
                                    <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', verificationClass(user?.verification_status)]">
                                        {{ verificationLabel(user?.verification_status) }}
                                    </span>
                                </div>
                                <p :class="['mt-1 text-sm font-bold', user?.can_post_scholarships ? 'text-slate-600' : 'text-amber-950']">
                                    {{ user?.can_post_scholarships ? 'Verified and ready to create programs.' : verificationPrompt.title }}
                                </p>
                                <p v-if="!user?.can_post_scholarships" class="mt-1 max-w-2xl text-sm leading-6 text-amber-900/80">
                                    {{ verificationPrompt.description }}
                                </p>
                                <p v-if="!user?.can_post_scholarships && user?.verification_notes" class="mt-2 text-xs leading-5 text-amber-900">
                                    <span class="font-bold">Admin note:</span> {{ user.verification_notes }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <a
                                :href="user?.can_post_scholarships ? '/provider/profile' : '/provider/profile#verification-documents'"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-center text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                            >
                                {{ user?.can_post_scholarships ? 'Profile' : verificationPrompt.action }}
                            </a>
                            <a v-if="user?.can_post_scholarships" href="/provider/programs/create" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-800">New program</a>
                        </div>
                    </section>

                    <section v-if="reviewQueue.length" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Application Review
                                </p>
                                <h3 class="mt-2 text-xl font-bold text-slate-950">
                                    Applications needing attention
                                </h3>
                            </div>
                            <a href="/provider/applications" class="rounded-md bg-slate-900 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800">
                                View review queue
                            </a>
                        </div>

                        <div class="mt-4 grid gap-3 lg:grid-cols-3">
                            <a
                                v-for="application in reviewQueue"
                                :key="application.id"
                                :href="application.detail_url"
                                class="flex h-full min-w-0 flex-col rounded-md border border-slate-200 bg-slate-50 p-3.5 transition hover:border-slate-300 hover:bg-white"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <p class="line-clamp-2 min-h-10 text-sm font-bold leading-5 text-slate-950">
                                        {{ application.applicant || 'Applicant' }}
                                    </p>
                                    <span class="shrink-0 rounded-md bg-slate-200 px-2 py-1 text-[10px] font-bold uppercase text-slate-700">
                                        {{ verificationLabel(application.status) }}
                                    </span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">
                                    {{ application.scholarship || 'Scholarship program' }}
                                </p>
                                <div class="mt-auto flex items-center justify-between gap-3 pt-4 text-xs font-bold text-slate-600">
                                    <span>{{ application.pending_documents }} pending file{{ application.pending_documents === 1 ? '' : 's' }}</span>
                                    <span>{{ application.submitted_at }}</span>
                                </div>
                            </a>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                    Program Health
                                </p>
                                <h3 class="mt-1 text-lg font-bold text-slate-950">
                                    Checks before publishing
                                </h3>
                            </div>
                        </div>

                        <div class="divide-y divide-slate-200">
                            <a
                                v-for="signal in programHealthSignals"
                                :key="signal.label"
                                :href="signal.href"
                                class="group flex items-center gap-3 px-5 py-3.5 transition hover:bg-slate-50"
                            >
                                <span :class="['flex h-9 w-9 shrink-0 items-center justify-center rounded-md', signalClass(signal.tone)]">
                                    <i :class="[signal.icon, 'text-xs']"></i>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-bold text-slate-950">{{ signal.label }}</span>
                                    <span class="mt-0.5 block text-sm text-slate-500">{{ signal.detail }}</span>
                                </span>
                                <span class="hidden text-xs font-bold text-slate-500 sm:block">{{ signal.action }}</span>
                                <i class="fa-solid fa-chevron-right text-[10px] text-slate-300 transition group-hover:text-slate-600"></i>
                            </a>
                        </div>
                    </section>

                    <section>
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">
                                        Recent Programs
                                    </p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">
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

                            <div v-if="recentPrograms.length" class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                <a
                                    v-for="program in recentPrograms"
                                    :key="program.id"
                                    :href="`/provider/programs/${program.id}/edit`"
                                    class="flex flex-col gap-2 bg-white p-3 transition hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between"
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
                                        {{ verificationLabel(program.status) }}
                                    </span>
                                </a>

                            </div>
                            <div v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                                No programs yet. Create the first scholarship when your provider account is approved.
                            </div>
                        </article>
                    </section>

                </div>

                <ProviderFooter />
            </div>
        </section>
    </main>
</template>
