<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

const props = defineProps({
    items: { type: Array, required: true },
    label: { type: String, required: true },
    layout: { type: String, default: 'scroll', validator: (value) => ['scroll', 'grid'].includes(value) },
});

const viewport = ref(null);
const viewportId = useId();
const hintId = `${viewportId}-hint`;
const overflowing = ref(false);
const canScrollBack = ref(false);
const canScrollForward = ref(false);
const isGrid = computed(() => props.layout === 'grid');
let observer;

function measure() {
    const element = viewport.value;
    if (!element || isGrid.value) {
        overflowing.value = canScrollBack.value = canScrollForward.value = false;
        return;
    }
    const maximum = Math.max(0, element.scrollWidth - element.clientWidth);
    overflowing.value = maximum > 1;
    canScrollBack.value = element.scrollLeft > 1;
    canScrollForward.value = element.scrollLeft < maximum - 1;
}

function revealActive() {
    const element = viewport.value;
    const active = element?.querySelector('[aria-current="page"]');
    if (element && active && !isGrid.value) {
        const bounds = element.getBoundingClientRect();
        const target = active.getBoundingClientRect();
        const padding = getComputedStyle(element);
        const left = bounds.left + parseFloat(padding.paddingLeft);
        const right = bounds.right - parseFloat(padding.paddingRight);
        if (target.left < left) element.scrollLeft -= left - target.left;
        else if (target.right > right) element.scrollLeft += target.right - right;
    }
    measure();
}

function scrollSections(direction) {
    const element = viewport.value;
    if (!element) return;
    element.scrollBy({
        left: direction * Math.max(160, element.clientWidth * 0.8),
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
    });
}

onMounted(async () => {
    await nextTick();
    if (!viewport.value) return;
    revealActive();
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(revealActive);
        observer.observe(viewport.value);
    }
    window.addEventListener('resize', revealActive);
});

watch(() => [props.layout, ...props.items.map((item) => `${item.href}:${item.active}`)], async () => {
    await nextTick();
    revealActive();
});

onBeforeUnmount(() => {
    observer?.disconnect();
    window.removeEventListener('resize', revealActive);
});
</script>

<template>
    <nav class="mt-4 overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm" :aria-label="label" data-applicant-record-tabs>
        <div :id="viewportId" ref="viewport" :class="['p-1', isGrid ? '' : 'overflow-x-auto']" :aria-describedby="overflowing ? hintId : undefined" data-record-tab-viewport @scroll.passive="measure">
            <div :class="['grid gap-1', isGrid ? 'grid-cols-2 sm:grid-cols-4' : 'min-w-[42rem] grid-cols-5']">
                <a v-for="item in items" :key="item.key" :href="item.href" :aria-current="item.active ? 'page' : undefined"
                    :class="['flex min-h-11 min-w-0 items-center justify-center gap-2 rounded-sm border-b-2 px-3 py-2 text-center text-sm font-bold transition focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-amber-500',
                        item.active ? 'border-amber-400 bg-slate-950 text-white' : 'border-transparent text-slate-600 hover:bg-slate-100 hover:text-slate-950']">
                    <i :class="[item.icon, 'block w-4 shrink-0 text-center text-xs leading-none']" aria-hidden="true"></i>
                    <span :class="isGrid ? 'min-w-0 break-words' : ''">{{ item.label }}</span>
                    <span v-if="item.count || item.badge" :class="['grid h-5 min-w-5 shrink-0 place-items-center rounded-sm px-1 text-xs', item.active ? 'bg-amber-300 text-slate-950' : 'bg-amber-100 text-amber-800']">{{ item.count || item.badge }}</span>
                </a>
            </div>
        </div>
        <div v-if="overflowing" class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-2 py-1" data-record-tab-cue>
            <span :id="hintId" class="sr-only">Swipe horizontally or use these buttons to see all sections.</span>
            <button type="button" :aria-controls="viewportId" :aria-label="`Scroll ${label.toLowerCase()} left`" :disabled="!canScrollBack" class="inline-flex min-h-10 items-center gap-2 rounded-sm px-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-amber-600 disabled:cursor-default disabled:text-slate-400" data-record-scroll-back @click="scrollSections(-1)"><i class="fa-solid fa-chevron-left text-[10px] leading-none" aria-hidden="true"></i>Previous sections</button>
            <button type="button" :aria-controls="viewportId" :aria-label="`Scroll ${label.toLowerCase()} right`" :disabled="!canScrollForward" class="inline-flex min-h-10 items-center gap-2 rounded-sm px-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-amber-600 disabled:cursor-default disabled:text-slate-400" data-record-scroll-forward @click="scrollSections(1)">More sections<i class="fa-solid fa-chevron-right text-[10px] leading-none" aria-hidden="true"></i></button>
        </div>
    </nav>
</template>
