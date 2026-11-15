<script setup>
import { computed } from 'vue';
import BenefitReleaseRecord from './BenefitReleaseRecord.vue';
import BenefitReleaseCard from './BenefitReleaseCard.vue';
import BenefitDeliveryReportPanel from '../benefit-delivery/BenefitDeliveryReportPanel.vue';
import RecipientCaseReviewPanel from '../case-reviews/RecipientCaseReviewPanel.vue';
import { benefitReleaseGroups } from '../../support/benefitReleasePresentation.js';

const props = defineProps({
    content: { type: Object, required: true },
    programTitle: { type: String, default: 'Scholarship program' },
    applicationId: { type: [String, Number], required: true },
});
const emit = defineEmits(['refresh']);

const allGroups = computed(() => benefitReleaseGroups(props.content.directory));
const groups = computed(() => allGroups.value.filter((group) => group.releases.length));
const total = computed(() => allGroups.value.reduce((sum, group) => sum + group.releases.length, 0));
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
        <section data-benefit-directory-header class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
            <header class="relative isolate overflow-hidden bg-portal-navy px-5 py-5 text-white sm:px-6">
                <div class="relative z-10 max-w-2xl">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-300">Your scholarship benefits</p>
                    <h2 class="mt-1 font-display text-xl font-bold sm:text-2xl">Benefit releases</h2>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-slate-200">{{ content.one_time ? 'Track your one-time award, review the delivery details, and confirm it only after it arrives.' : 'See what is coming, check the provider\'s instructions, and confirm what you actually receive.' }}</p>
                </div>
                <div aria-hidden="true" class="pointer-events-none absolute inset-y-0 right-0 hidden w-2/5 md:block"><img :src="'/images/filipino-students-group-study.jpg'" alt="" class="h-full w-full object-cover opacity-40"><div class="absolute inset-0 bg-linear-to-r from-portal-navy via-portal-navy/50 to-portal-navy/20"></div></div>
            </header>
            <dl v-if="total" data-benefit-summary class="grid grid-cols-3 divide-x divide-slate-200">
                <div v-for="group in allGroups" :key="group.key" class="px-3 py-3 sm:px-5 sm:py-4"><dt class="text-[10px] font-bold leading-4 text-slate-500 sm:text-xs">{{ group.title }}</dt><dd :class="['mt-1 text-xl font-bold tabular-nums', group.attention && group.releases.length ? 'text-amber-800' : 'text-slate-950']">{{ group.releases.length }}</dd></div>
            </dl>
        </section>

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
                :class="['flex list-none items-start justify-between gap-3 px-4 py-4 sm:px-5 [&::-webkit-details-marker]:hidden', group.key === 'completed' ? 'cursor-pointer' : '', group.attention ? 'bg-amber-50/60' : 'bg-slate-50']"
            >
                <div class="min-w-0">
                    <h3 class="text-base font-bold text-slate-950">{{ group.title }}</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ group.description }}</p>
                </div>
                <span class="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-600">
                    {{ group.releases.length }} {{ group.releases.length === 1 ? 'release' : 'releases' }}
                    <i v-if="group.key === 'completed'" class="fa-solid fa-chevron-down transition group-open:rotate-180" aria-hidden="true"></i>
                </span>
            </component>
            <BenefitReleaseCard v-for="release in group.releases" :key="release.record_key" :release="release" :attention="group.attention" embedded />
        </component>

        <BenefitDeliveryReportPanel :history-url="`/dashboard/applications/${applicationId}/delivery-reports`" @status-change="emit('refresh')" />
    </div>
    <RecipientCaseReviewPanel :history-url="`/dashboard/applications/${applicationId}/case-reviews`" />
</template>
