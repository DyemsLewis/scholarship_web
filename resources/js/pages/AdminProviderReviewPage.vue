<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import FilePreviewModal from '../components/FilePreviewModal.vue';
import { formatFileSize } from '../support/display';

const appElement = document.getElementById('app');
const providerId = appElement?.dataset.providerId;
const isLoading = ref(true);
const isSaving = ref(false);
const loadError = ref('');
const decisionError = ref('');
const provider = ref(null);
const reviewNote = ref('');
const previewDocument = ref(null);
const requestedSection = new URLSearchParams(window.location.search).get('section');
const reviewSections = [
    { key: 'organization', label: 'Provider record', icon: 'fa-solid fa-building' },
    { key: 'representative', label: 'Representative', icon: 'fa-solid fa-user-tie' },
    { key: 'proof', label: 'Evidence', icon: 'fa-solid fa-file-shield' },
    { key: 'decision', label: 'Decision', icon: 'fa-solid fa-gavel' },
];
const activeReviewSection = ref(reviewSections.some((section) => section.key === requestedSection) ? requestedSection : 'organization');
const activeReviewSectionIndex = computed(() => reviewSections.findIndex((section) => section.key === activeReviewSection.value));
const previousReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value - 1] ?? null);
const nextReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value + 1] ?? null);
const providerProofCount = computed(() => provider.value?.verification_documents?.length ?? 0);
const representativeName = computed(() => {
    const current = provider.value ?? {};

    return [
        current.first_name,
        current.middle_initial ? `${current.middle_initial}.` : '',
        current.last_name,
    ].filter(Boolean).join(' ') || current.username || 'Not provided';
});
const providerReviewChecks = computed(() => {
    const current = provider.value ?? {};

    return [
        {
            label: 'Organization profile',
            detail: current.provider_name && current.provider_type && current.provider_description
                ? 'Name, type, and organization description are provided.'
                : 'Confirm the organization name, type, and description.',
            ready: Boolean(current.provider_name && current.provider_type && current.provider_description),
        },
        {
            label: 'Public contact',
            detail: current.provider_contact_email || current.provider_contact_number
                ? 'Applicants have an organization contact channel.'
                : 'No public provider email or contact number is saved.',
            ready: Boolean(current.provider_contact_email || current.provider_contact_number),
        },
        {
            label: 'Representative account',
            detail: current.email_verified
                ? 'The representative email address is verified.'
                : 'The representative email address is not verified.',
            ready: Boolean(current.email_verified),
        },
        {
            label: 'Organization evidence',
            detail: providerProofCount.value
                ? `${providerProofCount.value} verification file${providerProofCount.value === 1 ? '' : 's'} submitted.`
                : 'No organization proof has been submitted.',
            ready: providerProofCount.value > 0,
        },
    ];
});
const providerAttentionCount = computed(() => providerReviewChecks.value.filter((check) => !check.ready).length);
const reviewFocus = computed(() => {
    const status = provider.value?.verification_status ?? 'pending';

    if (status === 'approved') {
        return {
            eyebrow: 'Verification complete',
            title: 'Publishing access is approved',
            description: 'The organization record and submitted proof have an approved verification decision.',
            icon: 'fa-solid fa-check',
            section: 'decision',
            action: 'View decision',
        };
    }

    if (status === 'rejected') {
        return {
            eyebrow: 'Provider action needed',
            title: 'Corrections were requested',
            description: 'Review the decision note and submitted evidence while the provider prepares an updated record.',
            icon: 'fa-solid fa-rotate',
            section: 'decision',
            action: 'View decision',
        };
    }

    if (!providerProofCount.value) {
        return {
            eyebrow: 'Waiting on provider',
            title: 'No organization proof submitted',
            description: 'The provider must upload verification evidence before the organization can be fully reviewed.',
            icon: 'fa-regular fa-clock',
            section: 'proof',
            action: 'Check evidence',
        };
    }

    return {
        eyebrow: 'Review needed',
        title: 'Confirm the organization and its evidence',
        description: 'Compare the provider record with the submitted proof, then record the verification decision.',
        icon: 'fa-solid fa-arrow-right',
        section: 'proof',
        action: 'Open evidence',
    };
});

function selectReviewSection(section) {
    activeReviewSection.value = section;

    const url = new URL(window.location.href);
    url.searchParams.set('section', section);
    window.history.replaceState(window.history.state, '', url);
}

function statusLabel(status) {
    return String(status ?? 'pending')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    return 'bg-amber-100 text-amber-800';
}

function documentStatusClass(status) {
    if (['accepted', 'approved'].includes(status)) {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'rejected') {
        return 'bg-rose-100 text-rose-800';
    }

    if (status === 'needs_replacement') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-slate-100 text-slate-700';
}

function readinessStatusClass(ready) {
    return ready
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-900';
}

function documentTypeLabel(type) {
    return statusLabel(type || 'document');
}

function openDocumentPreview(document) {
    previewDocument.value = document;
}

function closeDocumentPreview() {
    previewDocument.value = null;
}

function providerInitials(currentProvider) {
    return String(currentProvider?.provider_name || currentProvider?.name || 'Provider')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase();
}

function providerWebsiteUrl(website) {
    const value = String(website ?? '').trim();

    if (!value) {
        return null;
    }

    return /^https?:\/\//i.test(value) ? value : `https://${value}`;
}

function providerActionOptions(currentProvider) {
    const status = currentProvider?.verification_status ?? 'pending';
    const actions = [];

    if (status !== 'approved') {
        actions.push({
            status: 'approved',
            label: 'Approve provider',
            className: 'bg-slate-950 text-white hover:bg-slate-800',
        });
    }

    if (status !== 'rejected') {
        actions.push({
            status: 'rejected',
            label: 'Reject provider',
            className: 'border border-rose-200 bg-white text-rose-700 hover:bg-rose-50',
        });
    }

    if (status !== 'pending') {
        actions.push({
            status: 'pending',
            label: 'Move to pending',
            className: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
        });
    }

    return actions;
}

function applyProvider(payload) {
    provider.value = payload;
    reviewNote.value = payload?.verification_notes ?? '';
}

async function loadProvider() {
    isLoading.value = true;
    loadError.value = '';
    decisionError.value = '';

    try {
        const response = await window.axios.get(`/admin/providers/${providerId}/review/data`);
        applyProvider(response.data.provider);
    } catch (error) {
        loadError.value = error.response?.data?.message ?? 'Unable to load provider review details.';
    } finally {
        isLoading.value = false;
    }
}

async function updateProvider(verificationStatus) {
    if (!provider.value || provider.value.verification_status === verificationStatus) {
        return;
    }

    const verificationNote = reviewNote.value.trim();

    if (verificationStatus === 'rejected' && !verificationNote) {
        decisionError.value = 'Add a rejection reason before rejecting this provider.';
        return;
    }

    isSaving.value = true;
    decisionError.value = '';

    try {
        const response = await window.axios.patch(`/admin/providers/${providerId}/verification`, {
            verification_status: verificationStatus,
            verification_notes: verificationNote,
        });

        applyProvider(response.data.provider);
    } catch (error) {
        decisionError.value = error.response?.data?.message ?? 'Unable to save the provider decision.';
    } finally {
        isSaving.value = false;
    }
}

onMounted(loadProvider);
</script>

<template>
    <main class="admin-shell">
        <AdminSidebar active="reviews" />

        <FilePreviewModal
            :file="previewDocument"
            :title="documentTypeLabel(previewDocument?.document_type)"
            :context="provider?.provider_name || provider?.name || 'Provider'"
            @close="closeDocumentPreview"
        />

        <section class="admin-page">
            <div class="admin-container">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Provider review</p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">Verify provider organization</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">Confirm the organization record and supporting proof before granting publishing access.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a
                                href="/admin/reviews?type=providers"
                                class="inline-flex items-center rounded-md border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            >
                                Back to reviews
                            </a>
                            <button
                                type="button"
                                class="w-fit rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                                @click="loadProvider"
                            >
                                Refresh
                            </button>
                            <button
                                v-if="activeReviewSection !== 'decision'"
                                type="button"
                                class="w-fit rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800"
                                @click="selectReviewSection('decision')"
                            >
                                Record decision
                            </button>
                        </div>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider review details...
                </div>

                <div v-else-if="loadError || !provider" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 shadow-sm">
                    <p class="text-sm font-bold text-rose-800">Provider details could not be loaded</p>
                    <p class="mt-1 text-sm leading-6 text-rose-700">{{ loadError }}</p>
                </div>

                <div v-else class="mt-6 space-y-4">
                    <section class="admin-panel overflow-hidden">
                        <div class="flex flex-col gap-4 border-l-4 border-l-amber-400 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <img
                                    v-if="provider.provider_logo_url"
                                    :src="provider.provider_logo_url"
                                    :alt="`${provider.provider_name || provider.name} logo`"
                                    class="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1 ring-1 ring-slate-200"
                                >
                                <div v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-bold tracking-[0.08em] text-white">
                                    {{ providerInitials(provider) }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Provider organization</p>
                                    <h3 class="mt-1 truncate text-lg font-bold text-slate-950">{{ provider.provider_name || provider.name }}</h3>
                                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
                                        <span>{{ provider.email }}</span>
                                        <span>{{ provider.contact_number || 'No contact number' }}</span>
                                    </div>
                                </div>
                            </div>
                            <span :class="['w-fit shrink-0 rounded-md px-3 py-1.5 text-xs font-bold uppercase', statusClass(provider.verification_status)]">
                                {{ statusLabel(provider.verification_status) }}
                            </span>
                        </div>

                        <dl class="grid border-t border-slate-200 bg-slate-50/80 text-sm sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                            <div class="border-b border-slate-200 p-3 sm:border-r xl:border-b-0">
                                <dt class="text-xs font-semibold text-slate-500">Provider type</dt>
                                <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(provider.provider_type || 'not provided') }}</dd>
                            </div>
                            <div class="border-b border-slate-200 p-3 lg:border-r xl:border-b-0">
                                <dt class="text-xs font-semibold text-slate-500">Representative</dt>
                                <dd class="mt-1 truncate font-bold text-slate-950">{{ representativeName }}</dd>
                            </div>
                            <div class="border-b border-slate-200 p-3 sm:border-r lg:border-r-0 xl:border-b-0 xl:border-r">
                                <dt class="text-xs font-semibold text-slate-500">Email account</dt>
                                <dd class="mt-1 font-bold text-slate-950">{{ provider.email_verified ? 'Verified' : 'Not verified' }}</dd>
                            </div>
                            <div class="border-b border-slate-200 p-3 lg:border-b-0 lg:border-r">
                                <dt class="text-xs font-semibold text-slate-500">Evidence</dt>
                                <dd class="mt-1 font-bold text-slate-950">{{ providerProofCount ? `${providerProofCount} file${providerProofCount === 1 ? '' : 's'}` : 'Not submitted' }}</dd>
                            </div>
                            <div class="border-b border-slate-200 p-3 sm:border-b-0 sm:border-r">
                                <dt class="text-xs font-semibold text-slate-500">Programs</dt>
                                <dd class="mt-1 font-bold text-slate-950">{{ provider.programs_count || 0 }} total</dd>
                            </div>
                            <div class="p-3">
                                <dt class="text-xs font-semibold text-slate-500">Team accounts</dt>
                                <dd class="mt-1 font-bold text-slate-950">{{ provider.team_members_count || 0 }}</dd>
                            </div>
                        </dl>

                        <div class="flex flex-col gap-4 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-950 text-sm text-amber-300">
                                    <i :class="reviewFocus.icon" aria-hidden="true"></i>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">{{ reviewFocus.eyebrow }}</p>
                                    <p class="mt-1 text-sm font-bold text-slate-950">{{ reviewFocus.title }}</p>
                                    <p class="mt-1 max-w-3xl text-xs leading-5 text-slate-500">{{ reviewFocus.description }}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="w-fit shrink-0 rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800"
                                @click="selectReviewSection(reviewFocus.section)"
                            >
                                {{ reviewFocus.action }}
                            </button>
                        </div>
                    </section>

                    <section class="admin-panel overflow-hidden">
                        <nav class="grid gap-1 p-1 sm:grid-cols-2 xl:grid-cols-4" aria-label="Provider verification sections">
                            <button
                                v-for="section in reviewSections"
                                :key="section.key"
                                type="button"
                                :aria-current="activeReviewSection === section.key ? 'step' : undefined"
                                :class="[
                                    'flex items-center gap-3 rounded-md px-3 py-2.5 text-left transition',
                                    activeReviewSection === section.key
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-700 hover:bg-slate-50 hover:text-slate-950',
                                ]"
                                @click="selectReviewSection(section.key)"
                            >
                                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs', activeReviewSection === section.key ? 'bg-white/10 text-amber-300' : 'bg-slate-100 text-slate-600']"><i :class="section.icon" aria-hidden="true"></i></span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-bold">{{ section.label }}</span>
                                    <span :class="['mt-0.5 block truncate text-xs', activeReviewSection === section.key ? 'text-slate-300' : 'text-slate-500']">
                                        <template v-if="section.key === 'organization'">Identity and contact</template>
                                        <template v-else-if="section.key === 'representative'">Account and access</template>
                                        <template v-else-if="section.key === 'proof'">{{ providerProofCount ? `${providerProofCount} submitted` : 'No evidence' }}</template>
                                        <template v-else>{{ statusLabel(provider.verification_status) }}</template>
                                    </span>
                                </span>
                            </button>
                        </nav>
                    </section>

                    <div v-if="activeReviewSection !== 'decision'" class="space-y-4">
                        <article v-if="activeReviewSection === 'organization'" class="admin-panel p-5">
                            <div class="flex items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-building" aria-hidden="true"></i></span>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Organization information</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Provider record</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">Confirm who the provider is and how applicants can identify or contact it.</p>
                                </div>
                            </div>

                            <section class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white text-slate-700 ring-1 ring-slate-200">
                                        <i class="fa-solid fa-building" aria-hidden="true"></i>
                                    </span>
                                    <div>
                                        <h4 class="font-bold text-slate-950">About the organization</h4>
                                        <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">
                                            {{ provider.provider_description || 'No organization description provided.' }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <dl class="mt-4 grid overflow-hidden rounded-md border border-slate-200 text-sm md:grid-cols-2">
                                <div class="border-b border-slate-200 p-4 md:border-r">
                                    <dt class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                        <i class="fa-solid fa-globe" aria-hidden="true"></i>
                                        Website
                                    </dt>
                                    <dd class="mt-2 break-words font-bold text-slate-950">
                                        <a
                                            v-if="providerWebsiteUrl(provider.provider_website)"
                                            :href="providerWebsiteUrl(provider.provider_website)"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-sky-700 underline decoration-sky-200 underline-offset-2 hover:text-sky-900"
                                        >
                                            {{ provider.provider_website }}
                                        </a>
                                        <span v-else>Not provided</span>
                                    </dd>
                                </div>
                                <div class="border-b border-slate-200 p-4">
                                    <dt class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                        Address
                                    </dt>
                                    <dd class="mt-2 font-bold leading-6 text-slate-950">{{ provider.provider_address || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 md:border-b-0 md:border-r">
                                    <dt class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                        <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                                        Provider email
                                    </dt>
                                    <dd class="mt-2 break-words font-bold text-slate-950">{{ provider.provider_contact_email || 'Not provided' }}</dd>
                                </div>
                                <div class="p-4">
                                    <dt class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                        <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                        Provider contact number
                                    </dt>
                                    <dd class="mt-2 font-bold text-slate-950">{{ provider.provider_contact_number || 'Not provided' }}</dd>
                                </div>
                            </dl>
                        </article>

                        <article v-if="activeReviewSection === 'representative'" class="admin-panel overflow-hidden">
                            <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-user-tie" aria-hidden="true"></i></span>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Account representative</p>
                                        <h3 class="mt-1 text-xl font-bold text-slate-950">Representative and account access</h3>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">Confirm who controls the provider account. These sign-in details are separate from the public organization contact.</p>
                                    </div>
                                </div>
                                <span :class="['w-fit rounded-md px-2.5 py-1 text-xs font-bold', readinessStatusClass(provider.email_verified)]">
                                    {{ provider.email_verified ? 'Email verified' : 'Email not verified' }}
                                </span>
                            </div>

                            <dl class="grid text-sm sm:grid-cols-2 lg:grid-cols-3">
                                <div class="border-b border-slate-200 p-4 sm:border-r">
                                    <dt class="font-semibold text-slate-500">Representative name</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ representativeName }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 lg:border-r">
                                    <dt class="font-semibold text-slate-500">Sign-in email</dt>
                                    <dd class="mt-1 break-words font-bold text-slate-950">{{ provider.email || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 sm:border-r lg:border-r-0">
                                    <dt class="font-semibold text-slate-500">Username</dt>
                                    <dd class="mt-1 break-words font-bold text-slate-950">{{ provider.username || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 lg:border-b-0 lg:border-r">
                                    <dt class="font-semibold text-slate-500">Representative contact</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ provider.contact_number || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                                    <dt class="font-semibold text-slate-500">Account status</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(provider.account_status || 'active') }}</dd>
                                </div>
                                <div class="p-4">
                                    <dt class="font-semibold text-slate-500">Registered</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ provider.created_at || 'Not provided' }}</dd>
                                </div>
                            </dl>

                            <div class="border-t border-slate-200 bg-slate-50 px-5 py-4">
                                <p class="text-sm font-bold text-slate-950">Organization activity</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    {{ provider.programs_count || 0 }} program{{ provider.programs_count === 1 ? '' : 's' }},
                                    {{ provider.published_programs_count || 0 }} published,
                                    {{ provider.programs_in_review_count || 0 }} in review, and
                                    {{ provider.team_members_count || 0 }} team account{{ provider.team_members_count === 1 ? '' : 's' }}.
                                </p>
                            </div>
                        </article>

                        <article v-if="activeReviewSection === 'proof'" class="admin-panel p-5">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex items-start gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-file-shield" aria-hidden="true"></i></span>
                                    <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Verification proof</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Organization files</h3>
                                    <p class="mt-1 text-sm text-slate-600">Review the files submitted to confirm the provider's identity.</p>
                                    </div>
                                </div>
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                    {{ providerProofCount }} file{{ providerProofCount === 1 ? '' : 's' }}
                                </span>
                            </div>

                            <div v-if="provider.verification_documents?.length" class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-200">
                                <div
                                    v-for="document in provider.verification_documents"
                                    :key="document.id"
                                    class="flex flex-col gap-3 p-3 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-600">
                                            <i class="fa-solid fa-file-lines" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-bold text-slate-950">{{ documentTypeLabel(document.document_type) }}</p>
                                            <p class="mt-1 truncate text-xs text-slate-500">{{ document.original_name }} - {{ formatFileSize(document.size) }}</p>
                                            <p class="mt-1 text-xs text-slate-500">Uploaded {{ document.uploaded_at || 'recently' }}</p>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <span :class="['rounded-md px-2 py-1 text-[10px] font-bold uppercase', documentStatusClass(document.status)]">
                                            {{ statusLabel(document.status || 'submitted') }}
                                        </span>
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                            @click="openDocumentPreview(document)"
                                        >
                                            View file
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                No verification proof has been uploaded yet.
                            </p>
                        </article>
                    </div>

                    <section v-if="activeReviewSection === 'decision'" class="admin-panel w-full p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800"><i class="fa-solid fa-gavel" aria-hidden="true"></i></span>
                                <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Admin decision</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">Verification decision</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Approve publishing access, request corrections, or reopen the record for another review.</p>
                                </div>
                            </div>
                            <span :class="['shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(provider.verification_status)]">
                                {{ statusLabel(provider.verification_status) }}
                            </span>
                        </div>

                        <div class="mt-4 overflow-hidden rounded-md border border-slate-200">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                                <div>
                                    <p class="text-sm font-bold text-slate-950">Decision checklist</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Summary of the organization, account, and evidence reviewed above.</p>
                                </div>
                                <span :class="['rounded-md px-2.5 py-1 text-xs font-bold', readinessStatusClass(providerAttentionCount === 0)]">
                                    {{ providerAttentionCount ? `${providerAttentionCount} need attention` : 'Review complete' }}
                                </span>
                            </div>
                            <div class="grid md:grid-cols-2">
                                <div
                                    v-for="(check, index) in providerReviewChecks"
                                    :key="check.label"
                                    :class="[
                                        'flex items-start gap-3 p-3.5',
                                        index < providerReviewChecks.length - 2 ? 'border-b border-slate-200' : '',
                                        index % 2 === 0 ? 'md:border-r md:border-slate-200' : '',
                                    ]"
                                >
                                    <i :class="[check.ready ? 'fa-solid fa-circle-check text-emerald-600' : 'fa-solid fa-circle-exclamation text-amber-600', 'mt-0.5']" aria-hidden="true"></i>
                                    <div>
                                        <p class="text-sm font-bold text-slate-950">{{ check.label }}</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ check.detail }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="providerProofCount"
                            class="mt-4 flex items-center gap-3 rounded-md bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-200"
                        >
                            <i class="fa-solid fa-file-circle-check text-slate-500" aria-hidden="true"></i>
                            <span><strong>{{ providerProofCount }}</strong> proof file{{ providerProofCount === 1 ? '' : 's' }} available for this decision</span>
                        </div>
                        <div v-else class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                            No organization proof has been uploaded yet.
                        </div>

                        <label class="mt-5 block text-xs font-bold text-slate-700">
                            Review note <span class="font-normal text-slate-500">(required when rejecting)</span>
                        </label>
                        <textarea
                            v-model="reviewNote"
                            rows="4"
                            maxlength="1500"
                            placeholder="Add context or explain any missing or invalid proof."
                            class="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100"
                            @input="decisionError = ''"
                        ></textarea>

                        <p v-if="decisionError" class="mt-3 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs font-semibold leading-5 text-rose-700">
                            {{ decisionError }}
                        </p>

                        <div class="mt-4 grid gap-2">
                            <button
                                v-for="action in providerActionOptions(provider)"
                                :key="action.status"
                                type="button"
                                :disabled="isSaving"
                                :class="[
                                    'w-full rounded-md px-4 py-2.5 text-sm font-bold transition disabled:cursor-not-allowed disabled:opacity-60',
                                    action.className,
                                ]"
                                @click="updateProvider(action.status)"
                            >
                                {{ isSaving ? 'Saving decision...' : action.label }}
                            </button>
                        </div>
                    </section>

                    <nav class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3 shadow-sm" aria-label="Provider review navigation">
                        <button
                            type="button"
                            :disabled="!previousReviewSection"
                            class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 disabled:invisible"
                            @click="previousReviewSection && selectReviewSection(previousReviewSection.key)"
                        >
                            Previous
                        </button>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Step {{ activeReviewSectionIndex + 1 }} of {{ reviewSections.length }}</p>
                        <button
                            v-if="nextReviewSection"
                            type="button"
                            class="rounded-md bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800"
                            @click="selectReviewSection(nextReviewSection.key)"
                        >
                            Next: {{ nextReviewSection.label }}
                        </button>
                        <a v-else href="/admin/reviews?type=providers" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">Back to queue</a>
                    </nav>
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
