<script setup>
const props = defineProps({
    handoff: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['map']);

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
    <section id="formal-application-handoff" class="scroll-mt-4 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
        <header class="flex flex-col gap-4 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                    <i class="fa-solid fa-building-circle-arrow-right" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Formal application</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950">Continue with the provider</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-600">{{ handoff.notice }}</p>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <a v-if="handoff.url" :href="handoff.url" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-sm font-bold text-white hover:bg-slate-800">
                    Continue online
                    <i class="fa-solid fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i>
                </a>
                <button v-if="handoff.location_name || handoff.location_address" type="button" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" @click="emit('map', handoff)">
                    <i class="fa-solid fa-map-location-dot text-xs text-amber-700" aria-hidden="true"></i>
                    View map
                </button>
            </div>
        </header>

        <dl class="grid bg-slate-50/80 sm:grid-cols-3">
            <div class="px-4 py-3 sm:px-5">
                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Method</dt>
                <dd class="mt-1 text-sm font-bold capitalize text-slate-950">{{ modeLabel(handoff.mode) }}</dd>
            </div>
            <div class="border-t border-slate-200 px-4 py-3 sm:border-l sm:border-t-0 sm:px-5">
                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Deadline</dt>
                <dd class="mt-1 text-sm font-bold text-slate-950">{{ handoff.deadline || 'Provider will confirm' }}</dd>
            </div>
            <div class="border-t border-slate-200 px-4 py-3 sm:border-l sm:border-t-0 sm:px-5">
                <dt class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Contact</dt>
                <dd class="mt-1 text-sm font-bold text-slate-950">{{ handoff.contact.name || handoff.contact.department || 'Scholarship provider' }}</dd>
            </div>
        </dl>

        <div v-if="handoff.instructions" class="border-t border-slate-200 px-4 py-4 sm:px-5">
            <h3 class="text-sm font-bold text-slate-950">Provider instructions</h3>
            <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ handoff.instructions }}</p>
        </div>

        <div v-if="handoff.requirements?.length" class="border-t border-slate-200 px-4 py-4 sm:px-5">
            <h3 class="text-sm font-bold text-slate-950">What to prepare</h3>
            <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                <li v-for="requirement in handoff.requirements" :key="requirement" class="flex items-start gap-2 text-sm text-slate-700">
                    <i class="fa-solid fa-check mt-1 text-xs text-amber-700" aria-hidden="true"></i>
                    <span>{{ requirement }}</span>
                </li>
            </ul>
        </div>

        <div v-if="handoff.location_name || handoff.location_address || handoff.contact.email || handoff.contact.number" class="grid border-t border-slate-200 bg-slate-50/70 sm:grid-cols-2">
            <div v-if="handoff.location_name || handoff.location_address" class="px-4 py-4 sm:px-5">
                <p class="text-xs font-bold text-slate-500">Provider location</p>
                <p v-if="handoff.location_name" class="mt-1 text-sm font-bold text-slate-950">{{ handoff.location_name }}</p>
                <p v-if="handoff.location_address" class="mt-1 text-sm leading-5 text-slate-600">{{ handoff.location_address }}</p>
            </div>
            <div v-if="handoff.contact.email || handoff.contact.number" class="border-t border-slate-200 px-4 py-4 sm:border-l sm:border-t-0 sm:px-5">
                <p class="text-xs font-bold text-slate-500">Provider contact</p>
                <div class="mt-2 flex flex-col gap-1.5 text-sm">
                    <a v-if="handoff.contact.email" :href="`mailto:${handoff.contact.email}`" class="font-semibold text-slate-700 hover:text-slate-950 hover:underline">{{ handoff.contact.email }}</a>
                    <a v-if="handoff.contact.number" :href="`tel:${handoff.contact.number}`" class="font-semibold text-slate-700 hover:text-slate-950 hover:underline">{{ handoff.contact.number }}</a>
                </div>
            </div>
        </div>
    </section>
</template>
