<script setup>
import { computed } from 'vue';
import LocationMapLink from './LocationMapLink.vue';
import { hasLocationPin } from '../support/locationPins';

const props = defineProps({
    schedule: { type: Object, default: () => ({}) },
    preview: { type: Boolean, default: false },
});
const location = computed(() => props.schedule.location ?? {});
const requirements = computed(() => props.schedule.requirements ?? []);
const hasLocation = computed(() => location.value.name || location.value.address || hasLocationPin(location.value.latitude, location.value.longitude));
const mapAddress = computed(() => [...new Set([location.value.name, location.value.address].filter(Boolean))].join(', '));
</script>

<template>
    <div class="mt-4 overflow-hidden rounded-md border border-slate-300 bg-white" data-scholarship-formal-schedule>
        <div class="flex flex-wrap items-center justify-between gap-x-5 gap-y-3 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6" data-formal-deadline-strip>
            <div class="flex items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-amber-200 bg-amber-50 text-amber-800"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Complete by</p>
                    <p class="mt-1 text-lg font-bold text-slate-950" data-formal-deadline>{{ schedule.deadline_label || 'Provider will confirm' }}</p>
                </div>
            </div>
            <span class="inline-flex items-center gap-2 rounded-sm border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700"><i :class="schedule.mode === 'online' ? 'fa-solid fa-laptop' : 'fa-solid fa-building-columns'" class="text-slate-500" aria-hidden="true"></i>{{ schedule.mode_label || 'Provider instructions' }}</span>
        </div>

        <div class="divide-y divide-slate-200">
            <div v-if="hasLocation" class="flex flex-col justify-between gap-3 px-5 py-4 sm:flex-row sm:items-center sm:gap-6 sm:px-6" data-formal-venue>
                <div class="flex min-w-0 items-start gap-3">
                    <i class="fa-solid fa-location-dot mt-1 w-4 shrink-0 text-center text-amber-700" aria-hidden="true"></i>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-slate-500">Where to go</p>
                        <p class="mt-1 break-words text-sm font-bold text-slate-950">{{ location.name || location.address || 'Provider map location' }}</p>
                        <p v-if="location.name && location.address && location.address !== location.name" class="mt-1 break-words text-sm leading-6 text-slate-600">{{ location.address }}</p>
                    </div>
                </div>
                <div class="shrink-0"><LocationMapLink :address="mapAddress" :latitude="location.latitude" :longitude="location.longitude" :title="location.name || 'Formal application venue'" /></div>
            </div>
            <div class="flex items-start gap-3 px-5 py-4 sm:px-6">
                <i class="fa-solid fa-list-check mt-1 w-4 shrink-0 text-center text-slate-500" aria-hidden="true"></i>
                <div class="min-w-0 flex-1">
                    <h5 class="text-sm font-bold text-slate-950">What you need to do</h5>
                    <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ schedule.instructions || 'The provider will share instructions after you pass pre-screening. Check your application record for the next step.' }}</p>
                    <a v-if="schedule.url" :href="preview ? undefined : schedule.url" target="_blank" rel="noopener noreferrer" :aria-disabled="preview || undefined" :tabindex="preview ? -1 : undefined" class="student-button student-button-secondary mt-3 min-h-9 text-xs" @click="preview && $event.preventDefault()">Official application link<i class="fa-solid fa-arrow-up-right-from-square text-[10px]" aria-hidden="true"></i></a>
                </div>
            </div>
            <details v-if="requirements.length" :open="requirements.length <= 3" class="group px-5 py-4 sm:px-6" data-formal-preparation>
                <summary class="flex cursor-pointer list-none items-center gap-3 text-sm font-bold text-slate-800"><i class="fa-regular fa-folder-open w-4 shrink-0 text-center text-slate-500" aria-hidden="true"></i><span class="flex-1">What to bring or submit <span class="ml-1 text-xs font-normal text-slate-500">({{ requirements.length }})</span></span><i class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary>
                <ul class="mt-3 space-y-2 pl-7"><li v-for="requirement in requirements" :key="requirement" class="flex items-start gap-2 text-sm leading-6 text-slate-700"><i class="fa-solid fa-check mt-2 text-[9px] text-amber-700" aria-hidden="true"></i><span class="min-w-0 break-words">{{ requirement }}</span></li></ul>
            </details>
        </div>
        <p class="flex items-start gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-600 sm:px-6"><i class="fa-solid fa-circle-info mt-1 shrink-0 text-slate-400" aria-hidden="true"></i><span>Only continue after passing pre-screening. This is a completion deadline, not an assigned appointment. {{ schedule.session_count > 1 ? 'Several sessions are planned. ' : '' }}Check your application record for your confirmed session and instructions.</span></p>
    </div>
</template>
