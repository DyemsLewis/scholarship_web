<script setup>
const props = defineProps({
    pagination: {
        type: Object,
        required: true,
    },
    busy: {
        type: Boolean,
        default: false,
    },
    itemLabel: {
        type: String,
        default: 'items',
    },
});

const emit = defineEmits(['change']);

function changePage(page) {
    if (props.busy || page < 1 || page > Number(props.pagination.last_page ?? 1)) return;
    emit('change', page);
}
</script>

<template>
    <nav
        v-if="Number(pagination.last_page ?? 1) > 1"
        class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5"
        :aria-label="`${itemLabel} pagination`"
    >
        <p class="text-sm font-semibold text-slate-600" aria-live="polite">
            Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }} {{ itemLabel }}
        </p>
        <div class="grid grid-cols-2 gap-2 sm:flex">
            <button
                type="button"
                class="min-h-10 rounded-sm border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-slate-500 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="Number(pagination.current_page ?? 1) <= 1 || busy"
                @click="changePage(Number(pagination.current_page) - 1)"
            >
                <i class="fa-solid fa-arrow-left mr-2 text-xs" aria-hidden="true"></i>Previous
            </button>
            <button
                type="button"
                class="min-h-10 rounded-sm border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-slate-500 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="Number(pagination.current_page ?? 1) >= Number(pagination.last_page ?? 1) || busy"
                @click="changePage(Number(pagination.current_page) + 1)"
            >
                Next<i class="fa-solid fa-arrow-right ml-2 text-xs" aria-hidden="true"></i>
            </button>
        </div>
    </nav>
</template>
