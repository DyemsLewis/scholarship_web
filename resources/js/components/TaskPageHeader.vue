<script setup>
import { computed } from 'vue';

const props = defineProps({
    theme: {
        type: String,
        default: 'provider',
        validator: (value) => ['applicant', 'provider', 'admin'].includes(value),
    },
    eyebrow: {
        type: String,
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    icon: {
        type: String,
        default: 'fa-solid fa-list-check',
    },
    actionHref: {
        type: String,
        default: '',
    },
    actionLabel: {
        type: String,
        default: '',
    },
    secondaryHref: {
        type: String,
        default: '',
    },
    secondaryLabel: {
        type: String,
        default: '',
    },
});

const isApplicant = computed(() => props.theme === 'applicant');
const headerClass = computed(() => isApplicant.value ? 'student-hero' : `${props.theme}-hero task-page-header`);
</script>

<template>
    <header :class="headerClass">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <span
                    :class="[
                        'grid h-11 w-11 shrink-0 place-items-center rounded-md shadow-sm',
                        isApplicant ? 'student-hero-icon' : 'bg-slate-950 text-amber-300',
                    ]"
                >
                    <i :class="[icon, 'text-base']" aria-hidden="true"></i>
                </span>

                <div class="min-w-0">
                    <p :class="['text-[11px] font-bold uppercase tracking-[0.18em]', isApplicant ? 'text-amber-200' : 'text-amber-700']">
                        {{ eyebrow }}
                    </p>
                    <h1 :class="['mt-1 font-display text-xl font-bold leading-tight sm:text-2xl', isApplicant ? 'text-white' : 'text-slate-950']">
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        :class="['mt-1.5 max-w-2xl text-sm leading-5 sm:line-clamp-2', isApplicant ? 'text-slate-300' : 'text-slate-600']"
                    >
                        {{ description }}
                    </p>
                    <div
                        v-if="$slots.meta"
                        :class="['mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold', isApplicant ? 'text-slate-300' : 'text-slate-500']"
                    >
                        <slot name="meta"></slot>
                    </div>
                </div>
            </div>

            <div
                v-if="$slots.actions || actionHref || secondaryHref"
                class="grid w-full shrink-0 grid-cols-1 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center lg:justify-end"
            >
                <slot name="actions">
                    <a
                        v-if="secondaryHref && secondaryLabel"
                        :href="secondaryHref"
                        :class="[
                            'rounded-md border px-4 py-2.5 text-center text-sm font-bold transition',
                            isApplicant
                                ? 'border-white/30 bg-white/5 text-white hover:bg-white hover:text-slate-950'
                                : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
                        ]"
                    >
                        {{ secondaryLabel }}
                    </a>
                    <a
                        v-if="actionHref && actionLabel"
                        :href="actionHref"
                        :class="[
                            'rounded-md px-4 py-2.5 text-center text-sm font-bold transition',
                            isApplicant
                                ? 'bg-amber-300 text-slate-950 hover:bg-amber-200'
                                : 'bg-slate-950 text-white hover:bg-slate-800',
                        ]"
                    >
                        {{ actionLabel }}
                    </a>
                </slot>
            </div>
        </div>
    </header>
</template>
