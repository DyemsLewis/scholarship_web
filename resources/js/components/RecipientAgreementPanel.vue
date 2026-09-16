<script setup>
import { computed } from 'vue';
import { agreementClarity, recipientCommitmentLabels } from '../support/recipientAgreement';

const props = defineProps({
    scholarship: {
        type: Object,
        required: true,
    },
    reviewer: {
        type: Boolean,
        default: false,
    },
});

const clarity = computed(() => agreementClarity(props.scholarship));
const statusClass = computed(() => clarity.value.complete
    ? 'bg-emerald-100 text-emerald-800 ring-emerald-200'
    : 'bg-amber-100 text-amber-900 ring-amber-200');
const statusIcon = computed(() => clarity.value.complete
    ? 'fa-solid fa-circle-check'
    : 'fa-solid fa-circle-exclamation');
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <header class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-900 text-amber-300">
                    <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Recipient agreement preview</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">{{ reviewer ? 'Commitment disclosure check' : 'Check the terms before you agree' }}</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ recipientCommitmentLabels[clarity.agreement.commitment_type] || 'Recipient commitment' }}</p>
                </div>
            </div>
            <span :class="['inline-flex w-fit items-center gap-2 rounded-md px-3 py-1.5 text-xs font-bold ring-1 ring-inset', statusClass]">
                <i :class="statusIcon" aria-hidden="true"></i>
                {{ clarity.title }}
            </span>
        </header>

        <div class="divide-y divide-slate-200">
            <div v-for="check in clarity.checks" :key="check.key" class="grid gap-2 p-4 sm:grid-cols-[13rem_minmax(0,1fr)] sm:gap-4">
                <div class="flex items-center gap-2">
                    <i :class="check.complete ? 'fa-solid fa-check-circle text-emerald-700' : 'fa-solid fa-circle-question text-amber-700'" aria-hidden="true"></i>
                    <p class="text-sm font-bold text-slate-900">{{ check.label }}</p>
                </div>
                <p :class="['whitespace-pre-line text-sm leading-6', check.complete ? 'text-slate-600' : 'font-semibold text-amber-900']">
                    {{ check.complete ? check.value : check.missing }}
                </p>
            </div>
        </div>

        <footer class="border-t border-slate-200 bg-amber-50/70 px-4 py-3 text-xs leading-5 text-slate-600">
            This guide checks whether important terms are disclosed. It does not decide legal fairness or replace independent advice.
            Basis:
            <a href="https://lawphil.net/statutes/repacts/ra1949/ra_386_1949.html" target="_blank" rel="noopener noreferrer" class="font-bold text-slate-800 underline decoration-amber-400 underline-offset-2">Civil Code contract principles</a>,
            <a href="https://lawphil.net/statutes/repacts/ra1994/ra_7687_1994.html" target="_blank" rel="noopener noreferrer" class="font-bold text-slate-800 underline decoration-amber-400 underline-offset-2">DOST scholarship service obligations</a>, and
            <a href="https://ched.gov.ph/wp-content/uploads/2017/11/CMO-No.-3-Series-of-2016.pdf" target="_blank" rel="noopener noreferrer" class="font-bold text-slate-800 underline decoration-amber-400 underline-offset-2">CHED scholarship terms</a>.
        </footer>
    </section>
</template>
