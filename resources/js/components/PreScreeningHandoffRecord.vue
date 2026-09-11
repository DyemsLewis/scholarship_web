<script setup>
import { computed } from 'vue';

const props = defineProps({
    record: {
        type: Object,
        required: true,
    },
});

const applicantFields = computed(() => [
    { label: 'Applicant', value: props.record.applicant?.name, icon: 'fa-solid fa-user' },
    { label: 'Education', value: props.record.applicant?.education, icon: 'fa-solid fa-graduation-cap' },
    { label: 'School', value: props.record.applicant?.school, icon: 'fa-solid fa-school' },
    { label: 'Academic result', value: props.record.applicant?.academic_result, icon: 'fa-solid fa-chart-line' },
    { label: 'Location', value: props.record.applicant?.location, icon: 'fa-solid fa-location-dot' },
    { label: 'Contact', value: contactSummary.value, icon: 'fa-solid fa-address-card' },
].filter((field) => field.value));

const contactSummary = computed(() => [
    props.record.applicant?.email,
    props.record.applicant?.contact_number,
].filter(Boolean).join(' - '));

const documentSummary = computed(() => {
    const accepted = Number(props.record.review?.accepted_files ?? 0);
    const required = Number(props.record.review?.required_files ?? 0);

    return required > 0 ? `${accepted} of ${required} accepted` : 'No portal files required';
});

function scoreLabel(value) {
    return value === null || value === undefined ? 'Not scored' : `${value}%`;
}
</script>

<template>
    <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-200 bg-slate-950 px-4 py-4 text-white sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-300 text-slate-950">
                    <i class="fa-solid fa-file-circle-check" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-300">Pre-screening handoff record</p>
                    <h3 class="mt-1 text-base font-bold">{{ record.decision?.label || 'Passed pre-screening' }}</h3>
                    <p class="mt-0.5 text-xs text-slate-300">{{ record.record_id }} &middot; Snapshot version {{ record.snapshot_version }}</p>
                </div>
            </div>
            <div class="shrink-0 sm:text-right">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-100 px-2.5 py-1.5 text-xs font-bold text-emerald-800">
                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                    Qualified to continue
                </span>
                <p v-if="record.passed_at" class="mt-1.5 text-xs text-slate-300">Recorded {{ record.passed_at }}</p>
            </div>
        </header>

        <div class="grid border-b border-slate-200 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border-b border-slate-200 px-4 py-3 sm:border-r lg:border-b-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Eligibility check</p>
                <p class="mt-1 text-sm font-bold text-slate-950">{{ scoreLabel(record.review?.eligibility_score) }}</p>
            </div>
            <div class="border-b border-slate-200 px-4 py-3 lg:border-b-0 lg:border-r">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Match guidance</p>
                <p class="mt-1 text-sm font-bold text-slate-950">{{ record.review?.dss_label || scoreLabel(record.review?.dss_score) }}</p>
            </div>
            <div class="border-b border-slate-200 px-4 py-3 sm:border-r sm:border-b-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Required files</p>
                <p class="mt-1 text-sm font-bold text-slate-950">{{ documentSummary }}</p>
            </div>
            <div class="px-4 py-3">
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Academic proof</p>
                <p class="mt-1 text-sm font-bold text-slate-950">{{ record.review?.academic_verification_label }}</p>
            </div>
        </div>

        <div class="grid lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
            <section class="border-b border-slate-200 p-4 lg:border-r lg:border-b-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Information reviewed</p>
                        <p class="mt-1 text-sm font-bold text-slate-950">Submitted applicant record</p>
                    </div>
                    <span class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600">{{ record.profile_source }}</span>
                </div>
                <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                    <div v-for="field in applicantFields" :key="field.label" class="flex min-w-0 items-start gap-2 rounded-md bg-slate-50 px-3 py-2.5 ring-1 ring-slate-200">
                        <i :class="[field.icon, 'mt-1 w-4 shrink-0 text-center text-amber-700']" aria-hidden="true"></i>
                        <div class="min-w-0">
                            <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">{{ field.label }}</dt>
                            <dd class="mt-0.5 break-words text-xs font-semibold leading-5 text-slate-800">{{ field.value }}</dd>
                        </div>
                    </div>
                </dl>

                <div v-if="record.review?.accepted_file_names?.length" class="mt-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Accepted portal files</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span v-for="file in record.review.accepted_file_names" :key="file" class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200">
                            {{ file }}
                        </span>
                    </div>
                </div>
            </section>

            <section class="p-4">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Provider decision</p>
                <p class="mt-2 text-sm leading-6 text-slate-700">{{ record.decision?.note }}</p>

                <div class="mt-4 border-t border-slate-200 pt-4">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Next configured stage</p>
                    <p class="mt-1 text-sm font-bold text-slate-950">{{ record.next_step?.label }}</p>
                    <p v-if="record.next_step?.mode_label" class="mt-1 text-xs font-semibold text-slate-600">{{ record.next_step.mode_label }}</p>
                    <p class="mt-2 text-xs leading-5 text-slate-600">{{ record.next_step?.instructions }}</p>
                    <p v-if="record.next_step?.deadline" class="mt-2 text-xs font-bold text-amber-800">Deadline: {{ record.next_step.deadline }}</p>
                    <p v-if="record.next_step?.location" class="mt-1 text-xs font-semibold text-slate-700">{{ record.next_step.location }}</p>
                    <a v-if="record.next_step?.url" :href="record.next_step.url" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-2 rounded-md bg-slate-950 px-3 py-2 text-xs font-bold text-white">
                        Open provider link
                        <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                    </a>
                </div>
            </section>
        </div>

        <footer class="border-t border-slate-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-950">
            <i class="fa-solid fa-circle-info mr-1.5 text-amber-700" aria-hidden="true"></i>
            {{ record.notice }}
        </footer>
    </article>
</template>
