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
</script>

<template>
    <div :class="compact ? 'divide-y divide-emerald-100 overflow-hidden rounded-md border border-emerald-200 bg-white' : 'grid gap-3 sm:grid-cols-2'">
        <article
            v-for="benefit in displayedBenefits"
            :key="`${benefit.type}-${benefit.title}`"
            :class="compact ? 'px-3 py-2.5' : 'rounded-lg border border-slate-200 bg-slate-50 p-4'"
        >
            <div class="flex items-start gap-3">
                <span :class="compact ? 'grid h-7 w-7 shrink-0 place-items-center rounded-md bg-emerald-50 text-xs text-emerald-700' : 'grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white text-amber-700 ring-1 ring-slate-200'">
                    <i class="fa-solid fa-gift" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-950">{{ benefit.title }}</p>
                    <div :class="['flex flex-wrap gap-1.5 text-[11px] font-bold text-slate-600', compact ? 'mt-1' : 'mt-2']">
                        <span v-if="benefit.coverage_label" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">
                            {{ benefit.coverage_label }}
                        </span>
                        <span v-if="benefit.amount !== null && benefit.amount !== undefined && benefit.amount !== ''" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">
                            {{ formatAmount(benefit.amount) }}
                        </span>
                        <span v-if="benefit.frequency_label" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200">
                            {{ benefit.frequency_label }}
                        </span>
                    </div>
                    <p v-if="benefit.description" :class="compact ? 'mt-1 text-xs leading-5 text-slate-500' : 'mt-3 text-sm leading-6 text-slate-600'">
                        {{ benefit.description }}
                    </p>
                </div>
            </div>
        </article>
    </div>
</template>
