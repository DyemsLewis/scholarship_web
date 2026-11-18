<script setup>
import ApplicantTextSummary from '../ApplicantTextSummary.vue';

defineProps({ record: { type: Object, required: true } });
</script>

<template>
    <section id="final-agreement" tabindex="-1" data-final-agreement-requirement class="scroll-mt-6 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500" aria-labelledby="final-agreement-title">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-amber-300"><i class="fa-solid fa-file-signature text-sm leading-none" aria-hidden="true"></i></span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Provider onboarding</p>
                    <h2 id="final-agreement-title" class="mt-0.5 text-lg font-bold text-slate-950">Final agreement</h2>
                </div>
            </div>
            <span :class="['rounded-sm px-2.5 py-1.5 text-xs font-bold', record.state === 'completed' ? 'bg-emerald-100 text-emerald-800' : record.can_proceed ? 'bg-amber-100 text-amber-900' : 'bg-slate-200 text-slate-700']">{{ record.status_label }}</span>
        </header>
        <div class="space-y-4 px-5 py-5 sm:px-6">
            <p class="text-sm leading-6 text-slate-700">{{ record.guidance }}</p>
            <div v-if="record.completed_label" class="border-l-2 border-emerald-500 bg-emerald-50/60 px-4 py-3">
                <p class="text-xs font-semibold text-emerald-900">Provider-confirmed completion</p>
                <p class="mt-1 text-sm font-bold text-slate-950">{{ record.completed_label }}</p>
            </div>
            <div v-if="record.provider_instructions" data-final-agreement-provider-instructions class="rounded-sm border border-slate-200 bg-slate-50/60 p-4">
                <h3 class="text-sm font-bold text-slate-950">What you need to do</h3>
                <p v-if="record.instructions_published_label" class="mb-2 mt-1 text-xs text-slate-500">Provider update: {{ record.instructions_published_label }}</p>
                <ApplicantTextSummary :text="record.provider_instructions" label="provider onboarding instructions" />
            </div>
            <details v-if="record.instructions" :open="!record.provider_instructions && record.state !== 'completed'" class="group rounded-sm border border-slate-200">
                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-slate-800 [&::-webkit-details-marker]:hidden">Instructions disclosed with your offer <i class="fa-solid fa-chevron-down text-xs leading-none text-slate-500 group-open:rotate-180" aria-hidden="true"></i></summary>
                <p class="whitespace-pre-line break-words border-t border-slate-200 px-4 py-3 text-sm leading-6 text-slate-600">{{ record.instructions }}</p>
            </details>
            <p class="text-xs leading-5 text-slate-500">Only the provider can record completion. Attendance and intention confirmation do not automatically complete this requirement.</p>
        </div>
        <footer class="flex flex-wrap items-center gap-2 border-t border-slate-200 px-5 py-4 sm:px-6">
            <a :href="record.offer_href" class="student-button student-button-secondary">{{ record.state === 'awaiting_intention' ? 'Review offer first' : 'View offer record' }} <i class="fa-solid fa-arrow-right text-xs leading-none" aria-hidden="true"></i></a>
            <a v-if="record.contact.email_href" :href="record.contact.email_href" class="student-button student-button-secondary" :aria-label="`Email provider at ${record.contact.email}`">Email provider <i class="fa-regular fa-envelope text-xs leading-none" aria-hidden="true"></i></a>
            <a v-if="record.contact.phone_href" :href="record.contact.phone_href" class="student-button student-button-secondary" :aria-label="`Call provider at ${record.contact.number}`">Call provider <i class="fa-solid fa-phone text-xs leading-none" aria-hidden="true"></i></a>
            <a v-if="record.can_proceed" :href="record.benefits_href" class="text-xs font-bold leading-5 text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-950">Existing events and collection dates</a>
        </footer>
    </section>
</template>
