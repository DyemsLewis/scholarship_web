<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    applicant: { type: Object, required: true },
    eyebrow: { type: String, default: 'Applicant record' },
    statusLabel: { type: String, default: '' },
    statusClass: { type: String, default: 'bg-slate-100 text-slate-700' },
    facts: { type: Array, default: () => [] },
    stackFacts: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['approve-photo', 'request-photo-replacement']);
const showPhotoReview = ref(false);
const showReplacementForm = ref(false);
const replacementReason = ref('');
const localError = ref('');

const applicantName = computed(() => props.applicant?.name || props.applicant?.username || 'Applicant');
const initials = computed(() => applicantName.value
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word.charAt(0))
    .join('')
    .toUpperCase());
const photoStatus = computed(() => props.applicant?.profile_photo_review_status || 'unreviewed');
const photoStatusDisplay = computed(() => ({
    approved: { label: 'Photo checked', className: 'bg-emerald-100 text-emerald-800' },
    needs_replacement: { label: 'Replacement requested', className: 'bg-rose-100 text-rose-800' },
    resubmitted: { label: 'New photo ready', className: 'bg-amber-100 text-amber-800' },
    unreviewed: { label: props.applicant?.profile_photo_url ? 'Not checked' : 'Photo missing', className: 'bg-slate-100 text-slate-700' },
}[photoStatus.value]));

function openPhotoReview() {
    localError.value = '';
    replacementReason.value = '';
    showReplacementForm.value = false;
    showPhotoReview.value = true;
}

function closePhotoReview() {
    if (props.busy) {
        return;
    }

    showPhotoReview.value = false;
    showReplacementForm.value = false;
    replacementReason.value = '';
    localError.value = '';
}

function requestReplacement() {
    const reason = replacementReason.value.trim();

    if (reason.length < 5) {
        localError.value = 'Add a short reason so the applicant knows what to fix.';
        return;
    }

    localError.value = '';
    emit('request-photo-replacement', reason);
}

watch(() => props.applicant?.profile_photo_review_status, () => {
    if (!props.busy && !props.error) {
        showReplacementForm.value = false;
        replacementReason.value = '';
    }
});
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm">
        <div class="flex flex-col gap-4 border-l-4 border-l-amber-400 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div class="flex min-w-0 items-center gap-4">
                <button
                    type="button"
                    class="grid h-20 w-20 shrink-0 place-items-center overflow-hidden rounded-md bg-slate-950 text-lg font-black tracking-[0.08em] text-white ring-1 ring-slate-200 transition hover:ring-2 hover:ring-amber-400"
                    aria-label="Review applicant 2x2 photo"
                    @click="openPhotoReview"
                >
                    <img v-if="applicant.profile_photo_url" :src="applicant.profile_photo_url" :alt="`${applicantName} 2x2 photo`" class="h-full w-full object-cover">
                    <span v-else>{{ initials }}</span>
                </button>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">{{ eyebrow }}</p>
                    <h2 class="mt-1 truncate text-xl font-bold text-slate-950">{{ applicantName }}</h2>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
                        <span>{{ applicant.email || 'Email not provided' }}</span>
                        <span>{{ applicant.contact_number || 'Contact not provided' }}</span>
                    </div>
                    <button type="button" class="mt-2 inline-flex items-center gap-2 text-xs font-bold text-slate-700 hover:text-slate-950" @click="openPhotoReview">
                        <i class="fa-regular fa-image text-amber-700" aria-hidden="true"></i>
                        Review 2x2 photo
                    </button>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
                <span :class="['rounded-md px-2.5 py-1.5 text-[10px] font-bold uppercase', photoStatusDisplay.className]">{{ photoStatusDisplay.label }}</span>
                <span v-if="statusLabel" :class="['rounded-md px-2.5 py-1.5 text-[10px] font-bold uppercase', statusClass]">{{ statusLabel }}</span>
            </div>
        </div>

        <dl
            v-if="facts.length"
            :class="[
                'border-t border-slate-200 text-sm',
                stackFacts
                    ? 'divide-y divide-slate-200 bg-slate-50 px-4 sm:px-5'
                    : 'grid gap-px bg-slate-200 sm:grid-cols-2 lg:grid-cols-4',
            ]"
        >
            <div
                v-for="fact in facts"
                :key="fact.label"
                :class="stackFacts ? 'grid gap-1 py-3 sm:grid-cols-[13rem_minmax(0,1fr)] sm:items-start' : 'bg-slate-50 p-3'"
            >
                <dt class="text-xs font-semibold text-slate-500">{{ fact.label }}</dt>
                <dd :class="['break-words font-bold text-slate-950', stackFacts ? '' : 'mt-1']">{{ fact.value || 'Not provided' }}</dd>
            </div>
        </dl>

        <div v-if="photoStatus === 'needs_replacement' || photoStatus === 'resubmitted'" :class="['border-t px-4 py-3 text-sm sm:px-5', photoStatus === 'resubmitted' ? 'border-amber-200 bg-amber-50 text-amber-950' : 'border-rose-200 bg-rose-50 text-rose-950']">
            <strong>{{ photoStatus === 'resubmitted' ? 'A new photo is ready for review.' : 'Waiting for the applicant to replace the photo.' }}</strong>
            <span v-if="applicant.profile_photo_review_note" class="ml-1">{{ applicant.profile_photo_review_note }}</span>
        </div>
    </section>

    <Teleport to="body">
        <div v-if="showPhotoReview" class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/70 p-4" @click.self="closePhotoReview">
            <section class="w-full max-w-3xl overflow-hidden rounded-lg bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="photo-review-title">
                <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Identity photo</p>
                        <h2 id="photo-review-title" class="mt-1 text-xl font-bold text-slate-950">Review applicant 2x2</h2>
                        <p class="mt-1 text-sm text-slate-500">Check that the photo is recent, square, clear, and shows the applicant.</p>
                    </div>
                    <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-slate-300 text-slate-500 hover:bg-slate-100" aria-label="Close photo review" @click="closePhotoReview">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="grid md:grid-cols-[18rem_minmax(0,1fr)]">
                    <div class="grid min-h-72 place-items-center border-b border-slate-200 bg-slate-100 p-5 md:border-b-0 md:border-r">
                        <img v-if="applicant.profile_photo_url" :src="applicant.profile_photo_url" :alt="`${applicantName} 2x2 photo`" class="aspect-square w-full max-w-60 rounded-md bg-white object-cover shadow-sm ring-1 ring-slate-200">
                        <div v-else class="grid aspect-square w-full max-w-60 place-items-center rounded-md bg-slate-950 text-4xl font-black text-white">{{ initials }}</div>
                    </div>
                    <div class="p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-bold text-slate-950">{{ applicantName }}</p>
                                <p class="mt-1 text-xs text-slate-500">Applicant-owned profile photo</p>
                            </div>
                            <span :class="['rounded-md px-2.5 py-1.5 text-[10px] font-bold uppercase', photoStatusDisplay.className]">{{ photoStatusDisplay.label }}</span>
                        </div>

                        <div v-if="applicant.profile_photo_review_note" class="mt-4 border-l-4 border-amber-400 bg-amber-50 px-3 py-2.5 text-sm leading-6 text-amber-950">
                            {{ applicant.profile_photo_review_note }}
                        </div>

                        <div v-if="showReplacementForm" class="mt-5">
                            <label class="text-xs font-bold text-slate-700" for="photo-replacement-reason">Reason for replacement</label>
                            <textarea id="photo-replacement-reason" v-model="replacementReason" rows="4" maxlength="1000" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-950 outline-none placeholder:text-slate-400 focus:border-amber-500 focus:ring-3 focus:ring-amber-100" placeholder="Example: The photo is blurred and the applicant's face is not clear."></textarea>
                            <p class="mt-1 text-xs text-slate-500">The applicant will see this reason and receive a link to upload a new photo.</p>
                        </div>

                        <p v-if="localError || error" class="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-700">{{ localError || error }}</p>

                        <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                            <button v-if="applicant.profile_photo_url" type="button" :disabled="busy" class="rounded-md bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-60" @click="emit('approve-photo')">
                                {{ busy ? 'Saving...' : 'Mark photo acceptable' }}
                            </button>
                            <button v-if="!showReplacementForm" type="button" :disabled="busy" class="rounded-md border border-rose-200 bg-white px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-60" @click="showReplacementForm = true">
                                {{ applicant.profile_photo_url ? 'Request replacement' : 'Request 2x2 photo' }}
                            </button>
                            <button v-else type="button" :disabled="busy" class="rounded-md bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-800 disabled:opacity-60" @click="requestReplacement">
                                {{ busy ? 'Sending...' : 'Send request' }}
                            </button>
                            <button v-if="showReplacementForm" type="button" :disabled="busy" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100" @click="showReplacementForm = false">Cancel</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </Teleport>
</template>
