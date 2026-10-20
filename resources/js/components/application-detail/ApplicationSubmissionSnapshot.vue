<script setup>
import { computed } from 'vue';

const props = defineProps({
    profileRows: {
        type: Array,
        default: () => [],
    },
    questions: {
        type: Array,
        default: () => [],
    },
    profileHref: {
        type: String,
        default: '/dashboard/profile',
    },
});

const groups = computed(() => {
    const grouped = new Map();

    props.profileRows.forEach((row) => {
        if (!grouped.has(row.group)) {
            grouped.set(row.group, []);
        }

        grouped.get(row.group).push(row);
    });

    return Array.from(grouped, ([label, rows]) => ({ label, rows }));
});

function groupIcon(group) {
    return {
        Contact: 'fa-solid fa-address-card',
        Education: 'fa-solid fa-graduation-cap',
        Household: 'fa-solid fa-house',
    }[group] ?? 'fa-solid fa-circle-info';
}
</script>

<template>
    <section class="overflow-hidden rounded-md border border-slate-300 bg-white shadow-sm">
        <header class="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-amber-700">Submitted information</p>
                <h2 class="mt-1 text-lg font-bold text-slate-950">Profile snapshot</h2>
                <p class="mt-1 text-sm text-slate-600">These details were saved when you applied.</p>
            </div>
            <a :href="profileHref" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Open current profile
                <i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i>
            </a>
        </header>

        <div v-if="groups.length" class="divide-y divide-slate-200">
            <section v-for="group in groups" :key="group.label" class="px-4 py-4 sm:px-5">
                <h3 class="flex items-center gap-2 text-sm font-bold text-slate-950">
                    <i :class="[groupIcon(group.label), 'w-4 text-center text-amber-700']" aria-hidden="true"></i>
                    {{ group.label }}
                </h3>
                <dl class="mt-3 grid gap-x-8 gap-y-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div v-for="row in group.rows" :key="`${group.label}-${row.label}`" class="min-w-0">
                        <dt class="text-xs font-semibold text-slate-500">{{ row.label }}</dt>
                        <dd class="mt-1 break-words text-sm font-bold text-slate-950">{{ row.value }}</dd>
                    </div>
                </dl>
            </section>
        </div>
        <div v-else class="px-4 py-6 text-sm text-slate-600 sm:px-5">
            No profile details were stored with this application.
        </div>

        <section v-if="questions.length" class="border-t border-slate-200 bg-slate-50/70">
            <header class="px-4 py-3 sm:px-5">
                <h3 class="flex items-center gap-2 text-sm font-bold text-slate-950">
                    <i class="fa-solid fa-message w-4 text-center text-amber-700" aria-hidden="true"></i>
                    Provider questions
                </h3>
            </header>
            <ol class="divide-y divide-slate-200 border-t border-slate-200">
                <li v-for="(question, index) in questions" :key="`${index}-${question.prompt}`" class="bg-white px-4 py-4 sm:px-5">
                    <p class="text-sm font-bold text-slate-900">{{ question.prompt }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-600">{{ question.answer || 'No response saved.' }}</p>
                </li>
            </ol>
        </section>
    </section>
</template>
