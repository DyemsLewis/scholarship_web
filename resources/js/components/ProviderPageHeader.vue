<script setup>
import { computed } from 'vue';
import { getProviderRoleGuidance } from '../support/providerRoleGuidance';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    eyebrow: {
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
    showRoleGuide: {
        type: Boolean,
        default: true,
    },
});

const role = computed(() => getProviderRoleGuidance(props.roleKey));
const workspaceLabel = computed(() => role.value ? `${role.value.label} workspace` : props.eyebrow);
</script>

<template>
    <header class="overflow-hidden rounded-sm border border-slate-300 border-t-2 border-t-slate-950 bg-white shadow-[0_2px_8px_rgba(8,20,38,0.035)]">
        <div class="flex flex-col gap-4 px-4 py-4 sm:px-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 items-center gap-3.5">
                <slot name="leading">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-sm bg-slate-950 text-white">
                        <i :class="[icon, 'text-sm']" aria-hidden="true"></i>
                    </span>
                </slot>

                <div class="min-w-0">
                    <p v-if="workspaceLabel" class="mb-1 text-[0.65rem] font-bold uppercase tracking-[0.14em] text-slate-500">{{ workspaceLabel }}</p>
                    <h1 class="font-sans text-xl font-bold leading-tight tracking-[-0.015em] text-slate-950 sm:text-2xl">{{ title }}</h1>
                    <p v-if="description" class="mt-1 max-w-3xl text-sm leading-5 text-slate-600 sm:line-clamp-2">{{ description }}</p>
                </div>
            </div>

            <div v-if="$slots.actions" class="grid w-full shrink-0 grid-cols-1 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center lg:justify-end">
                <slot name="actions"></slot>
            </div>
        </div>

        <div v-if="$slots.meta" class="flex flex-wrap items-center gap-x-5 gap-y-1.5 border-t border-slate-200 bg-slate-50 px-4 py-2.5 text-xs font-semibold text-slate-600 sm:px-5">
            <slot name="meta"></slot>
        </div>

        <details v-if="role && showRoleGuide" class="group border-t border-slate-200 bg-white">
            <summary class="flex min-h-10 cursor-pointer list-none items-center justify-between gap-4 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 sm:px-5">
                <span class="flex min-w-0 items-center gap-2.5">
                    <i class="fa-regular fa-circle-question text-slate-400" aria-hidden="true"></i>
                    <span>What this role owns</span>
                    <span class="hidden truncate font-normal text-slate-500 md:inline">— {{ role.purpose }}</span>
                </span>
                <i class="fa-solid fa-chevron-down text-[0.65rem] text-slate-400 transition group-open:rotate-180" aria-hidden="true"></i>
            </summary>
            <div class="grid gap-5 border-t border-slate-200 bg-slate-50 px-4 py-4 sm:px-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(15rem,.8fr)]">
                <div>
                    <h2 class="text-sm font-bold text-slate-950">Role purpose</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ role.purpose }}</p>
                    <ul class="mt-3 grid gap-2 sm:grid-cols-3">
                        <li v-for="item in role.responsibilities" :key="item" class="flex gap-2 text-xs leading-5 text-slate-600">
                            <i class="fa-solid fa-check mt-1 text-[0.6rem] text-emerald-600" aria-hidden="true"></i>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </div>
                <div class="border-t border-slate-200 pt-4 lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0">
                    <h2 class="text-sm font-bold text-slate-950">Boundary and handoff</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-600">{{ role.boundaries }}</p>
                    <p class="mt-2 text-xs leading-5 text-slate-600"><span class="font-bold text-slate-800">Next:</span> {{ role.handoff }}</p>
                </div>
            </div>
        </details>
    </header>
</template>
