<script setup>
defineProps({
    modelValue: { type: String, default: 'required' },
    ruleKey: { type: String, required: true },
    label: { type: String, required: true },
});
const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <label class="inline-flex shrink-0 items-center">
        <span class="sr-only">{{ label }} rule treatment</span>
        <select :id="`eligibility-mode-${ruleKey}`" :data-eligibility-mode="ruleKey" :value="modelValue" aria-describedby="eligibility-mode-guide" class="h-8 min-w-28 border border-slate-300 bg-white px-2 text-xs font-bold text-slate-700 outline-none focus:border-slate-950 focus:ring-1 focus:ring-slate-950 disabled:cursor-not-allowed disabled:bg-slate-50" @change="emit('update:modelValue', $event.target.value)">
            <option value="required">Required</option>
            <option value="preferred">Preferred</option>
        </select>
    </label>
</template>
