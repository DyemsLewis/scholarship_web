<script setup>
defineProps({
    backUrl: { type: String, required: true },
    status: { type: String, default: 'Preview' },
    width: { type: String, default: 'desktop' },
    scenario: { type: String, default: 'general' },
    showScenario: { type: Boolean, default: false },
});

const emit = defineEmits(['update:width', 'update:scenario']);
</script>

<template>
    <header class="border-b border-slate-300 bg-white shadow-sm" data-provider-applicant-preview-toolbar>
        <div class="mx-auto flex max-w-[94rem] flex-wrap items-center justify-between gap-3 px-5 py-3">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid h-9 w-9 shrink-0 place-items-center bg-slate-950 text-amber-300">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-bold text-slate-950">Applicant preview</p>
                        <span class="border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-600">{{ status }}</span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">Actions are disabled and this view is not public.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <label v-if="showScenario" class="flex h-9 items-center gap-2 border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700">
                    Applicant state
                    <select :value="scenario" class="bg-transparent text-xs font-bold text-slate-950 outline-none" @change="emit('update:scenario', $event.target.value)">
                        <option value="general">General</option>
                        <option value="eligible">Eligible</option>
                        <option value="ineligible">Not eligible</option>
                    </select>
                </label>

                <div class="flex h-9 border border-slate-300 bg-white p-1" aria-label="Preview width">
                    <button type="button" :class="['grid w-8 place-items-center text-xs', width === 'desktop' ? 'bg-slate-950 text-white' : 'text-slate-500']" title="Desktop preview" @click="emit('update:width', 'desktop')">
                        <i class="fa-solid fa-desktop" aria-hidden="true"></i>
                    </button>
                    <button type="button" :class="['grid w-8 place-items-center text-xs', width === 'mobile' ? 'bg-slate-950 text-white' : 'text-slate-500']" title="Mobile preview" @click="emit('update:width', 'mobile')">
                        <i class="fa-solid fa-mobile-screen" aria-hidden="true"></i>
                    </button>
                </div>

                <a :href="backUrl" class="inline-flex h-9 items-center gap-2 bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">
                    <i class="fa-solid fa-arrow-left text-[10px]" aria-hidden="true"></i>
                    Back to editor
                </a>
            </div>
        </div>
    </header>
</template>
