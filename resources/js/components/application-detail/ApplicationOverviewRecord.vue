<script setup>
import { computed } from 'vue';
import ApplicationProgressRecord from './ApplicationProgressRecord.vue';

const props = defineProps({ detail: { type: Object, required: true } });
const content = computed(() => props.detail.content ?? {});
const sectionHref = key => props.detail.navigation?.find(item => item.key === key)?.href;
const showUpcoming = computed(() => content.value.upcoming_activity && props.detail.decision?.state !== 'action_required');
const recordRows = computed(() => (content.value.record ?? []).filter(row => row.value));
const hasEssentials = computed(() => showUpcoming.value || content.value.readiness || recordRows.value.length);
</script>

<template>
    <div class="space-y-4" data-application-overview>
        <ApplicationProgressRecord :progress="detail.progress" :description="content.current_step?.description || ''" />

        <div class="space-y-4" data-application-overview-stack>
            <section class="grid overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm sm:grid-cols-[minmax(0,1.6fr)_minmax(10rem,1fr)]" aria-labelledby="application-update-title" data-application-latest-update>
                <div class="min-w-0 px-5 py-5 sm:px-6 sm:py-6">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Latest application update</p>
                    <h2 id="application-update-title" class="mt-2 break-words font-display text-xl font-bold leading-snug text-slate-950">{{ content.latest_update?.title || 'No updates recorded yet' }}</h2>
                    <p v-if="content.latest_update?.occurred_at" class="mt-2 flex items-start gap-1.5 text-xs leading-5 text-slate-500"><i class="fa-regular fa-clock mt-1 block shrink-0 text-xs leading-none" aria-hidden="true"></i>{{ content.latest_update.occurred_at }}</p>
                    <p class="mt-3 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ content.latest_update?.description || 'New application updates will appear here when they are recorded.' }}</p>
                    <a v-if="sectionHref('history')" :href="sectionHref('history')" class="mt-4 inline-flex min-h-10 items-center gap-2 text-sm font-bold text-slate-800 underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600">View application history<i class="fa-solid fa-arrow-right block text-xs leading-none" aria-hidden="true"></i></a>
                </div>
                <figure class="relative h-28 overflow-hidden border-t border-slate-200 bg-slate-200 sm:h-auto sm:min-h-44 sm:border-t-0 sm:border-l">
                    <img :src="'/images/application-documents.jpg'" alt="Preparing scholarship application documents" class="absolute inset-0 h-full w-full object-cover object-[center_52%]" loading="lazy" data-application-update-image>
                </figure>
            </section>

            <section v-if="hasEssentials" class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-label="Application essentials" data-application-essentials>
                <section v-if="showUpcoming" class="border-b border-slate-200 bg-amber-50/70 px-5 py-5 sm:px-6" aria-labelledby="application-next-date" data-application-upcoming>
                    <p class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-[0.16em] text-amber-800"><i class="fa-regular fa-calendar block text-xs leading-none" aria-hidden="true"></i>Your next appointment</p>
                    <h2 id="application-next-date" class="mt-2 break-words text-base font-bold text-slate-950">{{ content.upcoming_activity.title }}</h2>
                    <p class="mt-2 text-sm font-semibold leading-6 text-slate-800">{{ content.upcoming_activity.scheduled_label }}</p>
                    <p v-if="content.upcoming_activity.mode_label" class="mt-1 text-xs text-slate-600">{{ content.upcoming_activity.mode_label }}</p>
                    <a v-if="sectionHref('activities')" :href="sectionHref('activities')" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-sm border border-amber-200 bg-white px-3 py-2 text-xs font-bold text-slate-800 hover:bg-amber-50">View schedule and instructions<i class="fa-solid fa-arrow-right block text-xs leading-none" aria-hidden="true"></i></a>
                </section>

                <section v-if="content.readiness" class="px-5 py-5 sm:px-6" aria-labelledby="application-submission-brief" data-application-submission-brief>
                    <h2 id="application-submission-brief" class="text-base font-bold text-slate-950">Your submitted record</h2>
                    <dl class="mt-3 divide-y divide-slate-100 text-sm">
                        <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-slate-600">Required files</dt><dd class="font-bold tabular-nums text-slate-950">{{ content.readiness.required_files }}</dd></div>
                        <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-slate-600">Files flagged for update</dt><dd :class="['font-bold tabular-nums', content.readiness.files_needing_attention ? 'text-amber-800' : 'text-slate-950']">{{ content.readiness.files_needing_attention }}</dd></div>
                        <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-slate-600">Saved question responses</dt><dd class="font-bold tabular-nums text-slate-950">{{ content.readiness.provider_questions }}</dd></div>
                    </dl>
                    <a v-if="sectionHref('submission')" :href="sectionHref('submission')" class="mt-3 inline-flex min-h-10 items-center gap-2 text-xs font-bold text-slate-800 underline-offset-4 hover:underline">View submitted details<i class="fa-solid fa-arrow-right block text-xs leading-none" aria-hidden="true"></i></a>
                </section>

                <details v-if="recordRows.length" class="group border-t border-slate-200" data-application-record-details>
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 text-xs font-bold text-slate-700 sm:px-6 [&::-webkit-details-marker]:hidden">Submission information<i class="fa-solid fa-chevron-down block text-xs leading-none text-slate-400 group-open:rotate-180" aria-hidden="true"></i></summary>
                    <dl class="space-y-3 border-t border-slate-100 bg-slate-50 px-5 py-4 sm:px-6">
                        <div v-for="row in recordRows" :key="row.label"><dt class="text-xs text-slate-500">{{ row.label }}</dt><dd class="mt-1 break-words text-sm font-semibold text-slate-800">{{ row.value }}</dd></div>
                    </dl>
                </details>
            </section>
        </div>

        <section v-if="content.links?.scholarship || content.links?.provider" class="divide-y divide-slate-200 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-label="Related information" data-application-related>
            <a v-if="content.links.scholarship" :href="content.links.scholarship" class="flex min-w-0 items-center gap-3 px-5 py-4 transition hover:bg-slate-50 sm:px-6">
                <span class="grid size-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-graduation-cap block text-sm leading-none" aria-hidden="true"></i></span>
                <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-900">Scholarship details</span><span class="mt-1 block text-xs text-slate-500">Revisit the published offer and terms.</span></span>
                <i class="fa-solid fa-arrow-right block shrink-0 text-xs leading-none text-slate-400" aria-hidden="true"></i>
            </a>
            <a v-if="content.links.provider" :href="content.links.provider" class="flex min-w-0 items-center gap-3 px-5 py-4 transition hover:bg-slate-50 sm:px-6">
                <span class="grid size-9 shrink-0 place-items-center rounded-sm bg-slate-100 text-slate-600"><i class="fa-solid fa-building-columns block text-sm leading-none" aria-hidden="true"></i></span>
                <span class="min-w-0 flex-1"><span class="block text-sm font-bold text-slate-900">Provider profile</span><span class="mt-1 block text-xs text-slate-500">Public contact and organization information.</span></span>
                <i class="fa-solid fa-arrow-right block shrink-0 text-xs leading-none text-slate-400" aria-hidden="true"></i>
            </a>
        </section>
    </div>
</template>
