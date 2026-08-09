<script setup>
import { computed, ref } from 'vue';
import TermsModal from './TermsModal.vue';

const props = defineProps({
    context: {
        type: String,
        default: 'profile',
        validator: (value) => ['profile', 'documents', 'applications', 'application'].includes(value),
    },
});

const showPrivacyNotice = ref(false);
const contextCopy = computed(() => ({
    profile: {
        title: 'Why we ask for profile details',
        text: 'Education, household, and location details support matching and eligibility checks. Your profile is not public.',
    },
    documents: {
        title: 'Your files are not public',
        text: 'Prepared files are reused only when you attach them to an application. Upload only information needed for scholarship review.',
    },
    applications: {
        title: 'Who can review an application',
        text: 'After submission, only the selected provider team and authorized administrators can review the relevant profile and attached files.',
    },
    application: {
        title: 'Access is limited to this application',
        text: 'Files and profile details here are available only to you, authorized administrators, and the provider team managing this program.',
    },
})[props.context]);
</script>

<template>
    <section class="mt-4 flex flex-col gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-emerald-100 text-emerald-800">
                <i class="fa-solid fa-shield-halved text-sm" aria-hidden="true"></i>
            </span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-sm font-bold text-slate-950">{{ contextCopy.title }}</h2>
                    <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600">Not public</span>
                </div>
                <p class="mt-1 text-xs leading-5 text-slate-600">{{ contextCopy.text }}</p>
            </div>
        </div>
        <button
            type="button"
            class="shrink-0 text-left text-xs font-bold text-slate-700 underline decoration-amber-400 underline-offset-4 hover:text-slate-950 sm:text-right"
            @click="showPrivacyNotice = true"
        >
            Privacy details
        </button>

        <TermsModal v-model="showPrivacyNotice" context="privacy" />
    </section>
</template>
