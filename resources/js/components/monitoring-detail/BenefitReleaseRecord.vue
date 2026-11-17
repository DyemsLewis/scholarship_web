<script setup>
import { computed, ref } from 'vue';
import FilePreviewModal from '../FilePreviewModal.vue';
import LocationMapLink from '../LocationMapLink.vue';
import BenefitReceiptResponseModal from './BenefitReceiptResponseModal.vue';
import BenefitPayoutDetailsModal from './BenefitPayoutDetailsModal.vue';
import BenefitFollowUpPanel from '../benefit-delivery/BenefitFollowUpPanel.vue';
import { checkInStatusClass } from '../../support/checkInStatus.js';
import { benefitNextStep, benefitMethodIcon } from '../../support/benefitReleasePresentation.js';

const props = defineProps({
    release: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
    canReport: Boolean,
});
const emit = defineEmits(['refresh', 'report']);
const previewFile = ref(null);
const responseOpen = ref(false);
const payoutOpen = ref(false);
const delivery = computed(() => props.release.delivery);
const payout = computed(() => props.release.payout);
const needsPayout = computed(() => ['required', 'correction_requested'].includes(payout.value?.status));
const nextStep = computed(() => benefitNextStep(props.release));
const collection = computed(() => props.release.release_method === 'in_person');
const hasVenue = computed(() => !!props.release.location || (props.release.latitude != null && props.release.longitude != null));
const included = computed(() => [...new Set((props.release.benefits ?? []).map((item) => item.title).filter(Boolean))]);
const response = computed(() => props.release.receipt_response);
const hasProviderRecord = computed(() => !!(props.release.notes || props.release.receipt || props.release.recorded_at || response.value));
const instructions = computed(() => delivery.value.key !== 'inactive' && (props.release.instructions || props.release.requires_original_verification));
</script>

<template>
    <FilePreviewModal :file="previewFile" :title="previewFile?.original_name || 'Benefit release file'" :context="programTitle" @close="previewFile = null" />
    <BenefitReceiptResponseModal :open="responseOpen" :release="release" @close="responseOpen = false" @saved="emit('refresh')" />
    <BenefitPayoutDetailsModal v-if="payout?.submit_url" :open="payoutOpen" :payout="payout" :release-title="release.title" @close="payoutOpen = false" @saved="emit('refresh')" />

    <div data-benefit-release-record class="mt-4 space-y-4">
        <a :href="release.return_href" class="inline-flex min-h-9 items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-950">
            <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i> All Benefits &amp; Events
        </a>

        <BenefitFollowUpPanel v-if="release.follow_up" :key="release.record_id" :follow-up="release.follow_up" :context="release.title" @saved="emit('refresh')" />

        <article class="overflow-hidden rounded-md border border-slate-300 border-t-4 border-t-amber-400 bg-white shadow-sm">
            <header class="flex flex-col gap-4 bg-portal-navy px-5 py-5 text-white sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-6">
                <div class="flex min-w-0 items-start gap-3 sm:gap-4">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-sm border border-white/15 bg-white/10 text-amber-300"><i :class="[benefitMethodIcon(release.release_method), 'leading-none']" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-300">Benefit release</p>
                        <h2 class="mt-1 break-words font-display text-xl font-bold leading-snug sm:text-2xl">{{ release.title }}</h2>
                        <span :class="['mt-3 inline-flex rounded-sm border px-2 py-0.5 text-xs font-bold', checkInStatusClass(delivery.tone)]">{{ delivery.label }}</span>
                    </div>
                </div>
                <div v-if="release.amount_label" class="shrink-0 pl-14 sm:pl-0 sm:text-right">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-300">Release amount</p>
                    <p class="mt-1 font-display text-2xl font-bold tabular-nums">{{ release.amount_label }}</p>
                </div>
            </header>

            <div>
                <section data-benefit-next-step :class="['grid items-start gap-4 border-b border-slate-200 px-5 py-4 sm:px-6 sm:py-5 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-center xl:gap-8', release.can_respond || needsPayout ? 'bg-amber-50' : 'bg-slate-50']">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">Your next step</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ nextStep.title }}</h3>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-700">{{ nextStep.description }}</p>
                        <p v-if="payout?.submitted_label" class="mt-2 text-xs text-slate-500">Payout details submitted {{ payout.submitted_label }}</p>
                    </div>
                    <div v-if="payout?.submit_url || release.can_respond || canReport || delivery.key === 'issue_open'" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap xl:max-w-sm xl:justify-end">
                        <button v-if="payout?.submit_url" type="button" :class="['student-button', needsPayout ? 'student-button-primary' : 'student-button-secondary']" @click="payoutOpen = true">
                            <i class="fa-solid fa-lock text-xs" aria-hidden="true"></i> {{ payout.status === 'required' ? 'Enter payout details' : needsPayout ? 'Update payout details' : 'Replace details' }}
                        </button>
                        <button v-if="release.can_respond" type="button" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2" @click="responseOpen = true">
                            <i class="fa-solid fa-check text-xs" aria-hidden="true"></i> Confirm receipt
                        </button>
                        <button v-if="canReport" type="button" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2" @click="emit('report')">
                            Report a problem
                        </button>
                        <a v-if="delivery.key === 'issue_open'" href="#benefit-delivery-reports" class="inline-flex min-h-11 items-center gap-2 rounded-sm border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 hover:bg-slate-100">
                            View delivery reports <i class="fa-solid fa-arrow-down text-xs" aria-hidden="true"></i>
                        </a>
                    </div>
                </section>

                <section data-benefit-delivery-details class="min-w-0 px-5 py-5 sm:px-6">
                    <h3 class="font-bold text-slate-950">Delivery details</h3>
                    <dl :class="['mt-4 grid gap-4 sm:grid-cols-2 sm:gap-6', collection && (hasVenue || ['scheduled', 'ready'].includes(delivery.key)) ? 'xl:grid-cols-3' : '']">
                        <div class="min-w-0">
                            <dt class="student-detail-label">{{ delivery.date_label ? delivery.date_caption : 'Release date' }}</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ delivery.date_label || 'Not scheduled' }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="student-detail-label">Delivery method</dt>
                            <dd class="mt-1 text-sm font-bold text-slate-950">{{ release.release_method_label }}</dd>
                        </div>
                        <div v-if="collection && (hasVenue || ['scheduled', 'ready'].includes(delivery.key))" class="min-w-0 sm:col-span-2 xl:col-span-1">
                            <dt class="student-detail-label">Collection venue</dt>
                            <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ release.location || (hasVenue ? 'Pinned location' : 'Awaiting provider details') }}</dd>
                            <LocationMapLink v-if="hasVenue" :address="release.location || ''" :latitude="release.latitude" :longitude="release.longitude" :title="release.title" />
                        </div>
                    </dl>
                    <button v-if="release.transfer_sent_on && release.receipt" type="button" class="mt-3 inline-flex min-h-10 items-center gap-2 rounded-sm border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = release.receipt">
                        <i class="fa-regular fa-file text-xs" aria-hidden="true"></i> View transfer proof
                    </button>
                </section>
            </div>
        </article>

        <article class="divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <section class="flex items-start gap-3 px-4 py-4 sm:px-6">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-gift text-xs" aria-hidden="true"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                        <h3 class="font-bold text-slate-950">What this release includes</h3>
                    </div>
                    <p v-if="release.benefit_description" class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ release.benefit_description }}</p>
                    <ul v-if="included.length" class="mt-2 list-disc space-y-0.5 pl-4 text-sm text-slate-700">
                        <li v-for="title in included" :key="title" class="break-words">{{ title }}</li>
                    </ul>
                    <p v-if="!release.benefit_description && !included.length && !release.amount_label" class="mt-1 text-sm text-slate-600">The provider has not listed the items in this release.</p>
                </div>
            </section>

            <section v-if="instructions" data-benefit-instructions class="flex items-start gap-3 bg-slate-50/60 px-4 py-4 sm:px-6">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-list-check text-xs" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <h3 class="font-bold text-slate-950">Provider instructions</h3>
                    <p v-if="release.instructions" class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ release.instructions }}</p>
                    <p v-if="release.requires_original_verification" class="mt-1 text-sm font-semibold text-slate-700">
                        {{ release.originals_verified ? 'The provider verified the originals.' : (collection && ['scheduled', 'ready'].includes(delivery.key) ? 'Bring the requested original documents.' : 'Original documents are required for verification.') }}
                    </p>
                </div>
            </section>
        </article>

        <details v-if="hasProviderRecord" :open="['missed', 'withheld', 'resolved'].includes(delivery.key) ? true : undefined" class="group overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 font-bold text-slate-950 sm:px-6 [&::-webkit-details-marker]:hidden">
                Release record and proof
                <i class="fa-solid fa-chevron-down text-xs text-slate-500 transition group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <div class="space-y-4 border-t border-slate-200 px-4 py-4 sm:px-6">
                <section v-if="release.notes || release.recorded_at || release.receipt">
                    <h4 class="text-sm font-bold text-slate-950">Provider record</h4>
                    <p v-if="release.notes" class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ release.notes }}</p>
                    <p v-if="release.recorded_at" class="mt-1 text-xs text-slate-600">Recorded {{ release.recorded_at }}<span v-if="release.recorded_by"> by {{ release.recorded_by }}</span></p>
                    <button v-if="release.receipt && !release.transfer_sent_on" type="button" class="mt-2 inline-flex min-h-10 items-center gap-2 rounded-sm border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = release.receipt">
                        <i class="fa-regular fa-file text-xs" aria-hidden="true"></i> View provider proof
                    </button>
                </section>
                <section v-if="response">
                    <h4 class="text-sm font-bold text-slate-950">Your response</h4>
                    <p class="mt-1 text-sm text-slate-700">{{ response.response_label }}<span v-if="response.received_label"> &middot; {{ response.received_label }}</span></p>
                    <p v-if="response.recipient_note" class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ response.recipient_note }}</p>
                    <template v-if="response.response_type === 'issue'">
                        <p class="mt-1 text-sm font-semibold text-slate-700">{{ response.issue_type_label }}</p>
                        <p class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ response.issue_details }}</p>
                    </template>
                    <button v-if="response.evidence" type="button" class="mt-2 min-h-10 rounded-sm border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = response.evidence">{{ response.response_type === 'confirmed' ? 'View your receipt proof' : 'View your evidence' }}</button>
                </section>
                <section v-if="response?.status === 'resolved'">
                    <h4 class="text-sm font-bold text-slate-950">Provider resolution</h4>
                    <p class="mt-1 text-sm font-semibold text-slate-700">{{ response.resolution_outcome_label }}</p>
                    <p v-if="response.resolution_notes" class="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{{ response.resolution_notes }}</p>
                    <p v-if="response.resolved_at" class="mt-1 text-xs text-slate-600">Resolved {{ response.resolved_at }}<span v-if="response.resolved_by"> by {{ response.resolved_by }}</span></p>
                    <button v-if="response.resolution_proof" type="button" class="mt-2 min-h-10 rounded-sm border border-slate-300 px-3 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="previewFile = response.resolution_proof">View resolution proof</button>
                </section>
            </div>
        </details>
    </div>
</template>
