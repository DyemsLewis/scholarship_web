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
    <header data-profile-identity class="relative isolate flex flex-col gap-5 overflow-hidden border-b border-slate-200 bg-portal-navy px-5 py-5 sm:px-6 sm:py-6 lg:flex-row lg:items-center lg:justify-between">
        <div class="relative z-10 flex min-w-0 items-center gap-4 sm:gap-5">
            <button type="button" class="relative h-20 w-20 shrink-0 rounded-sm border border-white/40 bg-white p-1 shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-portal-navy sm:h-24 sm:w-24" aria-label="Edit applicant photo" @click="$emit('edit')">
                <img v-if="record.photoUrl && !photoUnavailable" :src="record.photoUrl" :alt="`${record.name} applicant photo`" class="h-full w-full rounded-sm object-cover" @error="photoUnavailable = true">
                <img v-else :src="'/images/demo/default-applicant-avatar.jpg'" alt="Default applicant portrait" class="h-full w-full rounded-sm object-cover">
                <span class="absolute -bottom-1 -right-1 grid h-7 w-7 place-items-center rounded-sm border border-slate-200 bg-white text-slate-600" aria-hidden="true"><i class="fa-solid fa-camera text-[11px] leading-none"></i></span>
            </button>
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-300">Applicant record</p>
                <h2 id="applicant-record-name" class="mt-2 break-words font-display text-xl font-bold leading-snug text-white sm:text-3xl">{{ record.name }}</h2>
                <p class="mt-2 text-sm leading-5 text-slate-200">{{ record.educationSummary || 'Learning details not provided' }}</p>
                <span :class="['mt-3 inline-flex items-center gap-1.5 rounded-sm border px-2 py-1 text-[10px] font-bold uppercase tracking-wide', badgeClasses[status.tone] || badgeClasses.neutral]" data-profile-status>
                    <i :class="[status.key === 'ready' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle', status.key === 'ready' ? 'text-[10px]' : 'text-[5px]', 'leading-none']" aria-hidden="true"></i>{{ status.label }}
                </span>
            </div>
        </div>
        <button type="button" class="relative z-10 inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-sm border border-amber-300 bg-amber-300 px-4 py-2.5 text-sm font-bold text-portal-navy transition hover:bg-amber-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-portal-navy" @click="$emit('edit')">
            <i class="fa-solid fa-pen text-xs leading-none" aria-hidden="true"></i>Edit profile
        </button>
        <div data-profile-cover aria-hidden="true" class="pointer-events-none absolute inset-y-0 right-0 hidden w-2/5 md:block">
            <img :src="'/images/filipino-students-group-study.jpg'" alt="" width="720" height="548" decoding="async" class="h-full w-full object-cover object-center opacity-50">
            <div class="absolute inset-0 bg-linear-to-r from-portal-navy via-portal-navy/40 to-portal-navy/20"></div>
        </div>
    </header>
</template>
