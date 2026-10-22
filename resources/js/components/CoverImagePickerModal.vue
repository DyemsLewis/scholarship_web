<script setup>
import { ref } from 'vue';

defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Choose a background' },
    description: { type: String, default: 'Choose a system image or upload your own.' },
    images: { type: Array, default: () => [] },
    selectedSource: { type: String, default: 'default' },
    selectedKey: { type: String, default: '' },
    providerCoverUrl: { type: String, default: '' },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'select-system', 'select-provider', 'upload', 'reset']);
const uploadInput = ref(null);

function chooseUpload(event) {
    const file = event.target.files?.[0];
    if (file) emit('upload', file);
    event.target.value = '';
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[180] flex items-center justify-center bg-slate-950/65 p-4" role="presentation" @click.self="emit('close')">
            <section class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden border border-slate-300 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="cover-picker-title">
                <header class="flex items-start justify-between gap-6 border-b border-slate-200 px-6 py-5">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">Public image</p>
                        <h2 id="cover-picker-title" class="mt-1 text-xl font-bold text-slate-950">{{ title }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ description }}</p>
                    </div>
                    <button type="button" class="grid h-10 w-10 shrink-0 place-items-center border border-slate-300 text-slate-500 hover:bg-slate-50 hover:text-slate-950" aria-label="Close background picker" @click="emit('close')">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div v-if="providerCoverUrl" class="mb-5 border-b border-slate-200 pb-5">
                        <button
                            type="button"
                            :disabled="busy"
                            :class="['grid w-full grid-cols-[12rem_minmax(0,1fr)_auto] items-center gap-4 border p-3 text-left', selectedSource === 'provider' ? 'border-slate-950 bg-amber-50' : 'border-slate-200 bg-white hover:border-slate-400']"
                            @click="emit('select-provider')"
                        >
                            <img :src="providerCoverUrl" alt="Organization background preview" class="h-20 w-full object-cover">
                            <span><strong class="block text-sm text-slate-950">Use organization background</strong><span class="mt-1 block text-xs leading-5 text-slate-500">A separate copy will be saved for this scholarship.</span></span>
                            <i v-if="selectedSource === 'provider'" class="fa-solid fa-circle-check text-emerald-700" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="flex items-end justify-between gap-5">
                        <div>
                            <h3 class="text-sm font-bold text-slate-950">System images</h3>
                            <p class="mt-1 text-xs text-slate-500">Prepared for wide cards and detail-page backgrounds.</p>
                        </div>
                        <button type="button" :disabled="busy" class="text-xs font-bold text-slate-600 hover:text-slate-950 hover:underline" @click="emit('reset')">Use recommended</button>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <button
                            v-for="image in images"
                            :key="image.key"
                            type="button"
                            :disabled="busy"
                            :class="['overflow-hidden border text-left transition', selectedSource === 'system' && selectedKey === image.key ? 'border-slate-950 ring-2 ring-amber-300' : 'border-slate-200 hover:border-slate-500']"
                            @click="emit('select-system', image)"
                        >
                            <span class="relative block aspect-[16/9] overflow-hidden bg-slate-100">
                                <img :src="image.url" :alt="image.description" class="h-full w-full object-cover">
                                <span v-if="selectedSource === 'system' && selectedKey === image.key" class="absolute right-2 top-2 grid h-7 w-7 place-items-center bg-slate-950 text-xs text-amber-300"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                            </span>
                            <span class="block px-3 py-2.5"><strong class="block text-xs text-slate-950">{{ image.label }}</strong><span class="mt-1 line-clamp-2 block text-[11px] leading-4 text-slate-500">{{ image.description }}</span></span>
                        </button>
                    </div>

                    <div class="mt-6 flex items-center justify-between gap-5 border-t border-slate-200 pt-5">
                        <div>
                            <p class="text-sm font-bold text-slate-950">Upload your own background</p>
                            <p class="mt-1 text-xs text-slate-500">JPG, PNG, or WebP. At least 800 x 400 pixels, up to 6 MB.</p>
                        </div>
                        <input ref="uploadInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="chooseUpload">
                        <button type="button" :disabled="busy" class="inline-flex h-10 shrink-0 items-center gap-2 bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800 disabled:opacity-50" @click="uploadInput?.click()">
                            <i class="fa-solid fa-arrow-up-from-bracket text-xs text-amber-300" aria-hidden="true"></i>
                            Upload image
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </Teleport>
</template>
