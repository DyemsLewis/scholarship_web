<script setup>
import { computed, onMounted, ref } from 'vue';
import ProviderSidebar from '../ProviderSidebar.vue';

const programId = document.getElementById('app')?.dataset.programId;
const program = ref(null);
const isLoading = ref(true);
const errorMessage = ref('');
const showFullDescription = ref(false);

const descriptionIsLong = computed(() => (program.value?.description?.length ?? 0) > 360);
const visibleDescription = computed(() => {
    const description = program.value?.description || 'No program summary has been added.';

    if (!descriptionIsLong.value || showFullDescription.value) return description;

    return `${description.slice(0, 360).trim()}...`;
});

const timelineRows = computed(() => {
    if (!program.value) return [];

    return [
        { label: 'Applications open', value: program.value.timeline.opens || 'Not set' },
        { label: 'Application deadline', value: program.value.timeline.deadline.display },
        { label: 'Expected results', value: program.value.timeline.results || 'Not set' },
        { label: 'Support starts', value: program.value.timeline.support_starts || 'Not set' },
        { label: 'Support ends', value: program.value.timeline.support_ends || 'Not set' },
    ];
});

function statusClass(tone) {
    return {
        positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        attention: 'border-amber-200 bg-amber-50 text-amber-800',
        waiting: 'border-sky-200 bg-sky-50 text-sky-800',
        neutral: 'border-slate-200 bg-slate-100 text-slate-700',
    }[tone] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}

function capacityValue(value, suffix) {
    return value === null ? 'Not set' : `${value} ${suffix}`;
}

async function loadProgram() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(`/provider/programs/${programId}/data`);
        program.value = response.data.program;
    } catch (error) {
        errorMessage.value = error.response?.data?.message
            || 'This program record could not be loaded.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadProgram);
</script>

<template>
    <main class="student-shell">
        <ProviderSidebar />

        <section class="student-page" aria-labelledby="program-record-title">
            <div class="mx-auto max-w-[94rem]">
                <a href="/provider/programs" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                    <i class="fa-solid fa-arrow-left text-xs" aria-hidden="true"></i>
                    Back to programs
                </a>

                <div v-if="isLoading" class="mt-5 border border-slate-200 bg-white px-6 py-12 text-center shadow-sm">
                    <span class="mx-auto grid h-9 w-9 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-circle-notch animate-spin text-sm"></i></span>
                    <p class="mt-2 text-sm font-semibold text-slate-500">Loading program record...</p>
                </div>

                <div v-else-if="errorMessage" class="mt-5 border-l-4 border-rose-500 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-800" role="alert">
                    {{ errorMessage }}
                </div>

                <template v-else-if="program">
                    <header class="mt-5 border border-slate-200 border-t-2 border-t-slate-950 bg-white shadow-sm">
                        <div class="flex items-center justify-between gap-8 px-7 py-6">
                            <div class="flex min-w-0 items-center gap-4">
                                <span class="grid h-12 w-12 shrink-0 place-items-center bg-slate-950 text-amber-300" aria-hidden="true"><i class="fa-solid fa-graduation-cap"></i></span>
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-amber-700">Program record</p>
                                    <h1 id="program-record-title" class="mt-1 truncate font-display text-3xl font-bold tracking-tight text-slate-950">{{ program.title }}</h1>
                                    <p class="mt-2 text-sm text-slate-600">
                                        {{ program.category || 'Uncategorized' }}<template v-if="program.cycle"> · {{ program.cycle }}</template>
                                    </p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span :class="['inline-flex border px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide', statusClass(program.status.tone)]">
                                    {{ program.status.label }}
                                </span>
                                <a :href="`/provider/previews/programs/${programId}`" class="inline-flex h-10 items-center gap-2 border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 hover:border-slate-950">
                                    <i class="fa-solid fa-eye text-[10px]" aria-hidden="true"></i>
                                    Applicant preview
                                </a>
                                <a v-if="program.actions.edit_url" :href="program.actions.edit_url" class="inline-flex h-10 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">
                                    <i class="fa-solid fa-pen text-[10px]" aria-hidden="true"></i>
                                    Edit program
                                </a>
                            </div>
                        </div>

                        <div class="grid grid-cols-4 border-t border-slate-200 bg-slate-50">
                            <div class="flex items-center gap-3 border-r border-slate-200 px-5 py-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center bg-white text-slate-600" aria-hidden="true"><i class="fa-solid fa-file-lines text-sm"></i></span>
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Applications</p><p class="mt-1 text-sm font-bold text-slate-950">{{ program.capacity.applications }} received</p></div>
                            </div>
                            <div class="flex items-center gap-3 border-r border-slate-200 px-5 py-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center bg-white text-slate-600" aria-hidden="true"><i class="fa-solid fa-users-line text-sm"></i></span>
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Application limit</p><p class="mt-1 text-sm font-bold text-slate-950">{{ capacityValue(program.capacity.application_limit, 'applicants') }}</p></div>
                            </div>
                            <div class="flex items-center gap-3 border-r border-slate-200 px-5 py-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center bg-white text-slate-600" aria-hidden="true"><i class="fa-solid fa-award text-sm"></i></span>
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Recipient capacity</p><p class="mt-1 text-sm font-bold text-slate-950">{{ capacityValue(program.capacity.recipient_slots, 'slots') }}</p></div>
                            </div>
                            <div class="flex items-center gap-3 px-5 py-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center bg-white text-slate-600" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left text-sm"></i></span>
                                <div><p class="text-[10px] font-bold uppercase tracking-[0.15em] text-slate-500">Last updated</p><p class="mt-1 text-sm font-bold text-slate-950">{{ program.updated_display || 'Not available' }}</p></div>
                            </div>
                        </div>
                    </header>

                    <article class="mt-4 divide-y divide-slate-200 border border-slate-200 bg-white shadow-sm">
                        <section class="px-7 py-6" aria-labelledby="program-summary-heading">
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 place-items-center bg-slate-950 text-sm text-amber-300">
                                    <i class="fa-solid fa-align-left" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-amber-700">Overview</p>
                                    <h2 id="program-summary-heading" class="text-lg font-bold text-slate-950">Program summary</h2>
                                </div>
                            </div>
                            <p class="mt-4 max-w-5xl whitespace-pre-line text-sm leading-7 text-slate-700">{{ visibleDescription }}</p>
                            <button v-if="descriptionIsLong" type="button" class="mt-3 text-xs font-bold text-amber-700 hover:text-amber-900" @click="showFullDescription = !showFullDescription">
                                {{ showFullDescription ? 'Show less' : 'Read full summary' }}
                            </button>
                        </section>

                        <section class="px-7 py-6" aria-labelledby="program-timeline-heading">
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 place-items-center bg-slate-100 text-sm text-slate-700">
                                    <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-slate-500">Schedule</p>
                                    <h2 id="program-timeline-heading" class="text-lg font-bold text-slate-950">Program timeline</h2>
                                </div>
                            </div>
                            <dl class="mt-4 divide-y divide-slate-200 border-y border-slate-200">
                                <div v-for="row in timelineRows" :key="row.label" class="grid grid-cols-[16rem_1fr] items-center py-3 text-sm">
                                    <dt class="font-medium text-slate-500">{{ row.label }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ row.value }}</dd>
                                </div>
                            </dl>
                        </section>

                        <section class="px-7 py-6" aria-labelledby="program-selection-heading">
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 place-items-center bg-slate-100 text-sm text-slate-700">
                                    <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-slate-500">Process</p>
                                    <h2 id="program-selection-heading" class="text-lg font-bold text-slate-950">Selection stages</h2>
                                </div>
                            </div>
                            <ol v-if="program.selection_stages.length" class="mt-4 flex flex-wrap gap-2">
                                <li v-for="(stage, index) in program.selection_stages" :key="`${stage}-${index}`" class="inline-flex items-center border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800">
                                    <span class="mr-2 text-xs font-bold text-amber-700">{{ index + 1 }}</span>
                                    {{ stage }}
                                </li>
                            </ol>
                            <div v-else class="mt-4 flex items-center gap-3 border-y border-slate-200 py-4">
                                <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-100 text-slate-500" aria-hidden="true"><i class="fa-solid fa-list-check text-sm"></i></span>
                                <p class="text-sm text-slate-500">No selection stages are recorded.</p>
                            </div>
                        </section>

                        <section class="px-7 py-6" aria-labelledby="program-support-heading">
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 place-items-center bg-slate-100 text-sm text-slate-700">
                                    <i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.17em] text-slate-500">Award</p>
                                    <h2 id="program-support-heading" class="text-lg font-bold text-slate-950">Scholarship support</h2>
                                </div>
                            </div>
                            <div class="mt-4 border-y border-slate-200 py-4">
                                <p class="text-sm font-semibold text-slate-900">{{ program.support.summary }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ program.support.benefit_count }} recorded benefit{{ program.support.benefit_count === 1 ? '' : 's' }}</p>
                            </div>
                        </section>
                    </article>
                </template>
            </div>
        </section>
    </main>
</template>
