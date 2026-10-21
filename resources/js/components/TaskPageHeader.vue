<script setup>
import { computed } from 'vue';
import ProviderPageHeader from './ProviderPageHeader.vue';

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
    roleKey: {
        type: String,
        default: '',
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
    compact: {
        type: Boolean,
        default: false,
    },
});

const isApplicant = computed(() => props.theme === 'applicant');
const isProvider = computed(() => props.theme === 'provider');
const headerClass = computed(() => isApplicant.value ? 'student-hero' : `${props.theme}-hero task-page-header`);
</script>

<template>
    <ProviderPageHeader
        v-if="isProvider"
        :title="title"
        :description="description"
        :eyebrow="eyebrow"
        :icon="icon"
        :role-key="roleKey"
    >
        <template v-if="$slots.meta" #meta>
            <slot name="meta"></slot>
        </template>
        <template v-if="$slots.actions || actionHref || secondaryHref" #actions>
            <slot name="actions">
                <a
                    v-if="secondaryHref && secondaryLabel"
                    :href="secondaryHref"
                    class="rounded border border-slate-300 bg-white px-4 py-2.5 text-center text-sm font-bold text-slate-700 transition hover:bg-slate-50"
                >
                    {{ secondaryLabel }}
                </a>
                <a
                    v-if="actionHref && actionLabel"
                    :href="actionHref"
                    class="rounded bg-slate-950 px-4 py-2.5 text-center text-sm font-bold text-white transition hover:bg-slate-800"
                >
                    {{ actionLabel }}
                </a>
            </slot>
        </template>
    </ProviderPageHeader>

    <header v-else :class="[headerClass, compact ? '!min-h-0 !px-4 !py-3' : '']">
        <div :class="['flex flex-col lg:flex-row lg:items-center lg:justify-between', compact ? 'gap-3' : 'gap-4']">
            <div :class="['flex min-w-0 gap-3', isApplicant ? 'items-center' : 'items-start']">
                <span
                    :class="[
                        'inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md shadow-sm',
                        isApplicant ? 'student-hero-icon' : 'bg-slate-950 text-amber-300',
                    ]"
                >
                    <i :class="[icon, 'block w-4 text-center text-base leading-none']" aria-hidden="true"></i>
                </span>

                <div class="min-w-0">
                    <p :class="['text-[11px] font-bold uppercase tracking-[0.18em]', isApplicant ? 'text-amber-200' : 'text-amber-700']">
                        {{ eyebrow }}
                    </p>
                    <h1 :class="['mt-1 font-display font-bold leading-tight', compact ? 'text-lg sm:text-xl' : 'text-xl sm:text-2xl', isApplicant ? 'text-white' : 'text-slate-950']">
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        :class="['max-w-2xl leading-5 sm:line-clamp-2', compact ? 'mt-1 text-xs sm:text-sm' : 'mt-1.5 text-sm', isApplicant ? 'text-slate-300' : 'text-slate-600']"
                    >
                        {{ description }}
                    </p>
                    <div v-if="$slots.meta" :class="['mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold', isApplicant ? 'text-slate-300' : 'text-slate-500']">
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
