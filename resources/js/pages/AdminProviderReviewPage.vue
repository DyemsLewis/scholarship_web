<script setup>
import { onMounted, ref } from 'vue';
import AdminFooter from '../components/AdminFooter.vue';
import AdminSidebar from '../components/AdminSidebar.vue';
import { formatFileSize } from '../support/display';

const appElement = document.getElementById('app');
const providerId = appElement?.dataset.providerId;
const isLoading = ref(true);
const isSaving = ref(false);
const loadError = ref('');
const decisionError = ref('');
const provider = ref(null);
const reviewNote = ref('');

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

function documentTypeLabel(type) {
    return statusLabel(type || 'document');
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
            className: 'bg-emerald-700 text-white hover:bg-emerald-800',
        });
    }

    if (status !== 'rejected') {
        actions.push({
            status: 'rejected',
            label: 'Reject provider',
            className: 'bg-rose-700 text-white hover:bg-rose-800',
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

        <section class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto max-w-7xl">
                <header class="admin-hero">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <a href="/admin/reviews" class="inline-flex text-sm font-bold text-amber-700 underline underline-offset-4">
                                Back to review queue
                            </a>
                            <h2 class="mt-3 font-display text-3xl font-bold text-slate-950">Provider review details</h2>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                                Review the organization and its proof before approving access to publish scholarship programs.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="w-fit rounded-md bg-amber-300 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-amber-200"
                            @click="loadProvider"
                        >
                            Refresh details
                        </button>
                    </div>
                </header>

                <div v-if="isLoading" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                    Loading provider review details...
                </div>

                <div v-else-if="loadError || !provider" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 p-5 shadow-sm">
                    <p class="text-sm font-bold text-rose-800">Provider details could not be loaded</p>
                    <p class="mt-1 text-sm leading-6 text-rose-700">{{ loadError }}</p>
                </div>

                <div v-else class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div class="space-y-5">
                        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start gap-4">
                                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-md bg-slate-950 text-sm font-bold tracking-[0.08em] text-white">
                                    {{ providerInitials(provider) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-xl font-bold text-slate-950">{{ provider.provider_name || provider.name }}</h3>
                                        <span :class="['rounded-md px-2.5 py-1 text-[10px] font-bold uppercase', statusClass(provider.verification_status)]">
                                            {{ statusLabel(provider.verification_status) }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-500">{{ provider.email }}</p>
                                    <p class="mt-3 text-sm leading-6 text-slate-600">
                                        {{ provider.provider_description || 'No organization description provided.' }}
                                    </p>
                                </div>
                            </div>

                            <dl class="mt-5 grid gap-4 border-t border-slate-200 pt-5 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-semibold text-slate-500">Provider type</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ statusLabel(provider.provider_type || 'not provided') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold text-slate-500">Registered</dt>
                                    <dd class="mt-1 font-bold text-slate-950">{{ provider.created_at || 'Not provided' }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-semibold text-slate-500">Website</dt>
                                    <dd class="mt-1 break-words font-bold text-slate-950">
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
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-semibold text-slate-500">Address</dt>
                                    <dd class="mt-1 font-bold leading-6 text-slate-950">{{ provider.provider_address || 'Not provided' }}</dd>
                                </div>
                            </dl>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Verification proof</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950">Organization files</h3>
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
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-950">{{ documentTypeLabel(document.document_type) }}</p>
                                        <p class="mt-1 truncate text-xs text-slate-500">
                                            {{ document.original_name }} - {{ formatFileSize(document.size) }}
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">Uploaded {{ document.uploaded_at || 'recently' }}</p>
                                    </div>
                                    <a
                                        :href="document.download_url"
                                        class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50"
                                    >
                                        Download file
                                    </a>
                                </div>
                            </div>
                            <p v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                No verification proof has been uploaded yet.
                            </p>
                        </section>
                    </div>

                    <aside class="h-fit rounded-lg border border-slate-200 bg-white p-5 shadow-sm xl:sticky xl:top-8">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Admin decision</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950">Verify this provider?</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            The result and your note will be sent to the provider.
                        </p>

                        <label class="mt-5 block text-xs font-bold text-slate-700">
                            Review note <span class="font-normal text-slate-500">(required when rejecting)</span>
                        </label>
                        <textarea
                            v-model="reviewNote"
                            rows="5"
                            maxlength="1500"
                            placeholder="Explain any missing or invalid verification details."
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
                </div>

                <AdminFooter />
            </div>
        </section>
    </main>
</template>
