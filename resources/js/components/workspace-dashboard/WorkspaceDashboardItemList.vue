<script setup>
defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    fallbackIcon: {
        type: String,
        default: 'fa-solid fa-user',
    },
});

function toneClass(tone) {
    return {
        attention: 'border-amber-200 bg-amber-50 text-amber-800',
        positive: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        neutral: 'border-slate-200 bg-slate-100 text-slate-700',
    }[tone] ?? 'border-slate-200 bg-slate-100 text-slate-700';
}
</script>

<template>
    <div class="divide-y divide-slate-200">
        <article v-for="item in items" :key="item.id" class="grid min-h-24 grid-cols-[minmax(0,1fr)_15rem_9rem] items-center gap-6 px-6 py-4">
            <div class="flex min-w-0 items-center gap-4">
                <img v-if="item.image_url" :src="item.image_url" :alt="`${item.title} profile`" class="h-12 w-12 shrink-0 border border-slate-200 object-cover">
                <span v-else class="grid h-12 w-12 shrink-0 place-items-center bg-slate-100 text-slate-500" aria-hidden="true">
                    <i :class="fallbackIcon"></i>
                </span>
                <div class="min-w-0">
                    <h3 class="truncate text-sm font-black text-slate-950">{{ item.title }}</h3>
                    <p class="mt-1 truncate text-xs font-medium text-slate-500">{{ item.subtitle }}</p>
                </div>
            </div>

            <div class="min-w-0">
                <span :class="['inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', toneClass(item.status?.tone)]">
                    {{ item.status?.label }}
                </span>
                <p v-for="detail in item.meta" :key="detail" class="mt-1 truncate text-xs text-slate-500">{{ detail }}</p>
            </div>

            <a :href="item.action.url" class="inline-flex h-9 items-center justify-center gap-2 bg-slate-950 px-3 text-xs font-bold text-white hover:bg-slate-800">
                {{ item.action.label }}
                <i class="fa-solid fa-arrow-right text-[10px] text-amber-300" aria-hidden="true"></i>
            </a>
        </article>
    </div>
</template>
