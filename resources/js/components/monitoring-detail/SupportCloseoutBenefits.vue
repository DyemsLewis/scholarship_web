<script setup>
defineProps({ closeout: { type: Object, required: true } });

const outcomes = [
    { key: 'received', label: 'Receipt confirmed', icon: 'fa-solid fa-circle-check', color: 'text-emerald-700' },
    { key: 'unclaimed', label: 'Unclaimed', icon: 'fa-regular fa-clock', color: 'text-amber-800' },
    { key: 'declined', label: 'Declined by you', icon: 'fa-solid fa-ban', color: 'text-slate-500' },
    { key: 'pending', label: 'Unsettled', icon: 'fa-regular fa-circle-question', color: 'text-slate-500' },
];
</script>

<template>
    <section data-closeout-benefits class="border-t border-slate-200">
        <header class="flex flex-wrap items-start justify-between gap-3 px-5 py-4 sm:px-6">
            <div>
                <h3 class="font-bold text-slate-950">Benefits at closeout</h3>
                <p class="mt-1 text-sm text-slate-600">{{ closeout.counts ? 'Saved when support ended. Unclaimed and declined benefits do not count as received.' : 'This earlier decision does not have saved benefit totals.' }}</p>
            </div>
            <a :href="closeout.benefits_href" class="inline-flex items-center gap-2 rounded-sm px-1 py-1 text-sm font-bold text-slate-700 hover:text-slate-950 hover:underline">View benefit records <i class="fa-solid fa-arrow-right text-[10px]" aria-hidden="true"></i></a>
        </header>
        <dl v-if="closeout.counts" class="grid grid-cols-2 gap-px border-t border-slate-200 bg-slate-200 md:grid-cols-4">
            <div v-for="item in outcomes" :key="item.key" class="bg-slate-50 px-5 py-3 sm:px-6">
                <dt class="flex items-center gap-2 text-xs font-semibold text-slate-600"><i :class="[item.icon, item.color]" aria-hidden="true"></i>{{ item.label }}</dt>
                <dd class="mt-1 text-xl font-bold tabular-nums text-slate-950" :data-closeout-count="item.key">{{ closeout.counts[item.key] }}</dd>
            </div>
        </dl>
    </section>
</template>
