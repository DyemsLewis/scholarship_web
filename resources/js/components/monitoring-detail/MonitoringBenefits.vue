<script setup>
import { computed } from 'vue';
import BenefitReleaseRecord from './BenefitReleaseRecord.vue';
import BenefitDeliveryReportPanel from '../benefit-delivery/BenefitDeliveryReportPanel.vue';
import RecipientCaseReviewPanel from '../case-reviews/RecipientCaseReviewPanel.vue';
import { checkInStatusClass } from '../../support/checkInStatus.js';

const props = defineProps({
    content: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
    applicationId: { type: [String, Number], required: true },
});
const emit = defineEmits(['refresh']);

const groups = computed(() => [
    { key: 'follow_up', title: 'Reply about a missed benefit', icon: 'fa-solid fa-comment-dots', action: 'Open follow-up', attention: true },
    { key: 'action_required', title: 'Confirm what you received', icon: 'fa-solid fa-receipt', action: 'Review release', attention: true },
    { key: 'payout_required', title: 'Payout details needed', icon: 'fa-solid fa-lock', action: 'Open release', attention: true },
    { key: 'upcoming', title: 'Upcoming releases', icon: 'fa-regular fa-calendar-check', action: 'View release' },
    { key: 'in_progress', title: 'Release updates', icon: 'fa-solid fa-arrows-rotate', action: 'View update' },
    { key: 'completed', title: 'Past releases', icon: 'fa-solid fa-clock-rotate-left', action: 'View record' },
].map((group) => ({ ...group, releases: props.content.directory[group.key] ?? [] })).filter((group) => group.releases.length));
</script>

<template>
    <BenefitDeliveryReportPanel
        v-if="content.selected"
        :history-url="`/dashboard/applications/${applicationId}/delivery-reports`"
        :release-id="content.selected.record_id"
        :receipt-saved="!!content.selected.receipt_response"
        @status-change="emit('refresh')"
    >
        <template #default="{ canReport, reportRelease }">
            <BenefitReleaseRecord
                :release="content.selected"
                :program-title="programTitle"
                :can-report="canReport"
                @report="reportRelease"
                @refresh="emit('refresh')"
            />
        </template>
    </BenefitDeliveryReportPanel>

    <div v-else data-monitoring-benefits class="mt-4 space-y-4">
        <section v-if="content.empty" class="flex items-start gap-3 rounded-md border border-slate-300 bg-white p-5 shadow-sm sm:px-6">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i></span>
            <div>
                <h3 class="font-bold text-slate-950">No release recorded yet</h3>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ content.one_time ? 'The provider will post the delivery details for your one-time award here.' : 'Published schedules and delivery updates will appear here.' }}</p>
            </div>
        </section>

        <component
            :is="group.key === 'completed' ? 'details' : 'section'"
            v-for="group in groups"
            :key="group.key"
            :open="group.key === 'completed' && content.one_time && groups.length === 1 ? true : undefined"
            :class="['group overflow-hidden rounded-md border bg-white shadow-sm', group.attention ? 'border-amber-300' : 'border-slate-300']"
        >
            <component
                :is="group.key === 'completed' ? 'summary' : 'header'"
                :class="['flex list-none items-center justify-between gap-3 px-4 py-3 sm:px-6 [&::-webkit-details-marker]:hidden',
                    group.key === 'completed' ? 'cursor-pointer' : '',
                    group.attention ? 'bg-amber-50' : 'bg-slate-50']"
            >
                <div class="flex items-center gap-3">
                    <span :class="['grid h-8 w-8 shrink-0 place-items-center rounded-sm text-xs', group.attention ? 'bg-amber-100 text-amber-800' : 'bg-slate-200/70 text-slate-600']"><i :class="group.icon" aria-hidden="true"></i></span>
                    <h3 class="font-bold text-slate-950">{{ group.title }}</h3>
                </div>
                <span class="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-600">
                    {{ group.releases.length }} {{ group.releases.length === 1 ? 'release' : 'releases' }}
                    <i v-if="group.key === 'completed'" class="fa-solid fa-chevron-down transition group-open:rotate-180" aria-hidden="true"></i>
                </span>
            </component>
            <article v-for="release in group.releases" :key="release.record_key" class="grid gap-3 border-t border-slate-200 px-4 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-6 sm:px-6">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <h4 class="break-words font-bold text-slate-950">{{ release.title }}</h4>
                        <span :class="['rounded-sm border px-2 py-0.5 text-xs font-bold', checkInStatusClass(release.delivery.tone)]">{{ release.delivery.label }}</span>
                        <span v-if="release.payout_status === 'submitted'" class="rounded-sm border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-bold text-slate-700">Payout details submitted</span>
                        <span v-else-if="release.payout_status === 'approved'" class="rounded-sm border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-800">Payout details approved</span>
                        <span v-else-if="release.payout_status === 'correction_requested'" class="rounded-sm border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-900">Correction requested</span>
                    </div>
                    <p v-if="release.delivery.date_label" class="mt-1.5 text-sm text-slate-600">{{ release.delivery.date_caption }}: {{ release.delivery.date_label }}</p>
                    <p class="mt-0.5 text-sm text-slate-600">{{ release.method_label }}<span v-if="release.amount_label"> &middot; {{ release.amount_label }}</span></p>
                </div>
                <a :href="release.href" :class="['inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border px-4 text-sm font-bold', group.attention ? 'border-slate-950 bg-slate-950 text-white hover:bg-slate-800' : 'border-slate-300 text-slate-700 hover:bg-slate-50']">
                    {{ group.action }}<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
                </a>
            </article>
        </component>

        <BenefitDeliveryReportPanel :history-url="`/dashboard/applications/${applicationId}/delivery-reports`" @status-change="emit('refresh')" />
    </div>
    <RecipientCaseReviewPanel :history-url="`/dashboard/applications/${applicationId}/case-reviews`" />
</template>
