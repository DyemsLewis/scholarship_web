<script setup>
import { computed } from 'vue';

const props = defineProps({
    benefits: {
        type: Array,
        default: () => [],
    },
    compact: {
        type: Boolean,
        default: false,
    },
    uniform: {
        type: Boolean,
        default: false,
    },
    dense: {
        type: Boolean,
        default: false,
    },
    cashAmount: {
        type: [Number, String],
        default: null,
    },
});

const displayedBenefits = computed(() => {
    const benefits = props.benefits.map((benefit) => ({ ...benefit }));
    const hasCashOverride = props.cashAmount !== null
        && props.cashAmount !== undefined
        && props.cashAmount !== '';

    if (!hasCashOverride) {
        return benefits;
    }

    const cashGrant = benefits.find((benefit) => benefit.type === 'cash_grant');

    if (cashGrant) {
        cashGrant.amount = props.cashAmount;
        return benefits;
    }

    return [
        {
            type: 'cash_grant',
            title: 'Final cash award',
            amount: props.cashAmount,
            coverage_label: null,
            frequency_label: null,
            description: null,
        },
        ...benefits,
    ];
});

function formatAmount(value) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
    }).format(Number(value));
}

function benefitIcon(type) {
    return {
        cash_grant: 'fa-solid fa-peso-sign',
        tuition_coverage: 'fa-solid fa-school',
        allowance: 'fa-solid fa-wallet',
        school_supplies: 'fa-solid fa-book-open',
        device_support: 'fa-solid fa-laptop',
        transportation: 'fa-solid fa-bus',
        accommodation: 'fa-solid fa-house',
        training: 'fa-solid fa-certificate',
        mentorship: 'fa-solid fa-people-arrows',
        fee_waiver: 'fa-solid fa-receipt',
    }[type] ?? 'fa-solid fa-gift';
}
</script>

<template>
    <div :class="compact ? 'divide-y divide-emerald-100 overflow-hidden rounded-md border border-emerald-200 bg-white' : ['grid items-stretch sm:grid-cols-2', dense ? 'gap-2' : 'gap-3', uniform ? 'auto-rows-fr' : '']">
        <article
            v-for="benefit in displayedBenefits"
            :key="`${benefit.type}-${benefit.title}`"
            :class="compact ? 'px-3 py-2.5' : ['relative h-full overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm', dense ? 'p-3' : 'p-4']"
        >
            <span v-if="!compact" class="absolute inset-x-0 top-0 h-1 bg-amber-300"></span>
            <div class="flex items-start gap-3">
                <span :class="compact ? 'grid h-7 w-7 shrink-0 place-items-center rounded-md bg-emerald-50 text-xs text-emerald-700' : ['grid shrink-0 place-items-center rounded-md bg-slate-950 text-amber-300', dense ? 'h-8 w-8 text-xs' : 'h-10 w-10 text-sm']">
                    <i :class="benefitIcon(benefit.type)" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-950">{{ benefit.title }}</p>
                    <p
                        v-if="benefit.amount !== null && benefit.amount !== undefined && benefit.amount !== ''"
                        :class="compact ? 'mt-1 text-xs font-bold text-emerald-700' : ['mt-1 font-bold text-amber-800', dense ? 'text-sm' : 'text-base']"
                    >
                        {{ formatAmount(benefit.amount) }}
                    </p>
                    <div :class="['flex flex-wrap gap-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-600', compact ? 'mt-1' : 'mt-2']">
                        <span v-if="benefit.coverage_label" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">
                            {{ benefit.coverage_label }}
                        </span>
                        <span v-if="benefit.frequency_label" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">
                            {{ benefit.frequency_label }}
                        </span>
                    </div>
                    <p v-if="benefit.description" :class="compact ? 'mt-1 text-xs leading-5 text-slate-500' : (dense ? 'mt-2 text-xs leading-5 text-slate-600' : 'mt-3 text-sm leading-6 text-slate-600')">
                        {{ benefit.description }}
                    </p>
                </div>
            </div>
        </article>
    </div>
</template>
