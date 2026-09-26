<script setup>
import { computed, ref } from 'vue';

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
const isNoCommitment = computed(() => expectation.value.commitment_type === 'none');
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
    service: 'Complete the disclosed service commitment',
    return_service: 'Complete the disclosed service commitment',
    renewal: 'Meet the disclosed renewal requirements',
    provider_briefing: 'Final responsibilities were not fully disclosed',
    custom: 'Follow the provider commitment shown below',
}[expectation.value.commitment_type] ?? 'Follow the disclosed recipient commitment'));
const responsibilities = computed(() => {
    if (isNoCommitment.value) {
        return 'You are not required to perform a service, activity, or report in exchange for the listed support.';
    }

    return expectation.value.responsibilities
        || snapshot.value.return_service_contract
        || snapshot.value.other_contract_terms
        || snapshot.value.renewal_policy
        || 'Not specified in this recorded agreement.';
});
const agreementDetails = computed(() => [
    ...(!isNoCommitment.value ? [{
        label: 'Proof you must submit',
        value: expectation.value.required_evidence,
    }] : []),
    {
        label: 'Conditions for receiving benefits',
        value: expectation.value.release_conditions,
    },
    ...(!isNoCommitment.value ? [
        { label: 'Timeframe', value: expectation.value.duration },
        { label: 'If a responsibility is not completed', value: expectation.value.noncompliance_consequence },
        { label: 'Exception, adjustment, or withdrawal', value: expectation.value.exit_or_exception_process },
    ] : []),
]);
const providerContact = computed(() => {
    const contact = snapshot.value.provider_contact ?? {};

    return [
        contact.name,
        contact.department,
        contact.email,
        contact.number,
    ].filter((value) => String(value ?? '').trim() !== '');
});
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
const activeEmbeddedSection = ref('support');
const embeddedSections = computed(() => [
    { key: 'support', label: 'Your support', icon: 'fa-solid fa-gift' },
    { key: 'responsibilities', label: 'Your responsibilities', icon: 'fa-solid fa-list-check' },
    ...(hasProviderTerms.value ? [{ key: 'terms', label: 'Provider terms', icon: 'fa-solid fa-file-contract' }] : []),
    { key: 'contact', label: 'Questions', icon: 'fa-solid fa-circle-question' },
]);

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
    <section :class="['overflow-hidden border border-slate-200 bg-white', embedded ? 'rounded-md' : 'rounded-lg shadow-sm']">
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

        <nav v-if="embedded" class="grid grid-cols-2 gap-px border-b border-slate-200 bg-slate-200 sm:flex" aria-label="Agreement sections">
            <button
                v-for="section in embeddedSections"
                :key="section.key"
                type="button"
                :class="['inline-flex min-w-0 flex-1 items-center justify-center gap-2 bg-white px-3 py-3 text-xs font-bold transition', activeEmbeddedSection === section.key ? 'text-slate-950 shadow-[inset_0_-3px_0_#fbbf24]' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800']"
                @click="activeEmbeddedSection = section.key"
            >
                <i :class="[section.icon, activeEmbeddedSection === section.key ? 'text-amber-600' : 'text-slate-400']" aria-hidden="true"></i>
                <span class="truncate">{{ section.label }}</span>
            </button>
        </nav>

        <div :class="embedded ? '' : (compact ? 'grid gap-3 p-4 lg:grid-cols-2' : 'grid gap-4 p-4 sm:p-5 lg:grid-cols-2')">
            <article v-show="!embedded || activeEmbeddedSection === 'support'" :class="embedded ? 'p-4 sm:p-5' : 'rounded-md border border-slate-200 p-3.5'">
                <div class="flex items-start gap-3">
                    <span v-if="embedded" class="grid h-9 w-9 shrink-0 place-items-center rounded bg-slate-950 text-sm text-amber-300"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">What you will receive</p>
                        <p class="mt-1 font-bold text-slate-950">{{ snapshot.program_title }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ snapshot.provider_name }}</p>
                    </div>
                </div>
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

            <article v-show="!embedded || activeEmbeddedSection === 'responsibilities'" :class="embedded ? 'p-4 sm:p-5' : 'rounded-md border border-slate-200 p-3.5'">
                <div class="flex items-start gap-3">
                    <span v-if="embedded" class="grid h-9 w-9 shrink-0 place-items-center rounded bg-slate-950 text-sm text-amber-300"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">What you agree to</p>
                        <p class="mt-1 font-bold text-slate-950">{{ expectationLabel }}</p>
                        <p class="mt-1 text-xs text-slate-500">Support period: {{ supportPeriod }}</p>
                    </div>
                </div>
                <div class="mt-3 rounded border border-slate-200 bg-slate-50 px-3 py-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">Your exact responsibilities</p>
                    <p class="mt-1 whitespace-pre-line text-sm font-semibold leading-6 text-slate-800">{{ responsibilities }}</p>
                </div>
                <dl class="mt-3 overflow-hidden rounded border border-slate-200">
                    <div v-for="(detail, index) in agreementDetails" :key="detail.label" :class="['px-3 py-2.5', index ? 'border-t border-slate-200' : '']">
                        <dt class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-500">{{ detail.label }}</dt>
                        <dd :class="['mt-1 whitespace-pre-line text-sm leading-5', detail.value ? 'text-slate-700' : 'font-semibold text-amber-800']">
                            {{ detail.value || 'Not specified in this recorded agreement.' }}
                        </dd>
                    </div>
                </dl>
            </article>

            <article v-if="hasProviderTerms" v-show="!embedded || activeEmbeddedSection === 'terms'" :class="embedded ? 'p-4 sm:p-5' : 'rounded-md border border-slate-200 p-3.5 lg:col-span-2'">
                <div class="flex items-start gap-3">
                    <span v-if="embedded" class="grid h-9 w-9 shrink-0 place-items-center rounded bg-slate-950 text-sm text-amber-300"><i class="fa-solid fa-file-contract" aria-hidden="true"></i></span>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Additional provider terms</p>
                        <p v-if="embedded" class="mt-1 text-sm text-slate-600">Review renewal and other conditions recorded by the provider.</p>
                    </div>
                </div>
                <dl :class="['mt-3 grid gap-3', embedded ? 'sm:grid-cols-3' : 'md:grid-cols-3']">
                    <div v-if="snapshot.renewal_policy" :class="embedded ? 'rounded border border-slate-200 bg-slate-50 p-3' : ''">
                        <dt class="text-xs font-bold text-slate-900">Renewal</dt>
                        <dd class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-600">{{ snapshot.renewal_policy }}</dd>
                    </div>
                    <div v-if="snapshot.return_service_contract" :class="embedded ? 'rounded border border-slate-200 bg-slate-50 p-3' : ''">
                        <dt class="text-xs font-bold text-slate-900">Return service</dt>
                        <dd class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-600">{{ snapshot.return_service_contract }}</dd>
                    </div>
                    <div v-if="snapshot.other_contract_terms" :class="embedded ? 'rounded border border-slate-200 bg-slate-50 p-3' : ''">
                        <dt class="text-xs font-bold text-slate-900">Other terms</dt>
                        <dd class="mt-1 whitespace-pre-line text-xs leading-5 text-slate-600">{{ snapshot.other_contract_terms }}</dd>
                    </div>
                </dl>
            </article>

            <article v-show="!embedded || activeEmbeddedSection === 'contact'" :class="embedded ? 'p-4 sm:p-5' : 'rounded-md border border-slate-200 p-3.5 lg:col-span-2'">
                <div class="flex items-start gap-3">
                    <span v-if="embedded" class="grid h-9 w-9 shrink-0 place-items-center rounded bg-slate-950 text-sm text-amber-300"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Questions or requested adjustments</p>
                        <p class="mt-1 text-sm font-bold text-slate-950">Contact the provider before accepting if any term is unclear.</p>
                        <p v-if="providerContact.length" class="mt-2 break-words text-sm leading-6 text-slate-600">{{ providerContact.join(' · ') }}</p>
                        <p v-else class="mt-2 text-sm font-semibold text-amber-800">No provider contact was captured in this recorded agreement.</p>
                    </div>
                </div>
            </article>
        </div>

        <footer class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-500 sm:px-5">
            {{ agreement.notice }}
            <span v-if="agreement.responded_at" class="font-semibold text-slate-700"> Response recorded {{ agreement.responded_at }}.</span>
        </footer>
    </section>
</template>
