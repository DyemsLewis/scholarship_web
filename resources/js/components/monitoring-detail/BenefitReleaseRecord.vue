<script setup>
import { computed, ref } from 'vue';
import FilePreviewModal from '../FilePreviewModal.vue';
import LocationMapLink from '../LocationMapLink.vue';
import BenefitReceiptResponseModal from './BenefitReceiptResponseModal.vue';

const props = defineProps({
    release: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
});
const emit = defineEmits(['refresh']);
const previewFile = ref(null);
const responseOpen = ref(false);

const lifecycle = computed(() => props.release.lifecycle_status ?? props.release.status ?? 'scheduled');

const statePresentation = computed(() => ({
    confirmation_needed: {
        tone: 'attention',
        eyebrow: 'Your next action',
        title: 'Confirm this benefit release',
        description: 'Confirm what you received or report a problem to the provider.',
        icon: 'fa-solid fa-clipboard-check',
    },
    issue_open: {
        tone: 'waiting',
        eyebrow: 'Provider follow-up',
        title: 'Your report is being reviewed',
        description: 'The provider will record the resolution in this release record.',
        icon: 'fa-regular fa-clock',
    },
    fulfilled: {
        tone: 'complete',
        eyebrow: 'Release complete',
        title: 'Receipt confirmed',
        description: 'Your confirmation is saved with the provider release record.',
        icon: 'fa-solid fa-circle-check',
    },
    resolved: {
        tone: 'complete',
        eyebrow: 'Issue resolved',
        title: 'The provider completed the follow-up',
        description: 'Review the resolution and any proof recorded by the provider.',
        icon: 'fa-solid fa-shield-check',
    },
    prepared: {
        tone: 'scheduled',
        eyebrow: 'Prepare for release',
        title: 'This benefit is ready',
        description: 'Review the schedule and instructions before receiving it.',
        icon: 'fa-solid fa-box-open',
    },
    missed: {
        tone: 'critical',
        eyebrow: 'Release update',
        title: 'This release was missed',
        description: 'Review the provider note for the recorded outcome.',
        icon: 'fa-solid fa-calendar-xmark',
    },
    withheld: {
        tone: 'critical',
        eyebrow: 'Release update',
        title: 'This release is withheld',
        description: 'Review the provider note for the reason and next steps.',
        icon: 'fa-solid fa-circle-pause',
    },
    scheduled: {
        tone: 'scheduled',
        eyebrow: 'Upcoming release',
        title: 'Prepare for the scheduled benefit',
        description: 'Check the date, method, location, and provider instructions.',
        icon: 'fa-regular fa-calendar-check',
    },
}[lifecycle.value] ?? {
    tone: 'neutral',
    eyebrow: 'Benefit update',
    title: props.release.status_label,
    description: 'Review the recorded release information below.',
    icon: 'fa-solid fa-hand-holding-heart',
}));

function statusClass(status) {
    if (['fulfilled', 'resolved'].includes(status)) return 'bg-emerald-100 text-emerald-800';
    if (status === 'confirmation_needed') return 'bg-amber-100 text-amber-900';
    if (status === 'issue_open') return 'bg-sky-100 text-sky-800';
    if (['missed', 'withheld'].includes(status)) return 'bg-rose-100 text-rose-800';
    return 'bg-slate-100 text-slate-700';
}

function bannerClass(tone) {
    if (tone === 'attention') return 'border-amber-300 bg-amber-50';
    if (tone === 'waiting') return 'border-sky-200 bg-sky-50';
    if (tone === 'complete') return 'border-emerald-200 bg-emerald-50';
    if (tone === 'critical') return 'border-rose-200 bg-rose-50';
    return 'border-slate-200 bg-white';
}

function iconClass(tone) {
    if (tone === 'attention') return 'bg-amber-300 text-slate-950';
    if (tone === 'waiting') return 'bg-sky-100 text-sky-800';
    if (tone === 'complete') return 'bg-emerald-100 text-emerald-800';
    if (tone === 'critical') return 'bg-rose-100 text-rose-800';
    return 'bg-slate-100 text-slate-700';
}
</script>

<template>
    <FilePreviewModal
        :file="previewFile"
        :title="previewFile?.original_name || 'Benefit release file'"
        :context="programTitle"
        @close="previewFile = null"
    />
    <BenefitReceiptResponseModal
        :open="responseOpen"
        :release="release"
        @close="responseOpen = false"
        @saved="emit('refresh')"
    />

    <div data-benefit-release-record class="mt-4 space-y-4">
        <a :href="release.return_href" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
            All benefit releases
        </a>

        <article class="overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm">
            <header class="px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Benefit release</p>
                            <span :class="['px-2 py-1 text-[10px] font-bold uppercase', statusClass(lifecycle)]">{{ release.status_label }}</span>
                        </div>
                        <h3 class="mt-2 font-display text-xl font-bold text-slate-950 sm:text-2xl">{{ release.title }}</h3>
                        <p v-if="release.benefit_description" class="mt-2 max-w-4xl text-sm leading-6 text-slate-600">{{ release.benefit_description }}</p>
                    </div>
                    <button v-if="release.receipt" type="button" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = release.receipt">
                        <i class="fa-solid fa-file-shield text-xs" aria-hidden="true"></i>
                        Provider proof
                    </button>
                </div>
            </header>

            <dl class="grid border-t border-slate-200 bg-slate-50 sm:grid-cols-4">
                <div class="px-5 py-3">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Release date</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ release.release_label || 'Not scheduled' }}</dd>
                </div>
                <div class="border-t border-slate-200 px-5 py-3 sm:border-l sm:border-t-0">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Amount</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ release.amount_label || 'Non-cash benefit' }}</dd>
                </div>
                <div class="border-t border-slate-200 px-5 py-3 sm:border-l sm:border-t-0">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Method</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ release.release_method_label }}</dd>
                </div>
                <div class="border-t border-slate-200 px-5 py-3 sm:border-l sm:border-t-0">
                    <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Location</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-950">{{ release.location || 'Provider-coordinated' }}</dd>
                    <LocationMapLink v-if="release.release_method === 'in_person'" :address="release.location || ''" :latitude="release.latitude" :longitude="release.longitude" :title="release.title" />
                </div>
            </dl>
        </article>

        <section :class="['flex flex-col gap-4 rounded-md border p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:px-6', bannerClass(statePresentation.tone)]">
            <div class="flex min-w-0 items-start gap-3">
                <span :class="['grid h-11 w-11 shrink-0 place-items-center', iconClass(statePresentation.tone)]"><i :class="statePresentation.icon" aria-hidden="true"></i></span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ statePresentation.eyebrow }}</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ statePresentation.title }}</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ statePresentation.description }}</p>
                </div>
            </div>
            <button v-if="release.can_respond" type="button" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800" @click="responseOpen = true">
                Confirm or report
                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
            </button>
        </section>

        <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="border-b border-slate-200 px-5 py-4 sm:px-6">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Release details</p>
                <h3 class="mt-1 text-lg font-bold text-slate-950">What to know</h3>
            </header>

            <div class="divide-y divide-slate-200">
                <section class="flex gap-4 px-5 py-5 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-700"><i class="fa-solid fa-gift text-sm" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h4 class="font-bold text-slate-950">Benefit included</h4>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ release.benefit_description || 'The provider recorded this scholarship benefit.' }}</p>
                        <div v-if="release.benefits?.length" class="mt-3 flex flex-wrap gap-2">
                            <span v-for="benefit in release.benefits" :key="benefit.benefit_id || benefit.title" class="border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-bold text-slate-700">{{ benefit.title }}</span>
                        </div>
                    </div>
                </section>

                <section class="flex gap-4 px-5 py-5 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-700"><i class="fa-solid fa-circle-info text-sm" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h4 class="font-bold text-slate-950">Instructions</h4>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ release.instructions || 'Follow the provider instructions shared with this release.' }}</p>
                        <p v-if="release.requires_original_verification" class="mt-2 text-xs font-bold text-amber-800">{{ release.originals_verified ? 'Original records were verified.' : 'Bring the requested original records for verification.' }}</p>
                    </div>
                </section>

                <section v-if="release.notes || release.recorded_at || release.released_at" class="flex gap-4 px-5 py-5 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-700"><i class="fa-solid fa-building-circle-check text-sm" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <h4 class="font-bold text-slate-950">Provider record</h4>
                        <p v-if="release.notes" class="mt-1 text-sm leading-6 text-slate-600">{{ release.notes }}</p>
                        <p v-if="release.recorded_at || release.released_at" class="mt-2 text-xs text-slate-500">Recorded {{ release.released_at || release.recorded_at }}<span v-if="release.recorded_by"> by {{ release.recorded_by }}</span></p>
                    </div>
                </section>

                <section v-if="release.receipt_response" class="flex gap-4 px-5 py-5 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-700"><i class="fa-solid fa-receipt text-sm" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h4 class="font-bold text-slate-950">Your response</h4>
                            <span :class="['px-2 py-1 text-[10px] font-bold uppercase', statusClass(lifecycle)]">{{ release.receipt_response.status_label }}</span>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-700">{{ release.receipt_response.response_label }}<span v-if="release.receipt_response.received_label"> · {{ release.receipt_response.received_label }}</span></p>
                        <p v-if="release.receipt_response.recipient_note" class="mt-2 text-sm leading-6 text-slate-600">{{ release.receipt_response.recipient_note }}</p>
                        <template v-if="release.receipt_response.response_type === 'issue'">
                            <p class="mt-2 text-xs font-bold text-rose-700">{{ release.receipt_response.issue_type_label }}</p>
                            <p class="mt-1 text-sm leading-6 text-slate-600">{{ release.receipt_response.issue_details }}</p>
                            <button v-if="release.receipt_response.evidence" type="button" class="mt-3 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="previewFile = release.receipt_response.evidence">View your evidence</button>
                        </template>
                    </div>
                </section>

                <section v-if="release.receipt_response?.status === 'resolved'" class="flex gap-4 px-5 py-5 sm:px-6">
                    <span class="grid h-9 w-9 shrink-0 place-items-center bg-emerald-100 text-emerald-800"><i class="fa-solid fa-shield-check text-sm" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1">
                        <h4 class="font-bold text-slate-950">Provider resolution</h4>
                        <p class="mt-1 text-sm font-semibold text-slate-700">{{ release.receipt_response.resolution_outcome_label }}</p>
                        <p v-if="release.receipt_response.resolution_notes" class="mt-2 text-sm leading-6 text-slate-600">{{ release.receipt_response.resolution_notes }}</p>
                        <p v-if="release.receipt_response.resolved_at" class="mt-2 text-xs text-slate-500">Resolved {{ release.receipt_response.resolved_at }}<span v-if="release.receipt_response.resolved_by"> by {{ release.receipt_response.resolved_by }}</span></p>
                        <button v-if="release.receipt_response.resolution_proof" type="button" class="mt-3 border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700" @click="previewFile = release.receipt_response.resolution_proof">View resolution proof</button>
                    </div>
                </section>
            </div>
        </section>
    </div>
</template>
