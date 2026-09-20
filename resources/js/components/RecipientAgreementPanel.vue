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
const supportCheck = computed(() => clarity.value.checks.find((check) => check.key === 'support'));
const expectationCheck = computed(() => clarity.value.checks.find((check) => check.key === 'commitment'));
const detailChecks = computed(() => clarity.value.checks.filter((check) => !['support', 'commitment'].includes(check.key)));
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <header class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-amber-100 text-amber-800">
                    <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Support agreement</p>
                    <h3 class="mt-1 text-xl font-bold text-slate-950">{{ reviewer ? 'Review the support and recipient responsibilities' : 'What you receive and what is expected' }}</h3>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">
                        {{ reviewer ? 'Confirm that the support and recipient responsibilities are clearly explained before approving the program.' : 'Review the support package and the responsibilities that apply if you are selected.' }}
                    </p>
                </div>
            </div>
            <span :class="['inline-flex w-fit items-center gap-2 rounded-md px-3 py-1.5 text-xs font-bold ring-1 ring-inset', statusClass]">
                <i :class="statusIcon" aria-hidden="true"></i>
                {{ clarity.title }}
            </span>
        </header>

        <div class="bg-slate-50 p-4 sm:p-5">
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <article class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-[13rem_minmax(0,1fr)] sm:gap-5 sm:p-5">
                    <div class="flex items-center gap-3 sm:items-start">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200">
                            <i class="fa-solid fa-gift" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Provider support</p>
                            <p class="mt-1 text-sm font-bold text-slate-950">What you receive</p>
                        </div>
                    </div>
                    <p :class="['self-center text-sm font-semibold leading-6', supportCheck?.complete ? 'text-slate-800' : 'text-amber-900']">
                        {{ supportCheck?.complete ? supportCheck.value : supportCheck?.missing }}
                    </p>
                </article>

                <article class="grid gap-3 p-4 sm:grid-cols-[13rem_minmax(0,1fr)] sm:gap-5 sm:p-5">
                    <div class="flex items-center gap-3 sm:items-start">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200">
                            <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Recipient responsibility</p>
                            <p class="mt-1 text-sm font-bold text-slate-950">What you agree to</p>
                        </div>
                    </div>
                    <div class="self-center">
                        <p :class="['whitespace-pre-line text-sm font-semibold leading-6', expectationCheck?.complete ? 'text-slate-800' : 'text-amber-900']">
                            {{ expectationCheck?.complete ? expectationCheck.value : expectationCheck?.missing }}
                        </p>
                        <span class="mt-2 inline-flex rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                            {{ recipientCommitmentLabels[clarity.agreement.commitment_type] || 'Recipient expectation' }}
                        </span>
                    </div>
                </article>
            </div>
        </div>

        <div class="border-t border-slate-200 px-4 py-5 sm:px-5">
            <div class="mb-3">
                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">After selection</p>
                <h4 class="mt-1 text-base font-bold text-slate-950">How the responsibilities are handled</h4>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-200">
                <div v-for="check in detailChecks" :key="check.key" class="grid gap-2 border-b border-slate-200 p-4 last:border-b-0 sm:grid-cols-[14rem_minmax(0,1fr)] sm:gap-5">
                    <div class="flex items-start gap-2.5">
                        <span :class="['mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-[11px]', check.complete ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700']">
                            <i :class="check.complete ? 'fa-solid fa-check' : 'fa-solid fa-question'" aria-hidden="true"></i>
                        </span>
                        <p class="text-sm font-bold leading-6 text-slate-900">{{ check.label }}</p>
                    </div>
                    <p :class="['whitespace-pre-line text-sm leading-6', check.complete ? 'text-slate-600' : 'font-semibold text-amber-900']">
                        {{ check.complete ? check.value : check.missing }}
                    </p>
                </div>
            </div>
        </div>

        <footer class="flex items-start gap-2.5 border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-600 sm:px-5">
            <i class="fa-solid fa-circle-info mt-1 shrink-0 text-slate-400" aria-hidden="true"></i>
            <p>
            {{ reviewer ? 'Check whether the expectation is clearly disclosed and reasonably connected to the listed support. Return vague or incomplete terms for clarification.' : 'Consider whether the expectation is reasonable for the support offered, and ask the provider about anything unclear before continuing.' }}
            This guide supports transparency but does not make a legal fairness determination or replace independent advice.
            </p>
        </footer>
    </section>
</template>
