<script setup>
import { computed } from 'vue';
import { hasLocationPin } from '../../support/locationPins';

const props = defineProps({
    handoff: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['map']);
const contact = computed(() => props.handoff.contact ?? {});
const requirements = computed(() => props.handoff.requirements ?? []);
const hasContact = computed(() => contact.value.name || contact.value.department || contact.value.email || contact.value.number);
const hasLocation = computed(() => props.handoff.location_name || props.handoff.location_address
    || hasLocationPin(props.handoff.latitude, props.handoff.longitude));

function modeLabel(mode) {
    return {
        online: 'Online',
        onsite: 'In person',
        hybrid: 'Online and in person',
        provider_contact: 'Contact the provider',
    }[mode] ?? String(mode || 'Provider instructions').replaceAll('_', ' ');
}
</script>

<template>
    <section id="formal-application-handoff" class="scroll-mt-4 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" aria-labelledby="formal-handoff-title" data-application-formal-schedule>
        <header class="flex flex-col gap-4 border-b border-slate-200 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300">
                    <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Provider verification</p>
                    <h2 id="formal-handoff-title" class="mt-1 font-display text-2xl font-bold text-slate-950">Formal application</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">Follow the provider instructions to complete this stage.</p>
                </div>
            </div>
            <div v-if="handoff.url" class="flex shrink-0 flex-wrap gap-2">
                <a v-if="handoff.url" :href="handoff.url" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800">
                    Continue online
                    <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                </a>
            </div>
        </header>

        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6" data-formal-completion-deadline>
            <div class="flex items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm border border-amber-200 bg-amber-50 text-amber-800"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span>
                <div><p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Completion deadline</p><p class="mt-1 text-lg font-bold text-slate-950">{{ handoff.deadline || 'Provider will confirm' }}</p></div>
            </div>
            <span class="inline-flex items-center gap-2 rounded-sm border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700"><i :class="handoff.mode === 'online' ? 'fa-solid fa-laptop' : 'fa-solid fa-building-columns'" class="text-slate-500" aria-hidden="true"></i>{{ modeLabel(handoff.mode) }}</span>
        </div>

        <div v-if="hasLocation" class="flex flex-col justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:gap-6 sm:px-6" data-formal-handoff-venue>
            <div class="flex min-w-0 items-start gap-3">
                <i class="fa-solid fa-location-dot mt-1 w-4 shrink-0 text-center text-amber-700" aria-hidden="true"></i>
                <div class="min-w-0"><h3 class="text-xs font-semibold text-slate-500">Where to go</h3><p class="mt-1 break-words text-sm font-bold text-slate-950">{{ handoff.location_name || handoff.location_address || 'Provider map location' }}</p><p v-if="handoff.location_name && handoff.location_address && handoff.location_name !== handoff.location_address" class="mt-1 break-words text-sm leading-6 text-slate-600">{{ handoff.location_address }}</p></div>
            </div>
            <button type="button" class="inline-flex min-h-10 w-fit shrink-0 items-center justify-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="emit('map', handoff)"><i class="fa-solid fa-map-location-dot text-xs text-amber-700" aria-hidden="true"></i>View map</button>
        </div>

        <div class="flex items-start gap-3 px-5 py-4 sm:px-6">
            <i class="fa-solid fa-list-check mt-1 w-4 shrink-0 text-center text-slate-500" aria-hidden="true"></i>
            <div class="min-w-0"><h3 class="text-sm font-bold text-slate-950">What you need to do</h3><p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ handoff.instructions || 'Contact the provider for the instructions needed to complete formal verification.' }}</p></div>
        </div>

        <details v-if="requirements.length" :open="requirements.length <= 3" class="group border-t border-slate-200 px-5 py-4 sm:px-6" data-formal-preparation>
            <summary class="flex cursor-pointer list-none items-center gap-3 text-sm font-bold text-slate-950"><i class="fa-regular fa-folder-open w-4 shrink-0 text-center text-slate-500" aria-hidden="true"></i><span class="flex-1">What to prepare <span class="ml-1 text-xs font-normal text-slate-500">({{ requirements.length }})</span></span><i class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary>
            <ul class="mt-3 space-y-2 pl-7"><li v-for="requirement in requirements" :key="requirement" class="flex items-start gap-2 text-sm leading-6 text-slate-700"><i class="fa-solid fa-check mt-2 text-[9px] text-amber-700" aria-hidden="true"></i><span class="min-w-0 break-words">{{ requirement }}</span></li></ul>
        </details>

        <details v-if="hasContact" class="group border-t border-slate-200 px-5 py-4 sm:px-6" data-formal-provider-contact>
            <summary class="flex cursor-pointer list-none items-center gap-3 text-sm font-bold text-slate-700"><i class="fa-regular fa-address-card w-4 shrink-0 text-center text-slate-500" aria-hidden="true"></i><span class="flex-1">Need help? Contact the provider</span><i class="fa-solid fa-chevron-down text-[10px] text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i></summary>
            <div class="mt-3 space-y-2 pl-7 text-sm"><p v-if="contact.name || contact.department" class="font-bold text-slate-950">{{ [contact.department, contact.name].filter(Boolean).join(' | ') }}</p><a v-if="contact.email" :href="`mailto:${contact.email}`" class="block break-all font-semibold text-slate-700 hover:underline">{{ contact.email }}</a><a v-if="contact.number" :href="`tel:${contact.number}`" class="block font-semibold text-slate-700 hover:underline">{{ contact.number }}</a></div>
        </details>

        <footer class="flex items-start gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3 text-xs leading-5 text-slate-600 sm:px-6"><i class="fa-solid fa-circle-info mt-1 shrink-0 text-slate-400" aria-hidden="true"></i><p>{{ handoff.notice || 'Completing this step does not mean you have been awarded the scholarship.' }} A completion deadline is not an appointment time; check Next activity for a separately assigned session.</p></footer>
    </section>
</template>
