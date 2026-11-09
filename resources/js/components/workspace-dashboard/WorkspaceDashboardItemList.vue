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
        <article v-for="item in items" :key="item.id" class="workspace-dashboard-item grid min-h-24 grid-cols-[minmax(0,1fr)_15rem_9rem] items-center gap-6 px-6 py-4">
            <div class="flex min-w-0 items-center gap-4">
                <img v-if="item.image_url" :src="item.image_url" :alt="item.image_fit === 'contain' ? `${item.subtitle || item.title} logo` : `${item.title} profile`" :class="['h-12 w-12 shrink-0 border border-slate-200 bg-white', item.image_fit === 'contain' ? 'object-contain p-1' : 'object-cover']">
                <span v-else class="workspace-dashboard-icon grid h-12 w-12 shrink-0 place-items-center bg-slate-100 text-slate-500" aria-hidden="true">
                    <i :class="fallbackIcon"></i>
                </span>
                <div class="min-w-0">
                    <h3 class="workspace-dashboard-text break-words text-sm font-black text-slate-950">{{ item.title }}</h3>
                    <p v-if="item.subtitle || item.hint" class="workspace-dashboard-text workspace-dashboard-detail mt-1 break-words text-xs font-medium text-slate-500">
                        <span v-if="item.subtitle">{{ item.subtitle }}</span>
                        <span v-if="item.subtitle && item.hint" class="mx-1" aria-hidden="true">&middot;</span>
                        <span v-if="item.hint" class="font-semibold text-slate-700">{{ item.hint }}</span>
                    </p>
                </div>
            </div>

            <div class="min-w-0">
                <span :class="['workspace-dashboard-status inline-flex border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide', toneClass(item.status?.tone)]">
                    {{ item.status?.label }}
                </span>
            </div>

            <a :href="item.action.url" class="workspace-dashboard-action staff-row-action staff-row-action--primary min-h-10 px-3 py-2 text-center text-xs" :aria-label="`${item.action.label}: ${item.title}`">
                {{ item.action.label }}
                <i class="fa-solid fa-arrow-right text-[10px] text-amber-300" aria-hidden="true"></i>
            </a>
        </article>
    </div>
</template>
