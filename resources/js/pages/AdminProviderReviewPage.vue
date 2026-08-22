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
    { key: 'organization', label: 'Organization' },
    { key: 'proof', label: 'Proof files' },
    { key: 'decision', label: 'Decision' },
];
const activeReviewSection = ref(reviewSections.some((section) => section.key === requestedSection) ? requestedSection : 'organization');
const activeReviewSectionIndex = computed(() => reviewSections.findIndex((section) => section.key === activeReviewSection.value));
const previousReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value - 1] ?? null);
const nextReviewSection = computed(() => reviewSections[activeReviewSectionIndex.value + 1] ?? null);

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
    <main class="min-h-screen bg-[linear-gradient(180deg,_#f8fafc_0%,_#eef2f6_52%,_#e7edf4_100%)] text-slate-900 lg:grid lg:grid-cols-[18rem_1fr]">
        <AdminSidebar active="reviews" />

        <FilePreviewModal
            :file="previewDocument"
            :title="documentTypeLabel(previewDocument?.document_type)"
            :context="provider?.provider_name || provider?.name || 'Provider'"
            @close="closeDocumentPreview"
        />

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-700">Provider Review</p>
                            <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">Provider review details</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Review the organization and its proof before approving access to publish scholarship programs.
                            </p>
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

                <div v-else class="mt-6 space-y-5">
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <nav class="grid gap-1 p-2 sm:grid-cols-3" aria-label="Provider verification steps">
                            <button
                                v-for="(section, index) in reviewSections"
                                :key="section.key"
                                type="button"
                                :aria-current="activeReviewSection === section.key ? 'step' : undefined"
                                :class="[
                                    'flex items-center gap-3 rounded-md px-3 py-3 text-left transition',
                                    activeReviewSection === section.key
                                        ? 'bg-slate-950 text-white'
                                        : 'text-slate-700 hover:bg-slate-50 hover:text-slate-950',
                                ]"
                                @click="selectReviewSection(section.key)"
                            >
                                <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-md text-xs font-bold', activeReviewSection === section.key ? 'bg-white/10' : 'bg-slate-100 text-slate-600']">{{ index + 1 }}</span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-bold">{{ section.label }}</span>
                                    <span :class="['mt-0.5 block truncate text-xs', activeReviewSection === section.key ? 'text-slate-300' : 'text-slate-500']">
                                        <template v-if="section.key === 'organization'">Identity and contact</template>
                                        <template v-else-if="section.key === 'proof'">{{ provider.verification_documents?.length || 0 }} files</template>
                                        <template v-else>{{ statusLabel(provider.verification_status) }}</template>
                                    </span>
                                </span>
                            </button>
                        </nav>
                    </section>

                    <div v-if="activeReviewSection !== 'decision'" class="space-y-5">
                        <article v-if="activeReviewSection === 'organization'" class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-bold tracking-[0.08em] text-white">
                                    {{ providerInitials(provider) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Provider organization</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">{{ provider.provider_name || provider.name }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ provider.email }}</p>
                                </div>
                                <span :class="['w-fit shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(provider.verification_status)]">
                                    {{ statusLabel(provider.verification_status) }}
                                </span>
                            </div>

                            <dl class="grid border-t border-slate-200 bg-slate-50 sm:grid-cols-2 lg:grid-cols-4">
                                <div class="border-b border-slate-200 p-4 sm:border-r lg:border-b-0">
                                    <dt class="text-xs font-semibold text-slate-500">Provider type</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ statusLabel(provider.provider_type || 'not provided') }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 lg:border-b-0 lg:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Contact person</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ provider.name || 'Not provided' }}</dd>
                                </div>
                                <div class="border-b border-slate-200 p-4 sm:border-b-0 sm:border-r">
                                    <dt class="text-xs font-semibold text-slate-500">Contact number</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ provider.contact_number || 'Not provided' }}</dd>
                                </div>
                                <div class="p-4">
                                    <dt class="text-xs font-semibold text-slate-500">Registered</dt>
                                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ provider.created_at || 'Not provided' }}</dd>
                                </div>
                            </dl>
                        </article>

                        <article v-if="activeReviewSection === 'organization'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Organization information</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">Provider record</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Confirm who the provider is and where applicants can verify its information.</p>
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
                                <div class="border-b border-slate-200 p-4 md:border-b-0 md:border-r">
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
                                <div class="p-4">
                                    <dt class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                        Address
                                    </dt>
                                    <dd class="mt-2 font-bold leading-6 text-slate-950">{{ provider.provider_address || 'Not provided' }}</dd>
                                </div>
                            </dl>
                        </article>

                        <article v-if="activeReviewSection === 'proof'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Verification proof</p>
                                    <h3 class="mt-1 text-xl font-bold text-slate-950">Organization files</h3>
                                    <p class="mt-1 text-sm text-slate-600">Review the files submitted to confirm the provider's identity.</p>
                                </div>
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                                    {{ provider.verification_documents?.length || 0 }} file{{ provider.verification_documents?.length === 1 ? '' : 's' }}
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

                    <aside v-if="activeReviewSection === 'decision'" class="w-full rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Admin decision</p>
                                <h3 class="mt-1 text-xl font-bold text-slate-950">Verification decision</h3>
                            </div>
                            <span :class="['shrink-0 rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(provider.verification_status)]">
                                {{ statusLabel(provider.verification_status) }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Confirm that the organization information and submitted proof are valid.
                        </p>

                        <div
                            v-if="provider.verification_documents?.length"
                            class="mt-4 flex items-center gap-3 rounded-md bg-slate-50 p-3 text-sm text-slate-700 ring-1 ring-slate-200"
                        >
                            <i class="fa-solid fa-file-circle-check text-slate-500" aria-hidden="true"></i>
                            <span><strong>{{ provider.verification_documents.length }}</strong> proof file{{ provider.verification_documents.length === 1 ? '' : 's' }} available</span>
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
                    </aside>

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
