<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    record: { type: Object, required: true },
    status: { type: Object, required: true },
});
defineEmits(['edit']);
const photoUnavailable = ref(false);
watch(() => props.record.photoUrl, () => { photoUnavailable.value = false; });

const badgeClasses = {
    positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    attention: 'border-amber-200 bg-amber-50 text-amber-900',
    neutral: 'border-slate-200 bg-slate-100 text-slate-700',
};
</script>

<template>
    <header data-profile-identity class="flex flex-col gap-5 border-b border-slate-200 px-5 py-5 sm:px-6 sm:py-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex min-w-0 items-center gap-4 sm:gap-5">
            <button type="button" class="relative h-20 w-20 shrink-0 rounded-sm border border-slate-200 bg-slate-100 p-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 sm:h-24 sm:w-24" aria-label="Edit applicant photo" @click="$emit('edit')">
                <img v-if="record.photoUrl && !photoUnavailable" :src="record.photoUrl" :alt="`${record.name} applicant photo`" class="h-full w-full rounded-sm object-cover" @error="photoUnavailable = true">
                <img v-else :src="'/images/demo/default-applicant-avatar.jpg'" alt="Default applicant portrait" class="h-full w-full rounded-sm object-cover">
                <span class="absolute -bottom-1 -right-1 grid h-7 w-7 place-items-center rounded-sm border border-slate-200 bg-white text-slate-600" aria-hidden="true"><i class="fa-solid fa-camera text-[11px] leading-none"></i></span>
            </button>
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Applicant record</p>
                <h2 id="applicant-record-name" class="mt-2 break-words font-display text-xl font-bold leading-snug text-slate-950 sm:text-3xl">{{ record.name }}</h2>
                <p class="mt-2 text-sm leading-5 text-slate-600">{{ record.educationSummary || 'Learning details not provided' }}</p>
                <span :class="['mt-3 inline-flex items-center gap-1.5 rounded-sm border px-2 py-1 text-[10px] font-bold uppercase tracking-wide', badgeClasses[status.tone] || badgeClasses.neutral]" data-profile-status>
                    <i :class="[status.key === 'ready' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle', status.key === 'ready' ? 'text-[10px]' : 'text-[5px]', 'leading-none']" aria-hidden="true"></i>{{ status.label }}
                </span>
            </div>
        </div>
        <button type="button" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-sm bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2" @click="$emit('edit')">
            <i class="fa-solid fa-pen text-xs leading-none" aria-hidden="true"></i>Edit profile
        </button>
    </header>
</template>
