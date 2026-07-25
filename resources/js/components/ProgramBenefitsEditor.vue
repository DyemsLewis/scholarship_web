<script setup>
import { computed, ref } from 'vue';

const benefits = defineModel({ type: Array, default: () => [] });
const pendingType = ref('');
const editingIndex = ref(null);

const typeOptions = [
    { value: 'cash_grant', label: 'Cash grant', icon: 'fa-solid fa-peso-sign', amount: true },
    { value: 'tuition_coverage', label: 'Tuition coverage', icon: 'fa-solid fa-school', amount: true, coverage: true },
    { value: 'allowance', label: 'Allowance', icon: 'fa-solid fa-wallet', amount: true },
    { value: 'school_supplies', label: 'School supplies', icon: 'fa-solid fa-book' },
    { value: 'device_support', label: 'Device support', icon: 'fa-solid fa-laptop' },
    { value: 'transportation', label: 'Transportation support', icon: 'fa-solid fa-bus', amount: true },
    { value: 'accommodation', label: 'Accommodation support', icon: 'fa-solid fa-house', amount: true },
    { value: 'training', label: 'Training or certification', icon: 'fa-solid fa-certificate' },
    { value: 'mentorship', label: 'Mentorship', icon: 'fa-solid fa-people-arrows' },
    { value: 'fee_waiver', label: 'Fee waiver', icon: 'fa-solid fa-receipt', coverage: true },
    { value: 'other', label: 'Other benefit', icon: 'fa-solid fa-gift', amount: true },
];
const coverageOptions = [
    { value: 'full', label: 'Full coverage' },
    { value: 'partial', label: 'Partial coverage' },
    { value: 'fixed', label: 'Fixed-value coverage' },
];
const frequencyOptions = [
    { value: 'one_time', label: 'One-time' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'per_term', label: 'Per term or semester' },
    { value: 'annual', label: 'Annual' },
    { value: 'entire_program', label: 'Entire program' },
];
const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 outline-none transition focus:border-amber-500 focus:ring-3 focus:ring-amber-100';
const availableTypes = computed(() => typeOptions.filter(
    (option) => !benefits.value.some((benefit) => benefit.type === option.value)
));

function typeOption(type) {
    return typeOptions.find((option) => option.value === type) ?? typeOptions.at(-1);
}

function addBenefit() {
    const option = typeOption(pendingType.value);

    if (!pendingType.value || benefits.value.length >= 10) {
        return;
    }

    benefits.value = [...benefits.value, {
        type: option.value,
        title: option.label,
        amount: '',
        coverage: option.coverage ? 'partial' : '',
        frequency: 'one_time',
        description: '',
    }];
    editingIndex.value = benefits.value.length - 1;
    pendingType.value = '';
}

function removeBenefit(index) {
    benefits.value = benefits.value.filter((_, benefitIndex) => benefitIndex !== index);
    editingIndex.value = null;
}

function formatAmount(value) {
    if (value === '' || value === null || value === undefined) {
        return '';
    }

    return `PHP ${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function benefitSummary(benefit) {
    const details = [];
    const coverage = coverageOptions.find((option) => option.value === benefit.coverage)?.label;
    const frequency = frequencyOptions.find((option) => option.value === benefit.frequency)?.label;

    if (coverage) details.push(coverage);
    if (benefit.amount !== '' && benefit.amount !== null) details.push(formatAmount(benefit.amount));
    if (frequency) details.push(frequency);

    return details.length ? details.join(' | ') : 'Details can be added if needed';
}
</script>

<template>
    <section class="md:col-span-2">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-bold text-slate-950">Benefits offered</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">
                    Add cash, tuition, supplies, services, or other support. A monetary amount is optional unless the value is known.
                </p>
            </div>
            <span class="w-fit rounded-md bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">
                {{ benefits.length }} added
            </span>
        </div>

        <div class="mt-4 grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
            <select v-model="pendingType" :class="inputClass" aria-label="Benefit type to add">
                <option value="">Choose a benefit type</option>
                <option v-for="option in availableTypes" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
            <button
                type="button"
                :disabled="!pendingType || benefits.length >= 10"
                class="rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                @click="addBenefit"
            >
                Add benefit
            </button>
        </div>

        <div v-if="benefits.length" class="mt-4 grid gap-3">
            <article v-for="(benefit, index) in benefits" :key="`${benefit.type}-${index}`" class="rounded-md border border-slate-200 bg-slate-50 p-3">
                <div class="flex items-start gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white text-slate-700 ring-1 ring-slate-200">
                        <i :class="typeOption(benefit.type).icon" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-slate-950">{{ benefit.title || typeOption(benefit.type).label }}</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ benefitSummary(benefit) }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100" @click="editingIndex = editingIndex === index ? null : index">
                            {{ editingIndex === index ? 'Done' : 'Edit' }}
                        </button>
                        <button type="button" class="rounded-md border border-rose-200 bg-white px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="removeBenefit(index)">
                            Remove
                        </button>
                    </div>
                </div>

                <div v-if="editingIndex === index" class="mt-3 grid gap-3 border-t border-slate-200 pt-3 md:grid-cols-2">
                    <div :class="typeOption(benefit.type).value === 'other' ? '' : 'md:col-span-2'">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Display name</label>
                        <input v-model="benefit.title" type="text" maxlength="150" :placeholder="typeOption(benefit.type).label" :class="inputClass">
                    </div>
                    <div v-if="typeOption(benefit.type).amount">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Amount or estimated value</label>
                        <input v-model="benefit.amount" type="number" min="0" step="0.01" placeholder="Optional" :class="inputClass">
                    </div>
                    <div v-if="typeOption(benefit.type).coverage">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Coverage</label>
                        <select v-model="benefit.coverage" :class="inputClass">
                            <option v-for="option in coverageOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Frequency</label>
                        <select v-model="benefit.frequency" :class="inputClass">
                            <option v-for="option in frequencyOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-semibold text-slate-700">What is included? <span class="font-normal text-slate-400">Optional</span></label>
                        <textarea v-model="benefit.description" rows="2" maxlength="1000" placeholder="Briefly explain what recipients receive." :class="inputClass"></textarea>
                    </div>
                </div>
            </article>
        </div>

        <div v-else class="mt-4 rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500">
            No benefits added yet. Start with the main support recipients will receive.
        </div>
    </section>
</template>
