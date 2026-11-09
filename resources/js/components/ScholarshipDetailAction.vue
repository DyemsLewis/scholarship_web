<script setup>
import { computed } from 'vue';
import { preparationAction } from '../support/scholarshipPreparation';
import { withPreparationContext } from '../support/scholarshipPreparationContext';

const props = defineProps({
    decision: { type: Object, required: true },
    preparation: { type: Object, default: () => ({}) },
    scholarshipId: { type: [String, Number], default: '' },
    section: { type: String, default: 'summary' },
    preview: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    saved: { type: Boolean, default: false },
});
defineEmits(['save']);

const standalone = computed(() => props.section !== 'application');
const action = computed(() => preparationAction(props.decision.action, {
    preview: props.preview,
    canStart: props.decision.state === 'eligible' && props.preparation.can_start_application === true,
}));
const tone = computed(() => ({
    positive: 'border-emerald-500 bg-emerald-50/70 text-emerald-800',
    attention: 'border-amber-400 bg-amber-50 text-amber-900',
    blocked: 'border-rose-500 bg-rose-50 text-rose-800',
}[props.decision.tone] ?? 'border-slate-300 bg-slate-50 text-slate-700'));
</script>

<template>
    <component
        :is="standalone ? 'section' : 'div'"
        :aria-label="standalone ? 'Your next step' : undefined"
        :class="['min-w-0 border-l-[3px]', standalone ? 'student-card grid gap-3 rounded-md px-4 py-3 sm:px-6 md:grid-cols-[minmax(0,1fr)_auto] md:items-center md:gap-6' : 'rounded-r-sm px-3.5 py-3 sm:px-4', tone]"
        data-scholarship-next-action
    >
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <p v-if="standalone" class="text-[10px] font-bold uppercase tracking-[0.14em]">Your next step</p>
                <p v-if="decision.eyebrow" :class="standalone ? 'border-l border-current/25 pl-2.5 text-xs font-semibold' : 'text-[10px] font-bold uppercase tracking-[0.14em]'">{{ decision.eyebrow }}</p>
            </div>
            <component :is="standalone ? 'h2' : 'h3'" class="mt-1 text-base font-bold leading-snug text-slate-950">{{ decision.title }}</component>
            <p class="mt-1 max-w-3xl text-sm leading-5 text-slate-700">{{ decision.description }}</p>
        </div>

        <div v-if="!preview" :class="['flex flex-wrap items-center gap-2', standalone ? 'md:max-w-xs md:justify-end' : 'mt-3']">
            <a v-if="action?.kind === 'link'" :href="withPreparationContext(action.href, scholarshipId, section)" class="student-button student-button-primary" data-scholarship-primary-action>
                {{ action.label }}<i class="fa-solid fa-arrow-right shrink-0 text-xs" aria-hidden="true"></i>
            </a>
            <button v-else-if="action?.kind === 'save'" type="button" class="student-button student-button-primary" data-scholarship-primary-action :disabled="saving" @click="$emit('save')">
                {{ saving ? 'Saving...' : action.label }}<i class="fa-regular fa-bookmark" aria-hidden="true"></i>
            </button>
            <button v-if="action?.kind !== 'save'" type="button" class="student-button student-button-secondary" :disabled="saving" :aria-pressed="saved" aria-label="Save scholarship" @click="$emit('save')">
                <i :class="saved ? 'fa-solid fa-bookmark text-amber-600' : 'fa-regular fa-bookmark'" aria-hidden="true"></i>
                {{ saving ? 'Saving...' : (saved ? 'Saved' : 'Save') }}
            </button>
        </div>
        <p v-else-if="decision.action?.label" :class="['text-xs font-semibold text-slate-600', !standalone && 'mt-2']">Applicant action: {{ decision.action.label }}</p>
    </component>
</template>
