<script setup>
import { computed } from 'vue';
import { benefitCardGuidance, benefitMethodIcon } from '../../support/benefitReleasePresentation.js';
import { checkInStatusClass } from '../../support/checkInStatus.js';

const props = defineProps({ release: { type: Object, required: true }, attention: Boolean, embedded: Boolean });
const payoutNeeded = computed(() => props.release.priority_key === 'payout_required');
const badge = computed(() => payoutNeeded.value
    ? { label: props.release.payout_status === 'correction_requested' ? 'Correction requested' : 'Payout details needed', tone: 'attention' }
    : props.release.delivery);
</script>

<template>
    <article data-benefit-card :data-release-key="release.record_key" :class="['overflow-hidden bg-white', embedded ? 'border-t border-slate-200' : ['rounded-md border shadow-sm', attention ? 'border-amber-300' : 'border-slate-300']]">
        <div class="px-4 py-4 sm:px-5 sm:py-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-portal-navy text-amber-300"><i :class="[benefitMethodIcon(release.release_method), 'text-sm leading-none']" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <span :class="['inline-flex rounded-sm border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide', checkInStatusClass(badge?.tone)]">{{ badge?.label || 'Release update' }}</span>
                        <h4 class="mt-1.5 break-words text-base font-bold leading-6 text-slate-950">{{ release.title }}</h4>
                    </div>
                </div>
                <div v-if="release.amount_label" class="shrink-0 pl-[3.25rem] sm:pl-0 sm:text-right">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Release amount</p>
                    <p class="mt-1 font-display text-xl font-bold tabular-nums text-slate-950">{{ release.amount_label }}</p>
                </div>
            </div>
            <dl class="mt-4 grid gap-3 rounded-sm border border-slate-200 bg-slate-50 px-3 py-3 sm:grid-cols-2 sm:gap-5 sm:px-4">
                <div class="min-w-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ release.delivery?.date_caption || 'Release date' }}</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-800">{{ release.delivery?.date_label || 'Not provided yet' }}</dd></div>
                <div class="min-w-0"><dt class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Delivery method</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-800">{{ release.method_label || 'Awaiting provider details' }}</dd></div>
            </dl>
        </div>
        <footer class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-5">
            <p class="min-w-0 text-xs leading-5 text-slate-600">{{ benefitCardGuidance(release) }}</p>
            <a :href="release.href" :class="['inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md border px-4 text-sm font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 sm:min-w-44', attention ? 'border-slate-950 bg-slate-950 text-white hover:bg-slate-800' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50']">{{ release.action_label }}<i class="fa-solid fa-arrow-right block w-3 text-center text-xs leading-none" aria-hidden="true"></i></a>
        </footer>
    </article>
</template>
