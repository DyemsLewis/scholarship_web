<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    src: { type: String, required: true },
    alt: { type: String, default: 'File preview' },
});

const minimumZoom = 0.5;
const maximumZoom = 3;
const zoomStep = 0.25;
const zoom = ref(1);
const zoomPercent = computed(() => `${Math.round(zoom.value * 100)}%`);
const imageStyle = computed(() => ({
    width: `${zoom.value * 100}%`,
    height: `${zoom.value * 100}%`,
}));

watch(() => props.src, () => {
    zoom.value = 1;
});

function zoomIn() {
    zoom.value = Math.min(maximumZoom, zoom.value + zoomStep);
}

function zoomOut() {
    zoom.value = Math.max(minimumZoom, zoom.value - zoomStep);
}

function resetZoom() {
    zoom.value = 1;
}
</script>

<template>
    <section class="relative overflow-hidden rounded-md border border-slate-200 bg-white">
        <div class="absolute right-3 top-3 z-10 inline-flex items-center overflow-hidden rounded-md border border-slate-300 bg-white shadow-md">
            <button
                type="button"
                :disabled="zoom <= minimumZoom"
                class="grid h-9 w-9 place-items-center text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300"
                aria-label="Zoom out"
                @click="zoomOut"
            >
                <i class="fa-solid fa-minus" aria-hidden="true"></i>
            </button>
            <button
                type="button"
                class="h-9 min-w-16 border-x border-slate-300 px-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                title="Reset zoom"
                aria-label="Reset image zoom"
                @click="resetZoom"
            >
                {{ zoomPercent }}
            </button>
            <button
                type="button"
                :disabled="zoom >= maximumZoom"
                class="grid h-9 w-9 place-items-center text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300"
                aria-label="Zoom in"
                @click="zoomIn"
            >
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
            </button>
        </div>

        <div
            :class="[
                'flex h-full w-full overflow-auto p-3',
                zoom > 1 ? 'items-start justify-start' : 'items-center justify-center',
            ]"
        >
            <img
                :src="src"
                :alt="alt"
                :style="imageStyle"
                class="shrink-0 object-contain"
            >
        </div>
    </section>
</template>
