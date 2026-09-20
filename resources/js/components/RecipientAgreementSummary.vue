<script setup>
import { computed } from 'vue';

const props = defineProps({
    agreement: {
        type: Object,
        required: true,
    },
    compact: {
        type: Boolean,
        default: false,
    },
    embedded: {
        type: Boolean,
        default: false,
    },
});

const snapshot = computed(() => props.agreement?.snapshot ?? {});
const benefits = computed(() => snapshot.value.benefits ?? []);
const expectation = computed(() => snapshot.value.recipient_expectation ?? {});
const supportPeriod = computed(() => {
    const start = formatDate(snapshot.value.support_starts_at);
    const end = formatDate(snapshot.value.support_ends_at);

    if (start && end) {
        return `${start} to ${end}`;
    }

    return start || end || 'Provider will confirm the dates';
});
const expectationLabel = computed(() => ({
    none: 'No recipient contribution required',
    reporting: 'Submit the listed recipient updates',
    activities: 'Complete the listed program activities',
    return_service: 'Complete the disclosed return-service commitment',
    provider_briefing: 'Provider will explain the commitment before it begins',
    custom: 'Follow the provider commitment shown below',
}[expectation.value.commitment_type] ?? 'Follow the disclosed recipient commitment'));
const statusClass = computed(() => ({
    accepted: 'bg-emerald-100 text-emerald-800',
    declined: 'bg-rose-100 text-rose-800',
    pending: 'bg-amber-100 text-amber-900',
}[props.agreement?.status] ?? 'bg-slate-100 text-slate-700'));
const hasProviderTerms = computed(() => Boolean(
    snapshot.value.renewal_policy
    || snapshot.value.return_service_contract
    || snapshot.value.other_contract_terms,
));

function formatDate(value) {
    if (!value) {
        return '';
    }

    const date = new Date(`${value}T00:00:00`);

    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}

function formatAmount(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));
}
</script>

<template>
    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <header v-if="!embedded" class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300">
                    <i class="fa-solid fa-file-signature" aria-hidden="true"></i>
                </span>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">After selection</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Recipient agreement</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-600">A fixed record of the support and responsibilities shown at selection.</p>
                </div>
            </div>
            <span :class="['inline-flex w-fit rounded-md px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-wide', statusClass]">
                {{ agreement.status_label }}
            </span>
        </header>

        <div :class="compact ? 'grid gap-3 p-4 lg:grid-cols-2' : 'grid gap-4 p-4 sm:p-5 lg:grid-cols-2'">
            <article class="rounded-md border border-slate-200 p-3.5">
                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Support package</p>
                <p class="mt-1 font-bold text-slate-950">{{ snapshot.program_title }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ snapshot.provider_name }}</p>
                <ul v-if="benefits.length" class="mt-3 space-y-2">
                    <li v-for="benefit in benefits" :key="`${benefit.type}-${benefit.title}`" class="flex items-start gap-2 text-sm leading-5 text-slate-700">
                        <i class="fa-solid fa-gift mt-1 text-[10px] text-amber-700" aria-hidden="true"></i>
                        <span>{{ benefit.display_summary || benefit.title }}</span>
                    </li>
                </ul>
                <p v-else-if="snapshot.award_amount" class="mt-3 text-sm font-semibold text-slate-700">
                    Award amount: {{ formatAmount(snapshot.award_amount) }}
                </p>
                <p v-else class="mt-3 text-sm text-slate-500">The provider will confirm the final support package.</p>
            </article>

            <article class="rounded-md border border-slate-200 p-3.5">
                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Support period and commitment</p>
                <p class="mt-1 font-bold text-slate-950">{{ supportPeriod }}</p>
                <p class="mt-3 text-sm font-bold text-slate-900">{{ expectationLabel }}</p>
                <p v-if="expectation.duration" class="mt-1 text-sm leading-5 text-slate-600">{{ expectation.duration }}</p>
                <p v-if="expectation.noncompliance_consequence" class="mt-3 text-xs leading-5 text-slate-600">
                    <strong class="text-slate-800">If not completed:</strong> {{ expectation.noncompliance_consequence }}
                </p>
                <p v-if="expectation.exit_or_exception_process" class="mt-2 text-xs leading-5 text-slate-600">
                    <strong class="text-slate-800">Exceptions or withdrawal:</strong> {{ expectation.exit_or_exception_process }}
                </p>
            </article>

            <article v-if="hasProviderTerms" class="rounded-md border border-slate-200 p-3.5 lg:col-span-2">
                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Provider terms</p>
                <dl class="mt-3 grid gap-3 md:grid-cols-3">
                    <div v-if="snapshot.renewal_policy">
                        <dt class="text-xs font-bold text-slate-900">Renewal</dt>
                        <dd class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-600">{{ snapshot.renewal_policy }}</dd>
                    </div>
                    <div v-if="snapshot.return_service_contract">
                        <dt class="text-xs font-bold text-slate-900">Return service</dt>
                        <dd class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-600">{{ snapshot.return_service_contract }}</dd>
                    </div>
                    <div v-if="snapshot.other_contract_terms">
                        <dt class="text-xs font-bold text-slate-900">Other terms</dt>
                        <dd class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-600">{{ snapshot.other_contract_terms }}</dd>
                    </div>
                </dl>
            </article>
        </div>

        <footer class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-500 sm:px-5">
            {{ agreement.notice }}
            <span v-if="agreement.responded_at" class="font-semibold text-slate-700"> Response recorded {{ agreement.responded_at }}.</span>
        </footer>
    </section>
</template>
